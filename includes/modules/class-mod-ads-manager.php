<?php
/**
 * 모듈: Ads 매니저.
 *  - ads.txt / robots.txt 내용 편집(가상 서빙 우선).
 *  - 네이버/구글 사이트 인증 HTML 파일 가상 서빙.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Ads_Manager extends WSP_Module {

	public function id()   { return 'ads_manager'; }
	public function name() { return 'Ads 매니저'; }
	public function desc() { return 'ads.txt·robots.txt·사이트 인증파일을 파일 업로드 없이 관리합니다.'; }
	public function icon() { return 'dashicons-media-text'; }

	public function default_settings() {
		return array(
			'ads_txt'      => '',
			'robots_txt'   => '',
			'verify_files' => array(), // filename => content
		);
	}

	public function register() {
		$s = $this->settings();

		// ads.txt 가상 서빙.
		if ( '' !== trim( $s['ads_txt'] ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_serve_ads' ), 1 );
		}
		// robots.txt 는 WP robots_txt 필터로 치환(우선순위 높게).
		if ( '' !== trim( $s['robots_txt'] ) ) {
			add_filter( 'robots_txt', array( $this, 'filter_robots' ), 99, 2 );
		}
		// 인증 HTML 가상 서빙.
		if ( ! empty( $s['verify_files'] ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_serve_verify' ), 1 );
		}
	}

	protected function req_path() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return trim( wp_parse_url( $req, PHP_URL_PATH ) ?: '', '/' );
	}

	public function maybe_serve_ads() {
		if ( 'ads.txt' === $this->req_path() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: public, max-age=300' ); // CDN 장기 캐시 방지(수정 즉시 반영되게).
			echo esc_html( $this->settings()['ads_txt'] );
			exit;
		}
	}

	public function filter_robots( $output, $public ) {
		// 물리 robots.txt 가 있으면 그것이 우선이라 안내 필요(설정 화면에 표기).
		return $this->settings()['robots_txt'];
	}

	public function maybe_serve_verify() {
		$path  = $this->req_path();
		$files = $this->settings()['verify_files'];
		if ( isset( $files[ $path ] ) ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: public, max-age=300' ); // CDN 장기 캐시 방지.
			echo $files[ $path ]; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	/**
	 * 현재 실제 서빙되는 ads.txt 내용(설정 화면 자동 채움용).
	 * 우선순위: 저장된 값 > 물리 파일 > 빈값.
	 */
	protected function current_ads_txt() {
		$saved = $this->settings()['ads_txt'];
		if ( '' !== trim( $saved ) ) {
			return $saved;
		}
		if ( file_exists( ABSPATH . 'ads.txt' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return (string) @file_get_contents( ABSPATH . 'ads.txt' );
		}
		return '';
	}

	/**
	 * 현재 실제 서빙되는 robots.txt 내용(자동 채움용).
	 * 저장값이 있으면 그것, 없으면 물리 파일, 그것도 없으면 지금 실제 서빙되는 robots
	 * (우리 필터는 저장값이 비어 있을 때 비활성이라, 라이브 값 = 타 플러그인/코어의 실제 결과).
	 */
	protected function current_robots_txt() {
		$saved = $this->settings()['robots_txt'];
		if ( '' !== trim( $saved ) ) {
			return $saved;
		}
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return (string) @file_get_contents( ABSPATH . 'robots.txt' );
		}
		$cached = get_transient( 'wsp_cur_robots' );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		$res  = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 5 ) );
		$body = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		set_transient( 'wsp_cur_robots', $body, 5 * MINUTE_IN_SECONDS );
		return $body;
	}

	/**
	 * 물리 파일이 이미 있으면(=가상 서빙이 무시되는 상황) 그 파일을 직접 갱신.
	 * 새 물리 파일은 만들지 않음(가상 서빙 우선 원칙). 쓰기 불가면 조용히 통과.
	 */
	protected function write_through( $file, $content ) {
		$path = ABSPATH . $file;
		if ( file_exists( $path ) && is_writable( $path ) && '' !== trim( $content ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			@file_put_contents( $path, $content );
		}
	}

	public function sanitize( $input ) {
		$s   = $this->settings();
		$out = array(
			'ads_txt'      => isset( $input['ads_txt'] ) ? sanitize_textarea_field( (string) $input['ads_txt'] ) : '',
			'robots_txt'   => isset( $input['robots_txt'] ) ? sanitize_textarea_field( (string) $input['robots_txt'] ) : '',
			'verify_files' => is_array( $s['verify_files'] ) ? $s['verify_files'] : array(),
		);

		// robots 기본값 채우기 요청.
		if ( ! empty( $input['robots_default'] ) ) {
			$out['robots_txt'] = $this->default_robots();
		}

		// 물리 파일이 존재하면 가상 서빙이 무시되므로 파일에 직접 반영. robots 캐시도 무효화.
		$this->write_through( 'ads.txt', $out['ads_txt'] );
		$this->write_through( 'robots.txt', $out['robots_txt'] );
		delete_transient( 'wsp_cur_robots' );

		// 인증 파일 업로드(네이버/구글). 파일명·내용만 저장.
		foreach ( array( 'verify_naver', 'verify_google' ) as $field ) {
			if ( ! empty( $_FILES[ $field ]['name'] ) && empty( $_FILES[ $field ]['error'] ) ) {
				$name = sanitize_file_name( $_FILES[ $field ]['name'] );
				$tmp  = $_FILES[ $field ]['tmp_name']; // phpcs:ignore
				if ( $name && is_uploaded_file( $tmp ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$content = (string) @file_get_contents( $tmp );
					if ( strlen( $content ) < 100000 ) { // 100KB 캡.
						$out['verify_files'][ $name ] = $content;
					}
				}
			}
		}
		// 인증 파일 삭제.
		if ( ! empty( $input['remove_verify'] ) ) {
			$rm = sanitize_file_name( (string) $input['remove_verify'] );
			unset( $out['verify_files'][ $rm ] );
		}
		return $out;
	}

	protected function default_robots() {
		$sitemap = home_url( '/sitemap.xml' );
		return "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: {$sitemap}";
	}

	/** 물리 파일 존재 여부(가상 서빙이 가려지는지 진단). */
	protected function physical_exists( $file ) {
		return file_exists( ABSPATH . $file );
	}

	public function render_settings() {
		$s   = $this->settings();
		$pub = WSP_Stats_Bridge::adsense_pub_id();
		?>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>현재 파일 상태</strong></div>
			<div class="wsp-row-control">
				ads.txt(물리): <?php echo $this->physical_exists( 'ads.txt' ) ? '<span class="wsp-check-no">있음(물리 파일이 우선)</span>' : '<span class="wsp-check-ok">없음(가상 서빙 사용 가능)</span>'; ?><br>
				robots.txt(물리): <?php echo $this->physical_exists( 'robots.txt' ) ? '<span class="wsp-check-no">있음(물리 파일이 우선)</span>' : '<span class="wsp-check-ok">없음(가상 서빙 사용 가능)</span>'; ?>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>ads.txt 내용</strong>
				<span class="wsp-row-help"><?php echo $pub ? '통계 연동 추천 pub-id: ' . esc_html( $pub ) : '예: google.com, pub-XXXX, DIRECT, f08c47fec0942fa0'; ?></span></div>
			<div class="wsp-row-control">
				<textarea name="ads_txt" rows="4" spellcheck="false"><?php echo esc_textarea( $this->current_ads_txt() ); ?></textarea>
				<?php if ( $this->physical_exists( 'ads.txt' ) ) : ?>
					<div class="wsp-note">현재 <strong>물리 ads.txt 파일</strong>의 내용을 불러왔습니다. 저장하면 이 파일을 직접 수정합니다.</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>robots.txt 내용</strong>
				<span class="wsp-row-help">아래는 <strong>지금 실제 서빙되는 robots.txt</strong>를 그대로 불러온 것입니다.</span></div>
			<div class="wsp-row-control">
				<textarea name="robots_txt" rows="8" spellcheck="false"><?php echo esc_textarea( $this->current_robots_txt() ); ?></textarea>
				<p><label><input type="checkbox" name="robots_default" value="1"> 기본값으로 덮어쓰기(저장 시)</label></p>
				<?php if ( ! $this->physical_exists( 'robots.txt' ) && '' === trim( $s['robots_txt'] ) ) : ?>
					<div class="wsp-note">⚠️ 이 robots.txt는 <strong>다른 SEO 플러그인(예: Rank Math)이 생성</strong>하고 있을 수 있습니다.
						위 내용을 <strong>지우지 말고 그 위에서 편집</strong>하세요. 저장하면 이 내용으로 고정되며,
						비워서 저장하면 다시 원래(플러그인 생성) robots로 돌아갑니다.</div>
				<?php elseif ( $this->physical_exists( 'robots.txt' ) ) : ?>
					<div class="wsp-note">현재 <strong>물리 robots.txt 파일</strong>의 내용을 불러왔습니다. 저장하면 이 파일을 직접 수정합니다.</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트 인증 파일</strong>
				<span class="wsp-row-help">네이버 서치어드바이저 / 구글 서치콘솔 HTML 인증 파일.</span></div>
			<div class="wsp-row-control">
				네이버: <input type="file" name="verify_naver"><br>
				구글: <input type="file" name="verify_google">
				<?php if ( ! empty( $s['verify_files'] ) ) : ?>
					<table class="widefat striped" style="margin-top:10px"><tbody>
					<?php foreach ( $s['verify_files'] as $fname => $c ) : ?>
						<tr>
							<td><code class="wsp-code"><?php echo esc_html( $fname ); ?></code>
								<a href="<?php echo esc_url( home_url( '/' . $fname ) ); ?>" target="_blank">확인</a></td>
							<td><button type="submit" name="remove_verify" value="<?php echo esc_attr( $fname ); ?>" class="button-link-delete">삭제</button></td>
						</tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
