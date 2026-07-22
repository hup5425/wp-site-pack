<?php
/**
 * 모듈: 헤더 & 푸터 코드 삽입.
 *  - 관리자만 입력 → 그대로 출력(이스케이프 X). 저장 시 권한·nonce 확인.
 *  - 4곳: wp_head / wp_body_open / wp_footer(앞) / wp_footer.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Header_Footer extends WSP_Module {

	public function id()   { return 'header_footer'; }
	public function name() { return '헤더 & 푸터'; }
	public function desc() { return '분석/광고 스크립트 등 원시 코드를 <head>·<body>·푸터에 삽입합니다.'; }
	public function icon() { return 'dashicons-editor-code'; }

	public function default_settings() {
		return array(
			'head'        => '',
			'body_open'   => '',
			'footer_pre'  => '',
			'footer'      => '',
		);
	}

	public function register() {
		$s = $this->settings();

		if ( '' !== trim( $s['head'] ) ) {
			add_action( 'wp_head', function () use ( $s ) {
				echo "\n" . $s['head'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}, 20 );
		}
		if ( '' !== trim( $s['body_open'] ) ) {
			add_action( 'wp_body_open', function () use ( $s ) {
				echo "\n" . $s['body_open'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}, 5 );
		}
		if ( '' !== trim( $s['footer_pre'] ) ) {
			add_action( 'wp_footer', function () use ( $s ) {
				echo "\n" . $s['footer_pre'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}, 5 );
		}
		if ( '' !== trim( $s['footer'] ) ) {
			add_action( 'wp_footer', function () use ( $s ) {
				echo "\n" . $s['footer'] . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
			}, 99 );
		}
	}

	public function sanitize( $input ) {
		// 관리자 원시 코드 허용 — unfiltered_html 권한 있을 때만 그대로, 없으면 태그 제거.
		$raw = current_user_can( 'unfiltered_html' );
		$fields = array( 'head', 'body_open', 'footer_pre', 'footer' );
		$out = array();
		foreach ( $fields as $f ) {
			$val = isset( $input[ $f ] ) ? (string) $input[ $f ] : '';
			$out[ $f ] = $raw ? $val : wp_kses_post( $val );
		}
		return $out;
	}

	public function render_settings() {
		$s = $this->settings();
		$rows = array(
			'head'       => array( '헤더 코드', '<code>&lt;head&gt;</code> 내부(wp_head)에 출력. 분석/검증 메타 등.' ),
			'body_open'  => array( 'Body 시작 코드', '<code>&lt;body&gt;</code> 직후(wp_body_open). GTM noscript 등.' ),
			'footer_pre' => array( 'Body 종료 전 코드', '푸터 앞쪽(wp_footer 우선순위 5).' ),
			'footer'     => array( '푸터 코드', '페이지 맨 끝(wp_footer 우선순위 99).' ),
		);
		foreach ( $rows as $key => $meta ) :
			?>
			<div class="wsp-row">
				<div class="wsp-row-label">
					<strong><?php echo esc_html( $meta[0] ); ?></strong>
					<span class="wsp-row-help"><?php echo wp_kses_post( $meta[1] ); ?></span>
				</div>
				<div class="wsp-row-control">
					<textarea name="<?php echo esc_attr( $key ); ?>" rows="5" spellcheck="false"><?php echo esc_textarea( $s[ $key ] ); ?></textarea>
				</div>
			</div>
			<?php
		endforeach;
		?>
		<div class="wsp-note">코드 저장 후 웹사이트에 즉시 반영됩니다. 캐시 플러그인을 쓰면 캐시를 한 번 비워주세요. 입력한 코드는 관리자 책임 영역입니다.</div>
		<?php
	}
}
