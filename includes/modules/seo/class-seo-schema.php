<?php
/**
 * SEO — 구조화 데이터(JSON-LD). (기획서 4.8 마)
 *  <script type="application/ld+json"> 하나에 @graph 로 모아 내보낸다.
 *
 *  넣는 것: WebSite · Organization · WebPage · 글 종류(BlogPosting/Article/NewsArticle) ·
 *           ImageObject · FAQPage · VideoObject · BreadcrumbList
 *  ❌ 안 넣는 것: 글 별점·평점(자기 평가는 구글 지침 위반) · HowTo(2023 종료)
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_SEO_Schema {

	/** @var WSP_Mod_SEO */
	protected $mod;

	public function __construct( $mod ) {
		$this->mod = $mod;
	}

	public function register() {
		// 우선순위 3 — 공유 태그(2) 바로 뒤.
		add_action( 'wp_head', array( $this, 'output' ), 3 );
	}

	/* ============================ 내보내기 ============================ */

	public function output() {
		$graph = $this->build_graph();
		if ( empty( $graph ) ) {
			return;
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $graph ),
		);
		echo "\n" . '<script type="application/ld+json">'
			. wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
			. '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_json_encode 가 이스케이프한다.
	}

	protected function build_graph() {
		$s    = $this->mod->settings();
		$head = $this->mod->head();
		$home = trailingslashit( home_url( '/' ) );

		$org_id  = $home . '#organization';
		$site_id = $home . '#website';

		$graph = array();

		/* ---- 사이트 공통 ---- */
		$website = array(
			'@type'     => 'WebSite',
			'@id'       => $site_id,
			'url'       => $home,
			'name'      => get_bloginfo( 'name', 'display' ),
			'publisher' => array( '@id' => $org_id ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		$alt = trim( (string) $s['site_alternate_name'] );
		if ( '' !== $alt ) {
			$website['alternateName'] = $alt;
		}
		$graph[] = $website;

		$org = array(
			'@type' => 'Organization',
			'@id'   => $org_id,
			'name'  => ( '' !== trim( (string) $s['org_name'] ) ) ? trim( (string) $s['org_name'] ) : get_bloginfo( 'name', 'display' ),
			'url'   => $home,
		);
		$logo = trim( (string) $s['org_logo'] );
		if ( '' === $logo ) {
			$logo = (string) get_site_icon_url();
		}
		if ( '' !== $logo ) {
			$org['logo'] = array(
				'@type' => 'ImageObject',
				'@id'   => $home . '#logo',
				'url'   => $logo,
			);
			$org['image'] = array( '@id' => $home . '#logo' );
		}
		$same_as = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $s['same_as'] ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$same_as[] = $line;
			}
		}
		if ( ! empty( $same_as ) ) {
			$org['sameAs'] = $same_as;
		}
		$graph[] = $org;

		/* ---- 글·페이지 ---- */
		if ( ! is_singular( array( 'post', 'page' ) ) ) {
			return $graph;
		}

		$post = get_post();
		if ( ! $post ) {
			return $graph;
		}

		$url      = (string) get_permalink( $post );
		$page_id  = $url . '#webpage';
		$img_id   = $url . '#primaryimage';
		$title    = $head->title_text();
		$desc     = $head->post_description( $post );
		$img      = $head->share_image();
		$published = WSP_SEO_Head::iso8601( $post->post_date_gmt );
		$modified  = WSP_SEO_Head::iso8601( $post->post_modified_gmt );

		$webpage = array(
			'@type'      => 'WebPage',
			'@id'        => $page_id,
			'url'        => $url,
			'name'       => $title,
			'isPartOf'   => array( '@id' => $site_id ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		if ( '' !== $published ) {
			$webpage['datePublished'] = $published;
		}
		if ( '' !== $modified ) {
			$webpage['dateModified'] = $modified;
		}
		if ( '' !== $desc ) {
			$webpage['description'] = $desc;
		}
		if ( '' !== $img['url'] ) {
			$webpage['primaryImageOfPage'] = array( '@id' => $img_id );
		}
		$graph[] = $webpage;

		if ( '' !== $img['url'] ) {
			$image = array(
				'@type' => 'ImageObject',
				'@id'   => $img_id,
				'url'   => $img['url'],
			);
			if ( $img['width'] && $img['height'] ) {
				$image['width']  = (int) $img['width'];
				$image['height'] = (int) $img['height'];
			}
			$graph[] = $image;
		}

		// 글 종류(설정값). 페이지는 WebPage 로 충분해 글(post)에만 붙인다.
		if ( 'post' === $post->post_type ) {
			$author = trim( (string) $s['author_display_name'] );
			if ( '' === $author ) {
				$author = (string) get_the_author_meta( 'display_name', $post->post_author );
			}
			$plain   = WSP_SEO_Head::plain_text( (string) $post->post_content );
			$words   = preg_split( '/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY );
			$article = array(
				'@type'            => in_array( $s['article_type'], WSP_Mod_SEO::ARTICLE_TYPES, true ) ? $s['article_type'] : 'BlogPosting',
				'@id'              => $url . '#article',
				'headline'         => $title,
				'mainEntityOfPage' => array( '@id' => $page_id ),
				'author'           => array( '@type' => 'Person', 'name' => $author ),
				'publisher'        => array( '@id' => $org_id ),
				'wordCount'        => is_array( $words ) ? count( $words ) : 0,
				'inLanguage'       => get_bloginfo( 'language' ),
			);
			if ( '' !== $published ) {
				$article['datePublished'] = $published;
			}
			if ( '' !== $modified ) {
				$article['dateModified'] = $modified;
			}
			if ( '' !== $desc ) {
				$article['description'] = $desc;
			}
			if ( '' !== $img['url'] ) {
				$article['image'] = array( '@id' => $img_id );
			}
			$cats = get_the_category( $post->ID );
			if ( ! empty( $cats ) && isset( $cats[0]->name ) ) {
				$article['articleSection'] = $cats[0]->name;
			}
			$graph[] = $article;
		}

		/* ---- FAQPage ---- */
		$pairs = $this->faq_pairs( $post );
		if ( ! empty( $pairs ) ) {
			$items = array();
			foreach ( $pairs as $p ) {
				$items[] = array(
					'@type'          => 'Question',
					'name'           => $p['q'],
					'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $p['a'] ),
				);
			}
			$graph[] = array(
				'@type'      => 'FAQPage',
				'@id'        => $url . '#faq',
				'mainEntity' => $items,
			);
		}

		/* ---- VideoObject(유튜브가 본문에 있을 때만) ---- */
		$vids = self::youtube_ids( (string) $post->post_content );
		foreach ( $vids as $i => $vid ) {
			$video = array(
				'@type'        => 'VideoObject',
				'@id'          => $url . '#video' . ( $i + 1 ),
				'name'         => $title,
				'thumbnailUrl' => 'https://i.ytimg.com/vi/' . $vid . '/hqdefault.jpg',
				'embedUrl'     => 'https://www.youtube.com/embed/' . $vid,
			);
			if ( '' !== $desc ) {
				$video['description'] = $desc;
			}
			if ( '' !== $published ) {
				$video['uploadDate'] = $published;
			}
			$graph[] = $video;
		}

		/* ---- BreadcrumbList(홈 › 카테고리 › 글) ---- */
		if ( ! empty( $s['breadcrumb'] ) ) {
			$crumbs = $this->breadcrumb_items( $post, $home, $title, $url );
			if ( count( $crumbs ) > 1 ) {
				$graph[] = array(
					'@type'           => 'BreadcrumbList',
					'@id'             => $url . '#breadcrumb',
					'itemListElement' => $crumbs,
				);
			}
		}

		return $graph;
	}

	/** 홈 › 카테고리 › 글. */
	protected function breadcrumb_items( $post, $home, $title, $url ) {
		$items = array();
		$pos   = 1;
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos++,
			'name'     => '홈',
			'item'     => $home,
		);
		if ( 'post' === $post->post_type ) {
			$cats = get_the_category( $post->ID );
			if ( ! empty( $cats ) && isset( $cats[0] ) ) {
				$link = get_category_link( $cats[0]->term_id );
				if ( $link && ! is_wp_error( $link ) ) {
					$items[] = array(
						'@type'    => 'ListItem',
						'position' => $pos++,
						'name'     => $cats[0]->name,
						'item'     => (string) $link,
					);
				}
			}
		}
		$items[] = array(
			'@type'    => 'ListItem',
			'position' => $pos,
			'name'     => $title,
			'item'     => $url,
		);
		return $items;
	}

	/* ============================ FAQ 뽑기 ============================ */

	/**
	 * 글 하나에서 질문·답변 쌍을 뽑는다. 두 곳을 본다:
	 *  ① 옛 rank-math/faq-block 블록의 속성 JSON (이미 발행된 약 3,600편이 이 모양)
	 *  ② 제목 글자가 '자주 묻는 질문'·'자주묻는질문'·'FAQ' 인 H2/H3 아래의 한 단계 아래 제목(질문) + 그 뒤 문단(답)
	 * 질문이 0개면 FAQPage 를 안 붙인다.
	 *
	 * @param WP_Post $post
	 * @return array 각 항목 array('q'=>질문, 'a'=>답)
	 */
	public function faq_pairs( $post ) {
		$content = (string) $post->post_content;
		$pairs   = array();
		if ( false !== strpos( $content, 'rank-math/faq-block' ) && function_exists( 'parse_blocks' ) ) {
			$pairs = self::faq_from_blocks( parse_blocks( $content ) );
		}
		if ( empty( $pairs ) ) {
			$pairs = self::faq_from_headings( $content );
		}
		return $pairs;
	}

	/**
	 * ① 옛 블록의 속성 JSON 에서. (순수 함수 — parse_blocks 결과를 받는다)
	 *
	 * @param array $blocks parse_blocks() 결과.
	 * @return array
	 */
	public static function faq_from_blocks( $blocks ) {
		$out = array();
		foreach ( (array) $blocks as $block ) {
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::faq_from_blocks( $block['innerBlocks'] ) );
			}
			$name = isset( $block['blockName'] ) ? $block['blockName'] : '';
			if ( 'rank-math/faq-block' !== $name ) {
				continue;
			}
			$questions = isset( $block['attrs']['questions'] ) ? $block['attrs']['questions'] : array();
			foreach ( (array) $questions as $q ) {
				if ( isset( $q['visible'] ) && ! $q['visible'] ) {
					continue;
				}
				$title  = isset( $q['title'] ) ? self::to_text( $q['title'] ) : '';
				$answer = isset( $q['content'] ) ? self::to_text( $q['content'] ) : '';
				if ( '' !== $title && '' !== $answer ) {
					$out[] = array( 'q' => $title, 'a' => $answer );
				}
			}
		}
		return $out;
	}

	/**
	 * ② 제목형에서. (순수 함수)
	 *  - FAQ 제목(H1~H4)을 찾고, 그 구역은 '같은 레벨 이상의 제목'이 나올 때까지.
	 *  - 질문은 FAQ 제목보다 **정확히 한 단계 아래** 제목만 본다(H2 FAQ → H3 질문).
	 *  - 답은 그 질문 제목 뒤부터 다음 질문 제목(또는 구역 끝)까지. 태그를 걷어 평문으로.
	 *
	 * @param string $content 원본 post_content.
	 * @return array
	 */
	public static function faq_from_headings( $content ) {
		$content = (string) $content;
		if ( ! preg_match( '/<h([1-4])\b[^>]*>\s*(?:[^\w<]{0,3}\s*)?(?:자주\s*묻는\s*질문|자주묻는질문|FAQ)\b[^<]*<\/h\1>/iu', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$level       = (int) $m[1][0];
		$heading_end = $m[0][1] + strlen( $m[0][0] );
		$rest        = substr( $content, $heading_end );

		// 구역 끝 = 같은 레벨 이상(더 큰 제목)이 나오는 자리.
		if ( preg_match( '/<h[1-' . $level . ']\b/i', $rest, $nm, PREG_OFFSET_CAPTURE ) ) {
			$section = substr( $rest, 0, $nm[0][1] );
		} else {
			$section = $rest;
		}

		$q_tag = 'h' . ( $level + 1 );
		if ( ! preg_match_all( '/<' . $q_tag . '\b[^>]*>(.*?)<\/' . $q_tag . '>/is', $section, $qm, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
			return array();
		}

		$out   = array();
		$count = count( $qm );
		for ( $i = 0; $i < $count; $i++ ) {
			$q       = self::to_text( $qm[ $i ][1][0] );
			$a_start = $qm[ $i ][0][1] + strlen( $qm[ $i ][0][0] );
			$a_end   = ( $i + 1 < $count ) ? $qm[ $i + 1 ][0][1] : strlen( $section );
			$a       = self::to_text( substr( $section, $a_start, $a_end - $a_start ) );
			if ( '' !== $q && '' !== $a ) {
				$out[] = array( 'q' => $q, 'a' => $a );
			}
		}
		return $out;
	}

	/** HTML → 평문(태그를 걷고 공백 정리). */
	protected static function to_text( $html ) {
		$t = wp_strip_all_tags( (string) $html, true );
		$t = html_entity_decode( $t, ENT_QUOTES, 'UTF-8' );
		$t = str_replace( "\xC2\xA0", ' ', $t );
		return trim( preg_replace( '/\s+/u', ' ', $t ) );
	}

	/* ============================ 유튜브 ============================ */

	/**
	 * 본문에서 유튜브 동영상 id 를 뽑는다(중복 제거). 없으면 빈 배열 → VideoObject 를 안 붙인다.
	 * (순수 함수)
	 *
	 * @param string $content 원본 post_content.
	 * @return string[]
	 */
	public static function youtube_ids( $content ) {
		$content = (string) $content;
		$ids     = array();
		$patterns = array(
			'#youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_\-]{6,20})#i',
			'#youtu\.be/([A-Za-z0-9_\-]{6,20})#i',
			'#youtube\.com/watch\?(?:[^"\'\s<>]*&(?:amp;)?)?v=([A-Za-z0-9_\-]{6,20})#i',
			'#youtube\.com/shorts/([A-Za-z0-9_\-]{6,20})#i',
		);
		foreach ( $patterns as $re ) {
			if ( preg_match_all( $re, $content, $m ) ) {
				foreach ( $m[1] as $id ) {
					if ( ! in_array( $id, $ids, true ) ) {
						$ids[] = $id;
					}
				}
			}
		}
		return $ids;
	}
}
