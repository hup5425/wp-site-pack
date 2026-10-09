<?php
/**
 * 모듈: 스니펫 — 켜고 끄는 작은 기능 모음.
 *  - 스니펫 하나 = list() 의 한 줄 + snippet_{키}() 함수 하나. 화면의 체크 칸은 list() 에서 저절로 그려진다.
 *  - 지금 있는 것: 「댓글 기능 없애기」(no_comments).
 *  - 이 모듈과 「댓글 기능 없애기」는 처음부터 켜져 있다(사장님 결정 2026-10-09). 한 번 켠 뒤로는 화면에서 끈 대로 둔다.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Snippets extends WSP_Module {

	/** 처음 한 번 이 모듈을 켰는지. */
	const DEFAULT_ON_OPTION = 'wsp_snippets_default_on';

	/** 지우는 댓글 종류 — 워드프레스 자체 댓글만(다른 플러그인이 댓글 표에 넣는 기록은 건드리지 않는다). */
	const COMMENT_TYPES = "'', 'comment', 'pingback', 'trackback'";

	public function id()   { return 'snippets'; }
	public function name() { return '스니펫'; }
	public function desc() { return '댓글 기능 없애기처럼, 켜고 끄기만 하면 되는 작은 기능 모음입니다.'; }
	public function icon() { return 'dashicons-admin-tools'; }

	/**
	 * 스니펫 목록. 키 => array( 화면 이름, 설명, 기본값 ).
	 * 새 스니펫: 여기 한 줄 + snippet_{키}() 함수.
	 */
	public static function snippets() {
		return array(
			'no_comments' => array(
				'댓글 기능 없애기',
				'글·페이지의 댓글 쓰기 칸과 댓글 목록, 관리자 화면의 「댓글」 메뉴를 없애고 새 댓글·핑백·트랙백을 받지 않습니다.',
				1,
			),
		);
	}

	public function default_settings() {
		$d = array();
		foreach ( self::snippets() as $key => $meta ) {
			$d[ $key ] = $meta[2];
		}
		return $d;
	}

	/**
	 * 한 번만: 이 모듈을 켠다. WSP_Core::boot() 가 활성 모듈 목록을 읽기 전에 부른다.
	 * 그 뒤에 화면에서 끄면 다시 켜지 않는다.
	 */
	public static function maybe_default_on() {
		if ( get_option( self::DEFAULT_ON_OPTION ) ) {
			return;
		}
		WSP_Settings::set_active( 'snippets', 1 );
		update_option( self::DEFAULT_ON_OPTION, WSP_VERSION );
	}

	public function register() {
		$s = $this->settings();
		foreach ( self::snippets() as $key => $meta ) {
			if ( ! empty( $s[ $key ] ) ) {
				call_user_func( array( $this, 'snippet_' . $key ) );
			}
		}
	}

	/* ------------------------------ 댓글 기능 없애기 ------------------------------ */

	protected function snippet_no_comments() {
		// 글 종류에서 댓글·트랙백 지원을 뺀다 — 편집 화면의 토론 상자와 글 목록의 댓글 칸이 함께 사라진다.
		add_action( 'init', array( $this, 'nc_remove_support' ), 100 );

		// 방문자 화면: 닫힘 · 목록 없음 · 개수 0.
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 20 );
		add_filter( 'get_comments_number', '__return_zero', 20 );
		add_filter( 'comments_template', array( $this, 'nc_blank_template' ), 20 );
		add_filter( 'render_block', array( $this, 'nc_hide_blocks' ), 20, 2 );
		add_action( 'widgets_init', array( $this, 'nc_remove_widget' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'nc_remove_reply_script' ), 100 );

		// 밖에서 들어오는 길: 댓글 피드 · REST · XML-RPC · 핑백 머리줄.
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_action( 'template_redirect', array( $this, 'nc_redirect_feed' ), 1 );
		add_filter( 'rest_endpoints', array( $this, 'nc_rest_endpoints' ) );
		add_filter( 'xmlrpc_methods', array( $this, 'nc_xmlrpc_methods' ) );
		add_filter( 'wp_headers', array( $this, 'nc_headers' ) );

		// 관리자 화면.
		add_action( 'admin_menu', array( $this, 'nc_admin_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'nc_admin_redirect' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'nc_dashboard' ), 999 );
		add_action( 'wp_before_admin_bar_render', array( $this, 'nc_admin_bar' ) );
	}

	public function nc_remove_support() {
		foreach ( get_post_types() as $type ) {
			if ( post_type_supports( $type, 'comments' ) ) {
				remove_post_type_support( $type, 'comments' );
			}
			if ( post_type_supports( $type, 'trackbacks' ) ) {
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
	}

	/** 테마가 댓글 틀을 불러도 빈 파일이 나가게. */
	public function nc_blank_template() {
		return WSP_DIR . 'includes/modules/snippets/blank-comments.php';
	}

	/** 댓글 블록인지(블록 테마·블록 위젯). */
	public static function is_comment_block( $name ) {
		$name = (string) $name;
		return 0 === strpos( $name, 'core/comment' )
			|| in_array( $name, array( 'core/post-comments', 'core/post-comments-form', 'core/post-comments-count', 'core/post-comments-link', 'core/latest-comments' ), true );
	}

	public function nc_hide_blocks( $html, $block ) {
		return ( isset( $block['blockName'] ) && self::is_comment_block( $block['blockName'] ) ) ? '' : $html;
	}

	public function nc_remove_widget() {
		unregister_widget( 'WP_Widget_Recent_Comments' );
	}

	public function nc_remove_reply_script() {
		wp_deregister_script( 'comment-reply' );
	}

	/** 댓글 피드 주소는 그 글(없으면 첫 화면)로 넘긴다. */
	public function nc_redirect_feed() {
		if ( ! is_comment_feed() ) {
			return;
		}
		$to = is_singular() ? get_permalink() : home_url( '/' );
		wp_safe_redirect( $to ? $to : home_url( '/' ), 301 );
		exit;
	}

	public function nc_rest_endpoints( $endpoints ) {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public function nc_xmlrpc_methods( $methods ) {
		unset( $methods['wp.newComment'], $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	public function nc_headers( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public function nc_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	/** 주소를 직접 쳐서 댓글·토론 설정 화면에 들어오면 관리자 첫 화면으로. */
	public function nc_admin_redirect() {
		global $pagenow;
		if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php', 'options-discussion.php' ), true ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	public function nc_dashboard() {
		remove_meta_box( 'dashboard_recent_comments', 'dashboard', 'normal' );
	}

	public function nc_admin_bar() {
		global $wp_admin_bar;
		if ( is_object( $wp_admin_bar ) ) {
			$wp_admin_bar->remove_menu( 'comments' );
		}
	}

	/* ------------------------------ 달린 댓글 지우기 ------------------------------ */

	/** DB 에 남아 있는 댓글 수(대기·스팸·휴지통 포함). */
	public static function comment_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type IN (" . self::COMMENT_TYPES . ')' );
	}

	/**
	 * 달린 댓글을 전부 지운다(되돌릴 수 없다). 지운 개수를 돌려준다.
	 * 한 줄씩 wp_delete_comment 를 돌리면 스팸 수만 개에서 시간이 넘치므로 표에서 바로 지우고 글의 댓글 수를 다시 센다.
	 */
	public static function delete_all_comments() {
		global $wpdb;
		$types = self::COMMENT_TYPES;
		// phpcs:disable WordPress.DB
		$wpdb->query( "DELETE m FROM {$wpdb->commentmeta} m INNER JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_type IN ($types)" );
		$n = (int) $wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_type IN ($types)" );
		$wpdb->query( "UPDATE {$wpdb->posts} p SET p.comment_count = ( SELECT COUNT(*) FROM {$wpdb->comments} c WHERE c.comment_post_ID = p.ID AND c.comment_approved = '1' ) WHERE p.comment_count > 0" );
		// phpcs:enable
		wp_cache_flush();
		return $n;
	}

	/* ------------------------------ 설정 화면 ------------------------------ */

	public function sanitize( $input ) {
		$out = array();
		foreach ( self::snippets() as $key => $meta ) {
			$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}
		// [달린 댓글 모두 지우기] 단추 — 저장과 같은 폼이라 여기서 받는다.
		if ( ! empty( $input['wsp_delete_comments'] ) ) {
			self::delete_all_comments();
		}
		return $out;
	}

	public function render_settings() {
		$s = $this->settings();
		foreach ( self::snippets() as $key => $meta ) :
			?>
			<div class="wsp-row">
				<div class="wsp-row-label">
					<strong><?php echo esc_html( $meta[0] ); ?></strong>
					<span class="wsp-row-help"><?php echo esc_html( $meta[1] ); ?></span>
				</div>
				<div class="wsp-row-control">
					<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>" value="1" <?php checked( ! empty( $s[ $key ] ) ); ?>> 사용</label>
					<?php if ( 'no_comments' === $key ) : ?>
						<?php $n = self::comment_count(); ?>
						<p class="wsp-row-help">
							<?php if ( $n > 0 ) : ?>
								DB 에 남아 있는 댓글 <strong><?php echo esc_html( number_format_i18n( $n ) ); ?>개</strong>
								<button type="submit" name="wsp_delete_comments" value="1" class="button"
									onclick="return confirm('달린 댓글을 모두 지웁니다. 되돌릴 수 없습니다. 지울까요?');">달린 댓글 모두 지우기</button>
							<?php else : ?>
								DB 에 남아 있는 댓글이 없습니다.
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		endforeach;
		?>
		<div class="wsp-note">캐시 플러그인을 쓰면 저장한 뒤 캐시를 한 번 비워 주세요 — 비우기 전에는 예전 화면(댓글 칸)이 그대로 보일 수 있습니다.</div>
		<?php
	}
}
