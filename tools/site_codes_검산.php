<?php
/**
 * 「소유 확인·분석 코드」 모듈 검산 — 워드프레스 없이.  `php tools/site_codes_검산.php`
 *
 * 순수 함수만 본다:
 *   · 값 다듬기(clean_verify · clean_ga4 · clean_adsense · clean_naver_wa · clean_clarity)
 *     — 21곳 GeneratePress 엘리먼츠·자식 테마에 실제로 있던 코드 모양 그대로(2026-09-15 조사)
 *   · 글자에서 코드 찾기·세기(find_codes) — 첫 화면에서 「두 번 나감」을 잡는지
 *   · 넣는 코드 모양(head_html · footer_html) — 한 번씩만, 다시 읽으면 같은 값
 *   · 빈 칸만 채우기(fill_empty) · 인증 파일 이름(is_verify_filename)
 *
 * @package wp-site-pack
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "명령줄에서 돌려 주세요: php tools/site_codes_검산.php\n" );
}

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-module.php';
require __DIR__ . '/../includes/modules/class-mod-site-codes.php';

$통과 = 0;
$실패 = array();
function 확인( $이름, $실제, $기대 ) {
	global $통과, $실패;
	if ( $실제 === $기대 ) {
		$통과++;
	} else {
		$실패[] = $이름 . "\n    기대: " . var_export( $기대, true ) . "\n    실제: " . var_export( $실제, true );
	}
}

$C = 'WSP_Mod_Site_Codes';

/* ================= 1. 실제 엘리먼츠 코드 모양 ================= */

$ga4_el = "<!-- Google tag (gtag.js) -->\n<script async src=\"https://www.googletagmanager.com/gtag/js?id=G-R0EHR1LHP3\"></script>\n<script>\n  window.dataLayer = window.dataLayer || [];\n  function gtag(){dataLayer.push(arguments);}\n  gtag('js', new Date());\n\n  gtag('config', 'G-R0EHR1LHP3');\n</script>";
$ads_el = '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-5315316668262651" crossorigin="anonymous"></script>';
$clar_el = "<script type=\"text/javascript\">\n    (function(c,l,a,r,i,t,y){\n        c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};\n        t=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;\n        y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);\n    })(window, document, \"clarity\", \"script\", \"jevybcgpw9\");\n</script>";
$nv_fn = "add_action('wp_footer', 'add_naveranalytics');\nfunction add_naveranalytics() { ?>\n<script type=\"text/javascript\" src=\"//wcs.naver.net/wcslog.js\"></script>\n<script type=\"text/javascript\">\nif(!wcs_add) var wcs_add = {};\nwcs_add[\"wa\"] = \"1656f4e0380aaa\";\nwcs_do();\n</script>\n<?php }";
$meta_el = '<meta name="naver-site-verification" content="0c5a532874f570451bb2a04ae5e8cd5b082e600c" />';

확인( 'GA4 — 엘리먼츠 코드 통째로', $C::clean_ga4( $ga4_el ), 'G-R0EHR1LHP3' );
확인( 'GA4 — 소문자로 넣어도 대문자', $C::clean_ga4( 'g-r0ehr1lhp3' ), 'G-R0EHR1LHP3' );
확인( 'GA4 — 엉뚱한 값은 빈 값', $C::clean_ga4( 'UA-12345-1' ), '' );
확인( '애드센스 — 로더 코드 통째로', $C::clean_adsense( $ads_el ), 'ca-pub-5315316668262651' );
확인( '애드센스 — pub- 만 넣어도', $C::clean_adsense( 'pub-5315316668262651' ), 'ca-pub-5315316668262651' );
확인( '애드센스 — 숫자만 넣어도', $C::clean_adsense( ' 5315316668262651 ' ), 'ca-pub-5315316668262651' );
확인( '클래리티 — 코드 통째로', $C::clean_clarity( $clar_el ), 'jevybcgpw9' );
확인( '클래리티 — ID 만', $C::clean_clarity( 'JEVYBCGPW9' ), 'jevybcgpw9' );
확인( '클래리티 — 따옴표 섞인 값은 거절', $C::clean_clarity( 'abc"def' ), '' );
확인( '네이버 애널리틱스 — 자식 테마 functions.php 모양', $C::clean_naver_wa( $nv_fn ), '1656f4e0380aaa' );
확인( '네이버 애널리틱스 — 값만', $C::clean_naver_wa( '7a8d3f5b2ca90c' ), '7a8d3f5b2ca90c' );
확인( '인증 — 메타 태그 통째로', $C::clean_verify( $meta_el ), '0c5a532874f570451bb2a04ae5e8cd5b082e600c' );
확인( '인증 — 구글 값(밑줄·하이픈 포함)', $C::clean_verify( 'X6RfH1sJUDFjwlAKZ5ZrLAoFJnZzfF4Ip2d_37d7Eqw' ), 'X6RfH1sJUDFjwlAKZ5ZrLAoFJnZzfF4Ip2d_37d7Eqw' );
확인( '인증 — 태그 끼워 넣기는 거절', $C::clean_verify( 'abc"><script>' ), '' );

/* ================= 2. 글자에서 찾기·세기 ================= */

