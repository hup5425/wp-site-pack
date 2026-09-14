#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
대조.py — Rank Math 를 끄기 전/후, 워드프레스 사이트가 내보내는 SEO 관련 값이
그대로인지 견주는 도구. (기획서.md "4.8 SEO — 대조 도구" 참고)

파이썬 표준 라이브러리만 쓴다. 외부 패키지 설치가 필요 없다.
실사이트는 읽기만 한다(GET 요청뿐, 로그인 없음).

사용법:
    python3 tools/대조.py 저장 https://zau.kr 전.json
    python3 tools/대조.py diff 전.json 후.json

"저장"은 사이트 여러 자리를 훑어 JSON 파일 하나로 남긴다.
"diff"는 두 JSON 파일을 자리·칸마다 견줘 달라진 것만 보여준다. 흔히 있을
법한 차이(트위터 라벨 삭제, 발행자가 Person→Organization 으로 바뀜 등)는
"예상된 차이" 로 따로 묶어 "확인 필요" 목록과 헷갈리지 않게 한다.
"""

import argparse
import html
import json
import re
import time
import urllib.error
import urllib.request
from html.parser import HTMLParser

UA = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)
TIMEOUT = 20


# ─────────────────────────────────────────────────────────
# 요청 — 리다이렉트를 따라가지 않고 상태코드·Location 을 그대로 본다
# ─────────────────────────────────────────────────────────

class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None  # 따라가지 않는다 — 원래 응답(30x)을 그대로 받는다


_OPENER = urllib.request.build_opener(_NoRedirect)


def _add_nocache(url: str) -> str:
    """CDN·페이지 캐시를 피하려고 쿼리를 하나 붙인다."""
    ts = str(int(time.time() * 1000))
    sep = "&" if "?" in url else "?"
    return f"{url}{sep}wsp_nocache={ts}"


def _read_response(resp):
    status = getattr(resp, "status", None) or resp.getcode()
    headers = {}
    for k, v in resp.headers.items():
        headers[k.lower()] = v
    try:
        raw = resp.read()
    except Exception as e:
        return {"status": status, "headers": headers, "body": None,
                "error": f"본문을 읽지 못함: {e}"}
    charset = None
    try:
        charset = resp.headers.get_content_charset()
    except Exception:
        pass
    charset = charset or "utf-8"
    try:
        body = raw.decode(charset, errors="replace")
    except LookupError:
        body = raw.decode("utf-8", errors="replace")
    return {"status": status, "headers": headers, "body": body, "error": None}


def fetch(url: str, nocache: bool = False, timeout: int = TIMEOUT) -> dict:
    """GET 요청 하나. 실패해도 예외를 던지지 않고 결과 안에 오류를 담는다."""
    target = _add_nocache(url) if nocache else url
    req = urllib.request.Request(target, headers={
        "User-Agent": UA,
        "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
    })
    try:
        resp = _OPENER.open(req, timeout=timeout)
        return _read_response(resp)
    except urllib.error.HTTPError as e:
        # 리다이렉트(30x)도 여기로 온다 — NoRedirect 가 예외로 넘겨준다.
        return _read_response(e)
    except Exception as e:
        return {"status": None, "headers": {}, "body": None,
                "error": f"{type(e).__name__}: {e}"}


def fetch_json(url: str, timeout: int = TIMEOUT):
    """워드프레스 REST(wp-json) 호출. (파싱된 값, 오류메시지) 를 돌려준다."""
    req = urllib.request.Request(url, headers={
        "User-Agent": UA, "Accept": "application/json",
    })
    try:
        resp = _OPENER.open(req, timeout=timeout)
        raw = resp.read()
        return json.loads(raw.decode("utf-8", errors="replace")), None
    except Exception as e:
        return None, f"{type(e).__name__}: {e}"


# ─────────────────────────────────────────────────────────
# HTML 파싱 — title · meta · canonical · JSON-LD 원문을 뽑는다
# ─────────────────────────────────────────────────────────

class _PageParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.title = ""
        self._in_title = False
        self.meta_name = {}       # name 속성 meta (description, robots, twitter:*, msapplication-*)
        self.meta_property = {}   # property 속성 meta (og:*, article:*)
        self.canonical = None
        self._in_ld = False
        self._ld_buf = []
        self.ldjson_raw = []      # <script type="application/ld+json"> 원문 목록

    def handle_starttag(self, tag, attrs):
        d = {k.lower(): v for k, v in attrs}
        t = tag.lower()
        if t == "title":
            self._in_title = True
        elif t == "meta":
            name = d.get("name")
            prop = d.get("property")
            content = d.get("content", "")
            if name:
                self.meta_name[name] = content
            if prop:
                self.meta_property[prop] = content
        elif t == "link":
            rel = (d.get("rel") or "").lower()
            if rel == "canonical" and d.get("href"):
                self.canonical = d.get("href")
        elif t == "script":
            st = (d.get("type") or "").lower()
            if st == "application/ld+json":
                self._in_ld = True
                self._ld_buf = []

    def handle_endtag(self, tag):
        t = tag.lower()
        if t == "title":
            self._in_title = False
        elif t == "script" and self._in_ld:
            self._in_ld = False
            self.ldjson_raw.append("".join(self._ld_buf))

    def handle_data(self, data):
        if self._in_title:
            self.title += data
        elif self._in_ld:
            self._ld_buf.append(data)


# ─────────────────────────────────────────────────────────
# JSON-LD 요약 — @graph 를 펼치고 @type 마다 주요 값만 뽑는다
# ─────────────────────────────────────────────────────────

def _type_list(node: dict):
    t = node.get("@type")
    if isinstance(t, list):
        return [x for x in t if isinstance(x, str)]
    if isinstance(t, str):
        return [t]
    return []


def _type_key(node: dict) -> str:
    types = _type_list(node)
    return "+".join(types) if types else "?"


def _resolve_ref_type(val, id_map: dict):
    """publisher 같은 참조값의 @type 을(직접 있으면 그대로, @id 참조면 찾아서) 돌려준다."""
    if isinstance(val, dict):
        if val.get("@type"):
            return _type_key(val)
        rid = val.get("@id")
        if rid:
            ref = id_map.get(rid)
            if ref:
                return _type_key(ref)
            return f"(참조 대상 못 찾음: {rid})"
    if isinstance(val, str):
        ref = id_map.get(val)
        if ref:
            return _type_key(ref)
    return None


def _image_url(val):
    if isinstance(val, str):
        return val
    if isinstance(val, dict):
        return val.get("url") or val.get("@id")
    if isinstance(val, list) and val:
        return _image_url(val[0])
    return None


def _extract_ldjson_nodes(ldjson_raw_list):
    """<script type=ld+json> 원문 여러 개를 파싱해 @graph 를 펼친 노드 목록으로."""
    nodes = []
    for raw in ldjson_raw_list:
        raw = raw.strip()
        if not raw:
            continue
        try:
            data = json.loads(raw)
        except Exception:
            continue
        if isinstance(data, dict) and isinstance(data.get("@graph"), list):
            nodes.extend(n for n in data["@graph"] if isinstance(n, dict))
        elif isinstance(data, list):
            nodes.extend(n for n in data if isinstance(n, dict))
        elif isinstance(data, dict):
            nodes.append(data)
    return nodes


def summarize_ldjson(nodes: list) -> dict:
    """@type 마다 headline·name·날짜·publisher 종류·image url 과, 종류별 값 몇 개."""
    id_map = {n.get("@id"): n for n in nodes if isinstance(n, dict) and n.get("@id")}
    grouped = {}
    for n in nodes:
        types = _type_list(n)
        if not types:
            continue
        key = _type_key(n)
        out = {}
        for f in ("headline", "name", "datePublished", "dateModified", "@id"):
            if f in n and n[f] not in (None, ""):
                out[f] = n[f]
        if "publisher" in n:
            out["publisher_type"] = _resolve_ref_type(n["publisher"], id_map)
        if "image" in n:
            out["image_url"] = _image_url(n["image"])
        if "FAQPage" in types:
            me = n.get("mainEntity")
            out["mainEntity_count"] = len(me) if isinstance(me, list) else (1 if me else 0)
        if "VideoObject" in types:
            out["embedUrl"] = n.get("embedUrl")
        if "BreadcrumbList" in types:
            ile = n.get("itemListElement")
            out["itemListElement_count"] = len(ile) if isinstance(ile, list) else (1 if ile else 0)
        if not out:
            continue
        grouped.setdefault(key, []).append(out)
    result = {}
    for k, v in grouped.items():
        result[k] = v[0] if len(v) == 1 else v
    return result


def find_author_url(nodes: list):
    """글의 JSON-LD 에서 author 가 가리키는 자리(@id)를 실제 주소로 찾아본다."""
    id_map = {n.get("@id"): n for n in nodes if isinstance(n, dict) and n.get("@id")}
    for n in nodes:
        types = _type_list(n)
        if not any(t in ("BlogPosting", "Article", "NewsArticle") for t in types):
            continue
        author = n.get("author")
        aid = None
        if isinstance(author, dict):
            aid = author.get("@id")
        elif isinstance(author, str):
            aid = author
        if not aid:
            continue
        ref = id_map.get(aid)
        if ref and isinstance(ref.get("url"), str) and ref["url"].startswith("http"):
            return ref["url"]
        if aid.startswith("http") and "#" not in aid:
            return aid
    return None


# ─────────────────────────────────────────────────────────
# 페이지 하나를 받아 기록으로 만든다
# ─────────────────────────────────────────────────────────

def fetch_page_with_nodes(url: str, nocache: bool = True):
    resp = fetch(url, nocache=nocache)
    headers = resp["headers"]
    age = headers.get("age")
    xcache = headers.get("x-cache")
    cache_warn = False
    if age is not None and age.strip() != "0":
        cache_warn = True
    if xcache and "hit" in xcache.lower():
        cache_warn = True

    rec = {
        "url": url,
        "상태코드": resp["status"],
        "location": headers.get("location"),
        "age": age,
        "x-cache": xcache,
        "캐시경고": cache_warn,
        "오류": resp["error"],
        "title": None,
        "description": None,
        "robots": None,
        "msapplication_tileimage": None,
        "canonical": None,
        "og": {},
        "twitter": {},
        "ldjson": {},
    }
    nodes = []
    if resp["body"]:
        p = _PageParser()
        try:
            p.feed(resp["body"])
        except Exception as e:
            rec["오류"] = (rec["오류"] + " / " if rec["오류"] else "") + f"HTML 파싱 실패: {e}"
        rec["title"] = p.title.strip()
        rec["description"] = p.meta_name.get("description")
        rec["robots"] = p.meta_name.get("robots")
        rec["msapplication_tileimage"] = p.meta_name.get("msapplication-TileImage")
        rec["canonical"] = p.canonical
        rec["og"] = {k: v for k, v in p.meta_property.items()
                     if k.startswith("og:") or k.startswith("article:")}
        rec["twitter"] = {k: v for k, v in p.meta_name.items() if k.startswith("twitter:")}
        nodes = _extract_ldjson_nodes(p.ldjson_raw)
        rec["ldjson"] = summarize_ldjson(nodes)
    return rec, nodes


def fetch_page(url: str, nocache: bool = True) -> dict:
    rec, _ = fetch_page_with_nodes(url, nocache=nocache)
    return rec


# ─────────────────────────────────────────────────────────
# robots.txt · 사이트맵
# ─────────────────────────────────────────────────────────

def fetch_robots(url: str) -> dict:
    resp = fetch(url, nocache=False)
    lines = resp["body"].splitlines() if resp["body"] else []
    return {"url": url, "상태코드": resp["status"], "오류": resp["error"], "줄": lines}


def parse_sitemap_locs(text: str):
    """<sitemap>...<loc>...</loc>... 나 <url>...<loc>...</loc>... 블록에서 (주소, lastmod) 를 뽑는다."""
    entries = []
    for block in re.findall(r"<(?:sitemap|url)\b[^>]*>(.*?)</(?:sitemap|url)>", text, re.S | re.I):
        m_loc = re.search(r"<loc\b[^>]*>(.*?)</loc>", block, re.S | re.I)
        if not m_loc:
            continue
        loc = html.unescape(m_loc.group(1).strip())
        m_lm = re.search(r"<lastmod\b[^>]*>(.*?)</lastmod>", block, re.S | re.I)
        lastmod = html.unescape(m_lm.group(1).strip()) if m_lm else None
        entries.append((loc, lastmod))
    return entries


def fetch_sitemap_index(url: str) -> dict:
    """sitemap_index.xml 과 그 안에서 가리키는 사이트맵 파일들을 전부 훑는다."""
    resp = fetch(url, nocache=False)
    out = {
        "url": url,
        "상태코드": resp["status"],
        "location": resp["headers"].get("location"),
        "오류": resp["error"],
        "하위파일": {},
    }
    if not resp["body"]:
        return out
    for loc, idx_lastmod in parse_sitemap_locs(resp["body"]):
        child_resp = fetch(loc, nocache=False)
        child = {
            "색인_lastmod": idx_lastmod,
            "상태코드": child_resp["status"],
            "오류": child_resp["error"],
            "url목록": [],
            "lastmod": {},
        }
        if child_resp["body"]:
            child_entries = parse_sitemap_locs(child_resp["body"])
            child["url목록"] = sorted({u for u, _ in child_entries})
            child["lastmod"] = {u: lm for u, lm in child_entries}
        out["하위파일"][loc] = child
    return out


def fetch_sitemap_simple(url: str) -> dict:
    """/wp-sitemap.xml · /sitemap.xml — 지금은 대개 /sitemap_index.xml 로 301 이 예상된다."""
    resp = fetch(url, nocache=False)
    out = {
        "url": url,
        "상태코드": resp["status"],
        "location": resp["headers"].get("location"),
        "오류": resp["error"],
    }
    if resp["body"] and resp["status"] == 200:
        entries = parse_sitemap_locs(resp["body"])
        out["url목록"] = sorted({u for u, _ in entries})
    return out


# ─────────────────────────────────────────────────────────
# 사이트 하나를 통째로 훑기
# ─────────────────────────────────────────────────────────

def _note_warn(rec: dict, label: str, warnings: list):
    if rec.get("캐시경고"):
        warnings.append(f"{label}: 캐시 경고 (age={rec.get('age')}, x-cache={rec.get('x-cache')})")
    if rec.get("오류"):
        warnings.append(f"{label}: 오류 — {rec['오류']}")


def build_site_snapshot(base: str, log=print):
    base = base.rstrip("/")
    items = {}
    warnings = []

    log("  - 홈")
    items["홈"] = fetch_page(base + "/")
    _note_warn(items["홈"], "홈", warnings)

    log("  - 최신 글 5편")
    posts, err = fetch_json(f"{base}/wp-json/wp/v2/posts?per_page=5&_fields=link")
    if err:
        warnings.append(f"최신글: REST 호출 실패 — {err}")
    post_urls = [p["link"] for p in posts] if posts else []
    latest = []
    first_nodes = []
    for i, u in enumerate(post_urls, 1):
        rec, nodes = fetch_page_with_nodes(u)
        latest.append(rec)
        _note_warn(rec, f"최신글{i}", warnings)
        if i == 1:
            first_nodes = nodes
    items["최신글"] = latest

    log("  - 카테고리")
    cats, err = fetch_json(
        f"{base}/wp-json/wp/v2/categories?per_page=1&orderby=count&order=desc&_fields=link"
    )
    if cats:
        rec = fetch_page(cats[0]["link"])
        items["카테고리"] = rec
        _note_warn(rec, "카테고리", warnings)
    else:
        items["카테고리"] = {"건너뜀": "카테고리를 찾지 못함" if err else "카테고리 없음"}

    log("  - 페이지")
    pages, err = fetch_json(f"{base}/wp-json/wp/v2/pages?per_page=1&_fields=link")
    if pages:
        rec = fetch_page(pages[0]["link"])
        items["페이지"] = rec
        _note_warn(rec, "페이지", warnings)
    else:
        items["페이지"] = {"건너뜀": "페이지가 없음"}

    log("  - 사이트 안 검색")
    rec = fetch_page(f"{base}/?s=test")
    items["검색"] = rec
    _note_warn(rec, "검색", warnings)

    log("  - 작성자 페이지")
    author_url = find_author_url(first_nodes) if first_nodes else None
    if not author_url:
        users, _ = fetch_json(f"{base}/wp-json/wp/v2/users?per_page=1&_fields=link")
        if users:
            author_url = users[0]["link"]
    if author_url:
        rec = fetch_page(author_url)
        items["작성자"] = rec
        _note_warn(rec, "작성자", warnings)
    else:
        items["작성자"] = {"건너뜀": "작성자 페이지를 찾지 못함"}

    log("  - 첨부파일")
    media, _ = fetch_json(f"{base}/wp-json/wp/v2/media?per_page=1&_fields=link")
    if media:
        rec = fetch_page(media[0]["link"])
        items["첨부파일"] = rec
        _note_warn(rec, "첨부파일", warnings)
    else:
        items["첨부파일"] = {"건너뜀": "첨부파일이 없음"}

    log("  - robots.txt")
    items["robots_txt"] = fetch_robots(base + "/robots.txt")

    log("  - sitemap_index.xml (하위 파일 전부)")
    items["sitemap_index"] = fetch_sitemap_index(base + "/sitemap_index.xml")

    log("  - wp-sitemap.xml · sitemap.xml")
    items["wp_sitemap_xml"] = fetch_sitemap_simple(base + "/wp-sitemap.xml")
    items["sitemap_xml"] = fetch_sitemap_simple(base + "/sitemap.xml")

    return items, warnings


def cmd_save(url: str, out_path: str):
    print(f"[대조] {url} 을(를) 훑는 중…")
    items, warnings = build_site_snapshot(url)
    snapshot = {
        "사이트": url.rstrip("/"),
        "생성시각": time.strftime("%Y-%m-%dT%H:%M:%S"),
        "items": items,
    }
    with open(out_path, "w", encoding="utf-8") as f:
        json.dump(snapshot, f, ensure_ascii=False, indent=2)
    print(f"[대조] 저장했습니다: {out_path}")
    print(f"[대조] 자리 {len(items)}곳, 경고 {len(warnings)}건")
    for w in warnings:
        print(f"  ⚠ {w}")


# ─────────────────────────────────────────────────────────
# diff — 두 저장본을 자리·칸마다 견준다
# ─────────────────────────────────────────────────────────

def flatten(obj, prefix=""):
    """중첩된 dict·list 를 "a.b[0].c" 모양의 평평한 칸 이름으로 편다."""
    out = {}
    if isinstance(obj, dict):
        if not obj:
            out[prefix or "(값)"] = {}
            return out
        for k, v in obj.items():
            key = f"{prefix}.{k}" if prefix else str(k)
            out.update(flatten(v, key))
    elif isinstance(obj, list):
        if not obj:
            out[prefix or "(값)"] = []
            return out
        for i, v in enumerate(obj):
            key = f"{prefix}[{i}]"
            out.update(flatten(v, key))
    else:
        out[prefix] = obj
    return out


def diff_flat(before, after):
    fb = flatten(before) if before is not None else {}
    fa = flatten(after) if after is not None else {}
    rows = []
    for k in sorted(set(fb) | set(fa)):
        bv = fb.get(k, "(없음)")
        av = fa.get(k, "(없음)")
        if bv != av:
            rows.append((k, bv, av))
    return rows


def diff_latest_posts(before_list, after_list):
    rows = []
    b_by_url = {p.get("url"): p for p in (before_list or []) if p.get("url")}
    a_by_url = {p.get("url"): p for p in (after_list or []) if p.get("url")}
    seen = []
    for p in (before_list or []) + (after_list or []):
        u = p.get("url")
        if u and u not in seen:
            seen.append(u)
    for u in seen:
        b = b_by_url.get(u)
        a = a_by_url.get(u)
        label = f"최신글({u})"
        if b is None:
            rows.append((label, "목록", "(전에 없었음)", "새로 등장"))
            continue
        if a is None:
            rows.append((label, "목록", "전에 있었음", "(사라짐)"))
            continue
        for field, bv, av in diff_flat(b, a):
            if field == "url":
                continue
            rows.append((label, field, bv, av))
    return rows


def diff_sitemap_index(before, after):
    rows = []
    before = before or {}
    after = after or {}
    if before.get("상태코드") != after.get("상태코드"):
        rows.append(("sitemap_index", "상태코드", before.get("상태코드"), after.get("상태코드")))
    b_children = before.get("하위파일", {}) or {}
    a_children = after.get("하위파일", {}) or {}
    for f in sorted(set(b_children) | set(a_children)):
        bc = b_children.get(f)
        ac = a_children.get(f)
        label = f"sitemap({f})"
        if bc is None:
            rows.append((label, "요약", "(없음)", "새 사이트맵 파일이 생김"))
            continue
        if ac is None:
            rows.append((label, "요약", "사이트맵 파일이 있었음", "(없어짐)"))
            continue
        if bc.get("상태코드") != ac.get("상태코드"):
            rows.append((label, "상태코드", bc.get("상태코드"), ac.get("상태코드")))
        b_urls = set(bc.get("url목록", []))
        a_urls = set(ac.get("url목록", []))
        missing = b_urls - a_urls
        added = a_urls - b_urls
        common = b_urls & a_urls
        changed_lastmod = sum(
            1 for u in common
            if bc.get("lastmod", {}).get(u) != ac.get("lastmod", {}).get(u)
        )
        if missing or added or changed_lastmod:
            rows.append((
                label, "요약(주소·lastmod)",
                f"주소 {len(b_urls)}개",
                f"빠진 주소 {len(missing)} · 새 주소 {len(added)} · "
                f"lastmod 바뀐 것 {changed_lastmod} (지금 {len(a_urls)}개)",
            ))
    return rows


_EXPECTED_LABEL_SUFFIXES = ("twitter:label1", "twitter:data1", "twitter:label2", "twitter:data2")


def classify_expected(item, field, before, after):
    """모듈을 바꾸면 원래 이렇게 달라지는 것으로 미리 알려진 차이인지 본다."""
    fname = field.split(".")[-1]
    fname = re.sub(r"\[\d+\]$", "", fname)

    # 1) 트위터 label/data (작성자·읽을 시간 표시) — 원래 뺀다
    if fname in _EXPECTED_LABEL_SUFFIXES:
        return True

    # 2) 홈 <title> 끝의 " -" 가 없어짐 (태그라인이 비어도 구분기호를 붙이던 것을 고침)
    if item == "홈" and field == "title" and isinstance(before, str) and isinstance(after, str):
        m = re.match(r"^(.*\S)\s*-\s*$", before)
        if m and m.group(1) == after:
            return True

    # 3) 발행자(publisher)가 Person 에서 Organization 으로
    if "publisher" in field.lower():
        bs, as_ = str(before), str(after)
        if "Person" in bs and "Person" not in as_ and "Organization" in as_:
            return True

    # 4) og:image:secure_url · og:image:type 유무
    if fname in ("og:image:secure_url", "og:image:type"):
        return True

    # 5) JSON-LD 의 @id 값 차이 (Rank Math 와 우리 모듈의 고유 주소 형식이 다름)
    if "@id" in field:
        return True

    # 6) msapplication-TileImage 유무
    if fname == "msapplication_tileimage":
        return True

    # 7) canonical 끝 슬래시 유무
    if fname == "canonical" and isinstance(before, str) and isinstance(after, str):
        if before != after and before.rstrip("/") == after.rstrip("/"):
            return True

    return False


def _short(v, limit=200):
    s = v if isinstance(v, str) else json.dumps(v, ensure_ascii=False)
    if len(s) > limit:
        s = s[:limit] + "…"
    return s


def cmd_diff(before_path: str, after_path: str):
    with open(before_path, encoding="utf-8") as f:
        before = json.load(f)
    with open(after_path, encoding="utf-8") as f:
        after = json.load(f)

    b_items = before.get("items", {})
    a_items = after.get("items", {})

    rows = []

    simple_keys = ["홈", "카테고리", "페이지", "검색", "작성자", "첨부파일",
                   "robots_txt", "wp_sitemap_xml", "sitemap_xml"]
    for key in simple_keys:
        for field, bv, av in diff_flat(b_items.get(key), a_items.get(key)):
            rows.append((key, field, bv, av))

    rows.extend(diff_latest_posts(b_items.get("최신글"), a_items.get("최신글")))
    rows.extend(diff_sitemap_index(b_items.get("sitemap_index"), a_items.get("sitemap_index")))

    expected, review = [], []
    for item, field, bv, av in rows:
        (expected if classify_expected(item, field, bv, av) else review).append((item, field, bv, av))

    def _print_group(title, group):
        print(f"\n=== {title} ({len(group)}건) ===")
        if not group:
            print("  (없음)")
            return
        for item, field, bv, av in group:
            print(f"- [{item}] {field}")
            print(f"    전: {_short(bv)}")
            print(f"    후: {_short(av)}")

    _print_group("확인 필요", review)
    _print_group("예상된 차이", expected)
    print(f"\n확인 필요 {len(review)}건 / 예상된 차이 {len(expected)}건")


# ─────────────────────────────────────────────────────────

def main():
    parser = argparse.ArgumentParser(
        prog="대조.py",
        description="워드프레스 사이트의 SEO 관련 출력을 훑어 저장하고, 두 저장본을 견주는 도구",
    )
    sub = parser.add_subparsers(dest="cmd", required=True)

    p_save = sub.add_parser("저장", help="사이트를 훑어 JSON 파일로 저장한다")
    p_save.add_argument("url", help="사이트 주소, 예: https://zau.kr")
    p_save.add_argument("out", help="저장할 JSON 파일 경로")

    p_diff = sub.add_parser("diff", help="두 저장본을 견줘 다른 점만 보여준다")
    p_diff.add_argument("before", help="전 저장본 JSON 경로")
    p_diff.add_argument("after", help="후 저장본 JSON 경로")

    args = parser.parse_args()
    if args.cmd == "저장":
        cmd_save(args.url, args.out)
    elif args.cmd == "diff":
        cmd_diff(args.before, args.after)


if __name__ == "__main__":
    main()
