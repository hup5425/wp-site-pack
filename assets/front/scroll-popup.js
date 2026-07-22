/* WP Site Pack — 스크롤 팝업. N% 스크롤 시 표시 + 빈도 제어. */
( function () {
	'use strict';

	var cfg = window.WSP_POPUP || {};
	var KEY = 'wsp_popup_seen';

	function seen() {
		try {
			if ( cfg.frequency === 'always' ) { return false; }
			if ( cfg.frequency === 'session' ) { return sessionStorage.getItem( KEY ) === '1'; }
			return localStorage.getItem( KEY ) === '1'; // once
		} catch ( e ) { return false; }
	}
	function markSeen() {
		try {
			if ( cfg.frequency === 'session' ) { sessionStorage.setItem( KEY, '1' ); }
			else if ( cfg.frequency !== 'always' ) { localStorage.setItem( KEY, '1' ); }
		} catch ( e ) {}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var popup = document.getElementById( 'wsp-popup' );
		if ( ! popup || seen() ) { return; }

		var shown = false;
		function maybeShow() {
			if ( shown ) { return; }
			var h = document.documentElement;
			var scrolled = ( h.scrollTop + window.innerHeight ) / h.scrollHeight * 100;
			if ( scrolled >= ( cfg.percent || 50 ) ) {
				shown = true;
				popup.hidden = false;
				markSeen();
				window.removeEventListener( 'scroll', maybeShow );
			}
		}
		window.addEventListener( 'scroll', maybeShow, { passive: true } );
		maybeShow();

		popup.addEventListener( 'click', function ( e ) {
			if ( e.target.hasAttribute( 'data-wsp-close' ) ) {
				popup.hidden = true;
			}
		} );
	} );
} )();
