<?php
/**
 * 모듈: 소셜 공유.
 *  - 숏코드 [wsp_social_share] + (옵션) the_content 자동 삽입.
 *  - 페이스북/밴드/카카오톡/네이버/라인/X. 카카오는 JS 키 필요.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Social_Share extends WSP_Module {

	public function id()   { return 'social_share'; }
	public function name() { return '소셜 공유'; }
	public function desc() { return '글에 페이스북·카카오톡·네이버·라인·X 등 공유 버튼을 표시합니다.'; }
	public function icon() { return 'dashicons-share'; }

	protected function networks() {
		return array(
			'facebook'  => '페이스북',
			'band'      => '밴드',
			'kakao'     => '카카오톡',
			'naver'     => '네이버',
			'line'      => '라인',
			'x'         => 'X',
			'threads'   => '쓰레드',
			'instagram' => '인스타그램',
			'copy'      => '링크 복사',
		);
	}

	public function default_settings() {
		return array(
			'enabled'   => array( 'facebook' => 1, 'kakao' => 1, 'naver' => 1, 'x' => 1, 'copy' => 1 ),
			'kakao_key' => '',
			'align'     => 'left',   // left|center|right
			'auto'      => 0,        // the_content 자동 삽입
		);
	}

	public function register() {
		add_shortcode( 'wsp_social_share', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		if ( ! empty( $this->settings()['auto'] ) ) {
			add_filter( 'the_content', array( $this, 'append_to_content' ), 20 );
		}
	}

	public function assets() {
		if ( is_admin() ) {
			return;
		}
		WSP_Assets::front_style( 'social-share' );
		$s = $this->settings();
		$data = array( 'kakaoKey' => (string) $s['kakao_key'] );
		WSP_Assets::front_script( 'social-share', $data, 'WSP_SOCIAL' );
	}

	public function append_to_content( $content ) {
		if ( is_singular() && in_the_loop() && is_main_query() ) {
			return $content . $this->shortcode( array() );
		}
		return $content;
	}

	public function shortcode( $atts ) {
		$s   = $this->settings();
		$url = get_permalink();
		if ( ! $url ) {
			$url = home_url( add_query_arg( array(), '' ) );
		}
		$title = get_the_title();
		$eu    = rawurlencode( $url );
		$et    = rawurlencode( $title );

		// 웹 공유 URL 스킴이 있는 네트워크(쓰레드 포함). 인스타그램은 웹 공유 URL이 없어 '링크 복사'로 동작.
		$links = array(
			'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $eu,
			'band'     => 'https://band.us/plugin/share?body=' . $et . '%20' . $eu,
			'naver'    => 'https://share.naver.com/web/shareView?url=' . $eu . '&title=' . $et,
			'line'     => 'https://social-plugins.line.me/lineit/share?url=' . $eu,
			'x'        => 'https://twitter.com/intent/tweet?url=' . $eu . '&text=' . $et,
			'threads'  => 'https://www.threads.net/intent/post?text=' . $et . '%20' . $eu,
		);

		$out = '<div class="wsp-social wsp-align-' . esc_attr( $s['align'] ) . '">';
		foreach ( $this->networks() as $net => $label ) {
			if ( empty( $s['enabled'][ $net ] ) ) {
				continue;
			}
			if ( 'kakao' === $net ) {
				if ( '' === $s['kakao_key'] ) {
					continue; // 키 없으면 비활성.
				}
				$out .= '<button type="button" class="wsp-social-btn wsp-social-kakao" data-url="' . esc_attr( $url ) . '" data-title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</button>';
				continue;
			}
			// 인스타그램·링크복사: 클립보드 복사 버튼(인스타는 웹 공유 스킴이 없어 링크 복사 방식).
			if ( 'instagram' === $net || 'copy' === $net ) {
				$out .= '<button type="button" class="wsp-social-btn wsp-social-' . esc_attr( $net ) . '" data-wsp-copy="' . esc_attr( $url ) . '">' . esc_html( $label ) . '</button>';
				continue;
			}
			$out .= '<a class="wsp-social-btn wsp-social-' . esc_attr( $net ) . '" href="' . esc_url( $links[ $net ] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $label ) . '</a>';
		}
		$out .= '</div>';
		return $out;
	}

	public function sanitize( $input ) {
		$enabled = array();
		foreach ( array_keys( $this->networks() ) as $net ) {
			$enabled[ $net ] = empty( $input[ 'net_' . $net ] ) ? 0 : 1;
		}
		$align = isset( $input['align'] ) ? sanitize_key( $input['align'] ) : 'left';
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$align = 'left';
		}
		return array(
			'enabled'   => $enabled,
			'kakao_key' => isset( $input['kakao_key'] ) ? sanitize_text_field( (string) $input['kakao_key'] ) : '',
			'align'     => $align,
			'auto'      => empty( $input['auto'] ) ? 0 : 1,
		);
	}

	public function render_settings() {
		$s = $this->settings();
		?>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>활성화할 소셜 미디어</strong></div>
			<div class="wsp-row-control wsp-chips">
				<?php foreach ( $this->networks() as $net => $label ) : ?>
					<label class="wsp-chip">
						<input type="checkbox" name="net_<?php echo esc_attr( $net ); ?>" value="1" <?php checked( ! empty( $s['enabled'][ $net ] ) ); ?>>
						<?php echo esc_html( $label ); ?><?php echo 'kakao' === $net && '' === $s['kakao_key'] ? '(키 필요)' : ''; ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>카카오톡 JavaScript 키</strong>
				<span class="wsp-row-help">카카오 개발자 사이트의 JavaScript 키. 없으면 카카오 공유 비활성.</span></div>
			<div class="wsp-row-control"><input type="text" name="kakao_key" value="<?php echo esc_attr( $s['kakao_key'] ); ?>"></div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>버튼 정렬</strong></div>
			<div class="wsp-row-control">
				<select name="align">
					<option value="left" <?php selected( $s['align'], 'left' ); ?>>왼쪽</option>
					<option value="center" <?php selected( $s['align'], 'center' ); ?>>가운데</option>
					<option value="right" <?php selected( $s['align'], 'right' ); ?>>오른쪽</option>
				</select>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>글 하단 자동 삽입</strong>
				<span class="wsp-row-help">끄면 숏코드로만 표시.</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="auto" value="1" <?php checked( $s['auto'], 1 ); ?>> 본문 끝에 자동으로 버튼 추가</label>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>숏코드</strong></div>
			<div class="wsp-row-control"><code class="wsp-code wsp-copy" data-copy="[wsp_social_share]">[wsp_social_share]</code> 원하는 위치에 붙여넣기</div>
		</div>
		<?php
	}
}