$f = $C::find_codes( $ga4_el . $ads_el . $clar_el . $nv_fn . $meta_el );
확인( '찾기 — GA4', $f['ga4']['values'], array( 'G-R0EHR1LHP3' ) );
확인( '찾기 — 애드센스', $f['adsense']['values'], array( 'ca-pub-5315316668262651' ) );
확인( '찾기 — 클래리티', $f['clarity']['values'], array( 'jevybcgpw9' ) );
확인( '찾기 — 네이버 애널리틱스', $f['naver_wa']['values'], array( '1656f4e0380aaa' ) );
확인( '찾기 — 네이버 인증', $f['verify_naver']['values'], array( '0c5a532874f570451bb2a04ae5e8cd5b082e600c' ) );
확인( '찾기 — 없는 것은 빈 목록', $f['verify_bing']['values'], array() );

$두번 = $C::find_codes( $ga4_el . "\n" . $ga4_el );
확인( '세기 — GA4 가 두 곳에서 나오면 2', $두번['ga4']['count'], 2 );
확인( '세기 — 같은 값은 목록에 한 번', $두번['ga4']['values'], array( 'G-R0EHR1LHP3' ) );
$광고단위 = '<ins class="adsbygoogle" data-ad-client="ca-pub-5315316668262651" data-ad-slot="6742116982"></ins>';
확인( '세기 — 광고 단위(<ins>)는 로더로 안 센다', $C::find_codes( $광고단위 )['adsense']['count'], 0 );
확인( '세기 — 글 속 「G-2 등급」 같은 글자는 GA4 가 아니다', $C::find_codes( '<p>G-ABCDEFG 등급</p>' )['ga4']['count'], 0 );
$순서 = '<meta content="f682abc7f15f566fdb6b0e3dd676423ee52c44ae" name="naver-site-verification">';
확인( '찾기 — 메타 속성 순서가 바뀌어도', $C::find_codes( $순서 )['verify_naver']['values'], array( 'f682abc7f15f566fdb6b0e3dd676423ee52c44ae' ) );

/* ================= 3. 넣는 코드 ================= */

$s = array(
	'verify_google' => 'X6RfH1sJUDFjwlAKZ5ZrLAoFJnZzfF4Ip2d_37d7Eqw',
	'verify_naver'  => '9b7f7335fd1314455c4766bb584c0558e255c859',
	'verify_bing'   => '',
	'ga4'           => 'G-R8DY82RRGP',
	'adsense'       => 'ca-pub-9941112034500385',
	'naver_wa'      => '7f0cb07d1019b',
	'clarity'       => 'jewfwuki8h',
);
$머리 = $C::head_html( $s );
$발 = $C::footer_html( $s );
$다시 = $C::find_codes( $머리 . $발 );
foreach ( array( 'verify_google', 'verify_naver', 'ga4', 'adsense', 'naver_wa', 'clarity' ) as $k ) {
	확인( "넣은 코드를 다시 읽으면 같은 값 — $k", $다시[ $k ]['values'], array( $s[ $k ] ) );
	확인( "넣은 코드는 한 번씩 — $k", $다시[ $k ]['count'], 1 );
}
확인( '빙 인증은 비어 있으면 안 넣는다', $다시['verify_bing']['count'], 0 );
확인( '네이버 애널리틱스는 머리말이 아니라 푸터', strpos( $머리, 'wcslog' ), false );
확인( '아무 값도 없으면 머리말 코드 없음', $C::head_html( array() ), '' );
확인( '아무 값도 없으면 푸터 코드 없음', $C::footer_html( array() ), '' );
확인( '이상한 값은 넣지 않는다(태그 끼워 넣기)', strpos( $C::head_html( array( 'ga4' => '"><script>alert(1)</script>' ) ), 'alert' ), false );

/* ================= 4. 빈 칸만 채우기 · 인증 파일 이름 ================= */

$찾은것 = array(
	'ga4'     => array( array( 'value' => 'G-AAAAAAA1', 'source' => '엘리먼츠 #1' ) ),
	'clarity' => array( array( 'value' => 'abcdef1234', 'source' => '엘리먼츠 #2' ) ),
);
list( $채움, $한곳 ) = $C::fill_empty( array( 'ga4' => 'G-KEEPME01', 'clarity' => '' ), $찾은것 );
확인( '채우기 — 이미 넣은 값은 그대로', $채움['ga4'], 'G-KEEPME01' );
확인( '채우기 — 빈 칸은 찾은 값으로', $채움['clarity'], 'abcdef1234' );
확인( '채우기 — 채운 칸 목록', $한곳, array( 'clarity' => '엘리먼츠 #2' ) );
확인( '인증 파일 — 네이버', $C::is_verify_filename( 'naverbcb97e4623d0fa90a9d88599fd546595.html' ), true );
확인( '인증 파일 — 구글', $C::is_verify_filename( 'googleb3cd2bf785b8fdfe.html' ), true );
확인( '인증 파일 — 빙', $C::is_verify_filename( 'BingSiteAuth.xml' ), true );
확인( '인증 파일 — 다른 파일은 아님', $C::is_verify_filename( 'index.php' ), false );

/* ------------------------------ 결과 ------------------------------ */

if ( $실패 ) {
	echo "\n❌ 검산 실패 " . count( $실패 ) . "개 (통과 {$통과}개)\n\n" . implode( "\n\n", $실패 ) . "\n";
	exit( 1 );
}
echo "\n✅ 검산 통과 — {$통과}개 모두 맞습니다.\n";
