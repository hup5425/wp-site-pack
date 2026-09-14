<?php
/**
 * SEO 모듈 검산 — 워드프레스 없이 돌려 보는 확인.  `php tools/seo_검산.php`
 *
 * 워드프레스가 없어도 확인할 수 있는 **순수 함수**만 본다:
 *   · 설명문 자르기(WSP_SEO_Head::cut · plain_text · first_image_url · iso8601)
 *   · 설명문에 쓸 첫 문단 고르기(WSP_SEO_Head::paragraphs · first_paragraph)
 *   · 로봇 메타 값(WSP_SEO_Head::robots_values — `-1` 이 문자열인지)
 *   · 글 시각을 사이트 시간대로(WSP_SEO_Head::published_iso · modified_iso)
 *   · 2쪽부터의 주소(WSP_SEO_Head::paged_url)
 *   · FAQ 뽑기(WSP_SEO_Schema::faq_from_blocks · faq_from_headings)
 *   · 유튜브 id 뽑기(WSP_SEO_Schema::youtube_ids)
 *   · 사이트맵 나누기·주소 가르기(WSP_SEO_Sitemap::chunk_total · chunk_of_index · parse_path)
 *   · 사이트맵의 홈 한 줄(WSP_SEO_Sitemap::home_url_entry_xml)
 *   · robots.txt 의 Sitemap 줄(WSP_SEO_Sitemap::add_sitemap_line)
 *   · Rank Math 설정 이어받기(WSP_Mod_SEO::migrate_from_rank_math)
 *
 * 워드프레스 함수는 몇 개만 여기서 흉내 낸다(아래 「흉내 낸 함수」). 화면·DB 가 필요한 것은
 * 여기서 못 본다 — 그건 zau.kr 에서 눈으로 확인한다.
 *
 * @package wp-site-pack
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "명령줄에서 돌려 주세요: php tools/seo_검산.php\n" );
}

/* ------------------------------ 흉내 낸 함수 ------------------------------ */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );
		if ( $remove_breaks ) {
			$text = preg_replace( '/[\r\n\t ]+/', ' ', $text );
		}
		return trim( $text );
	}
}

if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $content ) {
		return preg_replace( '/\[[^\]]*\]/', '', (string) $content );
	}
}

if ( ! function_exists( 'excerpt_remove_blocks' ) ) {
	function excerpt_remove_blocks( $content ) {
		// 진짜 함수는 요약에 안 맞는 블록을 걷어 낸다. 여기서는 블록 주석만 지운다.
		return preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/', '', (string) $content );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) {
		return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $s ) ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $s ) {
		$s = trim( (string) $s );
		return preg_match( '#^https?://#i', $s ) ? $s : '';
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $s ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES );
	}
}

/** 사이트 시간대(여기서는 +09:00)로 바꾼 시각 — 진짜 get_the_date 가 하는 일. */
function 검산_사이트시각( $local ) {
	$local = (string) $local;
	return ( '' === $local ) ? '' : str_replace( ' ', 'T', $local ) . '+09:00';
}
if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $format, $post ) {
		return 검산_사이트시각( $post->post_date );
	}
	function get_the_modified_date( $format, $post ) {
		return 검산_사이트시각( $post->post_modified );
	}
}

/**
 * 워드프레스 wp_robots() 가 로봇 메타를 찍는 방식 그대로.
 * 값이 **문자열**일 때만 `이름:값`, 그 밖에 참이면 이름만.
 */
function 검산_로봇줄( $robots ) {
	$out = array();
	foreach ( $robots as $이름 => $값 ) {
		if ( is_string( $값 ) ) {
			$out[] = $이름 . ':' . $값;
		} elseif ( $값 ) {
			$out[] = $이름;
		}
	}
	return implode( ', ', $out );
}

/** 모듈 뼈대 — default_settings()·sanitize() 만 보려고 최소한만 흉내 낸다. */
abstract class WSP_Module {
	abstract public function id();
	abstract public function name();
	abstract public function desc();
	public function default_settings() { return array(); }
	abstract public function register();
	public function sanitize( $input ) { return array(); }
	public function on_activate() {}
	public function on_deactivate() {}
}

