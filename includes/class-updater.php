<?php
/**
 * GitHub Releases 기반 자체 업데이트. (wp-visitor-stats 의 class-updater.php 를 복사·개조)
 *  - 외부 라이브러리 없이 WP 기본 업데이트 시스템(플러그인 목록의 "업데이트")에 연결.
 *  - WP 가 update 체크 시 GitHub 최신 릴리스 태그와 현재 버전을 비교.
 *  - 새 버전이면 목록에 "지금 업데이트" 표시 + 클릭 시 릴리스 zip 설치(데이터 보존).
 *
 * 사용: WSP_UPDATE_REPO 상수에 'owner/repo' 지정(비공개면 WSP_UPDATE_TOKEN 도).
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Updater {

	/** GitHub 'owner/repo'. */
	protected static $repo = '';

	/** 'wp-site-pack/wp-site-pack.php'. */
	protected static $basename = '';

	/** 비공개 저장소 접근용 토큰(있으면). */
	protected static $token = '';

	/** API 응답 캐시 시간(초). 무인증 GitHub API 한도 보호. */
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init( $repo, $basename, $token = '' ) {
		self::$repo     = trim( (string) $repo );
		self::$basename = $basename;
		self::$token    = trim( (string) $token );

		if ( '' === self::$repo || false === strpos( self::$repo, '/' ) ) {
			return; // 저장소 미설정이면 비활성(안전).
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_source_dir' ), 10, 4 );
		if ( '' !== self::$token ) {
			add_filter( 'upgrader_pre_download', array( __CLASS__, 'pre_download' ), 10, 3 );
		}
	}

	protected static function api_headers() {
		$h = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'wp-site-pack-updater',
		);
		if ( '' !== self::$token ) {
			$h['Authorization'] = 'Bearer ' . self::$token;
		}
		return $h;
	}

	/**
	 * GitHub 최신 릴리스 조회(캐시). 실패 시 false.
	 *
	 * @return object|false
	 */
	protected static function fetch_release() {
		$key    = 'wsp_upd_' . md5( self::$repo );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return is_object( $cached ) ? $cached : false;
		}

		$url      = 'https://api.github.com/repos/' . self::$repo . '/releases/latest';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => self::api_headers(),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! is_object( $data ) || empty( $data->tag_name ) ) {
			set_transient( $key, 'none', HOUR_IN_SECONDS );
			return false;
		}

		set_transient( $key, $data, self::CACHE_TTL );
		return $data;
	}

	protected static function tag_to_version( $tag ) {
		return ltrim( (string) $tag, 'vV' );
	}

	/**
	 * 캐시된 최신 릴리스 버전(추가 네트워크 호출 없음). 모르면 ''.
	 *
	 * @return string
	 */
	public static function cached_latest_version() {
		if ( '' === self::$repo ) {
			return '';
		}
		$cached = get_transient( 'wsp_upd_' . md5( self::$repo ) );
		if ( is_object( $cached ) && ! empty( $cached->tag_name ) ) {
			return self::tag_to_version( $cached->tag_name );
		}
		return '';
	}

	protected static function package_url( $rel ) {
		if ( ! empty( $rel->assets ) && is_array( $rel->assets ) ) {
			foreach ( $rel->assets as $asset ) {
				if ( ! empty( $asset->browser_download_url ) && preg_match( '/\.zip$/i', $asset->browser_download_url ) ) {
					if ( '' !== self::$token && ! empty( $asset->url ) ) {
						return $asset->url;
					}
					return $asset->browser_download_url;
				}
			}
		}
		return ! empty( $rel->zipball_url ) ? $rel->zipball_url : '';
	}

	/**
	 * 비공개 저장소 자산 다운로드. API 자산 URL → 302(S3) → 본문 저장.
	 *
	 * @return mixed 로컬 임시파일 경로 | WP_Error | 원래 $reply.
	 */
	public static function pre_download( $reply, $package, $upgrader ) {
		if ( '' === self::$token || ! is_string( $package ) ) {
			return $reply;
		}
		if ( false === strpos( $package, 'api.github.com/repos/' . self::$repo . '/releases/assets/' ) ) {
			return $reply;
		}

		$res = wp_remote_get(
			$package,
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . self::$token,
					'Accept'        => 'application/octet-stream',
					'User-Agent'    => 'wp-site-pack-updater',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = '';
		if ( 302 === $code || 301 === $code ) {
			$loc = wp_remote_retrieve_header( $res, 'location' );
			if ( ! $loc ) {
				return new WP_Error( 'wsp_dl', '자산 리다이렉트 URL을 찾지 못했습니다.' );
			}
			$res2 = wp_remote_get( $loc, array( 'timeout' => 60 ) );
			if ( is_wp_error( $res2 ) ) {
				return $res2;
			}
			$body = wp_remote_retrieve_body( $res2 );
		} elseif ( 200 === $code ) {
			$body = wp_remote_retrieve_body( $res );
		} else {
			return new WP_Error( 'wsp_dl', '자산 다운로드 실패(HTTP ' . $code . ').' );
		}

		if ( '' === $body ) {
			return new WP_Error( 'wsp_dl', '자산 본문이 비어 있습니다.' );
		}
		if ( 'PK' !== substr( $body, 0, 2 ) ) {
			return new WP_Error( 'wsp_dl', '다운로드한 파일이 올바른 zip이 아닙니다.' );
		}

		$dir    = '';
		$upload = wp_upload_dir( null, false );
		if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) && wp_is_writable( $upload['basedir'] ) ) {
			$dir = trailingslashit( $upload['basedir'] );
		}
		$tmp = wp_tempnam( 'wsp-update.zip', $dir );
		if ( ! $tmp ) {
			return new WP_Error( 'wsp_dl', '임시 파일 생성 실패.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = @file_put_contents( $tmp, $body );
		if ( false === $written || strlen( $body ) !== (int) $written || ! file_exists( $tmp ) || filesize( $tmp ) < 100 ) {
			global $wp_filesystem;
			if ( ! $wp_filesystem ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
				WP_Filesystem();
			}
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			if ( ! $wp_filesystem || ! $wp_filesystem->put_contents( $tmp, $body, $chmod ) ) {
				return new WP_Error( 'wsp_dl', '다운로드 파일 저장 실패(임시폴더 권한/호스팅 제약).' );
			}
		}
		return $tmp;
	}

	public static function check_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$rel = self::fetch_release();
		if ( ! $rel ) {
			return $transient;
		}

		$new_version = self::tag_to_version( $rel->tag_name );
		$slug        = dirname( self::$basename );
		$url         = isset( $rel->html_url ) ? $rel->html_url : '';

		if ( '' === $new_version || version_compare( $new_version, WSP_VERSION, '<=' ) ) {
			$transient->no_update[ self::$basename ] = (object) array(
				'id'          => self::$basename,
				'slug'        => $slug,
				'plugin'      => self::$basename,
				'new_version' => WSP_VERSION,
				'url'         => $url,
				'package'     => '',
			);
			return $transient;
		}

		$package = self::package_url( $rel );
		if ( '' === $package ) {
			return $transient;
		}

		$transient->response[ self::$basename ] = (object) array(
			'id'          => self::$basename,
			'slug'        => $slug,
			'plugin'      => self::$basename,
			'new_version' => $new_version,
			'url'         => $url,
			'package'     => $package,
		);
		if ( isset( $transient->checked ) ) {
			$transient->checked[ self::$basename ] = WSP_VERSION;
		}
		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}
		if ( dirname( self::$basename ) !== $args->slug ) {
			return $result;
		}

		$rel = self::fetch_release();
		if ( ! $rel ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WP Site Pack',
			'slug'          => $args->slug,
			'version'       => self::tag_to_version( $rel->tag_name ),
			'homepage'      => isset( $rel->html_url ) ? $rel->html_url : '',
			'download_link' => self::package_url( $rel ),
			'sections'      => array(
				'changelog' => isset( $rel->body ) ? nl2br( esc_html( $rel->body ) ) : '',
			),
		);
	}

	public static function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( empty( $hook_extra['plugin'] ) || self::$basename !== $hook_extra['plugin'] ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$slug    = dirname( self::$basename );
		$desired = trailingslashit( $remote_source ) . $slug;

		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}
		if ( untrailingslashit( $source ) === untrailingslashit( $remote_source ) ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $desired ), true ) ) {
			return trailingslashit( $desired );
		}
		return $source;
	}
}
