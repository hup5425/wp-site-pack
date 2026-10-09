<?php
/**
 * 스니펫 검산 — 목록·기본값·저장값·댓글 블록 판정.  `php tools/snippets_검산.php`
 *
 * @package wp-site-pack
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( "명령줄에서 돌려 주세요: php tools/snippets_검산.php\n" );
}

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-module.php';
require __DIR__ . '/../includes/modules/class-mod-snippets.php';

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

$m = new WSP_Mod_Snippets();

확인( '「댓글 기능 없애기」는 기본이 켜짐', $m->default_settings(), array( 'no_comments' => 1 ) );
확인( '체크를 빼고 저장하면 꺼짐', $m->sanitize( array() ), array( 'no_comments' => 0 ) );
확인( '체크하고 저장하면 켜짐', $m->sanitize( array( 'no_comments' => '1' ) ), array( 'no_comments' => 1 ) );
확인( '목록에 없는 값은 버린다', $m->sanitize( array( 'no_comments' => '1', 'x' => '1' ) ), array( 'no_comments' => 1 ) );

foreach ( array_keys( WSP_Mod_Snippets::snippets() ) as $key ) {
	확인( "스니펫 「{$key}」 의 함수가 있다", method_exists( $m, 'snippet_' . $key ), true );
}

foreach ( array( 'core/comments', 'core/comment-template', 'core/comments-title', 'core/post-comments-form', 'core/latest-comments', 'core/post-comments-count' ) as $b ) {
	확인( "댓글 블록: {$b}", WSP_Mod_Snippets::is_comment_block( $b ), true );
}
foreach ( array( 'core/paragraph', 'core/group', 'core/post-content', '' ) as $b ) {
	확인( "댓글 블록 아님: {$b}", WSP_Mod_Snippets::is_comment_block( $b ), false );
}

echo "통과 {$통과}\n";
if ( $실패 ) {
	echo '실패 ' . count( $실패 ) . "\n  - " . implode( "\n  - ", $실패 ) . "\n";
	exit( 1 );
}
