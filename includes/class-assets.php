<?php
/**
 * 프론트 자원 공용 헬퍼. 모듈이 자기 front CSS/JS 를 간단히 등록하도록.
 *  - 파일 경로: assets/front/{name}.css , assets/front/{name}.js
 *  - 버전은 WSP_VERSION 고정(캐시 무효화는 릴리스 때 자동).
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Assets {

	/**
	 * 프론트 CSS 등록.
	 *
	 * @param string $name 파일명(확장자 제외). 핸들은 'wsp-'.$name.
	 */
	public static function front_style( $name ) {
		$rel = 'assets/front/' . $name . '.css';
		if ( file_exists( WSP_DIR . $rel ) ) {
			wp_enqueue_style( 'wsp-' . $name, WSP_URL . $rel, array(), WSP_VERSION );
		}
	}

	/**
	 * 프론트 JS 등록.
	 *
	 * @param string $name 파일명(확장자 제외). 핸들은 'wsp-'.$name.
	 * @param array  $data wp_localize_script 로 넘길 데이터(있으면 'WSP_'.대문자 객체명).
	 * @param string $obj  JS 전역 객체명(기본 'WSP_DATA').
	 */
	public static function front_script( $name, $data = null, $obj = 'WSP_DATA' ) {
		$rel = 'assets/front/' . $name . '.js';
		if ( ! file_exists( WSP_DIR . $rel ) ) {
			return;
		}
		$handle = 'wsp-' . $name;
		wp_enqueue_script( $handle, WSP_URL . $rel, array(), WSP_VERSION, true );
		if ( is_array( $data ) ) {
			wp_localize_script( $handle, $obj, $data );
		}
	}
}