require_once __DIR__ . '/../includes/modules/seo/class-seo-head.php';
require_once __DIR__ . '/../includes/modules/seo/class-seo-schema.php';
require_once __DIR__ . '/../includes/modules/seo/class-seo-sitemap.php';
require_once __DIR__ . '/../includes/modules/class-mod-seo.php';

/* ------------------------------ 검산 틀 ------------------------------ */

$GLOBALS['ok']   = 0;
$GLOBALS['fail'] = array();

function 확인( $이름, $얻은것, $바란것 ) {
	if ( $얻은것 === $바란것 ) {
		$GLOBALS['ok']++;
		return;
	}
	$GLOBALS['fail'][] = sprintf(
		"  ✗ %s\n     얻은 것: %s\n     바란 것: %s",
		$이름,
		var_export( $얻은것, true ),
		var_export( $바란것, true )
	);
}

/* ======= 0. 기본값과 저장 검증의 열쇠가 1:1 인가 (하나라도 빠지면 그 설정이 사라진다) ======= */

$모듈   = new WSP_Mod_SEO();
$기본값 = $모듈->default_settings();
$저장값 = $모듈->sanitize( array() );  // 빈 입력이라도 열쇠는 전부 나와야 한다.

$기본열쇠 = array_keys( $기본값 );
$저장열쇠 = array_keys( $저장값 );
sort( $기본열쇠 );
sort( $저장열쇠 );
확인( 'default_settings() 와 sanitize() 의 열쇠가 1:1', $저장열쇠, $기본열쇠 );

// 빈 입력일 때 sanitize 가 돌려주는 값이 기본값과 같은 뜻인지(체크칸은 0, 고르는 칸은 기본 항목).
확인( '빈 입력이면 글 종류는 BlogPosting', $저장값['article_type'], 'BlogPosting' );
확인( '빈 입력이면 첨부파일 페이지는 글로 보내기', $저장값['attachment_page'], 'to_post' );
확인( '구분 기호를 비우면 - 로', $모듈->sanitize( array( 'title_separator' => '   ' ) )['title_separator'], '-' );
확인( '한 파일에 글 수는 10~2000 안으로', $모듈->sanitize( array( 'sitemap_per_page' => 999999 ) )['sitemap_per_page'], 2000 );
확인(
	'연관 채널 주소는 주소 모양인 줄만 남긴다',
	$모듈->sanitize( array( 'same_as' => "https://blog.naver.com/a\n엉뚱한 글\n\nhttps://youtube.com/@b" ) )['same_as'],
	"https://blog.naver.com/a\nhttps://youtube.com/@b"
);
확인( '빈 입력이면 홈 제목 태그라인은 꺼짐(체크박스 관례 — 기본값 1과는 별개)', $저장값['home_title_tagline'], 0 );

/* ============================ 1. 설명문 자르기 ============================ */

확인(
	'160자 안이면 그대로',
	WSP_SEO_Head::cut( '짧은 설명문입니다.' ),
	'짧은 설명문입니다.'
);

확인(
	'공백은 한 칸으로 정리',
	WSP_SEO_Head::cut( "여러   줄과\n\n빈칸이   섞인   글" ),
	'여러 줄과 빈칸이 섞인 글'
);

// 문장 끝이 60자 뒤 → 그 문장 끝에서 자른다(… 안 붙음).
$문장 = str_repeat( '가', 70 ) . '. ' . str_repeat( '나', 120 );
확인(
	'160자 안 마지막 문장 끝에서 자름',
	WSP_SEO_Head::cut( $문장 ),
	str_repeat( '가', 70 ) . '.'
);

// 문장 끝이 60자 이전뿐 → 그냥 160자에서 자르고 …
$이른문장 = str_repeat( '다', 20 ) . '. ' . str_repeat( '라', 300 );
$잘린것   = WSP_SEO_Head::cut( $이른문장 );
확인( '문장 끝이 60자 이전이면 … 로 끝남', mb_substr( $잘린것, -1, 1, 'UTF-8' ), '…' );
확인( '그때 길이는 160자 + …', mb_strlen( $잘린것, 'UTF-8' ), 161 );

