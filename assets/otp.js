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
	/**
	 * Strip translation-plugin markers (TranslatePress' #!trpst#...#!trpen#)
	 * from every text in an AJAX answer. Those markers are only replaced by
	 * real markup in a finished page, never in a JSON response.
	 */
	function plainText( value ) {
		if ( 'string' === typeof value ) {
			if ( -1 === value.indexOf( '#!trp' ) ) {
				return value;
			}
			return value
				.replace( /#!trpst#trp-gettext[^#]*#!trpen#([\s\S]*?)#!trpst#\/trp-gettext#!trpen#/g, '$1' )
				.replace( /#!trp(?:st|en)#/g, '' );
		}
		if ( Array.isArray( value ) ) {
			return value.map( plainText );
		}
		if ( value && 'object' === typeof value ) {
			Object.keys( value ).forEach( function ( key ) {
				value[ key ] = plainText( value[ key ] );
			} );
		}
		return value;
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
				return plainText( json );
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

	/**
	 * Resend button text while a countdown runs: the button's own label (set
	 * in the settings, possibly empty) followed by the time left. A blank
	 * label leaves just the time, so the countdown never needs any wording.
	 */
	function resendText( btn, time ) {
		var base = ( btn && btn.getAttribute( 'data-label' ) ) || '';
		return ( base + ' ' + time ).trim();
	}

	/**
	 * Resend button text once a new code may be requested. With no label a
	 * plain refresh symbol stands in, so the button is never an empty box.
	 */
	function resendReady( btn ) {
		return ( ( btn && btn.getAttribute( 'data-label' ) ) || '' ) || '\u21bb';
	}

	function initPanel( root ) {
		var context = root.getAttribute( 'data-context' ) || '';
		var targetSel = root.getAttribute( 'data-target' ) || '';
		var inline = root.getAttribute( 'data-inline' ) === '1';
		var isRegister = root.getAttribute( 'data-register' ) === '1';
		var isReset = root.getAttribute( 'data-reset' ) === '1';
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
		var pwdToggleBtn = q( '.barar-otp__password-toggle', root );

		var busy = false;
		var countdownTimer = null;
		var showPwdToggle = root.getAttribute( 'data-show-password-toggle' ) === '1';

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
				if ( inline ) {
					setNativeRows( false );
				} else if ( nativeForm ) {
					nativeForm.style.display = '';
				}
				setStatus( '' );
				showStep( 'phone' );
			} else {
				if ( bodyEl ) {
					bodyEl.hidden = false;
				}
				if ( inline ) {
					setNativeRows( true );
				} else if ( nativeForm ) {
					nativeForm.style.display = 'none';
				}
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

			// A fixed redirect target (checkout, shortcodes) wins over the
			// WordPress redirect_to parameter.
			var fixedRedirect = root.getAttribute( 'data-redirect' ) || '';
			if ( fixedRedirect ) {
				data.redirect = fixedRedirect;
			}

			// Let the server honour WordPress' redirect_to parameter.
			try {
				var redirect = new URLSearchParams( window.location.search ).get( 'redirect_to' );
				if ( redirect && ! data.redirect ) {
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
					resendBtn.textContent = resendReady( resendBtn );
					return;
				}
				var m = Math.floor( left / 60 );
				var s = left % 60;
				var stamp = ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
				resendBtn.textContent = resendText( resendBtn, stamp );
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

			// A password reset needs the new password before the code is used.
			if ( isReset && ( ! data.password || data.password.length < 6 ) ) {
				setStatus( cfg.i18n.passwordShort, 'error' );
				var resetPwd = q( '.barar-otp__password', root );
				if ( resetPwd ) {
					resetPwd.focus();
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
					// A password problem (bad/too short) during a reset keeps the
					// code usable, so stay on the code step where the field lives.
					var passwordStepBack = !( isReset && ( 'bad_password' === err.code ) );
					if ( passwordStepBack && [ 'exists', 'exists_login', 'bad_email', 'bad_password', 'bad_name', 'duplicates', 'create_failed', 'unavailable', 'no_account' ].indexOf( err.code ) > -1 ) {
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

		/* Password toggle ------------------------------------------------------ */
		if ( pwdToggleBtn && showPwdToggle ) {
			var pwdInput = q( '.barar-otp__password', root );
			var eyeIcon = q( '.barar-otp__eye-icon', pwdToggleBtn );
			var eyeOffIcon = q( '.barar-otp__eye-off-icon', pwdToggleBtn );

			pwdToggleBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( ! pwdInput ) {
					return;
				}
				var isPassword = pwdInput.type === 'password';
				pwdInput.type = isPassword ? 'text' : 'password';
				pwdToggleBtn.setAttribute( 'aria-pressed', isPassword ? 'true' : 'false' );
				pwdToggleBtn.setAttribute( 'aria-label', isPassword ? cfg.i18n.hide || 'Hide password' : cfg.i18n.show || 'Show password' );
				if ( eyeIcon && eyeOffIcon ) {
					eyeIcon.style.display = isPassword ? 'none' : 'block';
					eyeOffIcon.style.display = isPassword ? 'block' : 'none';
				}
			} );
		}

		/* Initial state ------------------------------------------------------ */

		setMode( 'otp' );

		if ( hidePassword ) {
			if ( switchBtn ) {
				switchBtn.hidden = true;
			}
			if ( backBtn ) {
				backBtn.hidden = true;
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Native form enhancements: password eye icons and email autofill.
	 * These touch the WordPress/WooCommerce forms themselves and are
	 * controlled by two admin settings (see cfg.flags).
	 * ------------------------------------------------------------------ */

	var EYE_OPEN = '<svg class="barar-eye" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
	var EYE_SHUT = '<svg class="barar-eye barar-eye--off" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display:none;"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';

	var EMAIL_FIELDS = 'input[type="email"], input[name="email"], input[name="user_email"], input[name="reg_email"], input[name="billing_email"], input#user_email, input#reg_email';
	var PASSWORD_FIELDS = '#loginform input[type="password"], #registerform input[type="password"], .woocommerce-form-login input[type="password"], .woocommerce-form-register input[type="password"], .woocommerce-form-lost_password input[type="password"], .woocommerce-ResetPassword input[type="password"], input[name="reg_password"], input[name="pass1"], input[name="pass2"], input[name="account_password"], input[name="billing_password"]';

	function addPasswordToggle( input ) {
		if ( ! input || ! input.parentNode || ( input.closest && input.closest( '.barar-pwd' ) ) ) {
			return;
		}
		// Never touch the plugin's own fields: they already have a toggle.
		if ( input.closest && input.closest( '.barar-otp' ) ) {
			return;
		}
		var wrap = document.createElement( 'span' );
		wrap.className = 'barar-pwd';
		input.parentNode.insertBefore( wrap, input );
		wrap.appendChild( input );

		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'barar-pwd__toggle';
		btn.setAttribute( 'aria-pressed', 'false' );
		btn.setAttribute( 'aria-label', ( cfg.i18n && cfg.i18n.show ) || 'Show password' );
		btn.innerHTML = EYE_OPEN + EYE_SHUT;
		wrap.appendChild( btn );

		btn.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			var showing = input.type === 'text';
			input.type = showing ? 'password' : 'text';
			btn.setAttribute( 'aria-pressed', showing ? 'false' : 'true' );
			btn.setAttribute(
				'aria-label',
				showing
					? ( ( cfg.i18n && cfg.i18n.show ) || 'Show password' )
					: ( ( cfg.i18n && cfg.i18n.hide ) || 'Hide password' )
			);
			var openIcon = q( '.barar-eye', btn );
			var shutIcon = q( '.barar-eye--off', btn );
			if ( openIcon && shutIcon ) {
				openIcon.style.display = showing ? 'block' : 'none';
				shutIcon.style.display = showing ? 'none' : 'block';
			}
		} );
	}

	function enhanceNativeForms() {
		if ( ! cfg || ! cfg.flags ) {
			return;
		}
		if ( cfg.flags.eyeToggle ) {
			qa( PASSWORD_FIELDS ).forEach( addPasswordToggle );
		}
		if ( cfg.flags.autocomplete ) {
			qa( EMAIL_FIELDS ).forEach( function ( input ) {
				if ( input.closest && input.closest( '.barar-otp' ) ) {
					return;
				}
				input.setAttribute( 'autocomplete', 'email' );
				input.setAttribute( 'inputmode', 'email' );
				var type = String( input.getAttribute( 'type' ) || '' ).toLowerCase();
				if ( '' === type || 'text' === type ) {
					input.setAttribute( 'type', 'email' );
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', enhanceNativeForms );
	} else {
		enhanceNativeForms();
	}
	qa( '.barar-otp' ).forEach( initPanel );

	/* ---------------------------------------------------------------------
	 * Checkout authentication tabs.
	 *
	 * .barar-checkout-auth[data-barar-checkout-auth]
	 *   .barar-checkout-auth__tab[data-barar-tab]
	 *   .barar-checkout-auth__panel[data-barar-panel]
	 *
	 * The panel that matches the active tab is the only one shown. Phone OTP
	 * Login is the default; the markup already marks it active, and this only
	 * makes that state authoritative once JS is running.
	 * ------------------------------------------------------------------ */

	/**
	 * Has this node already been wired up?
	 *
	 * WooCommerce re-runs initCheckoutWidgets() on every totals refresh, and
	 * the observer sees nodes as they are inserted. Binding twice would make
	 * every click fire two requests, so each node is claimed once.
	 */
	function claim( el ) {
		if ( ! el || el.getAttribute( 'data-barar-ready' ) === '1' ) {
			return false;
		}
		el.setAttribute( 'data-barar-ready', '1' );
		return true;
	}

	/**
	 * Show WooCommerce's own login form inside a panel.
	 *
	 * WooCommerce renders it with an inline `display:none` and expects the
	 * "Click here to login" link above it to reveal it. Here the tab button
	 * already plays that role, so the form is shown directly and the link -
	 * which would otherwise be a dead end, because WooCommerce's handler is
	 * bound to `body` and toggles a class nothing here listens to - is hidden.
	 *
	 * @param {Element} panel The panel that was just made visible.
	 */
	function revealNativeLogin( panel ) {
		var forms = qa( '.woocommerce-form-login, .woocommerce-form-register', panel );

		if ( ! forms.length ) {
			return;
		}

		forms.forEach( function ( form ) {
			form.style.display = '';
			form.removeAttribute( 'hidden' );
		} );

		qa( '.woocommerce-form-login-toggle', panel ).forEach( function ( toggle ) {
			toggle.style.display = 'none';
		} );
	}

	function initAuthTabs( root ) {
		var tabEls   = qa( '.barar-checkout-auth__tab', root );
		var panelEls = qa( '.barar-checkout-auth__panel', root );

		if ( ! tabEls.length || ! claim( root ) ) {
			return;
		}

		function activate( key ) {
			var found = false;

			tabEls.forEach( function ( tab ) {
				var active = ( tab.getAttribute( 'data-barar-tab' ) === key );
				if ( active ) {
					found = true;
				}
				tab.classList.toggle( 'is-active', active );
				tab.setAttribute( 'aria-selected', active ? 'true' : 'false' );
				tab.setAttribute( 'tabindex', active ? '0' : '-1' );
			} );

			if ( ! found ) {
				return;
			}

			panelEls.forEach( function ( panel ) {
				var on = ( panel.getAttribute( 'data-barar-panel' ) === key );
				panel.hidden = ! on;

				if ( on ) {
					revealNativeLogin( panel );
				}
			} );
		}

		tabEls.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activate( tab.getAttribute( 'data-barar-tab' ) );
			} );

			// Left/right arrows move between tabs, as a tablist should.
			tab.addEventListener( 'keydown', function ( event ) {
				var step = ( 'ArrowRight' === event.key ) ? 1 : ( 'ArrowLeft' === event.key ? -1 : 0 );
				if ( ! step ) {
					return;
				}
				event.preventDefault();
				var next = tabEls[ ( index + step + tabEls.length ) % tabEls.length ];
				next.focus();
				activate( next.getAttribute( 'data-barar-tab' ) );
			} );
		} );

		var initial = tabEls[ 0 ].getAttribute( 'data-barar-tab' );
		activate( initial );
	}

	/* ---------------------------------------------------------------------
	 * Checkout authentication popup: a dialog holding the same tab group.
	 *
	 * Body scroll is locked while open and focus is restored to the trigger on
	 * close, so the page is left exactly as it was found.
	 * ------------------------------------------------------------------ */

	function initAuthPopup( wrap ) {
		var dialog = q( '.barar-auth-popup__dialog', wrap );
		if ( ! dialog || ! claim( wrap ) ) {
			return;
		}

		var openBtn = q( '[data-barar-popup-open]', wrap ) || q( '.barar-auth-popup__open', wrap );
		var closers = qa( '[data-barar-popup-close]', wrap );
		var lastFocus = null;

		function open() {
			lastFocus = document.activeElement;
			dialog.hidden = false;
			document.body.classList.add( 'barar-auth-popup--open' );
			if ( openBtn ) {
				openBtn.setAttribute( 'aria-expanded', 'true' );
			}
			var focusable = q( 'input, button, [tabindex]', dialog );
			if ( focusable ) {
				focusable.focus();
			}
		}

		function close() {
			if ( dialog.hidden ) {
				return;
			}
			dialog.hidden = true;
			document.body.classList.remove( 'barar-auth-popup--open' );
			if ( openBtn ) {
				openBtn.setAttribute( 'aria-expanded', 'false' );
			}
			if ( lastFocus && lastFocus.focus ) {
				lastFocus.focus();
			}
		}

		if ( openBtn ) {
			openBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				dialog.hidden ? open() : close();
			} );
		}

		closers.forEach( function ( el ) {
			el.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				close();
			} );
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! dialog.hidden ) {
				close();
			}
		} );
	}

	/* ---------------------------------------------------------------------
	 * Guest checkout verification.
	 *
	 * The Place Order button stays disabled until the server accepts the code.
	 * That is only the friendly half: woocommerce_checkout_process refuses the
	 * order server side when the proof does not match the submitted phone
	 * number and email address, so re-enabling the button here cannot be used
	 * to skip verification.
	 * ------------------------------------------------------------------ */

	/**
	 * Has this page session already proven a pair of contact details?
	 *
	 * The proof itself is held server side in the WooCommerce session, so it
	 * survives the #payment replacements WooCommerce makes while the customer
	 * types. This flag is only the client-side mirror of it, used to render the
	 * replacement widget already in its verified state.
	 */
	var proven = false;

	function initVerify( root ) {
		if ( ! claim( root ) ) {
			return;
		}

		/*
		 * Popup mode (data-popup="1"): this widget lives inside the dialog
		 * printed after the checkout form, a Place Order click opens it and
		 * sends the code, and an accepted code closes it and submits the
		 * order. Inline mode keeps the old behaviour: the widget sits next to
		 * the button and the button stays disabled until proven.
		 */
		var isPopup = '1' === root.getAttribute( 'data-popup' );
		var popupWrap = isPopup && root.closest ? root.closest( '[data-barar-verify-popup]' ) : null;
		var dialog = popupWrap ? q( '.barar-verify-popup__dialog', popupWrap ) : null;
		var granted = proven;

		/*
		 * Themes wrap the checkout in containers with transform, filter or
		 * overflow rules, and any of those turns position:fixed into
		 * "inside that container". Moved to <body>, the dialog is always
		 * centred on the screen, whatever the theme does.
		 */
		if ( popupWrap && document.body && popupWrap.parentNode !== document.body ) {
			document.body.appendChild( popupWrap );
		}

		/**
		 * The live Place Order button.
		 *
		 * WooCommerce throws #payment away on every totals refresh, so the
		 * element is looked up on each use instead of being captured once -
		 * a captured reference would go stale and gate()/grant() would then
		 * silently act on a detached node.
		 */
		function placeOrderBtn() {
			return document.getElementById( 'place_order' );
		}

		function gate() {
			var placeOrder = placeOrderBtn();
			if ( ! placeOrder ) {
				return;
			}
			if ( isPopup && dialog ) {
				/*
				 * Clickable by design: the click opens the dialog, so there is
				 * nothing to gate visually here. The refusal is server side
				 * (woocommerce_checkout_process) plus the capture-phase
				 * listeners installed at the bottom of this function, which
				 * stop an unproven click or submit from ever reaching
				 * WooCommerce.
				 */
				placeOrder.disabled = false;
				placeOrder.classList.remove( 'is-disabled' );
				placeOrder.removeAttribute( 'aria-disabled' );
				return;
			}
			placeOrder.disabled = ! granted;
			placeOrder.classList.toggle( 'is-disabled', ! granted );
			placeOrder.setAttribute( 'aria-disabled', granted ? 'false' : 'true' );
		}
		gate();

		var phoneEl;   // Not a widget input: an alias resolved below.
		var emailEl;

		/*
		 * The details to prove are the ones WooCommerce will actually order
		 * with, so they come from the billing fields rather than from a second
		 * copy of them inside this widget. Two sets of inputs would let them
		 * drift apart, and the server compares the proof against
		 * billing_phone / billing_email specifically.
		 */
		var billingPhone = q( '#billing_phone' );
		var billingEmail = q( '#billing_email' );
		var phoneTarget  = q( '[data-barar-verify-target="phone"]', root );
		var emailTarget  = q( '[data-barar-verify-target="email"]', root );
		var editBtns     = qa( '[data-barar-verify-edit]', root );
		var codeEl       = q( '.barar-verify__code', root );
		var sendBtn      = q( '.barar-verify__send', root );
		var okBtn        = q( '.barar-verify__ok', root );
		var resendBtn    = q( '.barar-verify__resend', root );
		var changeBtn    = q( '.barar-verify__change', root );
		var sendStep     = q( '.barar-verify__step--send', root );
		var codeStep     = q( '.barar-verify__step--code', root );
		var hintEl       = q( '.barar-verify__hint', root );
		var statusEl     = q( '.barar-verify__status', root );
		var doneEl       = q( '.barar-verify__done', root );
		var restartBtn   = q( '.barar-verify__restart', root );
		var spinnerEls   = qa( '.barar-verify__spinner', root );
		var expiryEl     = q( '[data-barar-expiry]', root );
		var barWrap      = q( '.barar-verify__bar', root );
		var barEl        = q( '[data-barar-bar]', root );
		var expiryTimer  = null;

		// A button without a label gets a symbol instead of a blank box.
		if ( sendBtn && ! String( sendBtn.textContent ).trim() ) {
			sendBtn.textContent = '\u2192';
		}
		if ( okBtn && ! String( okBtn.textContent ).trim() ) {
			okBtn.textContent = '\u2713';
		}
		var resendSeconds = parseInt( root.getAttribute( 'data-resend' ), 10 ) || 60;

		if ( '1' === root.getAttribute( 'data-phone' ) ) {
			phoneEl = billingPhone;
		}
		if ( '1' === root.getAttribute( 'data-email' ) ) {
			emailEl = billingEmail;
		}

		var busy = false;
		var countdownTimer = null;

		function setStatus( message, type ) {
			if ( ! statusEl ) {
				return;
			}
			statusEl.textContent = message || '';
			statusEl.className = 'barar-verify__status' + ( type ? ' is-' + type : '' );
		}

		function showStep( which ) {
			if ( sendStep ) {
				sendStep.hidden = ( 'send' !== which );
			}
			if ( codeStep ) {
				codeStep.hidden = ( 'code' !== which );
			}
		}

		function spinners( on ) {
			spinnerEls.forEach( function ( el ) {
				el.hidden = ! on;
			} );
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
					resendBtn.textContent = resendReady( resendBtn );
					return;
				}
				var m = Math.floor( left / 60 );
				var s = left % 60;
				resendBtn.textContent = resendText( resendBtn, ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s );
				left -= 1;
			};
			tick();
			countdownTimer = setInterval( tick, 1000 );
		}

		/**
		 * Show how long the code stays valid: mm:ss plus a shrinking bar.
		 */
		function stopExpiry() {
			if ( expiryTimer ) {
				clearInterval( expiryTimer );
				expiryTimer = null;
			}
		}

		function startExpiry( seconds ) {
			stopExpiry();
			var total = Math.max( 0, parseInt( seconds, 10 ) || 0 );
			if ( ! expiryEl || ! total ) {
				return;
			}
			var left = total;
			var paint = function () {
				var m = Math.floor( left / 60 );
				var s = left % 60;
				expiryEl.textContent = ( m < 10 ? '0' : '' ) + m + ':' + ( s < 10 ? '0' : '' ) + s;
				if ( barEl ) {
					barEl.style.width = Math.max( 0, Math.min( 100, ( left / total ) * 100 ) ) + '%';
				}
				if ( barWrap ) {
					barWrap.classList.toggle( 'is-low', left <= 30 );
				}
			};
			paint();
			expiryTimer = setInterval( function () {
				left -= 1;
				if ( left <= 0 ) {
					left = 0;
					stopExpiry();
				}
				paint();
			}, 1000 );
		}

		/**
		 * Inside the popup the code step is the only screen: after a failure
		 * the shopper stays on it and uses the resend button, instead of
		 * dropping to a details step the popup does not show.
		 */
		function retryStep() {
			if ( isPopup ) {
				showStep( 'code' );
				if ( resendBtn && ! countdownTimer ) {
					resendBtn.disabled = false;
					resendBtn.textContent = resendReady( resendBtn );
				}
				return;
			}
			showStep( 'send' );
		}

		function grant() {
			stopExpiry();
			granted = true;
			showStep( 'done' );
			if ( doneEl ) {
				doneEl.hidden = false;
			}
			if ( codeStep ) {
				qa( 'button', codeStep ).forEach( function ( btn ) {
					btn.disabled = true;
				} );
			}
			if ( codeEl ) {
				codeEl.readOnly = true;
			}
			var placeOrder = placeOrderBtn();
			if ( placeOrder ) {
				placeOrder.disabled = false;
				placeOrder.removeAttribute( 'aria-disabled' );
				placeOrder.classList.remove( 'is-disabled' );
			}
			markProven();

			/*
			 * The accepted code was the last missing piece of the Place Order
			 * click that opened this dialog: close it and finish the job.
			 * WooCommerce validates the form as usual, so fields the shopper
			 * left incomplete simply show their own errors and the proof is
			 * still waiting for the next attempt.
			 */
			if ( isPopup && dialog ) {
				closePopup();
				window.setTimeout( submitOrder, 250 );
			}
		}

		/**
		 * Re-run the Place Order click now that the proof exists.
		 */
		function submitOrder() {
			if ( ! granted ) {
				return;
			}
			var placeOrder = placeOrderBtn();
			if ( ! placeOrder ) {
				return;
			}
			placeOrder.disabled = false;
			placeOrder.removeAttribute( 'aria-disabled' );
			placeOrder.classList.remove( 'is-disabled' );
			placeOrder.click();
		}

		/* Popup open / close ------------------------------------------------ */

		function openPopup() {
			if ( ! dialog || ! dialog.hidden ) {
				return;
			}
			preview();
			dialog.hidden = false;
			document.body.classList.add( 'barar-verify-popup--open' );

			/*
			 * The code goes out as soon as the shopper asks to place the
			 * order. A code already on its way for these exact details is
			 * answered by the server as a success (same pair, inside the
			 * resend window), so reopening never costs a second message.
			 */
			if ( ! granted ) {
				send( false );
			}
		}

		function closePopup() {
			if ( ! dialog || dialog.hidden ) {
				return;
			}
			dialog.hidden = true;
			document.body.classList.remove( 'barar-verify-popup--open' );
		}

		/**
		 * Tell the widget that a fresh copy of itself is already proven.
		 *
		 * WooCommerce throws #payment away on every totals change, so the
		 * shopper can type in the address fields, watch the summary refresh
		 * and come back to an empty verification box. The server-side proof
		 * lives in the WooCommerce session and does not expire on a refresh,
		 * so the replacement widget is told to open in its verified state
		 * rather than asking for the same code twice.
		 */
		function markProven() {
			proven = true;
			root.setAttribute( 'data-barar-verified', '1' );
		}

		/**
		 * Undo a proof: the customer edited a contact detail after verifying.
		 *
		 * Only ever a convenience - the server compares the proof against the
		 * details actually posted, so this never decides anything.
		 */
		function revoke() {
			proven = false;
			granted = false;
			root.removeAttribute( 'data-barar-verified' );
			gate();
		}

		function collect() {
			return {
				phone: billingPhone ? String( billingPhone.value ).trim() : '',
				email: billingEmail ? String( billingEmail.value ).trim() : ''
			};
		}

		/**
		 * Keep the read-only preview in step with the billing fields.
		 */
		function preview() {
			var data = collect();

			if ( phoneTarget ) {
				phoneTarget.textContent = data.phone;
			}
			if ( emailTarget ) {
				emailTarget.textContent = data.email;
			}
			return data;
		}

		/**
		 * Scroll a billing field into view and put the caret in it.
		 */
		function focusBilling( field ) {
			if ( ! field ) {
				return;
			}
			field.focus();
			if ( field.select ) {
				field.select();
			}
			if ( field.scrollIntoView ) {
				field.scrollIntoView( {
					block: 'center',
					behavior: 'smooth'
				} );
			}
		}

		editBtns.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				// The dialog covers the billing fields; step out of it first.
				if ( isPopup ) {
					closePopup();
				}
				focusBilling( ( 'email' === btn.getAttribute( 'data-barar-verify-edit' ) ) ? billingEmail : billingPhone );
			} );
		} );

		function send( isResend ) {
			if ( busy ) {
				return;
			}
			var data = collect();

			// Catch an empty detail before asking the server, which would
			// refuse it anyway and leave the shopper with an error banner.
			// The dialog is in the way of the field being pointed at, so it
			// steps aside and puts the caret in the billing input itself.
			if ( phoneEl && ! data.phone ) {
				setStatus( cfg.i18n.phoneRequired, 'error' );
				if ( isPopup ) {
					closePopup();
				}
				focusBilling( billingPhone );
				return;
			}
			// The email address is verified when one is given; it is only
			// mandatory when no phone channel exists to carry the proof.
			if ( emailEl && ! data.email && ! phoneEl ) {
				setStatus( cfg.i18n.emailRequired, 'error' );
				if ( isPopup ) {
					closePopup();
				}
				focusBilling( billingEmail );
				return;
			}

			busy = true;
			setBusy( sendBtn, true );
			setBusy( resendBtn, true );
			spinners( true );
			setStatus( cfg.i18n.sending, '' );

			post( cfg.actions.vsend, data )
				.then( function ( json ) {
					busy = false;
					setBusy( sendBtn, false );
					setBusy( resendBtn, false );
					spinners( false );

					if ( json && json.success ) {
						var d = json.data || {};
						if ( hintEl ) {
							hintEl.textContent = d.sent_to || d.message || '';
						}
						setStatus( d.message || '', 'success' );
						showStep( 'code' );
						if ( codeEl ) {
							codeEl.value = '';
							codeEl.focus();
						}
						startCountdown( d.resend_after || resendSeconds );
						startExpiry( d.expires_in );
						return;
					}

					var err = ( json && json.data ) || {};
					setStatus( err.message || cfg.i18n.network, 'error' );
					if ( err.retry_after ) {
						startCountdown( err.retry_after );
					}
					// A failed send leaves the shopper somewhere they can
					// try again (popup: the code step with a live resend
					// button; inline: the details step).
					if ( err.code && 'cooldown' !== err.code ) {
						if ( isPopup ) {
							retryStep();
						} else if ( isResend ) {
							showStep( 'send' );
						}
					}
				} )
				.catch( function ( err ) {
					busy = false;
					setBusy( sendBtn, false );
					setBusy( resendBtn, false );
					spinners( false );
					setStatus( errorText( err ), 'error' );
					if ( isPopup ) {
						retryStep();
					}
				} );
		}

		function check() {
			if ( busy ) {
				return;
			}
			var data = collect();
			data.code = codeEl ? String( codeEl.value ).replace( /\D+/g, '' ) : '';

			if ( ! data.code ) {
				setStatus( cfg.i18n.codeRequired, 'error' );
				if ( codeEl ) {
					codeEl.focus();
				}
				return;
			}

			busy = true;
			setBusy( okBtn, true );
			spinners( true );
			setStatus( cfg.i18n.verifying, '' );

			post( cfg.actions.vcheck, data )
				.then( function ( json ) {
					busy = false;
					setBusy( okBtn, false );
					spinners( false );

					if ( json && json.success ) {
						setStatus( ( json.data && json.data.message ) || cfg.i18n.verifySuccess, 'success' );
						grant();
						return;
					}

					var err = ( json && json.data ) || {};
					setStatus( err.message || cfg.i18n.network, 'error' );
					// A spent, expired or exhausted code needs a fresh one.
					if ( [ 'no_code', 'expired', 'too_many' ].indexOf( err.code ) > -1 ) {
						stopExpiry();
						retryStep();
					}
				} )
				.catch( function ( err ) {
					busy = false;
					setBusy( okBtn, false );
					spinners( false );
					setStatus( errorText( err ), 'error' );
				} );
		}

		if ( sendBtn ) {
			sendBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				send( false );
			} );
		}
		if ( resendBtn ) {
			resendBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				send( true );
			} );
		}
		if ( okBtn ) {
			okBtn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				check();
			} );
		}
		/**
		 * Put the widget back on the "confirm the details" step.
		 */
		function resetSteps() {
			stopExpiry();
			showStep( 'send' );
			preview();
			setStatus( granted ? cfg.i18n.verifyNeeded : '' );
			if ( doneEl ) {
				doneEl.hidden = true;
			}
			if ( codeEl ) {
				codeEl.value = '';
				codeEl.readOnly = false;
			}
			if ( codeStep ) {
				qa( 'button', codeStep ).forEach( function ( btn ) {
					btn.disabled = false;
				} );
			}
		}

		[ changeBtn, restartBtn ].forEach( function ( btn ) {
			if ( ! btn ) {
				return;
			}
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				revoke();
				resetSteps();
			} );
		} );

		// Re-gate as soon as a proven detail is edited, so the shopper is not
		// shown "verified" for a number they have just changed. The server makes
		// the same comparison when the order is submitted.
		[ billingPhone, billingEmail ].forEach( function ( field ) {
			if ( ! field ) {
				return;
			}
			field.addEventListener( 'input', function () {
				preview();
				if ( ! granted ) {
					return;
				}
				revoke();
				resetSteps();
			} );
			field.addEventListener( 'change', preview );
		} );

		if ( codeEl ) {
			codeEl.addEventListener( 'input', function () {
				codeEl.value = String( codeEl.value ).replace( /\D+/g, '' );
			} );
			codeEl.addEventListener( 'keydown', function ( event ) {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					check();
				}
			} );
		}

		preview();

		// Initial state: a widget created after a proof already exists opens in
		// its verified state, everything else starts on the details step.
		if ( granted ) {
			showStep( 'done' );
			if ( doneEl ) {
				doneEl.hidden = false;
			}
		} else {
			showStep( 'send' );
		}

		/* Popup wiring ------------------------------------------------------ */

		if ( isPopup && dialog ) {
			qa( '[data-barar-verify-close]', popupWrap ).forEach( function ( el ) {
				el.addEventListener( 'click', function ( event ) {
					event.preventDefault();
					closePopup();
				} );
			} );

			document.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && ! dialog.hidden ) {
					closePopup();
				}
			} );

			/*
			 * Is the order otherwise ready to go?
			 *
			 * WooCommerce never sets the HTML5 required attribute - it marks
			 * fields with aria-required="true" and .validate-required instead,
			 * which native validation does not look at - so emptiness has to be
			 * read from those. A missing field is deliberately left for
			 * WooCommerce's own validation to report: the dialog is simply held
			 * back for now and the click carries on to the usual inline errors.
			 * Only once the order could go through without it is a code worth
			 * sending.
			 */
			function formIsComplete() {
				var form = q( 'form.checkout, form[name="checkout"]' );
				if ( ! form ) {
					return true;
				}

				/*
				 * Place Order must always lead to the code dialog first, so
				 * the only thing that can hold it back is a missing contact
				 * detail the code has nowhere to go to. Every other empty
				 * field is reported by WooCommerce once the code was accepted
				 * - the proof stays valid until then, so nothing is lost.
				 * These checks mirror ajax_send(): the phone carries the
				 * proof on its own, so an email is only indispensable when
				 * there is no phone channel to fall back on.
				 */
				var phoneNeeded = '1' === root.getAttribute( 'data-phone' );
				var emailNeeded = '1' === root.getAttribute( 'data-email' );

				if ( phoneNeeded && billingPhone && ! String( billingPhone.value || '' ).trim() ) {
					return false;
				}
				if ( emailNeeded && ! phoneNeeded && billingEmail && ! String( billingEmail.value || '' ).trim() ) {
					return false;
				}

				return true;
			}

			/*
			 * Every click on Place Order while the pair is unproven opens the
			 * dialog instead of submitting. Capture phase on document: it runs
			 * before WooCommerce's own handlers anywhere down the tree, and
			 * stopPropagation keeps them from ever seeing the click. This -
			 * together with the server-side guard - is the gate: the button
			 * itself cannot be disabled, because a disabled button fires no
			 * click events at all.
			 */
			document.addEventListener( 'click', function ( event ) {
				if ( granted ) {
					return;
				}
				var btn = event.target && event.target.closest ? event.target.closest( '#place_order' ) : null;
				if ( ! btn ) {
					return;
				}
				// Fields still missing: carry on and let WooCommerce show its
				// own errors rather than asking for a code nobody can use yet.
				if ( ! formIsComplete() ) {
					return;
				}
				event.preventDefault();
				event.stopPropagation();
				if ( ! dialog.hidden ) {
					return;
				}
				openPopup();
			}, true );

			/*
			 * The same gate for a form submitted without a click. Running at
			 * the document in the capture phase guarantees this listener fires
			 * before WooCommerce's submit handler on the form itself, wherever
			 * it is bound. The programmatic submit after an accepted code
			 * passes straight through because granted is already true.
			 */
			document.addEventListener( 'submit', function ( event ) {
				if ( granted || ! event.target || ! event.target.matches ) {
					return;
				}
				if ( ! event.target.matches( 'form.checkout, form[name="checkout"]' ) ) {
					return;
				}
				if ( ! formIsComplete() ) {
					return;
				}
				event.preventDefault();
				event.stopPropagation();
				if ( ! dialog.hidden ) {
					return;
				}
				openPopup();
			}, true );
		}
	}

	function initCheckoutWidgets() {
		qa( '[data-barar-checkout-auth]' ).forEach( initAuthTabs );
		qa( '[data-barar-auth-popup]' ).forEach( initAuthPopup );
		qa( '[data-barar-verify]' ).forEach( initVerify );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', initCheckoutWidgets );
	} else {
		initCheckoutWidgets();
	}

	/*
	 * WooCommerce replaces #payment wholesale on every totals refresh, so both
	 * the verification widget and the Place Order button are re-created. The
	 * replacement widget re-gates the button, which is why this has to run
	 * again. claim() keeps the re-run idempotent.
	 */
	if ( window.jQuery ) {
		window.jQuery( document.body ).on( 'updated_checkout', initCheckoutWidgets );
	}

	/*
	 * The jQuery event above covers stock WooCommerce checkout. Block themes
	 * and checkout blocks replace the payment area without firing it, so the
	 * DOM itself is watched as well. Mutations are coalesced into one pass per
	 * frame, otherwise a single refresh would run a dozen scans.
	 */
	if ( 'undefined' !== typeof window.MutationObserver && document.body ) {
		var queued = false;

		function rescan() {
			if ( queued ) {
				return;
			}
			queued = true;

			var run = function () {
				queued = false;
				initCheckoutWidgets();
			};

			if ( window.requestAnimationFrame ) {
				window.requestAnimationFrame( run );
			} else {
				window.setTimeout( run, 16 );
			}
		}

		new window.MutationObserver( rescan ).observe( document.body, {
			childList: true,
			subtree: true
		} );
	}
}() );
