/**
 * Barar Atik SMS OTP — frontend phone OTP controls.
 *
 * Works with the markup rendered by Barar_Atik_Frontend:
 *   .barar-otp[data-context][data-target][data-inline][data-hide-password]
 *   [data-passwordless][data-email-required][data-first-name-required]
 *   [data-last-name-required][data-resend]
 *
 * Two integration styles:
 *   - panel:   a standalone block outside the native form (default);
 *   - inline:  injected inside the WooCommerce form with a Password /
 *              Phone OTP switch that hides and restores the native rows.
 *
 * @package Barar_Atik
 */
(function () {
	'use strict';

	if ( ! window.bararAtikOtp ) {
		return;
	}

	var cfg = window.bararAtikOtp;

	function q( sel, ctx ) {
		return ( ctx || document ).querySelector( sel );
	}

	function qa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) );
	}

	/**
	 * POST to admin-ajax and resolve with a parsed JSON object.
	 * Non-JSON responses (HTML, "-1", "0") reject with a typed error so the
	 * caller can show an accurate message instead of a generic failure.
	 */
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
			var contentType = res.headers.get( 'content-type' ) || '';
			if ( contentType.indexOf( 'application/json' ) === -1 ) {
				var bad = new Error( 'non-json' );
				bad.httpStatus = res.status;
				throw bad;
			}
			return res.json().then( function ( json ) {
				if ( typeof json !== 'object' || json === null ) {
					// admin-ajax answered with a scalar (-1 = bad nonce, 0 = no handler).
					var scalar = new Error( 'bad-payload' );
					scalar.httpStatus = res.status;
					throw scalar;
				}
				return json;
			} );
		} );
	}

	function sprintf( template, value ) {
		return String( template ).replace( '%s', value );
	}

	/** Human message for a rejected post(). */
	function errorText( err ) {
		if ( err && err.message === 'bad-payload' ) {
			return cfg.i18n.session;
		}
		if ( err && err.httpStatus && 200 !== err.httpStatus ) {
			return cfg.i18n.network + ' (HTTP ' + err.httpStatus + ')';
		}
		return cfg.i18n.network;
	}

	function setBusy( btn, busy ) {
		if ( ! btn ) {
			return;
		}
		btn.disabled = !! busy;
		btn.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
	}

	function initPanel( root ) {
		var context = root.getAttribute( 'data-context' ) || '';
		var targetSel = root.getAttribute( 'data-target' ) || '';
		var inline = root.getAttribute( 'data-inline' ) === '1';
		var isRegister = root.getAttribute( 'data-register' ) === '1';
		var hidePassword = root.getAttribute( 'data-hide-password' ) === '1';
		var passwordless = root.getAttribute( 'data-passwordless' ) === '1';
		var resendSeconds = parseInt( root.getAttribute( 'data-resend' ), 10 ) || 60;

		var nativeForm = targetSel ? q( targetSel ) : null;
		var switchBtn = q( '.barar-otp__switch', root );
		var tabs = qa( '.barar-otp__tab', root );
		var bodyEl = q( '.barar-otp__body', root );
		var statusEl = q( '.barar-otp__status', root );
		var phoneStep = q( '.barar-otp__step--phone', root );
		var codeStep = q( '.barar-otp__step--code', root );
		var hintEl = q( '.barar-otp__hint', root );
		var phoneInput = q( '.barar-otp__phone', root );
		var codeInput = q( '.barar-otp__code', root );
		var sendBtn = q( '.barar-otp__send', root );
		var verifyBtn = q( '.barar-otp__verify', root );
		var resendBtn = q( '.barar-otp__resend', root );
		var changeBtn = q( '.barar-otp__change', root );
		var backBtn = q( '.barar-otp__back', root );

		var busy = false;
		var countdownTimer = null;

		function setStatus( message, type ) {
			if ( ! statusEl ) {
				return;
			}
			statusEl.textContent = message || '';
			statusEl.className = 'barar-otp__status' + ( type ? ' is-' + type : '' );
		}

		function showStep( which ) {
			if ( phoneStep ) {
				phoneStep.hidden = ( 'phone' !== which );
			}
			if ( codeStep ) {
				codeStep.hidden = ( 'code' !== which );
			}
		}

		function spinners( on ) {
			qa( '.barar-otp__spinner', bodyEl ).forEach( function ( el ) {
				el.hidden = ! on;
			} );
		}

		/* Inline mode: native rows of the surrounding WooCommerce form ------ */

		function nativeRows() {
			var rows = [];

			function push( el ) {
				if ( el && rows.indexOf( el ) === -1 ) {
					rows.push( el );
				}
			}

			if ( ! nativeForm ) {
				return rows;
			}

			[
				'input[name="username"]',
				'input[name="password"]',
				'input[name="email"]',
				'input[name="login"]',
				'input[name="register"]',
				'button[name="login"]',
				'button[name="register"]',
				'.lost_password'
			].forEach( function ( sel ) {
				qa( sel, nativeForm ).forEach( function ( el ) {
					push( el.closest( '.form-row' ) || el.closest( 'p' ) || el.closest( 'div' ) || el );
				} );
			} );

			// The password hint paragraph of the register form (classless, inputless).
			qa( 'p', nativeForm ).forEach( function ( p ) {
				if ( ! p.className && p.querySelectorAll( 'input, textarea, select, button, a' ).length === 0 ) {
					push( p );
				}
			} );

			return rows;
		}

		function setNativeRows( hidden ) {
			nativeRows().forEach( function ( row ) {
				if ( hidden ) {
					if ( ! row.hasAttribute( 'data-barar-display' ) ) {
						row.setAttribute( 'data-barar-display', row.style.display );
					}
					row.style.display = 'none';
				} else if ( row.hasAttribute( 'data-barar-display' ) ) {
					row.style.display = row.getAttribute( 'data-barar-display' ) || '';
					row.removeAttribute( 'data-barar-display' );
				}
			} );
		}

		function setTabs( mode ) {
			tabs.forEach( function ( tab ) {
				var active = tab.getAttribute( 'data-mode' ) === mode;
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
			} );
		}

		/** Inline: switch between the password fields and the OTP controls. */
		function setMode( mode ) {
			if ( mode === 'password' ) {
				if ( bodyEl ) {
					bodyEl.hidden = true;
				}
				setNativeRows( false );
				setStatus( '' );
				showStep( 'phone' );
			} else {
				if ( bodyEl ) {
					bodyEl.hidden = false;
				}
				setNativeRows( true );
				if ( phoneInput && phoneStep && ! phoneStep.hidden ) {
					phoneInput.focus();
				}
			}
			setTabs( mode );
		}

		/* Panel mode -------------------------------------------------------- */

		function openPanel() {
			if ( inline ) {
				setMode( 'otp' );
				return;
			}
			if ( bodyEl ) {
				bodyEl.hidden = false;
			}
			if ( switchBtn ) {
				switchBtn.setAttribute( 'aria-expanded', 'true' );
			}
			if ( nativeForm ) {
				nativeForm.style.display = 'none';
			}
			if ( backBtn ) {
				backBtn.hidden = hidePassword;
			}
			if ( phoneInput && phoneStep && ! phoneStep.hidden ) {
				phoneInput.focus();
			}
		}

		function closePanel() {
			if ( hidePassword ) {
				return;
			}
			if ( inline ) {
				setMode( 'password' );
				return;
			}
			if ( bodyEl ) {
				bodyEl.hidden = true;
			}
			if ( switchBtn ) {
				switchBtn.setAttribute( 'aria-expanded', 'false' );
			}
			if ( nativeForm ) {
				nativeForm.style.display = '';
			}
			setStatus( '' );
			showStep( 'phone' );
		}

		/* Data collection and validation ------------------------------------ */

		function collect() {
			var data = {
				context: context,
				phone: phoneInput ? String( phoneInput.value ).trim() : ''
			};
			[ 'email', 'username', 'first_name', 'last_name', 'password' ].forEach( function ( name ) {
				var el = q( '.barar-otp__' + name, root );
				if ( el ) {
					data[ name ] = el.value;
				}
			} );

			// Let the server honour WordPress' redirect_to parameter.
			try {
				var redirect = new URLSearchParams( window.location.search ).get( 'redirect_to' );
				if ( redirect ) {
					data.redirect = redirect;
				}
			} catch ( e ) {
				// Older browsers: fall back to the server default redirect mode.
			}

			return data;
		}

		function validateClient( data ) {
			if ( ! data.phone ) {
				return cfg.i18n.phoneRequired;
			}
			if ( ! /[0-9]/.test( data.phone ) ) {
				return cfg.i18n.invalidPhone;
			}
			if ( isRegister ) {
				var emailRequired = root.getAttribute( 'data-email-required' ) !== '0';
				var email = ( data.email || '' ).trim();
				if ( emailRequired ? ! email || email.indexOf( '@' ) < 1 : email && email.indexOf( '@' ) < 1 ) {
					return cfg.i18n.emailRequired;
				}
				if ( ! passwordless && ( ! data.password || data.password.length < 6 ) ) {
					return cfg.i18n.passwordShort;
				}
				if ( root.getAttribute( 'data-first-name-required' ) === '1' && ! ( data.first_name || '' ).trim() ) {
					return cfg.i18n.firstRequired;
				}
				if ( root.getAttribute( 'data-last-name-required' ) === '1' && ! ( data.last_name || '' ).trim() ) {
					return cfg.i18n.lastRequired;
				}
			}
			return '';
		}

		function startCountdown( seconds ) {
			if ( countdownTimer ) {
				clearInterval( countdownTimer );
				countdownTimer = null;
			}
			var left = Math.max( 0, parseInt( seconds, 10 ) || 0 );
			if ( ! resendBtn ) {
				return;
			}
			resendBtn.disabled = true;

			var tick = function () {
				if ( left <= 0 ) {
					clearInterval( countdownTimer );
					countdownTimer = null;
					resendBtn.disabled = false;
					resendBtn.textContent = cfg.i18n.resendNow;
					return;
				}
				var m = Math.floor( left / 60 );
				var s = left % 60;
				var stamp = ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
				resendBtn.textContent = sprintf( cfg.i18n.resendIn, stamp );
				left -= 1;
			};
			tick();
			countdownTimer = setInterval( tick, 1000 );
		}

		/* Send / verify ------------------------------------------------------ */

		function sendOtp( isResend ) {
			if ( busy ) {
				return;
			}
			var data = collect();
			var problem = validateClient( data );
			if ( problem ) {
				setStatus( problem, 'error' );
				if ( ! data.phone && phoneInput ) {
					phoneInput.focus();
				}
				return;
			}

			busy = true;
			setBusy( sendBtn, true );
			setBusy( resendBtn, true );
			spinners( true );
			setStatus( cfg.i18n.sending, '' );

			post( cfg.actions.send, data )
				.then( function ( json ) {
					busy = false;
					setBusy( sendBtn, false );
					setBusy( resendBtn, false );
					spinners( false );

					if ( json && json.success ) {
						var d = json.data || {};
						if ( hintEl ) {
							hintEl.textContent = d.masked_phone
								? sprintf( cfg.i18n.sentTo, d.masked_phone )
								: ( d.message || '' );
						}
						setStatus( d.message || '', 'success' );
						showStep( 'code' );
						if ( codeInput ) {
							codeInput.value = '';
							codeInput.focus();
						}
						startCountdown( d.resend_after || resendSeconds );
						return;
					}

					var err = ( json && json.data ) || {};
					setStatus( err.message || cfg.i18n.network, 'error' );
					if ( err.retry_after ) {
						startCountdown( err.retry_after );
					}
					if ( isResend && err.code && err.code !== 'cooldown' ) {
						showStep( 'phone' );
					}
				} )
				.catch( function ( err ) {
					busy = false;
					setBusy( sendBtn, false );
					setBusy( resendBtn, false );
					spinners( false );
					setStatus( errorText( err ), 'error' );
				} );
		}

		function verifyCode() {
			if ( busy ) {
				return;
			}
			var data = collect();
			data.otp = codeInput ? String( codeInput.value ).replace( /\D+/g, '' ) : '';

			if ( ! data.otp ) {
				setStatus( cfg.i18n.codeRequired, 'error' );
				if ( codeInput ) {
					codeInput.focus();
				}
				return;
			}

			busy = true;
			setBusy( verifyBtn, true );
			spinners( true );
			setStatus( cfg.i18n.verifying, '' );

			post( cfg.actions.verify, data )
				.then( function ( json ) {
					busy = false;
					setBusy( verifyBtn, false );
					spinners( false );

					if ( json && json.success ) {
						var d = json.data || {};
						setStatus( d.message || '✓', 'success' );
						qa( 'button', codeStep ).forEach( function ( btn ) {
							btn.disabled = true;
						} );
						if ( codeInput ) {
							codeInput.readOnly = true;
						}
						if ( d.redirect ) {
							setTimeout( function () {
								window.location.assign( d.redirect );
							}, 700 );
						}
						return;
					}

					var err = ( json && json.data ) || {};
					setStatus( err.message || cfg.i18n.network, 'error' );
					if ( [ 'exists', 'exists_login', 'bad_email', 'bad_password', 'bad_name', 'duplicates', 'create_failed', 'unavailable', 'no_account' ].indexOf( err.code ) > -1 ) {
						showStep( 'phone' );
						if ( codeInput ) {
							codeInput.value = '';
						}
					}
					if ( err.code === 'expired' || err.code === 'attempts' ) {
						// The resend button (after its countdown) issues a fresh code.
						if ( resendBtn && resendBtn.disabled === false && codeInput ) {
							codeInput.value = '';
							codeInput.focus();
						}
					}
				} )
				.catch( function ( err ) {
					busy = false;
					setBusy( verifyBtn, false );
					spinners( false );
					setStatus( errorText( err ), 'error' );
				} );
		}

		/* Wiring ------------------------------------------------------------- */

		if ( switchBtn ) {
			switchBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				openPanel();
			} );
		}
		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				setMode( tab.getAttribute( 'data-mode' ) === 'password' ? 'password' : 'otp' );
			} );
		} );
		if ( backBtn ) {
			backBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				closePanel();
			} );
		}
		if ( sendBtn ) {
			sendBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				sendOtp( false );
			} );
		}
		if ( resendBtn ) {
			resendBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				sendOtp( true );
			} );
		}
		if ( verifyBtn ) {
			verifyBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				verifyCode();
			} );
		}
		if ( changeBtn ) {
			changeBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				showStep( 'phone' );
				setStatus( '' );
				if ( phoneInput ) {
					phoneInput.focus();
				}
			} );
		}
		if ( phoneInput ) {
			phoneInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					sendOtp( false );
				}
			} );
		}
		if ( codeInput ) {
			codeInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					verifyCode();
				}
			} );
			codeInput.addEventListener( 'input', function () {
				codeInput.value = String( codeInput.value ).replace( /\D+/g, '' );
			} );
		}

		/* Initial state ------------------------------------------------------ */

		if ( inline ) {
			// Start on the OTP controls when passwords are disabled, otherwise
			// on the untouched native form with the switch visible.
			setMode( hidePassword ? 'otp' : 'password' );
		} else if ( hidePassword ) {
			if ( switchBtn ) {
				switchBtn.hidden = true;
			}
			openPanel();
		}
	}

	qa( '.barar-otp' ).forEach( initPanel );
}() );