// 소수점을 문장 끝으로 보지 않는다(뒤가 공백일 때만 문장 끝).
$소수   = str_repeat( '마', 65 ) . '3.5퍼센트' . str_repeat( '바', 200 );
$소수결과 = WSP_SEO_Head::cut( $소수 );
확인( '소수점에서 자르지 않음(길이가 그대로 160자 + …)', mb_strlen( $소수결과, 'UTF-8' ), 161 );
확인( '소수점에서 자르지 않음(3.5 가 살아 있음)', mb_strpos( $소수결과, '3.5퍼센트' ), 65 );

확인(
	'원본 본문 → 평문(숏코드·태그·블록 주석 걷기)',
	WSP_SEO_Head::plain_text( "<!-- wp:paragraph -->\n<p>첫 <strong>문장</strong>입니다.</p>\n<!-- /wp:paragraph -->[shortcode]" ),
	'첫 문장입니다.'
);

확인(
	'본문 첫 사진 주소',
	WSP_SEO_Head::first_image_url( '<p>글</p><img class="x" src="https://a.kr/1.jpg" alt="">' ),
	'https://a.kr/1.jpg'
);
확인( '사진이 없으면 빈 값', WSP_SEO_Head::first_image_url( '<p>글</p>' ), '' );

확인( 'GMT → ISO 8601', WSP_SEO_Head::iso8601( '2026-09-14 01:02:03' ), '2026-09-14T01:02:03+00:00' );
확인( '빈 시각은 빈 값', WSP_SEO_Head::iso8601( '0000-00-00 00:00:00' ), '' );

/* ================= 1-1. 설명문은 **첫 문단**까지만 (zau.kr 실측) ================= */

// Rank Math 는 첫 단락 「…정리합니다.」에서 끝냈는데 우리는 다음 단락(버튼 문구)까지 이어 붙였다.
$본문블록 = "<!-- wp:paragraph -->\n<p>2026년 청년 월세 지원의 신청 방법과 필요한 서류를 한눈에 보기 좋게 정리합니다.</p>\n<!-- /wp:paragraph -->\n"
	. "<!-- wp:buttons -->\n<div class=\"wp-block-buttons\"><a class=\"wp-block-button__link\">신청하러 가기</a></div>\n<!-- /wp:buttons -->\n"
	. "<!-- wp:paragraph -->\n<p>두 번째 문단입니다.</p>\n<!-- /wp:paragraph -->";
확인(
	'첫 문단만 쓴다(다음 문단·버튼 문구는 안 붙는다)',
	WSP_SEO_Head::first_paragraph( $본문블록 ),
	'2026년 청년 월세 지원의 신청 방법과 필요한 서류를 한눈에 보기 좋게 정리합니다.'
);

확인(
	'블록이 없는 옛 글은 첫 <p> 태그',
	WSP_SEO_Head::first_paragraph( '<h2>소제목</h2><p>옛 글의 첫 문단입니다. 블록이 없는 글에서도 여기까지가 설명문이 됩니다.</p><p>둘째 문단.</p>' ),
	'옛 글의 첫 문단입니다. 블록이 없는 글에서도 여기까지가 설명문이 됩니다.'
);

확인(
	'블록도 <p> 도 없으면 첫 줄바꿈 전까지',
	WSP_SEO_Head::first_paragraph( "줄만 있는 글의 첫 줄입니다. 이 줄이 설명문이 됩니다.\n둘째 줄." ),
	'줄만 있는 글의 첫 줄입니다. 이 줄이 설명문이 됩니다.'
);

확인(
	'첫 문단이 30자 미만이면 다음 문단을 이어 붙인다',
	WSP_SEO_Head::first_paragraph( "<!-- wp:paragraph -->\n<p>안녕하세요.</p>\n<!-- /wp:paragraph -->\n<!-- wp:paragraph -->\n<p>오늘은 청년 월세 지원을 살펴봅니다.</p>\n<!-- /wp:paragraph -->" ),
	'안녕하세요. 오늘은 청년 월세 지원을 살펴봅니다.'
);

확인( '문단이 하나도 없으면 빈 값', WSP_SEO_Head::first_paragraph( '   ' ), '' );

// 첫 문단이 160자를 넘으면 그 안의 마지막 문장 끝에서 자른다(설명문 규칙은 그대로).
$긴문단 = '<p>' . str_repeat( '가', 70 ) . '. ' . str_repeat( '나', 120 ) . '</p><p>둘째 문단</p>';
확인(
	'긴 첫 문단은 160자 안 마지막 문장 끝에서',
	WSP_SEO_Head::cut( WSP_SEO_Head::first_paragraph( $긴문단 ) ),
	str_repeat( '가', 70 ) . '.'
);

