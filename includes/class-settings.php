<?php
/**
 * 옵션 저장/조회 헬퍼.
 *  - 모듈별 옵션 분리: wsp_mod_{slug}  (한 모듈 문제로 다른 설정 오염 방지)
 *  - 활성 모듈 단일 옵션: wsp_active_modules = array( slug => 1, ... )
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Settings {

	/** 활성 모듈 목록을 담는 단일 옵션명. */
	const ACTIVE_OPTION = 'wsp_active_modules';

	/** 모듈 옵션명 접두어(뒤에 슬러그가 붙는다). */
	const MOD_PREFIX = 'wsp_mod_';

	/**
	 * 모듈 옵션 조회(기본값과 병합).
	 *
	 * @param string $slug     모듈 슬러그.
	 * @param array  $defaults 모듈이 제공하는 기본값.
	 * @return array
	 */
	public static function get( $slug, $defaults = array() ) {
		$saved = get_option( self::MOD_PREFIX . $slug, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	/**
	 * 모듈 옵션 저장.
	 *
	 * @param string $slug 모듈 슬러그.
	 * @param array  $data 저장할 값(이미 sanitize 된 것).
	 * @return bool
	 */
	public static function set( $slug, $data ) {
		return update_option( self::MOD_PREFIX . $slug, is_array( $data ) ? $data : array() );
	}

	/**
	 * 활성 모듈 목록(slug => 1).
	 *
	 * @return array
	 */
	public static function active_map() {
		$map = get_option( self::ACTIVE_OPTION, array() );
		return is_array( $map ) ? $map : array();
	}

	/**
	 * 모듈이 활성인지.
	 *
	 * @param string $slug 모듈 슬러그.
	 * @return bool
	 */
	public static function is_active( $slug ) {
		$map = self::active_map();
		return ! empty( $map[ $slug ] );
	}

	/**
	 * 모듈 활성/비활성 토글 저장.
	 *
	 * @param string $slug   모듈 슬러그.
	 * @param bool   $active 활성 여부.
	 * @return void
	 */
	public static function set_active( $slug, $active ) {
		$map = self::active_map();
		if ( $active ) {
			$map[ $slug ] = 1;
		} else {
			unset( $map[ $slug ] );
		}
		update_option( self::ACTIVE_OPTION, $map );
	}
}
