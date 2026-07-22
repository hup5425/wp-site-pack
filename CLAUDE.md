# wp-site-pack — CLAUDE.md

> 알팩 스타일의 **모듈형 통합 워드프레스 플러그인**. 기능별 On/Off. 상세 설계는 같은 폴더 `기획서.md`, 작업 이어받기는 `인계서.md` 참조.

## 🚨 최우선 개발 원칙 — "원본 우선, 땜질 금지" (모든 세션 필독)

문제가 생기면 **절대 그 위에 즉흥 코드를 덧붙여 증상만 덮지 말 것.** 반드시 이 순서를 지킨다:

1. **원본을 본다** — 정상 동작하던 원래 코드/디자인을 먼저 확인(git 이력 포함).
2. **원인을 찾는다** — 무엇이 원본과 달라졌고 왜 깨졌는지 근본 원인 파악.
3. **원본을 고친다 / 되돌린다** — 원본을 직접 수정하거나 원본 방식으로 되돌린다.
4. **정말 안 될 때만 차선책** — 이유를 반드시 기록.

- ❌ 원인 파악 없이 `width:100%` 식 임시 땜질을 붙이는 것, 그 땜질이 다른 곳을 깨뜨리면 또 코드를 덧붙이는 것.
- ✅ 한 번 꼬이면 멈추고, 원본으로 되돌린 뒤 다시 시작. 빠른 우회보다 느리더라도 원인 수정 우선.

## 이 팩 고유 원칙 — 모듈 격리가 생명

- 한 모듈 수정이 다른 모듈에 영향을 주면 **설계가 잘못된 것**. 공유 코드는 `includes/class-core.php`·`class-stats-bridge.php` 로만.
- 비활성 모듈은 런타임 훅이 걸리지 않는다(레지스트리 `WSP_Core` 가 활성 모듈만 `register()`).
- 통계 플러그인(`wp-visitor-stats`) DB 에는 **절대 쓰지 않는다**(읽기 전용 재사용, `class-stats-bridge.php`).

## 구조

```
wp-site-pack.php            메인: 상수(WSP_*), 코어 로드, 부트스트랩, 활성화훅
includes/
  class-settings.php        옵션 헬퍼(wsp_mod_{slug}, wsp_active_modules)
  class-module.php          추상 WSP_Module(모듈 공통 계약)
  class-core.php            레지스트리(활성 모듈만 register())
  class-admin.php           대시보드/설정 라우팅 + 저장 처리
  class-assets.php          프론트 자원 헬퍼
  class-stats-bridge.php    통계 데이터 읽기전용 재사용
  class-updater.php         GitHub 릴리스 자동 업데이트
  modules/class-mod-*.php   7개 모듈
admin/dashboard.php, settings-page.php
assets/admin.css, admin.js, front/*
```

새 모듈 추가: `includes/modules/class-mod-*.php` 작성(WSP_Module 상속) → `class-core.php` 의 `module_map()` 에 등록하면 대시보드에 자동 노출.

## 네이밍(가칭 — 브랜드 확정 시 일괄 치환)

폴더 `wp-site-pack` / 접두어 `wsp_` / 상수 `WSP_` / 클래스 `WSP_*` / 메뉴 "사이트 팩".

## 배포(릴리스) 절차

- **GitHub 저장소:** `hup5425/wp-site-pack` (비공개). `WSP_UPDATE_REPO` 상수에 설정됨.
- **업데이트 토큰:** 코드/zip 에 **넣지 않는다.** 사용자가 **대시보드의 "업데이트 토큰" 필드**에 붙여넣어 DB 옵션 `wsp_update_token` 에 저장(또는 wp-config 에 `WSP_UPDATE_TOKEN` 정의). fine-grained·읽기전용·이 저장소 하나만.
  - 이유: 비밀을 파일/저장소에 안 남김 + 업데이트해도 안 지워짐 + 자동모드 분류기·GitHub 푸시보호 회피.
- 버전 두 곳 일치: `wp-site-pack.php` 헤더 `Version:` + `WSP_VERSION` 상수.

```
cd ~/클로드작업
zip -rq wp-site-pack/wp-site-pack-vX.Y.Z.zip wp-site-pack \
  -x "wp-site-pack/.git/*" "wp-site-pack/.claude/*" "wp-site-pack/.DS_Store" \
     "wp-site-pack/*.zip" "wp-site-pack/CLAUDE.md" "wp-site-pack/기획서.md" "wp-site-pack/인계서.md"
cd wp-site-pack && git add -A && git commit -m "vX.Y.Z: ..." && git push
gh release create vX.Y.Z wp-site-pack-vX.Y.Z.zip -t vX.Y.Z -n "변경 내용"
```
⚠ SFTP 직접 배포는 이 사이트(co.coreabiz.com)에서 **rate-limit 으로 자주 차단**됨 → GitHub 릴리스 + 대시보드 업데이트 버튼으로 배포하는 게 정석.

```
cd ~/클로드작업
zip -r wp-site-pack/wp-site-pack-vX.Y.Z.zip wp-site-pack \
  -x "wp-site-pack/.git/*" "wp-site-pack/.claude/*" "wp-site-pack/.DS_Store" \
     "wp-site-pack/*.zip" "wp-site-pack/CLAUDE.md" "wp-site-pack/기획서.md" "wp-site-pack/인계서.md"
cd wp-site-pack && git add -A && git commit -m "vX.Y.Z: ..." && git push
gh release create vX.Y.Z wp-site-pack-vX.Y.Z.zip -t vX.Y.Z -n "변경 내용"
# 릴리스 끝나면 구버전 zip 자동 정리(최신 1개만):
ls -t wp-site-pack-v*.zip | tail -n +2 | xargs -r rm -f
```
- ⚠ zip 만들면 지난 릴리스 zip 과 파일 목록 diff 로 의도한 차이만 있는지 확인.
- ⚠ 구버전 zip 삭제는 **diff·릴리스가 끝난 맨 마지막에만**.

## 사용자 안내

사용자는 **비개발자**. 한글·쉬운 말로 설명. 바깥으로 나가는 작업(깃 푸시·릴리스·파일 실배포)은 **먼저 확인** 후 진행.
