<?php
/**
 * 통계 플러그인(wp-visitor-stats) 데이터 재사용 브릿지.
 *  - 있으면: 국가(class-geo) 등을 읽어 재사용.
 *  - 없으면: 각 모듈이 자체 최소 기능으로 폴백.
 *  - 원칙: 통계 DB 에 쓰지 않는다(읽기 전용). 통계는 측정 전용으로 보존.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Stats_Bridge {

	/**
	 * 통계 플러그인에서 실제로 재사용할 수 있는 기능이 하나라도 있는지.
	 * 클래스가 있는지만 보면 안 된다 — 쓰려는 메서드가 없으면 '연결됨'이라고 표시해 놓고
	 * 실제로는 한 번도 재사용되지 않는다(예전에 없는 WVS_Stats::client_ip 를 찾고 있었다).
	 */
	public static function available() {
		return self::has_client_ip() || self::has_geo();
	}

	/** IP 판정(WVS_Tracker::client_ip)을 재사용할 수 있는지. */
	public static function has_client_ip() {
		return class_exists( 'WVS_Tracker' ) && method_exists( 'WVS_Tracker', 'client_ip' );
	}

	/** 국가 판별 기능(WVS_Geo)이 있는지. */
	public static function has_geo() {
		return class_exists( 'WVS_Geo' ) && method_exists( 'WVS_Geo', 'lookup' );
	}

	/**
	 * 현재 방문자 IP.
	 *
	 * 기본은 서버가 직접 본 접속 주소(REMOTE_ADDR) 하나뿐이다.
	 * X-Forwarded-For·CF-Connecting-IP 같은 전달 헤더는 **누구나 지어낼 수 있어서**,
	 * 그대로 믿으면 ①남의 IP 를 적어 넣어 그 사람을 차단시키거나 ②클릭마다 다른 IP 를 적어
	 * 차단을 피할 수 있다. 그래서 "프록시/CDN 뒤에 있음" 을 켠 사이트에서만 헤더를 읽는다.
	 * (CDN 뒤에서는 REMOTE_ADDR 이 CDN 서버 주소라, 켜지 않으면 방문자가 모두 한 IP 로 보인다.)
	 *
	 * @param bool $trust_proxy 프록시/CDN 뒤에 있음(설정에서 켠 경우에만 true).
	 * @return string 판별 실패하면 ''.
	 */
	public static function client_ip( $trust_proxy = false ) {
		if ( ! $trust_proxy ) {
			return self::remote_addr();
		}
		// 통계 플러그인이 있으면 그쪽이 정한 방식을 그대로 쓴다 — 두 플러그인이 같은 방문자를
		// 다른 IP 로 보면 통계의 제외 IP 설정과 여기 허용/차단 IP 가 서로 어긋난다.
		if ( self::has_client_ip() ) {
			$ip = WVS_Tracker::client_ip();
			if ( is_string( $ip ) && '0.0.0.0' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
		// 자체 폴백: 널리 쓰는 전달 헤더 → 없으면 REMOTE_ADDR.
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' );
		foreach ( $keys as $k ) {
			if ( empty( $_SERVER[ $k ] ) ) {
				continue;
			}
			$val = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			// X-Forwarded-For 는 "방문자, 프록시1, 프록시2" 순서라 맨 앞이 방문자.
			$val = trim( explode( ',', $val )[0] );
			if ( filter_var( $val, FILTER_VALIDATE_IP ) ) {
				return $val;
			}
		}
		return self::remote_addr();
	}

	/** 서버가 직접 본 접속 주소. 지어낼 수 없는 유일한 값이다. */
	protected static function remote_addr() {
		if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$val = trim( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) );
		return filter_var( $val, FILTER_VALIDATE_IP ) ? $val : '';
	}

	/** IP → 해시(개인정보 최소화, 저장·비교용). */
	public static function ip_hash( $ip ) {
		return $ip ? substr( hash( 'sha256', $ip . '|wsp' ), 0, 32 ) : '';
	}

	/**
	 * IP → 국가코드(대문자 2자). 통계 브릿지 우선, 없으면 CDN 헤더 폴백.
	 *
	 * @param string $ip
	 * @return string 예: 'KR'. 모르면 ''.
	 */
	public static function country_code( $ip = '' ) {
		if ( self::has_geo() ) {
			$info = WVS_Geo::lookup( $ip );
			if ( is_array( $info ) && ! empty( $info['code'] ) ) {
				return $info['code'];
			}
		}
		// 폴백: Cloudflare 등이 주는 헤더.
		$headers = array( 'HTTP_CF_IPCOUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_GEOIP_COUNTRY_CODE' );
		foreach ( $headers as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$code = strtoupper( preg_replace( '/[^A-Za-z]/', '', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) ) );
				if ( 2 === strlen( $code ) ) {
					return $code;
				}
			}
		}
		return '';
	}

	/**
	 * 애드센스 pub-id 추천값(통계 class-adsense 가 있으면). 모르면 ''.
	 *
	 * @return string
	 */
	public static function adsense_pub_id() {
		// 통계 플러그인에 있는 실제 메서드는 WVS_AdSense::account() 이고
		// 'accounts/pub-1234...' 꼴로 돌려준다(class-adsense.php 의 account()).
		// 예전에는 없는 publisher_id() 를 찾고 있어서 늘 '' 이었다.
		if ( class_exists( 'WVS_AdSense' ) && method_exists( 'WVS_AdSense', 'account' ) ) {
			$raw = (string) WVS_AdSense::account();
			if ( '' !== $raw ) {
				$id = preg_replace( '#^accounts/#', '', $raw );
				if ( 0 === strpos( $id, 'pub-' ) ) {
					return $id;
				}
			}
		}
		return '';
	}
}
