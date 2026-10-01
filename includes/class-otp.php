<?php
/**
 * Phone-number OTP authentication: generation, storage, rate limiting,
 * verification, login and registration.
 *
 * Security properties:
 *  - only an HMAC of the code is stored (no plaintext OTP in options/transients);
 *  - codes expire, are single use and are replaced when a new one is requested;
 *  - per phone number and per IP rate limits plus a resend cooldown;
 *  - bounded verification attempts;
 *  - login uses WordPress' own auth cookie functions and the wp_login hook;
 *  - registration uses wp_insert_user / wc_create_new_customer.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_OTP {

	const NONCE    = 'barar_atik_otp';
	const CONTEXTS = array( 'wp_login', 'wp_register', 'wc_login', 'wc_register' );

	/**
	 * Send pipeline.
	 *
	 * @var Barar_Atik_SMS
	 */
	private $sms;

	/**
	 * Dependencies.
	 *
	 * @param Barar_Atik_SMS $sms Send pipeline.
	 */
	public function __construct( $sms ) {
		$this->sms = $sms;

		add_action( 'wp_ajax_barar_atik_send_otp', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_nopriv_barar_atik_send_otp', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_barar_atik_verify_otp', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_nopriv_barar_atik_verify_otp', array( $this, 'ajax_verify' ) );
	}

	/* ---------------------------------------------------------------------
	 * Response helpers (wp_send_json_* never return).
	 * ------------------------------------------------------------------ */

	/**
	 * Send a structured error.
	 *
	 * @param string $code    Machine code.
	 * @param string $message User facing message.
	 * @param array  $extra   Extra data.
	 * @return void
	 */
	private function fail( $code, $message, $extra = array() ) {
		wp_send_json_error( array_merge( array( 'code' => $code, 'message' => $message ), $extra ) );
	}

	/**
	 * Send a structured success.
	 *
	 * @param array $data Data.
	 * @return void
	 */
	private function ok( $data = array() ) {
		wp_send_json_success( $data );
	}

	/**
	 * Guarantee a clean JSON response body.
	 *
	 * A single PHP notice/warning (or output from a conflicting plugin)
	 * printed before the JSON payload makes the browser reject the whole
	 * response, which the client then reports as a generic error. Display of
	 * errors is disabled for the remainder of this request and any buffered
	 * output is dropped so wp_send_json_* always emits parseable JSON.
	 *
	 * @return void
	 */
	private function prepare_json() {
		/*
		 * JSON endpoints must never be polluted by notices or PHP errors,
		 * so error display is switched off and buffers are discarded.
		 *
		 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
		 * phpcs:disable PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors
		 * phpcs:disable Squiz.PHP.DiscouragedFunctions.Discouraged
		 */
		@ini_set( 'display_errors', '0' );
		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}
		/*
		 * phpcs:enable WordPress.PHP.NoSilencedErrors.Discouraged
		 * phpcs:enable PluginCheck.CodeAnalysis.PHPErrorReporting.IniDirectiveDisplay_errors
		 * phpcs:enable Squiz.PHP.DiscouragedFunctions.Discouraged
		 */
	}

	/**
	 * Record an unexpected error so administrators can inspect it on the
	 * Diagnostics page instead of the visitor seeing a dead end.
	 *
	 * @param string    $where Handler name (send|verify).
	 * @param \Throwable $error Caught error.
	 * @return void
	 */
	private function crash( $where, $error ) {
		$entry = array(
			'at'      => time(),
			'where'   => (string) $where,
			'message' => $error->getMessage(),
			'file'    => wp_basename( $error->getFile() ),
			'line'    => (int) $error->getLine(),
		);
		update_option( 'barar_atik_last_error', $entry, false );

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Only runs when the site explicitly enables WP_DEBUG_LOG.
			error_log(
				sprintf(
					'Barar Atik SMS OTP [%s] %s in %s:%d',
					$entry['where'],
					$entry['message'],
					$entry['file'],
					$entry['line']
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Is this a registration context?
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	private function is_register_context( $context ) {
		return 'wp_register' === $context || 'wc_register' === $context;
	}

	/**
	 * Is registration currently allowed for this context?
	 *
	 * Mirrors the site/WooCommerce registration switches so the AJAX
	 * endpoints cannot be used when the native forms are disabled.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	private function registration_allowed( $context ) {
		if ( 'wp_register' === $context ) {
			return (bool) get_option( 'users_can_register' );
		}
		if ( 'wc_register' === $context ) {
			return 'yes' === get_option( 'woocommerce_enable_myaccount_registration', 'no' );
		}
		return true;
	}

	/**
	 * Transient key for an OTP record.
	 *
	 * @param string $context Context.
	 * @param string $number  Normalized number.
	 * @return string
	 */
	private function key( $context, $number ) {
		return 'barar_atik_otp_' . substr( hash( 'sha256', $context . '|' . $number ), 0, 32 );
	}

	/**
	 * Client IP for rate limiting.
	 *
	 * @return string
	 */
	private function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? $ip : '0.0.0.0';
	}

	/**
	 * Secure random numeric OTP.
	 *
	 * @param int $length Length.
	 * @return string
	 */
	private function generate( $length ) {
		$length = max( 4, min( 8, (int) $length ) );
		$code   = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$code .= (string) random_int( 0, 9 );
		}
		return $code;
	}

	/**
	 * Hash of a code for storage.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	private function hash_code( $code ) {
		return hash_hmac( 'sha256', (string) $code, (string) wp_salt( 'auth' ) );
	}

	/**
	 * Remaining cooldown seconds for a context/number.
	 *
	 * @param string $context Context.
	 * @param string $number  Normalized number.
	 * @return int
	 */
	private function cooldown_remaining( $context, $number ) {
		$stored = get_transient( 'barar_atik_cd_' . substr( hash( 'sha256', $context . '|' . $number ), 0, 32 ) );
		if ( ! $stored ) {
			return 0;
		}
		return max( 0, (int) ( $stored - time() ) );
	}

	/**
	 * Start the resend cooldown.
	 *
	 * @param string $context Context.
	 * @param string $number  Normalized number.
	 * @return void
	 */
	private function start_cooldown( $context, $number ) {
		$seconds = max( 10, min( 3600, (int) Barar_Atik_Settings::get( 'otp_resend', '60' ) ) );
		set_transient( 'barar_atik_cd_' . substr( hash( 'sha256', $context . '|' . $number ), 0, 32 ), time() + $seconds, $seconds + 30 );
	}

	/**
	 * Simple fixed-window rate limiter.
	 *
	 * @param string $scope Scope key.
	 * @param string $id    Identifier (number or IP).
	 * @param int    $max   Maximum hits per window.
	 * @return bool True when allowed.
	 */
	private function rate_ok( $scope, $id, $max ) {
		$max   = max( 1, (int) $max );
		$tkey  = 'barar_atik_rt_' . substr( hash( 'sha256', $scope . '|' . $id ), 0, 32 );
		$count = (int) get_transient( $tkey );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $tkey, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Redirect target validated server side (never a foreign host).
	 *
	 * Honour the configured redirect mode:
	 *  - default  → use the candidate supplied by the client (validated);
	 *  - previous → send the visitor back to the page they started from;
	 *  - custom   → always use the configured URL.
	 *
	 * @param string $candidate Candidate URL.
	 * @return string
	 */
	private function safe_redirect( $candidate = '' ) {
		$fallback = home_url( '/' );
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$account = wc_get_page_permalink( 'myaccount' );
			if ( $account ) {
				$fallback = $account;
			}
		}

		$mode = (string) Barar_Atik_Settings::get( 'redirect_mode', 'default' );

		if ( 'custom' === $mode ) {
			$url = $this->validate_url( (string) Barar_Atik_Settings::get( 'redirect_url', '' ) );
			return '' !== $url ? $url : $fallback;
		}

		if ( 'previous' === $mode ) {
			$previous = wp_get_referer();
			if ( ! $previous && isset( $_SERVER['HTTP_REFERER'] ) ) {
				$previous = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ), array( 'http', 'https' ) );
			}
			$url = $this->validate_url( $previous );
			return '' !== $url ? $url : $fallback;
		}

		$url = $this->validate_url( $candidate );
		return '' !== $url ? $url : $fallback;
	}

	/**
	 * Validate an absolute http(s) URL that stays on this site.
	 *
	 * @param string $url Candidate URL.
	 * @return string Valid URL or an empty string.
	 */
	private function validate_url( $url ) {
		$url = is_string( $url ) ? trim( $url ) : '';
		if ( '' === $url ) {
			return '';
		}
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		return wp_validate_redirect( $url, '' );
	}

	/**
	 * Sanitized string input.
	 *
	 * @param string $key     Field key.
	 * @param string $default Default.
	 * @return string
	 */
	private function input( $key, $default = '' ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Callers verify the nonce before reading input.
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return $default;
		}
		return sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Raw string input for values that never reach output (passwords).
	 *
	 * @param string $key     Field key.
	 * @param string $default Default.
	 * @return string
	 */
	private function raw_input( $key, $default = '' ) {
		/*
		 * Callers verify the nonce before reading input. Passwords are only
		 * compared against the stored hash and never rendered, so they are
		 * deliberately not run through sanitize_*().
		 *
		 * phpcs:disable WordPress.Security.NonceVerification.Missing
		 * phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		 */
		if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) {
			return $default;
		}
		return (string) wp_unslash( $_POST[ $key ] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/* ---------------------------------------------------------------------
	 * AJAX: send an OTP
	 * ------------------------------------------------------------------ */

	/**
	 * Send an OTP code. Public entry point: keeps the response JSON clean and
	 * converts unexpected errors into a structured error the client can show.
	 *
	 * @return void
	 */
	public function ajax_send() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->prepare_json();

		try {
			$this->handle_send();
		} catch ( \Throwable $error ) {
			$this->crash( 'send', $error );
			$this->fail( 'server', __( 'Something went wrong while sending the code. Please try again in a moment.', 'barar-atik-sms-otp' ) );
		}
	}

	/**
	 * Send pipeline body.
	 *
	 * @return void
	 */
	private function handle_send() {
		$context = sanitize_key( $this->input( 'context' ) );
		if ( ! in_array( $context, self::CONTEXTS, true ) ) {
			$this->fail( 'bad_request', __( 'Invalid request.', 'barar-atik-sms-otp' ) );
		}
		if ( ! Barar_Atik_Settings::context_enabled( $context ) ) {
			$this->fail( 'unavailable', __( 'Phone OTP is not available.', 'barar-atik-sms-otp' ) );
		}
		if ( $this->is_register_context( $context ) && ! $this->registration_allowed( $context ) ) {
			$this->fail( 'unavailable', __( 'Registration is currently disabled.', 'barar-atik-sms-otp' ) );
		}

		$number = Barar_Atik_Phone::normalize( $this->input( 'phone' ) );
		if ( '' === $number ) {
			$this->fail( 'invalid_phone', __( 'Please enter a valid phone number.', 'barar-atik-sms-otp' ) );
		}

		$register = $this->is_register_context( $context );
		$resend   = max( 10, min( 3600, (int) Barar_Atik_Settings::get( 'otp_resend', '60' ) ) );
		$expiry   = max( 1, min( 60, (int) Barar_Atik_Settings::get( 'otp_expiry', '5' ) ) );

		// Cooldown first, so repeated clicks cannot bypass limits.
		$cooldown = $this->cooldown_remaining( $context, $number );
		if ( $cooldown > 0 ) {
			$this->fail(
				'cooldown',
				sprintf(
					/* translators: %d: seconds to wait. */
					__( 'Please wait %d seconds before requesting a new code.', 'barar-atik-sms-otp' ),
					$cooldown
				),
				array( 'retry_after' => $cooldown )
			);
		}

		$allowed = $this->rate_ok( 'phone', $context . '|' . $number, (int) Barar_Atik_Settings::get( 'otp_limit_phone', '5' ) )
			&& $this->rate_ok( 'ip', $context . '|' . $this->ip(), (int) Barar_Atik_Settings::get( 'otp_limit_ip', '20' ) );
		if ( ! $allowed ) {
			$this->fail( 'rate', __( 'Too many requests. Please try again later.', 'barar-atik-sms-otp' ) );
		}

		$user_ids = Barar_Atik_Phone::find_user_ids( $number );

		if ( $register ) {
			if ( ! empty( $user_ids ) ) {
				$this->fail( 'exists', __( 'An account with this phone number already exists. Please sign in instead.', 'barar-atik-sms-otp' ) );
			}
			$error = $this->validate_registration_fields();
			if ( is_wp_error( $error ) ) {
				$this->fail( $error->get_error_code(), $error->get_error_message() );
			}
		} else {
			if ( count( $user_ids ) > 1 ) {
				$this->fail(
					'duplicates',
					__( 'This phone number is linked to multiple accounts. Please contact the site administrator.', 'barar-atik-sms-otp' ),
					array( 'needs_admin' => true )
				);
			}
			if ( empty( $user_ids ) ) {
				// Do not reveal whether an account exists: generic answer, no SMS cost.
				// The response shape matches a real send (same keys) so it cannot
				// be used to probe for accounts.
				$this->start_cooldown( $context, $number );
				$this->ok(
					array(
						'message'      => __( 'If an account matches this phone number, a verification code has been sent.', 'barar-atik-sms-otp' ),
						'resend_after' => $resend,
						'masked_phone' => Barar_Atik_Phone::mask( $number ),
						'expiry'       => $expiry * MINUTE_IN_SECONDS,
					)
				);
			}
		}

		$length = max( 4, min( 8, (int) Barar_Atik_Settings::get( 'otp_length', '6' ) ) );
		$code   = $this->generate( $length );

		$record = array(
			'code'     => $this->hash_code( $code ),
			'exp'      => time() + ( $expiry * MINUTE_IN_SECONDS ),
			'attempts' => 0,
			'phone'    => $number,
			'context'  => $context,
		);
		// Replaces any previous code for this context (no stale codes).
		set_transient( $this->key( $context, $number ), $record, ( $expiry * MINUTE_IN_SECONDS ) + 60 );

		$message = Barar_Atik_Macros::render(
			(string) Barar_Atik_Settings::get( 'otp_template', 'Your {site_name} verification code is {otp}' ),
			array(
				'extra' => array(
					'otp'        => $code,
					'otp_expiry' => $expiry,
				),
			)
		);
		if ( '' === $message ) {
			$message = 'Your ' . get_bloginfo( 'name' ) . ' verification code is ' . $code;
		}

		$result = $this->sms->send(
			array(
				'recipient' => $number,
				'message'   => $message,
				'source'    => 'otp',
				'event'     => $register ? 'otp_register' : 'otp_login',
				'ref_type'  => 'user',
				'ref_id'    => empty( $user_ids ) ? 0 : (int) $user_ids[0],
				'redact'    => $code,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
			delete_transient( $this->key( $context, $number ) );
			Barar_Atik_Stats::inc( 'otp_send_fail' );
			$this->fail( 'sms_failed', __( 'We could not send the verification code right now. Please try again later.', 'barar-atik-sms-otp' ) );
		}

		$this->start_cooldown( $context, $number );
		Barar_Atik_Stats::inc( 'otp_sent' );

		$this->ok(
			array(
				'message'      => $register
					? __( 'A verification code has been sent to your phone.', 'barar-atik-sms-otp' )
					: __( 'If an account matches this phone number, a verification code has been sent.', 'barar-atik-sms-otp' ),
				'resend_after' => $resend,
				'masked_phone' => Barar_Atik_Phone::mask( $number ),
				'expiry'       => $expiry * MINUTE_IN_SECONDS,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * AJAX: verify an OTP (login or registration)
	 * ------------------------------------------------------------------ */

	/**
	 * Verify a code and complete login or registration. Public entry point:
	 * keeps the response JSON clean and converts unexpected errors into a
	 * structured error the client can show.
	 *
	 * @return void
	 */
	public function ajax_verify() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$this->prepare_json();

		try {
			$this->handle_verify();
		} catch ( \Throwable $error ) {
			$this->crash( 'verify', $error );
			$this->fail( 'server', __( 'Something went wrong while signing you in. Please try again, or use the password form.', 'barar-atik-sms-otp' ) );
		}
	}

	/**
	 * Verification pipeline body.
	 *
	 * @return void
	 */
	private function handle_verify() {
		$context = sanitize_key( $this->input( 'context' ) );
		if ( ! in_array( $context, self::CONTEXTS, true ) || ! Barar_Atik_Settings::context_enabled( $context ) ) {
			$this->fail( 'bad_request', __( 'Invalid request.', 'barar-atik-sms-otp' ) );
		}
		if ( $this->is_register_context( $context ) && ! $this->registration_allowed( $context ) ) {
			$this->fail( 'unavailable', __( 'Registration is currently disabled.', 'barar-atik-sms-otp' ) );
		}

		$number = Barar_Atik_Phone::normalize( $this->input( 'phone' ) );
		if ( '' === $number ) {
			$this->fail( 'invalid_phone', __( 'Please enter a valid phone number.', 'barar-atik-sms-otp' ) );
		}

		$code   = preg_replace( '/\D+/', '', $this->input( 'otp' ) );
		$length = max( 4, min( 8, (int) Barar_Atik_Settings::get( 'otp_length', '6' ) ) );
		if ( '' === $code || strlen( $code ) !== $length ) {
			$this->fail( 'invalid', __( 'Please enter the verification code.', 'barar-atik-sms-otp' ) );
		}

		$key    = $this->key( $context, $number );
		$record = get_transient( $key );

		if ( ! is_array( $record ) || empty( $record['code'] ) ) {
			Barar_Atik_Stats::inc( 'otp_verify_fail' );
			$this->fail( 'expired', __( 'The verification code is invalid or has expired. Please request a new one.', 'barar-atik-sms-otp' ) );
		}

		if ( (int) $record['exp'] < time() ) {
			delete_transient( $key );
			Barar_Atik_Stats::inc( 'otp_verify_fail' );
			$this->fail( 'expired', __( 'The verification code has expired. Please request a new one.', 'barar-atik-sms-otp' ) );
		}

		$max_attempts = max( 1, min( 10, (int) Barar_Atik_Settings::get( 'otp_max_attempts', '5' ) ) );
		if ( (int) $record['attempts'] >= $max_attempts ) {
			delete_transient( $key );
			Barar_Atik_Stats::inc( 'otp_verify_fail' );
			$this->fail( 'attempts', __( 'Too many incorrect attempts. Please request a new code.', 'barar-atik-sms-otp' ) );
		}

		if ( ! hash_equals( (string) $record['code'], $this->hash_code( $code ) ) ) {
			$record['attempts'] = (int) $record['attempts'] + 1;
			set_transient( $key, $record, max( 1, (int) $record['exp'] - time() ) );
			Barar_Atik_Stats::inc( 'otp_verify_fail' );
			$this->fail( 'incorrect', __( 'The verification code is incorrect.', 'barar-atik-sms-otp' ) );
		}

		// Registration problems are reported while the code stays usable, so
		// fixing an email/username does not require requesting a new SMS.
		if ( $this->is_register_context( $context ) ) {
			$error = $this->validate_registration_fields();
			if ( is_wp_error( $error ) ) {
				$this->fail( $error->get_error_code(), $error->get_error_message() );
			}
		}

		// Single use: verified codes are destroyed immediately (no replay).
		delete_transient( $key );

		if ( $this->is_register_context( $context ) ) {
			$this->complete_registration( $number );
		} else {
			$this->complete_login( $number );
		}
	}

	/* ---------------------------------------------------------------------
	 * Completion: login / registration
	 * ------------------------------------------------------------------ */

	/**
	 * Sign in the account matching the verified number.
	 *
	 * @param string $number Normalized number.
	 * @return void
	 */
	private function complete_login( $number ) {
		$user_ids = Barar_Atik_Phone::find_user_ids( $number );

		if ( count( $user_ids ) > 1 ) {
			$this->fail(
				'duplicates',
				__( 'This phone number is linked to multiple accounts. Please contact the site administrator.', 'barar-atik-sms-otp' ),
				array( 'needs_admin' => true )
			);
		}
		if ( empty( $user_ids ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress action, fired so other plugins observe the failed login.
			do_action( 'wp_login_failed', $number, new WP_Error( 'phone_not_found', 'Phone number not found.' ) );
			$this->fail( 'no_account', __( 'We could not sign you in with this phone number. Please use the password form.', 'barar-atik-sms-otp' ) );
		}

		$user = get_userdata( (int) $user_ids[0] );
		if ( ! $user ) {
			$this->fail( 'no_account', __( 'We could not sign you in with this phone number. Please use the password form.', 'barar-atik-sms-otp' ) );
		}

		$this->login_user( $user );
		Barar_Atik_Stats::inc( 'otp_login' );

		$this->ok(
			array(
				'message'  => __( 'Signed in successfully.', 'barar-atik-sms-otp' ),
				'redirect' => $this->safe_redirect( $this->input( 'redirect' ) ),
				'user'     => array(
					'name' => $user->display_name,
				),
			)
		);
	}

	/**
	 * Create the account for a verified number.
	 *
	 * @param string $number Normalized number.
	 * @return void
	 */
	private function complete_registration( $number ) {
		$error = $this->validate_registration_fields();
		if ( is_wp_error( $error ) ) {
			$this->fail( $error->get_error_code(), $error->get_error_message() );
		}

		if ( ! empty( Barar_Atik_Phone::find_user_ids( $number ) ) ) {
			$this->fail( 'exists', __( 'An account with this phone number already exists. Please sign in instead.', 'barar-atik-sms-otp' ), array( 'exists' => true ) );
		}

		$email      = sanitize_email( $this->input( 'email' ) );
		$first_name = sanitize_text_field( $this->input( 'first_name' ) );
		$last_name  = sanitize_text_field( $this->input( 'last_name' ) );
		$password   = $this->raw_input( 'password' );
		$context    = sanitize_key( $this->input( 'context' ) );
		$login      = $this->chosen_username( $number, $email );
		$system     = (string) Barar_Atik_Settings::get( 'register_system', 'auto' );

		if ( '' === $password ) {
			// Passwordless: WordPress generates a secret the user never sees.
			$password = wp_generate_password( 24, true, true );
		}

		$auto_login = '1' === Barar_Atik_Settings::get( 'auto_login_register', '1' );

		/*
		 * Which system creates the account:
		 *  - auto       → WooCommerce for the My Account form, WordPress otherwise;
		 *  - woocommerce → always WooCommerce (when active and an email exists);
		 *  - wordpress  → always wp_insert_user.
		 */
		$is_wc = function_exists( 'wc_create_new_customer' )
			&& '' !== $email
			&& ( 'woocommerce' === $system || ( 'auto' === $system && 'wc_register' === $context ) );

		$user_id = 0;

		if ( $is_wc ) {
			$name_args = array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
			);
			/*
			 * WooCommerce 9.4.0 changed the signature from
			 * ( $email, $password, $args ) to ( $email, $username, $password, $args ).
			 * Calling the new version with the old argument order passes an
			 * array as the password and fatals inside wp_hash_password().
			 */
			$legacy_wc = defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '9.4.0', '<' );

			$user_id = $legacy_wc
				? wc_create_new_customer( $email, $password, array_merge( $name_args, array( 'user_login' => $login ) ) )
				: wc_create_new_customer( $email, $login, $password, $name_args );
		} else {
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => $password,
					'user_email'   => $email,
					'first_name'   => $first_name,
					'last_name'    => $last_name,
					'display_name' => trim( $first_name . ' ' . $last_name ) ? trim( $first_name . ' ' . $last_name ) : $login,
					'role'         => Barar_Atik_Settings::safe_role( (string) Barar_Atik_Settings::get( 'register_role', 'subscriber' ) ),
				)
			);
		}

		if ( is_wp_error( $user_id ) ) {
			$error_code = $user_id->get_error_code();
			$message    = __( 'The account could not be created. Please try again.', 'barar-atik-sms-otp' );
			$code       = 'create_failed';

			if ( in_array( $error_code, array( 'existing_user_email', 'existing_email', 'registration-error-email-exists' ), true ) ) {
				$message = __( 'An account with this email address already exists.', 'barar-atik-sms-otp' );
				$code    = 'exists';
			} elseif ( in_array( $error_code, array( 'existing_user_login', 'registration-error-username-exists' ), true ) ) {
				$message = __( 'That username is already taken. Please choose another.', 'barar-atik-sms-otp' );
				$code    = 'exists_login';
			}
			$this->fail( $code, $message );
		}

		$user_id = (int) $user_id;
		Barar_Atik_Phone::store_for_user( $user_id, $number );

		if ( ! $is_wc ) {
			update_user_meta( $user_id, 'first_name', $first_name );
			update_user_meta( $user_id, 'last_name', $last_name );

			if ( '' !== $email && function_exists( 'wp_new_user_notification' ) && apply_filters( 'barar_atik_send_registration_email', true ) ) {
				wp_new_user_notification( $user_id, null, 'user' );
			}
		}

		Barar_Atik_Stats::inc( 'otp_register' );

		$user = get_userdata( $user_id );
		if ( $auto_login && $user ) {
			$this->login_user( $user );
			Barar_Atik_Stats::inc( 'otp_login' );
		}

		$this->ok(
			array(
				'message'   => __( 'Your account has been created.', 'barar-atik-sms-otp' ),
				'redirect'  => $this->safe_redirect( $this->input( 'redirect' ) ),
				'logged_in' => (bool) ( $auto_login && $user ),
			)
		);
	}

	/**
	 * Username for a new account: the phone number when the admin enabled
	 * "use phone number as username", otherwise the WordPress username
	 * (entered or derived from the email).
	 *
	 * @param string $number Normalized phone number.
	 * @param string $email  Email.
	 * @return string
	 */
	private function chosen_username( $number, $email ) {
		if ( '1' === Barar_Atik_Settings::get( 'phone_as_username', '0' ) ) {
			$base = preg_replace( '/\D+/', '', ltrim( (string) $number, '+' ) );
			if ( '' === $base ) {
				return $this->desired_username( $email );
			}
			$base      = substr( $base, 0, 50 );
			$candidate = $base;
			$suffix    = 1;
			while ( username_exists( $candidate ) ) {
				$suffix++;
				$candidate = $base . $suffix;
				if ( $suffix > 50 ) {
					$candidate = $base . wp_rand( 10000, 99999 );
					break;
				}
			}
			if ( ! validate_username( $candidate ) ) {
				$candidate = 'user' . wp_rand( 10000, 99999 );
			}
			return $candidate;
		}

		return $this->desired_username( $email );
	}

	/**
	 * Username for WordPress context registration.
	 *
	 * @param string $email Email.
	 * @return string
	 */
	private function desired_username( $email ) {
		$username = sanitize_user( $this->input( 'username' ), true );
		if ( '' !== $username && ! username_exists( $username ) ) {
			return $username;
		}

		$parts     = explode( '@', (string) $email );
		$base      = sanitize_user( strtolower( (string) current( $parts ) ), true );
		if ( '' === $base ) {
			$base = 'customer';
		}

		$candidate = $base;
		$suffix    = 1;
		while ( username_exists( $candidate ) ) {
			$suffix++;
			$candidate = $base . $suffix;
			if ( $suffix > 50 ) {
				$candidate = $base . wp_rand( 10000, 99999 );
				break;
			}
		}
		return $candidate;
	}

	/**
	 * Validate registration fields (checked at send time and again at verify time).
	 * Visibility and required-ness follow the admin field configuration.
	 *
	 * @return true|WP_Error
	 */
	private function validate_registration_fields() {
		$email_state = Barar_Atik_Settings::field_mode( 'reg_email', '2' );
		$email       = sanitize_email( $this->input( 'email' ) );

		if ( '' !== $email && ! is_email( $email ) ) {
			return new WP_Error( 'bad_email', __( 'Please enter a valid email address.', 'barar-atik-sms-otp' ) );
		}
		if ( '2' === $email_state && '' === $email ) {
			return new WP_Error( 'bad_email', __( 'Please enter a valid email address.', 'barar-atik-sms-otp' ) );
		}
		if ( '' !== $email && email_exists( $email ) ) {
			return new WP_Error( 'exists', __( 'An account with this email address already exists.', 'barar-atik-sms-otp' ) );
		}

		$phone_as_username = '1' === Barar_Atik_Settings::get( 'phone_as_username', '0' );
		if ( ! $phone_as_username ) {
			$username = sanitize_user( $this->input( 'username' ), true );
			if ( '' !== $username && username_exists( $username ) ) {
				return new WP_Error( 'exists_login', __( 'That username is already taken. Please choose another.', 'barar-atik-sms-otp' ) );
			}
		}

		$name_fields = array(
			'first_name' => array( 'reg_first_name', __( 'Please enter your first name.', 'barar-atik-sms-otp' ) ),
			'last_name'  => array( 'reg_last_name', __( 'Please enter your last name.', 'barar-atik-sms-otp' ) ),
		);
		foreach ( $name_fields as $field => $rule ) {
			$state = Barar_Atik_Settings::field_mode( $rule[0], '1' );
			if ( '2' === $state && '' === trim( $this->input( $field ) ) ) {
				return new WP_Error( 'bad_name', $rule[1] );
			}
		}

		$password      = $this->raw_input( 'password' );
		$passwordless  = '1' === Barar_Atik_Settings::get( 'passwordless_register', '0' );
		if ( $passwordless ) {
			if ( '' !== $password && strlen( $password ) < 6 ) {
				return new WP_Error( 'bad_password', __( 'The password must be at least 6 characters long.', 'barar-atik-sms-otp' ) );
			}
		} elseif ( strlen( $password ) < 6 ) {
			return new WP_Error( 'bad_password', __( 'Please choose a password of at least 6 characters.', 'barar-atik-sms-otp' ) );
		}

		return true;
	}

	/**
	 * Log a user in with WordPress' supported session functions.
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	private function login_user( $user ) {
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, true, is_ssl() );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress action, fired so other plugins observe the login.
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'barar_atik_user_logged_in', $user->ID, 'otp' );
	}
}
