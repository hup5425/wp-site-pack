<?php
/**
 * 모듈: 소셜 공유.
 *  - 숏코드 [wsp_social_share] + (옵션) the_content 자동 삽입.
 *  - 플랫폼 로고(SVG) + 플랫폼별 배경색 커스터마이즈 + 버튼 스타일(로고/텍스트).
 *  - 페이스북/밴드/카카오톡/네이버/라인/X/쓰레드/인스타그램/링크복사.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Social_Share extends WSP_Module {

	public function id()   { return 'social_share'; }
	public function name() { return '소셜 공유'; }
	public function desc() { return '글에 페이스북·카카오톡·네이버·라인·X 등 로고 공유 버튼을 표시합니다.'; }
	public function icon() { return 'dashicons-share'; }

	protected function networks() {
		return array(
			'facebook'   => '페이스북',
			'band'       => '밴드',
			'kakao'      => '카카오톡',
			'naver_blog' => '블로그',
			'naver_cafe' => '카페',
			'line'       => '라인',
			'x'          => 'X',
			'threads'    => '쓰레드',
			'instagram'  => '인스타그램',
			'copy'       => '링크 복사',
		);
	}

	/** 플랫폼 기본 배경색(브랜드 컬러). */
	protected function brand_colors() {
		return array(
			'facebook'   => '#1877f2',
			'band'       => '#03c75a',
			'kakao'      => '#fee500',
			'naver_blog' => '#03c75a',
			'naver_cafe' => '#2db400',
			'line'       => '#06c755',
			'x'          => '#191919',
			'threads'    => '#000000',
			'instagram'  => '#d62976',
			'copy'       => '#5b616b',
		);
	}

	public function default_settings() {
		return array(
			'enabled'     => array( 'facebook' => 1, 'kakao' => 1, 'naver_blog' => 1, 'x' => 1, 'copy' => 1 ),
			'share_label' => '공유하기',
			'colors'      => $this->brand_colors(),
			'btn_style'   => 'logo_text', // logo_text | logo_only | text_only
			'kakao_key' => '',
			'align'     => 'left',       // left|center|right
			'position'  => 'bottom',     // none | top | bottom | both
		);
	}

	public function register() {
		add_shortcode( 'wsp_social_share', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		if ( 'none' !== $this->settings()['position'] ) {
			add_filter( 'the_content', array( $this, 'insert_content' ), 20 );
		}
	}

	public function assets() {
		if ( is_admin() ) {
			return;
		}
		WSP_Assets::front_style( 'social-share' );
		$s = $this->settings();
		WSP_Assets::front_script( 'social-share', array( 'kakaoKey' => (string) $s['kakao_key'] ), 'WSP_SOCIAL' );
	}

	public function insert_content( $content ) {
		if ( ! ( is_singular() && in_the_loop() && is_main_query() ) ) {
			return $content;
		}
		$pos  = $this->settings()['position'];
		$html = $this->shortcode( array() );
		if ( 'top' === $pos ) {
			return $html . $content;
		}
		if ( 'both' === $pos ) {
			return $html . $content . $html;
		}
		return $content . $html; // bottom
	}

	/** 배경색 밝기에 따라 글자/아이콘 색(어두우면 흰색, 밝으면 검정). */
	protected function text_on( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '#ffffff';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		$lum = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
		return $lum > 0.65 ? '#191600' : '#ffffff';
	}

	/** 플랫폼 로고 SVG(단색, currentColor). */
	protected function icon_svg( $net ) {
		// 링크복사: 쇠사슬(체인 링크) 아이콘.
		if ( 'copy' === $net ) {
			return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>';
		}
		$p = array(
			'facebook'  => '<path d="M13 22v-8h2.7l.4-3H13V9.1c0-.9.3-1.5 1.6-1.5H17V4.9c-.3 0-1.3-.1-2.5-.1-2.4 0-4 1.5-4 4.2V11H8v3h2.5v8H13z"/>',
			'band'      => '<path d="M8 5h4.6c2.5 0 3.9 1.2 3.9 3.2 0 1.3-.7 2.1-1.7 2.6 1.3.4 2.2 1.4 2.2 2.9 0 2.2-1.6 3.5-4.3 3.5H8V5zm2.3 5.1h1.9c1.1 0 1.7-.5 1.7-1.3 0-.9-.6-1.3-1.7-1.3h-1.9v2.6zm0 5.1h2c1.2 0 1.9-.5 1.9-1.4 0-1-.7-1.5-1.9-1.5h-2v2.9z"/>',
			'kakao'     => '<path d="M12 4C7.3 4 3.5 7 3.5 10.7c0 2.4 1.6 4.5 4 5.7-.2.6-.6 2.2-.7 2.6 0 .2.1.4.4.2.3-.1 2.6-1.7 3.6-2.4.5.1 1.1.1 1.7.1 4.7 0 8.5-3 8.5-6.8C20.5 7 16.7 4 12 4z"/>',
			'naver_blog' => '<text x="12" y="15.4" font-size="7.6" font-weight="800" letter-spacing="-0.4" text-anchor="middle" font-family="Arial,Helvetica,sans-serif">blog</text>',
			'naver_cafe' => '<text x="12" y="15.4" font-size="7.6" font-weight="800" letter-spacing="-0.4" text-anchor="middle" font-family="Arial,Helvetica,sans-serif">cafe</text>',
			'line'      => '<path d="M12 3.5C6.8 3.5 2.5 6.9 2.5 11c0 3.7 3.4 6.8 8 7.4.3.1.7.2.8.5.1.3.1.6 0 .9 0 0-.1.7-.1.8-.1.3-.2 1 .9.6 1.1-.5 5.7-3.4 7.8-5.8 1.4-1.5 2.1-3.1 2.1-5C22 6.9 17.7 3.5 12 3.5zM8.3 13.3H6.4c-.3 0-.5-.2-.5-.5V9.2c0-.3.2-.5.5-.5s.5.2.5.5v3.1h1.4c.3 0 .5.2.5.5s-.2.5-.5.5zm2-.5c0 .3-.2.5-.5.5s-.5-.2-.5-.5V9.2c0-.3.2-.5.5-.5s.5.2.5.5v3.6zm4.4 0c0 .2-.1.4-.4.5h-.1c-.2 0-.3-.1-.4-.2l-1.9-2.5v2.2c0 .3-.2.5-.5.5s-.5-.2-.5-.5V9.2c0-.2.1-.4.4-.5h.1c.1 0 .3.1.4.2l1.9 2.6V9.2c0-.3.2-.5.5-.5s.5.2.5.5v3.6zm3-2.3c.3 0 .5.2.5.5s-.2.5-.5.5h-1.4v.9h1.4c.3 0 .5.2.5.5s-.2.5-.5.5h-1.9c-.3 0-.5-.2-.5-.5V9.2c0-.3.2-.5.5-.5h1.9c.3 0 .5.2.5.5s-.2.5-.5.5h-1.4v.8h1.4z"/>',
			'x'         => '<path d="M17.5 3h3l-6.6 7.5L21.8 21h-6l-4.7-6.1L5.6 21h-3l7-8.1L2.3 3h6.2l4.2 5.6L17.5 3zm-1.1 16h1.7L7.7 4.8H5.9L16.4 19z"/>',
			'threads'   => '<path d="M16.9 11.4c1.4.7 2.4 1.7 2.9 3 .7 1.7.7 4.4-1.5 6.5-1.6 1.6-3.6 2.3-6.3 2.3h0c-3-.1-5.4-1.1-6.9-3C3.7 18.5 3 16.1 3 13v0c0-3.1.7-5.5 2.1-7.2C6.6 3.6 9 2.6 12 2.5h0c3 .1 5.4 1.1 6.9 3 .8 1 1.3 2.1 1.6 3.5l-1.9.5c-.2-1-.6-1.9-1.1-2.5-1.1-1.4-2.8-2.1-5.1-2.1h0c-2.4 0-4.1.7-5.1 2.1-1 1.3-1.5 3.2-1.5 5.6v0c0 2.4.5 4.3 1.5 5.6 1 1.4 2.7 2.1 5.1 2.1h0c2.1 0 3.6-.5 4.8-1.6 1.3-1.3 1.3-2.9.9-3.8-.2-.5-.6-1-1.2-1.4-.2 1.1-.5 2-1.1 2.7-.7.9-1.8 1.4-3.1 1.5-1 .1-2-.2-2.7-.7-.9-.6-1.4-1.5-1.5-2.6-.1-1.1.3-2 1.2-2.7.8-.6 1.9-.9 3.2-1 .5 0 1 0 1.4.1-.1-.5-.2-.9-.4-1.2-.4-.5-1-.7-1.9-.7h0c-.7 0-1.6.2-2.1 1l-1.6-1.1c.8-1.2 2.1-1.8 3.7-1.8h0c2.7 0 4.3 1.7 4.5 4.6zm-5 5.1c.9-.1 1.6-.9 1.8-2.4-.4-.1-.9-.2-1.4-.2-1.3 0-2.1.6-2 1.4.1.8.9 1.3 1.6 1.2z"/>',
			'instagram' => '<path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7zm0 5.8a2.3 2.3 0 1 1 0-4.6 2.3 2.3 0 0 1 0 4.6zm4.5-6a.8.8 0 1 1-1.6 0 .8.8 0 0 1 1.6 0zM19 8.6c-.1-1.1-.3-2.1-1.1-2.9-.8-.8-1.8-1-2.9-1.1-1.1-.1-4.9-.1-6 0-1.1.1-2.1.3-2.9 1.1-.8.8-1 1.8-1.1 2.9-.1 1.1-.1 4.9 0 6 .1 1.1.3 2.1 1.1 2.9.8.8 1.8 1 2.9 1.1 1.1.1 4.9.1 6 0 1.1-.1 2.1-.3 2.9-1.1.8-.8 1-1.8 1.1-2.9.1-1.1.1-4.9 0-6zm-1.5 7.2c-.2.6-.7 1.1-1.3 1.3-.9.4-3.1.3-4.2.3s-3.3.1-4.2-.3c-.6-.2-1.1-.7-1.3-1.3-.4-.9-.3-3.1-.3-4.2s-.1-3.3.3-4.2c.2-.6.7-1.1 1.3-1.3.9-.4 3.1-.3 4.2-.3s3.3-.1 4.2.3c.6.2 1.1.7 1.3 1.3.4.9.3 3.1.3 4.2s.1 3.3-.3 4.2z"/>',
			'copy'      => '<path d="M10.6 13.4a1 1 0 0 0 1.4 0l3-3a3 3 0 1 0-4.2-4.2L9.5 7.5a1 1 0 1 0 1.4 1.4l1.3-1.3a1 1 0 0 1 1.4 1.4l-3 3a1 1 0 0 0 0 1.4zm2.8-2.8a1 1 0 0 0-1.4 0l-3 3a3 3 0 1 0 4.2 4.2l1.3-1.3a1 1 0 1 0-1.4-1.4l-1.3 1.3a1 1 0 0 1-1.4-1.4l3-3a1 1 0 0 0 0-1.4z"/>',
		);
		$path = isset( $p[ $net ] ) ? $p[ $net ] : '';
		if ( '' === $path ) {
			return '';
		}
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
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

		$links = array(
			'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $eu,
			'band'     => 'https://band.us/plugin/share?body=' . $et . '%20' . $eu,
			'naver_blog' => 'https://blog.naver.com/openapi/share?url=' . $eu . '&title=' . $et,
			'naver_cafe' => 'https://share.naver.com/web/shareView?url=' . $eu . '&title=' . $et,
			'line'     => 'https://social-plugins.line.me/lineit/share?url=' . $eu,
			'x'        => 'https://twitter.com/intent/tweet?url=' . $eu . '&text=' . $et,
			'threads'  => 'https://www.threads.net/intent/post?text=' . $et . '%20' . $eu,
		);

		$style   = in_array( $s['btn_style'], array( 'logo_text', 'logo_only', 'text_only' ), true ) ? $s['btn_style'] : 'logo_text';
		$colors  = is_array( $s['colors'] ) ? $s['colors'] : array();
		$brand   = $this->brand_colors();

		$out = '';
		if ( '' !== trim( (string) $s['share_label'] ) ) {
			$out .= '<div class="wsp-social-heading">' . esc_html( $s['share_label'] ) . '</div>';
		}
		$out .= '<div class="wsp-social wsp-social--' . esc_attr( $style ) . ' wsp-align-' . esc_attr( $s['align'] ) . '">';
		foreach ( $this->networks() as $net => $label ) {
			if ( empty( $s['enabled'][ $net ] ) ) {
				continue;
			}
			if ( 'kakao' === $net && '' === $s['kakao_key'] ) {
				continue;
			}
			$bg   = ! empty( $colors[ $net ] ) ? $colors[ $net ] : $brand[ $net ];
			$fg   = $this->text_on( $bg );
			$attr = ' style="background:' . esc_attr( $bg ) . ';color:' . esc_attr( $fg ) . '"';

			$inner = '';
			if ( 'text_only' !== $style ) {
				$inner .= $this->icon_svg( $net );
			}
			if ( 'logo_only' !== $style ) {
				$inner .= '<span class="wsp-social-label">' . esc_html( $label ) . '</span>';
			}
			$cls = 'wsp-social-btn wsp-social-' . esc_attr( $net );

			if ( 'kakao' === $net ) {
				$out .= '<button type="button" class="' . $cls . '"' . $attr . ' data-url="' . esc_attr( $url ) . '" data-title="' . esc_attr( $title ) . '" aria-label="' . esc_attr( $label ) . '">' . $inner . '</button>';
			} elseif ( 'instagram' === $net || 'copy' === $net ) {
				$out .= '<button type="button" class="' . $cls . '"' . $attr . ' data-wsp-copy="' . esc_attr( $url ) . '" aria-label="' . esc_attr( $label ) . '">' . $inner . '</button>';
			} else {
				$out .= '<a class="' . $cls . '"' . $attr . ' href="' . esc_url( $links[ $net ] ) . '" target="_blank" rel="noopener nofollow" aria-label="' . esc_attr( $label ) . '">' . $inner . '</a>';
			}
		}
		$out .= '</div>';
		return $out;
	}

	public function sanitize( $input ) {
		$enabled = array();
		foreach ( array_keys( $this->networks() ) as $net ) {
			$enabled[ $net ] = empty( $input[ 'net_' . $net ] ) ? 0 : 1;
		}
		$colors = array();
		$brand  = $this->brand_colors();
		foreach ( array_keys( $this->networks() ) as $net ) {
			$c = isset( $input[ 'color_' . $net ] ) ? (string) $input[ 'color_' . $net ] : '';
			$colors[ $net ] = preg_match( '/^#[0-9a-fA-F]{6}$/', $c ) ? $c : $brand[ $net ];
		}
		$style = isset( $input['btn_style'] ) ? sanitize_key( $input['btn_style'] ) : 'logo_text';
		if ( ! in_array( $style, array( 'logo_text', 'logo_only', 'text_only' ), true ) ) {
			$style = 'logo_text';
		}
		$align = isset( $input['align'] ) ? sanitize_key( $input['align'] ) : 'left';
		if ( ! in_array( $align, array( 'left', 'center', 'right' ), true ) ) {
			$align = 'left';
		}
		$position = isset( $input['position'] ) ? sanitize_key( $input['position'] ) : 'bottom';
		if ( ! in_array( $position, array( 'none', 'top', 'bottom', 'both' ), true ) ) {
			$position = 'bottom';
		}
		return array(
			'enabled'     => $enabled,
			'share_label' => isset( $input['share_label'] ) ? sanitize_text_field( (string) $input['share_label'] ) : '',
			'colors'      => $colors,
			'btn_style'   => $style,
			'kakao_key'   => isset( $input['kakao_key'] ) ? sanitize_text_field( (string) $input['kakao_key'] ) : '',
			'align'       => $align,
			'position'    => $position,
		);
	}

	public function render_settings() {
		$s      = $this->settings();
		$colors = is_array( $s['colors'] ) ? $s['colors'] : $this->brand_colors();
		$brand  = $this->brand_colors();

		// 미리보기용 아이콘/라벨/브랜드색 데이터(JS 가 실시간 렌더).
		$pv = array();
		foreach ( $this->networks() as $net => $label ) {
			$pv[ $net ] = array(
				'label' => $label,
				'svg'   => $this->icon_svg( $net ),
				'brand' => $brand[ $net ],
			);
		}
		?>
		<div class="wsp-row wsp-row--toggle">
			<div class="wsp-row-label"><strong>미리보기</strong>
				<span class="wsp-row-help">아래 설정을 바꾸면 즉시 반영됩니다.</span></div>
			<div class="wsp-row-control">
				<div id="wsp-social-preview" class="wsp-social-preview"></div>
				<script type="application/json" id="wsp-social-pv-data"><?php echo wp_json_encode( $pv ); ?></script>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>공유 안내 문구</strong>
				<span class="wsp-row-help">버튼 앞에 표시(예: 공유하기 / 이 글이 유용했다면 공유해주세요). 비우면 숨김.</span></div>
			<div class="wsp-row-control"><input type="text" name="share_label" value="<?php echo esc_attr( $s['share_label'] ); ?>" style="width:60%"></div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>버튼 스타일</strong></div>
			<div class="wsp-row-control">
				<select name="btn_style">
					<option value="logo_text" <?php selected( $s['btn_style'], 'logo_text' ); ?>>로고 + 텍스트</option>
					<option value="logo_only" <?php selected( $s['btn_style'], 'logo_only' ); ?>>로고만</option>
					<option value="text_only" <?php selected( $s['btn_style'], 'text_only' ); ?>>텍스트만</option>
				</select>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>플랫폼 · 배경색</strong>
				<span class="wsp-row-help">체크로 표시 여부, 색상칸으로 배경색을 바꿉니다.</span></div>
			<div class="wsp-row-control">
				<table class="widefat striped" style="max-width:520px">
					<thead><tr><th>표시</th><th>플랫폼</th><th>배경색</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $this->networks() as $net => $label ) : ?>
						<tr>
							<td><input type="checkbox" name="net_<?php echo esc_attr( $net ); ?>" value="1" <?php checked( ! empty( $s['enabled'][ $net ] ) ); ?>></td>
							<td><?php echo esc_html( $label ); ?><?php echo 'kakao' === $net && '' === $s['kakao_key'] ? ' <span class="wsp-check-no">(키필요)</span>' : ''; ?></td>
							<td><input type="color" name="color_<?php echo esc_attr( $net ); ?>" value="<?php echo esc_attr( ! empty( $colors[ $net ] ) ? $colors[ $net ] : $brand[ $net ] ); ?>"></td>
							<td><span class="wsp-swatch wsp-social-<?php echo esc_attr( $net ); ?>" style="background:<?php echo esc_attr( ! empty( $colors[ $net ] ) ? $colors[ $net ] : $brand[ $net ] ); ?>"></span></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
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
			<div class="wsp-row-label"><strong>표시 위치</strong>
				<span class="wsp-row-help">글의 어디에 자동으로 넣을지. "숏코드만"이면 원하는 위치에 직접 삽입.</span></div>
			<div class="wsp-row-control">
				<select name="position">
					<option value="bottom" <?php selected( $s['position'], 'bottom' ); ?>>글 하단</option>
					<option value="top" <?php selected( $s['position'], 'top' ); ?>>글 상단</option>
					<option value="both" <?php selected( $s['position'], 'both' ); ?>>글 상단 + 하단</option>
					<option value="none" <?php selected( $s['position'], 'none' ); ?>>자동 삽입 안 함(숏코드만)</option>
				</select>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>숏코드</strong></div>
			<div class="wsp-row-control"><code class="wsp-code wsp-copy" data-copy="[wsp_social_share]">[wsp_social_share]</code> 원하는 위치에 붙여넣기</div>
		</div>
		<?php
	}
}