확인( '문단 목록 세기', count( WSP_SEO_Head::paragraphs( $본문블록 ) ), 2 );

/* ================= 1-2. 로봇 메타의 `-1` 은 문자열 (zau.kr 실측) ================= */

$로봇 = WSP_SEO_Head::robots_values( false );
확인( 'max-snippet 은 문자열 -1', $로봇['max-snippet'], '-1' );
확인( 'max-video-preview 도 문자열 -1', $로봇['max-video-preview'], '-1' );
확인(
	'그래서 화면에 -1 이 찍힌다(숫자로 두면 이름만 나갔다)',
	검산_로봇줄( $로봇 ),
	'index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1'
);
확인( '검색·작성자·첨부·404 는 noindex, follow', 검산_로봇줄( WSP_SEO_Head::robots_values( true ) ), 'noindex, follow' );

/* ================= 1-3. 글 시각은 사이트 시간대(+09:00) ================= */

$글 = (object) array(
	'post_date'         => '2026-09-14 10:02:03',
	'post_date_gmt'     => '2026-09-14 01:02:03',
	'post_modified'     => '2026-09-14 11:30:00',
	'post_modified_gmt' => '2026-09-14 02:30:00',
);
확인( '발행 시각은 사이트 시간대', WSP_SEO_Head::published_iso( $글 ), '2026-09-14T10:02:03+09:00' );
확인( '수정 시각도 사이트 시간대', WSP_SEO_Head::modified_iso( $글 ), '2026-09-14T11:30:00+09:00' );
확인( '글이 없으면 빈 값', WSP_SEO_Head::published_iso( null ), '' );

/* ================= 1-4. 2쪽부터의 주소(canonical·og:url) ================= */

확인( '1쪽은 그대로', WSP_SEO_Head::paged_url( 'https://zau.kr/category/food/', 1 ), 'https://zau.kr/category/food/' );
확인( '2쪽은 /page/2/', WSP_SEO_Head::paged_url( 'https://zau.kr/category/food/', 2 ), 'https://zau.kr/category/food/page/2/' );
확인( '쿼리가 붙은 주소는 paged 로', WSP_SEO_Head::paged_url( 'https://zau.kr/?s=test', 3 ), 'https://zau.kr/?s=test&paged=3' );
확인( '이미 있던 paged 는 하나만', WSP_SEO_Head::paged_url( 'https://zau.kr/?s=test&paged=2', 3 ), 'https://zau.kr/?s=test&paged=3' );

/* ============================ 2. FAQ 뽑기 ============================ */

// ① 옛 rank-math/faq-block 의 속성 JSON.
$blocks = array(
	array( 'blockName' => 'core/paragraph', 'attrs' => array(), 'innerBlocks' => array() ),
	array(
		'blockName'   => 'core/group',
		'attrs'       => array(),
		'innerBlocks' => array(
			array(
				'blockName'   => 'rank-math/faq-block',
				'attrs'       => array(
					'questions' => array(
						array( 'title' => '신청 기간은 언제인가요?', 'content' => '<p>3월 <strong>1일</strong>부터입니다.</p>', 'visible' => true ),
						array( 'title' => '숨긴 질문', 'content' => '<p>안 보임</p>', 'visible' => false ),
						array( 'title' => '비용은 얼마인가요?', 'content' => '<p>무료입니다.</p>' ),
					),
				),
				'innerBlocks' => array(),
			),
		),
	),
);
$쌍 = WSP_SEO_Schema::faq_from_blocks( $blocks );
확인( '옛 블록에서 질문 2개', count( $쌍 ), 2 );
확인( '질문 글자', $쌍[0]['q'], '신청 기간은 언제인가요?' );
확인( '답은 태그를 걷은 평문', $쌍[0]['a'], '3월 1일부터입니다.' );
확인( 'visible=false 는 뺌', $쌍[1]['q'], '비용은 얼마인가요?' );

