<?php
/**
 * SEO — 머리말(head) 내보내기: 제목 태그 · 설명문 · 로봇 메타 · 공유 태그. (기획서 4.8 가·나·다·라)
 *
 * 여기에 있는 「지금 글의 제목·설명문·대표사진」 계산은 구조화 데이터(class-seo-schema.php)도
 * 그대로 가져다 쓴다 — 같은 값을 두 벌로 만들면 한쪽만 고쳐진다.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_SEO_Head {

	/** 설명문 최대 글자 수(Rank Math 기본과 같다). */
	const DESC_LIMIT = 160;

	/** 설명문을 문장 끝에서 자를 때, 문장 끝이 이 글자 수보다 앞이면 그냥 상한에서 자른다. */
	const DESC_MIN_SENTENCE = 60;

	/** @var WSP_Mod_SEO */
	protected $mod;

	/** @var array|null 한 요청 안에서 여러 번 쓰이는 대표사진 정보. */
	protected $image_cache = null;

	public function __construct( $mod ) {
		$this->mod = $mod;
	}

	public function register() {
		add_filter( 'document_title_separator', array( $this, 'filter_separator' ) );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ) );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ) );
		// 우선순위 2 — canonical(rel_canonical, 10)보다 앞. canonical 은 워드프레스 기본을 그대로 둔다.
		add_action( 'wp_head', array( $this, 'output' ), 2 );
	}

	/* ============================ (가) 제목 태그 ============================ */

	public function filter_separator( $sep ) {
		$s = $this->mod->settings();
		return ( '' !== (string) $s['title_separator'] ) ? $s['title_separator'] : $sep;
	}

	/**
	 * 홈: 사이트명 (태그라인이 있으면 `사이트명 구분기호 태그라인`, 비면 구분 기호를 안 붙인다).
	 * 글·페이지: 「검색 제목」이 있으면 그것, 사이트명은 설정이 켜졌을 때만.
	 */
	public function filter_title_parts( $parts ) {
		$s = $this->mod->settings();

		if ( is_front_page() ) {
			$parts['title'] = get_bloginfo( 'name', 'display' );
			unset( $parts['site'] );
			$tagline = trim( (string) get_bloginfo( 'description', 'display' ) );
			if ( '' !== $tagline ) {
				$parts['tagline'] = $tagline;
			} else {
				unset( $parts['tagline'] );
			}
			return $parts;
		}

		if ( is_singular( array( 'post', 'page' ) ) ) {
			$custom = $this->custom_title( get_queried_object_id() );
			if ( '' !== $custom ) {
				$parts['title'] = $custom;
			}
			if ( empty( $s['title_append_sitename'] ) ) {
				unset( $parts['site'] );
			}
		}
		return $parts;
	}

	/**
	 * 글마다 따로 적은 「검색 제목」(_wsp_seo_title). 없으면 빈 문자열.
	 *
	 * Rank Math 의 rank_math_title 은 여기서 쓰지 않는다 — 그 값에는 %title% %sep% 같은
	 * 치환 변수가 들어 있어 그대로 내보내면 제목에 변수 글자가 그대로 찍힌다.
	 * (편집 화면 「검색 제목」 칸에는 보여 주고, 저장을 누르면 우리 메타로 들어온다.)
	 *
	 * @param int $post_id
	 * @return string
	 */
	public function custom_title( $post_id ) {
		if ( ! $post_id ) {
			return '';
		}
		return trim( (string) get_post_meta( $post_id, '_wsp_seo_title', true ) );
	}

	/** 지금 화면의 제목(공유 태그·구조화 데이터가 쓰는 값. 사이트명은 안 붙인 알맹이). */
	public function title_text() {
		if ( is_front_page() ) {
			return get_bloginfo( 'name', 'display' );
		}
		if ( is_singular() ) {
			$id     = get_queried_object_id();
			$custom = $this->custom_title( $id );
			return ( '' !== $custom ) ? $custom : get_the_title( $id );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			return ( $term && isset( $term->name ) ) ? $term->name : '';
		}
		return wp_strip_all_tags( (string) wp_get_document_title() );
	}

	/* ============================ (나) 설명문 ============================ */

	/**
	 * 지금 화면의 설명문.
	 *  글·페이지: 「설명문」 → rank_math_description(읽기만) → 손으로 쓴 요약 → 본문 첫 문장부터 160자.
	 *  홈: 「사이트 설명문」 → 태그라인.
	 *  카테고리: 카테고리 설명 → 없으면 빈 값(안 내보냄).
	 *
	 * @return string
	 */
	public function description() {
		$s = $this->mod->settings();

		if ( is_front_page() ) {
			$d = trim( (string) $s['site_description'] );
			if ( '' === $d ) {
				$d = trim( (string) get_bloginfo( 'description', 'display' ) );
			}
			return self::cut( $d );
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			$d    = ( $term && isset( $term->description ) ) ? $term->description : '';
			return self::cut( wp_strip_all_tags( (string) $d ) );
		}

		if ( is_singular() ) {
			return $this->post_description( get_post() );
		}

		return '';
	}

	/**
	 * 글 하나의 설명문(위 우선순위 그대로). 사이트맵·구조화 데이터도 이 값을 쓴다.
	 *
	 * @param WP_Post|null $post
	 * @return string
	 */
	public function post_description( $post ) {
		if ( ! $post ) {
			return '';
		}

		$own = trim( (string) get_post_meta( $post->ID, '_wsp_seo_description', true ) );
		if ( '' !== $own ) {
			return self::cut( $own );
		}

		// Rank Math 가 저장해 둔 값 — 읽기만 하고 옮겨 쓰지 않는다.
		$rm = trim( (string) get_post_meta( $post->ID, 'rank_math_description', true ) );
		if ( '' !== $rm && ! preg_match( '/%[a-z_]+%/i', $rm ) ) { // %excerpt% 같은 치환 변수가 든 값은 버린다.
			return self::cut( $rm );
		}

		$excerpt = trim( (string) $post->post_excerpt );
		if ( '' !== $excerpt ) {
			return self::cut( wp_strip_all_tags( $excerpt ) );
		}

		return self::cut( self::plain_text( (string) $post->post_content ) );
	}

	/**
	 * 원본 본문 → 평문. (the_content 필터을 타지 않는다 — 관련 글·소셜 공유가 거기 붙는다.)
	 *
	 * @param string $content post_content 원본.
	 * @return string
	 */
	public static function plain_text( $content ) {
		$t = (string) $content;
		if ( function_exists( 'excerpt_remove_blocks' ) ) {
			$t = excerpt_remove_blocks( $t );
		}
		$t = strip_shortcodes( $t );
		$t = wp_strip_all_tags( $t, true );
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		$t = str_replace( "\xC2\xA0", ' ', $t ); // 줄바꿈 없는 공백.
		return trim( preg_replace( '/\s+/u', ' ', $t ) );
	}

	/**
	 * 설명문 자르기 — 160자 안에서 마지막 문장 끝에서 자른다.
	 * 문장 끝이 60자 이전이면 그냥 160자에서 자르고 「…」를 붙인다.
	 *
	 * (순수 함수 — tools/seo_검산.php 가 이 규칙을 검산한다.)
	 *
	 * @param string $text
	 * @param int    $limit
	 * @return string
	 */
	public static function cut( $text, $limit = self::DESC_LIMIT ) {
		$text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
		if ( '' === $text ) {
			return '';
		}
		if ( mb_strlen( $text, 'UTF-8' ) <= $limit ) {
			return $text;
		}

		$slice = mb_substr( $text, 0, $limit, 'UTF-8' );
		$len   = mb_strlen( $slice, 'UTF-8' );
		$cut   = -1;
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = mb_substr( $slice, $i, 1, 'UTF-8' );
			if ( '.' !== $ch && '!' !== $ch && '?' !== $ch && '。' !== $ch ) {
				continue;
			}
			// 소수점(3.5)을 문장 끝으로 보지 않게 — 뒤가 공백이거나 끝일 때만 문장 끝.
			$next = ( $i + 1 < $len ) ? mb_substr( $slice, $i + 1, 1, 'UTF-8' ) : ' ';
			if ( ' ' === $next ) {
				$cut = $i;
			}
		}

		if ( $cut >= ( self::DESC_MIN_SENTENCE - 1 ) ) {
			return trim( mb_substr( $slice, 0, $cut + 1, 'UTF-8' ) );
		}
		return trim( $slice ) . '…';
	}

	/* ============================ (다) 로봇 메타 ============================ */

	/**
	 * 워드프레스 표준 wp_robots 필터.
	 *  글·페이지·카테고리·홈: index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1
	 *  사이트 안 검색·작성자 페이지·첨부파일 페이지·404: noindex, follow
	 */
	public function filter_robots( $robots ) {
		$s = $this->mod->settings();

		$noindex = ( is_search() || is_404() || is_attachment() );
		if ( is_author() && empty( $s['author_archive_index'] ) ) {
			$noindex = true;
		}

		if ( $noindex ) {
			unset( $robots['index'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
			return $robots;
		}

		if ( is_singular() || is_category() || is_tag() || is_tax() || is_front_page() || is_home() || is_author() ) {
			unset( $robots['noindex'] );
			$robots['index']             = true;
			$robots['follow']            = true;
			$robots['max-snippet']       = -1;
			$robots['max-image-preview'] = 'large';
			$robots['max-video-preview'] = -1;
		}
		return $robots;
	}

	/* ============================ (라) 공유 태그 ============================ */

	/** 지금 화면의 주소. */
	public function current_url() {
		if ( is_front_page() ) {
			return home_url( '/' );
		}
		if ( is_singular() ) {
			return (string) get_permalink( get_queried_object_id() );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			return is_wp_error( $link ) ? home_url( '/' ) : (string) $link;
		}
		return home_url( add_query_arg( array() ) );
	}

	/**
	 * 공유 사진: 대표사진(원본 크기) → 본문 첫 사진 → 「기본 공유 사진」 → 사이트 아이콘.
	 *
	 * @return array url·width·height·alt·type(없으면 빈 값)
	 */
	public function share_image() {
		if ( null !== $this->image_cache ) {
			return $this->image_cache;
		}
		$s     = $this->mod->settings();
		$empty = array( 'url' => '', 'width' => 0, 'height' => 0, 'alt' => '', 'type' => '' );
		$out   = $empty;

		if ( is_singular() ) {
			$id = get_queried_object_id();
			if ( has_post_thumbnail( $id ) ) {
				$out = $this->image_from_attachment( get_post_thumbnail_id( $id ) );
			}
			if ( '' === $out['url'] ) {
				$post = get_post( $id );
				$url  = $post ? self::first_image_url( (string) $post->post_content ) : '';
				if ( '' !== $url ) {
					$out = $this->image_from_url( $url );
				}
			}
		}

		if ( '' === $out['url'] && '' !== (string) $s['default_share_image'] ) {
			$out = $this->image_from_url( (string) $s['default_share_image'] );
		}
		if ( '' === $out['url'] ) {
			$icon_id = (int) get_option( 'site_icon' );
			if ( $icon_id ) {
				$out = $this->image_from_attachment( $icon_id );
			}
		}

		$this->image_cache = $out;
		return $out;
	}

	/** 첨부파일 번호로 사진 정보(크기·대체글·형식까지 안다). */
	protected function image_from_attachment( $att_id ) {
		$empty = array( 'url' => '', 'width' => 0, 'height' => 0, 'alt' => '', 'type' => '' );
		$att_id = (int) $att_id;
		if ( ! $att_id ) {
			return $empty;
		}
		$src = wp_get_attachment_image_src( $att_id, 'full' );
		if ( ! $src || empty( $src[0] ) ) {
			return $empty;
		}
		return array(
			'url'    => (string) $src[0],
			'width'  => (int) ( $src[1] ?? 0 ),
			'height' => (int) ( $src[2] ?? 0 ),
			'alt'    => trim( (string) get_post_meta( $att_id, '_wp_attachment_image_alt', true ) ),
			'type'   => (string) get_post_mime_type( $att_id ),
		);
	}

	/** 주소만 아는 사진 — 우리 미디어 라이브러리 것이면 크기까지 붙인다. */
	protected function image_from_url( $url ) {
		$att_id = attachment_url_to_postid( $url );
		if ( $att_id ) {
			$info = $this->image_from_attachment( $att_id );
			if ( '' !== $info['url'] ) {
				return $info;
			}
		}
		return array( 'url' => (string) $url, 'width' => 0, 'height' => 0, 'alt' => '', 'type' => '' );
	}

	/**
	 * 원본 본문에서 첫 사진 주소. (순수 함수)
	 *
	 * @param string $content
	 * @return string
	 */
	public static function first_image_url( $content ) {
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $content, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/** 머리말 출력 — 설명문 · 공유 태그. */
	public function output() {
		$title = $this->title_text();
		$desc  = $this->description();
		$url   = $this->current_url();
		$img   = $this->share_image();
		$site  = get_bloginfo( 'name', 'display' );

		$lines = array();
		$lines[] = '<!-- 사이트 팩 · SEO -->';

		if ( '' !== $desc ) {
			$lines[] = '<meta name="description" content="' . esc_attr( $desc ) . '">';
		}

		$is_article = ( is_singular( 'post' ) );
		$lines[]    = '<meta property="og:locale" content="' . esc_attr( str_replace( '-', '_', get_locale() ) ) . '">';
		$lines[]    = '<meta property="og:type" content="' . ( $is_article ? 'article' : 'website' ) . '">';
		$lines[]    = '<meta property="og:title" content="' . esc_attr( $title ) . '">';
		if ( '' !== $desc ) {
			$lines[] = '<meta property="og:description" content="' . esc_attr( $desc ) . '">';
		}
		$lines[] = '<meta property="og:url" content="' . esc_url( $url ) . '">';
		$lines[] = '<meta property="og:site_name" content="' . esc_attr( $site ) . '">';

		if ( $is_article ) {
			$post = get_post();
			if ( $post ) {
				$lines[] = '<meta property="article:published_time" content="' . esc_attr( self::iso8601( $post->post_date_gmt ) ) . '">';
				$lines[] = '<meta property="article:modified_time" content="' . esc_attr( self::iso8601( $post->post_modified_gmt ) ) . '">';
				$cats    = get_the_category( $post->ID );
				if ( ! empty( $cats ) && isset( $cats[0]->name ) ) {
					$lines[] = '<meta property="article:section" content="' . esc_attr( $cats[0]->name ) . '">';
				}
			}
		}

		if ( '' !== $img['url'] ) {
			$lines[] = '<meta property="og:image" content="' . esc_url( $img['url'] ) . '">';
			if ( 0 === strpos( $img['url'], 'https://' ) ) {
				$lines[] = '<meta property="og:image:secure_url" content="' . esc_url( $img['url'] ) . '">';
			}
			if ( $img['width'] && $img['height'] ) {
				$lines[] = '<meta property="og:image:width" content="' . (int) $img['width'] . '">';
				$lines[] = '<meta property="og:image:height" content="' . (int) $img['height'] . '">';
			}
			$alt     = ( '' !== $img['alt'] ) ? $img['alt'] : $title;
			$lines[] = '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '">';
			if ( '' !== $img['type'] ) {
				$lines[] = '<meta property="og:image:type" content="' . esc_attr( $img['type'] ) . '">';
			}
		}

		$lines[] = '<meta name="twitter:card" content="summary_large_image">';
		$lines[] = '<meta name="twitter:title" content="' . esc_attr( $title ) . '">';
		if ( '' !== $desc ) {
			$lines[] = '<meta name="twitter:description" content="' . esc_attr( $desc ) . '">';
		}
		if ( '' !== $img['url'] ) {
			$lines[] = '<meta name="twitter:image" content="' . esc_url( $img['url'] ) . '">';
		}

		echo "\n" . implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- 줄마다 이미 이스케이프했다.
	}

	/**
	 * GMT 시각 문자열 → ISO 8601(+00:00).
	 *
	 * @param string $gmt 'Y-m-d H:i:s' (GMT).
	 * @return string
	 */
	public static function iso8601( $gmt ) {
		$gmt = (string) $gmt;
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return '';
		}
		$ts = strtotime( $gmt . ' GMT' );
		if ( ! $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d\TH:i:sP', $ts );
	}
}
