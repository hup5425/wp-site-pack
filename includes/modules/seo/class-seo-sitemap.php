<?php
/**
 * SEO — 사이트맵. (기획서 4.8 바)
 *
 *  주소를 **지금 그대로** 유지한다(검색콘솔·네이버·다음에 등록된 주소가 끊기면 안 된다):
 *    /sitemap_index.xml  → post-sitemap1.xml … N · page-sitemap.xml · category-sitemap.xml
 *  글은 **오래된 것부터** 차례로 담는다(Rank Math 와 같은 순서라 기존 파일 번호가 유지된다).
 *  post-sitemap1.xml 맨 앞에는 **홈 주소**를 넣는다(Rank Math 와 같은 자리. 그래서 글 200편이면
 *  파일 안의 주소가 201개다 — 홈은 글 수로 세지 않으므로 파일 개수는 그대로다).
 *  워드프레스 기본 사이트맵은 끄고, /wp-sitemap.xml · /sitemap.xml 은 /sitemap_index.xml 로 301.
 *  robots.txt 에 `Sitemap:` 줄이 없으면 그 줄만 더한다(robots.txt 를 통째로 다루는 것은
 *  Ads 매니저다 — 그쪽이 우선순위 99 로 덮어쓰면 그쪽이 이긴다).
 *
 *  ⚠ 규칙(rewrite)이 아직 안 깔린 사이트(퍼머링크를 한 번도 저장 안 한 경우)에서도 돌게
 *    request 필터에서 주소를 직접 보고 쿼리 변수를 넣어 준다.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_SEO_Sitemap {

	/** 무엇을 내보낼지(index·post·page·category). */
	const QV = 'wsp_sitemap';

	/** post 사이트맵의 몇 번째 파일인지. */
	const QV_PAGE = 'wsp_sitemap_page';

	/** 만들어 둔 XML 을 담아 두는 시간(초). */
	const CACHE_TTL = HOUR_IN_SECONDS;

	/** 트랜지언트 이름 앞머리. */
	const CACHE_PREFIX = 'wsp_seo_sm_';

	/** @var WSP_Mod_SEO */
	protected $mod;

	public function __construct( $mod ) {
		$this->mod = $mod;
	}

	public function register() {
		// 워드프레스 기본 사이트맵(/wp-sitemap.xml)은 끈다 — 같은 일을 두 곳에 두지 않는다.
		add_filter( 'wp_sitemaps_enabled', '__return_false' );

		// robots.txt 의 `Sitemap:` 줄 — 워드프레스 기본 사이트맵을 끄면 그 줄도 같이 사라진다.
		add_filter( 'robots_txt', array( $this, 'filter_robots_txt' ), 20, 2 );

		add_action( 'init', array( $this, 'add_rules' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_filter( 'request', array( $this, 'catch_request' ) );
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );

		// 글·카테고리가 바뀌면 담아 둔 XML 을 버린다.
		add_action( 'save_post', array( $this, 'purge' ) );
		add_action( 'deleted_post', array( $this, 'purge' ) );
		add_action( 'trashed_post', array( $this, 'purge' ) );
		add_action( 'untrashed_post', array( $this, 'purge' ) );
		add_action( 'created_term', array( $this, 'purge' ) );
		add_action( 'edited_term', array( $this, 'purge' ) );
		add_action( 'delete_term', array( $this, 'purge' ) );
	}

	/* ============================ robots.txt ============================ */

	/**
	 * robots.txt 에 `Sitemap:` 줄 더하기.
	 * 워드프레스 기본 사이트맵(`wp_sitemaps_enabled`)을 끄면 워드프레스가 넣던 이 줄도 사라진다
	 * — zau.kr 실측에서 robots.txt 에서 통째로 없어졌다.
	 *
	 * @param string $output 지금까지 만들어진 robots.txt.
	 * @param bool   $public 「검색엔진 노출」 설정.
	 * @return string
	 */
	public function filter_robots_txt( $output, $public = true ) {
		if ( ! $public ) {
			return $output; // 검색에 안 보이게 해 둔 사이트에는 사이트맵 주소를 알리지 않는다.
		}
		return self::add_sitemap_line( $output, home_url( '/sitemap_index.xml' ) );
	}

	/**
	 * `Sitemap:` 줄이 없으면 맨 끝에 더한다. 이미 있으면 그대로. (순수 함수)
	 *
	 * @param string $output robots.txt 내용.
	 * @param string $url    사이트맵 주소.
	 * @return string
	 */
	public static function add_sitemap_line( $output, $url ) {
		$output = (string) $output;
		$url    = trim( (string) $url );
		if ( '' === $url || preg_match( '/^\s*Sitemap\s*:/mi', $output ) ) {
			return $output;
		}
		return rtrim( $output, "\r\n" ) . "\n\nSitemap: " . $url . "\n";
	}

	/* ============================ 주소 잡기 ============================ */

	public function add_rules() {
		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?' . self::QV . '=index', 'top' );
		add_rewrite_rule( '^post-sitemap([0-9]+)\.xml$', 'index.php?' . self::QV . '=post&' . self::QV_PAGE . '=$matches[1]', 'top' );
		add_rewrite_rule( '^page-sitemap\.xml$', 'index.php?' . self::QV . '=page', 'top' );
		add_rewrite_rule( '^category-sitemap\.xml$', 'index.php?' . self::QV . '=category', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = self::QV;
		$vars[] = self::QV_PAGE;
		return $vars;
	}

	/** 지금 요청의 경로(앞뒤 / 없이). */
	protected function req_path() {
		$req = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return trim( (string) wp_parse_url( $req, PHP_URL_PATH ), '/' );
	}

	/**
	 * rewrite 규칙이 아직 안 깔린 사이트에서도 돌게, 주소를 직접 보고 쿼리 변수를 넣는다.
	 *
	 * @param array $qv
	 * @return array
	 */
	public function catch_request( $qv ) {
		if ( ! empty( $qv[ self::QV ] ) ) {
			return $qv;
		}
		$what = self::parse_path( $this->req_path() );
		if ( null === $what ) {
			return $qv;
		}
		$qv[ self::QV ] = $what['what'];
		if ( isset( $what['page'] ) ) {
			$qv[ self::QV_PAGE ] = $what['page'];
		}
		return $qv;
	}

	/**
	 * 사이트맵 주소인지 가른다. (순수 함수)
	 *
	 * @param string $path 'sitemap_index.xml' 같은 경로.
	 * @return array|null array('what'=>..., 'page'=>...) 또는 null.
	 */
	public static function parse_path( $path ) {
		$path = trim( (string) $path, '/' );
		if ( 'sitemap_index.xml' === $path ) {
			return array( 'what' => 'index' );
		}
		if ( 'page-sitemap.xml' === $path ) {
			return array( 'what' => 'page' );
		}
		if ( 'category-sitemap.xml' === $path ) {
			return array( 'what' => 'category' );
		}
		if ( preg_match( '/^post-sitemap([0-9]+)\.xml$/', $path, $m ) ) {
			return array( 'what' => 'post', 'page' => max( 1, (int) $m[1] ) );
		}
		return null;
	}

	/* ============================ 내보내기 ============================ */

	public function maybe_serve() {
		$path = $this->req_path();

		// 옛 주소 → 지금 주소로 301(지금과 같음).
		if ( 'wp-sitemap.xml' === $path || 'sitemap.xml' === $path ) {
			wp_redirect( home_url( '/sitemap_index.xml' ), 301 ); // phpcs:ignore WordPress.Security.SafeRedirect -- 같은 사이트 주소.
			exit;
		}

		$what = get_query_var( self::QV );
		if ( ! $what ) {
			return;
		}
		$page = max( 1, (int) get_query_var( self::QV_PAGE ) );

		$xml = $this->xml( (string) $what, $page );
		if ( null === $xml ) {
			return; // 없는 파일 번호 등 — 워드프레스가 404 를 내게 그냥 둔다.
		}

		// template_redirect 시점에는 워드프레스가 이미 404 로 결론을 내 둔 뒤라 되돌려야 한다.
		// (WSP_Module::send_virtual_headers 와 같은 처리 — 그쪽은 모듈 전용 protected 라 여기서 다시 쓴다.)
		global $wp_query;
		if ( $wp_query instanceof WP_Query ) {
			$wp_query->is_404 = false;
		}
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- 만들 때 esc_url·esc_xml 로 넣었다.
		exit;
	}

	/**
	 * XML 한 벌(담아 둔 것이 있으면 그것). 없는 것이면 null.
	 *
	 * @param string $what index|post|page|category
	 * @param int    $page post 사이트맵의 번호.
	 * @return string|null
	 */
	protected function xml( $what, $page ) {
		$key    = self::CACHE_PREFIX . $what . '_' . $page;
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		switch ( $what ) {
			case 'index':
				$xml = $this->build_index();
				break;
			case 'post':
				$xml = $this->build_posts( $page );
				break;
			case 'page':
				$xml = $this->build_pages();
				break;
			case 'category':
				$xml = $this->build_categories();
				break;
			default:
				return null;
		}
		if ( null === $xml ) {
			return null;
		}
		set_transient( $key, $xml, self::CACHE_TTL );
		return $xml;
	}

	/** 한 파일에 담을 글 수. */
	protected function per_page() {
		return max( 10, (int) $this->mod->settings()['sitemap_per_page'] );
	}

	/**
	 * 글 수 → 파일 수. (순수 함수)
	 *
	 * @param int $count 전체 글 수.
	 * @param int $per   한 파일에 담을 수.
	 * @return int
	 */
	public static function chunk_total( $count, $per ) {
		$per   = max( 1, (int) $per );
		$count = max( 0, (int) $count );
		return ( $count > 0 ) ? (int) ceil( $count / $per ) : 0;
	}

	/**
	 * 오래된 것부터 센 순번(0 부터) → 몇 번째 파일인가. (순수 함수)
	 *
	 * @param int $index0 0 부터 세는 순번.
	 * @param int $per    한 파일에 담을 수.
	 * @return int 1 부터.
	 */
	public static function chunk_of_index( $index0, $per ) {
		$per = max( 1, (int) $per );
		return (int) floor( max( 0, (int) $index0 ) / $per ) + 1;
	}

	/* ---------------------------- 목록 만들기 ---------------------------- */

	/**
	 * 사이트맵에 담을 글 조회 조건.
	 * 첨부파일·비공개는 애초에 안 들어오고, noindex 로 표시된 글은 뺀다.
	 */
	protected function post_query_args( $type ) {
		return array(
			'post_type'           => $type,
			'post_status'         => 'publish',
			'has_password'        => false,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'orderby'             => 'date',
			'order'               => 'ASC',
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array( 'key' => 'rank_math_robots', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'rank_math_robots', 'value' => 'noindex', 'compare' => 'NOT LIKE' ),
			),
		);
	}

	/** 글(post) 전체 수. */
	protected function post_count( $type ) {
		$args                   = $this->post_query_args( $type );
		$args['posts_per_page'] = 1;
		$args['fields']         = 'ids';
		$q                      = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	protected function build_index() {
		$per   = $this->per_page();
		$total = self::chunk_total( $this->post_count( 'post' ), $per );

		$items = array();
		for ( $i = 1; $i <= $total; $i++ ) {
			$items[] = array(
				'loc'     => home_url( '/post-sitemap' . $i . '.xml' ),
				'lastmod' => $this->latest_modified( 'post', $i, $per ),
			);
		}
		if ( $this->post_count( 'page' ) > 0 ) {
			$items[] = array(
				'loc'     => home_url( '/page-sitemap.xml' ),
				'lastmod' => $this->latest_modified( 'page', 1, 0 ),
			);
		}
		if ( ! empty( $this->categories() ) ) {
			$items[] = array(
				'loc'     => home_url( '/category-sitemap.xml' ),
				'lastmod' => $this->latest_modified( 'post', 1, 0 ),
			);
		}

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $items as $it ) {
			$xml .= "\t<sitemap>\n\t\t<loc>" . esc_url( $it['loc'] ) . "</loc>\n";
			if ( '' !== $it['lastmod'] ) {
				$xml .= "\t\t<lastmod>" . esc_html( $it['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= "\t</sitemap>\n";
		}
		$xml .= '</sitemapindex>';
		return $xml;
	}

	/**
	 * 그 묶음에서 가장 늦게 수정된 시각(ISO 8601).
	 *
	 * @param string $type  post|page
	 * @param int    $chunk 몇 번째 파일(1 부터).
	 * @param int    $per   0 이면 전체에서 찾는다.
	 */
	protected function latest_modified( $type, $chunk, $per ) {
		$args = $this->post_query_args( $type );
		$args['no_found_rows'] = true;
		if ( $per > 0 ) {
			// 그 파일에 담기는 묶음(오래된 것부터 센 자리)만 훑는다.
			$args['posts_per_page'] = $per;
			$args['offset']         = ( max( 1, (int) $chunk ) - 1 ) * $per;
			$posts                  = ( new WP_Query( $args ) )->posts;
		} else {
			// 전체에서 찾을 때는 수정 시각이 가장 늦은 한 편 + 발행 시각이 가장 늦은 한 편.
			// 예약 발행 글은 수정 시각이 발행 시각보다 앞서 「수정 내림차순」만으로는 빠진다.
			$args['posts_per_page'] = 1;
			$args['order']          = 'DESC';
			$posts                  = array();
			foreach ( array( 'modified', 'date' ) as $by ) {
				$args['orderby'] = $by;
				$posts           = array_merge( $posts, ( new WP_Query( $args ) )->posts );
			}
		}
		$latest = '';
		foreach ( $posts as $p ) {
			$iso = WSP_SEO_Head::modified_iso( $p ); // 사이트 시간대(+09:00) — 머리말·구조화 데이터와 같은 방식.
			if ( '' !== $iso && $iso > $latest ) {
				$latest = $iso;
			}
		}
		return $latest;
	}

	protected function build_posts( $page ) {
		$per   = $this->per_page();
		$total = self::chunk_total( $this->post_count( 'post' ), $per );
		if ( $page > max( 1, $total ) ) {
			return null; // 없는 번호 — 404.
		}

		$args                   = $this->post_query_args( 'post' );
		$args['posts_per_page'] = $per;
		$args['offset']         = ( $page - 1 ) * $per;
		$args['no_found_rows']  = true;

		// 첫 파일 맨 앞에 홈 주소(Rank Math 와 같은 자리). 홈은 글 수로 세지 않는다.
		$prepend = ( 1 === (int) $page )
			? self::home_url_entry_xml( home_url( '/' ), $this->latest_modified( 'post', 1, 0 ) )
			: '';

		return $this->build_urlset( ( new WP_Query( $args ) )->posts, $prepend );
	}

	/**
	 * 사이트맵의 홈 한 줄. lastmod 는 가장 최근에 수정된 글의 시각. (순수 함수)
	 *
	 * @param string $home    홈 주소.
	 * @param string $lastmod ISO 8601(비면 안 넣는다).
	 * @return string
	 */
	public static function home_url_entry_xml( $home, $lastmod ) {
		$xml = "\t<url>\n\t\t<loc>" . esc_url( (string) $home ) . "</loc>\n";
		if ( '' !== (string) $lastmod ) {
			$xml .= "\t\t<lastmod>" . esc_html( (string) $lastmod ) . "</lastmod>\n";
		}
		return $xml . "\t</url>\n";
	}

	protected function build_pages() {
		$args                   = $this->post_query_args( 'page' );
		$args['posts_per_page'] = -1;
		$args['no_found_rows']  = true;
		return $this->build_urlset( ( new WP_Query( $args ) )->posts );
	}

	/** 글이 1편 이상인 카테고리만. */
	protected function categories() {
		$terms = get_terms( array(
			'taxonomy'   => 'category',
			'hide_empty' => true,
			'orderby'    => 'term_id',
			'order'      => 'ASC',
		) );
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $t ) {
			if ( (int) $t->count > 0 ) {
				$out[] = $t;
			}
		}
		return $out;
	}

	protected function build_categories() {
		$xml = $this->urlset_open();
		foreach ( $this->categories() as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				continue;
			}
			$xml .= "\t<url>\n\t\t<loc>" . esc_url( (string) $link ) . "</loc>\n\t</url>\n";
		}
		$xml .= '</urlset>';
		return $xml;
	}

	protected function urlset_open() {
		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
			. ' xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
	}

	/**
	 * 글 목록 → urlset. 항목마다 loc · lastmod · image:image(대표사진).
	 *
	 * @param WP_Post[] $posts
	 * @param string    $prepend 맨 앞에 먼저 넣을 <url> 덩어리(첫 파일의 홈 주소).
	 * @return string
	 */
	protected function build_urlset( $posts, $prepend = '' ) {
		$xml = $this->urlset_open() . (string) $prepend;
		foreach ( (array) $posts as $p ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( (string) get_permalink( $p ) ) . "</loc>\n";
			$iso  = WSP_SEO_Head::modified_iso( $p );
			if ( '' !== $iso ) {
				$xml .= "\t\t<lastmod>" . esc_html( $iso ) . "</lastmod>\n";
			}
			$thumb = get_the_post_thumbnail_url( $p->ID, 'full' );
			if ( $thumb ) {
				$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . esc_url( (string) $thumb ) . "</image:loc>\n\t\t</image:image>\n";
			}
			$xml .= "\t</url>\n";
		}
		$xml .= '</urlset>';
		return $xml;
	}

	/* ============================ 담아 둔 것 버리기 ============================ */

	/**
	 * 글·카테고리가 바뀌면 담아 둔 XML 을 버린다.
	 * 파일이 몇 개 안 되므로(글 1,400편이면 7개) 전부 버린다 — 어느 파일에 들었는지 세다가
	 * 한 칸 어긋나면 옛 내용이 한 시간 더 남는다.
	 */
	public function purge() {
		$per   = $this->per_page();
		$total = self::chunk_total( $this->post_count( 'post' ), $per );

		delete_transient( self::CACHE_PREFIX . 'index_1' );
		delete_transient( self::CACHE_PREFIX . 'page_1' );
		delete_transient( self::CACHE_PREFIX . 'category_1' );
		// 방금 줄어들었을 수도 있어 두 개 더 지운다.
		for ( $i = 1; $i <= $total + 2; $i++ ) {
			delete_transient( self::CACHE_PREFIX . 'post_' . $i );
		}
	}
}