// ② 제목형 — H2 '자주 묻는 질문' + H3 질문.
$본문 = '<h2>본문 소제목</h2><p>앞 글</p>'
	. '<h2>자주 묻는 질문</h2>'
	. '<p>아래를 참고하세요.</p>'
	. '<h3>언제 접수하나요?</h3><p>3월 1일부터입니다.</p>'
	. '<h3>비용이 있나요?</h3><p>없습니다.</p>'
	. '<h2>마무리</h2><h3>이건 FAQ 가 아님</h3><p>뒷글</p>';
$쌍2 = WSP_SEO_Schema::faq_from_headings( $본문 );
확인( '제목형에서 질문 2개(다음 H2 에서 끊김)', count( $쌍2 ), 2 );
확인( '제목형 질문', $쌍2[0]['q'], '언제 접수하나요?' );
확인( '제목형 답', $쌍2[1]['a'], '없습니다.' );

// H3 FAQ → 질문은 H4.
$본문3 = '<h3>FAQ</h3><h4>질문 하나</h4><p>답 하나</p><h2>다른 큰 제목</h2><h4>여긴 아님</h4>';
$쌍3   = WSP_SEO_Schema::faq_from_headings( $본문3 );
확인( 'H3 FAQ 면 H4 가 질문', count( $쌍3 ), 1 );
확인( 'H3 FAQ 의 답', $쌍3[0]['a'], '답 하나' );

확인( 'FAQ 제목이 없으면 0개', WSP_SEO_Schema::faq_from_headings( '<h2>소제목</h2><p>글</p>' ), array() );
확인( '답이 비면 그 질문은 안 담김', WSP_SEO_Schema::faq_from_headings( '<h2>FAQ</h2><h3>질문만 있음</h3>' ), array() );

/* ============================ 3. 유튜브 id ============================ */

확인(
	'embed 주소',
	WSP_SEO_Schema::youtube_ids( '<iframe src="https://www.youtube.com/embed/abc123XYZ_-"></iframe>' ),
	array( 'abc123XYZ_-' )
);
확인(
	'youtu.be 짧은 주소',
	WSP_SEO_Schema::youtube_ids( '<!-- wp:core-embed/youtube -->https://youtu.be/dQw4w9WgXcQ<!-- /wp -->' ),
	array( 'dQw4w9WgXcQ' )
);
확인(
	'watch?v= 주소',
	WSP_SEO_Schema::youtube_ids( 'https://www.youtube.com/watch?feature=share&v=zzzz1111AAA' ),
	array( 'zzzz1111AAA' )
);
확인(
	'같은 동영상은 한 번만',
	WSP_SEO_Schema::youtube_ids( 'https://youtu.be/dQw4w9WgXcQ 그리고 https://www.youtube.com/embed/dQw4w9WgXcQ' ),
	array( 'dQw4w9WgXcQ' )
);
확인( '동영상이 없으면 빈 배열', WSP_SEO_Schema::youtube_ids( '<p>글만 있습니다.</p>' ), array() );

/* ============================ 4. 사이트맵 ============================ */

확인( '글 0편이면 파일 0개', WSP_SEO_Sitemap::chunk_total( 0, 200 ), 0 );
확인( '글 1편이면 파일 1개', WSP_SEO_Sitemap::chunk_total( 1, 200 ), 1 );
확인( '글 200편이면 파일 1개', WSP_SEO_Sitemap::chunk_total( 200, 200 ), 1 );
확인( '글 201편이면 파일 2개', WSP_SEO_Sitemap::chunk_total( 201, 200 ), 2 );
확인( '글 1,400편이면 파일 7개', WSP_SEO_Sitemap::chunk_total( 1400, 200 ), 7 );

확인( '첫 글은 1번 파일', WSP_SEO_Sitemap::chunk_of_index( 0, 200 ), 1 );
확인( '200번째 글은 1번 파일', WSP_SEO_Sitemap::chunk_of_index( 199, 200 ), 1 );
확인( '201번째 글은 2번 파일', WSP_SEO_Sitemap::chunk_of_index( 200, 200 ), 2 );

