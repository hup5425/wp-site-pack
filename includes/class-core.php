<?php
/**
 * 모듈 레지스트리.
 *  - 모든 모듈을 등록 → 활성(active) 모듈만 register() 실행.
 *  - 비활성 모듈은 클래스 로드만 되고 런타임 훅은 안 걸림.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Core {

	/** @var WSP_Module[] slug => 인스턴스 */
	protected static $modules = array();

	/** @var bool 중복 부트 방지. */
	protected static $booted = false;

	/** 등록할 모듈 클래스 파일 → 클래스명. 여기 추가하면 대시보드에 자동 노출. */
	protected static function module_map() {
		return array(
			'class-mod-header-footer.php'      => 'WSP_Mod_Header_Footer',
			'class-mod-scheduled-publish.php'  => 'WSP_Mod_Scheduled_Publish',
			'class-mod-auto-index.php'         => 'WSP_Mod_Auto_Index',
			'class-mod-ads-manager.php'        => 'WSP_Mod_Ads_Manager',
			'class-mod-social-share.php'       => 'WSP_Mod_Social_Share',
			'class-mod-scroll-popup.php'       => 'WSP_Mod_Scroll_Popup',
			'class-mod-ad-protector.php'       => 'WSP_Mod_Ad_Protector',
		);
	}

	/**
	 * 모듈을 로드·인스턴스화하고, 활성 모듈만 register() 실행.
	 * plugins_loaded 및 활성화/비활성화 훅에서 호출된다(1회만 실제 동작).
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$dir = WSP_DIR . 'includes/modules/';
		foreach ( self::module_map() as $file => $class ) {
			$path = $dir . $file;
			if ( ! file_exists( $path ) ) {
				continue;
			}
			require_once $path;
			if ( ! class_exists( $class ) ) {
				continue;
			}
			/** @var WSP_Module $mod */
			$mod = new $class();
			self::$modules[ $mod->id() ] = $mod;
		}

		// 활성 모듈만 훅 등록.
		foreach ( self::$modules as $slug => $mod ) {
			if ( WSP_Settings::is_active( $slug ) ) {
				$mod->register();
			}
		}
	}

	/** 등록된 모든 모듈(대시보드 나열용). */
	public static function modules() {
		return self::$modules;
	}

	/**
	 * 슬러그로 모듈 인스턴스 조회.
	 *
	 * @param string $slug
	 * @return WSP_Module|null
	 */
	public static function module( $slug ) {
		return isset( self::$modules[ $slug ] ) ? self::$modules[ $slug ] : null;
	}

	/** 활성화 시: 활성 모듈들의 on_activate() 실행. */
	public static function on_activate() {
		foreach ( self::$modules as $slug => $mod ) {
			if ( WSP_Settings::is_active( $slug ) ) {
				$mod->on_activate();
			}
		}
	}

	/** 비활성화 시: 활성 모듈들의 on_deactivate() 실행. */
	public static function on_deactivate() {
		foreach ( self::$modules as $slug => $mod ) {
			if ( WSP_Settings::is_active( $slug ) ) {
				$mod->on_deactivate();
			}
		}
	}
}
