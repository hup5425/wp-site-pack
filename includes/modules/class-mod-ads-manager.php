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
		// 인증 HTML 가상 서빙 — 자동 인덱싱 모듈로 옮기기 전에 여기 저장돼 있던 옛 파일만 해당.
		// (새 업로드는 자동 인덱싱 모듈에서 한다. 옮겨진 뒤에는 이 값이 비어 훅이 안 걸린다.)
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
			$this->send_virtual_headers( 'text/plain; charset=utf-8' );
			// 텍스트 파일이라 이스케이프 없이 그대로 내보낸다.
			// (esc_html 을 거치면 &·< 가 &amp;·&lt; 로 바뀌어 애드센스가 줄을 못 읽는다. 저장할 때 이미 태그를 걸러 둔다.)
			echo $this->settings()['ads_txt']; // phpcs:ignore WordPress.Security.EscapeOutput
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
			$this->send_virtual_headers( 'text/html; charset=utf-8' );
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
	 * 편집칸(textarea)에 넣을 robots 내용.
	 * 저장값이 있으면 그것, 없으면 물리 파일(저장하면 그 파일을 직접 고치므로 같은 것),
	 * 둘 다 없으면 **빈 값**.
	 *
	 * 지금 서빙 중인 robots(타 SEO 플러그인이 만드는 것)를 여기에 담지 않는다 —
	 * 담아 두면 ads.txt 만 고쳐 저장해도 그 내용이 우리 설정으로 굳어, 그 뒤로는
	 * 플러그인이 robots 를 바꿔도 옛 내용이 계속 나간다. 지금 서빙 중인 내용은
	 * 아래 live_robots_txt() 로 읽어 화면에 '보기 전용'으로만 보여 준다.
	 */
	protected function editable_robots_txt() {
		$saved = $this->settings()['robots_txt'];
		if ( '' !== trim( $saved ) ) {
			return $saved;
		}
		if ( file_exists( ABSPATH . 'robots.txt' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return (string) @file_get_contents( ABSPATH . 'robots.txt' );
		}
		return '';
	}

	/**
	 * 지금 실제 서빙되는 robots.txt(보기 전용·기본값 만들 때 참고용).
	 * 우리 필터는 저장값이 비어 있을 때 비활성이라, 라이브 값 = 타 플러그인/코어의 실제 결과.
	 */
	protected function live_robots_txt() {
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

	/**
	 * 텍스트 파일(ads.txt·robots.txt) 내용 정리 — 줄 단위로 태그만 걷어낸다.
	 *
	 * sanitize_textarea_field 를 쓰지 않는 이유: 그 함수는 `%20` 같은 퍼센트 표기를 통째로
	 * 지우고 `<` 를 `&lt;` 로 바꾼다. robots 의 `Disallow: /*%20*` 나 주소의 `&` 가 있는 줄이
	 * 조용히 망가진다. 이 파일들은 HTML 이 아니라 한 줄씩 읽히는 텍스트라 태그만 지우면 된다.
	 *
	 * @param string $raw 입력값.
	 * @return string
	 */
	protected function clean_txt( $raw ) {
		$raw   = wp_check_invalid_utf8( (string) $raw );
		$raw   = str_replace( array( "\r\n", "\r" ), "\n", $raw );
		$lines = array();
		foreach ( explode( "\n", $raw ) as $line ) {
			$lines[] = rtrim( wp_strip_all_tags( $line, false ) );
		}
		return trim( implode( "\n", $lines ) );
	}

	public function sanitize( $input ) {
		$s   = $this->settings();
		$out = array(
			'ads_txt'    => isset( $input['ads_txt'] ) ? $this->clean_txt( $input['ads_txt'] ) : '',
			'robots_txt' => isset( $input['robots_txt'] ) ? $this->clean_txt( $input['robots_txt'] ) : '',
			// 인증 파일은 자동 인덱싱 모듈에서 관리한다. 여기 남아 있는 옛 값은 그대로 둔다
			// (그 모듈이 켜질 때 옮겨 간다).
			'verify_files' => is_array( $s['verify_files'] ) ? $s['verify_files'] : array(),
		);

		// robots 기본값 채우기 요청 — 지금 서빙 중인 내용의 인증 줄(#)·Sitemap 줄은 지키면서.
		if ( ! empty( $input['robots_default'] ) ) {
			$base_for_keep     = '' !== trim( $out['robots_txt'] ) ? $out['robots_txt'] : $this->live_robots_txt();
			$out['robots_txt'] = $this->default_robots( $base_for_keep );
		}

		// 물리 파일이 존재하면 가상 서빙이 무시되므로 파일에 직접 반영. robots 캐시도 무효화.
		$this->write_through( 'ads.txt', $out['ads_txt'] );
		$this->write_through( 'robots.txt', $out['robots_txt'] );
		delete_transient( 'wsp_cur_robots' );

		return $out;
	}

	/**
	 * [기본값으로 덮어쓰기] 를 눌렀을 때 넣을 내용.
	 *
	 * 지금 서빙 중인 robots 에서 **지우면 안 되는 줄**을 그대로 가져온다:
	 *  · `#` 로 시작하는 줄 — 다음 웹마스터도구(#DaumWebMasterTool:) 처럼 인증에 쓰이는 줄.
	 *  · `Sitemap:` 줄 — 사이트맵 주소는 사이트마다 다르다(SEO 플러그인은 보통 /sitemap_index.xml).
	 * 가져올 사이트맵 줄이 없을 때만 /sitemap_index.xml 을 쓴다.
	 *
	 * @param string $current 지금 서빙 중인 robots 내용.
	 * @return string
	 */
	protected function default_robots( $current = '' ) {
		$keep        = array();
		$has_sitemap = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $current ) as $line ) {
			$t = trim( $line );
			if ( '' === $t ) {
				continue;
			}
			if ( 0 === strpos( $t, '#' ) ) {
				$keep[] = $t;
			} elseif ( 0 === stripos( $t, 'sitemap:' ) ) {
				$keep[]      = $t;
				$has_sitemap = true;
			}
		}
		if ( ! $has_sitemap ) {
			$keep[] = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
		}
		$base = "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php";
		return $base . "\n\n" . implode( "\n", $keep );
	}

	/** 물리 파일 존재 여부(가상 서빙이 가려지는지 진단). */
	protected function physical_exists( $file ) {
		return file_exists( ABSPATH . $file );
	}

	public function render_settings() {
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
				<span class="wsp-row-help">여기에 적은 내용이 robots.txt 가 됩니다. <strong>비워 두면</strong> 지금 서빙 중인 robots.txt(아래 보기)를 그대로 씁니다.</span></div>
			<div class="wsp-row-control">
				<?php $robots_edit = $this->editable_robots_txt(); ?>
				<textarea name="robots_txt" rows="8" spellcheck="false" placeholder="비워 두면 지금 서빙 중인 robots.txt 가 그대로 쓰입니다."><?php echo esc_textarea( $robots_edit ); ?></textarea>
				<p><label><input type="checkbox" name="robots_default" value="1"> 기본값으로 덮어쓰기(저장 시)</label>
					<span class="wsp-row-help">기본값을 넣어도 <code class="wsp-code">#</code> 로 시작하는 인증 줄과 <code class="wsp-code">Sitemap:</code> 줄은 그대로 지킵니다.</span></p>
				<?php if ( $this->physical_exists( 'robots.txt' ) ) : ?>
					<div class="wsp-note">현재 <strong>물리 robots.txt 파일</strong>의 내용을 불러왔습니다. 저장하면 이 파일을 직접 수정합니다.</div>
				<?php else : ?>
					<?php $live = $this->live_robots_txt(); ?>
					<div class="wsp-note">
						지금 서빙 중인 robots.txt(<strong>보기 전용</strong>) — 다른 SEO 플러그인(예: Rank Math)이 만들고 있을 수 있습니다.
						위 칸을 <strong>비워 두면 이 내용이 그대로 나갑니다.</strong>
						<textarea rows="8" spellcheck="false" readonly style="width:100%;margin-top:6px;background:#f6f7f7"><?php echo esc_textarea( $live ); ?></textarea>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트 인증 파일</strong>
				<span class="wsp-row-help">네이버 서치어드바이저 / 구글 서치콘솔 HTML 인증 파일.</span></div>
			<div class="wsp-row-control">
				<?php
				// 같은 기능이 두 모듈에 있으면 어느 쪽에 올렸는지 헷갈리고 한쪽만 고쳐진다 →
				// 업로드·삭제는 자동 인덱싱 모듈 한 곳에서만 한다.
				$ai      = WSP_Core::module( 'auto_index' );
				$ai_link = $ai ? $ai->settings_url() : '';
				?>
				<p><strong>자동 인덱싱 모듈</strong>에서 관리합니다.
					<?php if ( $ai_link ) : ?>
						<a href="<?php echo esc_url( $ai_link ); ?>">자동 인덱싱 설정 열기 →</a>
					<?php endif; ?>
				</p>
			</div>
		</div>
		<?php
	}
}