확인( '/sitemap_index.xml', WSP_SEO_Sitemap::parse_path( 'sitemap_index.xml' ), array( 'what' => 'index' ) );
확인( '/post-sitemap3.xml', WSP_SEO_Sitemap::parse_path( '/post-sitemap3.xml' ), array( 'what' => 'post', 'page' => 3 ) );
확인( '/page-sitemap.xml', WSP_SEO_Sitemap::parse_path( 'page-sitemap.xml' ), array( 'what' => 'page' ) );
확인( '/category-sitemap.xml', WSP_SEO_Sitemap::parse_path( 'category-sitemap.xml' ), array( 'what' => 'category' ) );
확인( '사이트맵이 아닌 주소', WSP_SEO_Sitemap::parse_path( '2026/09/글제목' ), null );
확인( '옛 주소는 여기서 안 잡는다(301 로 따로 처리)', WSP_SEO_Sitemap::parse_path( 'wp-sitemap.xml' ), null );

/* ---- post-sitemap1.xml 맨 앞의 홈 한 줄 (zau.kr 실측: Rank Math 도 여기에 홈을 넣어 201개였다) ---- */

확인(
	'홈 한 줄(loc + lastmod)',
	WSP_SEO_Sitemap::home_url_entry_xml( 'https://zau.kr/', '2026-09-14T11:30:00+09:00' ),
	"\t<url>\n\t\t<loc>https://zau.kr/</loc>\n\t\t<lastmod>2026-09-14T11:30:00+09:00</lastmod>\n\t</url>\n"
);
확인(
	'수정 시각을 모르면 lastmod 를 안 넣는다',
	WSP_SEO_Sitemap::home_url_entry_xml( 'https://zau.kr/', '' ),
	"\t<url>\n\t\t<loc>https://zau.kr/</loc>\n\t</url>\n"
);
// 홈은 **글 수로 세지 않는다** — 그래서 글 200편이면 파일은 그대로 1개(주소만 201개)다.
확인( '홈을 넣어도 파일 개수는 그대로', WSP_SEO_Sitemap::chunk_total( 200, 200 ), 1 );

/* ---- robots.txt 의 Sitemap 줄 (워드프레스 기본 사이트맵을 끄면 이 줄이 사라졌다) ---- */

확인(
	'Sitemap 줄이 없으면 끝에 더한다',
	WSP_SEO_Sitemap::add_sitemap_line( "User-agent: *\nDisallow: /wp-admin/\n", 'https://zau.kr/sitemap_index.xml' ),
	"User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://zau.kr/sitemap_index.xml\n"
);
확인(
	'이미 있으면 그대로 둔다',
	WSP_SEO_Sitemap::add_sitemap_line( "User-agent: *\n\nSitemap: https://zau.kr/다른사이트맵.xml\n", 'https://zau.kr/sitemap_index.xml' ),
	"User-agent: *\n\nSitemap: https://zau.kr/다른사이트맵.xml\n"
);
확인(
	'대소문자가 달라도 있는 것으로 본다',
	WSP_SEO_Sitemap::add_sitemap_line( "sitemap: https://zau.kr/a.xml", 'https://zau.kr/sitemap_index.xml' ),
	'sitemap: https://zau.kr/a.xml'
);

/* ============================ 5. Rank Math 설정 이어받기 ============================ */

// zau.kr 실측값.
$랭크매스 = array(
	'titles'  => array(
		'website_name'                 => 'zau',
		'knowledgegraph_type'           => 'person',
		'knowledgegraph_name'           => 'zau',
		'title_separator'               => '-',
		'pt_post_default_article_type'  => 'BlogPosting',
		'author_robots'                 => array( 'noindex' ),
		'homepage_title'                => '%sitename% %page% %sep% %sitedesc%', // 치환 변수 — 건너뛴다.
	),
	'general' => array( 'attachment_redirect_urls' => 'on' ),
	'sitemap' => array( 'items_per_page' => 200 ),
);
$기본설정 = $모듈->default_settings();
$이어받기 = WSP_Mod_SEO::migrate_from_rank_math( $기본설정, $기본설정, $랭크매스 );

확인( '사이트 이름을 이어받는다', $이어받기['settings']['site_name'], 'zau' );
확인( '조직 이름을 이어받는다', $이어받기['settings']['org_name'], 'zau' );
확인( '옮긴 것이 화면에 보일 목록으로 남는다', $이어받기['moved'], array( '사이트 이름' => 'zau', '조직 이름' => 'zau' ) );
확인( '값이 같은 칸(구분 기호·글 종류·글 수)은 옮긴 것으로 세지 않는다', isset( $이어받기['moved']['구분 기호'] ), false );

