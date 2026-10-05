<?php
/**
 * Checkout by phone number: the WooCommerce customer account comes from the
 * phone number the shopper proved with a verification code.
 *
 * The classic WooCommerce checkout is built around an email address, which a
 * phone-first shop often does not have. When this component is on, the
 * verified phone number becomes the customer account: a number that already
 * belongs to an account signs in, an unknown number gets a new account, both
 * without an email address anywhere.
 *
 * Deliberately separate from Barar_Atik_Checkout (the OTP panels and the field
 * labels) and from Barar_Atik_WooCommerce (the order SMS automations), so both
 * keep behaving exactly as they do today.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Checkout_Customer {

	/**
	 * Roles that must never be signed in through the checkout, no matter which
	 * phone number is typed.
	 *
	 * @var string[]
	 */
	private $blocked_roles = array( 'administrator' );

	/**
	 * Hook everything.
	 */
	public function __construct() {
		/*
		 * Priority 3, after the verification gate at priority 2: which
		 * audience the shopper belongs to has to be decided before this sign
		 * in happens, not after it. A guest turned into a signed-in customer
		 * here would otherwise land in the audience that is not checked and
		 * walk past the gate.
		 *
		 * Still well before get_posted_data(), so the rest of the request
		 * runs with a signed-in customer and never tries to create a second
		 * account.
		 */
		add_action( 'woocommerce_checkout_process', array( $this, 'prepare_customer' ), 3 );

		// An account without an email address has nothing to mail.
		add_filter( 'woocommerce_email_enabled_customer_new_account', array( $this, 'new_account_email_enabled' ), 20, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Checkout
	 * ------------------------------------------------------------------ */

	/**
	 * Sign in or create the WooCommerce customer behind the posted phone
	 * number.
	 *
	 * @return void
	 */
	public function prepare_customer() {
		if ( ! $this->enabled() || is_user_logged_in() ) {
			return;
		}

		/*
		 * The verification gate runs at priority 2 and refuses the order by
		 * adding an error notice. Signing a shopper in for an order that is
		 * never going to exist would be wrong on its own - and because the
		 * gate reads is_user_logged_in(), doing it here is exactly how a
		 * guest would end up in the audience that is not checked.
		 */
		if ( function_exists( 'wc_notice_count' ) && wc_notice_count( 'error' ) > 0 ) {
			return;
		}

		// A shopper who typed an email address and asked for an account is on
		// WooCommerce's own path; leave that completely alone.
		if ( '' !== $this->posted( 'billing_email' ) && $this->wants_account() ) {
			return;
		}

		$number = Barar_Atik_Phone::normalize( $this->posted_phone() );
		if ( '' === $number ) {
			// Nothing usable was typed. The phone number being required is the
			// job of the "orders without an email address" field rules.
			return;
		}

		if ( ! Barar_Atik_Verified::has( $number ) ) {
			/*
			 * The number was never proven with a code in this session, so it
			 * stays a guest checkout. There is no setting to skip this: signing
			 * in or creating an account for a number that was merely typed
			 * would let anyone take over any customer's account.
			 */
			return;
		}

		$user = null;
		$how  = '';

		/*
		 * The lookup always runs, even while signing in is switched off: only a
		 * number that belongs to nobody at all may become a new account.
		 */
		$user_ids = Barar_Atik_Phone::find_user_ids( $number );

		if ( empty( $user_ids ) ) {
			if ( $this->may_create() ) {
				$user = $this->create_customer( $number );
				$how  = $user ? 'create' : '';
			}
		} elseif ( $this->may_login() && $this->is_unambiguous( $user_ids ) ) {
			$user = get_userdata( (int) $user_ids[0] );
		}

		if ( ! $user || ! $this->may_sign_in( $user ) ) {
			return;
		}

		$this->login_customer( $user );

		if ( '' === $how ) {
			$how = 'login';
		}

		/**
		 * Fires once the checkout turned a phone number into a customer.
		 *
		 * @param int    $user_id Customer ID.
		 * @param string $number  Normalized phone number.
		 * @param string $how     "login" or "create".
		 */
		do_action( 'barar_atik_checkout_customer', $user->ID, $number, $how );
	}

	/* ---------------------------------------------------------------------
	 * Emails
	 * ------------------------------------------------------------------ */

	/**
	 * Never send the "your account is ready" mail to an empty address.
	 *
	 * @param bool          $enabled Whether the email is enabled.
	 * @param object|null   $email   WooCommerce email object.
	 * @return bool
	 */
	public function new_account_email_enabled( $enabled, $email ) {
		if ( ! $this->enabled() ) {
			return $enabled;
		}

		if ( is_object( $email ) && method_exists( $email, 'get_email' ) ) {
			return '' !== trim( (string) $email->get_email() ) ? $enabled : false;
		}

		return $enabled;
	}

	/* ---------------------------------------------------------------------
	 * Customers
	 * ------------------------------------------------------------------ */

	/**
	 * Does this number belong to exactly one account?
	 *
	 * A number linked to several accounts is never guessed at: the OTP login
	 * reports that case and points the administrator at the resolver on the
	 * Diagnostics page.
	 *
	 * @param int[] $user_ids Matching user IDs.
	 * @return bool
	 */
	private function is_unambiguous( $user_ids ) {
		return 1 === count( $user_ids );
	}

	/**
	 * Create a customer account for a phone number that has none yet.
	 *
	 * @param string $number Normalized number.
	 * @return WP_User|null
	 */
	private function create_customer( $number ) {
		$login = $this->unique_username( $number );
		if ( '' === $login ) {
			return null;
		}

		$first = $this->posted( 'billing_first_name' );
		$last  = $this->posted( 'billing_last_name' );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				/*
				 * Nobody signs in with this password: the account is reached
				 * through a phone code, or the administrator resets it.
				 */
				'user_pass'    => wp_generate_password( 32, true, true ),
				'user_email'   => '',
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
				'role'         => $this->new_role(),
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return null;
		}

		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return null;
		}

		Barar_Atik_Phone::store_for_user( $user->ID, $number );

		if ( '' === trim( (string) $user->display_name ) ) {
			wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => $login,
				)
			);
		}

		return $user;
	}

	/**
	 * An unused WordPress username for a phone number.
	 *
	 * @param string $number Normalized number.
	 * @return string Empty when no usable username could be built.
	 */
	private function unique_username( $number ) {
		$base = Barar_Atik_Phone::username_from( $number );
		if ( '' === $base ) {
			$base = 'customer' . wp_rand( 10000, 99999 );
		}
		$base = substr( $base, 0, 60 );

		$candidate = $base;
		$suffix    = 0;

		while ( username_exists( $candidate ) || ! validate_username( $candidate ) ) {
			$suffix++;
			if ( $suffix > 50 ) {
				$candidate = substr( $base, 0, 50 ) . wp_rand( 10000, 99999 );
				break;
			}
			$candidate = substr( $base, 0, 60 - strlen( (string) $suffix ) ) . $suffix;
		}

		return $candidate;
	}

	/**
	 * Role for an account created at checkout.
	 *
	 * @return string
	 */
	private function new_role() {
		$role = Barar_Atik_Settings::safe_role( (string) Barar_Atik_Settings::get( 'checkout_auto_customer_role', 'customer' ) );

		// WooCommerce reports and e-mails only know the customer role.
		if ( 'subscriber' === $role && class_exists( 'WooCommerce' ) ) {
			$role = 'customer';
		}

		return $role;
	}

	/**
	 * May this account be signed in through the checkout?
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	private function may_sign_in( $user ) {
		$blocked = (array) apply_filters( 'barar_atik_checkout_blocked_roles', $this->blocked_roles );

		return array() === array_intersect( (array) $user->roles, array_map( 'strval', $blocked ) );
	}

	/**
	 * Sign a customer in, keeping WooCommerce's guest cart session attached.
	 *
	 * @param WP_User $user User.
	 * @return void
	 */
	private function login_customer( $user ) {
		if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
			/*
			 * WooCommerce's own helper: the auth cookie plus the guest → user
			 * session migration it performs after it creates a customer, so
			 * the cart that was filled as a guest survives.
			 */
			wc_set_customer_auth_cookie( $user->ID );
		} else {
			wp_set_current_user( $user->ID );
			wp_set_auth_cookie( $user->ID, true, is_ssl() );
		}

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WordPress action, fired so other plugins observe the login.
		do_action( 'wp_login', $user->user_login, $user );
		do_action( 'barar_atik_user_logged_in', $user->ID, 'checkout' );
	}

	/* ---------------------------------------------------------------------
	 * Settings and input
	 * ------------------------------------------------------------------ */

	/**
	 * Is the phone-as-customer-account feature on?
	 *
	 * @return bool
	 */
	private function enabled() {
		return '1' === Barar_Atik_Settings::get( 'checkout_auto_customer', '0' );
	}

	/**
	 * May the administrator allow creating accounts here?
	 *
	 * @return bool
	 */
	private function may_create() {
		return '1' === Barar_Atik_Settings::get( 'checkout_auto_customer_create', '1' );
	}

	/**
	 * May the administrator allow signing in here?
	 *
	 * @return bool
	 */
	private function may_login() {
		return '1' === Barar_Atik_Settings::get( 'checkout_auto_customer_login', '1' );
	}

	/**
	 * Did the shopper tick "create an account"?
	 *
	 * @return bool
	 */
	private function wants_account() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this hook runs.
		return ! empty( $_POST['createaccount'] );
	}

	/**
	 * A posted checkout value.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce before this hook runs.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/**
	 * The phone number of this checkout, whichever field carries it.
	 *
	 * @return string
	 */
	private function posted_phone() {
		foreach ( array( 'billing_phone', 'phone', 'billing_phone_number', 'customer_phone' ) as $key ) {
			$value = $this->posted( $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}