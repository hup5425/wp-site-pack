<?php
/**
 * SEO — 글 편집 화면의 「검색 미리보기」(스니펫 편집기 + 포커스 키워드). (기획서 4.8 사)
 *
 *  칸: 「검색 제목」 · 「설명문」 · 「주소」 · 「포커스 키워드」
 *  저장: _wsp_seo_title · _wsp_seo_description · _wsp_seo_focus_keyword
 *        (셋 다 REST 에 열어 둔다 — wp-auto-writer 가 발행할 때 meta 로 같이 보낼 수 있게)
 *  「주소」는 wp_update_post 로 다시 저장하면 무한 되돌이가 되므로 wp_insert_post_data 필터에서
 *  post_name 을 바꾼다.
 *
 *  Rank Math 가 켜져 있어도 이 메타박스는 보인다 — 옛 값(rank_math_*)을 칸에 채워 보여 주고,
 *  저장을 누르면 그때 우리 메타로 들어온다(한꺼번에 옮기지 않는다).
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_SEO_Metabox {

	const NONCE  = 'wsp_seo_nonce';
	const ACTION = 'wsp_seo_box';

	/** 다루는 글 종류. */
	const POST_TYPES = array( 'post', 'page' );

	/** 우리 메타 ↔ Rank Math 의 같은 뜻 메타(비었을 때 읽어서 보여 준다). */
	const META_MAP = array(
		'_wsp_seo_title'         => 'rank_math_title',
		'_wsp_seo_description'   => 'rank_math_description',
		'_wsp_seo_focus_keyword' => 'rank_math_focus_keyword',
	);

	/** @var WSP_Mod_SEO */
	protected $mod;

	public function __construct( $mod ) {
		$this->mod = $mod;
	}

	public function register() {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'filter_slug' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/* ============================ 메타 등록(REST) ============================ */

	public function register_meta() {
		foreach ( self::POST_TYPES as $type ) {
			foreach ( array_keys( self::META_MAP ) as $key ) {
				register_post_meta(
					$type,
					$key,
					array(
						'show_in_rest'  => true,
						'single'        => true,
						'type'          => 'string',
						'auth_callback' => function ( $allowed, $meta_key, $post_id ) {
							return current_user_can( 'edit_post', $post_id );
						},
					)
				);
			}
		}
	}

	/* ============================ 메타박스 ============================ */

	public function add_box() {
		add_meta_box(
			'wsp-seo-snippet',
			'검색 미리보기',
			array( $this, 'render' ),
			self::POST_TYPES,
			'normal',
			'default'
		);
	}

	/**
	 * 칸 값 — 우리 메타가 비어 있으면 Rank Math 가 저장해 둔 값을 읽어서 채운다
	 * (안내 문구가 아니라 실제 값으로 넣는다. 저장을 누르면 그때 우리 메타가 된다).
	 *
	 * @param int    $post_id
	 * @param string $key 우리 메타 이름.
	 * @return string
	 */
	protected function field_value( $post_id, $key ) {
		$v = trim( (string) get_post_meta( $post_id, $key, true ) );
		if ( '' !== $v ) {
			return $v;
		}
		$old = isset( self::META_MAP[ $key ] ) ? self::META_MAP[ $key ] : '';
		return $old ? trim( (string) get_post_meta( $post_id, $old, true ) ) : '';
	}

	public function render( $post ) {
		wp_nonce_field( self::ACTION, self::NONCE );

		$title   = $this->field_value( $post->ID, '_wsp_seo_title' );
		$desc    = $this->field_value( $post->ID, '_wsp_seo_description' );
		$keyword = $this->field_value( $post->ID, '_wsp_seo_focus_keyword' );
		$slug    = (string) $post->post_name;

		// 미리보기에 보일 주소 앞부분 — 지금 글의 주소에서 슬러그만 뺀 것.
		$permalink = get_permalink( $post->ID );
		$base      = home_url( '/' );
		if ( $permalink && '' !== $slug && false !== strpos( $permalink, $slug ) ) {
			$base = substr( $permalink, 0, strrpos( $permalink, $slug ) );
		}
		?>
		<div class="wsp-seo-box">
			<div class="wsp-seo-preview">
				<div class="wsp-seo-preview-label">구글 검색결과에서 이렇게 보입니다</div>
				<div class="wsp-seo-preview-url" id="wsp-seo-pv-url"></div>
				<div class="wsp-seo-preview-title" id="wsp-seo-pv-title"></div>
				<div class="wsp-seo-preview-desc" id="wsp-seo-pv-desc"></div>
			</div>

			<p class="wsp-seo-field">
				<label for="wsp_seo_title"><strong>검색 제목</strong></label>
				<input type="text" id="wsp_seo_title" name="wsp_seo_title" value="<?php echo esc_attr( $title ); ?>" class="widefat">
				<span class="wsp-seo-help">비우면 글 제목을 씁니다.</span>
			</p>

			<p class="wsp-seo-field">
				<label for="wsp_seo_description"><strong>설명문</strong>
					<span class="wsp-seo-count"><span id="wsp-seo-desc-count">0</span> / 160자</span></label>
				<textarea id="wsp_seo_description" name="wsp_seo_description" rows="3" class="widefat"><?php echo esc_textarea( $desc ); ?></textarea>
				<span class="wsp-seo-help">비우면 본문 <strong>첫 문단</strong>을 자동으로 씁니다(160자가 넘으면 문장 끝에서 자릅니다).</span>
			</p>

			<p class="wsp-seo-field">
				<label for="wsp_seo_slug"><strong>주소</strong></label>
				<input type="text" id="wsp_seo_slug" name="wsp_seo_slug" value="<?php echo esc_attr( $slug ); ?>" class="widefat"
					data-base="<?php echo esc_attr( $base ); ?>">
				<span class="wsp-seo-help">이미 발행된 글의 주소를 바꾸면 옛 주소로 들어온 사람을 워드프레스가 스스로 새 주소로 보냅니다. 비워 두면 지금 주소를 그대로 둡니다.</span>
			</p>

			<p class="wsp-seo-field">
				<label for="wsp_seo_focus_keyword"><strong>포커스 키워드</strong></label>
				<input type="text" id="wsp_seo_focus_keyword" name="wsp_seo_focus_keyword" value="<?php echo esc_attr( $keyword ); ?>" class="widefat">
				<span class="wsp-seo-help">이 글로 잡고 싶은 검색어. 아래 표에서 어디에 몇 번 들어갔는지 셉니다.</span>
			</p>

			<p>
				<button type="button" class="button" id="wsp-seo-recount">다시 세기</button>
			</p>
			<table class="widefat striped wsp-seo-count-table">
				<thead><tr><th>어디</th><th style="width:120px">몇 번</th></tr></thead>
				<tbody id="wsp-seo-count-body">
					<tr><td colspan="2">포커스 키워드를 적으면 셉니다.</td></tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/* ============================ 저장 ============================ */

	/**
	 * 세 메타 저장. nonce · 권한 · 자동 저장 제외.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function save( $post_id, $post = null ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post && ! in_array( $post->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		if ( empty( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::ACTION ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'_wsp_seo_title'         => 'wsp_seo_title',
			'_wsp_seo_description'   => 'wsp_seo_description',
			'_wsp_seo_focus_keyword' => 'wsp_seo_focus_keyword',
		);
		foreach ( $fields as $meta_key => $field ) {
			if ( ! isset( $_POST[ $field ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $meta_key );
			} else {
				update_post_meta( $post_id, $meta_key, $value );
			}
		}
	}

	/**
	 * 「주소」 칸 → post_name.
	 *  wp_update_post 로 다시 저장하면 save_post 가 또 돌아 되돌이가 된다. 저장되기 직전 자료를
	 *  바꾸는 이 필터가 제자리다.
	 *  ⚠ 이 필터는 post_name 중복 정리(wp_unique_post_slug)가 끝난 **뒤**에 돌기 때문에
	 *     우리가 직접 한 번 더 겹침을 풀어 준다.
	 *
	 * @param array $data    저장 직전 자료.
	 * @param array $postarr 원래 들어온 값($_POST 포함).
	 * @return array
	 */
	public function filter_slug( $data, $postarr ) {
		if ( empty( $data['post_type'] ) || ! in_array( $data['post_type'], self::POST_TYPES, true ) ) {
			return $data;
		}
		if ( empty( $postarr[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $postarr[ self::NONCE ] ) ), self::ACTION ) ) {
			return $data;
		}
		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return $data;
		}
		if ( ! isset( $postarr['wsp_seo_slug'] ) ) {
			return $data;
		}
		$slug = sanitize_title( wp_unslash( $postarr['wsp_seo_slug'] ) );
		if ( '' === $slug ) {
			return $data; // 비어 있으면 안 건드린다.
		}
		if ( $slug === $data['post_name'] ) {
			return $data;
		}
		$data['post_name'] = wp_unique_post_slug(
			$slug,
			$post_id,
			$data['post_status'],
			$data['post_type'],
			isset( $data['post_parent'] ) ? (int) $data['post_parent'] : 0
		);
		return $data;
	}

	/* ============================ 편집 화면 자원 ============================ */

	public function assets( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->post_type, self::POST_TYPES, true ) ) {
			return;
		}
		wp_enqueue_style( 'wsp-seo-metabox', WSP_URL . 'assets/seo-metabox.css', array(), WSP_VERSION );
		wp_enqueue_script( 'wsp-seo-metabox', WSP_URL . 'assets/seo-metabox.js', array(), WSP_VERSION, true );
		wp_localize_script(
			'wsp-seo-metabox',
			'WSP_SEO_MB',
			array(
				'home'  => home_url( '/' ),
				'limit' => WSP_SEO_Head::DESC_LIMIT,
			)
		);
	}
}
