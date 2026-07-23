/* WP Site Pack — 관리자 공용 JS. 탭, 칩 토글, 복사 버튼. */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {

		// ── 탭 ──
		document.querySelectorAll( '.wsp-tabs' ).forEach( function ( tabs ) {
			var panes = tabs.parentElement.querySelectorAll( '.wsp-tabpane' );
			tabs.querySelectorAll( '.wsp-tab' ).forEach( function ( tab ) {
				tab.addEventListener( 'click', function () {
					var target = tab.getAttribute( 'data-tab' );
					tabs.querySelectorAll( '.wsp-tab' ).forEach( function ( t ) { t.classList.remove( 'active' ); } );
					tab.classList.add( 'active' );
					panes.forEach( function ( p ) {
						p.classList.toggle( 'active', p.getAttribute( 'data-pane' ) === target );
					} );
				} );
			} );
		} );

		// ── 칩 토글(체크박스 시각화) ──
		document.querySelectorAll( '.wsp-chip input[type=checkbox]' ).forEach( function ( cb ) {
			var chip = cb.closest( '.wsp-chip' );
			var sync = function () { chip.classList.toggle( 'checked', cb.checked ); };
			cb.addEventListener( 'change', sync );
			sync();
		} );

		// ── 복사 버튼(data-copy 값) ──
		document.querySelectorAll( '.wsp-copy' ).forEach( function ( el ) {
			el.addEventListener( 'click', function () {
				var text = el.getAttribute( 'data-copy' ) || el.textContent;
				navigator.clipboard.writeText( text ).then( function () {
					var old = el.textContent;
					el.textContent = '복사됨!';
					setTimeout( function () { el.textContent = old; }, 1200 );
				} );
			} );
		} );

		// ── 업데이트 확인 / 지금 업데이트 ──
		var checkBtn  = document.getElementById( 'wsp-check-update' );
		var doBtn     = document.getElementById( 'wsp-do-update' );
		var statusEl  = document.getElementById( 'wsp-update-status' );
		var WSPD      = window.WSP || {};

		function ajax( action, cb ) {
			var body = new URLSearchParams();
			body.set( 'action', action );
			body.set( 'nonce', WSPD.nonce || '' );
			fetch( WSPD.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( cb )
				.catch( function () { if ( statusEl ) { statusEl.textContent = ' 통신 오류'; } } );
		}

		if ( checkBtn ) {
			checkBtn.addEventListener( 'click', function () {
				statusEl.textContent = ' 확인 중…';
				doBtn.style.display = 'none';
				ajax( 'wsp_check_update', function ( res ) {
					if ( ! res || ! res.success ) { statusEl.textContent = ' 확인 실패'; return; }
					var d = res.data || {};
					if ( d.no_repo ) { statusEl.innerHTML = ' <span style="color:#8a6d00">' + d.message + '</span>'; return; }
					if ( d.has_update ) {
						statusEl.innerHTML = ' <span style="color:#007017">새 버전 v' + d.latest + ' 있음!</span>';
						doBtn.style.display = '';
					} else {
						statusEl.innerHTML = ' <span style="color:#646970">최신 버전입니다.</span>';
					}
				} );
			} );
		}

		if ( doBtn ) {
			doBtn.addEventListener( 'click', function () {
				doBtn.disabled = true;
				statusEl.textContent = ' 업데이트 중… (창을 닫지 마세요)';
				ajax( 'wsp_do_update', function ( res ) {
					if ( res && res.success ) {
						statusEl.innerHTML = ' <span style="color:#007017">' + ( res.data.message || '완료!' ) + '</span>';
						setTimeout( function () { location.reload(); }, 1500 );
					} else {
						doBtn.disabled = false;
						statusEl.innerHTML = ' <span style="color:#b32d2e">' + ( ( res && res.data && res.data.message ) || '실패' ) + '</span>';
					}
				} );
			} );
		}

		// ── IndexNow 키 생성(유효한 랜덤 32자리 hex 를 대상 input 에 채움) ──
		document.querySelectorAll( '.wsp-gen-key' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = document.querySelector( btn.getAttribute( 'data-target' ) );
				if ( ! target ) { return; }
				var arr = new Uint8Array( 16 );
				( window.crypto || window.msCrypto ).getRandomValues( arr );
				var hex = Array.prototype.map.call( arr, function ( b ) {
					return ( '0' + b.toString( 16 ) ).slice( -2 );
				} ).join( '' );
				target.value = hex;
				target.focus();
			} );
		} );

		// ── 수동 인덱싱: 글 목록 검색/페이지/개별·일괄 인덱싱 ──
		var miList = document.getElementById( 'wsp-mi-list' );
		if ( miList ) {
			var MW = window.WSP || {};
			var miPost = function ( action, extra, cb ) {
				var body = new URLSearchParams();
				body.set( 'action', action );
				body.set( 'nonce', MW.nonce || '' );
				Object.keys( extra || {} ).forEach( function ( k ) {
					if ( Array.isArray( extra[k] ) ) { extra[k].forEach( function ( v ) { body.append( k + '[]', v ); } ); }
					else { body.set( k, extra[k] ); }
				} );
				fetch( MW.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
					.then( function ( r ) { return r.json(); } ).then( cb ).catch( function () {} );
			};
			var miSearchInp = document.getElementById( 'wsp-mi-search' );
			var loadList = function ( s, p ) {
				miList.innerHTML = '<p>불러오는 중…</p>';
				miPost( 'wsp_indexnow_list', { s: s || '', p: p || 1 }, function ( res ) {
					if ( res && res.success ) { miList.innerHTML = res.data.html; }
					else { miList.innerHTML = '<p>목록을 불러오지 못했습니다.</p>'; }
				} );
			};
			var searchBtn = document.getElementById( 'wsp-mi-search-btn' );
			if ( searchBtn ) { searchBtn.addEventListener( 'click', function () { loadList( miSearchInp.value, 1 ); } ); }
			if ( miSearchInp ) { miSearchInp.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { e.preventDefault(); loadList( miSearchInp.value, 1 ); } } ); }

			miList.addEventListener( 'click', function ( e ) {
				var btn = e.target.closest( '.wsp-mi-btn' );
				if ( btn ) {
					var id = btn.getAttribute( 'data-id' );
					btn.disabled = true; btn.textContent = '요청중…';
					miPost( 'wsp_indexnow_post', { post_id: id }, function ( res ) {
						btn.disabled = false; btn.textContent = '인덱싱';
						if ( res && res.success ) {
							var cell = miList.querySelector( '.wsp-mi-status[data-id="' + id + '"]' );
							if ( cell ) { cell.innerHTML = res.data.status; }
						} else if ( res && res.data && res.data.message ) { alert( res.data.message ); }
					} );
					return;
				}
				var pg = e.target.closest( '.wsp-mi-page' );
				if ( pg ) { loadList( miSearchInp ? miSearchInp.value : '', pg.getAttribute( 'data-p' ) ); }
			} );

			miList.addEventListener( 'change', function ( e ) {
				if ( e.target.classList.contains( 'wsp-mi-all' ) ) {
					var on = e.target.checked;
					miList.querySelectorAll( '.wsp-mi-cb' ).forEach( function ( cb ) { cb.checked = on; } );
				}
			} );

			var bulkBtn = document.getElementById( 'wsp-mi-bulk' );
			if ( bulkBtn ) {
				bulkBtn.addEventListener( 'click', function () {
					var ids = Array.prototype.map.call( miList.querySelectorAll( '.wsp-mi-cb:checked' ), function ( cb ) { return cb.value; } );
					if ( ! ids.length ) { alert( '게시글을 선택하세요.' ); return; }
					bulkBtn.disabled = true; bulkBtn.textContent = '요청중… (' + ids.length + ')';
					miPost( 'wsp_indexnow_bulk', { ids: ids }, function ( res ) {
						bulkBtn.disabled = false; bulkBtn.textContent = '선택된 게시글 인덱싱';
						if ( res && res.success && res.data.results ) {
							Object.keys( res.data.results ).forEach( function ( id ) {
								var cell = miList.querySelector( '.wsp-mi-status[data-id="' + id + '"]' );
								if ( cell ) { cell.innerHTML = res.data.results[id]; }
							} );
						} else if ( res && res.data && res.data.message ) { alert( res.data.message ); }
					} );
				} );
			}
		}

		// ── 감지된 기존 IndexNow 키 채택(대상 input 에 채움) ──
		document.querySelectorAll( '.wsp-use-key' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var target = document.querySelector( btn.getAttribute( 'data-target' ) );
				if ( target ) { target.value = btn.getAttribute( 'data-key' ) || ''; target.focus(); }
			} );
		} );

		// ── 미디어 라이브러리 선택(이미지/동영상 → 대상 input 에 URL 채움) ──
		document.querySelectorAll( '.wsp-media-pick' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! window.wp || ! window.wp.media ) { return; }
				var target = document.querySelector( btn.getAttribute( 'data-target' ) );
				var frame = window.wp.media( { title: '이미지/동영상 선택', multiple: false } );
				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					if ( target ) { target.value = att.url; }
				} );
				frame.open();
			} );
		} );

		// ── 스크롤 팝업: 배너/HTML 모드 전환 ──
		document.querySelectorAll( '.wsp-pp-type' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				var isBanner = document.querySelector( '.wsp-pp-type[value=banner]' ).checked;
				var b = document.querySelector( '.wsp-pp-banner' );
				var h = document.querySelector( '.wsp-pp-html' );
				if ( b ) { b.style.display = isBanner ? '' : 'none'; }
				if ( h ) { h.style.display = isBanner ? 'none' : ''; }
			} );
		} );

		// ── 스크롤 팝업: 미리보기 ──
		var previewBtn = document.getElementById( 'wsp-popup-preview' );
		if ( previewBtn ) {
			previewBtn.addEventListener( 'click', function () {
				var form = previewBtn.closest( 'form' );
				var val = function ( name ) { var el = form.querySelector( '[name="' + name + '"]' ); return el ? el.value : ''; };
				var type = ( form.querySelector( '.wsp-pp-type:checked' ) || {} ).value || 'banner';
				var width = parseInt( val( 'width' ), 10 ) || 480;
				var inner = '';
				if ( type === 'banner' ) {
					var url = val( 'banner_url' ), link = val( 'banner_link' ), alt = val( 'banner_alt' );
					if ( ! url ) { alert( '이미지/동영상 주소를 입력하세요.' ); return; }
					var isVid = /\.(mp4|webm|ogg|mov|m4v)(\?.*)?$/i.test( url );
					var media = isVid
						? '<video src="' + url + '" autoplay muted loop playsinline style="display:block;width:100%"></video>'
						: '<img src="' + url + '" alt="' + alt + '" style="display:block;width:100%">';
					inner = link ? '<a href="' + link + '" target="_blank" rel="sponsored nofollow noopener" style="display:block;line-height:0">' + media + '</a>' : media;
				} else {
					// TinyMCE 내용 가져오기.
					var ed = window.tinymce && window.tinymce.get( 'wsp_popup_content' );
					inner = ed && ! ed.isHidden() ? ed.getContent() : val( 'content' );
					if ( ! inner ) { alert( 'HTML 내용을 입력하세요.' ); return; }
				}
				showPopupPreview( inner, width, type === 'banner' );
			} );
		}

		function showPopupPreview( inner, width, banner ) {
			var old = document.getElementById( 'wsp-pp-preview-overlay' );
			if ( old ) { old.remove(); }
			var ov = document.createElement( 'div' );
			ov.id = 'wsp-pp-preview-overlay';
			ov.style.cssText = 'position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.55)';
			var box = document.createElement( 'div' );
			box.style.cssText = 'position:relative;background:#fff;border-radius:10px;width:' + width + 'px;max-width:92vw;max-height:85vh;overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3);' + ( banner ? 'padding:0' : 'padding:28px 24px 24px' );
			box.innerHTML = '<button type="button" style="position:absolute;top:6px;right:8px;font-size:22px;border:none;background:' + ( banner ? 'rgba(0,0,0,.5);color:#fff;border-radius:50%;width:28px;height:28px' : 'none;color:#666' ) + ';cursor:pointer;z-index:2">×</button><div>' + inner + '</div>';
			box.querySelector( 'button' ).addEventListener( 'click', function () { ov.remove(); } );
			ov.addEventListener( 'click', function ( e ) { if ( e.target === ov ) { ov.remove(); } } );
			ov.appendChild( box );
			document.body.appendChild( ov );
		}

		// ── 소셜 공유 실시간 미리보기 ──
		var pvData = document.getElementById( 'wsp-social-pv-data' );
		var pvBox  = document.getElementById( 'wsp-social-preview' );
		if ( pvData && pvBox ) {
			var NETS = {};
			try { NETS = JSON.parse( pvData.textContent || '{}' ); } catch ( e ) {}
			var sform = pvBox.closest( 'form' );

			var textOn = function ( hex ) {
				hex = ( hex || '' ).replace( '#', '' );
				if ( hex.length === 3 ) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
				if ( hex.length !== 6 ) { return '#ffffff'; }
				var r = parseInt( hex.substr(0,2),16 ), g = parseInt( hex.substr(2,2),16 ), b = parseInt( hex.substr(4,2),16 );
				return ( 0.299*r + 0.587*g + 0.114*b ) / 255 > 0.65 ? '#191600' : '#ffffff';
			};
			var val = function ( name ) { var el = sform.querySelector( '[name="' + name + '"]' ); return el ? el.value : ''; };

			var buildPreview = function () {
				var style = val( 'btn_style' ) || 'logo_text';
				var align = val( 'align' ) || 'left';
				var kakaoKey = val( 'kakao_key' );
				var label = val( 'share_label' );
				var html = '';
				if ( label && label.trim() ) {
					html += '<div class="wsp-social-heading">' + label.replace( /</g, '&lt;' ) + '</div>';
				}
				html += '<div class="wsp-social wsp-social--' + style + ' wsp-align-' + align + '">';
				Object.keys( NETS ).forEach( function ( net ) {
					var cb = sform.querySelector( '[name="net_' + net + '"]' );
					if ( ! cb || ! cb.checked ) { return; }
					if ( net === 'kakao' && ! kakaoKey ) { return; }
					var ce = sform.querySelector( '[name="color_' + net + '"]' );
					var bg = ( ce && ce.value ) || NETS[net].brand;
					var fg = textOn( bg );
					var inner = '';
					if ( style !== 'text_only' ) { inner += NETS[net].svg; }
					if ( style !== 'logo_only' ) { inner += '<span class="wsp-social-label">' + NETS[net].label + '</span>'; }
					html += '<span class="wsp-social-btn wsp-social-' + net + '" style="background:' + bg + ';color:' + fg + '">' + inner + '</span>';
				} );
				html += '</div>';
				if ( ! /wsp-social-btn/.test( html ) ) { html = '<em style="color:#888">표시할 플랫폼을 선택하세요.</em>'; }
				pvBox.innerHTML = html;
			};

			sform.addEventListener( 'input', buildPreview );
			sform.addEventListener( 'change', buildPreview );
			buildPreview();
		}

	} );
} )();
