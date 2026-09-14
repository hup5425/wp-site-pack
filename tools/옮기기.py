#!/usr/bin/env python3
"""
사이트 하나를 Rank Math·알팩·인덱싱 플러그인에서 사이트팩으로 옮긴다.

서버 관리 도구(`~/클로드작업/wp-visitor-stats/_local-tool`)의 SSH·WP-CLI 를 빌려 쓰므로
**그 폴더의 파이썬**으로 돌린다:

    PY=~/클로드작업/wp-visitor-stats/_local-tool/.venv/bin/python
    $PY tools/옮기기.py 상태   coreabiz.com
    $PY tools/옮기기.py 준비   coreabiz.com v0.4.2   # 설치·모듈 켜기·Rank Math 설정 가져오기 (Rank Math 는 켜 둔 채 — SEO 모듈은 쉰다)
    $PY tools/옮기기.py 끄기   coreabiz.com          # Rank Math 무료·프로 끄기 → 이때부터 SEO 모듈이 일한다
    $PY tools/옮기기.py 되돌리기 coreabiz.com        # 문제가 있으면 Rank Math 다시 켜기
    $PY tools/옮기기.py 지우기 coreabiz.com          # 대조가 깨끗하면 필요 없는 플러그인 삭제

순서: 대조 도구로 「전」 저장(tools/대조기록/<도메인>_전.json) → 준비 → 끄기 →
대조 「후」 저장·diff → 지우기. robots.txt 는 「전」 기록의 줄을 그대로 Ads 매니저에 담는다
(Rank Math 가 만들던 다음 웹마스터 인증 줄을 잃지 않게). 「전」 기록이 없으면 준비를 멈춘다.
"""
import json
import os
import shlex
import sys

TOOL = os.path.expanduser('~/클로드작업/wp-visitor-stats/_local-tool')
sys.path.insert(0, TOOL)
import store   # noqa: E402
import wpcli   # noqa: E402

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = 'hup5425/wp-site-pack'

# 사이트팩이 대신하므로 지우는 플러그인(사장님 결정 2026-09-14).
지울것 = ['seo-by-rank-math-pro', 'seo-by-rank-math', 'alpack', 'fast-indexing-api',
         'scheduled-post-trigger', 'bing-webmaster-tools']


class 사이트:
    def __init__(self, domain):
        found = [s for s in store.sites() if s['domain'] == domain]
        if not found:
            sys.exit(f'서버 관리 도구에 없는 사이트입니다: {domain}')
        self.s = found[0]
        self.cfg = store.load()['hostings'][self.s['hosting']]
        self.dir = f"{self.cfg.get('app_root', '/home/master/applications')}/{self.s['app_dir']}/public_html"
        self.cli = wpcli._ssh(self.cfg)

    def wp(self, args, timeout=180):
        out, _ = wpcli._run(self.cli, f'cd {shlex.quote(self.dir)} && wp {args} 2>&1', timeout=timeout)
        return out.strip()

    def eval(self, php, timeout=180):
        return self.wp(f'eval {shlex.quote(php)}', timeout=timeout)

    def close(self):
        self.cli.close()


def 상태(site):
    print(site.wp('plugin list --fields=name,status,version --format=csv --skip-plugins --skip-themes'))
    print('사이트팩 모듈:', site.wp('option get wsp_active_modules --format=json --skip-plugins --skip-themes'))
    print('예약글:', site.wp('post list --post_status=future --format=count --skip-plugins --skip-themes'))


