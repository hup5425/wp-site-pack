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

	/** 통계 플러그인이 활성/로드되어 있는지. */
	public static function available() {
		return class_exists( 'WVS_Geo' ) || class_exists( 'WVS_Stats' );
	}

	/** 국가 판별 기능(WVS_Geo)이 있는지. */
	public static function has_geo() {
		return class_exists( 'WVS_Geo' ) && method_exists( 'WVS_Geo', 'lookup' );
	}

	/**
	 * 현재 방문자 IP. 통계의 판정 방식과 최대한 맞추되, 없으면 자체 폴백.
	 *
	 * @return string
	 */
	public static function client_ip() {
		// 통계 플러그인이 공개 헬퍼를 제공하면 우선 사용.
		if ( class_exists( 'WVS_Stats' ) && method_exists( 'WVS_Stats', 'client_ip' ) ) {
			$ip = WVS_Stats::client_ip();
			if ( $ip ) {
				return $ip;
			}
		}
		// 자체 폴백(프록시 헤더 → REMOTE_ADDR).
		$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
		foreach ( $keys as $k ) {
			if ( ! empty( $_SERVER[ $k ] ) ) {
				$val = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
				$val = trim( explode( ',', $val )[0] );
				if ( filter_var( $val, FILTER_VALIDATE_IP ) ) {
					return $val;
				}
			}
		}
		return '';
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
		if ( class_exists( 'WVS_AdSense' ) && method_exists( 'WVS_AdSense', 'publisher_id' ) ) {
			$id = WVS_AdSense::publisher_id();
			if ( $id ) {
				return (string) $id;
			}
		}
		return '';
	}
}
