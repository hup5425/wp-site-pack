<?php
/**
 * SEO 모듈 검산 — 워드프레스 없이 돌려 보는 확인.  `php tools/seo_검산.php`
 *
 * 워드프레스가 없어도 확인할 수 있는 **순수 함수**만 본다:
 *   · 설명문 자르기(WSP_SEO_Head::cut · plain_text · first_image_url · iso8601)
 *   · FAQ 뽑기(WSP_SEO_Schema::faq_from_blocks · faq_from_headings)
 *   · 유튜브 id 뽑기(WSP_SEO_Schema::youtube_ids)
 *   · 사이트맵 나누기·주소 가르기(WSP_SEO_Sitemap::chunk_total · chunk_of_index · parse_path)
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

/* ------------------------------ 결과 ------------------------------ */

echo "\n";
if ( empty( $GLOBALS['fail'] ) ) {
	echo '✅ 검산 통과 — ' . $GLOBALS['ok'] . '개 모두 맞습니다.' . "\n";
	exit( 0 );
}
echo '❌ 틀린 것 ' . count( $GLOBALS['fail'] ) . '개 (맞은 것 ' . $GLOBALS['ok'] . '개)' . "\n";
echo implode( "\n", $GLOBALS['fail'] ) . "\n";
exit( 1 );
