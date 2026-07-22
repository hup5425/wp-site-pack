<?php
/**
 * Plugin Name: WP Site Pack
 * Description: 모듈형 사이트 운영 유틸리티 팩. 헤더/푸터, 예약글 발행 보장, 자동 인덱싱(IndexNow), Ads 매니저, 소셜 공유, 스마트 스크롤 팝업, 애드 프로텍터를 모듈 On/Off 로 제공합니다.
 * Version: 0.2.2
 * Author: You
 * License: GPL-2.0+
 * Text Domain: wp-site-pack
 *
 * ── 네이밍은 가칭 ── 폴더 wp-site-pack / 접두어 wsp_ / 상수 WSP_ / 클래스 WSP_*.
 *   브랜드 확정 시 일괄 치환. (기획서 §6, 인계서 §1-6 참조)
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WSP_VERSION' ) ) {
	return;
}

define( 'WSP_VERSION', '0.2.2' );
define( 'WSP_FILE', __FILE__ );
define( 'WSP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSP_URL', plugin_dir_url( __FILE__ ) );

// 자동 업데이트 소스 — GitHub 'owner/repo'. 비우면 업데이트 비활성(안전).
if ( ! defined( 'WSP_UPDATE_REPO' ) ) {
	define( 'WSP_UPDATE_REPO', 'hup5425/wp-site-pack' );
}
// 비공개 저장소 접근용 fine-grained read-only 토큰(이 저장소 Contents:Read 전용).
// wp-config.php 에서 재정의 가능. ⚠ 넓은 권한의 토큰을 넣지 말 것(읽기전용·단일저장소만).
if ( ! defined( 'WSP_UPDATE_TOKEN' ) ) {
	define( 'WSP_UPDATE_TOKEN', '' );
}

/* ------------------------------ 코어 로드 ------------------------------ */

require_once WSP_DIR . 'includes/class-settings.php';
require_once WSP_DIR . 'includes/class-module.php';
require_once WSP_DIR . 'includes/class-stats-bridge.php';
require_once WSP_DIR . 'includes/class-core.php';
require_once WSP_DIR . 'includes/class-assets.php';
require_once WSP_DIR . 'includes/class-admin.php';
require_once WSP_DIR . 'includes/class-updater.php';

// 부트스트랩: 모듈 등록 → 활성 모듈만 훅 연결.
add_action( 'plugins_loaded', array( 'WSP_Core', 'boot' ) );

// 관리자 UI(대시보드 + 모듈 설정).
WSP_Admin::init();

// 모듈 토글 등으로 예약된 rewrite flush 를 다음 init 에서 1회 처리(가상 서빙 규칙 반영).
add_action(
	'init',
	function () {
		if ( get_option( 'wsp_flush_rewrite' ) ) {
			flush_rewrite_rules();
			delete_option( 'wsp_flush_rewrite' );
		}
	},
	99
);

// 자동 업데이트 — 관리자/크론 컨텍스트에서만(프런트 부하 없음).
// 토큰 우선순위: 상수(WSP_UPDATE_TOKEN, wp-config 등) > DB 옵션(대시보드에서 입력) > 없음(공개 저장소용).
if ( ( is_admin() || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) ) && '' !== WSP_UPDATE_REPO ) {
	$wsp_token = WSP_UPDATE_TOKEN;
	if ( '' === $wsp_token ) {
		$wsp_token = (string) get_option( 'wsp_update_token', '' );
	}
	WSP_Updater::init( WSP_UPDATE_REPO, plugin_basename( WSP_FILE ), $wsp_token );
}

// 플러그인 목록에 "대시보드" 바로가기.
add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=wp-site-pack' ) ) . '">대시보드</a>';
		return $links;
	}
);

/* ------------------------------ 활성화 / 비활성화 ------------------------------ */

register_activation_hook(
	__FILE__,
	function () {
		// 활성 모듈 목록 옵션 최초 생성(기본: 전부 비활성).
		if ( false === get_option( WSP_Settings::ACTIVE_OPTION ) ) {
			add_option( WSP_Settings::ACTIVE_OPTION, array() );
		}
		// 활성 모듈의 활성화 훅(rewrite flush·테이블 생성 등) 실행.
		WSP_Core::boot();
		WSP_Core::on_activate();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		WSP_Core::boot();
		WSP_Core::on_deactivate();
		flush_rewrite_rules();
	}
);
