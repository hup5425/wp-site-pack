<?php
/**
 * 워프글쓰기(wp-auto-writer)의 「웹마스터 도구」가 부르는 REST 창.
 *
 * 무엇을 하나
 *  · 다음 웹마스터도구 PIN 을 새로 받으면 robots.txt 의 `#DaumWebMasterTool:` 줄을 바꿔야 한다.
 *    워프글쓰기는 윈도우 PC 에서 돌고 서버 SSH 가 없다 — 그래서 앱 비밀번호로 이 창을 부른다.
 *  · 네이버 서치어드바이저 소유 확인 메타 태그 값을 넣는다.
 *  · 캐시를 비운다. 다음은 robots.txt 를 캐시에서 옛 내용으로 읽으면 로그인이 실패한다
 *    (사장님 실측 2026-09-14: Breeze [Purge All Cache] 뒤에야 로그인됐다).
 *
 * 모듈에 두지 않은 까닭: robots 줄은 Ads 매니저, 네이버 메타는 자동 인덱싱 몫이라 두 모듈에 걸친다.
 * 이 파일은 각 모듈의 공개 메서드만 부르고 모듈 설정을 직접 만지지 않는다(모듈 격리).
 *
 * 권한: 관리자(manage_options). 워프글쓰기는 사이트 등록 때 넣은 앱 비밀번호로 부른다.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Rest {

	const NS = 'wsp/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$admin = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route( self::NS, '/webmaster', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'info' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/webmaster/daum', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'daum' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/webmaster/naver', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'naver' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/cache/purge', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'purge' ),
			'permission_callback' => $admin,
		) );
	}

	/** 지금 상태 — 사이트팩 버전·켜진 모듈·robots 의 다음 줄·네이버 메타 값. */
	public static function info() {
		$ads  = WSP_Core::module( 'ads_manager' );
		$sc   = WSP_Core::module( 'site_codes' );
		$line = $ads ? $ads->daum_line() : '';
		return array(
			'version'      => WSP_VERSION,
			'modules'      => array_keys( array_filter( WSP_Settings::active_map() ) ),
			'daum_line'    => $line,
			'naver_verify' => $sc ? (string) $sc->settings()['verify_naver'] : '',
			'feed'         => get_feed_link(),
			// 예전엔 home_url('/sitemap_index.xml') 고정이라 사이트팩 SEO 가 없는 곳(bcbnews 는 wp-sitemap.xml)에서 틀렸다.
			'sitemap'      => $ads ? $ads->sitemap_url() : home_url( '/sitemap_index.xml' ),
		);
	}

	/** robots.txt 의 다음 인증 줄을 바꾸고(없으면 붙이고) 캐시를 비운다. */
	public static function daum( WP_REST_Request $req ) {
		$line = trim( (string) $req->get_param( 'line' ) );
		if ( 0 !== strpos( $line, '#DaumWebMasterTool:' ) || false !== strpos( $line, "\n" ) ) {
			return new WP_Error( 'wsp_bad_line', '#DaumWebMasterTool: 로 시작하는 한 줄이어야 합니다.', array( 'status' => 400 ) );
		}
		$ads = WSP_Core::module( 'ads_manager' );
		if ( ! $ads ) {
			return new WP_Error( 'wsp_no_module', 'Ads 매니저 모듈을 불러오지 못했습니다.', array( 'status' => 500 ) );
		}
		$robots = $ads->put_daum_line( $line );
		self::clear_caches();
		return array( 'ok' => true, 'robots' => $robots );
	}

	/** 네이버 소유 확인 메타 태그 값(naver-site-verification 의 content)을 넣는다. */
	public static function naver( WP_REST_Request $req ) {
		$code = trim( (string) $req->get_param( 'verify' ) );
		if ( '' === $code || ! preg_match( '/^[A-Za-z0-9_\-]+$/', $code ) ) {
			return new WP_Error( 'wsp_bad_code', '영문·숫자로 된 확인 값이어야 합니다.', array( 'status' => 400 ) );
		}
		// 소유 확인 태그는 「소유 확인·분석 코드」 모듈이 맡는다(예전엔 자동 인덱싱 안에 있었다 — 0.4.6).
		$sc = WSP_Core::module( 'site_codes' );
		if ( ! $sc ) {
			return new WP_Error( 'wsp_no_module', '소유 확인·분석 코드 모듈을 불러오지 못했습니다.', array( 'status' => 500 ) );
		}
		$sc->put_naver_verification( $code );
		self::clear_caches();
		return array( 'ok' => true );
	}

	public static function purge() {
		self::clear_caches();
		return array( 'ok' => true );
	}

	/** Breeze 페이지 캐시·Varnish·객체 캐시를 비운다(Breeze 가 없으면 객체 캐시만). */
	protected static function clear_caches() {
		do_action( 'breeze_clear_all_cache' );
		do_action( 'breeze_clear_varnish' );
		wp_cache_flush();
	}
}
