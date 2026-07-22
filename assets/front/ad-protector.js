/* WP Site Pack — 애드 프로텍터. 광고 클릭 감지 + 차단 상태 처리. */
( function () {
	'use strict';

	var cfg = window.WSP_ADP || {};

	function showBlocked( text ) {
		document.body.classList.add( 'wsp-adp-blocked' );
		if ( document.getElementById( 'wsp-adp-modal' ) ) { return; }
		var m = document.createElement( 'div' );
		m.id = 'wsp-adp-modal';
		m.className = 'wsp-adp-modal';
		m.innerHTML = '<div class="wsp-adp-box"><h3>접근 제한</h3><p></p></div>';
		m.querySelector( 'p' ).textContent = text || '접근이 제한되었습니다.';
		document.body.appendChild( m );
	}

	var lastReport = 0;
	function reportClick() {
		// 과도한 중복 전송 방지(같은 클릭이 여러 이벤트로 잡힐 수 있어 800ms 디바운스).
		var now = Date.now();
		if ( now - lastReport < 800 ) { return; }
		lastReport = now;

		var body = new URLSearchParams();
		body.set( 'action', 'wsp_ad_click' );
		body.set( 'nonce', cfg.nonce );
		fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.data && res.data.blocked ) {
					showBlocked( cfg.modalText || '비정상적인 광고 클릭이 감지되었습니다.' );
				}
			} )
			.catch( function () {} );
	}

	// 마우스 포인터가 광고 영역 위에 있는지 추적.
	var overAd = false;
	function isAd( el ) {
		return el && el.closest && ( el.closest( 'ins.adsbygoogle' ) || el.closest( '.adsbygoogle' ) || el.closest( 'iframe[id^="aswift_"]' ) || el.closest( 'iframe[id^="google_ads"]' ) );
	}
	document.addEventListener( 'mouseover', function ( e ) { if ( isAd( e.target ) ) { overAd = true; } }, true );
	document.addEventListener( 'mouseout',  function ( e ) { if ( isAd( e.target ) ) { overAd = false; } }, true );

	document.addEventListener( 'DOMContentLoaded', function () {
		// 이미 차단된 IP.
		if ( cfg.blocked ) {
			showBlocked( cfg.modalText );
			return;
		}

		// 1) 컨테이너 직접 클릭(광고 여백/텍스트 링크 등 iframe 밖 클릭).
		document.addEventListener( 'click', function ( e ) {
			if ( isAd( e.target ) ) { reportClick(); }
		}, true );

		// 2) 표준 기법: 광고 위에서 창이 blur 되면(=iframe 광고를 클릭해 포커스가 광고로 넘어감) 광고 클릭으로 간주.
		//    애드센스 광고는 iframe(다른 출처)이라 내부 클릭을 직접 못 잡으므로 이 방식이 핵심.
		window.addEventListener( 'blur', function () {
			if ( overAd && document.activeElement && document.activeElement.tagName === 'IFRAME' ) {
				reportClick();
			}
		} );
	} );
} )();
