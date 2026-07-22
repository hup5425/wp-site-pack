=== WP Site Pack ===
Contributors: you
Tags: utility, header footer, indexnow, ads.txt, social share, popup, ad protection, scheduled post
Requires at least: 5.5
Tested up to: 6.5
Requires PHP: 7.2
Stable tag: 0.1.0
License: GPL-2.0+

모듈형 사이트 운영 유틸리티 팩. 필요한 기능만 켜서 씁니다.

== Description ==

여러 사이트 운영 유틸리티를 하나의 플러그인에 담고, 기능별로 On/Off 하는 모듈형 통합 팩입니다.
켠 모듈만 로드되어 가볍고, 한 모듈의 문제가 다른 모듈로 번지지 않습니다.

= 모듈 =
* 헤더 & 푸터 — 원시 코드를 head/body/footer 에 삽입
* 예약글 발행 보장 — 방문자가 없어도 예약 시각 지난 글을 강제 발행(발행 누락 방지)
* 자동 인덱싱(IndexNow) — 글 발행/수정 시 검색엔진에 색인 요청
* Ads 매니저 — ads.txt / robots.txt / 사이트 인증파일 가상 서빙
* 소셜 공유 — 페이스북·카카오톡·네이버·라인·X 공유 버튼
* 스마트 스크롤(팝업) — N% 스크롤 시 팝업 표시
* 애드 프로텍터 — 광고 과다 클릭 IP 차단(통계 플러그인 연동)

통계 플러그인(WP Visitor Stats)이 설치돼 있으면 IP·국가 데이터를 읽기 전용으로 재사용합니다(없어도 자체 동작).

== Changelog ==

= 0.1.0 =
* 최초 버전. 모듈 프레임워크(레지스트리·대시보드·설정) + 7개 모듈 구현.
