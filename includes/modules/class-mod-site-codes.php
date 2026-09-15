<?php
/**
 * 모듈: 소유 확인·분석 코드.
 *  - 소유 확인: 구글·네이버·빙 인증 메타 태그 + 인증 HTML 파일(업로드한 것은 가상 서빙, 웹 루트에 있는 것은 목록만).
 *  - 분석·광고 코드: GA4 · 애드센스(자동 광고 로더) · 네이버 애널리틱스 · 마이크로소프트 클래리티.
 *  - 값만 넣으면 표준 코드로 만들어 내보낸다(원시 코드를 붙여 넣어도 값만 뽑아 쓴다).
 *  - 칸마다 「지금 첫 화면에 몇 번 나가는지」와 「다른 곳(GeneratePress 엘리먼츠·자식 테마·다른 플러그인)에서도 나가는지」를 보여 준다.
 *    두 곳에서 나가면 GA4 는 방문이 두 번 잡힌다 — 사이트팩으로 옮겼으면 다른 곳은 꺼야 한다.
 *
 * 예전에는 인증 칸이 「자동 인덱싱」 안에 있었다(전송과 무관한데 섞여 있었고, 그 모듈을 켜야만 태그가 나갔다).
 * 처음 불릴 때 한 번 이쪽으로 옮기고 이 모듈을 켠다 — maybe_migrate().
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Site_Codes extends WSP_Module {

	/** 옛 자리(자동 인덱싱·Ads 매니저)에서 옮기기를 마쳤는지. */
	const MIGRATED_OPTION = 'wsp_site_codes_migrated';

	public function id()   { return 'site_codes'; }
	public function name() { return '소유 확인·분석 코드'; }
	public function desc() { return '검색엔진 소유 확인 태그·인증 파일과 GA4·애드센스·네이버 애널리틱스·클래리티 코드를 한 곳에서 넣고, 사이트에 제대로 나가는지 봅니다.'; }
	public function icon() { return 'dashicons-shield-alt'; }

	public function default_settings() {
		return array(
			'verify_google' => '',
			'verify_naver'  => '',
			'verify_bing'   => '',
			'verify_files'  => array(), // 파일 이름 => 내용(업로드한 인증 파일 — 가상 서빙)
			'ga4'           => '',      // G-XXXXXXXX
			'adsense'       => '',      // ca-pub-0000000000000000
			'naver_wa'      => '',      // 네이버 애널리틱스 wcs_add["wa"] 값
			'clarity'       => '',      // 클래리티 프로젝트 ID
		);
	}

	/** 칸 이름 => [화면 이름, 종류(소유 확인·분석 코드)]. 화면·찾기·검산이 같은 목록을 쓴다. */
	public static function fields() {
		return array(
			'verify_google' => array( '구글 서치콘솔', 'verify' ),
			'verify_naver'  => array( '네이버 서치어드바이저', 'verify' ),
			'verify_bing'   => array( '빙 웹마스터', 'verify' ),
			'ga4'           => array( 'GA4 (구글 애널리틱스)', 'code' ),
			'adsense'       => array( '애드센스', 'code' ),
			'naver_wa'      => array( '네이버 애널리틱스', 'code' ),
			'clarity'       => array( '마이크로소프트 클래리티', 'code' ),
		);
	}

	/* ------------------------------ 옛 자리에서 옮기기 ------------------------------ */

	/**
	 * 한 번만: 자동 인덱싱(과 그보다 옛 자리인 Ads 매니저)에 있던 인증 값·인증 파일을 이 모듈로 옮기고 이 모듈을 켠다.
	 * WSP_Core::boot() 가 모듈을 불러온 직후 부른다(이 모듈이 꺼져 있어도 돈다).
	 */
	public static function maybe_migrate() {
		if ( get_option( self::MIGRATED_OPTION ) ) {
			return;
		}
		$me = new self();
		$s  = $me->settings();

		$ai = get_option( 'wsp_mod_auto_index', array() );
		if ( is_array( $ai ) ) {
			foreach ( array( 'verify_google', 'verify_naver', 'verify_bing' ) as $k ) {
				if ( '' === (string) $s[ $k ] && ! empty( $ai[ $k ] ) ) {
					$s[ $k ] = self::clean_verify( $ai[ $k ] );
				}
				unset( $ai[ $k ] );
			}
			if ( ! empty( $ai['verify_files'] ) && is_array( $ai['verify_files'] ) ) {
				$s['verify_files'] = array_merge( $ai['verify_files'], (array) $s['verify_files'] );
			}
			unset( $ai['verify_files'] );
			update_option( 'wsp_mod_auto_index', $ai );
		}
		$ads = get_option( 'wsp_mod_ads_manager', array() );
		if ( is_array( $ads ) && ! empty( $ads['verify_files'] ) && is_array( $ads['verify_files'] ) ) {
			$s['verify_files']   = array_merge( $ads['verify_files'], (array) $s['verify_files'] );
			$ads['verify_files'] = array();
			update_option( 'wsp_mod_ads_manager', $ads );
		}

		WSP_Settings::set( 'site_codes', $s );
		WSP_Settings::set_active( 'site_codes', 1 );
		update_option( self::MIGRATED_OPTION, WSP_VERSION );
	}

	/* ------------------------------ 출력 ------------------------------ */

	public function register() {
		add_action( 'wp_head', array( $this, 'output_head' ), 1 );
		add_action( 'wp_footer', array( $this, 'output_footer' ), 20 );
		if ( ! empty( $this->settings()['verify_files'] ) ) {
			// 코어의 redirect_canonical(10) 이 404 를 다른 주소로 돌리기 전에 먼저 내보낸다.
			add_action( 'template_redirect', array( $this, 'maybe_serve_verify' ), 1 );
		}
	}

	public function output_head() {
		echo self::head_html( $this->settings() ); // phpcs:ignore WordPress.Security.EscapeOutput -- 값은 clean_* 로 영문·숫자만 남긴다.
	}

	public function output_footer() {
		echo self::footer_html( $this->settings() ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** 업로드한 인증 파일을 루트 주소에서 내보낸다(예: googleXXXX.html, naverXXXX.html). */
	public function maybe_serve_verify() {
		$req   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path  = trim( (string) wp_parse_url( $req, PHP_URL_PATH ), '/' );
		$files = $this->settings()['verify_files'];
		if ( is_array( $files ) && isset( $files[ $path ] ) ) {
			$type = '.xml' === strtolower( substr( $path, -4 ) ) ? 'application/xml; charset=utf-8' : 'text/html; charset=utf-8';
			$this->send_virtual_headers( $type );
			echo $files[ $path ]; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	/**
	 * <head> 에 넣을 코드 전부(순수 함수 — 검산이 본다).
	 *
	 * @param array $s 설정.
	 * @return string
	 */
	public static function head_html( $s ) {
		$out = '';
		$metas = array(
			'verify_google' => 'google-site-verification',
			'verify_naver'  => 'naver-site-verification',
			'verify_bing'   => 'msvalidate.01',
		);
		foreach ( $metas as $k => $name ) {
			$v = self::clean_verify( isset( $s[ $k ] ) ? $s[ $k ] : '' );
			if ( '' !== $v ) {
				$out .= '<meta name="' . $name . '" content="' . $v . '" />' . "\n";
			}
		}
		$ga = self::clean_ga4( isset( $s['ga4'] ) ? $s['ga4'] : '' );
		if ( '' !== $ga ) {
			$out .= '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $ga . '"></script>' . "\n"
				. "<script>window.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('js', new Date());gtag('config', '" . $ga . "');</script>\n";
		}
		$ads = self::clean_adsense( isset( $s['adsense'] ) ? $s['adsense'] : '' );
		if ( '' !== $ads ) {
			$out .= '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . $ads . '" crossorigin="anonymous"></script>' . "\n";
		}
		$cl = self::clean_clarity( isset( $s['clarity'] ) ? $s['clarity'] : '' );
		if ( '' !== $cl ) {
			$out .= '<script type="text/javascript">(function(c,l,a,r,i,t,y){c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};'
				. 't=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);'
				. '})(window, document, "clarity", "script", "' . $cl . '");</script>' . "\n";
		}
		return '' === $out ? '' : "<!-- WP Site Pack: 소유 확인·분석 코드 -->\n" . $out;
	}

	/**
	 * 푸터에 넣을 코드(네이버 애널리틱스는 네이버 안내대로 페이지 끝).
	 *
	 * @param array $s 설정.
	 * @return string
	 */
	public static function footer_html( $s ) {
		$wa = self::clean_naver_wa( isset( $s['naver_wa'] ) ? $s['naver_wa'] : '' );
		if ( '' === $wa ) {
			return '';
		}
		return '<script type="text/javascript" src="//wcs.naver.net/wcslog.js"></script>' . "\n"
			. '<script type="text/javascript">if(!wcs_add) var wcs_add = {};wcs_add["wa"] = "' . $wa . '";if(window.wcs) { wcs_do(); }</script>' . "\n";
	}

	/* ------------------------------ 값 다듬기(순수 함수) ------------------------------ */

	/** 메타 태그 통째로 붙여 넣어도 content 값만. 영문·숫자·_ - 만 남긴다. */
	public static function clean_verify( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '~content\s*=\s*["\']([^"\']+)["\']~i', $v, $m ) ) {
			$v = $m[1];
		}
		return preg_match( '~^[A-Za-z0-9_\-]{6,120}$~', $v ) ? $v : '';
	}

	/** GA4 측정 ID(G-XXXXXXX). 코드 통째로 붙여도 ID 만. */
	public static function clean_ga4( $v ) {
		return preg_match( '~\bG-[A-Z0-9]{4,20}\b~i', (string) $v, $m ) ? strtoupper( $m[0] ) : '';
	}

	/** 애드센스 게시자 ID. pub-… 나 숫자만 넣어도 ca-pub-… 로. */
	public static function clean_adsense( $v ) {
		$v = (string) $v;
		if ( preg_match( '~(?:ca-)?pub-(\d{10,20})~i', $v, $m ) ) {
			return 'ca-pub-' . $m[1];
		}
		return preg_match( '~^\s*(\d{10,20})\s*$~', $v, $m ) ? 'ca-pub-' . $m[1] : '';
	}

	/** 네이버 애널리틱스 wa 값. 코드 통째로 붙여도 값만. */
	public static function clean_naver_wa( $v ) {
		$v = (string) $v;
		if ( preg_match( '~wcs_add\s*\[\s*["\']wa["\']\s*\]\s*=\s*["\']([^"\']+)["\']~', $v, $m ) ) {
			$v = $m[1];
		}
		$v = trim( $v );
		return preg_match( '~^[A-Za-z0-9]{6,40}$~', $v ) ? $v : '';
	}

	/** 클래리티 프로젝트 ID. 코드 통째로 붙여도 ID 만. */
	public static function clean_clarity( $v ) {
		$v = (string) $v;
		if ( preg_match( '~["\']clarity["\']\s*,\s*["\']script["\']\s*,\s*["\']([A-Za-z0-9]+)["\']~', $v, $m ) ) {
			$v = $m[1];
		} elseif ( preg_match( '~clarity\.ms/tag/([A-Za-z0-9]+)~', $v, $m ) ) {
			$v = $m[1];
		}
		$v = trim( $v );
		return preg_match( '~^[A-Za-z0-9]{6,20}$~', $v ) ? strtolower( $v ) : '';
	}

	/**
	 * 글자(페이지 HTML·엘리먼츠 코드·functions.php)에서 칸별 값을 찾는다.
	 * 로더(스크립트 주소)가 몇 번 나오는지도 센다 — 첫 화면에서 「두 번 나감」을 잡으려고.
	 *
	 * @param string $text
	 * @return array 칸 => ['values' => [값...], 'count' => 나온 횟수]
	 */
	public static function find_codes( $text ) {
		$text = (string) $text;
		$r    = array();
		foreach ( array_keys( self::fields() ) as $k ) {
			$r[ $k ] = array( 'values' => array(), 'count' => 0 );
		}
		// 소유 확인 메타(속성 순서가 바뀐 것도).
		$names = array( 'google-site-verification' => 'verify_google', 'naver-site-verification' => 'verify_naver', 'msvalidate.01' => 'verify_bing' );
		if ( preg_match_all( '~<meta\b[^>]*>~i', $text, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				if ( preg_match( '~name\s*=\s*["\']([^"\']+)["\']~i', $tag, $n ) && isset( $names[ strtolower( $n[1] ) ] ) ) {
					$v = self::clean_verify( $tag );
					if ( '' !== $v ) {
						$k = $names[ strtolower( $n[1] ) ];
						$r[ $k ]['values'][] = $v;
						$r[ $k ]['count']++;
					}
				}
			}
		}
		// GA4 — gtag 로더 주소 기준(글자 속 아무 G-… 를 잡지 않게).
		if ( preg_match_all( '~googletagmanager\.com/gtag/js\?id=(G-[A-Z0-9]+)~i', $text, $m ) ) {
			foreach ( $m[1] as $v ) {
				$r['ga4']['values'][] = strtoupper( $v );
				$r['ga4']['count']++;
			}
		}
		// 애드센스 — 로더 스크립트 기준(광고 단위 <ins> 의 data-ad-client 는 세지 않는다).
		if ( preg_match_all( '~adsbygoogle\.js\?client=(ca-pub-\d+)~i', $text, $m ) ) {
			foreach ( $m[1] as $v ) {
				$r['adsense']['values'][] = strtolower( $v );
				$r['adsense']['count']++;
			}
		}
		if ( preg_match_all( '~wcs_add\s*\[\s*["\']wa["\']\s*\]\s*=\s*["\']([^"\']+)["\']~', $text, $m ) ) {
			foreach ( $m[1] as $v ) {
				$v = self::clean_naver_wa( $v );
				if ( '' !== $v ) {
					$r['naver_wa']['values'][] = $v;
					$r['naver_wa']['count']++;
				}
			}
		}
		if ( preg_match_all( '~["\']clarity["\']\s*,\s*["\']script["\']\s*,\s*["\']([A-Za-z0-9]+)["\']~', $text, $m ) ) {
			foreach ( $m[1] as $v ) {
				$r['clarity']['values'][] = strtolower( $v );
				$r['clarity']['count']++;
			}
		}
		foreach ( $r as $k => $x ) {
			$r[ $k ]['values'] = array_values( array_unique( $x['values'] ) );
		}
		return $r;
	}

	/* ------------------------------ 사이트에서 찾기 ------------------------------ */

	/**
	 * 코드가 들어 있을 만한 곳을 훑는다(이 모듈 자신은 빼고).
	 *
	 * @return array 칸 => [ ['value' => 값, 'source' => '어디'], ... ]
	 */
	public function scan_sources() {
		$out = array();
		foreach ( array_keys( self::fields() ) as $k ) {
			$out[ $k ] = array();
		}
		$add = function ( $text, $source ) use ( &$out ) {
			foreach ( self::find_codes( $text ) as $k => $x ) {
				foreach ( $x['values'] as $v ) {
					$out[ $k ][] = array( 'value' => $v, 'source' => $source );
				}
			}
		};

		// ① GeneratePress 엘리먼츠(훅, 발행된 것).
		$els = get_posts( array(
			'post_type'      => 'gp_elements',
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
		) );
		foreach ( $els as $el ) {
			if ( 'hook' !== get_post_meta( $el->ID, '_generate_element_type', true ) ) {
				continue;
			}
			$add( self::without_ad_unit_loader( (string) get_post_meta( $el->ID, '_generate_element_content', true ) ), 'GeneratePress 엘리먼츠 #' . $el->ID . ' «' . $el->post_title . '»' );
		}
		// ② 자식 테마 functions.php.
		$fn = get_stylesheet_directory() . '/functions.php';
		if ( get_stylesheet_directory() !== get_template_directory() && is_readable( $fn ) ) {
			$add( (string) @file_get_contents( $fn ), '자식 테마 functions.php' ); // phpcs:ignore
		}
		// ③ Insert Headers and Footers 플러그인 — 켜져 있을 때만(꺼진 채 설정값만 남은 곳은 코드가 안 나간다. benefitf 실측).
		$ihaf_on = false;
		foreach ( (array) get_option( 'active_plugins', array() ) as $pl ) {
			if ( false !== strpos( (string) $pl, 'insert-headers-and-footers' ) ) {
				$ihaf_on = true;
			}
		}
		if ( $ihaf_on ) {
			foreach ( array( 'ihaf_insert_header', 'ihaf_insert_body', 'ihaf_insert_footer' ) as $opt ) {
				$add( (string) get_option( $opt, '' ), 'Insert Headers and Footers 플러그인' );
			}
		}
		// ④ 사이트팩 「헤더 & 푸터」 모듈.
		$hf = get_option( 'wsp_mod_header_footer', array() );
		if ( is_array( $hf ) && WSP_Settings::is_active( 'header_footer' ) ) {
			$add( implode( "\n", array_map( 'strval', $hf ) ), '사이트팩 「헤더 & 푸터」' );
		}
		// ⑤ 방문자통계 플러그인의 GA4(켜져 있을 때만 gtag 를 내보낸다).
		$wvs = get_option( 'wvs_settings', array() );
		if ( is_array( $wvs ) && ! empty( $wvs['ga_enable'] ) && ! empty( $wvs['ga_measurement'] ) ) {
			$ga = self::clean_ga4( $wvs['ga_measurement'] );
			if ( '' !== $ga ) {
				$out['ga4'][] = array( 'value' => $ga, 'source' => '방문자통계 플러그인(GA4 코드 넣기 켜짐)' );
			}
		}
		return $out;
	}

	/**
	 * 광고 단위(<ins class="adsbygoogle">)가 든 코드에서는 애드센스 로더를 찾기 대상에서 뺀다.
	 * 광고 단위 엘리먼츠는 옮기지 않고 그대로 두므로, 거기 딸린 로더까지 사이트팩 칸에 채우면
	 * 같은 로더가 두 번 나간다(2026-09-15 coreabiz — 푸터 광고 단위 cobiz_footer 만 로더를 내던 곳).
	 *
	 * @param string $text
	 * @return string
	 */
	public static function without_ad_unit_loader( $text ) {
		$text = (string) $text;
		if ( false === stripos( $text, '<ins' ) ) {
			return $text;
		}
		return preg_replace( '~adsbygoogle\.js\?client=ca-pub-\d+~i', 'adsbygoogle.js', $text );
	}

	/** 웹 루트에 실제로 있는 인증 파일(네이버·구글·빙). */
	public static function root_verify_files() {
		$found = array();
		$list  = @scandir( ABSPATH ); // phpcs:ignore
		if ( ! is_array( $list ) ) {
			return $found;
		}
		foreach ( $list as $f ) {
			if ( self::is_verify_filename( $f ) ) {
				$found[] = $f;
			}
		}
		return $found;
	}

	/** 인증 파일 이름 모양인가(naver….html · google….html · BingSiteAuth.xml · yandex_….html). */
	public static function is_verify_filename( $f ) {
		return (bool) preg_match( '~^(naver[0-9a-f]{16,40}\.html|google[0-9a-f]{12,20}\.html|BingSiteAuth\.xml|yandex_[0-9a-f]{12,20}\.html)$~', (string) $f );
	}

	/** 지금 첫 화면에 나가는 코드(캐시를 비껴 새로 받는다). */
	public function scan_home() {
		$res = wp_remote_get( add_query_arg( 'wsp_codes_check', time(), home_url( '/' ) ), array( 'timeout' => 12, 'sslverify' => false ) );
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		return array( 'codes' => self::find_codes( (string) wp_remote_retrieve_body( $res ) ) );
	}

	/**
	 * 빈 칸만 채운다(이미 넣은 값은 그대로).
	 *
	 * @param array $s       설정.
	 * @param array $sources scan_sources() 결과.
	 * @return array [새 설정, 채운 칸 목록]
	 */
	public static function fill_empty( $s, $sources ) {
		$filled = array();
		foreach ( $sources as $k => $items ) {
			if ( '' !== (string) ( isset( $s[ $k ] ) ? $s[ $k ] : '' ) || empty( $items ) ) {
				continue;
			}
			$s[ $k ]    = $items[0]['value'];
			$filled[ $k ] = $items[0]['source'];
		}
		return array( $s, $filled );
	}

	/* ------------------------------ 저장 ------------------------------ */

	/** 워프글쓰기 「웹마스터 도구」 REST 가 네이버 인증 값을 넣는다. */
	public function put_naver_verification( $code ) {
		$s                 = $this->settings();
		$s['verify_naver'] = self::clean_verify( $code );
		WSP_Settings::set( $this->id(), $s );
		WSP_Settings::set_active( $this->id(), 1 );
		return true;
	}

	public function sanitize( $input ) {
		$s   = $this->settings();
		$out = array(
			'verify_google' => self::clean_verify( isset( $input['verify_google'] ) ? $input['verify_google'] : '' ),
			'verify_naver'  => self::clean_verify( isset( $input['verify_naver'] ) ? $input['verify_naver'] : '' ),
			'verify_bing'   => self::clean_verify( isset( $input['verify_bing'] ) ? $input['verify_bing'] : '' ),
			'verify_files'  => is_array( $s['verify_files'] ) ? $s['verify_files'] : array(),
			'ga4'           => self::clean_ga4( isset( $input['ga4'] ) ? $input['ga4'] : '' ),
			'adsense'       => self::clean_adsense( isset( $input['adsense'] ) ? $input['adsense'] : '' ),
			'naver_wa'      => self::clean_naver_wa( isset( $input['naver_wa'] ) ? $input['naver_wa'] : '' ),
			'clarity'       => self::clean_clarity( isset( $input['clarity'] ) ? $input['clarity'] : '' ),
		);

		// 인증 파일 업로드(파일 방식 인증). 이름·내용만 저장, 100KB 까지.
		if ( ! empty( $_FILES['verify_upload']['name'] ) && empty( $_FILES['verify_upload']['error'] ) ) {
			$name = sanitize_file_name( $_FILES['verify_upload']['name'] );
			$tmp  = isset( $_FILES['verify_upload']['tmp_name'] ) ? $_FILES['verify_upload']['tmp_name'] : ''; // phpcs:ignore
			if ( $name && is_uploaded_file( $tmp ) ) {
				$content = (string) @file_get_contents( $tmp ); // phpcs:ignore
				if ( strlen( $content ) < 100000 ) {
					$out['verify_files'][ $name ] = $content;
				}
			}
		}
		if ( ! empty( $input['remove_verify'] ) ) {
			unset( $out['verify_files'][ sanitize_file_name( (string) $input['remove_verify'] ) ] );
		}

		// 「사이트에서 찾아 채우기」 — 빈 칸만.
		if ( ! empty( $input['scan_fill'] ) ) {
			list( $out, $filled ) = self::fill_empty( $out, $this->scan_sources() );
			set_transient( 'wsp_site_codes_filled', $filled, 120 );
		}
		return $out;
	}

	/* ------------------------------ 화면 ------------------------------ */

	public function render_settings() {
		$s       = $this->settings();
		$sources = $this->scan_sources();
		$home    = $this->scan_home();
		$live    = isset( $home['codes'] ) ? $home['codes'] : null;
		$filled  = get_transient( 'wsp_site_codes_filled' );
		if ( false !== $filled ) {
			delete_transient( 'wsp_site_codes_filled' );
		}
		$helps = array(
			'verify_google' => '서치콘솔 「HTML 태그」 방식의 content 값. 메타 태그 통째로 붙여 넣어도 됩니다.',
			'verify_naver'  => '서치어드바이저 「HTML 태그」 방식의 content 값.',
			'verify_bing'   => '빙 웹마스터 「메타 태그」 방식(msvalidate.01)의 content 값.',
			'ga4'           => '측정 ID(G-로 시작). 코드 통째로 붙여 넣어도 ID 만 씁니다.',
			'adsense'       => '게시자 ID(ca-pub-…). 자동 광고 로더를 &lt;head&gt; 에 넣습니다. 광고 단위(&lt;ins&gt;)는 여기서 넣지 않습니다.',
			'naver_wa'      => '네이버 애널리틱스 코드의 wcs_add["wa"] 값. 페이지 끝에 넣습니다.',
			'clarity'       => '클래리티 프로젝트 ID(설정 → 개요의 ID).',
		);
		?>
		<?php if ( is_array( $filled ) ) : ?>
			<div class="wsp-note">
				<?php if ( empty( $filled ) ) : ?>
					찾아 채울 빈 칸이 없었습니다(이미 넣었거나, 사이트 어디에도 없습니다).
				<?php else : ?>
					<strong>찾아 채웠습니다</strong> — 아래 값을 확인하고 한 번 더 <strong>저장하기</strong>를 누르면 끝입니다.
					<ul style="margin:6px 0 0">
						<?php foreach ( $filled as $k => $src ) : ?>
							<li><?php echo esc_html( self::fields()[ $k ][0] ); ?> ← <?php echo esc_html( $src ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트에서 찾아 채우기</strong>
				<span class="wsp-row-help">GeneratePress 엘리먼츠·자식 테마 functions.php·Insert Headers and Footers·방문자통계에 이미 심어 둔 코드를 찾아 <strong>빈 칸만</strong> 채웁니다.</span></div>
			<div class="wsp-row-control">
				<button type="submit" name="scan_fill" value="1" class="button">찾아서 빈 칸 채우기</button>
				<?php if ( isset( $home['error'] ) ) : ?>
					<p class="wsp-check-no">첫 화면을 읽지 못해 「나가는 중」 여부를 못 봤습니다: <?php echo esc_html( $home['error'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<?php
		$sections = array(
			'verify' => array( '소유 확인', '검색엔진 웹마스터 도구에 「이 사이트는 내 것」임을 보이는 태그입니다. 색인 요청(IndexNow)과는 관계없습니다. 한 번 인증한 뒤에도 지우면 인증이 풀릴 수 있습니다.' ),
			'code'   => array( '분석·광고 코드', '값만 넣으면 표준 코드로 만들어 넣습니다. 같은 코드가 다른 곳(엘리먼츠 등)에서도 나가면 방문이 두 번 잡히니, 옮긴 뒤에는 다른 곳을 꺼 주세요.' ),
		);
		foreach ( $sections as $kind => $sec ) :
			?>
			<h2 class="wsp-section-title" style="margin:28px 0 6px"><?php echo esc_html( $sec[0] ); ?></h2>
			<p class="wsp-row-help" style="margin:0 0 10px"><?php echo esc_html( $sec[1] ); ?></p>
			<?php
			foreach ( self::fields() as $k => $meta ) :
				if ( $meta[1] !== $kind ) {
					continue;
				}
				$others = array();
				foreach ( $sources[ $k ] as $it ) {
					$others[] = $it['source'] . ( $it['value'] !== (string) $s[ $k ] ? ' (' . $it['value'] . ')' : '' );
				}
				$others = array_values( array_unique( $others ) );
				$count  = $live ? (int) $live[ $k ]['count'] : null;
				?>
				<div class="wsp-row">
					<div class="wsp-row-label"><strong><?php echo esc_html( $meta[0] ); ?></strong>
						<span class="wsp-row-help"><?php echo wp_kses_post( $helps[ $k ] ); ?></span></div>
					<div class="wsp-row-control">
						<input type="text" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $s[ $k ] ); ?>" style="width:60%">
						<p class="wsp-row-help" style="margin-top:6px">
							<?php if ( '' !== (string) $s[ $k ] ) : ?>
								<span class="wsp-check-ok">사이트팩이 넣는 중</span>
							<?php else : ?>
								<span class="wsp-check-no">사이트팩에는 없음</span>
							<?php endif; ?>
							<?php if ( null !== $count ) : ?>
								· 첫 화면에 <?php echo 0 === $count ? '<span class="wsp-check-no">없음</span>' : ( '<strong>' . (int) $count . '번</strong> 나감' ); ?>
								<?php if ( $count > 1 && 'adsense' !== $k ) : ?>
									<span class="wsp-check-no">— 두 곳 이상에서 나갑니다</span>
								<?php endif; ?>
							<?php endif; ?>
						</p>
						<?php if ( ! empty( $others ) ) : ?>
							<p class="wsp-row-help">다른 곳에도 있음: <?php echo esc_html( implode( ' · ', $others ) ); ?></p>
						<?php endif; ?>
					</div>
				</div>
				<?php
			endforeach;

			if ( 'verify' === $kind ) :
				$root = self::root_verify_files();
				$vf   = is_array( $s['verify_files'] ) ? $s['verify_files'] : array();
				$ads  = WSP_Core::module( 'ads_manager' );
				$daum = ( $ads && method_exists( $ads, 'daum_line' ) ) ? (string) $ads->daum_line() : '';
				?>
				<div class="wsp-row">
					<div class="wsp-row-label"><strong>인증 파일</strong>
						<span class="wsp-row-help">파일 방식으로 인증할 때. 웹 루트에 이미 있는 파일은 그대로 두고 목록만 보여 줍니다. 새 파일은 여기서 올리면 루트 주소로 내보냅니다.</span></div>
					<div class="wsp-row-control">
						<?php if ( empty( $root ) && empty( $vf ) ) : ?>
							<p class="wsp-check-no">인증 파일 없음</p>
						<?php else : ?>
							<table class="widefat striped" style="max-width:560px"><tbody>
								<?php foreach ( $root as $f ) : ?>
									<tr><td><code class="wsp-code"><?php echo esc_html( $f ); ?></code> <a href="<?php echo esc_url( home_url( '/' . $f ) ); ?>" target="_blank">열기</a></td>
										<td style="text-align:right"><span class="wsp-row-help">웹 루트 파일</span></td></tr>
								<?php endforeach; ?>
								<?php foreach ( $vf as $f => $c ) : ?>
									<tr><td><code class="wsp-code"><?php echo esc_html( $f ); ?></code> <a href="<?php echo esc_url( home_url( '/' . $f ) ); ?>" target="_blank">열기</a></td>
										<td style="text-align:right"><button type="submit" name="remove_verify" value="<?php echo esc_attr( $f ); ?>" class="button-link-delete"
											onclick="return confirm('<?php echo esc_js( $f ); ?> 인증 파일을 지울까요? 검색엔진 소유 확인이 풀릴 수 있습니다.');">지우기</button></td></tr>
								<?php endforeach; ?>
							</tbody></table>
						<?php endif; ?>
						<p style="margin-top:8px"><input type="file" name="verify_upload" accept=".html,.htm,.txt,.xml"></p>
					</div>
				</div>
				<div class="wsp-row">
					<div class="wsp-row-label"><strong>다음(Daum) 웹마스터</strong>
						<span class="wsp-row-help">다음은 robots.txt 한 줄로 인증합니다 — 그 줄은 「Ads 매니저」의 robots.txt 에 있습니다.</span></div>
					<div class="wsp-row-control">
						<?php if ( '' !== $daum ) : ?>
							<code class="wsp-code"><?php echo esc_html( $daum ); ?></code>
						<?php else : ?>
							<p class="wsp-check-no">robots.txt 에 다음 인증 줄 없음</p>
						<?php endif; ?>
						<?php if ( $ads ) : ?>
							<p class="wsp-row-help"><a href="<?php echo esc_url( $ads->settings_url() ); ?>">Ads 매니저에서 보기</a></p>
						<?php endif; ?>
					</div>
				</div>
				<?php
			endif;
		endforeach;
		?>
		<div class="wsp-note">저장하면 바로 반영됩니다. 페이지 캐시(Breeze)를 쓰면 한 번 비워 주세요 — 위의 「첫 화면에 N번」은 캐시를 비껴 새로 읽은 결과입니다.</div>
		<?php
	}
}
