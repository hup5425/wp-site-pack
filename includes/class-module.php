<?php
/**
 * 모듈 추상 클래스. 모든 모듈은 이 계약을 구현한다.
 *  - 활성 상태일 때만 WSP_Core 가 register() 를 호출 → 비활성 모듈 코드는 런타임에 안 걸림(격리·경량).
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class WSP_Module {

	/** 슬러그(예: 'header_footer'). 옵션명·URL 파라미터에 쓰인다. */
	abstract public function id();

	/** 대시보드/메뉴에 보일 한글 표시명. */
	abstract public function name();

	/** 카드에 보일 한 줄 설명. */
	abstract public function desc();

	/** dashicons 아이콘 클래스(예: 'dashicons-editor-code'). */
	public function icon() {
		return 'dashicons-admin-generic';
	}

	/** 기본 설정값. */
	public function default_settings() {
		return array();
	}

	/**
	 * 활성 상태일 때만 호출된다. 실제 프론트/관리자 훅을 여기서 등록.
	 * (비활성 모듈은 이 메서드가 아예 호출되지 않는다.)
	 */
	abstract public function register();

	/** 모듈 설정 폼 렌더(설정 페이지 본문). */
	public function render_settings() {
		echo '<p>이 모듈에는 별도 설정이 없습니다.</p>';
	}

	/**
	 * 저장 전 입력 검증. 반환값이 wsp_mod_{slug} 로 저장된다.
	 *
	 * @param array $input $_POST 원시 입력.
	 * @return array
	 */
	public function sanitize( $input ) {
		return array();
	}

	/** 플러그인 활성화 시(모듈이 활성일 때만) 1회 — 테이블 생성·rewrite 등록 등. */
	public function on_activate() {}

	/** 플러그인 비활성화 시(모듈이 활성일 때만) 1회 — cron 해제 등. */
	public function on_deactivate() {}

	/* ------------------------------ 공통 헬퍼 ------------------------------ */

	/** 이 모듈의 현재 설정(기본값 병합). */
	public function settings() {
		return WSP_Settings::get( $this->id(), $this->default_settings() );
	}

	/** 이 모듈이 활성인지. */
	public function is_active() {
		return WSP_Settings::is_active( $this->id() );
	}

	/** 설정 페이지 URL. */
	public function settings_url() {
		return admin_url( 'admin.php?page=wp-site-pack&module=' . rawurlencode( $this->id() ) );
	}
}
