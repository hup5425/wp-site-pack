<?php
/**
 * 모듈: 스마트 스크롤(팝업). 단일 캠페인.
 *  - 페이지 N% 스크롤 시 팝업 표시. 빈도(쿠키/localStorage) 제어.
 *  - 표시 유형: (1) 이미지/동영상 배너 + 클릭 시 랜딩 링크(쿠팡파트너스·토스 등),
 *              (2) 직접 HTML.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Scroll_Popup extends WSP_Module {

	public function id()   { return 'scroll_popup'; }
	public function name() { return '스마트 스크롤(팝업)'; }
	public function desc() { return '방문자가 일정 비율까지 스크롤하면 배너/광고 팝업을 띄웁니다.'; }
	public function icon() { return 'dashicons-align-center'; }

	public function default_settings() {
		return array(
			'percent'      => 50,
			'animation'    => 'fade',   // fade|slide|none
			'frequency'    => 'once',   // once|session|always
			'width'        => 480,
			'display_type' => 'banner', // banner|html
			'banner_url'   => '',       // 이미지/gif/동영상 주소
			'banner_link'  => '',       // 클릭 시 이동할 랜딩 URL
			'banner_alt'   => '',
			'content'      => '',       // display_type=html 일 때 사용
		);
	}

	public function register() {
		add_action( 'wp_footer', array( $this, 'render_popup' ), 50 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets() {
		if ( is_admin() ) {
			return;
		}
		WSP_Assets::front_style( 'scroll-popup' );
		$s = $this->settings();
		WSP_Assets::front_script( 'scroll-popup', array(
			'percent'   => (int) $s['percent'],
			'animation' => $s['animation'],
			'frequency' => $s['frequency'],
		), 'WSP_POPUP' );
	}

	/** 동영상 파일 확장자면 true. */
	protected function is_video( $url ) {
		return (bool) preg_match( '/\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i', (string) $url );
	}

	/**
	 * 팝업 내부 콘텐츠 HTML 생성(프론트/미리보기 공용).
	 *
	 * @param array $s 설정.
	 * @return string 비어있으면 표시 안 함.
	 */
	public function build_inner( $s ) {
		if ( 'banner' === $s['display_type'] ) {
			$url = trim( (string) $s['banner_url'] );
			if ( '' === $url ) {
				return '';
			}
			if ( $this->is_video( $url ) ) {
				$media = '<video src="' . esc_url( $url ) . '" autoplay muted loop playsinline class="wsp-popup-media"></video>';
			} else {
				$media = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( $s['banner_alt'] ) . '" class="wsp-popup-media">';
			}
			$link = trim( (string) $s['banner_link'] );
			if ( '' !== $link ) {
				// 광고/제휴 링크: rel="sponsored nofollow noopener", 새 탭.
				return '<a href="' . esc_url( $link ) . '" target="_blank" rel="sponsored nofollow noopener">' . $media . '</a>';
			}
			return $media;
		}
		// HTML 모드.
		return (string) $s['content']; // 저장 시 이미 검증됨.
	}

	public function render_popup() {
		if ( is_admin() ) {
			return;
		}
		$s     = $this->settings();
		$inner = $this->build_inner( $s );
		if ( '' === trim( $inner ) ) {
			return;
		}
		$banner = ( 'banner' === $s['display_type'] );
		$w      = max( 200, min( 1200, (int) $s['width'] ) );
		echo '<div id="wsp-popup" class="wsp-popup wsp-anim-' . esc_attr( $s['animation'] ) . '" hidden>';
		echo '<div class="wsp-popup-backdrop" data-wsp-close></div>';
		echo '<div class="wsp-popup-box ' . ( $banner ? 'wsp-popup--banner' : '' ) . '" style="width:' . $w . 'px;max-width:92vw">';
		echo '<button type="button" class="wsp-popup-close" data-wsp-close aria-label="닫기">×</button>';
		echo '<div class="wsp-popup-content">' . $inner . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</div></div>';
	}

	public function sanitize( $input ) {
		$anim = isset( $input['animation'] ) ? sanitize_key( $input['animation'] ) : 'fade';
		if ( ! in_array( $anim, array( 'fade', 'slide', 'none' ), true ) ) {
			$anim = 'fade';
		}
		$freq = isset( $input['frequency'] ) ? sanitize_key( $input['frequency'] ) : 'once';
		if ( ! in_array( $freq, array( 'once', 'session', 'always' ), true ) ) {
			$freq = 'once';
		}
		$type = isset( $input['display_type'] ) ? sanitize_key( $input['display_type'] ) : 'banner';
		if ( ! in_array( $type, array( 'banner', 'html' ), true ) ) {
			$type = 'banner';
		}
		$raw     = current_user_can( 'unfiltered_html' );
		$content = isset( $input['content'] ) ? (string) $input['content'] : '';
		return array(
			'percent'      => max( 1, min( 100, (int) ( $input['percent'] ?? 50 ) ) ),
			'animation'    => $anim,
			'frequency'    => $freq,
			'width'        => max( 200, min( 1200, (int) ( $input['width'] ?? 480 ) ) ),
			'display_type' => $type,
			'banner_url'   => isset( $input['banner_url'] ) ? esc_url_raw( trim( (string) $input['banner_url'] ) ) : '',
			'banner_link'  => isset( $input['banner_link'] ) ? esc_url_raw( trim( (string) $input['banner_link'] ) ) : '',
			'banner_alt'   => isset( $input['banner_alt'] ) ? sanitize_text_field( (string) $input['banner_alt'] ) : '',
			'content'      => $raw ? $content : wp_kses_post( $content ),
		);
	}

	public function render_settings() {
		$s = $this->settings();
		?>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>표시 유형</strong>
				<span class="wsp-row-help">배너: 이미지/동영상 + 클릭 시 링크 이동. HTML: 직접 작성.</span></div>
			<div class="wsp-row-control">
				<label><input type="radio" name="display_type" value="banner" class="wsp-pp-type" <?php checked( $s['display_type'], 'banner' ); ?>> 이미지/동영상 배너</label>
				&nbsp;&nbsp;
				<label><input type="radio" name="display_type" value="html" class="wsp-pp-type" <?php checked( $s['display_type'], 'html' ); ?>> 직접 HTML</label>
			</div>
		</div>

		<!-- 배너 모드 필드 -->
		<div class="wsp-pp-banner" style="<?php echo 'html' === $s['display_type'] ? 'display:none' : ''; ?>">
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>이미지/동영상 주소</strong>
					<span class="wsp-row-help">gif·움직이는 이미지·mp4 동영상 모두 가능. 미디어 라이브러리에서 골라도 됩니다.</span></div>
				<div class="wsp-row-control">
					<input type="text" name="banner_url" id="wsp_banner_url" value="<?php echo esc_attr( $s['banner_url'] ); ?>" placeholder="https://.../banner.gif 또는 .mp4" style="width:70%">
					<button type="button" class="button wsp-media-pick" data-target="#wsp_banner_url">미디어 선택</button>
				</div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>클릭 시 이동할 링크(랜딩)</strong>
					<span class="wsp-row-help">쿠팡파트너스·토스 등 제휴 링크. 새 탭 + rel="sponsored".</span></div>
				<div class="wsp-row-control">
					<input type="text" name="banner_link" value="<?php echo esc_attr( $s['banner_link'] ); ?>" placeholder="https://link.coupang.com/..." style="width:70%">
				</div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>대체 텍스트(선택)</strong></div>
				<div class="wsp-row-control"><input type="text" name="banner_alt" value="<?php echo esc_attr( $s['banner_alt'] ); ?>" style="width:70%"></div>
			</div>
		</div>

		<!-- HTML 모드 필드 -->
		<div class="wsp-pp-html" style="<?php echo 'html' !== $s['display_type'] ? 'display:none' : ''; ?>">
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>팝업 내용(HTML)</strong>
					<span class="wsp-row-help">이미지·버튼 등 자유롭게.</span></div>
				<div class="wsp-row-control">
					<?php
					wp_editor( $s['content'], 'wsp_popup_content', array(
						'textarea_name' => 'content',
						'textarea_rows' => 8,
						'media_buttons' => true,
					) );
					?>
				</div>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>팝업 폭</strong>
				<span class="wsp-row-help">200~1200px. 모바일에서는 화면의 92%로 자동 축소.</span></div>
			<div class="wsp-row-control"><input type="number" name="width" min="200" max="1200" value="<?php echo esc_attr( $s['width'] ); ?>"> px</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>스크롤 퍼센트</strong>
				<span class="wsp-row-help">페이지의 N%까지 내리면 팝업 표시.</span></div>
			<div class="wsp-row-control"><input type="number" name="percent" min="1" max="100" value="<?php echo esc_attr( $s['percent'] ); ?>"> %</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>팝업 애니메이션</strong></div>
			<div class="wsp-row-control">
				<select name="animation">
					<option value="fade" <?php selected( $s['animation'], 'fade' ); ?>>페이드</option>
					<option value="slide" <?php selected( $s['animation'], 'slide' ); ?>>슬라이드</option>
					<option value="none" <?php selected( $s['animation'], 'none' ); ?>>없음</option>
				</select>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>반복 설정</strong></div>
			<div class="wsp-row-control">
				<select name="frequency">
					<option value="once" <?php selected( $s['frequency'], 'once' ); ?>>브라우저당 한 번만</option>
					<option value="session" <?php selected( $s['frequency'], 'session' ); ?>>세션당 한 번</option>
					<option value="always" <?php selected( $s['frequency'], 'always' ); ?>>매번</option>
				</select>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>미리보기</strong>
				<span class="wsp-row-help">현재 입력값으로 팝업을 바로 확인(저장 안 해도 됨).</span></div>
			<div class="wsp-row-control">
				<button type="button" class="button button-secondary" id="wsp-popup-preview">미리보기 열기</button>
			</div>
		</div>

		<div class="wsp-note">쿠팡파트너스·토스 등 제휴 배너는 <strong>애드센스와 무관</strong>하며 여기서 자유롭게 쓸 수 있습니다.
			단, <strong>애드센스 광고 코드를 팝업에 넣는 것은 구글 정책 위반</strong>이니 넣지 마세요.</div>
		<?php
	}
}