def 준비(site, domain, tag):
    전 = os.path.join(HERE, '대조기록', f'{domain}_전.json')
    if not os.path.exists(전):
        sys.exit(f'「전」 대조 기록이 없습니다: {전}\n먼저: python3 tools/대조.py 저장 https://{domain} {전}')
    robots = '\n'.join(json.load(open(전, encoding='utf-8'))['items']['robots_txt']['줄']).strip()

    url = f'https://github.com/{REPO}/releases/download/{tag}/wp-site-pack-{tag}.zip'
    print(site.wp(f'plugin install {shlex.quote(url)} --force --activate --skip-plugins --skip-themes', 300)[-300:])

    php = r'''
WSP_Core::boot();
$robots = base64_decode("%s");
// Ads 매니저: ads.txt 는 물리 파일이 있으면 그대로 두고, robots 만 「전」 내용으로.
$am = WSP_Settings::get("ads_manager", array());
$am["robots_txt"] = $robots;
if (!isset($am["ads_txt"])) { $am["ads_txt"] = ""; }
WSP_Settings::set("ads_manager", $am);
WSP_Settings::set_active("ads_manager", 1);
// 자동 인덱싱: 이미 쓰던 IndexNow 키를 이어 쓴다(알팩 → Rank Math 순).
$ai = new WSP_Mod_Auto_Index();
$s = $ai->settings();
if (empty($s["key"])) {
  $k = get_option("presslearn_indexnow_api_key");
  if (!$k) { $ii = get_option("rank-math-options-instant-indexing"); $k = is_array($ii) && !empty($ii["indexnow_api_key"]) ? $ii["indexnow_api_key"] : ""; }
  $s["key"] = $k;
}
$s["auto"] = 1;
WSP_Settings::set("auto_index", $s);
WSP_Settings::set_active("auto_index", 1);
// 예약글 발행 보장.
WSP_Settings::set_active("scheduled_publish", 1);
(new WSP_Mod_Scheduled_Publish())->on_activate();
// SEO: Rank Math 설정을 가져와 저장하고 켠다(Rank Math 가 켜져 있는 동안은 스스로 쉰다).
$seo = new WSP_Mod_SEO();
WSP_Settings::set("seo", $seo->import_rank_math($seo->settings()));
WSP_Settings::set_active("seo", 1);
$seo->on_activate();
update_option("wsp_flush_rewrite", 1);
echo json_encode(array("모듈" => get_option("wsp_active_modules"), "IndexNow키" => $s["key"] ? "있음" : "없음",
  "SEO" => WSP_Settings::get("seo", array()), "가져온것" => get_option("wsp_seo_migrated")), JSON_UNESCAPED_UNICODE);
''' % __import__('base64').b64encode(robots.encode()).decode()
    print(site.eval(php))
    print(site.wp('rewrite flush'))
    print(site.wp('cache flush'))


def 끄기(site):
    print(site.wp('plugin deactivate seo-by-rank-math-pro seo-by-rank-math --skip-themes'))
    print(site.wp('rewrite flush'))
    print(site.wp('cache flush'))


def 되돌리기(site):
    print(site.wp('plugin activate seo-by-rank-math seo-by-rank-math-pro --skip-themes'))
    print(site.wp('rewrite flush'))
    print(site.wp('cache flush'))


def 지우기(site):
    있는것 = set(site.wp('plugin list --field=name --skip-plugins --skip-themes').split())
    대상 = [p for p in 지울것 if p in 있는것]
    if not 대상:
        print('지울 플러그인이 없습니다.')
        return
    # Rank Math 는 "삭제 시 데이터 지움" 이 켜져 있으면 글별 설명문·포커스 키워드가 사라진다 → 먼저 확인.
    rm = site.eval('$g=get_option("rank-math-options-general"); echo isset($g["remove_data_on_uninstall"]) ? $g["remove_data_on_uninstall"] : "off";')
    if rm.strip() == 'on':
        sys.exit('Rank Math 의 "삭제 시 데이터 지움" 이 켜져 있어 멈춥니다. 끄고 다시 돌리세요.')
    print('지울 것:', ' '.join(대상))
    print(site.wp('plugin deactivate ' + ' '.join(대상) + ' --skip-themes'))
    print(site.wp('plugin delete ' + ' '.join(대상) + ' --skip-themes'))
    print(site.wp('cache flush'))


def main():
    if len(sys.argv) < 3:
        sys.exit(__doc__)
    cmd, domain = sys.argv[1], sys.argv[2]
    site = 사이트(domain)
    try:
        if cmd == '상태':
            상태(site)
        elif cmd == '준비':
            if len(sys.argv) < 4:
                sys.exit('릴리스 태그를 적으세요. 예: 준비 coreabiz.com v0.4.2')
            준비(site, domain, sys.argv[3])
        elif cmd == '끄기':
            끄기(site)
        elif cmd == '되돌리기':
            되돌리기(site)
        elif cmd == '지우기':
            지우기(site)
        else:
            sys.exit(__doc__)
    finally:
        site.close()


if __name__ == '__main__':
    main()
