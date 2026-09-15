<?php
/**
 * Ads 매니저 검산 — robots 의 Sitemap 줄 맞추기(WSP_Mod_Ads_Manager::with_sitemap_line).  `php tools/ads_manager_검산.php`
 *
 * 2026-09-15 실제로 있었던 모양 그대로:
 *   · wooun.kr — 다음 인증 줄을 넣다가 Sitemap 이 sitemap.xml(404)로 바뀐 것
 *   · bcbnews.kr — 옮기기 전 robots 의 wp-sitemap.xml(사이트팩 SEO 가 켜지면 301)
 *
 * @package wp-site-pack
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "명령줄에서 돌려 주세요: php tools/ads_manager_검산.php\n" );
}

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-module.php';
require __DIR__ . '/../includes/modules/class-mod-ads-manager.php';

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

$A   = 'WSP_Mod_Ads_Manager';
$url = 'https://wooun.kr/sitemap_index.xml';
$daum = '#DaumWebMasterTool:24b488a0c58e7ef4d5a7ac763b25ad80b22ae3fa2dd715b6a7e08f3ce73c9aec:sQJG/Wp2eeNqqbQj8JLcHA==';

$틀린 = "User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://wooun.kr/sitemap.xml\n\n" . $daum;
확인( 'wooun — sitemap.xml 줄을 sitemap_index.xml 로', $A::with_sitemap_line( $틀린, $url ),
	"User-agent: *\nAllow: /\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://wooun.kr/sitemap_index.xml\n\n" . $daum );

$맞는 = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://wooun.kr/sitemap_index.xml\n\n" . $daum;
확인( '이미 맞으면 그대로', $A::with_sitemap_line( $맞는, $url ), $맞는 );

$bcb = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://bcbnews.kr/wp-sitemap.xml\n\n#DaumWebMasterTool:cd96:sQJG";
확인( 'bcbnews — wp-sitemap.xml 도 바꾼다', $A::with_sitemap_line( $bcb, 'https://bcbnews.kr/sitemap_index.xml' ),
	"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://bcbnews.kr/sitemap_index.xml\n\n#DaumWebMasterTool:cd96:sQJG" );

$두줄 = "User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://a.kr/wp-sitemap.xml\nSitemap: https://a.kr/news-sitemap.xml";
확인( 'Sitemap 줄이 여럿이면 하나로', $A::with_sitemap_line( $두줄, 'https://a.kr/sitemap_index.xml' ),
	"User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://a.kr/sitemap_index.xml" );

$없음 = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\n" . $daum;
확인( 'Sitemap 줄이 없으면 인증 줄 앞에 넣는다', $A::with_sitemap_line( $없음, $url ),
	"User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://wooun.kr/sitemap_index.xml\n\n" . $daum );

$인증없음 = "User-agent: *\nDisallow: /wp-admin/";
확인( '인증 줄도 없으면 끝에 붙인다', $A::with_sitemap_line( $인증없음, $url ),
	"User-agent: *\nDisallow: /wp-admin/\n\nSitemap: https://wooun.kr/sitemap_index.xml" );

확인( '주소가 비면 손대지 않는다', $A::with_sitemap_line( $틀린, '' ), $틀린 );
확인( 'CRLF 줄바꿈도', $A::with_sitemap_line( str_replace( "\n", "\r\n", $맞는 ), $url ), $맞는 );

if ( $실패 ) {
	echo "\n❌ 검산 실패 " . count( $실패 ) . "개 (통과 {$통과}개)\n\n" . implode( "\n\n", $실패 ) . "\n";
	exit( 1 );
}
echo "\n✅ 검산 통과 — {$통과}개 모두 맞습니다.\n";
