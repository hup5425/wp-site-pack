<?php
/**
 * 구글 인덱싱 API 검산 — 워드프레스 없이 순수 계산만 확인한다.
 *
 *   php tools/google_index_검산.php
 *
 * 확인하는 것
 *   1. base64url(JWT 가 쓰는 모양)
 *   2. JWT 주장(claims) 구성 — iss·scope·aud·iat·exp(+3600)
 *   3. 서명 전 JWT(머리말.주장) 의 내용
 *   4. RS256 서명 — 임시 키를 만들어 서명하고 그 키로 확인(openssl_verify)
 *   5. 서비스 계정 키(JSON) 읽기 — client_email·private_key 가 없으면 못 쓴다
 *   6. 하루 200건 한도 계산 — 날짜가 바뀌면 0 부터
 *
 * 모듈 파일을 그대로 읽어서 시험한다(같은 계산을 두 벌 만들지 않으려고).
 * 그래서 워드프레스 함수 몇 개만 흉내 낸다.
 *
 * @package wp-site-pack
 */

define( 'ABSPATH', __DIR__ . '/' ); // 모듈 파일이 '워드프레스 안에서 읽혔나' 를 보는 자리.

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { // phpcs:ignore
		return json_encode( $data ); // phpcs:ignore
	}
}

/** 모듈 계약(추상 클래스) 흉내 — 시험이 부르는 것은 정적 계산뿐이다. */
abstract class WSP_Module { // phpcs:ignore
	public function settings() { return array(); }
	public function is_active() { return true; }
	protected function send_virtual_headers( $content_type ) {}
	public function settings_url() { return ''; }
}

/** 옵션 헬퍼 흉내(정적 계산에는 쓰이지 않는다). */
class WSP_Settings { // phpcs:ignore
	public static function get( $slug, $defaults = array() ) { return $defaults; }
	public static function set( $slug, $data ) { return true; }
	public static function is_active( $slug ) { return true; }
}

require __DIR__ . '/../includes/modules/class-mod-auto-index.php';

$G    = 'WSP_Mod_Auto_Index';
$fail = 0;
$done = 0;

/**
 * 한 가지 확인.
 *
 * @param string $what 무엇을 보나.
 * @param bool   $ok   맞았나.
 * @param string $seen 틀렸을 때 실제로 본 것.
 */
function 확인( $what, $ok, $seen = '' ) {
	global $fail, $done;
	$done++;
	if ( $ok ) {
		echo "  ok   $what\n";
		return;
	}
	$fail++;
	echo "  실패 $what" . ( '' === $seen ? '' : " — 본 것: $seen" ) . "\n";
}

echo "1. base64url\n";
$b = $G::b64url( "\xfb\xff\xfe" ); // +, / 가 나오는 값.
확인( '+ / = 가 없다', ! preg_match( '#[+/=]#', $b ), $b );
확인( '되돌리면 같다', base64_decode( strtr( $b, '-_', '+/' ) . '==' ) === "\xfb\xff\xfe", $b );

echo "2. JWT 주장(claims)\n";
$now    = 1757800000;
$claims = $G::jwt_claims( 'bot@proj.iam.gserviceaccount.com', $now );
확인( 'iss = 서비스 계정 주소', 'bot@proj.iam.gserviceaccount.com' === $claims['iss'], $claims['iss'] );
확인( 'scope = 인덱싱 권한', 'https://www.googleapis.com/auth/indexing' === $claims['scope'], $claims['scope'] );
확인( 'aud = 토큰 주소', 'https://oauth2.googleapis.com/token' === $claims['aud'], $claims['aud'] );
확인( 'iat = 지금', $now === $claims['iat'], (string) $claims['iat'] );
확인( 'exp = 한 시간 뒤', $now + 3600 === $claims['exp'], (string) $claims['exp'] );

echo "3. 서명 전 JWT\n";
$unsigned = $G::jwt_unsigned( 'bot@proj.iam.gserviceaccount.com', $now );
$parts    = explode( '.', $unsigned );
확인( '두 도막이다', 2 === count( $parts ), (string) count( $parts ) );
$head = json_decode( base64_decode( strtr( $parts[0], '-_', '+/' ) ), true );
확인( '머리말 alg = RS256', isset( $head['alg'] ) && 'RS256' === $head['alg'], wp_json_encode( $head ) );
확인( '머리말 typ = JWT', isset( $head['typ'] ) && 'JWT' === $head['typ'], wp_json_encode( $head ) );
$body = json_decode( base64_decode( strtr( $parts[1], '-_', '+/' ) ), true );
확인( '주장이 그대로 들어 있다', $body === $claims, wp_json_encode( $body ) );