// 이미 고쳐 둔 칸은 덮어쓰지 않는다.
$내설정 = $기본설정;
$내설정['site_name'] = '다시쓰기';
$지킴 = WSP_Mod_SEO::migrate_from_rank_math( $내설정, $기본설정, $랭크매스 );
확인( '내가 고쳐 둔 칸은 그대로', $지킴['settings']['site_name'], '다시쓰기' );

// %…% 치환 변수가 든 값은 건너뛴다.
$변수 = WSP_Mod_SEO::migrate_from_rank_math(
	$기본설정,
	$기본설정,
	array( 'titles' => array( 'homepage_description' => '%sitedesc%' ), 'general' => array(), 'sitemap' => array() )
);
확인( '치환 변수가 든 값은 안 옮긴다', $변수['settings']['site_description'], '' );

// 한 파일에 글 수는 10~2000 안으로.
$글수 = WSP_Mod_SEO::migrate_from_rank_math(
	$기본설정,
	$기본설정,
	array( 'titles' => array(), 'general' => array(), 'sitemap' => array( 'items_per_page' => 5000 ) )
);
확인( '이어받은 글 수도 2000 안으로', $글수['settings']['sitemap_per_page'], 2000 );

확인( 'Rank Math 설정이 없으면 아무것도 안 옮긴다', WSP_Mod_SEO::migrate_from_rank_math( $기본설정, $기본설정, array() )['moved'], array() );

/* ---- 구분 기호의 HTML 엔티티를 풀어서 이어받는다 (benefitf.com 실측 `&bull;`) ---- */

$벤핏에프_구분기호 = WSP_Mod_SEO::migrate_from_rank_math(
	$기본설정,
	$기본설정,
	array( 'titles' => array( 'title_separator' => '&bull;' ), 'general' => array(), 'sitemap' => array() )
);
확인( '엔티티로 저장된 구분 기호는 풀어서 이어받는다', $벤핏에프_구분기호['settings']['title_separator'], '•' );
확인( '이어받은 구분 기호에 엔티티 글자(&·;)가 남지 않는다', strpbrk( $벤핏에프_구분기호['settings']['title_separator'], '&;' ), false );

/* ---- 홈 제목에 태그라인을 붙일지는 Rank Math homepage_title 의 %sitedesc% 유무로 (2026-09-14 실측) ---- */

$자우_홈제목 = WSP_Mod_SEO::migrate_from_rank_math(
	$기본설정,
	$기본설정,
	array( 'titles' => array( 'homepage_title' => '%sitename% %page% %sep% %sitedesc%' ), 'general' => array(), 'sitemap' => array() )
);
확인( '%sitedesc% 가 있으면 태그라인 붙이기는 그대로 켬(기본값과 같아 옮긴 것으로 안 셈)', $자우_홈제목['settings']['home_title_tagline'], 1 );
확인( '값이 기본값과 같으면 옮긴 목록에 안 남는다', isset( $자우_홈제목['moved']['홈 제목에 태그라인 붙이기'] ), false );

$apt뷰_홈제목 = WSP_Mod_SEO::migrate_from_rank_math(
	$기본설정,
	$기본설정,
	array( 'titles' => array( 'homepage_title' => '%sitename% %page%' ), 'general' => array(), 'sitemap' => array() )
);
확인( '%sitedesc% 가 없으면 태그라인 붙이기를 끈다(apt-view.com)', $apt뷰_홈제목['settings']['home_title_tagline'], 0 );
확인( '끈 것이 옮긴 목록에 남는다', $apt뷰_홈제목['moved']['홈 제목에 태그라인 붙이기'], '끔' );

$코어비즈_홈제목 = WSP_Mod_SEO::migrate_from_rank_math(
	$기본설정,
	$기본설정,
	array( 'titles' => array( 'homepage_title' => '%sitename%' ), 'general' => array(), 'sitemap' => array() )
);
확인( '%sitedesc% 가 없으면 태그라인 붙이기를 끈다(coreabiz)', $코어비즈_홈제목['settings']['home_title_tagline'], 0 );

