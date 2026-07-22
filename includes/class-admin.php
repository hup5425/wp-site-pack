<?php
/**
 * 관리자 UI — 대시보드(카드 그리드) + 모듈 설정 페이지 라우팅 + 저장 처리.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Admin {

	const CAP  = 'manage_options';
	const SLUG = 'wp-site-pack';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		// 저장/토글 POST 는 화면 출력 전에 처리(리다이렉트로 재제출 방지).
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		// 업데이트 확인/설치 AJAX.
		add_action( 'wp_ajax_wsp_check_update', array( __CLASS__, 'ajax_check_update' ) );
		add_action( 'wp_ajax_wsp_do_update', array( __CLASS__, 'ajax_do_update' ) );
	}

	public static function menu() {
		add_menu_page( '사이트 팩', '사이트 팩', self::CAP, self::SLUG, array( __CLASS__, 'render' ), 'dashicons-screenoptions', 58 );
	}

	public static function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG !== $page ) {
			return;
		}
		wp_enqueue_style( 'wsp-admin', WSP_URL . 'assets/admin.css', array(), WSP_VERSION );
		// 스크롤 팝업 등에서 미디어 라이브러리(이미지/동영상 선택) 사용.
		wp_enqueue_media();
		wp_enqueue_script( 'wsp-admin', WSP_URL . 'assets/admin.js', array( 'jquery' ), WSP_VERSION, true );
		wp_localize_script( 'wsp-admin', 'WSP', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'wsp_ajax' ),
		) );
	}

	/* ------------------------------ 업데이트 확인/설치 ------------------------------ */

	protected static function ajax_guard() {
		if ( ! check_ajax_referer( 'wsp_ajax', 'nonce', false ) || ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => '권한이 없습니다.' ), 403 );
		}
	}

	/** GitHub 최신 릴리스와 현재 버전 비교(캐시 비우고 강제 재검사). */
	public static function ajax_check_update() {
		self::ajax_guard();

		if ( '' === WSP_UPDATE_REPO ) {
			wp_send_json_success( array(
				'current'    => WSP_VERSION,
				'latest'     => WSP_VERSION,
				'has_update' => false,
				'no_repo'    => true,
				'message'    => '자동 업데이트 저장소(GitHub)가 아직 설정되지 않았습니다.',
			) );
		}

		delete_transient( 'wsp_upd_' . md5( WSP_UPDATE_REPO ) );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$basename = plugin_basename( WSP_FILE );
		$t        = get_site_transient( 'update_plugins' );
		$has      = is_object( $t ) && isset( $t->response[ $basename ] );

		$latest = WSP_VERSION;
		if ( $has ) {
			$latest = $t->response[ $basename ]->new_version;
		} elseif ( is_object( $t ) && isset( $t->no_update[ $basename ] ) ) {
			$latest = $t->no_update[ $basename ]->new_version;
		}

		wp_send_json_success( array(
			'current'     => WSP_VERSION,
			'latest'      => $latest,
			'has_update'  => $has,
			'plugins_url' => self_admin_url( 'plugins.php' ),
		) );
	}

	/** 새 버전을 즉시 설치(WP 업그레이더). 데이터 보존. */
	public static function ajax_do_update() {
		self::ajax_guard();
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_send_json_error( array( 'message' => '업데이트 권한이 없습니다.' ), 403 );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		if ( ! class_exists( 'WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}

		if ( '' !== WSP_UPDATE_REPO ) {
			delete_transient( 'wsp_upd_' . md5( WSP_UPDATE_REPO ) );
		}
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		$plugin     = plugin_basename( WSP_FILE );
		$was_active = is_plugin_active( $plugin );

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin );

		if ( $skin->get_errors()->has_errors() ) {
			wp_send_json_error( array( 'message' => $skin->get_errors()->get_error_message() ), 500 );
		}
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => '업데이트할 새 버전을 찾지 못했습니다(잠시 후 다시 시도).' ), 500 );
		}

		// 업그레이더가 교체 중 비활성화했다면 원래대로 복구(조용히).
		if ( $was_active && ! is_plugin_active( $plugin ) ) {
			activate_plugin( $plugin, '', false, true );
		}

		wp_send_json_success( array( 'message' => '업데이트 완료! 곧 새로고침됩니다.' ) );
	}

	/**
	 * 현재 URL 이 가리키는 모듈(있으면). ?module=slug
	 *
	 * @return WSP_Module|null
	 */
	protected static function current_module() {
		$slug = isset( $_GET['module'] ) ? sanitize_key( wp_unslash( $_GET['module'] ) ) : '';
		return $slug ? WSP_Core::module( $slug ) : null;
	}

	/* ------------------------------ POST 처리 ------------------------------ */

	public static function handle_post() {
		if ( empty( $_POST['wsp_action'] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['wsp_action'] ) );

		if ( 'toggle_module' === $action ) {
			check_admin_referer( 'wsp_toggle' );
			$slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
			$mod  = WSP_Core::module( $slug );
			if ( $mod ) {
				$want = ! empty( $_POST['active'] );
				$was  = WSP_Settings::is_active( $slug );
				WSP_Settings::set_active( $slug, $want );
				// 켜질 때 on_activate(테이블/rewrite), 꺼질 때 on_deactivate(cron 해제). rewrite 는 다음 요청서 flush.
				if ( $want && ! $was ) {
					$mod->on_activate();
					update_option( 'wsp_flush_rewrite', 1 );
				} elseif ( ! $want && $was ) {
					$mod->on_deactivate();
					update_option( 'wsp_flush_rewrite', 1 );
				}
			}
			self::redirect_back( $slug ? array( 'module' => $slug ) : array(), 'toggled' );
		}

		if ( 'save_update_token' === $action ) {
			check_admin_referer( 'wsp_token' );
			$tok = isset( $_POST['update_token'] ) ? sanitize_text_field( wp_unslash( $_POST['update_token'] ) ) : '';
			if ( ! empty( $_POST['clear_token'] ) ) {
				delete_option( 'wsp_update_token' );
			} elseif ( '' !== $tok ) {
				update_option( 'wsp_update_token', $tok );
			}
			// 토큰 바뀌면 릴리스 캐시 비워 다음 확인 때 즉시 반영.
			if ( '' !== WSP_UPDATE_REPO ) {
				delete_transient( 'wsp_upd_' . md5( WSP_UPDATE_REPO ) );
			}
			self::redirect_back( array(), 'saved' );
		}

		if ( 'save_module' === $action ) {
			$slug = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
			check_admin_referer( 'wsp_save_' . $slug );
			$mod = WSP_Core::module( $slug );
			if ( $mod ) {
				$clean = $mod->sanitize( wp_unslash( $_POST ) );
				WSP_Settings::set( $slug, $clean );
			}
			self::redirect_back( array( 'module' => $slug ), 'saved' );
		}
	}

	protected static function redirect_back( $args, $notice ) {
		$args['page']        = self::SLUG;
		$args['wsp_notice']  = $notice;
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/* ------------------------------ 렌더 ------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$mod = self::current_module();
		echo '<div class="wrap wsp-wrap">';
		self::notice();
		if ( $mod ) {
			require WSP_DIR . 'admin/settings-page.php';
		} else {
			require WSP_DIR . 'admin/dashboard.php';
		}
		echo '</div>';
	}

	protected static function notice() {
		$n = isset( $_GET['wsp_notice'] ) ? sanitize_key( wp_unslash( $_GET['wsp_notice'] ) ) : '';
		if ( ! $n ) {
			return;
		}
		$msg = array(
			'saved'   => '설정을 저장했습니다.',
			'toggled' => '모듈 상태를 변경했습니다.',
		);
		if ( isset( $msg[ $n ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg[ $n ] ) . '</p></div>';
		}
	}
}
