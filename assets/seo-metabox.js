/**
 * 글 편집 화면의 「검색 미리보기」 — 구글 검색결과 모양 미리보기 + 포커스 키워드 세기.
 * (기획서 4.8 사)
 *
 *  - 미리보기: 「검색 제목」·「주소」·「설명문」을 치는 대로 바꿔 보인다.
 *    ⚠ 설명문을 비웠을 때 "본문 첫 문장부터 160자"를 여기서 흉내 내 그리지 않는다 —
 *      자르는 규칙이 PHP 와 두 벌이 되어 한쪽만 고쳐지는 자리가 된다. 안내 한 줄만 보인다.
 *  - 키워드 세기: 화면을 열 때 한 번 + [다시 세기] 단추. 점수·등급은 만들지 않는다.
 */
( function () {
	'use strict';

	var LIMIT = ( window.WSP_SEO_MB && window.WSP_SEO_MB.limit ) ? window.WSP_SEO_MB.limit : 160;
	var HOME  = ( window.WSP_SEO_MB && window.WSP_SEO_MB.home ) ? window.WSP_SEO_MB.home : '/';

	function $( id ) { return document.getElementById( id ); }

	/* ---------------- 편집 중인 글 읽기 ---------------- */

	function editedContent() {
		// 구텐베르크 — 메타박스가 구텐베르크 화면 안에서 돌기 때문에 편집 중인 본문을 바로 읽는다.
		if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
			var c = wp.data.select( 'core/editor' ).getEditedPostContent();
			if ( typeof c === 'string' ) { return c; }
		}
		// 클래식 편집기.
		var el = $( 'content' );
		return el ? el.value : '';
	}

	function editedTitle() {
		if ( window.wp && wp.data && wp.data.select( 'core/editor' ) ) {
			var t = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' );
			if ( typeof t === 'string' ) { return t; }
		}
		var el = $( 'title' );
		return el ? el.value : '';
	}

	/* ---------------- 글자 다루기 ---------------- */

	function norm( s ) {
		return String( s || '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
	}

	function stripTags( html ) {
		return String( html || '' )
			.replace( /<!--[\s\S]*?-->/g, ' ' )   // 구텐베르크 블록 주석.
			.replace( /<[^>]+>/g, ' ' )
			.replace( /&nbsp;/g, ' ' )
			.replace( /&amp;/g, '&' )
			.replace( /&lt;/g, '<' )
			.replace( /&gt;/g, '>' )
			.replace( /&quot;/g, '"' );
	}

	/** 겹치지 않게 몇 번 들어갔나. */
	function countIn( haystack, needle ) {
		var h = norm( haystack );
		var n = norm( needle );
		if ( ! n || ! h ) { return 0; }
		var c = 0, i = h.indexOf( n );
		while ( i !== -1 ) {
			c++;
			i = h.indexOf( n, i + n.length );
		}
		return c;
	}

	function firstParagraph( html ) {
		var m = String( html || '' ).match( /<p\b[^>]*>([\s\S]*?)<\/p>/i );
		if ( m ) { return stripTags( m[1] ); }
		var lines = stripTags( html ).split( /\n+/ );
		for ( var i = 0; i < lines.length; i++ ) {
			if ( lines[ i ].trim() ) { return lines[ i ]; }
		}
		return '';
	}

	function headings( html ) {
		var out = [];
		var re  = /<h([23])\b[^>]*>([\s\S]*?)<\/h\1>/gi;
		var m;
		while ( ( m = re.exec( String( html || '' ) ) ) !== null ) {
			out.push( stripTags( m[2] ) );
		}
		return out.join( ' ' );
	}

	/* ---------------- 미리보기 ---------------- */

	function refreshPreview() {
		var titleEl = $( 'wsp_seo_title' );
		var descEl  = $( 'wsp_seo_description' );
		var slugEl  = $( 'wsp_seo_slug' );
		if ( ! titleEl || ! descEl || ! slugEl ) { return; }

		var base  = slugEl.getAttribute( 'data-base' ) || HOME;
		var title = titleEl.value.trim() || editedTitle() || '(제목 없음)';
		var slug  = slugEl.value.trim();
		var desc  = descEl.value.trim();

		$( 'wsp-seo-pv-title' ).textContent = title;
		$( 'wsp-seo-pv-url' ).textContent   = base + ( slug ? slug + '/' : '' );

		var pv = $( 'wsp-seo-pv-desc' );
		if ( desc ) {
			pv.textContent = desc;
			pv.classList.remove( 'wsp-seo-pv-auto' );
		} else {
			pv.textContent = '(비워 두면 본문 첫 문장부터 ' + LIMIT + '자를 자동으로 씁니다)';
			pv.classList.add( 'wsp-seo-pv-auto' );
		}

		var cnt = $( 'wsp-seo-desc-count' );
		if ( cnt ) {
			cnt.textContent = String( desc.length );
			cnt.parentNode.classList.toggle( 'wsp-seo-over', desc.length > LIMIT );
		}
	}

	/* ---------------- 키워드 세기 ---------------- */

	function recount() {
		var body = $( 'wsp-seo-count-body' );
		if ( ! body ) { return; }
		var kwEl = $( 'wsp_seo_focus_keyword' );
		var kw   = kwEl ? kwEl.value.trim() : '';
		if ( ! kw ) {
			body.innerHTML = '<tr><td colspan="2">포커스 키워드를 적으면 셉니다.</td></tr>';
			return;
		}

		var content = editedContent();
		var title   = ( $( 'wsp_seo_title' ).value.trim() || editedTitle() );
		var slug    = $( 'wsp_seo_slug' ).value.trim().replace( /-/g, ' ' );
		var desc    = $( 'wsp_seo_description' ).value.trim();

		var rows = [
			[ '제목', countIn( title, kw ) ],
			[ '주소', countIn( decodeURIComponent( slug ), kw ) ],
			[ '설명문', countIn( desc, kw ) ],
			[ '첫 문단', countIn( firstParagraph( content ), kw ) ],
			[ '소제목(H2·H3)', countIn( headings( content ), kw ) ],
			[ '본문 전체', countIn( stripTags( content ), kw ) ]
		];

		var html = '';
		for ( var i = 0; i < rows.length; i++ ) {
			html += '<tr><td>' + rows[ i ][0] + '</td><td>' + rows[ i ][1] + '번</td></tr>';
		}
		body.innerHTML = html;
	}

	/* ---------------- 시작 ---------------- */

	function init() {
		if ( ! $( 'wsp_seo_title' ) ) { return; }

		[ 'wsp_seo_title', 'wsp_seo_description', 'wsp_seo_slug' ].forEach( function ( id ) {
			var el = $( id );
			if ( el ) { el.addEventListener( 'input', refreshPreview ); }
		} );
		var btn = $( 'wsp-seo-recount' );
		if ( btn ) { btn.addEventListener( 'click', recount ); }

		refreshPreview();
		recount();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