// 이미 사장님이 꺼 둔 칸은 Rank Math 값이 %sitedesc% 를 갖고 있어도 건드리지 않는다.
$내설정_홈제목 = $기본설정;
$내설정_홈제목['home_title_tagline'] = 0;
$지킴_홈제목 = WSP_Mod_SEO::migrate_from_rank_math(
	$내설정_홈제목,
	$기본설정,
	array( 'titles' => array( 'homepage_title' => '%sitename% %sep% %sitedesc%' ), 'general' => array(), 'sitemap' => array() )
);
확인( '내가 꺼 둔 칸은 그대로', $지킴_홈제목['settings']['home_title_tagline'], 0 );

/* ============================ 6. 제목 조각 잇기 · 엔티티 이스케이프 ============================ */

확인( '빈 조각은 버리고 이어붙인다', WSP_SEO_Head::join_title( array( '글 제목', '', '사이트' ), '-' ), '글 제목 - 사이트' );
확인( '조각이 하나뿐이면 구분 기호 없이', WSP_SEO_Head::join_title( array( '사이트', '' ), '-' ), '사이트' );
확인( '조각이 모두 비면 빈 문자열', WSP_SEO_Head::join_title( array( '', '' ), '-' ), '' );

확인( '조각의 태그를 걷는다', WSP_SEO_Head::part( '<b>굵게</b> 글자' ), '굵게 글자' );
확인( '조각의 HTML 엔티티를 되살린다', WSP_SEO_Head::part( 'A &amp; B' ), 'A & B' );

// 구분 기호에 엔티티가 그대로 남아 있으면(디코드를 안 했다면) 워드프레스가 <title> 을 낼 때
// esc_html 로 한 번 더 이스케이프해 `&amp;bull;` 로 깨진다 — 대조군.
확인(
	'엔티티를 안 푼 구분 기호로 이으면 출력 때 두 번 이스케이프된다(대조군 — 우리는 이렇게 안 함)',
	esc_html( WSP_SEO_Head::join_title( array( 'A', 'B' ), '&bull;' ) ),
	'A &amp;bull; B'
);
// migrate_from_rank_math 가 미리 풀어 둔 구분 기호(•)로 이으면 한 번만 이스케이프되어 깨지지 않는다.
확인(
	'엔티티를 미리 푼 구분 기호로 이으면 출력 때 한 번만 이스케이프되어 그대로',
	esc_html( WSP_SEO_Head::join_title( array( 'A', 'B' ), $벤핏에프_구분기호['settings']['title_separator'] ) ),
	'A • B'
);

/* ============================ 7. 첨부파일 404 → 슬러그 판정 ============================ */

확인( 'zau.kr 실측 `/3095/` 형태에서 슬러그를 뽑는다', WSP_Mod_SEO::attachment_slug_from_path( '/3095/' ), '3095' );
확인( '깊은 경로면 마지막 조각만', WSP_Mod_SEO::attachment_slug_from_path( '/2026/09/사진-이름/' ), '사진-이름' );
확인( '쿼리 문자열은 버린다', WSP_Mod_SEO::attachment_slug_from_path( '/사진/?utm=1&x=2' ), '사진' );
확인( '앞뒤 빗금이 없어도 동일', WSP_Mod_SEO::attachment_slug_from_path( '사진' ), '사진' );
확인( '도메인이 붙어 있어도 마지막 조각만', WSP_Mod_SEO::attachment_slug_from_path( 'https://zau.kr/2026/09/3095/' ), '3095' );
확인( 'URL 인코딩된 한글도 원문으로 되돌린다', WSP_Mod_SEO::attachment_slug_from_path( '/%EC%82%AC%EC%A7%84/' ), '사진' );
확인( '루트 주소는 빈 값(첨부파일이 아니다)', WSP_Mod_SEO::attachment_slug_from_path( '/' ), '' );
확인( '빈 문자열은 빈 값', WSP_Mod_SEO::attachment_slug_from_path( '' ), '' );

/* ------------------------------ 결과 ------------------------------ */

echo "\n";
if ( empty( $GLOBALS['fail'] ) ) {
	echo '✅ 검산 통과 — ' . $GLOBALS['ok'] . '개 모두 맞습니다.' . "\n";
	exit( 0 );
}
echo '❌ 틀린 것 ' . count( $GLOBALS['fail'] ) . '개 (맞은 것 ' . $GLOBALS['ok'] . '개)' . "\n";
echo implode( "\n", $GLOBALS['fail'] ) . "\n";
exit( 1 );
