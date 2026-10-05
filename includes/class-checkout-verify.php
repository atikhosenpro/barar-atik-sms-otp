<?php
/**
 * Checkout verification: the phone number and email address of a guest order
 * have to be proven with a code before Place Order is allowed to work.
 *
 * Why both: the phone number is the contact channel (order SMS go there) and
 * the email address receives the WooCommerce receipt. Proving only one of
 * them leaves the other open to typos and to somebody else's number, so the
 * default asks for both and lets the administrator switch either off.
 *
 * The gate has two halves and both are needed:
 *
 *  - the browser hides the Place Order button until the code is accepted, so
 *    the shopper is guided through it;
 *  - the server refuses to create the order (woocommerce_checkout_process),
 *    so the gate cannot be skipped.
 *
 * The server half is the real one. It compares a short-lived HMAC of the
 * submitted phone number and email address against what was actually proven,
 * so a refresh, a direct POST, a replayed checkout nonce or a hand-crafted
 * request all fail the same way: the proof does not match the details being
 * ordered. Editing the number after verifying also invalidates the proof,
 * which is the point - otherwise verification would prove nothing.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Checkout_Verify {

	/**
	 * WooCommerce session key holding the granted proof.
	 */
	const SESSION_KEY = 'barar_atik_checkout_verified';

	/**
	 * Transients: the pending code record and the resend cooldown.
	 */
	const CODE_PREFIX = 'barar_atik_vcode_';
	const COOL_PREFIX = 'barar_atik_vcool_';

	/**
	 * How long a granted proof stays usable, in seconds.
	 *
	 * Long enough to fill a long checkout form, short enough that a proof
	 * cannot be parked in a browser for later use.
	 */
	const PROOF_TTL = 1800;

	/**
	 * OTP service (code generation, hashing, message template, JSON hygiene).
	 *
	 * @var Barar_Atik_OTP
	 */
	private $otp;

	/**
	 * Send pipeline.
	 *
	 * @var Barar_Atik_SMS
	 */
	private $sms;

	/**
	 * How many times this component has been asked to render, so the widget
	 * (and its element ids) stays unique when the page is rendered twice.
	 *
	 * @var int
	 */
	private static $seq = 0;

	/**
	 * Whether the dialog has already been printed this request, so the
	 * primary hook and the wp_footer safety net cannot both fire.
	 *
	 * @var bool
	 */
	private static $popup_printed = false;

	/**
	 * Wire dependencies.
	 *
	 * @param Barar_Atik_OTP $otp OTP service.
	 * @param Barar_Atik_SMS $sms Send pipeline.
	 */
	public function __construct( $otp, $sms ) {
		$this->otp = $otp;
		$this->sms = $sms;

		add_action( 'wp_ajax_barar_atik_verify_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_nopriv_barar_atik_verify_send', array( $this, 'ajax_send' ) );
		add_action( 'wp_ajax_barar_atik_verify_check', array( $this, 'ajax_check' ) );
		add_action( 'wp_ajax_nopriv_barar_atik_verify_check', array( $this, 'ajax_check' ) );

		/*
		 * Server-side gate, priority 2: WooCommerce fires this before it reads
		 * the posted data or touches the cart, so a rejection here leaves the
		 * basket alone. A notice added here makes process_checkout() skip
		 * both process_customer() and create_order().
		 *
		 * It has to run before Barar_Atik_Checkout_Customer (priority 3),
		 * which signs a proven number in: which audience the gate decides for
		 * must be the one the shopper actually began the checkout in, not one
		 * created by that sign-in a moment earlier.
		 */
		add_action( 'woocommerce_checkout_process', array( $this, 'guard_checkout' ), 2 );

		/*
		 * The dialog is printed after the whole checkout form, outside the
		 * order review block WooCommerce re-renders on every totals refresh,
		 * so an open dialog survives updated_checkout. wp_footer is only a
		 * safety net for themes that replace form-checkout.php and drop that
		 * hook: render_popup() prints at most once per request.
		 */
		add_action( 'woocommerce_after_checkout_form', array( $this, 'render_popup' ), 10 );
		add_action( 'wp_footer', array( $this, 'render_popup' ), 5 );
	}

	/* ---------------------------------------------------------------------
	 * State
	 * ------------------------------------------------------------------ */

	/**
	 * Reasons why the verification step would not show up or not be enforced
	 * on the checkout, for the admin settings screen.
	 *
	 * @return string[] Human readable problems (empty when all is well).
	 */
	public function diagnose() {
		$problems = array();

		if ( '1' !== Barar_Atik_Settings::get( 'checkout_verify', '0' ) ) {
			$problems[] = __( 'The master switch "Require verification before Place Order" is off, so orders are placed without a code.', 'barar-atik-sms-otp' );
		}
		if ( ! $this->guests_must_verify() ) {
			$problems[] = __( '"Ask signed-out shoppers" is off, so a visitor who is not signed in is never asked for a code.', 'barar-atik-sms-otp' );
		}
		if ( ! $this->phone_required() && ! $this->email_required() ) {
			$problems[] = __( 'Both "Verify the phone number" and "Verify the email address" are off, so there is nothing to verify.', 'barar-atik-sms-otp' );
		}
		if ( '' === trim( (string) Barar_Atik_Settings::get( 'api_key', '' ) ) || '' === trim( (string) Barar_Atik_Settings::get( 'device_id', '' ) ) ) {
			$problems[] = __( 'The TextBee API key or device ID is empty, so no SMS can be sent.', 'barar-atik-sms-otp' );
		}

		// Labels are never filled in for you: an empty button stays blank.
		if ( $this->applies_to_guests_setting() && ( '' === Barar_Atik_Settings::label( 'btn_send' ) || '' === Barar_Atik_Settings::label( 'btn_verify' ) ) ) {
			$problems[] = __( 'The buttons "Send code" and "Verify the code (checkout)" have no text yet. There is no default wording, so the buttons in the verification popup are blank until you type their labels (Texts tab).', 'barar-atik-sms-otp' );
		}

		// The code dialog and the server check hook into the classic checkout
		// (shortcode). WooCommerce's Checkout block uses different hooks.
		if ( function_exists( 'wc_get_page_id' ) ) {
			$page_id = (int) wc_get_page_id( 'checkout' );
			if ( $page_id > 0 && has_block( 'woocommerce/checkout', $page_id ) ) {
				$problems[] = __( 'Your Checkout page uses the WooCommerce Checkout BLOCK. The code popup and the order check only work on the classic checkout: edit the Checkout page, remove the block and add a Shortcode block with [woocommerce_checkout].', 'barar-atik-sms-otp' );
			}
		}

		return $problems;
	}

	/**
	 * Is the checkout verification switched on at all?
	 *
	 * @return bool
	 */
	private function applies_to_guests_setting() {
		return $this->enabled() && $this->guests_must_verify();
	}

	/**
	 * Is guest verification switched on?
	 *
	 * @return bool
	 */
	private function enabled() {
		if ( '1' !== Barar_Atik_Settings::get( 'checkout_verify', '0' ) ) {
			return false;
		}
		// Both channels off means there is nothing to prove.
		return $this->phone_required() || $this->email_required();
	}

	/**
	 * Must the phone number be proven?
	 *
	 * @return bool
	 */
	private function phone_required() {
		return '1' === Barar_Atik_Settings::get( 'checkout_verify_phone', '1' );
	}

	/**
	 * Must the email address be proven?
	 *
	 * @return bool
	 */
	private function email_required() {
		return '1' === Barar_Atik_Settings::get( 'checkout_verify_email', '1' );
	}

	/**
	 * Must a signed-out shopper prove the contact details?
	 *
	 * @return bool
	 */
	private function guests_must_verify() {
		return '1' === Barar_Atik_Settings::get( 'checkout_verify_guests', '1' );
	}

	/**
	 * Must a signed-in customer prove the contact details?
	 *
	 * Off by default: an account has already been reached through a code (or
	 * a password), so re-asking on every order only adds friction. Turned on,
	 * it proves the number on the order itself, which is what a
	 * cash-on-delivery shop is really checking.
	 *
	 * @return bool
	 */
	private function users_must_verify() {
		return '1' === Barar_Atik_Settings::get( 'checkout_verify_users', '0' );
	}

	/**
	 * Does verification apply to the shopper making this request?
	 *
	 * One answer for the whole component - the gate, the dialog and the two
	 * AJAX endpoints all ask the same question, so the shopper can never be
	 * shown a step the server would not have enforced, or be blocked by one
	 * it does not want.
	 *
	 * @return bool
	 */
	private function applies() {
		if ( ! $this->enabled() ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			return $this->users_must_verify();
		}

		/*
		 * Signed-out shoppers are always asked, even when WooCommerce is set
		 * to require an account. The account is then created from the proven
		 * phone number by Barar_Atik_Checkout_Customer; skipping the code here
		 * would let an unverified number open an account and place the order.
		 */
		return $this->guests_must_verify();
	}

	/* ---------------------------------------------------------------------
	 * Identifiers
	 * ------------------------------------------------------------------ */

	/**
	 * Opaque stamp of a pair of contact details.
	 *
	 * The raw phone number and email address are never written to the
	 * session or to a transient key, so neither a session table dump nor a
	 * transient listing reveals them.
	 *
	 * @param string $phone Normalized phone number.
	 * @param string $email Email address.
	 * @return string
	 */
	private function pair_stamp( $phone, $email ) {
		return substr( hash_hmac( 'sha256', $phone . '|' . $email, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * Normalize a raw phone number the same way on both sides of the check.
	 *
	 * A number that cannot be normalized is kept as typed, so an unusable
	 * value can never be swapped for a good one between the two calls.
	 *
	 * @param string $raw Raw value.
	 * @return string
	 */
	private function normalize_phone( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$normalized = Barar_Atik_Phone::normalize( $raw );
		return '' !== $normalized ? $normalized : $raw;
	}

	/* ---------------------------------------------------------------------
	 * AJAX: send the code
	 * ------------------------------------------------------------------ */

	/**
	 * Send the verification code to every required channel.
	 *
	 * @return void
	 */
	public function ajax_send() {
		check_ajax_referer( Barar_Atik_OTP::NONCE, 'nonce' );
		$this->otp->prepare_json();

		if ( ! $this->applies() ) {
			wp_send_json_error( array( 'message' => __( 'Verification is not available.', 'barar-atik-sms-otp' ) ), 400 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$phone = $this->normalize_phone( $phone );

		if ( $this->phone_required() && '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the phone number you want to verify.', 'barar-atik-sms-otp' ) ), 400 );
		}
		if ( $this->phone_required() && ! Barar_Atik_Phone::is_valid( $phone ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid phone number.', 'barar-atik-sms-otp' ) ), 400 );
		}
		/*
		 * The phone number carries the proof on its own; an email address is
		 * verified when one is given. It only has to be present when there is
		 * no phone channel to fall back on.
		 */
		if ( $this->email_required() && '' === $email && ! $this->phone_required() ) {
			wp_send_json_error( array( 'message' => __( 'Please enter the email address you want to verify.', 'barar-atik-sms-otp' ) ), 400 );
		}
		if ( '' !== $email && ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'barar-atik-sms-otp' ) ), 400 );
		}

		$stamp = $this->pair_stamp( $phone, $email );
		$left  = $this->cooldown_left( $stamp );
		if ( $left > 0 ) {
			/*
			 * A code for these exact details is still on its way (the popup
			 * asks for one every time Place Order is clicked). Answer the way
			 * a fresh send would, so the shopper lands on the code step with
			 * a running countdown instead of an error - and without paying
			 * for a second message.
			 */
			$pending = get_transient( self::CODE_PREFIX . $stamp );
			if ( is_array( $pending ) && ! empty( $pending['code'] ) && (int) $pending['exp'] > time() ) {
				wp_send_json_success(
					array(
						'message'      => __( 'A verification code is on its way. Enter it below, or wait to request a new one.', 'barar-atik-sms-otp' ),
						'sent_to'      => implode( ' · ', $this->pending_labels( $phone, $email ) ),
						'resend_after' => $left,
						'expires_in'   => (int) $pending['exp'] - time(),
						'partial'      => false,
					)
				);
			}

			wp_send_json_error(
				array(
					'message'     => sprintf(
						/* translators: %d: seconds to wait. */
						__( 'Please wait %d seconds before requesting a new code.', 'barar-atik-sms-otp' ),
						$left
					),
					'retry_after' => $left,
				),
				429
			);
		}

		/*
		 * The cooldown above is keyed on the phone + email pair, so changing
		 * the email would reset it. These limits are keyed on the phone number
		 * (or the email when there is no phone) and on the visitor's IP, which
		 * is what stops someone texting a stranger's number over and over.
		 */
		$who     = '' !== $phone ? $phone : $email;
		$allowed = $this->otp->rate_ok( 'vwho', $who, (int) Barar_Atik_Settings::get( 'otp_limit_phone', '5' ) )
			&& $this->otp->rate_ok( 'vip', $this->otp->ip(), (int) Barar_Atik_Settings::get( 'otp_limit_ip', '20' ) );
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'barar-atik-sms-otp' ) ), 429 );
		}

		$expiry = $this->expiry_minutes();
		$code   = $this->otp->generate( (int) Barar_Atik_Settings::get( 'otp_length', '6' ) );

		/*
		 * The record carries the stamps it was issued for, so a code can never
		 * be redeemed against different contact details than the ones it was
		 * sent to. Only an HMAC of the code is stored.
		 */
		set_transient(
			self::CODE_PREFIX . $stamp,
			array(
				'code'     => $this->otp->hash_code( $code ),
				'phone'    => $this->pair_stamp( $phone, '' ),
				'email'    => $this->pair_stamp( '', $email ),
				'exp'      => time() + ( $expiry * MINUTE_IN_SECONDS ),
				'attempts' => 0,
			),
			( $expiry * MINUTE_IN_SECONDS ) + 60
		);

		$message = $this->otp->code_message( $code, $expiry );
		$sent    = array();

		if ( '' !== $phone && $this->phone_required() ) {
			$sent[] = $this->send_sms( $phone, $message, $code );
		}
		if ( '' !== $email && $this->email_required() ) {
			$sent[] = $this->send_email( $email, $message );
		}

		/*
		 * No channel accepted the code: throw the record away so the shopper
		 * is not left with a code that can never arrive.
		 */
		$delivered = array_filter(
			$sent,
			static function ( $channel ) {
				return ! empty( $channel['ok'] );
			}
		);

		/*
		 * When the phone number is one of the things being proven, the text
		 * message is the proof. A code that only reached the email address
		 * says nothing about who holds the phone, so it never counts.
		 */
		$phone_ok = true;
		if ( '' !== $phone && $this->phone_required() ) {
			$phone_ok = false;
			foreach ( $delivered as $channel ) {
				if ( 'phone' === $channel['channel'] ) {
					$phone_ok = true;
				}
			}
		}

		if ( ! $delivered || ! $phone_ok ) {
			delete_transient( self::CODE_PREFIX . $stamp );
			Barar_Atik_Stats::inc( 'otp_send_fail' );
			wp_send_json_error(
				array(
					'message' => __( 'The verification code could not be sent. Please try again in a moment.', 'barar-atik-sms-otp' ),
					'code'    => 'send_failed',
				),
				502
			);
		}

		$resend = $this->resend_seconds();
		$this->start_cooldown( $stamp, $resend );
		Barar_Atik_Stats::inc( 'otp_sent' );

		$labels = array();
		foreach ( $delivered as $channel ) {
			$labels[] = $channel['label'];
		}

		wp_send_json_success(
			array(
				'message'      => __( 'A verification code has been sent. Please enter it below.', 'barar-atik-sms-otp' ),
				'sent_to'      => implode( ' · ', $labels ),
				'resend_after' => $resend,
				'expires_in'   => $expiry * MINUTE_IN_SECONDS,
				'partial'      => ( count( $delivered ) < count( $sent ) ),
			)
		);
	}

	/**
	 * Send the code by SMS through the existing TextBee pipeline.
	 *
	 * @param string $phone   Normalized phone number.
	 * @param string $message Rendered message.
	 * @param string $code    Plain code, redacted from the local log.
	 * @return array
	 */
	private function send_sms( $phone, $message, $code ) {
		$result = $this->sms->send(
			array(
				'recipient' => $phone,
				'message'   => $message,
				'source'    => 'otp',
				'event'     => 'checkout_verify',
				'ref_type'  => 'none',
				'ref_id'    => 0,
				'redact'    => $code,
			)
		);

		if ( is_wp_error( $result ) || empty( $result['ok'] ) ) {
			return array(
				'channel' => 'phone',
				'ok'      => false,
			);
		}

		return array(
			'channel' => 'phone',
			'ok'      => true,
			'label'   => Barar_Atik_Phone::mask( $phone ),
		);
	}

	/**
	 * Send the code by email.
	 *
	 * @param string $email   Email address.
	 * @param string $message Rendered message.
	 * @return array
	 */
	private function send_email( $email, $message ) {
		$subject = Barar_Atik_Settings::text(
			'verify_email_subject',
			__( 'Your store verification code', 'barar-atik-sms-otp' )
		);

		$sent = wp_mail(
			$email,
			$subject,
			$message,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		if ( ! $sent ) {
			return array(
				'channel' => 'email',
				'ok'      => false,
			);
		}

		return array(
			'channel' => 'email',
			'ok'      => true,
			'label'   => $this->mask_email( $email ),
		);
	}

	/**
	 * Mask an email address for display.
	 *
	 * @param string $email Email address.
	 * @return string
	 */
	private function mask_email( $email ) {
		$parts = explode( '@', $email, 2 );
		$local = $parts[0];
		$host  = isset( $parts[1] ) ? $parts[1] : '';

		if ( strlen( $local ) > 2 ) {
			$local = substr( $local, 0, 1 ) . str_repeat( '*', strlen( $local ) - 2 ) . substr( $local, -1 );
		} elseif ( '' !== $local ) {
			$local = $local[0] . '*';
		}

		return $local . ( '' !== $host ? '@' . $host : '' );
	}

	/**
	 * Masked labels of the channels a code was (or is being) sent to.
	 *
	 * @param string $phone Normalized phone number.
	 * @param string $email Email address.
	 * @return string[]
	 */
	private function pending_labels( $phone, $email ) {
		$labels = array();

		if ( '' !== $phone && $this->phone_required() ) {
			$labels[] = Barar_Atik_Phone::mask( $phone );
		}
		if ( '' !== $email && $this->email_required() ) {
			$labels[] = $this->mask_email( $email );
		}

		return $labels;
	}

	/* ---------------------------------------------------------------------
	 * AJAX: check the code
	 * ------------------------------------------------------------------ */

	/**
	 * Check a submitted code and grant the proof when it matches.
	 *
	 * @return void
	 */
	public function ajax_check() {
		check_ajax_referer( Barar_Atik_OTP::NONCE, 'nonce' );
		$this->otp->prepare_json();

		if ( ! $this->applies() ) {
			wp_send_json_error( array( 'message' => __( 'Verification is not available.', 'barar-atik-sms-otp' ) ), 400 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified above.
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$code  = isset( $_POST['code'] ) ? preg_replace( '/\D+/', '', wp_unslash( $_POST['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$phone = $this->normalize_phone( $phone );
		$key   = self::CODE_PREFIX . $this->pair_stamp( $phone, $email );
		$rec   = get_transient( $key );

		if ( ! is_array( $rec ) || empty( $rec['code'] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'There is no code waiting for these details. Please request a new one.', 'barar-atik-sms-otp' ),
					'code'    => 'no_code',
				),
				400
			);
		}

		if ( (int) $rec['exp'] < time() ) {
			delete_transient( $key );
			wp_send_json_error(
				array(
					'message' => __( 'That code has expired. Please request a new one.', 'barar-atik-sms-otp' ),
					'code'    => 'expired',
				),
				400
			);
		}

		$max = max( 1, min( 10, (int) Barar_Atik_Settings::get( 'otp_max_attempts', '5' ) ) );
		if ( (int) $rec['attempts'] >= $max ) {
			delete_transient( $key );
			wp_send_json_error(
				array(
					'message' => __( 'Too many incorrect codes. Please request a new one.', 'barar-atik-sms-otp' ),
					'code'    => 'too_many',
				),
				429
			);
		}

		// The code must belong to exactly these contact details.
		if ( ! hash_equals( (string) $rec['phone'], $this->pair_stamp( $phone, '' ) )
			|| ! hash_equals( (string) $rec['email'], $this->pair_stamp( '', $email ) ) ) {
			$this->bump_attempts( $key, $rec );
			wp_send_json_error(
				array( 'message' => __( 'That verification code is not correct.', 'barar-atik-sms-otp' ) ),
				400
			);
		}

		if ( '' === $code || ! hash_equals( (string) $rec['code'], $this->otp->hash_code( $code ) ) ) {
			$left = $this->bump_attempts( $key, $rec );
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %d: attempts left. */
						_n(
							'That verification code is not correct. %d attempt left.',
							'That verification code is not correct. %d attempts left.',
							$left,
							'barar-atik-sms-otp'
						),
						$left
					),
				),
				400
			);
		}

		// Single use: a proven code is spent immediately.
		delete_transient( $key );
		delete_transient( self::COOL_PREFIX . $this->pair_stamp( $phone, $email ) );

		Barar_Atik_Stats::inc( 'otp_verify_ok' );

		if ( '' !== $phone && $this->phone_required() ) {
			// Reuse the shared proof marker so the phone-as-account feature
			// and the guest gate agree on what has been proven. Only when the
			// code really went to that phone number (see ajax_send()).
			Barar_Atik_Verified::mark( $phone );
		}
		$this->grant( $phone, $email );

		wp_send_json_success(
			array( 'message' => __( 'Verified. You can place the order now.', 'barar-atik-sms-otp' ) )
		);
	}

	/**
	 * Record a failed attempt and report how many are left.
	 *
	 * @param string $key Transient key.
	 * @param array  $rec Current record.
	 * @return int
	 */
	private function bump_attempts( $key, $rec ) {
		$max          = max( 1, min( 10, (int) Barar_Atik_Settings::get( 'otp_max_attempts', '5' ) ) );
		$rec['attempts'] = (int) $rec['attempts'] + 1;
		$left          = max( 0, $max - (int) $rec['attempts'] );

		if ( $left <= 0 ) {
			delete_transient( $key );
			return 0;
		}

		set_transient( $key, $rec, max( 60, (int) $rec['exp'] - time() ) );

		return $left;
	}

	/* ---------------------------------------------------------------------
	 * Limits
	 * ------------------------------------------------------------------ */

	/**
	 * Configured code validity, in minutes.
	 *
	 * @return int
	 */
	private function expiry_minutes() {
		return max( 1, min( 60, (int) Barar_Atik_Settings::get( 'checkout_verify_expiry', '1' ) ) );
	}

	/**
	 * Configured resend delay, in seconds.
	 *
	 * @return int
	 */
	private function resend_seconds() {
		return max( 10, min( 3600, (int) Barar_Atik_Settings::get( 'checkout_verify_resend', '60' ) ) );
	}

	/**
	 * Seconds left on the resend cooldown.
	 *
	 * @param string $stamp Pair stamp.
	 * @return int
	 */
	private function cooldown_left( $stamp ) {
		$stored = get_transient( self::COOL_PREFIX . $stamp );
		return $stored ? max( 0, (int) ( $stored - time() ) ) : 0;
	}

	/**
	 * Start the resend cooldown.
	 *
	 * @param string $stamp   Pair stamp.
	 * @param int    $seconds Delay.
	 * @return void
	 */
	private function start_cooldown( $stamp, $seconds ) {
		set_transient( self::COOL_PREFIX . $stamp, time() + $seconds, $seconds + 30 );
	}

	/* ---------------------------------------------------------------------
	 * Server-side gate
	 * ------------------------------------------------------------------ */

	/**
	 * Refuse to create the order while the contact details are unproven.
	 *
	 * @return void
	 */
	public function guard_checkout() {
		/*
		 * One question for the gate, the dialog and both AJAX endpoints: does
		 * this shopper have to prove anything? It reads who they are BEFORE
		 * Barar_Atik_Checkout_Customer runs (priority 3), so a sign-in this
		 * same request performs cannot quietly move them into the audience
		 * that is not checked.
		 */
		if ( ! $this->applies() ) {
			return;
		}
		if ( ! function_exists( 'wc_add_notice' ) ) {
			return;
		}
		if ( $this->proof_covers_posted() ) {
			return;
		}

		Barar_Atik_Stats::inc( 'checkout_verify_block' );
		wc_add_notice( $this->failure_message(), 'error' );
	}

	/**
	 * Does the granted proof match the contact details being submitted?
	 *
	 * @return bool
	 */
	private function proof_covers_posted() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the checkout nonce before firing this hook.
		$phone = isset( $_POST['billing_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) ) : '';
		$email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return $this->stamp_is_granted( $this->pair_stamp( $this->normalize_phone( $phone ), $email ) );
	}

	/**
	 * Does the stored proof already cover the details sitting in the form?
	 *
	 * The gate asks the same question of the posted data while checking the
	 * order; here it is asked of the WooCommerce customer instead, because
	 * the dialog opens before anything has been submitted. The answer is
	 * only a hint for the browser - the code below never trusts it, it
	 * re-asks the server on every submit.
	 *
	 * The stamp itself is deliberately not printed: it is an HMAC of the
	 * phone number, and putting it in the page would hand anyone who can
	 * view source a yes/no oracle for guessing numbers.
	 *
	 * @return bool
	 */
	private function proven_pair() {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || empty( WC()->customer ) ) {
			return false;
		}

		$customer = WC()->customer;
		$phone    = method_exists( $customer, 'get_billing_phone' ) ? (string) $customer->get_billing_phone() : '';
		$email    = method_exists( $customer, 'get_billing_email' ) ? (string) $customer->get_billing_email() : '';

		return $this->stamp_is_granted( $this->pair_stamp( $this->normalize_phone( $phone ), $email ) );
	}

	/**
	 * Is this exact pair stamp still held by the session?
	 *
	 * @param string $expected Pair stamp being asked about.
	 * @return bool
	 */
	private function stamp_is_granted( $expected ) {
		$session = $this->session();
		if ( ! $session ) {
			return false;
		}

		$proof = $session->get( self::SESSION_KEY );
		if ( ! is_array( $proof ) || empty( $proof['stamp'] ) ) {
			return false;
		}

		if ( (int) $proof['exp'] < time() ) {
			$session->set( self::SESSION_KEY, null );
			return false;
		}

		return hash_equals( (string) $proof['stamp'], $expected );
	}

	/**
	 * Message shown when the gate refuses the order.
	 *
	 * @return string
	 */
	private function failure_message() {
		return Barar_Atik_Settings::text(
			'verify_error',
			__( 'Please verify your phone number and email address before placing the order.', 'barar-atik-sms-otp' )
		);
	}

	/* ---------------------------------------------------------------------
	 * Session
	 * ------------------------------------------------------------------ */

	/**
	 * Store a granted proof in the WooCommerce session.
	 *
	 * @param string $phone Normalized phone number.
	 * @param string $email Email address.
	 * @return void
	 */
	private function grant( $phone, $email ) {
		$session = $this->session();
		if ( ! $session ) {
			return;
		}

		$session->set(
			self::SESSION_KEY,
			array(
				'stamp' => $this->pair_stamp( $phone, $email ),
				'exp'   => time() + self::PROOF_TTL,
			)
		);
	}

	/**
	 * The WooCommerce session, when it is usable.
	 *
	 * @return object|null
	 */
	private function session() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}

		$wc = WC();
		if ( ! is_object( $wc ) || empty( $wc->session ) || ! is_object( $wc->session ) ) {
			return null;
		}
		if ( ! method_exists( $wc->session, 'get' ) || ! method_exists( $wc->session, 'set' ) ) {
			return null;
		}

		return $wc->session;
	}

	/* ---------------------------------------------------------------------
	 * Markup
	 * ------------------------------------------------------------------ */

	/**
	 * Print the verification dialog opened by the Place Order button.
	 *
	 * It lives after the checkout form (outside the order review block), so
	 * WooCommerce's totals refreshes never replace it while it is open - a
	 * dialog torn out from under an open code step would strand the shopper
	 * with a code and nowhere to type it.
	 *
	 * @return void
	 */
	public function render_popup() {
		if ( ! $this->applies() ) {
			return;
		}
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		// Printed at most once: wp_footer below is only a safety net for
		// themes that replace form-checkout.php and drop the main hook.
		if ( self::$popup_printed ) {
			return;
		}
		self::$popup_printed = true;

		Barar_Atik_Plugin::instance()->frontend->enqueue_assets();

		self::$seq++;
		$id    = 'barar-verify' . ( self::$seq > 1 ? '-' . self::$seq : '' );
		$title = Barar_Atik_Settings::label( 'verify_popup_title' );
		?>
		<div class="barar-verify-popup" data-barar-verify-popup>
			<style><?php echo $this->popup_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static CSS defined above. ?></style>
			<noscript>
				<style>
					.barar-verify-popup__dialog[hidden] { display: block !important; position: static; overflow: visible; padding: 0; }
					.barar-verify-popup__backdrop, .barar-verify-popup__close { display: none !important; }
					.barar-verify-popup__panel { max-width: none; border: 0; box-shadow: none; padding: 0; animation: none; }
					#place_order { opacity: .5; pointer-events: none; }
				</style>
			</noscript>

			<div class="barar-verify-popup__dialog"
				id="<?php echo esc_attr( $id . '-dialog' ); ?>"
				role="dialog"
				aria-modal="true"
				<?php echo '' !== $title ? 'aria-label="' . esc_attr( $title ) . '"' : ''; // phpcs:ignore ?>
				hidden>
				<div class="barar-verify-popup__backdrop" data-barar-verify-close></div>
				<div class="barar-verify-popup__panel" role="document">
					<button type="button" class="barar-verify-popup__close" data-barar-verify-close aria-label="<?php esc_attr_e( 'Close', 'barar-atik-sms-otp' ); ?>">
						<span aria-hidden="true">&times;</span>
					</button>
					<?php if ( '' !== $title ) : ?>
					<h2 class="barar-verify-popup__title"><?php echo esc_html( $title ); ?></h2>
					<?php endif; ?>

					<div class="barar-verify"
						id="<?php echo esc_attr( $id ); ?>"
						data-barar-verify
						data-popup="1"
						data-proven="<?php echo esc_attr( $this->proven_pair() ? '1' : '0' ); ?>"
						data-phone="<?php echo esc_attr( $this->phone_required() ? '1' : '0' ); ?>"
						data-email="<?php echo esc_attr( $this->email_required() ? '1' : '0' ); ?>"
						data-resend="<?php echo esc_attr( $this->resend_seconds() ); ?>"
						data-nonce="<?php echo esc_attr( wp_create_nonce( Barar_Atik_OTP::NONCE ) ); ?>"
						data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
						<?php $this->widget_body( $id ); ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Layout the popup needs to work, printed with the popup itself.
	 *
	 * The dialog must be a centred, blurred overlay even when the plugin's
	 * stylesheet never reached the page (a cache or optimisation plugin that
	 * drops late-printed styles, "disable plugin styles", a theme that
	 * resets everything). Without these rules the hidden attribute is the
	 * only thing keeping it out of the page, and once opened it is drawn as
	 * a plain block under the checkout form.
	 *
	 * @return string
	 */
	private function popup_css() {
		return '.barar-verify-popup [hidden]{display:none!important}'
			. '.barar-verify-popup__dialog[hidden]{display:none!important}'
			. '.barar-verify-popup__dialog{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;overflow-y:auto;box-sizing:border-box}'
			. '.barar-verify-popup__dialog *{box-sizing:border-box}'
			. '.barar-verify-popup__backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);-webkit-backdrop-filter:blur(6px);backdrop-filter:blur(6px)}'
			. '.barar-verify-popup__panel{position:relative;z-index:1;width:100%;max-width:400px;margin:auto;background:#fff;color:#1d2327;border-radius:16px;padding:28px 24px 22px;box-shadow:0 24px 64px rgba(0,0,0,.28);text-align:center}'
			. '.barar-verify-popup__title{margin:0 0 6px;font-size:1.2em;line-height:1.3;color:inherit}'
			. '.barar-verify-popup__close{position:absolute;right:8px;top:6px;font:inherit;font-size:26px;line-height:1;color:#50575e;background:none;border:0;padding:6px 10px;cursor:pointer;border-radius:6px;box-shadow:none}'
			. 'body.barar-verify-popup--open{overflow:hidden}'
			. '.barar-verify-popup .barar-verify{margin:0;padding:0;background:transparent;border:0;box-shadow:none}'
			. '.barar-verify-popup .barar-verify__target,.barar-verify-popup .barar-verify__step--send .barar-verify__send,.barar-verify-popup .barar-verify__step--send .barar-verify__actions{display:none}'
			. '.barar-verify-popup .barar-verify__note{margin:0 0 10px;font-size:.92em;color:#50575e}'
			. '.barar-verify-popup .barar-verify__hint{margin:0 0 12px;font-size:.92em;color:#50575e;word-break:break-word}'
			. '.barar-verify-popup .barar-verify__status{margin:0 0 10px;min-height:1.3em;font-size:.9em}'
			. '.barar-verify-popup .barar-verify__status.is-error{color:#b32d2e}'
			. '.barar-verify-popup .barar-verify__status.is-success{color:#1a7f37}'
			. '.barar-verify-popup .barar-verify__field{margin:0 0 12px}'
			. '.barar-verify-popup .barar-verify__field label{display:block;margin:0 0 6px;font-size:.9em;font-weight:600;text-align:left}'
			. '.barar-verify-popup .barar-verify__code{display:block;width:100%;height:54px;margin:0;padding:0 12px;font-size:26px;letter-spacing:.45em;text-align:center;font-variant-numeric:tabular-nums;border:2px solid #c3c4c7;border-radius:12px;background:#fff;color:#1d2327;box-shadow:none}'
			. '.barar-verify-popup .barar-verify__code:focus{outline:0;border-color:#2271b1;box-shadow:0 0 0 3px rgba(34,113,177,.2)}'
			. '.barar-verify-popup .barar-verify__meta{display:flex;align-items:center;gap:10px;margin:0 0 14px}'
			. '.barar-verify-popup .barar-verify__expiry{min-width:3.4em;font-size:.95em;font-weight:600;font-variant-numeric:tabular-nums;color:#50575e}'
			. '.barar-verify-popup .barar-verify__bar{flex:1;height:6px;border-radius:99px;background:#e5e7eb;overflow:hidden}'
			. '.barar-verify-popup .barar-verify__bar i{display:block;height:100%;width:100%;border-radius:99px;background:#2271b1;transition:width 1s linear}'
			. '.barar-verify-popup .barar-verify__bar.is-low i{background:#b32d2e}'
			. '.barar-verify-popup .barar-verify__actions{display:flex;flex-direction:column;gap:8px;margin:0 0 8px}'
			. '.barar-verify-popup .barar-verify__actions .button{display:block;width:100%;min-height:46px;margin:0;padding:10px 14px;border-radius:12px;font-size:1em;line-height:1.2;cursor:pointer}'
			. '.barar-verify-popup .barar-verify__ok{background:#2271b1;border:1px solid #2271b1;color:#fff;font-weight:600}'
			. '.barar-verify-popup .barar-verify__resend{background:#f6f7f7;border:1px solid #c3c4c7;color:#1d2327;font-variant-numeric:tabular-nums}'
			. '.barar-verify-popup .barar-verify__resend[disabled]{opacity:.65;cursor:not-allowed}'
			. '.barar-verify-popup .barar-verify__change,.barar-verify-popup .barar-verify__restart{background:none;border:0;color:#2271b1;text-decoration:underline;box-shadow:none;min-height:0;padding:6px}'
			. '.barar-verify-popup .barar-verify__spinner{display:inline-block;width:18px;height:18px;margin:0 auto;border:2px solid #c3c4c7;border-top-color:#2271b1;border-radius:50%;animation:barar-vspin .7s linear infinite}'
			. '.barar-verify-popup .barar-verify__spinner[hidden]{display:none}'
			. '.barar-verify-popup .barar-verify__done{margin:0;font-weight:600;color:#1a7f37}'
			. '@keyframes barar-vspin{to{transform:rotate(360deg)}}'
			. '@media (max-width:480px){.barar-verify-popup__panel{padding:26px 16px 18px;border-radius:14px}}';
	}

	/**
	 * The shared body of the widget: note, status, the two steps and the
	 * verified state. Printed inside the inline card and inside the popup.
	 *
	 * @param string $id Widget id, used for the code input and its label.
	 * @return void
	 */
	private function widget_body( $id ) {
		$note  = Barar_Atik_Settings::label( 'checkout_verify_note' );
		$label = Barar_Atik_Settings::label( 'btn_verify' );
		$send  = Barar_Atik_Settings::label( 'btn_send' );
		$done  = Barar_Atik_Settings::label( 'verify_done' );
		?>
			<?php if ( '' !== $note ) : ?>
			<p class="barar-verify__note"><?php echo esc_html( $note ); ?></p>
			<?php endif; ?>

			<p class="barar-verify__status" role="status" aria-live="polite"></p>

			<div class="barar-verify__step barar-verify__step--send">
				<?php if ( $this->phone_required() ) : ?>
					<p class="barar-verify__target barar-verify__target--phone">
						<?php if ( '' !== Barar_Atik_Settings::phone_label() ) : ?>
						<span class="barar-verify__target-label"><?php echo esc_html( Barar_Atik_Settings::phone_label() ); ?></span>
						<?php endif; ?>
						<code class="barar-verify__target-value" data-barar-verify-target="phone"></code>
						<button type="button" class="barar-verify__edit" data-barar-verify-edit="phone"<?php echo '' === Barar_Atik_Settings::label( 'btn_edit' ) ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>>
							<?php echo esc_html( Barar_Atik_Settings::label( 'btn_edit' ) ); ?>
						</button>
					</p>
				<?php endif; ?>
				<?php if ( $this->email_required() ) : ?>
					<p class="barar-verify__target barar-verify__target--email">
						<?php if ( '' !== Barar_Atik_Settings::label( 'lbl_email' ) ) : ?>
						<span class="barar-verify__target-label"><?php echo esc_html( Barar_Atik_Settings::label( 'lbl_email' ) ); ?></span>
						<?php endif; ?>
						<code class="barar-verify__target-value" data-barar-verify-target="email"></code>
						<button type="button" class="barar-verify__edit" data-barar-verify-edit="email"<?php echo '' === Barar_Atik_Settings::label( 'btn_edit' ) ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>>
							<?php echo esc_html( Barar_Atik_Settings::label( 'btn_edit' ) ); ?>
						</button>
					</p>
				<?php endif; ?>
				<p class="barar-verify__actions">
					<button type="button" class="barar-verify__send button"><?php echo esc_html( $send ); ?></button>
					<span class="barar-verify__spinner" hidden aria-hidden="true"></span>
				</p>
			</div>

			<div class="barar-verify__step barar-verify__step--code" hidden>
				<p class="barar-verify__hint"></p>
				<p class="barar-verify__field">
					<?php if ( '' !== Barar_Atik_Settings::label( 'lbl_code' ) ) : ?>
					<label for="<?php echo esc_attr( $id . '-code' ); ?>"><?php echo esc_html( Barar_Atik_Settings::label( 'lbl_code' ) ); ?></label>
					<?php endif; ?>
					<input type="text" id="<?php echo esc_attr( $id . '-code' ); ?>" name="barar_vcode" class="barar-verify__input barar-verify__code"
						inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]*" maxlength="8" />
				</p>
				<div class="barar-verify__meta" aria-live="off">
					<span class="barar-verify__expiry" data-barar-expiry></span>
					<span class="barar-verify__bar" aria-hidden="true"><i data-barar-bar></i></span>
				</div>
				<p class="barar-verify__actions">
					<button type="button" class="barar-verify__ok button"><?php echo esc_html( $label ); ?></button>
					<button type="button" class="barar-verify__resend button" disabled data-label="<?php echo esc_attr( Barar_Atik_Settings::label( 'btn_resend' ) ); ?>"><?php echo esc_html( Barar_Atik_Settings::label( 'btn_resend' ) ); ?></button>
					<span class="barar-verify__spinner" hidden aria-hidden="true"></span>
				</p>
				<p class="barar-verify__actions">
					<button type="button" class="barar-verify__change button"<?php echo '' === Barar_Atik_Settings::label( 'verify_change' ) ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>><?php echo esc_html( Barar_Atik_Settings::label( 'verify_change' ) ); ?></button>
				</p>
			</div>

			<p class="barar-verify__done" hidden>
				<span class="barar-verify__done-mark" aria-hidden="true">&check;</span>
				<?php echo esc_html( $done ); ?>
				<button type="button" class="barar-verify__restart button"<?php echo '' === Barar_Atik_Settings::label( 'verify_change' ) ? ' style="display:none" data-barar-blank="1"' : ''; // phpcs:ignore ?>>
					<?php echo esc_html( Barar_Atik_Settings::label( 'verify_change' ) ); ?>
				</button>
			</p>
		<?php
	}
}