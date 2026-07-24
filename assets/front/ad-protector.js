/* WP Site Pack — 애드 프로텍터. 실제 광고 클릭만 엄격 감지 + 차단 상태 처리. */
( function () {
	'use strict';

	var cfg = window.WSP_ADP || {};

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
	function reportClick() {
		var now = Date.now();
		if ( now - lastReport < 1000 ) { return; } // 중복 방지.
		lastReport = now;
		var body = new URLSearchParams();
		body.set( 'action', 'wsp_ad_click' );
		body.set( 'nonce', cfg.nonce );
		fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.data && res.data.blocked ) {
					showBlocked( cfg.modalText || '이 페이지의 광고 표시가 제한되었습니다.' );
				}
			} )
			.catch( function () {} );
	}

	function isAd( el ) {
		return el && el.closest && ( el.closest( 'ins.adsbygoogle' ) || el.closest( '.adsbygoogle' )
			|| el.closest( 'iframe[id^="aswift_"]' ) || el.closest( 'iframe[id^="google_ads"]' ) );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		// 이미 차단된 IP.
		if ( cfg.blocked ) {
			showBlocked( cfg.modalText );
			return;
		}

		// ── 실제 광고 클릭만 엄격 감지 ──
		// 조건: (1) 광고 위에서 마우스를 "눌렀고"(mousedown) → (2) 1초 내 창이 blur 되며
		//        (3) 포커스가 광고 iframe 으로 넘어감. 세 조건을 모두 만족해야 클릭으로 집계.
		// (단순 탭 전환·호버만으로는 절대 집계되지 않음 → 오탐 방지.)
		var pressedAdAt = 0;
		document.addEventListener( 'mousedown', function ( e ) {
			pressedAdAt = isAd( e.target ) ? Date.now() : 0;
		}, true );

		window.addEventListener( 'blur', function () {
			if ( ! pressedAdAt || Date.now() - pressedAdAt > 1000 ) { pressedAdAt = 0; return; }
			var ae = document.activeElement;
			if ( ae && ae.tagName === 'IFRAME' && isAd( ae ) ) {
				reportClick();
			}
			pressedAdAt = 0;
		} );
	} );
} )();