echo "4. RS256 서명(임시 키로 서명하고 확인)\n";
if ( ! function_exists( 'openssl_pkey_new' ) ) {
	확인( 'openssl 이 있다', false, '이 PHP 에는 openssl 이 없다' );
} else {
	$res = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
	openssl_pkey_export( $res, $pem );
	$pub = openssl_pkey_get_details( $res )['key'];

	$jwt = $G::jwt_sign( $unsigned, $pem );
	확인( '서명이 붙어 세 도막이 된다', is_string( $jwt ) && 3 === count( explode( '.', $jwt ) ), var_export( $jwt, true ) );

	$sig = base64_decode( strtr( explode( '.', $jwt )[2], '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( explode( '.', $jwt )[2] ) % 4 ) % 4 ) );
	확인( '그 공개키로 확인된다', 1 === openssl_verify( $unsigned, $sig, $pub, OPENSSL_ALGO_SHA256 ) );
	확인( '내용이 한 글자라도 바뀌면 확인이 안 된다', 1 !== openssl_verify( $unsigned . 'x', $sig, $pub, OPENSSL_ALGO_SHA256 ) );
	확인( '키가 아닌 것으로는 서명하지 못한다', false === $G::jwt_sign( $unsigned, '이건 키가 아니다' ) );
}

echo "5. 서비스 계정 키(JSON) 읽기\n";
$good = wp_json_encode( array(
	'type'         => 'service_account',
	'client_email' => 'bot@proj.iam.gserviceaccount.com',
	'private_key'  => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n",
) );
$k = $G::parse_key_json( $good );
확인( '제대로 된 키를 읽는다', is_array( $k ) && 'bot@proj.iam.gserviceaccount.com' === $k['client_email'], var_export( $k, true ) );
확인( '줄바꿈(\\n)이 진짜 줄바꿈이 된다', is_array( $k ) && false !== strpos( $k['private_key'], "\n" ) );
확인( 'client_email 이 없으면 못 쓴다', null === $G::parse_key_json( '{"private_key":"x"}' ) );
확인( 'private_key 가 없으면 못 쓴다', null === $G::parse_key_json( '{"client_email":"a@b.c"}' ) );
확인( 'private_key 가 공백뿐이면 못 쓴다', null === $G::parse_key_json( '{"client_email":"a@b.c","private_key":"   "}' ) );
확인( 'JSON 이 아니면 못 쓴다', null === $G::parse_key_json( '키를 여기 붙여넣으세요' ) );
확인( '빈 칸이면 못 쓴다', null === $G::parse_key_json( '   ' ) );

echo "6. 하루 200건 한도\n";
확인( '오늘 기록이 없으면 0건 보냄', 0 === $G::quota_today( array(), '2026-09-14' ) );
확인( '오늘 기록이 없으면 200건 남음', 200 === $G::quota_room( array(), '2026-09-14', 200 ) );
$saved = array( 'date' => '2026-09-14', 'count' => 3 );
확인( '오늘 3건 보냈다', 3 === $G::quota_today( $saved, '2026-09-14' ) );
확인( '오늘 197건 남았다', 197 === $G::quota_room( $saved, '2026-09-14', 200 ) );
확인( '날짜가 바뀌면 0 부터 다시 센다', 0 === $G::quota_today( $saved, '2026-09-15' ) );
확인( '날짜가 바뀌면 200건 남는다', 200 === $G::quota_room( $saved, '2026-09-15', 200 ) );
확인( '200건을 채우면 더 못 보낸다', 0 === $G::quota_room( array( 'date' => '2026-09-14', 'count' => 200 ), '2026-09-14', 200 ) );
확인( '어쩌다 넘겨도 음수가 되지 않는다', 0 === $G::quota_room( array( 'date' => '2026-09-14', 'count' => 250 ), '2026-09-14', 200 ) );
확인( '기록이 망가져 있으면 0건으로 본다', 0 === $G::quota_today( 'ㅁㄴㅇㄹ', '2026-09-14' ) );
확인( '기본 한도는 200 이다', 200 === $G::quota_room( array(), '2026-09-14' ) );

echo "\n" . ( $fail ? "✗ {$done}개 중 {$fail}개 실패\n" : "✓ {$done}개 모두 통과\n" );
exit( $fail ? 1 : 0 );
