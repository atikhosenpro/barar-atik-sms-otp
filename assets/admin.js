/**
 * Barar Atik SMS OTP â admin tools.
 *
 * Test connection, test SMS, template preview, message sync, webhook setup,
 * secret reveal, copy buttons, macro insertion and live SMS counters.
 *
 * @package Barar_Atik
 */
(function () {
	'use strict';

	if ( ! window.bararAtikAdmin ) {
		return;
	}

	var cfg = window.bararAtikAdmin;
	var i18n = cfg.i18n || {};

	function q( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function qa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			if ( value !== undefined && value !== null ) {
				body.append( key, value );
			}
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function setResult( el, type, text ) {
		if ( ! el ) {
			return;
		}
		el.textContent = text || '';
		el.classList.remove( 'is-ok', 'is-error', 'is-info' );
		if ( type ) {
			el.classList.add( 'is-' + type );
		}
	}

	function toast( text, isError ) {
		var el = document.createElement( 'div' );
		el.className = 'barar-toast' + ( isError ? ' is-error' : '' );
		el.setAttribute( 'role', 'status' );
		el.textContent = text;
		document.body.appendChild( el );
		setTimeout( function () {
			el.classList.add( 'is-in' );
		}, 20 );
		setTimeout( function () {
			el.classList.remove( 'is-in' );
			setTimeout( function () {
				if ( el.parentNode ) {
					el.parentNode.removeChild( el );
				}
			}, 320 );
		}, 1900 );
	}

	/* ---------------------------------------------------------------------
	 * SMS segmentation (mirrors Barar_Atik_Macros::segments()).
	 * ------------------------------------------------------------------ */

	var GSM_EXTRAS = '\u20ac\u00a1\u00a3\u00a5\u00a4\u00a7\u00bf\u00c4\u00d6\u00d1\u00dc\u00e4\u00f6\u00f1\u00fc\u00e0\u00e8\u00e9\u00f9\u00ec\u00f2\u00c7\u00d8\u00f8\u00c5\u00e5\u00c6\u00e6\u00df\u00c9\u0394\u03a6\u0393\u039b\u03a9\u03a0\u03a8\u03a3\u0398\u039e';

	function isGsm7( text ) {
		var chars = Array.from( text );
		for ( var i = 0; i < chars.length; i++ ) {
			var ch = chars[ i ];
			var cp = ch.codePointAt( 0 );
			if ( cp <= 126 ) {
				if ( cp < 32 && ch !== '\n' && ch !== '\r' && ch !== '\t' ) {
					return false;
				}
				continue;
			}
			if ( GSM_EXTRAS.indexOf( ch ) === -1 ) {
				return false;
			}
		}
		return true;
	}

	function smsInfo( text ) {
		text = String( text || '' );
		var gsm = isGsm7( text );
		var len = Array.from( text ).length;
		var single = gsm ? 160 : 70;
		var multi = gsm ? 153 : 67;
		var segs = 0 === len ? 0 : ( len <= single ? 1 : Math.ceil( len / multi ) );
		return {
			len: len,
			segs: segs,
			enc: gsm ? 'GSM-7' : 'UCS-2'
		};
	}

	function updateCounter( tpl ) {
		if ( ! tpl ) {
			return;
		}
		var counter = tpl.parentElement ? tpl.parentElement.querySelector( '.barar-counter' ) : null;
		if ( ! counter ) {
			return;
		}
		var info = smsInfo( tpl.value );
		var text = String( i18n.chars || '%1$d characters \u00b7 %2$d SMS (%3$s)' )
			.replace( '%1$d', info.len )
			.replace( '%2$d', info.segs )
			.replace( '%3$s', info.enc );
		counter.textContent = text;
		counter.classList.toggle( 'is-warn', info.segs > 1 );
	}

	/* ---------------------------------------------------------------------
	 * Secret reveal + copy.
	 * ------------------------------------------------------------------ */

	qa( '.barar-toggle-secret' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var wrap = btn.closest( '.barar-secret' );
			var input = wrap ? wrap.querySelector( 'input' ) : null;
			if ( ! input ) {
				return;
			}
			var showing = 'password' === input.type;
			input.type = showing ? 'text' : 'password';
			btn.textContent = showing ? ( i18n.hide || 'Hide' ) : ( i18n.show || 'Show' );
			btn.setAttribute( 'aria-pressed', showing ? 'true' : 'false' );
		} );
	} );

	qa( '.barar-copy' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var row = btn.closest( '.barar-copy-row' ) || document;
			var input = q( '.barar-copy-input', row );
			if ( ! input ) {
				return;
			}
			var done = function () {
				var original = btn.textContent;
				btn.textContent = i18n.copied || 'Copied!';
				setTimeout( function () {
					btn.textContent = original;
				}, 1500 );
			};
			input.select();
			try {
				input.setSelectionRange( 0, 99999 );
			} catch ( e ) {}
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( input.value ).then( done, function () {
					try {
						document.execCommand( 'copy' );
						done();
					} catch ( err ) {}
				} );
			} else {
				try {
					document.execCommand( 'copy' );
					done();
				} catch ( err ) {}
			}
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Dashboard tabs.
	 * ------------------------------------------------------------------ */

	qa( '.barar-tabset' ).forEach( function ( set ) {
		var tabs   = qa( '.barar-tab', set );
		var panels = qa( '.barar-tabpanel', set );

		function activate( slug, focus ) {
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-tab' ) === slug;
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', on ? '0' : '-1' );
				if ( on && focus ) {
					tab.focus();
				}
			} );
			panels.forEach( function ( panel ) {
				var on = panel.getAttribute( 'data-tab-panel' ) === slug;
				panel.classList.toggle( 'is-active', on );
				if ( on ) {
					panel.removeAttribute( 'hidden' );
				} else {
					panel.setAttribute( 'hidden', '' );
				}
			} );
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-tab' ), false );
			} );
			tab.addEventListener( 'keydown', function ( event ) {
				if ( [ 'ArrowLeft', 'ArrowRight', 'Home', 'End' ].indexOf( event.key ) === -1 ) {
					return;
				}
				event.preventDefault();
				var next = index;
				if ( 'ArrowLeft' === event.key ) {
					next = ( index - 1 + tabs.length ) % tabs.length;
				} else if ( 'ArrowRight' === event.key ) {
					next = ( index + 1 ) % tabs.length;
				} else if ( 'Home' === event.key ) {
					next = 0;
				} else if ( 'End' === event.key ) {
					next = tabs.length - 1;
				}
				activate( tabs[ next ].getAttribute( 'data-tab' ), true );
			} );
		} );

		// Normalise the roving tabindex from the server-rendered state.
		var initial = tabs.filter( function ( tab ) {
			return 'true' === tab.getAttribute( 'aria-selected' );
		} )[0] || tabs[0];
		if ( initial ) {
			activate( initial.getAttribute( 'data-tab' ), false );
		}

		// Deep link: admin.php?page=barar-atik-sms-otp#messages
		var hash = ( window.location.hash || '' ).replace( '#', '' ).replace( /[^a-z0-9_-]/gi, '' );
		if ( hash && q( '.barar-tab[data-tab="' + hash + '"]', set ) ) {
			activate( hash, false );
		}
	} );

	/* ---------------------------------------------------------------------
	 * Test connection.
	 * ------------------------------------------------------------------ */

	qa( '.barar-test-connection' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var box = q( '.barar-test-connection-result' );
			setResult( box, 'info', i18n.working || 'Workingâ¦' );
			btn.disabled = true;

			post( cfg.actions.connection, {} )
				.then( function ( json ) {
					btn.disabled = false;
					if ( json && json.success ) {
						var d = json.data || {};
						var lines = [ d.message + ' (HTTP ' + d.status + ( d.elapsed_ms ? ' \u00b7 ' + d.elapsed_ms + ' ms' : '' ) + ')' ];
						var totals = d.totals || {};
						Object.keys( totals ).forEach( function ( key ) {
							lines.push( key + ': ' + totals[ key ] );
						} );
						setResult( box, 'ok', lines.join( '\n' ) );
						if ( box ) {
							box.style.whiteSpace = 'pre-line';
						}
						return;
					}
					var err = ( json && json.data ) || {};
					setResult( box, 'error', ( err.message || i18n.network ) + ( err.status ? ' (HTTP ' + err.status + ')' : '' ) );
				} )
				.catch( function () {
					btn.disabled = false;
					setResult( box, 'error', i18n.network );
				} );
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Test SMS.
	 * ------------------------------------------------------------------ */

	qa( '.barar-test-send' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var scope = btn.closest( '.barar-card' ) || document;
			var phone = q( '.barar-test-phone', scope );
			var message = q( '.barar-test-message', scope );
			var box = q( '.barar-test-result', scope );

			if ( ! phone || ! String( phone.value ).trim() ) {
				setResult( box, 'error', i18n.required || 'Please fill in the required fields.' );
				if ( phone ) {
					phone.focus();
				}
				return;
			}
			if ( ! message || ! String( message.value ).trim() ) {
				setResult( box, 'error', i18n.required || 'Please fill in the required fields.' );
				if ( message ) {
					message.focus();
				}
				return;
			}

			setResult( box, 'info', i18n.working || 'Workingâ¦' );
			btn.disabled = true;

			post( cfg.actions.testSms, {
				phone: phone.value,
				message: message.value
			} )
				.then( function ( json ) {
					btn.disabled = false;
					if ( json && json.success ) {
						var d = json.data || {};
						var parts = [ d.message || '' ];
						if ( d.masked ) {
							parts.push( d.masked );
						}
						if ( d.http ) {
							parts.push( 'HTTP ' + d.http );
						}
						setResult( box, 'ok', parts.filter( Boolean ).join( ' \u00b7 ' ) );
						return;
					}
					var err = ( json && json.data ) || {};
					setResult( box, 'error', ( err.message || i18n.network ) + ( err.status ? ' (HTTP ' + err.status + ')' : '' ) );
				} )
				.catch( function () {
					btn.disabled = false;
					setResult( box, 'error', i18n.network );
				} );
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Template preview.
	 * ------------------------------------------------------------------ */

	var eventSel = q( '.barar-preview-event' );
	var orderSel = q( '.barar-preview-order' );
	var recipSel = q( '.barar-preview-recipient' );
	var tplInput = q( '.barar-preview-template' );

	function applyEventTemplate() {
		if ( ! eventSel || ! tplInput || ! eventSel.value ) {
			return;
		}
		var opt = eventSel.options[ eventSel.selectedIndex ];
		if ( ! opt ) {
			return;
		}
		var recipient = recipSel ? recipSel.value : 'customer';
		var template = opt.getAttribute( 'data-template' ) || '';
		if ( 'vendor' === recipient ) {
			template = opt.getAttribute( 'data-template-vendor' ) || template;
		} else if ( 'admin' === recipient ) {
			template = opt.getAttribute( 'data-template-admin' ) || template;
		}
		tplInput.value = template;
		updateCounter( tplInput );
	}

	if ( eventSel ) {
		eventSel.addEventListener( 'change', applyEventTemplate );
	}
	if ( recipSel ) {
		recipSel.addEventListener( 'change', function () {
			if ( eventSel && eventSel.value ) {
				applyEventTemplate();
			}
		} );
	}

	var previewBtn = q( '.barar-preview-send' );
	if ( previewBtn ) {
		previewBtn.addEventListener( 'click', function () {
			var box = q( '.barar-preview-result' );
			setResult( box, 'info', i18n.working || 'Workingâ¦' );
			previewBtn.disabled = true;

			post( cfg.actions.preview, {
				order_id: orderSel ? orderSel.value : 0,
				recipient: recipSel ? recipSel.value : 'customer',
				template: tplInput ? tplInput.value : ''
			} )
				.then( function ( json ) {
					previewBtn.disabled = false;
					if ( ! box ) {
						return;
					}
					if ( json && json.success ) {
						var d = json.data || {};
						var seg = d.segments || {};
						setResult( box, 'ok', '' );

						var pre = document.createElement( 'pre' );
						pre.className = 'barar-preview-out';
						pre.textContent = d.preview || '';
						box.appendChild( pre );

						var meta = document.createElement( 'p' );
						meta.className = 'barar-preview-meta';
						var metaParts = [];
						metaParts.push( ( seg.length || 0 ) + ' chars' );
						metaParts.push( ( seg.segments || 0 ) + ' SMS (' + ( seg.encoding || '' ) + ')' );
						if ( d.phone ) {
							metaParts.push( d.phone );
						} else {
							metaParts.push( 'no recipient number' );
						}
						meta.textContent = metaParts.join( ' \u00b7 ' );
						box.appendChild( meta );
						return;
					}
					var err = ( json && json.data ) || {};
					setResult( box, 'error', err.message || i18n.network );
				} )
				.catch( function () {
					previewBtn.disabled = false;
					setResult( box, 'error', i18n.network );
				} );
		} );
	}

	/* ---------------------------------------------------------------------
	 * Message sync.
	 * ------------------------------------------------------------------ */

	qa( '.barar-sync' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var box = q( '.barar-sync-result' );
			setResult( box, 'info', i18n.working || 'Workingâ¦' );
			btn.disabled = true;

			post( cfg.actions.sync, {
				direction: btn.getAttribute( 'data-direction' ) || '',
				status: btn.getAttribute( 'data-status' ) || '',
				search: btn.getAttribute( 'data-search' ) || ''
			} )
				.then( function ( json ) {
					btn.disabled = false;
					if ( json && json.success ) {
						var d = json.data || {};
						setResult( box, 'ok', d.message || i18n.synced );
						setTimeout( function () {
							window.location.reload();
						}, 1400 );
						return;
					}
					var err = ( json && json.data ) || {};
					setResult( box, 'error', ( err.message || i18n.network ) + ( err.status ? ' (HTTP ' + err.status + ')' : '' ) );
				} )
				.catch( function () {
					btn.disabled = false;
					setResult( box, 'error', i18n.network );
				} );
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Webhook subscription.
	 * ------------------------------------------------------------------ */

	qa( '.barar-create-webhook' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var box = q( '.barar-webhook-result' );
			setResult( box, 'info', i18n.working || 'Workingâ¦' );
			btn.disabled = true;

			post( cfg.actions.webhook, {} )
				.then( function ( json ) {
					btn.disabled = false;
					if ( json && json.success ) {
						var d = json.data || {};
						setResult( box, 'ok', ( d.message || '' ) + ( d.url ? ' â ' + d.url : '' ) );
						if ( d.url ) {
							var input = q( '.barar-copy-input' );
							if ( input ) {
								input.value = d.url;
							}
						}
						return;
					}
					var err = ( json && json.data ) || {};
					setResult( box, 'error', ( err.message || i18n.network ) + ( err.status ? ' (HTTP ' + err.status + ')' : '' ) );
				} )
				.catch( function () {
					btn.disabled = false;
					setResult( box, 'error', i18n.network );
				} );
		} );
	} );

	/* ---------------------------------------------------------------------
	 * Macro insertion + counters.
	 * ------------------------------------------------------------------ */

	var lastTemplate = null;
	document.addEventListener( 'focusin', function ( event ) {
		if ( event.target && event.target.classList && event.target.classList.contains( 'barar-tpl' ) ) {
			lastTemplate = event.target;
		}
	} );

	qa( '.barar-macro' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var macro = btn.getAttribute( 'data-macro' ) || '';
			var target = lastTemplate;

			if ( ! target || ! document.body.contains( target ) ) {
				target = q( '.barar-tpl' );
			}
			if ( ! target ) {
				toast( i18n.noTemplate || 'No message box found.', true );
				return;
			}

			var start = target.selectionStart;
			var end = target.selectionEnd;
			var value = target.value || '';
			if ( 'number' === typeof start && 'number' === typeof end ) {
				target.value = value.slice( 0, start ) + macro + value.slice( end );
				var pos = start + macro.length;
				try {
					target.setSelectionRange( pos, pos );
				} catch ( e ) {}
			} else {
				target.value = value + macro;
			}
			target.focus();
			target.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			toast( i18n.inserted || 'Macro inserted.', false );
		} );
	} );

	qa( '.barar-tpl' ).forEach( function ( tpl ) {
		tpl.addEventListener( 'input', function () {
			updateCounter( tpl );
		} );
		updateCounter( tpl );
	} );

	/* ---------------------------------------------------------------------
	 * AJAX Search for Messages
	 * ------------------------------------------------------------------ */

	var searchInput = q( '.barar-filters input[type="search"]' );
	var searchDebounce = null;
	var tbody = q( '.barar-messages tbody' );
	var pagination = q( '.barar-pagination' );

	if ( searchInput && tbody ) {
		searchInput.addEventListener( 'input', function () {
			clearTimeout( searchDebounce );
			searchDebounce = setTimeout( function () {
				doSearch( 1 );
			}, 300 );
		} );
	}

	function doSearch( page ) {
		var searchVal = searchInput ? searchInput.value : '';
		var direction = searchInput ? searchInput.closest( '.barar-filters' ).querySelector( 'select[name="direction"]' ) : null;
		var status = searchInput ? searchInput.closest( '.barar-filters' ).querySelector( 'select[name="status"]' ) : null;

		var data = {
			search: searchVal,
			paged: page,
		};
		if ( direction && direction.value ) {
			data.direction = direction.value;
		}
		if ( status && status.value ) {
			data.status = status.value;
		}

		var form = searchInput ? searchInput.closest( 'form' ) : null;
		if ( form ) {
			var submitBtn = form.querySelector( 'button[type="submit"]' );
			if ( submitBtn ) {
				submitBtn.disabled = true;
				submitBtn.textContent = i18n.working || 'Workingâ¦';
			}
		}

		post( cfg.actions.searchMessages, data )
			.then( function ( json ) {
				if ( form ) {
					var submitBtn = form.querySelector( 'button[type="submit"]' );
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.textContent = 'Filter';
					}
				}
				if ( json && json.success ) {
					var d = json.data || {};
					if ( tbody ) {
						tbody.innerHTML = d.rows_html || '';
					}
					if ( pagination ) {
						pagination.innerHTML = d.pagination_html || '';
					}
					// Update URL without reload
					var newUrl = addQueryArg( { s: searchVal, paged: page } );
					window.history.replaceState( {}, '', newUrl );
				}
			} )
			.catch( function () {
				if ( form ) {
					var submitBtn = form.querySelector( 'button[type="submit"]' );
					if ( submitBtn ) {
						submitBtn.disabled = false;
						submitBtn.textContent = 'Filter';
					}
				}
			} );
	}

	function addQueryArg( params ) {
		var url = new URL( window.location.href );
		Object.keys( params ).forEach( function ( key ) {
			if ( params[ key ] ) {
				url.searchParams.set( key, params[ key ] );
			} else {
				url.searchParams.delete( key );
			}
		} );
		return url.toString();
	}

	// Also handle pagination clicks via AJAX
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest( '.barar-pagination a' );
		if ( link && link.href ) {
			event.preventDefault();
			var url = new URL( link.href );
			var page = url.searchParams.get( 'paged' ) || 1;
			doSearch( parseInt( page, 10 ) );
		}
	} );

}() );

