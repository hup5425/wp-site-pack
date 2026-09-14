/* WP Site Pack — 애드 프로텍터. 실제 광고 클릭만 엄격 감지 + 차단 상태 처리.
 *
 * 🔴 페이지 HTML 은 캐시(Breeze)+CDN 으로 25분쯤 여러 사람에게 그대로 나간다.
 *    그래서 HTML 에서 받는 값(WSP_ADP)은 ajax 주소·액션 이름처럼 **누구에게나 같은 것**뿐이고,
 *    "이 사람이 차단 대상인가" 와 nonce 는 페이지가 뜬 뒤 admin-ajax 로 물어서 받는다.
 *    admin-ajax 응답은 캐시되지 않으므로, 캐시된 페이지에서도 판단은 언제나 지금 값이다.
 */
( function () {
	'use strict';

	var cfg   = window.WSP_ADP || {};
	var nonce = '';

	function post( action, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		if ( extra ) {
			Object.keys( extra ).forEach( function ( k ) { body.set( k, extra[ k ] ); } );
		}
		return fetch( cfg.ajax, {
			method: 'POST',
			body: body,
			credentials: 'same-origin',
			cache: 'no-store'
		} ).then( function ( r ) { return r.json(); } );
	}

	function showBlocked( text ) {
		document.body.classList.add( 'wsp-adp-blocked' ); // 광고 숨김.
		if ( document.getElementById( 'wsp-adp-modal' ) ) { return; }
		var m = document.createElement( 'div' );
		m.id = 'wsp-adp-modal';
		m.className = 'wsp-adp-modal';
		m.innerHTML = '<div class="wsp-adp-box"><button type="button" class="wsp-adp-close" aria-label="닫기">×</button><h3>안내</h3><p></p></div>';
		m.querySelector( 'p' ).textContent = text || '이 페이지의 광고 표시가 제한되었습니다.';
		m.querySelector( '.wsp-adp-close' ).addEventListener( 'click', function () { m.remove(); } );
		document.body.appendChild( m );
	}

	var lastReport = 0;
	function reportClick( retried ) {
		if ( ! retried ) {
			var now = Date.now();
			if ( now - lastReport < 1000 ) { return; } // 중복 방지.
			lastReport = now;
		}
		post( cfg.click, { nonce: nonce } ).then( function ( res ) {
			if ( ! res ) { return; }
			if ( res.success && res.data && res.data.blocked ) {
				showBlocked( res.data.text );
				return;
			}
			// nonce 가 만료된 경우 — 새로 받아 딱 한 번 다시 보낸다(집계가 조용히 사라지지 않게).
			if ( ! retried && ! res.success && res.data && res.data.renew ) {
				post( cfg.status ).then( function ( s ) {
					if ( s && s.success && s.data && s.data.nonce ) {
						nonce = s.data.nonce;
						reportClick( true );
					}
				} ).catch( function () {} );
			}
		} ).catch( function () {} );
	}

	function isAd( el ) {
		return el && el.closest && ( el.closest( 'ins.adsbygoogle' ) || el.closest( '.adsbygoogle' )
			|| el.closest( 'iframe[id^="aswift_"]' ) || el.closest( 'iframe[id^="google_ads"]' ) );
	}

	// ── 실제 광고 클릭만 엄격 감지 ──
	// 조건: (1) 광고 위에서 마우스를 "눌렀고"(mousedown) → (2) 1초 내 창이 blur 되며
	//        (3) 포커스가 광고 iframe 으로 넘어감. 세 조건을 모두 만족해야 클릭으로 집계.
	// (단순 탭 전환·호버만으로는 절대 집계되지 않음 → 오탐 방지.)
	function watchClicks() {
		var pressedAdAt = 0;
		document.addEventListener( 'mousedown', function ( e ) {
			pressedAdAt = isAd( e.target ) ? Date.now() : 0;
		}, true );

		window.addEventListener( 'blur', function () {
			if ( ! pressedAdAt || Date.now() - pressedAdAt > 1000 ) { pressedAdAt = 0; return; }
			var ae = document.activeElement;
			if ( ae && ae.tagName === 'IFRAME' && isAd( ae ) ) {
				reportClick( false );
			}
			pressedAdAt = 0;
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( ! cfg.ajax || ! cfg.status || ! cfg.click ) { return; }
		// 이 요청 하나가 세 가지를 한꺼번에 정한다: 차단 여부 · 안내 문구 · 지금 쓸 nonce.
		post( cfg.status ).then( function ( res ) {
			if ( ! res || ! res.success || ! res.data ) { return; }
			var d = res.data;
			nonce = d.nonce || '';
			if ( 'blocked' === d.state ) { showBlocked( d.text ); return; }
			if ( 'off' === d.state ) { return; } // 로그인 편집자 등 — 추적하지 않음.
			watchClicks();
		} ).catch( function () {} );
	} );
} )();
