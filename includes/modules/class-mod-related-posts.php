<?php
/**
 * 모듈: 관련 글 (함께 읽어보면 좋은 정보). Contextual Related Posts 대체.
 *  - 글 하단에 관련 글 그리드를 표시. 선정 기준·정렬·열 수·레이아웃·썸네일 비율 설정.
 *  - 숏코드 [wsp_related] 또는 본문 끝 자동 삽입.
 *
 * @package wp-site-pack
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WSP_Mod_Related_Posts extends WSP_Module {

	public function id()   { return 'related_posts'; }
	public function name() { return '관련 글'; }
	public function desc() { return '글 하단에 "함께 읽어보면 좋은 정보" 관련 글 섹션을 표시합니다.'; }
	public function icon() { return 'dashicons-grid-view'; }

	public function default_settings() {
		return array(
			'title'           => '함께 읽어보면 좋은 정보',
			'heading_style'   => 'bar',           // bar(왼쪽 굵은선) | plain | center | underline
			'accent'          => '#222222',       // 제목 강조색(막대/밑줄)
			'count'           => 10,
			'columns'         => 5,
			'layout'          => 'overlay',       // overlay(썸네일+제목오버레이) | card(썸네일위 제목아래) | text(텍스트만)
			'thumb_ratio'     => '4-3',           // 1-1 | 4-3 | 16-9
			'source'          => 'same_category', // same_category | same_tag | category | search | recent
			'source_category' => 0,
			'search_term'     => '',
			'order'           => 'recent',        // recent | oldest | random | popular
			'auto'            => 1,               // 본문 끝 자동 삽입
			'post_types'      => array( 'post' => 1 ),
			// 이전/다음 글 네비게이션.
			'nav_enabled'     => 0,
			'nav_position'    => 'below',         // above | below (관련글 기준)
			'nav_thumb'       => 1,
			'nav_prev_label'  => '이전 글',
			'nav_next_label'  => '다음 글',
		);
	}

	public function register() {
		add_shortcode( 'wsp_related', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		if ( ! empty( $this->settings()['auto'] ) ) {
			// 우선순위 15 — 소셜공유(20)보다 먼저라 관련글이 위에 온다.
			add_filter( 'the_content', array( $this, 'append_to_content' ), 15 );
		}
	}

	public function assets() {
		if ( is_admin() ) {
			return;
		}
		WSP_Assets::front_style( 'related-posts' );
	}

	public function append_to_content( $content ) {
		if ( is_singular() && in_the_loop() && is_main_query() ) {
			return $content . $this->render();
		}
		return $content;
	}

	public function shortcode( $atts ) {
		return $this->render();
	}

	/** 썸네일 비율 → CSS aspect-ratio 값. */
	protected function ratio_css( $r ) {
		$map = array( '1-1' => '1 / 1', '4-3' => '4 / 3', '16-9' => '16 / 9' );
		return isset( $map[ $r ] ) ? $map[ $r ] : '4 / 3';
	}

	/**
	 * 관련 글 ID 목록 조회(현재 글 기준).
	 *
	 * @return int[]
	 */
	protected function get_related_ids() {
		$s       = $this->settings();
		$current = get_queried_object_id();
		$types   = array_keys( array_filter( (array) $s['post_types'] ) );
		if ( empty( $types ) ) {
			$types = array( 'post' );
		}

		$args = array(
			'post_type'           => $types,
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, (int) $s['count'] ),
			'post__not_in'        => $current ? array( $current ) : array(),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		);

		// 선정 기준.
		switch ( $s['source'] ) {
			case 'same_category':
				$cats = $current ? wp_get_post_categories( $current ) : array();
				if ( $cats ) {
					$args['category__in'] = $cats;
				}
				break;
			case 'same_tag':
				$tags = $current ? wp_get_post_tags( $current, array( 'fields' => 'ids' ) ) : array();
				if ( $tags ) {
					$args['tag__in'] = $tags;
				}
				break;
			case 'category':
				if ( (int) $s['source_category'] > 0 ) {
					$args['cat'] = (int) $s['source_category'];
				}
				break;
			case 'search':
				if ( '' !== trim( (string) $s['search_term'] ) ) {
					$args['s'] = trim( (string) $s['search_term'] );
				}
				break;
			case 'recent':
			default:
				break;
		}

		// 정렬.
		switch ( $s['order'] ) {
			case 'oldest':
				$args['orderby'] = 'date';
				$args['order']   = 'ASC';
				break;
			case 'random':
				$args['orderby'] = 'rand';
				break;
			case 'popular':
				$args['orderby'] = 'comment_count';
				$args['order']   = 'DESC';
				break;
			case 'recent':
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
				break;
		}

		$q   = new WP_Query( $args );
		$ids = $q->posts;

		// 부족하면 최신 글로 보충(중복·현재글 제외).
		if ( count( $ids ) < (int) $s['count'] && 'recent' !== $s['source'] ) {
			$exclude = array_merge( $ids, $current ? array( $current ) : array() );
			$fill    = new WP_Query( array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => (int) $s['count'] - count( $ids ),
				'post__not_in'   => $exclude,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'fields'         => 'ids',
				'ignore_sticky_posts' => true,
			) );
			$ids = array_merge( $ids, $fill->posts );
		}

		return array_map( 'intval', $ids );
	}

	/** 관련 글 그리드 + 이전/다음 네비 조립. */
	public function render() {
		$grid = $this->build_grid();
		$nav  = $this->render_nav();
		if ( '' === $grid && '' === $nav ) {
			return '';
		}
		$pos = $this->settings()['nav_position'];
		return ( 'above' === $pos ) ? $nav . $grid : $grid . $nav;
	}

	/** 관련 글 그리드 HTML(비면 ''). */
	protected function build_grid() {
		$s   = $this->settings();
		$ids = $this->get_related_ids();
		if ( empty( $ids ) ) {
			return '';
		}

		$cols  = max( 1, min( 8, (int) $s['columns'] ) );
		$ratio = $this->ratio_css( $s['thumb_ratio'] );
		$layout = in_array( $s['layout'], array( 'overlay', 'card', 'text' ), true ) ? $s['layout'] : 'overlay';

		$hstyle = in_array( $s['heading_style'], array( 'bar', 'plain', 'center', 'underline' ), true ) ? $s['heading_style'] : 'bar';
		$accent = preg_match( '/^#[0-9a-fA-F]{3,6}$/', (string) $s['accent'] ) ? $s['accent'] : '#222222';

		$out  = '<div class="wsp-related wsp-related--' . esc_attr( $layout ) . '" style="--wsp-cols:' . $cols . ';--wsp-ratio:' . esc_attr( $ratio ) . ';--wsp-accent:' . esc_attr( $accent ) . '">';
		if ( '' !== trim( (string) $s['title'] ) ) {
			$out .= '<h3 class="wsp-related-title wsp-heading-' . esc_attr( $hstyle ) . '">' . esc_html( $s['title'] ) . '</h3>';
		}
		$out .= '<div class="wsp-related-grid">';

		foreach ( $ids as $id ) {
			$link  = get_permalink( $id );
			$title = get_the_title( $id );
			$thumb = ( 'text' !== $layout ) ? get_the_post_thumbnail_url( $id, 'medium' ) : '';

			$out .= '<a class="wsp-related-card" href="' . esc_url( $link ) . '">';
			if ( 'text' !== $layout ) {
				$out .= '<span class="wsp-related-thumb">';
				if ( $thumb ) {
					$out .= '<img src="' . esc_url( $thumb ) . '" alt="' . esc_attr( $title ) . '" loading="lazy">';
				} else {
					$out .= '<span class="wsp-related-noimg">' . esc_html( mb_substr( $title, 0, 2 ) ) . '</span>';
				}
				$out .= '</span>';
			}
			$out .= '<span class="wsp-related-label">' . esc_html( $title ) . '</span>';
			$out .= '</a>';
		}

		$out .= '</div></div>';
		return $out;
	}

	/** 이전/다음 글 네비게이션 HTML(설정 켜졌고 인접 글 있을 때). */
	protected function render_nav() {
		$s = $this->settings();
		if ( empty( $s['nav_enabled'] ) ) {
			return '';
		}
		$prev = get_previous_post();
		$next = get_next_post();
		if ( empty( $prev ) && empty( $next ) ) {
			return '';
		}
		$thumb = ! empty( $s['nav_thumb'] );
		$out   = '<div class="wsp-postnav' . ( $thumb ? ' has-thumb' : '' ) . '">';
		$out  .= $prev ? $this->nav_item( $prev, 'prev', (string) $s['nav_prev_label'], $thumb ) : '<span class="wsp-postnav-item wsp-postnav-empty"></span>';
		$out  .= $next ? $this->nav_item( $next, 'next', (string) $s['nav_next_label'], $thumb ) : '<span class="wsp-postnav-item wsp-postnav-empty"></span>';
		$out  .= '</div>';
		return $out;
	}

	protected function nav_item( $post, $dir, $label, $thumb ) {
		$link  = get_permalink( $post );
		$title = get_the_title( $post );
		$out   = '<a class="wsp-postnav-item wsp-postnav-' . esc_attr( $dir ) . '" href="' . esc_url( $link ) . '">';
		if ( $thumb ) {
			$t = get_the_post_thumbnail_url( $post->ID, 'thumbnail' );
			if ( $t ) {
				$out .= '<span class="wsp-postnav-thumb"><img src="' . esc_url( $t ) . '" alt="" loading="lazy"></span>';
			}
		}
		$out .= '<span class="wsp-postnav-text"><span class="wsp-postnav-label">' . esc_html( $label ) . '</span>';
		$out .= '<span class="wsp-postnav-title">' . esc_html( $title ) . '</span></span></a>';
		return $out;
	}

	public function sanitize( $input ) {
		$layout = isset( $input['layout'] ) ? sanitize_key( $input['layout'] ) : 'overlay';
		if ( ! in_array( $layout, array( 'overlay', 'card', 'text' ), true ) ) {
			$layout = 'overlay';
		}
		$ratio = isset( $input['thumb_ratio'] ) ? sanitize_key( $input['thumb_ratio'] ) : '4-3';
		if ( ! in_array( $ratio, array( '1-1', '4-3', '16-9' ), true ) ) {
			$ratio = '4-3';
		}
		$source = isset( $input['source'] ) ? sanitize_key( $input['source'] ) : 'same_category';
		if ( ! in_array( $source, array( 'same_category', 'same_tag', 'category', 'search', 'recent' ), true ) ) {
			$source = 'same_category';
		}
		$order = isset( $input['order'] ) ? sanitize_key( $input['order'] ) : 'recent';
		if ( ! in_array( $order, array( 'recent', 'oldest', 'random', 'popular' ), true ) ) {
			$order = 'recent';
		}

		$hstyle = isset( $input['heading_style'] ) ? sanitize_key( $input['heading_style'] ) : 'bar';
		if ( ! in_array( $hstyle, array( 'bar', 'plain', 'center', 'underline' ), true ) ) {
			$hstyle = 'bar';
		}
		$accent = isset( $input['accent'] ) && preg_match( '/^#[0-9a-fA-F]{3,6}$/', (string) $input['accent'] ) ? $input['accent'] : '#222222';

		return array(
			'title'           => isset( $input['title'] ) ? sanitize_text_field( (string) $input['title'] ) : '',
			'heading_style'   => $hstyle,
			'accent'          => $accent,
			'count'           => max( 1, min( 30, (int) ( $input['count'] ?? 10 ) ) ),
			'columns'         => max( 1, min( 8, (int) ( $input['columns'] ?? 5 ) ) ),
			'layout'          => $layout,
			'thumb_ratio'     => $ratio,
			'source'          => $source,
			'source_category' => max( 0, (int) ( $input['source_category'] ?? 0 ) ),
			'search_term'     => isset( $input['search_term'] ) ? sanitize_text_field( (string) $input['search_term'] ) : '',
			'order'           => $order,
			'auto'            => empty( $input['auto'] ) ? 0 : 1,
			'post_types'      => array( 'post' => empty( $input['type_post'] ) ? 0 : 1, 'page' => empty( $input['type_page'] ) ? 0 : 1 ),
			'nav_enabled'     => empty( $input['nav_enabled'] ) ? 0 : 1,
			'nav_position'    => ( isset( $input['nav_position'] ) && 'above' === $input['nav_position'] ) ? 'above' : 'below',
			'nav_thumb'       => empty( $input['nav_thumb'] ) ? 0 : 1,
			'nav_prev_label'  => isset( $input['nav_prev_label'] ) ? sanitize_text_field( (string) $input['nav_prev_label'] ) : '이전 글',
			'nav_next_label'  => isset( $input['nav_next_label'] ) ? sanitize_text_field( (string) $input['nav_next_label'] ) : '다음 글',
		);
	}

	public function render_settings() {
		$s = $this->settings();
		?>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>섹션 제목(소제목)</strong>
				<span class="wsp-row-help">예: 함께 읽어보면 좋은 정보 / 관련 글 / 이런 글은 어때요?</span></div>
			<div class="wsp-row-control"><input type="text" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" style="width:60%"></div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>제목 디자인</strong></div>
			<div class="wsp-row-control">
				<select name="heading_style">
					<option value="bar" <?php selected( $s['heading_style'], 'bar' ); ?>>왼쪽 굵은 막대(스샷 스타일)</option>
					<option value="underline" <?php selected( $s['heading_style'], 'underline' ); ?>>아래 밑줄</option>
					<option value="center" <?php selected( $s['heading_style'], 'center' ); ?>>가운데 정렬</option>
					<option value="plain" <?php selected( $s['heading_style'], 'plain' ); ?>>기본(장식 없음)</option>
				</select>
				&nbsp; 강조색: <input type="color" name="accent" value="<?php echo esc_attr( $s['accent'] ); ?>">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>표시 개수 / 한 줄 개수</strong>
				<span class="wsp-row-help">전체 몇 개를, 한 줄에 몇 개씩(열) 배치할지.</span></div>
			<div class="wsp-row-control">
				전체 <input type="number" name="count" min="1" max="30" value="<?php echo esc_attr( $s['count'] ); ?>"> 개 /
				한 줄에 <input type="number" name="columns" min="1" max="8" value="<?php echo esc_attr( $s['columns'] ); ?>"> 개
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>레이아웃</strong></div>
			<div class="wsp-row-control">
				<select name="layout">
					<option value="overlay" <?php selected( $s['layout'], 'overlay' ); ?>>썸네일 + 제목 오버레이(스샷 스타일)</option>
					<option value="card" <?php selected( $s['layout'], 'card' ); ?>>썸네일 위 · 제목 아래</option>
					<option value="text" <?php selected( $s['layout'], 'text' ); ?>>텍스트만(썸네일 없음)</option>
				</select>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>썸네일 비율</strong></div>
			<div class="wsp-row-control">
				<select name="thumb_ratio">
					<option value="1-1" <?php selected( $s['thumb_ratio'], '1-1' ); ?>>1:1 (정사각)</option>
					<option value="4-3" <?php selected( $s['thumb_ratio'], '4-3' ); ?>>4:3</option>
					<option value="16-9" <?php selected( $s['thumb_ratio'], '16-9' ); ?>>16:9 (와이드)</option>
				</select>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>관련 글 선정 기준</strong>
				<span class="wsp-row-help">현재 글 기준으로 어떤 글을 모을지.</span></div>
			<div class="wsp-row-control">
				<select name="source">
					<option value="same_category" <?php selected( $s['source'], 'same_category' ); ?>>같은 카테고리</option>
					<option value="same_tag" <?php selected( $s['source'], 'same_tag' ); ?>>같은 태그</option>
					<option value="category" <?php selected( $s['source'], 'category' ); ?>>특정 카테고리 지정</option>
					<option value="search" <?php selected( $s['source'], 'search' ); ?>>검색어 기준</option>
					<option value="recent" <?php selected( $s['source'], 'recent' ); ?>>전체(사이트 최신)</option>
				</select>
				<div style="margin-top:8px">
					특정 카테고리:
					<?php
					wp_dropdown_categories( array(
						'name'             => 'source_category',
						'selected'         => (int) $s['source_category'],
						'show_option_none' => '— 선택 —',
						'option_none_value' => 0,
						'hide_empty'       => 0,
					) );
					?>
				</div>
				<div style="margin-top:8px">
					검색어: <input type="text" name="search_term" value="<?php echo esc_attr( $s['search_term'] ); ?>" placeholder="예: 건강보험">
				</div>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>정렬</strong></div>
			<div class="wsp-row-control">
				<select name="order">
					<option value="recent" <?php selected( $s['order'], 'recent' ); ?>>최신순</option>
					<option value="oldest" <?php selected( $s['order'], 'oldest' ); ?>>오래된순</option>
					<option value="random" <?php selected( $s['order'], 'random' ); ?>>랜덤</option>
					<option value="popular" <?php selected( $s['order'], 'popular' ); ?>>인기순(댓글 많은 순)</option>
				</select>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>대상 글 종류</strong></div>
			<div class="wsp-row-control wsp-chips">
				<label class="wsp-chip"><input type="checkbox" name="type_post" value="1" <?php checked( ! empty( $s['post_types']['post'] ) ); ?>> 게시글</label>
				<label class="wsp-chip"><input type="checkbox" name="type_page" value="1" <?php checked( ! empty( $s['post_types']['page'] ) ); ?>> 페이지</label>
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>글 하단 자동 삽입</strong>
				<span class="wsp-row-help">끄면 숏코드로만 표시.</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="auto" value="1" <?php checked( $s['auto'], 1 ); ?>> 본문 끝에 자동 표시</label>
			</div>
		</div>

		<hr style="margin:22px 0">
		<h3 style="margin:0 0 8px">이전/다음 글 네비게이션</h3>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>네비게이션 표시</strong>
				<span class="wsp-row-help">글 하단에 이전 글/다음 글 이동 링크를 표시(테마 기본 네비와 중복되면 테마 것을 끄세요).</span></div>
			<div class="wsp-row-control">
				<label><input type="checkbox" name="nav_enabled" value="1" <?php checked( $s['nav_enabled'], 1 ); ?>> 이전/다음 글 네비 표시</label>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>위치 / 썸네일</strong></div>
			<div class="wsp-row-control">
				<select name="nav_position">
					<option value="below" <?php selected( $s['nav_position'], 'below' ); ?>>관련 글 아래</option>
					<option value="above" <?php selected( $s['nav_position'], 'above' ); ?>>관련 글 위</option>
				</select>
				&nbsp; <label><input type="checkbox" name="nav_thumb" value="1" <?php checked( $s['nav_thumb'], 1 ); ?>> 썸네일 표시</label>
			</div>
		</div>
		<div class="wsp-row">
			<div class="wsp-row-label"><strong>라벨 문구</strong></div>
			<div class="wsp-row-control">
				이전: <input type="text" name="nav_prev_label" value="<?php echo esc_attr( $s['nav_prev_label'] ); ?>" style="width:120px">
				다음: <input type="text" name="nav_next_label" value="<?php echo esc_attr( $s['nav_next_label'] ); ?>" style="width:120px">
			</div>
		</div>

		<div class="wsp-row">
			<div class="wsp-row-label"><strong>숏코드</strong></div>
			<div class="wsp-row-control"><code class="wsp-code wsp-copy" data-copy="[wsp_related]">[wsp_related]</code> 원하는 위치에 붙여넣기</div>
		</div>
		<?php
	}
}
