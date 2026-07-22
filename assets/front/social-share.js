/* WP Site Pack — 소셜 공유. 카카오만 SDK 필요(있을 때만 로드). */
( function () {
	'use strict';

	function initKakao( cb ) {
		var key = ( window.WSP_SOCIAL && window.WSP_SOCIAL.kakaoKey ) || '';
		if ( ! key ) { return; }
		if ( window.Kakao && window.Kakao.isInitialized && window.Kakao.isInitialized() ) {
			cb(); return;
		}
		if ( window.Kakao ) {
			try { window.Kakao.init( key ); } catch ( e ) {}
			cb(); return;
		}
		var sc = document.createElement( 'script' );
		sc.src = 'https://t1.kakaocdn.net/kakao_js_sdk/2.7.2/kakao.min.js';
		sc.onload = function () {
			try { window.Kakao.init( key ); } catch ( e ) {}
			cb();
		};
		document.head.appendChild( sc );
	}

	function toast( msg ) {
		var t = document.createElement( 'div' );
		t.className = 'wsp-social-toast';
		t.textContent = msg;
		document.body.appendChild( t );
		setTimeout( function () { t.classList.add( 'show' ); }, 10 );
		setTimeout( function () { t.classList.remove( 'show' ); setTimeout( function () { t.remove(); }, 300 ); }, 1600 );
	}

	// 링크 복사(인스타그램·링크복사 버튼).
	document.addEventListener( 'click', function ( e ) {
		var cbtn = e.target.closest( '[data-wsp-copy]' );
		if ( ! cbtn ) { return; }
		e.preventDefault();
		var url = cbtn.getAttribute( 'data-wsp-copy' );
		var isInsta = cbtn.classList.contains( 'wsp-social-instagram' );
		function done() { toast( isInsta ? '링크가 복사됐어요. 인스타그램에 붙여넣기 하세요.' : '링크가 복사됐어요.' ); }
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( url ).then( done ).catch( function () {
				window.prompt( '아래 링크를 복사하세요:', url );
			} );
		} else {
			window.prompt( '아래 링크를 복사하세요:', url );
		}
	} );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.wsp-social-kakao' );
		if ( ! btn ) { return; }
		e.preventDefault();
		initKakao( function () {
			if ( ! window.Kakao || ! window.Kakao.Share ) { return; }
			window.Kakao.Share.sendDefault( {
				objectType: 'feed',
				content: {
					title: btn.getAttribute( 'data-title' ) || document.title,
					description: '',
					imageUrl: '',
					link: { mobileWebUrl: btn.getAttribute( 'data-url' ), webUrl: btn.getAttribute( 'data-url' ) }
				}
			} );
		} );
	} );
} )();
