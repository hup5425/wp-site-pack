<?php
/**
 * 모듈: 자동 인덱싱 (IndexNow).
 *  - 게시글 발행/수정 시 IndexNow 엔드포인트로 URL 제출(네이버/Bing 등).
 *  - 키 파일 가상 서빙: https://site/{key}.txt → 키 텍스트.
 *  - 탭: 설정 / 로그 / 수동 인덱싱.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Auto_Index extends WSP_Module {

	const ENDPOINT = 'https://api.indexnow.org/indexnow';

	public function id()   { return 'auto_index'; }
	public function name() { return '자동 인덱싱 (IndexNow)'; }
	public function desc() { return '글 발행/수정 시 검색엔진(네이버·Bing 등)에 색인 요청을 자동 전송합니다.'; }
	public function icon() { return 'dashicons-search'; }

	public function default_settings() {
		return array(
			'key'       => '',
			'auto'      => 1,
			'types'         => array( 'post' => 1, 'page' => 0 ),
			'verify_bing'   => '',
			'verify_naver'  => '',
			'verify_google' => '',
			'verify_files'  => array(), // filename => content (업로드한 인증 HTML)
			'log'           => array(),
		);
	}

	public function register() {
		// 키 파일 가상 서빙.
		add_action( 'template_redirect', array( $this, 'maybe_serve_key' ) );

		// 빙/네이버/구글 사이트 소유 인증 메타.
		add_action( 'wp_head', array( $this, 'output_verification' ), 1 );

		// 업로드한 인증 HTML 파일 가상 서빙.
		if ( ! empty( $this->settings()['verify_files'] ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_serve_verify' ), 1 );
		}

		$s = $this->settings();
		if ( ! empty( $s['auto'] ) && '' !== $s['key'] ) {
			// 발행 진입 시 제출(비동기: 단일 이벤트 스케줄).
			add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		}
		add_action( 'wsp_indexnow_submit', array( $this, 'submit_url' ) );
	}

	/** 빙/네이버/구글 인증 메타 태그 출력(<head>). */
	public function output_verification() {
		$s = $this->settings();
		if ( ! empty( $s['verify_bing'] ) ) {
			echo '<meta name="msvalidate.01" content="' . esc_attr( $s['verify_bing'] ) . '" />' . "\n";
		}
		if ( ! empty( $s['verify_naver'] ) ) {
			echo '<meta name="naver-site-verification" content="' . esc_attr( $s['verify_naver'] ) . '" />' . "\n";
		}
		if ( ! empty( $s['verify_google'] ) ) {
			echo '<meta name="google-site-verification" content="' . esc_attr( $s['verify_google'] ) . '" />' . "\n";
		}
	}

	/** 업로드한 인증 HTML 파일을 루트 경로에서 서빙(예: googleXXXX.html, naverXXXX.html). */
	public function maybe_serve_verify() {
		$req  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = trim( wp_parse_url( $req, PHP_URL_PATH ) ?: '', '/' );
		$files = $this->settings()['verify_files'];
		if ( is_array( $files ) && isset( $files[ $path ] ) ) {
			header( 'Content-Type: text/html; charset=utf-8' );
			header( 'Cache-Control: public, max-age=300' );
			echo $files[ $path ]; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	/**
	 * 사이트 루트에 이미 존재하는 IndexNow 키 파일 감지(다른 플러그인/수동 업로드로 생긴 것).
	 * IndexNow 규격상 키 파일은 {key}.txt 이고 내용이 곧 키다(파일명==내용) → 그걸로 판별.
	 *
	 * @return string[] 감지된 키 목록.
	 */
	public function detect_existing_keys() {
		$found = array();
		$dir   = ABSPATH;
		if ( ! is_dir( $dir ) || ! is_readable( $dir ) ) {
			return $found;
		}
		$files = @scandir( $dir ); // phpcs:ignore
		if ( ! is_array( $files ) ) {
			return $found;
		}
		foreach ( $files as $f ) {
			if ( '.txt' !== substr( $f, -4 ) ) {
				continue;
			}
			$base = substr( $f, 0, -4 );
			// IndexNow 키 형식(영문/숫자/하이픈 8~128자). license.txt/readme.txt 등은 형식·내용 불일치로 제외됨.
			if ( ! preg_match( '/^[A-Za-z0-9\-]{8,128}$/', $base ) ) {
				continue;
			}
			$path = $dir . $f;
			if ( ! is_readable( $path ) || filesize( $path ) > 200 ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = trim( (string) @file_get_contents( $path ) );
			if ( $content === $base ) {
				$found[] = $base;
			}
		}
		return array_values( array_unique( $found ) );
	}

	/** {key}.txt 요청 시 키 문자열 반환(물리 파일 불필요). */
	public function maybe_serve_key() {
		$key = (string) $this->settings()['key'];
		if ( '' === $key ) {
			return;
		}
		$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = trim( wp_parse_url( $req, PHP_URL_PATH ) ?: '', '/' );
		if ( $path === $key . '.txt' ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: public, max-age=600' ); // CDN 1년 캐시 방지(키는 가끔 바뀔 수 있음).
			echo esc_html( $key );
			exit;
		}
	}

	/**
	 * 게시된 모든 글/페이지 URL 을 IndexNow 로 일괄 제출(100개씩 나눠서).
	 *
	 * @return int 제출한 URL 수.
	 */
	public function submit_bulk() {
		$s   = $this->settings();
		$key = (string) $s['key'];
		if ( '' === $key ) {
			return 0;
		}
		$types = array_keys( array_filter( (array) $s['types'] ) );
		if ( empty( $types ) ) {
			$types = array( 'post' );
		}
		$ids = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => 1000,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		$list = array();
		foreach ( $ids as $id ) {
			$u = get_permalink( $id );
			if ( $u ) {
				$list[] = $u;
			}
		}
		if ( empty( $list ) ) {
			return 0;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$done = 0;
		foreach ( array_chunk( $list, 100 ) as $chunk ) {
			$body = array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => home_url( '/' . $key . '.txt' ),
				'urlList'     => $chunk,
			);
			$res  = wp_remote_post( self::ENDPOINT, array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$this->log_submit( '[일괄] ' . count( $chunk ) . '개 URL', $code );
			if ( $code >= 200 && $code < 300 ) {
				$done += count( $chunk );
			}
		}
		return $done;
	}

	public function on_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		$types = $this->settings()['types'];
		if ( empty( $types[ $post->post_type ] ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( $url ) {
			// 비동기: 즉시 페이지를 막지 않도록 single event 예약(1초 후).
			wp_schedule_single_event( time() + 1, 'wsp_indexnow_submit', array( $url ) );
		}
	}

	/**
	 * IndexNow 로 URL 제출.
	 *
	 * @param string $url
	 * @return int HTTP 응답코드(0=실패).
	 */
	public function submit_url( $url ) {
		$s   = $this->settings();
		$key = (string) $s['key'];
		if ( '' === $key || '' === $url ) {
			return 0;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$body = array(
			'host'        => $host,
			'key'         => $key,
			'keyLocation' => home_url( '/' . $key . '.txt' ),
			'urlList'     => array( $url ),
		);
		$res = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 10,
			'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'    => wp_json_encode( $body ),
		) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$this->log_submit( $url, $code );
		return $code;
	}

	protected function log_submit( $url, $code ) {
		$s   = $this->settings();
		$log = is_array( $s['log'] ) ? $s['log'] : array();
		array_unshift( $log, array(
			'url'  => $url,
			'code' => $code,
			'time' => current_time( 'mysql' ),
		) );
		$s['log'] = array_slice( $log, 0, 100 ); // 옵션 캡 100건.
		WSP_Settings::set( $this->id(), $s );
	}

	public function sanitize( $input ) {
		$s   = $this->settings();
		$out = array(
			'key'          => isset( $input['key'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $input['key'] ) : '',
			'auto'         => empty( $input['auto'] ) ? 0 : 1,
			'types'        => array(
				'post' => empty( $input['type_post'] ) ? 0 : 1,
				'page' => empty( $input['type_page'] ) ? 0 : 1,
			),
			'verify_bing'   => isset( $input['verify_bing'] ) ? sanitize_text_field( (string) $input['verify_bing'] ) : '',
			'verify_naver'  => isset( $input['verify_naver'] ) ? sanitize_text_field( (string) $input['verify_naver'] ) : '',
			'verify_google' => isset( $input['verify_google'] ) ? sanitize_text_field( (string) $input['verify_google'] ) : '',
			'verify_files'  => is_array( $s['verify_files'] ) ? $s['verify_files'] : array(),
			'log'           => is_array( $s['log'] ) ? $s['log'] : array(),
		);

		// 인증 HTML 파일 업로드(구글/빙/네이버 파일 방식). 파일명·내용만 저장.
		if ( ! empty( $_FILES['verify_upload']['name'] ) && empty( $_FILES['verify_upload']['error'] ) ) {
			$name = sanitize_file_name( $_FILES['verify_upload']['name'] );
			$tmp  = isset( $_FILES['verify_upload']['tmp_name'] ) ? $_FILES['verify_upload']['tmp_name'] : ''; // phpcs:ignore
			if ( $name && is_uploaded_file( $tmp ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$content = (string) @file_get_contents( $tmp );
				if ( strlen( $content ) < 100000 ) { // 100KB 캡.
					$out['verify_files'][ $name ] = $content;
				}
			}
		}
		// 인증 파일 삭제.
		if ( ! empty( $input['remove_verify'] ) ) {
			unset( $out['verify_files'][ sanitize_file_name( (string) $input['remove_verify'] ) ] );
		}

		// 수동 인덱싱 요청(저장과 동시에 즉시 제출).
		if ( ! empty( $input['manual_url'] ) ) {
			$manual = esc_url_raw( trim( (string) $input['manual_url'] ) );
			if ( $manual ) {
				// out 을 먼저 저장한 뒤 제출되도록, 임시로 저장 반영 후 호출.
				WSP_Settings::set( $this->id(), $out );
				$this->submit_url( $manual );
				return WSP_Settings::get( $this->id(), $this->default_settings() );
			}
		}
		// 전체 URL 일괄 제출 요청.
		if ( ! empty( $input['bulk_submit'] ) ) {
			WSP_Settings::set( $this->id(), $out );
			$this->submit_bulk();
			return WSP_Settings::get( $this->id(), $this->default_settings() );
		}
		return $out;
	}

	public function render_settings() {
		$s = $this->settings();
		?>
		<div class="wsp-tabs">
			<div class="wsp-tab active" data-tab="set">설정</div>
			<div class="wsp-tab" data-tab="log">로그</div>
			<div class="wsp-tab" data-tab="man">수동 인덱싱</div>
		</div>

		<div class="wsp-tabpane active" data-pane="set">
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>IndexNow API 키</strong>
					<span class="wsp-row-help">영문/숫자/하이픈. 키 파일은 자동 가상 서빙됩니다.</span></div>
				<div class="wsp-row-control">
					<input type="text" name="key" id="wsp_indexnow_key" value="<?php echo esc_attr( $s['key'] ); ?>" placeholder="예: a1b2c3d4e5f6..." style="width:55%">
					<button type="button" class="button wsp-gen-key" data-target="#wsp_indexnow_key">키 생성</button>
					<p class="wsp-row-help">키가 없으면 <strong>키 생성</strong>을 누른 뒤 <strong>저장하기</strong>를 누르세요. 키 파일은 자동으로 서빙됩니다.</p>
					<?php
					$existing = $this->detect_existing_keys();
					$others   = array_values( array_diff( $existing, array( (string) $s['key'] ) ) );
					if ( ! empty( $others ) ) :
						?>
						<div class="wsp-note">
							⚠️ 이 사이트에 <strong>이미 IndexNow 키 파일이 있습니다</strong>(다른 플러그인·수동 업로드 등). 중복을 피하려면 <strong>기존 키를 그대로 사용</strong>하세요:
							<ul style="margin:6px 0 0">
								<?php foreach ( $others as $k ) : ?>
									<li>
										<code class="wsp-code"><?php echo esc_html( $k ); ?></code>
										<button type="button" class="button button-small wsp-use-key" data-target="#wsp_indexnow_key" data-key="<?php echo esc_attr( $k ); ?>">이 키 사용</button>
										<a href="<?php echo esc_url( home_url( '/' . $k . '.txt' ) ); ?>" target="_blank">열기</a>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
					<?php if ( '' !== $s['key'] ) : ?>
						<p>키 파일: <code class="wsp-code"><?php echo esc_html( home_url( '/' . $s['key'] . '.txt' ) ); ?></code>
							<a href="<?php echo esc_url( home_url( '/' . $s['key'] . '.txt' ) ); ?>" target="_blank">열기</a></p>
					<?php endif; ?>
				</div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>자동 인덱싱</strong>
					<span class="wsp-row-help">글 발행/수정 시 자동으로 색인 요청 전송.</span></div>
				<div class="wsp-row-control">
					<label><input type="checkbox" name="auto" value="1" <?php checked( $s['auto'], 1 ); ?>> 활성화</label>
				</div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>인덱싱 대상</strong></div>
				<div class="wsp-row-control wsp-chips">
					<label class="wsp-chip"><input type="checkbox" name="type_post" value="1" <?php checked( ! empty( $s['types']['post'] ) ); ?>> 게시글</label>
					<label class="wsp-chip"><input type="checkbox" name="type_page" value="1" <?php checked( ! empty( $s['types']['page'] ) ); ?>> 페이지</label>
				</div>
			</div>

			<div class="wsp-row">
				<div class="wsp-row-label"><strong>빙 웹마스터 인증</strong>
					<span class="wsp-row-help">빙 웹마스터툴의 메타태그 인증 코드(msvalidate.01 값만).</span></div>
				<div class="wsp-row-control"><input type="text" name="verify_bing" value="<?php echo esc_attr( $s['verify_bing'] ); ?>" placeholder="예: A1B2C3D4E5F6..."></div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>네이버 서치어드바이저 인증</strong>
					<span class="wsp-row-help">네이버 메타태그 인증 코드(naver-site-verification 값만).</span></div>
				<div class="wsp-row-control"><input type="text" name="verify_naver" value="<?php echo esc_attr( $s['verify_naver'] ); ?>" placeholder="예: 1a2b3c..."></div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>구글 서치콘솔 인증</strong>
					<span class="wsp-row-help">구글 메타태그 인증 코드(google-site-verification 값만).</span></div>
				<div class="wsp-row-control"><input type="text" name="verify_google" value="<?php echo esc_attr( $s['verify_google'] ); ?>" placeholder="예: AbCdEf..."></div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>인증 HTML 파일 업로드</strong>
					<span class="wsp-row-help">메타태그 대신 <strong>파일 방식</strong>으로 인증할 때. 구글/빙/네이버가 준 HTML 파일을 그대로 올리세요(루트에서 자동 서빙).</span></div>
				<div class="wsp-row-control">
					<input type="file" name="verify_upload" accept=".html,.htm,.txt,.xml">
					<?php $vf = $s['verify_files']; if ( ! empty( $vf ) ) : ?>
						<table class="widefat striped" style="margin-top:10px;max-width:520px"><tbody>
						<?php foreach ( $vf as $fname => $c ) : ?>
							<tr>
								<td><code class="wsp-code"><?php echo esc_html( $fname ); ?></code>
									<a href="<?php echo esc_url( home_url( '/' . $fname ) ); ?>" target="_blank">열기</a></td>
								<td style="text-align:right"><button type="submit" name="remove_verify" value="<?php echo esc_attr( $fname ); ?>" class="button-link-delete">삭제</button></td>
							</tr>
						<?php endforeach; ?>
						</tbody></table>
					<?php endif; ?>
				</div>
			</div>

			<div class="wsp-row">
				<div class="wsp-row-label"><strong>전체 URL 일괄 제출</strong>
					<span class="wsp-row-help">게시된 모든 글/페이지를 빙·네이버 등(IndexNow)에 한 번에 제출. 처음 연결 시 유용.</span></div>
				<div class="wsp-row-control">
					<label><input type="checkbox" name="bulk_submit" value="1"> 저장 시 전체 제출 실행</label>
					<?php if ( '' === $s['key'] ) : ?><p class="wsp-check-no">먼저 IndexNow 키를 입력·저장해야 합니다.</p><?php endif; ?>
				</div>
			</div>

			<div class="wsp-note">IndexNow는 빙·네이버·Yandex 등 참여 엔진이 신호를 공유합니다(구글은 미참여). 요청을 보낼 뿐, 인덱싱 결과를 보장하지 않습니다.</div>
		</div>

		<div class="wsp-tabpane" data-pane="log">
			<?php if ( empty( $s['log'] ) ) : ?>
				<p>아직 제출 기록이 없습니다.</p>
			<?php else : ?>
				<table class="widefat striped"><thead><tr><th>URL</th><th>응답</th><th>시각</th></tr></thead><tbody>
				<?php foreach ( $s['log'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['url'] ); ?></td>
						<td><?php echo (int) $row['code'] === 200 ? '<span class="wsp-check-ok">200</span>' : esc_html( $row['code'] ); ?></td>
						<td><?php echo esc_html( $row['time'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>

		<div class="wsp-tabpane" data-pane="man">
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>URL 직접 제출</strong>
					<span class="wsp-row-help">저장 시 즉시 IndexNow로 전송됩니다.</span></div>
				<div class="wsp-row-control">
					<input type="url" name="manual_url" placeholder="https://<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>/...">
				</div>
			</div>
		</div>
		<?php
	}
}
