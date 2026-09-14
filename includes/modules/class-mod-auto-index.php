<?php
/**
 * 모듈: 자동 인덱싱 (IndexNow · 구글 인덱싱 API).
 *  - 게시글 발행/수정 시 IndexNow 엔드포인트로 URL 제출(네이버/Bing 등).
 *  - 같은 자리에서 구글 인덱싱 API 로도 보낸다(서비스 계정 키 → JWT → 액세스 토큰).
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

	/** 구글 인덱싱 API 제출 주소. */
	const GOOGLE_ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';

	/** 구글 액세스 토큰을 받는 주소. */
	const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';

	/** 구글 인덱싱 API 권한 범위. */
	const GOOGLE_SCOPE = 'https://www.googleapis.com/auth/indexing';

	/** 받은 액세스 토큰을 담아 두는 트랜지언트 이름(55분). */
	const GOOGLE_TOKEN_TRANSIENT = 'wsp_google_index_token';

	/** 구글로 보내는 단발 예약(WP-Cron) 훅 이름. */
	const GOOGLE_HOOK = 'wsp_google_submit';

	/** 오늘 구글로 보낸 수를 세는 옵션(날짜·수). autoload 안 함. */
	const GOOGLE_QUOTA_OPTION = 'wsp_google_index_quota';

	/** 구글 인덱싱 API 하루 한도(구글이 정한 기본값). */
	const GOOGLE_DAILY_LIMIT = 200;

	/** 서비스 계정 키가 잘못돼 저장하지 못했을 때 화면에 한 번 보여 줄 안내. */
	const GOOGLE_ERROR_TRANSIENT = 'wsp_google_key_error';

	/** 제출 기록 옵션(설정과 분리 — 같은 칸에 쓰면 관리자 저장과 서로 덮어쓴다). autoload 안 함. */
	const LOG_OPTION = 'wsp_log_auto_index';

	/** 일괄 제출 진행 상태 옵션(예약·진행률). autoload 안 함. */
	const BULK_OPTION = 'wsp_bulk_auto_index';

	/** 일괄 제출을 이어서 돌리는 단발 예약(WP-Cron) 훅 이름. */
	const BULK_HOOK = 'wsp_indexnow_bulk_run';

	/** 한 번에 IndexNow 로 보내는 URL 수(IndexNow 권장 범위). */
	const BULK_CHUNK = 100;

	public function id()   { return 'auto_index'; }
	public function name() { return '자동 인덱싱 (IndexNow·구글)'; }
	public function desc() { return '글 발행/수정 시 검색엔진(네이버·Bing 등)과 구글 인덱싱 API 에 색인 요청을 자동 전송합니다.'; }
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
			'google_auto'     => 0,     // 구글 인덱싱 API 자동 전송
			'google_key_json' => '',    // 구글 서비스 계정 키(JSON) 원문
		);
	}

	public function register() {
		// 옛 버전에서 넘어온 값 옮기기(로그 → 별도 옵션, Ads 매니저의 인증 파일 → 이 모듈).
		$this->migrate_log();
		$this->migrate_verify_files();

		// 키 파일 가상 서빙. 우선순위 1 — 코어의 redirect_canonical(10) 이 404 를 다른 주소로
		// 돌려보내기 전에 먼저 내보낸다.
		add_action( 'template_redirect', array( $this, 'maybe_serve_key' ), 1 );

		// 빙/네이버/구글 사이트 소유 인증 메타.
		add_action( 'wp_head', array( $this, 'output_verification' ), 1 );

		// 업로드한 인증 HTML 파일 가상 서빙.
		if ( ! empty( $this->settings()['verify_files'] ) ) {
			add_action( 'template_redirect', array( $this, 'maybe_serve_verify' ), 1 );
		}

		// 일괄 제출은 저장 요청 안에서 돌리지 않고 WP-Cron 단발 예약으로 이어서 보낸다.
		add_action( self::BULK_HOOK, array( $this, 'run_bulk_batch' ) );

		if ( $this->indexnow_ready() || $this->google_ready() ) {
			// 발행 진입 시 제출(비동기: 단일 이벤트 스케줄).
			add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		}
		if ( $this->google_ready() ) {
			// 휴지통·완전삭제는 상태 변화가 아니라 여기서 본다.
			// 휴지통으로 보내면 글 주소 뒤에 __trashed 가 붙어, 상태가 바뀐 뒤에는 원래 주소를 못 읽는다.
			add_action( 'wp_trash_post', array( $this, 'on_remove' ) );
			add_action( 'before_delete_post', array( $this, 'on_remove' ) );
		}
		add_action( 'wsp_indexnow_submit', array( $this, 'submit_url' ) );
		add_action( self::GOOGLE_HOOK, array( $this, 'submit_google' ), 10, 2 );

		// 수동 인덱싱(글 목록) AJAX.
		add_action( 'wp_ajax_wsp_indexnow_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_wsp_indexnow_post', array( $this, 'ajax_index_post' ) );
		add_action( 'wp_ajax_wsp_indexnow_bulk', array( $this, 'ajax_index_bulk' ) );
	}

	/** 모듈을 끄면 남은 일괄 제출·구글 전송 예약도 푼다(끈 뒤에 혼자 계속 보내지 않게). */
	public function on_deactivate() {
		wp_clear_scheduled_hook( self::BULK_HOOK );
		wp_clear_scheduled_hook( self::GOOGLE_HOOK );
		delete_transient( self::GOOGLE_TOKEN_TRANSIENT );
	}

	/* ------------------------------ 옛 값 옮기기 ------------------------------ */

	/**
	 * 옛 버전은 제출 기록을 설정과 같은 옵션(wsp_mod_auto_index['log'])에 썼다.
	 * 그러면 관리자가 설정을 저장하는 사이에 들어온 기록이 통째로 덮어써진다(그 반대도).
	 * 기록은 별도 옵션으로 옮기고 설정 쪽 'log' 는 지운다.
	 */
	protected function migrate_log() {
		$saved = get_option( 'wsp_mod_' . $this->id(), array() );
		if ( ! is_array( $saved ) || ! array_key_exists( 'log', $saved ) ) {
			return;
		}
		$old = is_array( $saved['log'] ) ? $saved['log'] : array();
		if ( ! empty( $old ) ) {
			$now = $this->log_rows();
			update_option( self::LOG_OPTION, array_slice( array_merge( $now, $old ), 0, 100 ), false );
		}
		unset( $saved['log'] );
		update_option( 'wsp_mod_' . $this->id(), $saved );
	}

	/**
	 * 인증 파일 업로드가 Ads 매니저에도 겹쳐 있었다. 관리는 이 모듈 한 곳으로 모으고,
	 * Ads 매니저에 저장돼 있던 파일은 잃지 않게 이쪽으로 옮긴다.
	 * (이 모듈이 켜져 있을 때만 옮긴다 — 꺼진 채로 옮기면 그 파일들이 아무 데서도 서빙되지 않는다.
	 *  register() 에서만 부르므로 켜진 상태가 보장된다.)
	 */
	protected function migrate_verify_files() {
		$ads = get_option( 'wsp_mod_ads_manager', array() );
		if ( ! is_array( $ads ) || empty( $ads['verify_files'] ) || ! is_array( $ads['verify_files'] ) ) {
			return;
		}
		$s   = $this->settings();
		$mine = is_array( $s['verify_files'] ) ? $s['verify_files'] : array();
		foreach ( $ads['verify_files'] as $name => $content ) {
			if ( ! isset( $mine[ $name ] ) ) {
				$mine[ $name ] = $content; // 같은 이름이 이미 있으면 이쪽 것을 그대로 둔다.
			}
		}
		$s['verify_files'] = $mine;
		WSP_Settings::set( $this->id(), $s );

		$ads['verify_files'] = array(); // 두 모듈이 같은 파일을 서빙하지 않게 옛 자리는 비운다.
		update_option( 'wsp_mod_ads_manager', $ads );
	}

	/**
	 * 네이버 소유 확인 메타 값을 넣는다(WSP_Rest 가 부른다). 메타 태그는 이 모듈이 켜져 있을 때만 나간다.
	 *
	 * @param string $code naver-site-verification 의 content 값.
	 * @return bool 이 모듈이 켜져 있어 태그가 실제로 나가는가.
	 */
	public function put_naver_verification( $code ) {
		$s                 = $this->settings();
		$s['verify_naver'] = sanitize_text_field( (string) $code );
		WSP_Settings::set( $this->id(), $s );
		return $this->is_active();
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
			$this->send_virtual_headers( 'text/html; charset=utf-8' );
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
			$this->send_virtual_headers( 'text/plain; charset=utf-8' );
			// 텍스트 파일이라 이스케이프 없이 그대로 내보낸다(키는 영문/숫자/하이픈만 저장된다).
			echo $key; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	/* ------------------------------ 전체 URL 일괄 제출 ------------------------------ */

	/** 일괄 제출 대상 글 유형(설정에서 고른 것, 하나도 없으면 게시글). */
	protected function bulk_types() {
		$types = array_keys( array_filter( (array) $this->settings()['types'] ) );
		return empty( $types ) ? array( 'post' ) : $types;
	}

	/** 일괄 제출 진행 상태. */
	public function bulk_state() {
		$st = get_option( self::BULK_OPTION, array() );
		return is_array( $st ) ? $st : array();
	}

	/**
	 * 게시된 모든 글/페이지를 일괄 제출하도록 예약한다.
	 * 저장 요청 안에서 직접 보내면(옛 방식) 글이 많을수록 관리자 화면이 멈추고 도중에 끊긴다.
	 * 여기서는 전체 편수만 세어 두고 실제 전송은 WP-Cron 단발 예약이 100편씩 이어서 한다.
	 *
	 * @return int 예약된 전체 편수(0=예약 안 됨).
	 */
	public function schedule_bulk() {
		// 모듈이 꺼져 있으면 이어서 보낼 훅(register)이 안 걸려 예약만 남는다 → 예약하지 않는다.
		if ( ! $this->is_active() || '' === (string) $this->settings()['key'] ) {
			return 0;
		}
		$q = new WP_Query( array(
			'post_type'           => $this->bulk_types(),
			'post_status'         => 'publish',
			'posts_per_page'      => 1,
			'fields'              => 'ids',
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'ignore_sticky_posts' => true,
		) );
		$total = (int) $q->found_posts;
		if ( $total < 1 ) {
			return 0;
		}
		update_option( self::BULK_OPTION, array(
			'total'    => $total,
			'done'     => 0,
			'offset'   => 0,
			'types'    => $this->bulk_types(),
			'started'  => current_time( 'mysql' ),
			'finished' => '',
		), false );

		wp_clear_scheduled_hook( self::BULK_HOOK ); // 앞서 돌던 예약이 있으면 새로 시작.
		wp_schedule_single_event( time() + 5, self::BULK_HOOK );
		return $total;
	}

	/**
	 * 일괄 제출 한 묶음(100편) 전송 후, 남았으면 다음 묶음을 다시 예약한다.
	 * WP-Cron 이 부르는 자리라 관리자 화면과 무관하게 돈다.
	 */
	public function run_bulk_batch() {
		$st = $this->bulk_state();
		if ( empty( $st['total'] ) || (int) $st['done'] >= (int) $st['total'] ) {
			return;
		}
		$key = (string) $this->settings()['key'];
		if ( '' === $key ) {
			$st['finished'] = current_time( 'mysql' );
			update_option( self::BULK_OPTION, $st, false );
			return; // 키가 지워졌으면 더 보내지 않는다.
		}
		$types = ! empty( $st['types'] ) && is_array( $st['types'] ) ? $st['types'] : $this->bulk_types();

		$ids = get_posts( array(
			'post_type'      => $types,
			'post_status'    => 'publish',
			'posts_per_page' => self::BULK_CHUNK,
			'offset'         => (int) $st['offset'],
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );

		if ( empty( $ids ) ) { // 더 없으면 끝.
			$st['done']     = (int) $st['total'];
			$st['finished'] = current_time( 'mysql' );
			update_option( self::BULK_OPTION, $st, false );
			return;
		}

		$list = array();
		foreach ( $ids as $id ) {
			$u = get_permalink( $id );
			if ( $u ) {
				$list[] = $u;
			}
		}
		if ( ! empty( $list ) ) {
			$body = array(
				'host'        => wp_parse_url( home_url(), PHP_URL_HOST ),
				'key'         => $key,
				'keyLocation' => home_url( '/' . $key . '.txt' ),
				'urlList'     => $list,
			);
			$res  = wp_remote_post( self::ENDPOINT, array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			) );
			$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
			$this->log_submit( '[일괄] ' . count( $list ) . '개 URL', $code );
		}

		$st['offset'] = (int) $st['offset'] + count( $ids );
		$st['done']   = min( (int) $st['total'], (int) $st['done'] + count( $ids ) );
		if ( (int) $st['done'] >= (int) $st['total'] ) {
			$st['finished'] = current_time( 'mysql' );
			update_option( self::BULK_OPTION, $st, false );
			return;
		}
		update_option( self::BULK_OPTION, $st, false );
		wp_schedule_single_event( time() + 60, self::BULK_HOOK ); // 1분 뒤 다음 묶음.
	}

	public function on_transition( $new_status, $old_status, $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		$types = $this->settings()['types'];
		if ( empty( $types[ $post->post_type ] ) ) {
			return;
		}
		if ( 'publish' === $new_status ) {
			$url = get_permalink( $post );
			if ( $url ) {
				$this->queue_submit( $url, 'URL_UPDATED' );
			}
			return;
		}
		// 발행글이 비공개·임시글로 내려갔다 → 구글에 '없어졌다'고 알린다.
		// 휴지통(trash)은 주소가 바뀌기 전에 잡아야 해서 on_remove 가 맡는다.
		if ( 'publish' === $old_status && 'trash' !== $new_status ) {
			$url = get_permalink( $post );
			if ( $url ) {
				$this->queue_submit( $url, 'URL_DELETED' );
			}
		}
	}

	/**
	 * 발행글을 휴지통으로 보내거나 완전히 지울 때. 주소가 아직 원래 모양일 때 읽어 둔다.
	 *
	 * @param int $post_id
	 * @return void
	 */
	public function on_remove( $post_id ) {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) || 'publish' !== $post->post_status ) {
			return; // 이미 휴지통에 있던 글을 비우는 경우 등 — 발행 중이던 글만 알린다.
		}
		$types = $this->settings()['types'];
		if ( empty( $types[ $post->post_type ] ) ) {
			return;
		}
		$url = get_permalink( $post );
		if ( $url ) {
			$this->queue_submit( $url, 'URL_DELETED' );
		}
	}

	/**
	 * 켜져 있는 엔진으로 보내도록 예약한다(비동기 — 지금 열린 페이지를 막지 않게 1초 뒤 단발 이벤트).
	 *
	 * @param string $url
	 * @param string $type URL_UPDATED | URL_DELETED.
	 * @return void
	 */
	protected function queue_submit( $url, $type = 'URL_UPDATED' ) {
		// IndexNow 에는 '삭제' 개념이 없다 → 발행·수정만 보낸다.
		if ( 'URL_UPDATED' === $type && $this->indexnow_ready() ) {
			wp_schedule_single_event( time() + 1, 'wsp_indexnow_submit', array( $url ) );
		}
		if ( $this->google_ready() ) {
			wp_schedule_single_event( time() + 1, self::GOOGLE_HOOK, array( $url, $type ) );
		}
	}

	/** IndexNow 자동 전송이 켜져 있고 키도 있나. */
	public function indexnow_ready() {
		$s = $this->settings();
		return ! empty( $s['auto'] ) && '' !== (string) $s['key'];
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

	/* ------------------------------ 구글 인덱싱 API ------------------------------ */

	/** 이 서버에서 구글 전송에 필요한 서명을 만들 수 있나(openssl). */
	public static function openssl_available() {
		return function_exists( 'openssl_sign' );
	}

	/**
	 * 서비스 계정 키(JSON) 원문에서 client_email·private_key 를 꺼낸다.
	 * 둘 중 하나라도 없으면 쓸 수 없는 키다.
	 *
	 * @param string $raw 붙여 넣은 JSON.
	 * @return array|null array( 'client_email' => ..., 'private_key' => ... ) 또는 null.
	 */
	public static function parse_key_json( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$email = isset( $data['client_email'] ) ? trim( (string) $data['client_email'] ) : '';
		$pkey  = isset( $data['private_key'] ) ? (string) $data['private_key'] : '';
		if ( '' === $email || '' === trim( $pkey ) ) {
			return null;
		}
		return array( 'client_email' => $email, 'private_key' => $pkey );
	}

	/** 저장된 서비스 계정 키(없거나 모양이 틀리면 null). */
	public function google_key() {
		return self::parse_key_json( (string) $this->settings()['google_key_json'] );
	}

	/** 구글로 보낼 수 있나(쓸 수 있는 키 + openssl). 수동 인덱싱 단추는 이것만 본다. */
	public function google_usable() {
		return self::openssl_available() && null !== $this->google_key();
	}

	/** 구글 자동 전송이 켜져 있고 보낼 수도 있나. 발행·수정·삭제 때 저절로 보내는 길이 이것을 본다. */
	public function google_ready() {
		return ! empty( $this->settings()['google_auto'] ) && $this->google_usable();
	}

	/** JWT 가 쓰는 base64(주소에 넣을 수 있는 모양 — +/ 를 -_ 로 바꾸고 = 를 뗀다). */
	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * 구글에 보낼 JWT 의 주장(claims).
	 *
	 * @param string $client_email 서비스 계정 주소.
	 * @param int    $now          지금 시각(유닉스).
	 * @return array
	 */
	public static function jwt_claims( $client_email, $now ) {
		$now = (int) $now;
		return array(
			'iss'   => (string) $client_email,
			'scope' => self::GOOGLE_SCOPE,
			'aud'   => self::GOOGLE_TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		);
	}

	/**
	 * 서명 전 JWT(머리말.주장). 이 문자열에 서명한다.
	 *
	 * @param string $client_email
	 * @param int    $now
	 * @return string
	 */
	public static function jwt_unsigned( $client_email, $now ) {
		$header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
		return self::b64url( wp_json_encode( $header ) ) . '.' . self::b64url( wp_json_encode( self::jwt_claims( $client_email, $now ) ) );
	}

	/**
	 * 서명 전 JWT 에 서명해 완성한다.
	 *
	 * @param string $unsigned    jwt_unsigned() 결과.
	 * @param string $private_key 서비스 계정의 private_key(PEM).
	 * @return string|false 완성된 JWT, 서명 못 하면 false.
	 */
	public static function jwt_sign( $unsigned, $private_key ) {
		if ( ! self::openssl_available() ) {
			return false;
		}
		$sig = '';
		$ok  = @openssl_sign( $unsigned, $sig, $private_key, OPENSSL_ALGO_SHA256 ); // phpcs:ignore
		if ( ! $ok || '' === $sig ) {
			return false;
		}
		return $unsigned . '.' . self::b64url( $sig );
	}

	/**
	 * 구글 액세스 토큰(55분 보관). 못 받으면 빈 문자열.
	 *
	 * @return string
	 */
	public function google_access_token() {
		$cached = get_transient( self::GOOGLE_TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$key = $this->google_key();
		if ( ! $key ) {
			return '';
		}
		$jwt = self::jwt_sign( self::jwt_unsigned( $key['client_email'], time() ), $key['private_key'] );
		if ( ! $jwt ) {
			$this->log_submit( '토큰 요청', 0, '구글', '서비스 계정 키로 서명하지 못했습니다(private_key 확인).' );
			return '';
		}
		$res = wp_remote_post( self::GOOGLE_TOKEN_URL, array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $jwt,
			),
		) );
		if ( is_wp_error( $res ) ) {
			$this->log_submit( '토큰 요청', 0, '구글', $res->get_error_message() );
			return '';
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$tok  = is_array( $body ) && ! empty( $body['access_token'] ) ? (string) $body['access_token'] : '';
		if ( '' === $tok ) {
			$this->log_submit( '토큰 요청', $code, '구글', $this->google_error_message( $body ) );
			return '';
		}
		set_transient( self::GOOGLE_TOKEN_TRANSIENT, $tok, 55 * MINUTE_IN_SECONDS );
		return $tok;
	}

	/** 구글 응답에서 사람이 읽을 오류 메시지를 꺼낸다. */
	protected function google_error_message( $body ) {
		if ( is_array( $body ) ) {
			if ( ! empty( $body['error']['message'] ) ) {
				return (string) $body['error']['message'];
			}
			if ( ! empty( $body['error_description'] ) ) {
				return (string) $body['error_description'];
			}
			if ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
				return (string) $body['error'];
			}
		}
		return '';
	}

	/* --------------------------- 하루 200건 한도 --------------------------- */

	/**
	 * 저장된 기록에서 '오늘' 보낸 수를 읽는다(날짜가 다르면 0 — 날마다 새로 센다).
	 *
	 * @param array  $saved 옵션에 담긴 array( 'date' => 'Y-m-d', 'count' => n ).
	 * @param string $today 오늘 날짜(Y-m-d).
	 * @return int
	 */
	public static function quota_today( $saved, $today ) {
		if ( ! is_array( $saved ) || empty( $saved['date'] ) || (string) $saved['date'] !== (string) $today ) {
			return 0;
		}
		return max( 0, (int) $saved['count'] );
	}

	/**
	 * 오늘 더 보낼 수 있는 수.
	 *
	 * @param array  $saved 옵션에 담긴 기록.
	 * @param string $today 오늘 날짜(Y-m-d).
	 * @param int    $limit 하루 한도.
	 * @return int
	 */
	public static function quota_room( $saved, $today, $limit = self::GOOGLE_DAILY_LIMIT ) {
		return max( 0, (int) $limit - self::quota_today( $saved, $today ) );
	}

	/** 오늘 구글로 보낸 수. */
	public function google_sent_today() {
		return self::quota_today( get_option( self::GOOGLE_QUOTA_OPTION, array() ), current_time( 'Y-m-d' ) );
	}

	/** 오늘 구글로 더 보낼 수 있는 수. */
	public function google_room() {
		return self::quota_room( get_option( self::GOOGLE_QUOTA_OPTION, array() ), current_time( 'Y-m-d' ), self::GOOGLE_DAILY_LIMIT );
	}

	/** 보낸 수 1 늘리기(구글은 보낸 요청 수로 센다 — 실패한 요청도 한도를 쓴다). */
	protected function bump_google_quota() {
		$today = current_time( 'Y-m-d' );
		$count = self::quota_today( get_option( self::GOOGLE_QUOTA_OPTION, array() ), $today ) + 1;
		update_option( self::GOOGLE_QUOTA_OPTION, array( 'date' => $today, 'count' => $count ), false );
	}

	/**
	 * 구글 인덱싱 API 로 URL 제출.
	 *
	 * @param string $url
	 * @param string $type URL_UPDATED(발행·수정) | URL_DELETED(휴지통·비공개·삭제).
	 * @return int HTTP 응답코드(0=보내지 못함).
	 */
	public function submit_google( $url, $type = 'URL_UPDATED' ) {
		$url  = (string) $url;
		$type = 'URL_DELETED' === $type ? 'URL_DELETED' : 'URL_UPDATED';
		if ( '' === $url || ! $this->google_usable() ) {
			return 0;
		}
		if ( $this->google_room() < 1 ) {
			$this->log_submit( $url, 0, '구글', '한도 초과 — 내일' );
			return 0;
		}
		$token = $this->google_access_token();
		if ( '' === $token ) {
			return 0; // 까닭은 google_access_token() 이 이미 기록했다.
		}
		$res = wp_remote_post( self::GOOGLE_ENDPOINT, array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => wp_json_encode( array( 'url' => $url, 'type' => $type ) ),
		) );
		$this->bump_google_quota();

		if ( is_wp_error( $res ) ) {
			$this->log_submit( $url, 0, '구글', $res->get_error_message() );
			return 0;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$msg  = $code >= 200 && $code < 300 ? '' : $this->google_error_message( $body );
		if ( 401 === $code || 403 === $code ) {
			delete_transient( self::GOOGLE_TOKEN_TRANSIENT ); // 토큰이 막혔으면 다음에 새로 받는다.
		}
		if ( 'URL_DELETED' === $type ) {
			$msg = '' === $msg ? '삭제 알림' : '삭제 알림 — ' . $msg;
		}
		$this->log_submit( $url, $code, '구글', $msg );
		return $code;
	}

	/** 제출 기록(최신순). 설정과 별도 옵션이라 관리자 저장과 서로 덮어쓰지 않는다. */
	public function log_rows() {
		$rows = get_option( self::LOG_OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * 제출 한 건 기록.
	 *
	 * @param string $url    보낸 주소(또는 '[일괄] …' 같은 설명).
	 * @param int    $code   HTTP 응답코드(0=보내지 못함).
	 * @param string $engine 'IndexNow' 또는 '구글'.
	 * @param string $msg    오류 메시지 등 남길 말(없으면 빈 문자열).
	 * @return void
	 */
	protected function log_submit( $url, $code, $engine = 'IndexNow', $msg = '' ) {
		$log = $this->log_rows();
		array_unshift( $log, array(
			'url'    => $url,
			'code'   => $code,
			'engine' => $engine,
			'msg'    => (string) $msg,
			'time'   => current_time( 'mysql' ),
		) );
		update_option( self::LOG_OPTION, array_slice( $log, 0, 100 ), false ); // 100건까지, autoload 안 함.
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
			'google_auto'     => empty( $input['google_auto'] ) ? 0 : 1,
			'google_key_json' => (string) $s['google_key_json'], // 기본은 저장돼 있던 키를 그대로 둔다.
		);

		// 구글 서비스 계정 키 — 「키 지우기」로만 지우고, 빈 칸으로 저장하면 있던 키를 그대로 쓴다.
		if ( ! empty( $input['google_clear'] ) ) {
			$out['google_key_json'] = '';
			delete_transient( self::GOOGLE_TOKEN_TRANSIENT );
		} elseif ( isset( $input['google_key_json'] ) && '' !== trim( (string) $input['google_key_json'] ) ) {
			$raw    = trim( (string) $input['google_key_json'] );
			$parsed = self::parse_key_json( $raw );
			if ( $parsed ) {
				$out['google_key_json'] = $raw;
				delete_transient( self::GOOGLE_TOKEN_TRANSIENT ); // 키가 바뀌었으니 토큰도 새로 받는다.
			} else {
				// 저장하지 않고 까닭을 화면에 보인다(있던 키는 지우지 않는다).
				set_transient( self::GOOGLE_ERROR_TRANSIENT, '구글 서비스 계정 키를 저장하지 못했습니다 — JSON 안에 client_email 과 private_key 가 둘 다 있어야 합니다. 구글 클라우드 콘솔에서 내려받은 키 파일 내용을 통째로 붙여 넣으세요.', 60 );
			}
		}

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
				$this->submit_now( $manual );
				return WSP_Settings::get( $this->id(), $this->default_settings() );
			}
		}
		// 전체 URL 일괄 제출 요청 — 여기서 보내지 않고 예약만 한다(저장이 오래 걸리지 않게).
		if ( ! empty( $input['bulk_submit'] ) ) {
			WSP_Settings::set( $this->id(), $out );
			$this->schedule_bulk();
			return WSP_Settings::get( $this->id(), $this->default_settings() );
		}
		return $out;
	}

	/* ------------------------------ 수동 인덱싱(글 목록) ------------------------------ */

	/**
	 * 지금 바로(예약 없이) 켜져 있는 엔진 모두에 보낸다. 수동 인덱싱 단추·URL 직접 제출이 쓴다.
	 *
	 * @param string $url
	 * @return array 엔진 이름 => HTTP 응답코드.
	 */
	protected function submit_now( $url ) {
		$codes = array();
		if ( '' !== (string) $this->settings()['key'] ) {
			$codes['IndexNow'] = $this->submit_url( $url );
		}
		// 한도를 다 쓴 날에는 구글을 부르지 않는다(부르면 기록만 '한도 초과'로 쌓인다).
		if ( $this->google_usable() && $this->google_room() > 0 ) {
			$codes['구글'] = $this->submit_google( $url, 'URL_UPDATED' );
		}
		return $codes;
	}

	/** 글 하나 인덱싱 + 상태 저장. 반환: 엔진별 HTTP 응답코드. */
	protected function index_post( $id ) {
		$url = get_permalink( $id );
		if ( ! $url ) {
			return array();
		}
		$codes = $this->submit_now( $url );
		$first = empty( $codes ) ? 0 : (int) reset( $codes );
		update_post_meta( $id, '_wsp_indexnow', array(
			'code'    => $first,  // 옛 버전과 같은 자리(하나만 보던 시절 값).
			'engines' => array_map( 'intval', $codes ),
			'time'    => current_time( 'mysql' ),
		) );
		return $codes;
	}

	/** 글의 인덱싱 상태 배지 HTML(엔진마다 하나씩). */
	protected function status_cell( $id ) {
		$m = get_post_meta( $id, '_wsp_indexnow', true );
		if ( ! is_array( $m ) || empty( $m['time'] ) ) {
			return '<span class="wsp-mi-badge none">미요청</span>';
		}
		// 옛 기록에는 엔진 칸이 없다 — 그때는 IndexNow 하나만 보냈다.
		$engines = ! empty( $m['engines'] ) && is_array( $m['engines'] )
			? $m['engines']
			: array( 'IndexNow' => isset( $m['code'] ) ? (int) $m['code'] : 0 );
		$one = 1 === count( $engines );
		$out = '';
		foreach ( $engines as $name => $code ) {
			$code = (int) $code;
			$ok   = $code >= 200 && $code < 300;
			$out .= '<span class="wsp-mi-badge ' . ( $ok ? 'ok' : 'no' ) . '" title="' . esc_attr( $name . ' · ' . $m['time'] ) . '">'
				. ( $one ? '' : esc_html( $name ) . ' ' )
				. ( $ok ? '요청됨' : '실패(' . $code . ')' ) . '</span> ';
		}
		return trim( $out );
	}

	/** 발행글 목록 + 페이지네이션 HTML(수동 인덱싱 표). 예약(future)글은 제외(publish 만). */
	public function manual_list_html( $search, $paged ) {
		$types = array_keys( array_filter( (array) $this->settings()['types'] ) );
		if ( empty( $types ) ) {
			$types = array( 'post' );
		}
		$q = new WP_Query( array(
			'post_type'           => $types,
			'post_status'         => 'publish', // 발행된 글만(예약글 제외).
			'posts_per_page'      => 100,
			'paged'               => max( 1, (int) $paged ),
			's'                   => (string) $search,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'ignore_sticky_posts' => true,
		) );

		ob_start();
		if ( empty( $q->posts ) ) {
			echo '<p>표시할 발행글이 없습니다.</p>';
			return ob_get_clean();
		}
		?>
		<table class="widefat striped wsp-mi-table">
			<thead><tr>
				<td class="check-column"><input type="checkbox" class="wsp-mi-all"></td>
				<th>제목</th><th style="width:70px">유형</th><th style="width:150px">작성일</th><th style="width:90px">인덱싱 상태</th><th style="width:80px">동작</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $q->posts as $po ) :
				$id    = $po->ID;
				$url   = get_permalink( $id );
				$pt    = get_post_type_object( $po->post_type );
				$tlabel = $pt ? $pt->labels->singular_name : $po->post_type;
				?>
				<tr>
					<th class="check-column"><input type="checkbox" class="wsp-mi-cb" value="<?php echo (int) $id; ?>"></th>
					<td>
						<strong><?php echo esc_html( get_the_title( $id ) ); ?></strong><br>
						<a href="<?php echo esc_url( $url ); ?>" target="_blank" style="font-size:11px;color:#2271b1;word-break:break-all"><?php echo esc_html( urldecode( $url ) ); ?></a>
					</td>
					<td><?php echo esc_html( $tlabel ); ?></td>
					<td><?php echo esc_html( mysql2date( 'Y-m-d H:i:s', $po->post_date ) ); ?></td>
					<td class="wsp-mi-status" data-id="<?php echo (int) $id; ?>"><?php echo $this->status_cell( $id ); // phpcs:ignore ?></td>
					<td><button type="button" class="button button-primary wsp-mi-btn" data-id="<?php echo (int) $id; ?>">인덱싱</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$total = (int) $q->max_num_pages;
		$cur   = max( 1, (int) $paged );
		if ( $total > 1 ) {
			echo '<div class="wsp-mi-pager">';
			if ( $cur > 1 ) {
				echo '<a class="button wsp-mi-page" data-p="' . ( $cur - 1 ) . '">‹ 이전</a> ';
			}
			echo '<span style="margin:0 10px">' . $cur . ' / ' . $total . ' 페이지</span>';
			if ( $cur < $total ) {
				echo ' <a class="button wsp-mi-page" data-p="' . ( $cur + 1 ) . '">다음 ›</a>';
			}
			echo '</div>';
		}
		return ob_get_clean();
	}

	protected function ajax_guard() {
		if ( ! check_ajax_referer( 'wsp_ajax', 'nonce', false ) || ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '권한이 없습니다.' ), 403 );
		}
	}

	public function ajax_list() {
		$this->ajax_guard();
		$s = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		$p = isset( $_POST['p'] ) ? max( 1, (int) $_POST['p'] ) : 1;
		wp_send_json_success( array( 'html' => $this->manual_list_html( $s, $p ) ) );
	}

	public function ajax_index_post() {
		$this->ajax_guard();
		$id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $id || 'publish' !== get_post_status( $id ) ) {
			wp_send_json_error( array( 'message' => '발행된 글이 아닙니다.' ) );
		}
		if ( ! $this->any_engine_usable() ) {
			wp_send_json_error( array( 'message' => $this->no_engine_message() ) );
		}
		$this->index_post( $id );
		wp_send_json_success( array( 'status' => $this->status_cell( $id ) ) );
	}

	public function ajax_index_bulk() {
		$this->ajax_guard();
		if ( ! $this->any_engine_usable() ) {
			wp_send_json_error( array( 'message' => $this->no_engine_message() ) );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$ids = array_slice( array_filter( $ids ), 0, 100 );
		$res = array();
		foreach ( $ids as $id ) {
			if ( 'publish' !== get_post_status( $id ) ) {
				continue;
			}
			// 구글 한도가 남은 데까지만 구글로 가고(submit_now 가 그날 남은 수를 본다),
			// 한도를 넘긴 나머지 글은 IndexNow 로만 나간다.
			$this->index_post( $id );
			$res[ $id ] = $this->status_cell( $id );
		}
		wp_send_json_success( array( 'results' => $res ) );
	}

	/** 지금 보낼 수 있는 엔진이 하나라도 있나. */
	protected function any_engine_usable() {
		return '' !== (string) $this->settings()['key'] || $this->google_usable();
	}

	/** 보낼 엔진이 하나도 없을 때 화면에 보일 말. */
	protected function no_engine_message() {
		if ( ! self::openssl_available() ) {
			return '먼저 IndexNow 키를 저장하세요. (이 서버에는 openssl 이 없어 구글 전송은 쓸 수 없습니다.)';
		}
		return '먼저 IndexNow 키 또는 구글 서비스 계정 키(JSON)를 저장하세요.';
	}

	public function render_settings() {
		$this->migrate_log(); // 모듈이 꺼져 있어도 옛 기록을 로그 탭에서 볼 수 있게.
		$s = $this->settings();
		// AJAX 훅(수동 인덱싱)은 모듈이 켜져 있을 때만 걸린다 → 꺼져 있으면 단추를 못 쓰게 한다.
		$active = $this->is_active();
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

			<?php
			$g_key     = $this->google_key();
			$g_openssl = self::openssl_available();
			$g_sent    = $this->google_sent_today();
			$g_err     = get_transient( self::GOOGLE_ERROR_TRANSIENT );
			if ( $g_err ) {
				delete_transient( self::GOOGLE_ERROR_TRANSIENT ); // 한 번만 보인다.
			}
			?>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>구글 인덱싱 API 자동 전송</strong>
					<span class="wsp-row-help">글 발행/수정 시 구글에 색인 요청, 휴지통·비공개·삭제 시 삭제 요청.</span></div>
				<div class="wsp-row-control">
					<label><input type="checkbox" name="google_auto" value="1" <?php checked( $s['google_auto'], 1 ); ?>> 활성화</label>
					<?php if ( ! $g_openssl ) : ?>
						<p class="wsp-check-no">서버에 openssl 이 없어 구글 전송을 못 합니다.</p>
					<?php elseif ( ! $g_key ) : ?>
						<p class="wsp-row-help">아래에 <strong>구글 서비스 계정 키(JSON)</strong>를 먼저 붙여 넣으세요.</p>
					<?php endif; ?>
					<p class="wsp-row-help">오늘 보낸 수 <strong><?php echo (int) $g_sent; ?>/<?php echo (int) self::GOOGLE_DAILY_LIMIT; ?></strong> (구글 하루 한도. 넘으면 다음 날 보냅니다.)</p>
				</div>
			</div>
			<div class="wsp-row">
				<div class="wsp-row-label"><strong>구글 서비스 계정 키(JSON)</strong>
					<span class="wsp-row-help">구글 클라우드 콘솔에서 내려받은 키 파일 내용을 통째로 붙여 넣으세요.</span></div>
				<div class="wsp-row-control">
					<?php if ( $g_err ) : ?>
						<p class="wsp-check-no"><?php echo esc_html( $g_err ); ?></p>
					<?php endif; ?>
					<?php if ( $g_key ) : ?>
						<p><span class="wsp-check-ok">키 저장됨</span> · <code class="wsp-code"><?php echo esc_html( $g_key['client_email'] ); ?></code></p>
					<?php elseif ( '' !== (string) $s['google_key_json'] ) : ?>
						<p class="wsp-check-no">저장된 키를 읽지 못했습니다 — 다시 붙여 넣으세요.</p>
					<?php endif; ?>
					<textarea name="google_key_json" rows="5" style="width:100%;font-family:monospace" placeholder='{"type":"service_account","client_email":"...","private_key":"-----BEGIN PRIVATE KEY-----\n..."}'></textarea>
					<p class="wsp-row-help">안전을 위해 저장된 키는 다시 보여 주지 않습니다. <strong>비워 두고 저장하면 저장된 키를 그대로 씁니다.</strong></p>
					<?php if ( $g_key ) : ?>
						<label><input type="checkbox" name="google_clear" value="1"> 키 지우기</label>
					<?php endif; ?>
				</div>
			</div>
			<div class="wsp-note">구글 인덱싱 API 는 구글이 공식적으로는 채용공고·생방송 페이지용으로 열어 둔 것이라, 일반 글은 반영이 늦거나 안 될 수 있습니다.</div>

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
								<td style="text-align:right"><button type="submit" name="remove_verify" value="<?php echo esc_attr( $fname ); ?>" class="button-link-delete"
										onclick="return confirm('<?php echo esc_js( $fname ); ?> 인증 파일을 삭제할까요? 검색엔진 소유 확인이 풀릴 수 있습니다.');">삭제</button></td>
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
					<label><input type="checkbox" name="bulk_submit" value="1" <?php disabled( ! $active ); ?>> 저장 시 전체 제출 예약</label>
					<p class="wsp-row-help">저장할 때 바로 다 보내지 않고 <strong>예약</strong>합니다. 이후 워드프레스가 <?php echo (int) self::BULK_CHUNK; ?>편씩 이어서 보냅니다(편수 제한 없음).
						이 일괄 제출은 <strong>IndexNow 로만</strong> 보냅니다(구글은 하루 <?php echo (int) self::GOOGLE_DAILY_LIMIT; ?>건 한도라 수동 인덱싱 탭에서 골라 보내세요).</p>
					<?php if ( ! $active ) : ?>
						<p class="wsp-check-no">모듈을 켜야 예약할 수 있습니다.</p>
					<?php elseif ( '' === $s['key'] ) : ?>
						<p class="wsp-check-no">먼저 IndexNow 키를 입력·저장해야 합니다.</p>
					<?php endif; ?>
					<?php
					$bulk = $this->bulk_state();
					if ( ! empty( $bulk['total'] ) ) :
						$b_total = (int) $bulk['total'];
						$b_done  = (int) $bulk['done'];
						?>
						<div class="wsp-note">
							<strong><?php echo (int) $b_total; ?>편 예약됨</strong> · 진행 <?php echo (int) $b_done; ?>/<?php echo (int) $b_total; ?>
							<?php if ( ! empty( $bulk['finished'] ) ) : ?>
								— <span class="wsp-check-ok">완료</span> (<?php echo esc_html( $bulk['finished'] ); ?>)
							<?php else : ?>
								— 전송 중입니다. 이 화면을 닫아도 계속 보냅니다(1분에 <?php echo (int) self::BULK_CHUNK; ?>편).
							<?php endif; ?>
							<?php if ( ! empty( $bulk['started'] ) ) : ?>
								<br><span class="wsp-row-help">시작: <?php echo esc_html( $bulk['started'] ); ?></span>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="wsp-note">IndexNow는 빙·네이버·Yandex 등 참여 엔진이 신호를 공유합니다(구글은 미참여). 요청을 보낼 뿐, 인덱싱 결과를 보장하지 않습니다.</div>
		</div>

		<div class="wsp-tabpane" data-pane="log">
			<?php $log_rows = $this->log_rows(); ?>
			<?php if ( empty( $log_rows ) ) : ?>
				<p>아직 제출 기록이 없습니다.</p>
			<?php else : ?>
				<table class="widefat striped"><thead><tr><th style="width:90px">엔진</th><th>URL</th><th style="width:70px">응답</th><th style="width:160px">시각</th></tr></thead><tbody>
				<?php foreach ( $log_rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( empty( $row['engine'] ) ? 'IndexNow' : $row['engine'] ); ?></td>
						<td><?php echo esc_html( $row['url'] ); ?>
							<?php if ( ! empty( $row['msg'] ) ) : ?>
								<br><span class="wsp-row-help"><?php echo esc_html( $row['msg'] ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo (int) $row['code'] === 200 ? '<span class="wsp-check-ok">200</span>' : esc_html( $row['code'] ); ?></td>
						<td><?php echo esc_html( $row['time'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>

		<div class="wsp-tabpane" data-pane="man">
			<p class="wsp-sub">게시글을 선택해 인덱싱 요청을 보낼 수 있습니다. 상태는 가장 최근 요청 결과 기준입니다. <strong>발행된 글만</strong> 표시됩니다(예약글 제외).<br>
				키가 저장된 엔진에 <strong>모두</strong> 보냅니다
				(<?php echo '' !== (string) $s['key'] ? 'IndexNow' : '<span class="wsp-check-no">IndexNow 키 없음</span>'; // phpcs:ignore ?>
				· <?php echo $this->google_usable() ? '구글 — 오늘 ' . (int) $this->google_room() . '건 더 보낼 수 있음' : '<span class="wsp-check-no">구글 키 없음</span>'; // phpcs:ignore ?>).
				구글 하루 한도를 넘긴 글은 IndexNow 로만 나갑니다.</p>

			<div class="wsp-row">
				<div class="wsp-row-label"><strong>URL 직접 제출</strong>
					<span class="wsp-row-help">목록에 없는 주소를 직접 제출. 저장 시 즉시 전송됩니다.</span></div>
				<div class="wsp-row-control">
					<input type="url" name="manual_url" placeholder="https://<?php echo esc_attr( wp_parse_url( home_url(), PHP_URL_HOST ) ); ?>/...">
				</div>
			</div>

			<hr style="margin:18px 0">

			<?php if ( ! $active ) : ?>
				<div class="wsp-note">⚠️ <strong>모듈을 켜야 동작합니다.</strong> 위쪽 <strong>플러그인 기능 활성화</strong>를 켠 뒤에 이 목록에서 인덱싱을 요청할 수 있습니다.</div>
				<div class="wsp-mi-toolbar">
					<input type="text" placeholder="게시글 제목 검색" style="width:260px" disabled>
					<button type="button" class="button" disabled>검색</button>
					<button type="button" class="button button-primary" disabled>선택된 게시글 인덱싱</button>
				</div>
			<?php else : ?>
				<div class="wsp-mi-toolbar">
					<input type="text" id="wsp-mi-search" placeholder="게시글 제목 검색" style="width:260px">
					<button type="button" class="button" id="wsp-mi-search-btn">검색</button>
					<button type="button" class="button button-primary" id="wsp-mi-bulk">선택된 게시글 인덱싱</button>
				</div>
				<div id="wsp-mi-list"><?php echo $this->manual_list_html( '', 1 ); // phpcs:ignore ?></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
