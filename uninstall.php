<?php
/**
 * 삭제(플러그인 목록에서 "삭제") 시 정리.
 *  - wsp_ 로 시작하는 옵션 전부(활성 모듈 목록 · 모듈별 설정 wsp_mod_{slug} · 업데이트 토큰 ·
 *    발행 로그 wsp_log_scheduled_publish 등).
 *  - wsp_ 로 시작하는 트랜지언트(업데이트 캐시 · ads.txt 캐시 · 애드 프로텍터 클릭 카운트/정리 게이트 ·
 *    예약글 발행 보정 게이트 등).
 *  - 이 플러그인이 만든 표({prefix}wsp_ad_blocks — class-mod-ad-protector.php 의 CREATE TABLE).
 *  - 크론 이벤트(wsp_publish_due_cron — class-mod-scheduled-publish.php).
 *
 * 단순 비활성화가 아니라 "삭제"에서만 실행된다(WP_UNINSTALL_PLUGIN 표준 검사).
 *
 * @package wp-site-pack
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

/**
 * 한 사이트분 정리(옵션 · 트랜지언트 · 표 · 크론). 멀티사이트면 사이트마다 반복 호출한다.
 */
function wsp_uninstall_cleanup_site() {
	global $wpdb;

	// 1) 옵션: wsp_ 로 시작하는 전부. wsp_mod_{slug}(모듈별 설정) · wsp_active_modules ·
	//    wsp_update_token · wsp_flush_rewrite · wsp_log_scheduled_publish 를 모두 포함한다.
	$like = $wpdb->esc_like( 'wsp_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) );

	// 2) 트랜지언트: wp_options 에는 '_transient_wsp_...' · '_transient_timeout_wsp_...' 꼴로 저장돼
	//    위 1) 의 LIKE 'wsp_%' 로는 안 잡힌다(밑줄로 시작). 이름이 동적인 것(업데이트 캐시 wsp_upd_{md5},
	//    애드 프로텍터 클릭 카운트 wsp_adc_{hash} 등)도 이 두 줄이 전부 잡는다.
	$t_like  = $wpdb->esc_like( '_transient_wsp_' ) . '%';
	$tt_like = $wpdb->esc_like( '_transient_timeout_wsp_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $t_like, $tt_like ) );

	// 3) 이 플러그인이 만든 표(class-mod-ad-protector.php: CREATE TABLE {prefix}wsp_ad_blocks).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wsp_ad_blocks" );

	// 4) 크론 이벤트(class-mod-scheduled-publish.php 의 자체 점검 주기).
	wp_clear_scheduled_hook( 'wsp_publish_due_cron' );
}

if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		wsp_uninstall_cleanup_site();
		restore_current_blog();
	}
} else {
	wsp_uninstall_cleanup_site();
}
