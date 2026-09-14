<?php
/**
 * 모듈: SEO — Rank Math(무료·프로)가 하던 일을 대신한다. (기획서 4.8)
 *
 *  나누어 담은 곳(길어져서 갈랐다. 이 파일이 네 개를 require 한다):
 *   - seo/class-seo-head.php     제목 태그 · 설명문 · 로봇 메타 · 공유 태그(오픈그래프·트위터)
 *   - seo/class-seo-schema.php   구조화 데이터(JSON-LD @graph · FAQ · 동영상 · 경로)
 *   - seo/class-seo-sitemap.php  사이트맵(sitemap_index.xml · post/page/category)
 *   - seo/class-seo-metabox.php  글 편집 화면의 「검색 미리보기」(스니펫 편집기 + 포커스 키워드)
 *
 *  지키는 것(기획서 4.8 원칙):
 *   - Rank Math 가 켜져 있으면 머리말 출력 · 사이트맵 · 첨부파일 처리를 **모두 쉰다**.
 *     같은 태그가 두 번 나가면 안 된다. 스니펫 편집기만 그대로 보인다(값을 우리 쪽으로 옮기는 자리).
 *   - robots.txt 의 내용은 Ads 매니저, 인증 메타·인증 파일·IndexNow 는 자동 인덱싱 모듈이 맡는다.
 *     이 모듈이 robots.txt 에 넣는 것은 **`Sitemap:` 한 줄뿐**이다(그 줄이 없을 때만.
 *     seo/class-seo-sitemap.php). Ads 매니저가 robots.txt 를 통째로 바꾸면 그쪽이 이긴다.
 *   - 페이지 캐시(Breeze+CDN)가 있으므로 방문자마다 달라지는 값을 HTML 에 넣지 않는다.
 *   - 값을 읽을 때 the_content 필터 결과를 쓰지 않는다(관련 글·소셜 공유가 거기 붙는다).
 *     원본 post_content 를 읽는다.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/seo/class-seo-head.php';
require_once __DIR__ . '/seo/class-seo-schema.php';
require_once __DIR__ . '/seo/class-seo-sitemap.php';
require_once __DIR__ . '/seo/class-seo-metabox.php';

class WSP_Mod_SEO extends WSP_Module {

	/** 「글 종류」에 쓸 수 있는 값. */
	const ARTICLE_TYPES = array( 'BlogPosting', 'Article', 'NewsArticle' );

	/** 「첨부파일 페이지」에 쓸 수 있는 값. */
	const ATTACHMENT_MODES = array( 'to_post', 'wp_default' );

	/** Rank Math 에서 이어받은 것을 적어 두는 옵션 이름(설정 화면에 한 줄로 보인다). */
	const MIGRATED_OPTION = 'wsp_seo_migrated';

	/** [Rank Math 설정 가져오기] 단추 이름. */
	const IMPORT_FIELD = 'wsp_seo_import_rankmath';

	/**
	 * Rank Math 설정 → 우리 설정. (열쇠 = Rank Math 옵션 묶음·그 안의 이름, 값 = 우리 설정 열쇠·화면 이름)
	 * 값에 `%…%` 치환 변수가 들어 있으면 건너뛴다(그대로 내보내면 변수 글자가 화면에 찍힌다).
	 */
	const RANK_MATH_MAP = array(
		array( 'titles',  'website_name',                 'site_name',           '사이트 이름',     'text' ),
		array( 'titles',  'website_alternate_name',       'site_alternate_name', '사이트 다른 이름', 'text' ),
		array( 'titles',  'knowledgegraph_name',          'org_name',            '조직 이름',       'text' ),
		array( 'titles',  'knowledgegraph_logo',          'org_logo',            '로고',           'url' ),
		array( 'titles',  'title_separator',              'title_separator',     '구분 기호',       'sep' ),
		array( 'titles',  'pt_post_default_article_type', 'article_type',        '글 종류',         'article_type' ),
		array( 'titles',  'homepage_description',         'site_description',    '사이트 설명문',    'text' ),
		array( 'titles',  'open_graph_image',             'default_share_image', '기본 공유 사진',   'url' ),
		array( 'sitemap', 'items_per_page',               'sitemap_per_page',    '사이트맵 한 파일에 글 수', 'int' ),
	);

	/** @var WSP_SEO_Head|null */
	protected $head = null;
	/** @var WSP_SEO_Schema|null */
	protected $schema = null;
	/** @var WSP_SEO_Sitemap|null */
	protected $sitemap = null;
	/** @var WSP_SEO_Metabox|null */
	protected $metabox = null;

	public function id()   { return 'seo'; }
	public function name() { return 'SEO'; }
	public function desc() { return '검색·SNS 에 나가는 제목·설명문·공유 사진·구조화 데이터·사이트맵을 내보냅니다(Rank Math 대체).'; }
	public function icon() { return 'dashicons-search'; }

	/**
	 * 기본 설정값.
	 * ⚠ sanitize() 의 열쇠와 1:1 로 맞춰 둔다 — 여기 있는데 sanitize 에 없으면 저장할 때 그 값이 사라진다.
	 */
	public function default_settings() {
		return array(
			'title_append_sitename' => 0,            // 「글 제목 뒤에 사이트명 붙이기」
			'title_separator'       => '-',          // 「구분 기호」
			'home_title_tagline'    => 1,            // 「홈 제목에 태그라인 붙이기」(태그라인이 비면 어차피 안 붙음)
			'site_name'             => '',           // 「사이트 이름」(비면 블로그 이름)
			'site_description'      => '',           // 「사이트 설명문」(홈 설명문)
			'default_share_image'   => '',           // 「기본 공유 사진」
			'org_name'              => '',           // 「조직 이름」(비면 사이트명)
			'org_logo'              => '',           // 「로고」(비면 사이트 아이콘)
			'site_alternate_name'   => '',           // 「사이트 다른 이름」
			'same_as'               => '',           // 「연관 채널 주소」(줄마다 하나)
			'article_type'          => 'BlogPosting',// 「글 종류」
			'author_display_name'   => '',           // 「작성자 표시 이름」(비면 글 작성자 표시명)
			'breadcrumb'            => 1,            // 「경로 표시」
			'author_archive_index'  => 0,            // 「작성자 페이지 검색 노출」
			'sitemap_per_page'      => 200,          // 「사이트맵 한 파일에 글 수」
			'attachment_page'       => 'to_post',    // 「첨부파일 페이지」
		);
	}

	/** 저장 전 검증. 기본값 목록과 열쇠가 1:1. */
	public function sanitize( $input ) {
		$type = isset( $input['article_type'] ) ? (string) $input['article_type'] : 'BlogPosting';
		if ( ! in_array( $type, self::ARTICLE_TYPES, true ) ) {
			$type = 'BlogPosting';
		}
		$attach = isset( $input['attachment_page'] ) ? sanitize_key( $input['attachment_page'] ) : 'to_post';
		if ( ! in_array( $attach, self::ATTACHMENT_MODES, true ) ) {
			$attach = 'to_post';
		}

		// 「연관 채널 주소」 — 줄마다 하나. 주소 모양이 아닌 줄은 버린다.
		$same_as = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $input['same_as'] ?? '' ) ) as $line ) {
			$line = esc_url_raw( trim( $line ) );
			if ( '' !== $line ) {
				$same_as[] = $line;
			}
		}

		$sep = isset( $input['title_separator'] ) ? trim( sanitize_text_field( (string) $input['title_separator'] ) ) : '-';
		if ( '' === $sep ) {
			$sep = '-';
		}

		$out = array(
			'title_append_sitename' => empty( $input['title_append_sitename'] ) ? 0 : 1,
			'title_separator'       => $sep,
			'home_title_tagline'    => empty( $input['home_title_tagline'] ) ? 0 : 1,
			'site_name'             => isset( $input['site_name'] ) ? sanitize_text_field( (string) $input['site_name'] ) : '',
			'site_description'      => isset( $input['site_description'] ) ? sanitize_text_field( (string) $input['site_description'] ) : '',
			'default_share_image'   => isset( $input['default_share_image'] ) ? esc_url_raw( (string) $input['default_share_image'] ) : '',
			'org_name'              => isset( $input['org_name'] ) ? sanitize_text_field( (string) $input['org_name'] ) : '',
			'org_logo'              => isset( $input['org_logo'] ) ? esc_url_raw( (string) $input['org_logo'] ) : '',
			'site_alternate_name'   => isset( $input['site_alternate_name'] ) ? sanitize_text_field( (string) $input['site_alternate_name'] ) : '',
			'same_as'               => implode( "\n", $same_as ),
			'article_type'          => $type,
			'author_display_name'   => isset( $input['author_display_name'] ) ? sanitize_text_field( (string) $input['author_display_name'] ) : '',
			'breadcrumb'            => empty( $input['breadcrumb'] ) ? 0 : 1,
			'author_archive_index'  => empty( $input['author_archive_index'] ) ? 0 : 1,
			'sitemap_per_page'      => max( 10, min( 2000, (int) ( $input['sitemap_per_page'] ?? 200 ) ) ),
			'attachment_page'       => $attach,
		);

		// [Rank Math 설정 가져오기] 를 눌렀을 때 — 저장과 같은 걸음에서 이어받는다.
		if ( ! empty( $input[ self::IMPORT_FIELD ] ) ) {
			$out = $this->import_rank_math( $out );
		}

		return $out;
	}

	/* ------------------------------ Rank Math 설정 이어받기 ------------------------------ */

	/** Rank Math 가 저장해 둔 설정 세 묶음(없으면 빈 배열). */
	public function rank_math_options() {
		if ( ! function_exists( 'get_option' ) ) {
			return array( 'titles' => array(), 'general' => array(), 'sitemap' => array() );
		}
		$get = function ( $name ) {
			$v = get_option( $name, array() );
			return is_array( $v ) ? $v : array();
		};
		return array(
			'titles'  => $get( 'rank-math-options-titles' ),
			'general' => $get( 'rank-math-options-general' ),
			'sitemap' => $get( 'rank-math-options-sitemap' ),
		);
	}

	/**
	 * Rank Math 설정을 우리 설정으로 옮기고, 옮긴 것을 옵션에 적어 둔다.
	 *
	 * @param array $current 지금 설정.
	 * @return array 옮긴 뒤의 설정.
	 */
	public function import_rank_math( $current ) {
		$res = self::migrate_from_rank_math( $current, $this->default_settings(), $this->rank_math_options() );
		if ( function_exists( 'update_option' ) ) {
			update_option(
				self::MIGRATED_OPTION,
				array(
					'at'    => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
					'moved' => $res['moved'],
				)
			);
		}
		return $res['settings'];
	}

	/**
	 * Rank Math 설정 → 우리 설정. **아직 기본값인 칸에만** 옮긴다(사장님이 고쳐 둔 값을 덮지 않는다).
	 * (순수 함수 — tools/seo_검산.php 가 이 규칙을 검산한다.)
	 *
	 * @param array $current  지금 설정.
	 * @param array $defaults 기본값.
	 * @param array $rm       array('titles'=>…, 'general'=>…, 'sitemap'=>…)
	 * @return array array('settings'=>새 설정, 'moved'=>array(화면 이름 => 옮긴 값))
	 */
	public static function migrate_from_rank_math( $current, $defaults, $rm ) {
		$out   = is_array( $current ) ? $current : array();
		$moved = array();

		$titles  = isset( $rm['titles'] ) && is_array( $rm['titles'] ) ? $rm['titles'] : array();
		$general = isset( $rm['general'] ) && is_array( $rm['general'] ) ? $rm['general'] : array();
		$sitemap = isset( $rm['sitemap'] ) && is_array( $rm['sitemap'] ) ? $rm['sitemap'] : array();
		$group   = array( 'titles' => $titles, 'general' => $general, 'sitemap' => $sitemap );

		foreach ( self::RANK_MATH_MAP as $row ) {
			list( $bundle, $rm_key, $our_key, $label, $kind ) = $row;

			if ( ! isset( $group[ $bundle ][ $rm_key ] ) || ! array_key_exists( $our_key, $defaults ) ) {
				continue;
			}
			// 이미 사장님이 고친 칸은 건드리지 않는다.
			if ( ! isset( $out[ $our_key ] ) || $out[ $our_key ] !== $defaults[ $our_key ] ) {
				continue;
			}

			$raw = $group[ $bundle ][ $rm_key ];
			if ( is_array( $raw ) || null === $raw ) {
				continue;
			}
			$raw = trim( (string) $raw );
			if ( '' === $raw || preg_match( '/%[^%\s]+%/', $raw ) ) {
				continue; // 빈 값·치환 변수(%title% 같은 것)는 건너뛴다.
			}

			$value = null;
			switch ( $kind ) {
				case 'int':
					$value = max( 10, min( 2000, (int) $raw ) );
					break;
				case 'url':
					$value = preg_match( '#^https?://#i', $raw ) ? $raw : null;
					break;
				case 'article_type':
					$value = in_array( $raw, self::ARTICLE_TYPES, true ) ? $raw : null;
					break;
				case 'sep':
					// 구분 기호는 Rank Math 가 HTML 엔티티로 저장해 둔 경우가 있다(benefitf.com
					// `&bull;`). 그대로 옮기면 제목을 낼 때(이미 텍스트로 이어붙인 뒤 워드프레스가
					// esc_html 하는 자리) `&amp;bull;` 로 두 번 이스케이프된다 — 미리 풀어 둔다.
					$decoded = trim( html_entity_decode( $raw, ENT_QUOTES, 'UTF-8' ) );
					$value   = ( '' !== $decoded ) ? $decoded : null;
					break;
				default:
					$value = $raw;
			}
			if ( null === $value || $value === $out[ $our_key ] ) {
				continue;
			}
			$out[ $our_key ]  = $value;
			$moved[ $label ] = (string) $value;
		}

		// 첨부파일 페이지 — Rank Math 가 「글로 보내기」였으면 우리도 그렇게.
		if ( isset( $general['attachment_redirect_urls'] ) && 'on' === $general['attachment_redirect_urls']
			&& isset( $out['attachment_page'], $defaults['attachment_page'] )
			&& $out['attachment_page'] === $defaults['attachment_page']
			&& 'to_post' !== $out['attachment_page'] ) {
			$out['attachment_page']       = 'to_post';
			$moved['첨부파일 페이지'] = '글로 보내기';
		}

		// 홈 제목에 태그라인을 붙일지 — Rank Math 의 homepage_title 에 %sitedesc% 가 있었을 때만 켠다.
		// (apt-view.com 은 `%sitename% %page%` 라 태그라인이 없다 — 그대로 두면 태그라인이 블로그
		//  주소("https://apt-view.com")라 홈 제목이 "아파트VIEW - https://apt-view.com" 이 된다.
		//  zau.kr `%sitename% %page% %sep% %sitedesc%` · coreabiz `%sitename%` · benefitf `%sitename% %page%`.)
		if ( isset( $titles['homepage_title'] ) && is_string( $titles['homepage_title'] ) && '' !== trim( $titles['homepage_title'] )
			&& isset( $out['home_title_tagline'], $defaults['home_title_tagline'] )
			&& (int) $out['home_title_tagline'] === (int) $defaults['home_title_tagline'] ) {
			$want = ( false !== strpos( $titles['homepage_title'], '%sitedesc%' ) ) ? 1 : 0;
			if ( $want !== (int) $out['home_title_tagline'] ) {
				$out['home_title_tagline']            = $want;
				$moved['홈 제목에 태그라인 붙이기'] = $want ? '켬' : '끔';
			}
		}

		// 작성자 페이지 — Rank Math 가 noindex 였으면 「검색 노출」을 끈다.
		$author_robots = isset( $titles['author_robots'] ) ? (array) $titles['author_robots'] : array();
		if ( in_array( 'noindex', $author_robots, true )
			&& isset( $out['author_archive_index'], $defaults['author_archive_index'] )
			&& (int) $out['author_archive_index'] === (int) $defaults['author_archive_index']
			&& 0 !== (int) $out['author_archive_index'] ) {
			$out['author_archive_index']          = 0;
			$moved['작성자 페이지 검색 노출'] = '끔';
		}

		return array( 'settings' => $out, 'moved' => $moved );
	}

	/* ------------------------------ 부품 ------------------------------ */

	public function head() {
		if ( null === $this->head ) {
			$this->head = new WSP_SEO_Head( $this );
		}
		return $this->head;
	}

	public function schema() {
		if ( null === $this->schema ) {
			$this->schema = new WSP_SEO_Schema( $this );
		}
		return $this->schema;
	}

	public function sitemap() {
		if ( null === $this->sitemap ) {
			$this->sitemap = new WSP_SEO_Sitemap( $this );
		}
		return $this->sitemap;
	}

	public function metabox() {
		if ( null === $this->metabox ) {
			$this->metabox = new WSP_SEO_Metabox( $this );
		}
		return $this->metabox;
	}

	/**
	 * Rank Math 가 켜져 있나.
	 * register() 는 plugins_loaded 에서 돌아 이미 모든 플러그인이 로드된 뒤라 이 판정이 확실하다.
	 *
	 * @return bool
	 */
	public function rank_math_active() {
		return ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) );
	}

	/* ------------------------------ 훅 등록 ------------------------------ */

	public function register() {
		// 스니펫 편집기는 Rank Math 가 켜져 있어도 보인다 — 옛 값을 우리 칸으로 옮기는 자리라서.
		$this->metabox()->register();

		if ( $this->rank_math_active() ) {
			// 머리말·사이트맵·첨부파일 처리는 쉰다(같은 태그가 두 번 나가면 안 된다).
			return;
		}

		$this->head()->register();
		$this->schema()->register();
		$this->sitemap()->register();

		// 첨부파일 페이지 — 「글로 보내기」일 때만 걸린다.
		if ( 'to_post' === $this->settings()['attachment_page'] ) {
			add_action( 'template_redirect', array( $this, 'maybe_redirect_attachment' ), 1 );
		}

		// 옛 Rank Math FAQ 블록이 Rank Math CSS 없이도 안 깨지게 하는 최소 CSS.
		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );
	}

	/**
	 * 이 모듈이 쓰는 「사이트 이름」. 비면 블로그 이름.
	 * (WebSite.name · og:site_name · 조직 이름이 비었을 때의 Organization.name — 세 자리가 이것을 쓴다.
	 *  Rank Math 는 「사이트 이름」을 따로 두어 zau.kr 은 `zau` 였다. 블로그 이름과 다를 수 있다.)
	 */
	public function site_name() {
		$name = trim( (string) $this->settings()['site_name'] );
		return ( '' !== $name ) ? $name : (string) get_bloginfo( 'name', 'display' );
	}

	/** 활성화 시 — Rank Math 설정을 이어받고, 사이트맵 주소 규칙을 심고 다시 깐다. */
	public function on_activate() {
		// 이어받기는 Rank Math 가 켜져 있어도 한다 — 그 값을 읽어 오는 것이 목적이다.
		$imported = $this->import_rank_math( $this->settings() );
		WSP_Settings::set( $this->id(), $imported );

		if ( $this->rank_math_active() ) {
			return; // 쉬는 중이면 규칙을 심지 않는다(Rank Math 의 사이트맵과 부딪힌다).
		}
		$this->sitemap()->add_rules();
		flush_rewrite_rules();
	}

	/** 비활성화 시 — 우리 규칙이 남지 않게 다시 깐다(코어가 flush 를 한 번 더 한다). */
	public function on_deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * 첨부파일 페이지 → 부모 글로 301(부모가 없거나 비공개면 홈).
	 * 지금 benefitf·zau 는 홈 301, coreabiz·apt-view 는 404 라 사이트마다 달랐다 — 통일한다.
	 *
	 * `wp_attachment_pages_enabled=0`(워드프레스 6.4+ 기본) 인 사이트(zau.kr 실측)는 첨부파일
	 * 주소를 워드프레스가 **404 로** 낸다 — `is_attachment()` 가 아니라 `is_404()` 로 걸린다.
	 * 그때는 요청 주소의 슬러그로 첨부파일 글을 직접 찾는다.
	 */
	public function maybe_redirect_attachment() {
		if ( is_attachment() ) {
			$this->redirect_to_attachment_parent( (int) get_queried_object_id() );
			return;
		}

		if ( is_404() ) {
			$id = $this->find_404_attachment_id();
			if ( $id ) {
				$this->redirect_to_attachment_parent( $id );
			}
		}
	}

	/**
	 * 404 화면인데 사실 첨부파일 주소인가 — 있으면 그 첨부파일 글 번호(못 찾으면 0).
	 * `attachment_id`·`attachment` 쿼리 변수가 남아 있으면 그것부터 보고, 없으면 요청 주소의
	 * 마지막 조각을 슬러그로 본다(첨부파일은 부모 글 산하라 경로 전체가 일치할 필요는 없다 —
	 * `get_page_by_path()` 도 첨부파일일 때는 계층을 보지 않는다).
	 */
	protected function find_404_attachment_id() {
		$by_id = (int) get_query_var( 'attachment_id' );
		if ( $by_id ) {
			return $by_id;
		}

		$slug = trim( (string) get_query_var( 'attachment' ) );
		if ( '' === $slug ) {
			$slug = self::attachment_slug_from_path( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- 슬러그 조회에만 쓴다.
		}
		if ( '' === $slug ) {
			return 0;
		}

		$post = get_page_by_path( $slug, OBJECT, 'attachment' );
		return ( $post && isset( $post->ID ) ) ? (int) $post->ID : 0;
	}

	/** 첨부파일 번호 → 부모 글(공개일 때)로 301, 부모가 없거나 비공개면 홈으로. */
	protected function redirect_to_attachment_parent( $attachment_id ) {
		$parent_id = $attachment_id ? wp_get_post_parent_id( $attachment_id ) : 0;
		$url       = '';
		if ( $parent_id && 'publish' === get_post_status( $parent_id ) ) {
			$url = (string) get_permalink( $parent_id );
		}
		if ( '' === $url ) {
			$url = home_url( '/' );
		}
		wp_redirect( $url, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect -- 같은 사이트 주소.
		exit;
	}

	/**
	 * 요청 경로에서 첨부파일 슬러그로 볼 마지막 조각. (순수 함수 — tools/seo_검산.php 가 검산한다.)
	 * 쿼리 문자열은 버리고, 앞뒤 빗금을 없앤 뒤 남는 마지막 조각을 돌려준다.
	 *
	 * @param string $path REQUEST_URI 같은 요청 경로(쿼리·도메인 있어도 된다).
	 * @return string 못 찾으면 빈 문자열.
	 */
	public static function attachment_slug_from_path( $path ) {
		$path = trim( (string) strtok( (string) $path, '?' ), '/' );
		if ( '' === $path ) {
			return '';
		}
		$segments = explode( '/', $path );
		return trim( rawurldecode( (string) end( $segments ) ) );
	}

	/** 옛 FAQ 블록 최소 CSS — 그 블록이 실제로 들어 있는 글에서만 싣는다. */
	public function front_assets() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post || false === strpos( (string) $post->post_content, 'rank-math-faq' ) ) {
			return;
		}
		WSP_Assets::front_style( 'seo-faq' );
	}

	/* ------------------------------ 설정 화면 ------------------------------ */

	public function render_settings() {
		$s        = $this->settings();
		$rm       = $this->rank_math_active();
		$icon     = get_site_icon_url();
		$migrated = get_option( self::MIGRATED_OPTION, array() );
		$moved    = ( is_array( $migrated ) && ! empty( $migrated['moved'] ) && is_array( $migrated['moved'] ) ) ? $migrated['moved'] : array();
		?>
		<?php if ( $rm ) : ?>
		<div class="notice notice-warning" style="margin:0 0 16px;padding:12px 14px">
			<p style="margin:0;font-size:14px"><strong>Rank Math 가 켜져 있어 이 모듈이 쉬고 있습니다 — 하나만 켜세요.</strong></p>
			<p style="margin:6px 0 0;color:#646970">
				같은 태그가 두 번 나가지 않도록, Rank Math 가 켜져 있는 동안에는 제목·설명문·공유 태그·구조화 데이터·사이트맵·첨부파일 처리를 모두 쉽니다.
				아래 「검색 미리보기」(글 편집 화면)는 그동안에도 그대로 쓸 수 있습니다.
			</p>
		</div>
		<?php endif; ?>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>Rank Math 설정 가져오기</strong>
				<span class="wsp-row-help">Rank Math 에 적어 두셨던 값을 이 화면으로 옮깁니다. <strong>아직 손대지 않은 칸에만</strong> 들어가므로 여기서 고쳐 둔 값은 그대로 남습니다.</span></div>
			<div class="wsp-row-control">
				<button type="submit" name="<?php echo esc_attr( self::IMPORT_FIELD ); ?>" value="1" class="button">Rank Math 설정 가져오기</button>
				<?php if ( ! empty( $moved ) ) : ?>
					<?php
					$lines = array();
					foreach ( $moved as $label => $value ) {
						$lines[] = $label . ' → ' . $value;
					}
					?>
					<p style="margin:8px 0 0;color:#1d2327;font-size:13px">
						가져온 것<?php echo ! empty( $migrated['at'] ) ? ' (' . esc_html( (string) $migrated['at'] ) . ')' : ''; ?>:
						<?php echo esc_html( implode( ' · ', $lines ) ); ?>
					</p>
				<?php elseif ( is_array( $migrated ) && ! empty( $migrated['at'] ) ) : ?>
					<p style="margin:8px 0 0;color:#646970;font-size:13px">
						가져올 것이 없었습니다 (<?php echo esc_html( (string) $migrated['at'] ); ?>) — Rank Math 설정이 없거나 이미 여기서 고쳐 두신 칸뿐입니다.
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트 이름</strong>
				<span class="wsp-row-help">검색·SNS 에 나가는 사이트 이름(구조화 데이터·og:site_name). 비우면 블로그 이름을 씁니다.</span></div>
			<div class="wsp-row-control">
				<input type="text" name="site_name" value="<?php echo esc_attr( $s['site_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>제목</strong>
				<span class="wsp-row-help">글·페이지 제목 뒤에 사이트명을 붙일지, 붙인다면 무엇으로 나눌지.</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="title_append_sitename" value="1" <?php checked( $s['title_append_sitename'], 1 ); ?>> 글 제목 뒤에 사이트명 붙이기</label>
				<div style="margin-top:8px">
					구분 기호: <input type="text" name="title_separator" value="<?php echo esc_attr( $s['title_separator'] ); ?>" style="width:80px;min-width:0">
					<span class="wsp-row-help" style="display:inline">홈은 태그라인이 있을 때만 이 기호를 씁니다(태그라인이 비면 안 붙습니다).</span>
				</div>
				<div style="margin-top:8px">
					<label><input type="checkbox" name="home_title_tagline" value="1" <?php checked( $s['home_title_tagline'], 1 ); ?>> 홈 제목에 태그라인 붙이기</label>
					<span class="wsp-row-help" style="display:inline">태그라인이 비어 있으면 켜져 있어도 붙지 않습니다.</span>
				</div>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트 설명문</strong>
				<span class="wsp-row-help">홈 화면의 설명문. 비우면 워드프레스 태그라인을 씁니다.</span></div>
			<div class="wsp-row-control">
				<input type="text" name="site_description" value="<?php echo esc_attr( $s['site_description'] ); ?>" style="width:80%" maxlength="300">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>기본 공유 사진</strong>
				<span class="wsp-row-help">대표사진도 본문 사진도 없을 때 SNS 공유 상자에 쓸 사진(홈에도 이것).</span></div>
			<div class="wsp-row-control">
				<input type="url" id="wsp_seo_share_image" name="default_share_image" value="<?php echo esc_attr( $s['default_share_image'] ); ?>" style="width:60%" placeholder="https://...">
				<button type="button" class="button wsp-media-pick" data-target="#wsp_seo_share_image">미디어 선택</button>
				<?php if ( $s['default_share_image'] ) : ?>
					<div style="margin-top:8px"><img src="<?php echo esc_url( $s['default_share_image'] ); ?>" alt="" style="max-width:220px;height:auto;border:1px solid #dcdcde"></div>
				<?php endif; ?>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>조직 이름</strong>
				<span class="wsp-row-help">검색결과에 보이는 발행자 이름. 비우면 위의 「사이트 이름」.</span></div>
			<div class="wsp-row-control">
				<input type="text" name="org_name" value="<?php echo esc_attr( $s['org_name'] ); ?>" placeholder="<?php echo esc_attr( $this->site_name() ); ?>">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>로고</strong>
				<span class="wsp-row-help">발행자 로고. 비우면 사이트 아이콘<?php echo $icon ? '' : '(지금 사이트 아이콘도 없습니다)'; ?>.</span></div>
			<div class="wsp-row-control">
				<input type="url" id="wsp_seo_org_logo" name="org_logo" value="<?php echo esc_attr( $s['org_logo'] ); ?>" style="width:60%" placeholder="<?php echo esc_attr( $icon ); ?>">
				<button type="button" class="button wsp-media-pick" data-target="#wsp_seo_org_logo">미디어 선택</button>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트 다른 이름</strong>
				<span class="wsp-row-help">사이트를 부르는 또 하나의 이름(영문명·줄임말 등).</span></div>
			<div class="wsp-row-control">
				<input type="text" name="site_alternate_name" value="<?php echo esc_attr( $s['site_alternate_name'] ); ?>">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>연관 채널 주소</strong>
				<span class="wsp-row-help">줄마다 하나. 네이버 블로그·유튜브·페이스북 등 같은 곳이 운영하는 채널 주소.</span></div>
			<div class="wsp-row-control">
				<textarea name="same_as" rows="4" placeholder="https://blog.naver.com/..."><?php echo esc_textarea( $s['same_as'] ); ?></textarea>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>글 종류</strong>
				<span class="wsp-row-help">구조화 데이터에 넣을 글의 종류. 지금 쓰던 값 그대로 맞춰 두세요.</span></div>
			<div class="wsp-row-control">
				<select name="article_type">
					<option value="BlogPosting" <?php selected( $s['article_type'], 'BlogPosting' ); ?>>BlogPosting (블로그 글)</option>
					<option value="Article" <?php selected( $s['article_type'], 'Article' ); ?>>Article (일반 글)</option>
					<option value="NewsArticle" <?php selected( $s['article_type'], 'NewsArticle' ); ?>>NewsArticle (뉴스 글)</option>
				</select>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>작성자 표시 이름</strong>
				<span class="wsp-row-help">구조화 데이터의 작성자 이름. 비우면 글쓴이의 표시 이름을 씁니다.</span></div>
			<div class="wsp-row-control">
				<input type="text" name="author_display_name" value="<?php echo esc_attr( $s['author_display_name'] ); ?>">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>경로 표시</strong>
				<span class="wsp-row-help">홈 › 카테고리 › 글 경로를 구조화 데이터로 넣습니다(화면에는 보이지 않습니다).</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="breadcrumb" value="1" <?php checked( $s['breadcrumb'], 1 ); ?>> 경로 데이터 넣기</label>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>작성자 페이지 검색 노출</strong>
				<span class="wsp-row-help">끄면 작성자 페이지에 noindex 를 붙입니다(지금과 같음).</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="author_archive_index" value="1" <?php checked( $s['author_archive_index'], 1 ); ?>> 작성자 페이지를 검색에 노출</label>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>사이트맵 한 파일에 글 수</strong>
				<span class="wsp-row-help">바꾸면 파일 번호(post-sitemap1·2…)가 달라집니다. 검색엔진에 등록된 주소가 있으면 그대로 두세요.</span></div>
			<div class="wsp-row-control">
				<input type="number" name="sitemap_per_page" min="10" max="2000" value="<?php echo esc_attr( $s['sitemap_per_page'] ); ?>"> 편
				&nbsp; <a href="<?php echo esc_url( home_url( '/sitemap_index.xml' ) ); ?>" target="_blank" rel="noopener">지금 사이트맵 보기</a>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>첨부파일 페이지</strong>
				<span class="wsp-row-help">사진 한 장만 있는 빈 페이지가 검색에 잡히지 않게 합니다.</span></div>
			<div class="wsp-row-control">
				<select name="attachment_page">
					<option value="to_post" <?php selected( $s['attachment_page'], 'to_post' ); ?>>글로 보내기 (부모 글로 301, 부모가 없으면 홈)</option>
					<option value="wp_default" <?php selected( $s['attachment_page'], 'wp_default' ); ?>>워드프레스 기본</option>
				</select>
			</div>
		</div>
		<?php
	}
}
