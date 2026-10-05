<?php
/**
 * Plugin settings: defaults, access, sanitization and Settings API registration.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Settings {

	const OPTION   = 'barar_atik_settings';
	const GROUP    = 'barar_atik';
	const DB_FLAG  = 'barar_atik_db_version';

	/**
	 * Merged settings, cached for the request (get() is called many times per page).
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default configuration.
	 *
	 * @return array
	 */
	public static function defaults() {
		$event_defaults = array(
			'new_order'  => array(
				'label'      => __( 'New order', 'barar-atik-sms-otp' ),
				'enabled'    => '1',
				'recipients' => array( 'customer', 'admin' ),
				'tpl'        => __( 'New order {order_number} received from {customer_name}. Total {order_total}. Items: {items}.', 'barar-atik-sms-otp' ),
				'tpl_vendor' => '',
				'tpl_admin'  => __( 'New order {order_number} received. Total {order_total}.', 'barar-atik-sms-otp' ),
			),
			'processing' => array(
				'label'      => __( 'Order processing', 'barar-atik-sms-otp' ),
				'enabled'    => '1',
				'recipients' => array( 'customer' ),
				'tpl'        => __( 'Hi {first_name}, your order {order_number} is now being processed.', 'barar-atik-sms-otp' ),
				'tpl_vendor' => '',
				'tpl_admin'  => '',
			),
			'completed'  => array(
				'label'      => __( 'Order completed', 'barar-atik-sms-otp' ),
				'enabled'    => '1',
				'recipients' => array( 'customer' ),
				'tpl'        => __( 'Thanks {first_name}! Your order {order_number} has been completed.', 'barar-atik-sms-otp' ),
				'tpl_vendor' => '',
				'tpl_admin'  => '',
			),
			'cancelled'  => array(
				'label'      => __( 'Order cancelled', 'barar-atik-sms-otp' ),
				'enabled'    => '0',
				'recipients' => array( 'customer' ),
				'tpl'        => __( 'Your order {order_number} has been cancelled.', 'barar-atik-sms-otp' ),
				'tpl_vendor' => '',
				'tpl_admin'  => '',
			),
			'failed'     => array(
				'label'      => __( 'Order failed', 'barar-atik-sms-otp' ),
				'enabled'    => '0',
				'recipients' => array( 'customer', 'admin' ),
				'tpl'        => __( 'Payment for order {order_number} failed. Please contact us for help.', 'barar-atik-sms-otp' ),
				'tpl_vendor' => '',
				'tpl_admin'  => '',
			),
		);

		$defaults = array(
			// Connection.
			'api_key'           => '',
			'device_id'         => '',
			'country_code'      => '880',
			'admin_phone'       => '',

			// Webhook (TextBee delivery-status push).
			'webhook_enabled'   => '0',
			'webhook_secret'    => '',
			'webhook_id'        => '',

			// SMS behaviour.
			'retry_enabled'     => '1',
			'retry_count'       => '2',

			// OTP: which forms get an OTP option.
			'otp_wp_login'      => '1',
			'otp_wp_register'   => '1',
			'otp_wc_login'      => '1',
			'otp_wc_register'   => '1',

			// OTP: behaviour.
			'password_login'        => '1',
			'passwordless_register' => '0',
			'auto_login_register'   => '1',
			'show_password_toggle'  => '1',
			'register_role'         => class_exists( 'WooCommerce' ) ? 'customer' : 'subscriber',
			'otp_template'      => __( 'Your {site_name} verification code is {otp}. It expires in {otp_expiry} minutes.', 'barar-atik-sms-otp' ),

			// Account creation: which system creates the account and which
			// fields the registration form asks for.
			// reg_* states: 0 = hidden, 1 = shown (optional), 2 = shown (required).
			'phone_as_username' => '0',
			'register_system'   => 'auto', // auto | wordpress | woocommerce.
			'reg_first_name'    => '1',
			'reg_last_name'     => '1',
			'reg_email'         => '2',
			'phone_label'       => '',     // Empty falls back to the translated default.

			// Where the visitor lands after an OTP login/registration.
			'redirect_mode'     => 'default', // default | previous | custom.
			'redirect_url'      => '',

			// Username generated from a phone number:
			// '1' stores the local form (01722032083), '0' keeps the full
			// international digits (8801722032083).
			'username_local_format' => '1',

			// Native form enhancements (eye icon + email autocomplete on the
			// WordPress/WooCommerce forms themselves).
			'enhance_native'    => '1',

			// Password reset with a phone OTP instead of an email link.
			'otp_wp_reset'      => '1',
			'otp_wc_reset'      => '1',
			'reset_auto_login'  => '1',

			// Checkout: phone login/registration and ordering without an email.
			'checkout_otp_login'     => '1',
			'checkout_otp_register'  => '1',
			'checkout_login_no_email'=> '0',
			'checkout_order_no_email'=> '0',

			/*
			 * Checkout authentication tabs. The plugin draws the whole
			 * "enable login during checkout" area, so WooCommerce's own login
			 * form becomes one tab next to the two phone forms.
			 */
			'checkout_auth_tabs'   => '1',
			'checkout_popup'       => '0',
			'checkout_popup_label' => '',
			'checkout_top_content' => '',
			'checkout_bottom_content'=> '',
			'checkout_popup_title' => '',

			/*
			 * Guest verification: a code must be proven for the phone number
			 * (and, when one is given, the email address) before the order can
			 * be placed. Enforced server side in process_checkout().
			 */
			'checkout_verify'       => '1',
			/*
			 * Who the gate applies to, chosen separately: a store can insist
			 * on a proven number from a signed-out shopper while trusting an
			 * already signed-in one, or the other way round.
			 */
			'checkout_verify_guests'=> '1',
			'checkout_verify_users' => '0',
			'checkout_verify_resend' => '60',
			'checkout_verify_expiry' => '1',
			'checkout_verify_phone'  => '1',
			'checkout_verify_email'  => '1',
			'checkout_verify_note'   => '',
			'verify_popup_title'     => '',

			// Checkout: the code-verified phone number becomes the WooCommerce
			// customer account, so an order can be placed with a phone number
			// only and no email address anywhere.
			'checkout_auto_customer'       => '1',
			'checkout_auto_customer_login' => '1',
			'checkout_auto_customer_create'=> '1',
			'checkout_auto_customer_role'  => 'customer',

			// Mirror WooCommerce emails to SMS.
			'email_to_sms'      => '0',
			'email_to_sms_types'=> array( 'customer_new_order', 'customer_processing_order', 'customer_completed_order' ),
			'email_to_sms_tpl'  => __( '{email_subject}. Order {order_number}.', 'barar-atik-sms-otp' ),

			// WooCommerce checkout field text (empty keeps the WooCommerce label).
			'txt_wc_email_label'   => '',
			'txt_wc_email_ph'      => '',
			'txt_wc_phone_label'   => '',
			'txt_wc_phone_ph'      => '',
			'txt_wc_first_label'   => '',
			'txt_wc_last_label'    => '',
			'txt_checkout_login'   => '',
			'txt_checkout_register'=> '',

			// Checkout authentication tab labels.
			'tab_auth_login'    => '',
			'tab_auth_register' => '',
			'tab_auth_account'  => '',
			'tab_auth_email'    => '',

			// Form field text (empty keeps the translated default).
			'lbl_email'       => '',
			'lbl_username'    => '',
			'lbl_first_name'  => '',
			'lbl_last_name'   => '',
			'lbl_password'    => '',
			'lbl_new_password'=> '',
			'lbl_code'        => '',
			'ph_phone'        => '',
			'ph_email'        => '',
			'ph_username'     => '',
			'btn_send'        => '',
			'btn_verify'      => '',
			'btn_edit'        => '',
			'btn_verify_login'=> '',
			'btn_verify_register' => '',
			'btn_verify_reset'    => '',
			'btn_resend'      => '',
			'btn_change'      => '',
			'btn_back_login'  => '',
			'btn_back_register'   => '',
			'btn_back_reset'  => '',
			'switch_login'    => '',
			'switch_register' => '',
			'switch_reset'    => '',
			'tab_password'    => '',
			'tab_otp'         => '',
			'tab_email'       => '',

			// Checkout contact verification text.
			'verify_done'           => '',
			'verify_error'          => '',
			'verify_change'         => '',
			'verify_email_subject'  => '',

			// Frontend design (only used when "Disable plugin styles" is off).
			'disable_styles'  => '0',
			'design_primary'  => '#1e6dd8',
			'design_border'   => '#c3c4c7',
			'design_surface'  => '#ffffff',
			'design_label'    => '#1d2327',
			'design_radius'   => '6',
			'design_font'     => '15',
			'design_width'    => '470',
			'design_button_bg'   => '',
			'design_button_text' => '',

			// WooCommerce form integration: render the OTP fields inside the
			// native forms instead of the separate panel below them.
			'wc_inject'         => '0',

			// OTP: security limits.
			'otp_length'        => '6',
			'otp_expiry'        => '5',
			'otp_resend'        => '60',
			'otp_max_attempts'  => '5',
			'otp_limit_phone'   => '5',
			'otp_limit_ip'      => '20',

			// WooCommerce events.
			'events'            => $event_defaults,
		);

		return (array) apply_filters( 'barar_atik_settings_defaults', $defaults );
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = self::defaults();
		$merged   = self::replace_recursive( $defaults, $stored );

		// Events are stored lists; make sure the recipient list is a plain list of known keys.
		foreach ( $merged['events'] as $key => $event ) {
			$merged['events'][ $key ] = self::clean_event( $event );
		}

		// Defaults carry translated strings, so only cache once translations can load.
		if ( did_action( 'init' ) ) {
			self::$cache = $merged;
		}

		return $merged;
	}

	/**
	 * Drop the per-request settings cache (hooked to the option's add/update/delete).
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Read a single value. Supports dot paths such as "events.new_order.enabled".
	 *
	 * @param string $key     Key or dot path.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();
		if ( ! isset( $all[ $key ] ) && false === strpos( $key, '.' ) ) {
			return $default;
		}
		$parts = explode( '.', $key );
		$value = $all;
		foreach ( $parts as $part ) {
			if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
				return $default;
			}
			$value = $value[ $part ];
		}
		return $value;
	}

	/**
	 * Store a value at a top level key (merge + save). Used by admin AJAX helpers.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	public static function set( $key, $value ) {
		$all         = self::all();
		$all[ $key ] = $value;
		// No autoload argument: the stored autoload mode is preserved (this
		// option is read on frontend requests, so it should stay cached).
		return update_option( self::OPTION, $all );
	}

	/**
	 * Whether an OTP context is enabled (wp_login, wp_register, wc_login, wc_register).
	 *
	 * @param string $context Context key.
	 * @return bool
	 */
	public static function context_enabled( $context ) {
		$map = array(
			'wp_login'    => 'otp_wp_login',
			'wp_register' => 'otp_wp_register',
			'wc_login'    => 'otp_wc_login',
			'wc_register' => 'otp_wc_register',
			'wp_reset'    => 'otp_wp_reset',
			'wc_reset'    => 'otp_wc_reset',
		);
		if ( ! isset( $map[ $context ] ) ) {
			return false;
		}
		return '1' === self::get( $map[ $context ], '0' );
	}

	/**
	 * Register the option with the Settings API.
	 */
	public function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Make an event array safe.
	 *
	 * @param mixed $event Event data.
	 * @return array
	 */
	private static function clean_event( $event ) {
		if ( ! is_array( $event ) ) {
			$event = array();
		}
		$allowed = array( 'customer', 'vendor', 'admin' );
		$recips  = isset( $event['recipients'] ) && is_array( $event['recipients'] ) ? $event['recipients'] : array();

		return array(
			'label'      => isset( $event['label'] ) ? (string) $event['label'] : '',
			'enabled'    => empty( $event['enabled'] ) ? '0' : '1',
			'recipients' => array_values( array_intersect( $allowed, array_map( 'strval', $recips ) ) ),
			'tpl'        => isset( $event['tpl'] ) ? (string) $event['tpl'] : '',
			'tpl_vendor' => isset( $event['tpl_vendor'] ) ? (string) $event['tpl_vendor'] : '',
			'tpl_admin'  => isset( $event['tpl_admin'] ) ? (string) $event['tpl_admin'] : '',
		);
	}

	/**
	 * Is this a sequential (list) array? Empty arrays count as lists so an
	 * explicitly emptied list (e.g. no recipient checkboxes checked) replaces
	 * the previous value instead of merging back into it.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	private static function is_list_arr( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Like array_replace_recursive(), except lists always replace wholesale.
	 * (array_replace_recursive() merges lists index by index, which would turn
	 * a stored recipients list of ['customer'] back into ['customer','admin'].)
	 *
	 * @param array $defaults Default values.
	 * @param array $stored   Stored values.
	 * @return array
	 */
	private static function replace_recursive( $defaults, $stored ) {
		$out = $defaults;
		foreach ( $stored as $key => $value ) {
			if ( ! isset( $out[ $key ] ) || ! is_array( $out[ $key ] ) ) {
				// Unknown key or scalar default: take the stored value as-is.
				$out[ $key ] = $value;
				continue;
			}
			if ( ! is_array( $value ) ) {
				// Type mismatch (corrupt storage where an array is expected): keep the default.
				continue;
			}
			if ( self::is_list_arr( $value ) || self::is_list_arr( $out[ $key ] ) ) {
				// Lists replace wholesale, never index by index.
				$out[ $key ] = $value;
				continue;
			}
			$out[ $key ] = self::replace_recursive( $out[ $key ], $value );
		}
		return $out;
	}

	/**
	 * Merge helper: associative arrays recurse, lists replace wholesale.
	 *
	 * @param array $existing Existing values.
	 * @param array $input    Submitted values.
	 * @return array
	 */
	private static function merge( $existing, $input ) {
		$out = $existing;
		foreach ( $input as $key => $value ) {
			$existing_is_array = isset( $out[ $key ] ) && is_array( $out[ $key ] );
			$is_list           = self::is_list_arr( $value );

			if ( is_array( $value ) && $existing_is_array && ! $is_list ) {
				$out[ $key ] = self::merge( $out[ $key ], $value );
			} else {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Settings API sanitize callback. Only keys present in the input are changed,
	 * so every admin page can save the shared option independently.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$clean = array();

		// Connection fields.
		if ( array_key_exists( 'api_key', $input ) ) {
			$key = trim( sanitize_text_field( (string) $input['api_key'] ) );
			// Blank keeps the currently stored key.
			$clean['api_key'] = '' === $key ? ( isset( $existing['api_key'] ) ? (string) $existing['api_key'] : '' ) : $key;
		}
		if ( array_key_exists( 'device_id', $input ) ) {
			$device = trim( sanitize_text_field( (string) $input['device_id'] ) );
			$clean['device_id'] = '' === $device ? ( isset( $existing['device_id'] ) ? (string) $existing['device_id'] : '' ) : $device;
		}
		if ( array_key_exists( 'country_code', $input ) ) {
			$cc = preg_replace( '/\D+/', '', (string) $input['country_code'] );
			$clean['country_code'] = '' !== $cc ? substr( $cc, 0, 4 ) : '880';
		}
		if ( array_key_exists( 'admin_phone', $input ) ) {
			$clean['admin_phone'] = sanitize_text_field( (string) $input['admin_phone'] );
		}

		// Webhook.
		if ( array_key_exists( 'webhook_enabled', $input ) ) {
			$clean['webhook_enabled'] = empty( $input['webhook_enabled'] ) ? '0' : '1';
		}
		if ( array_key_exists( 'webhook_secret', $input ) ) {
			$secret = trim( sanitize_text_field( (string) $input['webhook_secret'] ) );
			$clean['webhook_secret'] = '' === $secret ? ( isset( $existing['webhook_secret'] ) ? (string) $existing['webhook_secret'] : '' ) : $secret;
		}

		// Simple flags and numbers.
		$flags = array(
			'retry_enabled',
			'otp_wp_login',
			'otp_wp_register',
			'otp_wc_login',
			'otp_wc_register',
			'password_login',
			'passwordless_register',
			'auto_login_register',
			'show_password_toggle',
			'username_local_format',
			'enhance_native',
			'otp_wp_reset',
			'otp_wc_reset',
			'reset_auto_login',
			'checkout_otp_login',
			'checkout_otp_register',
			'checkout_login_no_email',
			'checkout_order_no_email',
			'checkout_auth_tabs',
			'checkout_popup',
			'checkout_verify',
			'checkout_verify_guests',
			'checkout_verify_users',
			'checkout_verify_phone',
			'checkout_verify_email',
			'checkout_auto_customer',
			'checkout_auto_customer_login',
			'checkout_auto_customer_create',
			'email_to_sms',
			'disable_styles',
		);
		foreach ( $flags as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$clean[ $flag ] = empty( $input[ $flag ] ) ? '0' : '1';
			}
		}

		$numbers = array(
			'retry_count'      => array( 0, 5 ),
			'otp_length'       => array( 4, 8 ),
			'otp_expiry'       => array( 1, 60 ),
			'checkout_verify_resend' => array( 10, 3600 ),
			'checkout_verify_expiry' => array( 1, 60 ),
			'otp_resend'       => array( 10, 3600 ),
			'otp_max_attempts' => array( 1, 10 ),
			'otp_limit_phone'  => array( 1, 100 ),
			'otp_limit_ip'     => array( 1, 200 ),
			'design_radius'    => array( 0, 40 ),
			'design_font'      => array( 12, 24 ),
			'design_width'     => array( 280, 900 ),
		);
		foreach ( $numbers as $num => $range ) {
			if ( array_key_exists( $num, $input ) ) {
				$clean[ $num ] = (string) max( $range[0], min( $range[1], (int) $input[ $num ] ) );
			}
		}

		// Free text: labels, placeholders and button captions.
		$text_keys = array(
			'lbl_email',
			'lbl_username',
			'lbl_first_name',
			'lbl_last_name',
			'lbl_password',
			'lbl_new_password',
			'lbl_code',
			'ph_phone',
			'ph_email',
			'ph_username',
			'btn_send',
			'btn_verify_login',
			'checkout_popup_label',
			'checkout_popup_title',
			'checkout_verify_note',
			'btn_verify_register',
			'btn_verify_reset',
			'btn_edit',
			'btn_resend',
			'btn_change',
			'btn_back_login',
			'btn_back_register',
			'btn_back_reset',
			'switch_login',
			'switch_register',
			'switch_reset',
			'tab_password',
			'tab_otp',
			'tab_email',
			'tab_auth_login',
			'tab_auth_register',
			'tab_auth_account',
			'verify_done',
			'verify_error',
			'verify_change',
			'verify_email_subject',
			'verify_popup_title',
			'txt_wc_email_label',
			'txt_wc_email_ph',
			'txt_wc_phone_label',
			'txt_wc_phone_ph',
			'txt_wc_first_label',
			'txt_wc_last_label',
			'txt_checkout_login',
			'btn_verify',
			'btn_edit',
			'txt_checkout_register',
		);
		foreach ( $text_keys as $text_key ) {
			if ( array_key_exists( $text_key, $input ) ) {
				$clean[ $text_key ] = sanitize_text_field( (string) $input[ $text_key ] );
			}
		}

		// Colour pickers for the frontend design.
		$colors = array( 'design_primary', 'design_border', 'design_surface', 'design_label', 'design_button_bg', 'design_button_text' );
		foreach ( $colors as $color ) {
			if ( array_key_exists( $color, $input ) ) {
				$value = strtoupper( trim( (string) $input[ $color ] ) );
				if ( '' === $value ) {
					$value = self::defaults()[ $color ];
				}
				$clean[ $color ] = preg_match( '/^#[0-9A-F]{6}$/', $value ) ? $value : self::defaults()[ $color ];
			}
		}

		if ( array_key_exists( 'otp_template', $input ) ) {
			$clean['otp_template'] = sanitize_textarea_field( (string) $input['otp_template'] );
		}

		if ( array_key_exists( 'email_to_sms_tpl', $input ) ) {
			$clean['email_to_sms_tpl'] = sanitize_textarea_field( (string) $input['email_to_sms_tpl'] );
		}

		// WooCommerce email classes mirrored to SMS (whitelist of known ids).
		if ( array_key_exists( 'email_to_sms_types', $input ) ) {
			$known_types   = self::known_email_types();
			$chosen        = is_array( $input['email_to_sms_types'] ) ? $input['email_to_sms_types'] : array();
			$clean_types   = array();
			foreach ( $chosen as $type ) {
				$type = sanitize_key( (string) $type );
				if ( in_array( $type, $known_types, true ) ) {
					$clean_types[] = $type;
				}
			}
			$clean['email_to_sms_types'] = array_values( array_unique( $clean_types ) );
		}

		if ( array_key_exists( 'register_role', $input ) ) {
			$clean['register_role'] = self::safe_role( (string) $input['register_role'] );
		}

		if ( array_key_exists( 'checkout_auto_customer_role', $input ) ) {
			$clean['checkout_auto_customer_role'] = self::safe_role( (string) $input['checkout_auto_customer_role'] );
		}

		// New flags (phone-as-username, inline WooCommerce injection).
		$flags2 = array( 'phone_as_username', 'wc_inject' );
		foreach ( $flags2 as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$clean[ $flag ] = empty( $input[ $flag ] ) ? '0' : '1';
			}
		}

		// Enumerated settings.
		$enums = array(
			'register_system' => array(
				'options' => array( 'auto', 'wordpress', 'woocommerce' ),
				'default' => 'auto',
			),
			'redirect_mode'   => array(
				'options' => array( 'default', 'previous', 'custom' ),
				'default' => 'default',
			),
		);
		foreach ( $enums as $key => $rule ) {
			if ( array_key_exists( $key, $input ) ) {
				$value            = sanitize_key( (string) $input[ $key ] );
				$clean[ $key ]    = in_array( $value, $rule['options'], true ) ? $value : $rule['default'];
			}
		}

		// Registration field states: 0 hidden, 1 optional, 2 required.
		$field_states = array(
			'reg_first_name' => '1',
			'reg_last_name'  => '1',
			'reg_email'      => '2',
		);
		foreach ( $field_states as $key => $default_state ) {
			if ( array_key_exists( $key, $input ) ) {
				$value         = (string) $input[ $key ];
				$clean[ $key ] = in_array( $value, array( '0', '1', '2' ), true ) ? $value : $default_state;
			}
		}

		if ( array_key_exists( 'phone_label', $input ) ) {
			$clean['phone_label'] = sanitize_text_field( (string) $input['phone_label'] );
		}

		// Checkout content fields: post HTML plus shortcodes, administrator only.
		foreach ( array( 'checkout_top_content', 'checkout_bottom_content' ) as $slot ) {
			if ( array_key_exists( $slot, $input ) ) {
				$clean[ $slot ] = wp_kses_post( (string) $input[ $slot ] );
			}
		}

		if ( array_key_exists( 'checkout_verify_note', $input ) ) {
			$clean['checkout_verify_note'] = sanitize_text_field( (string) $input['checkout_verify_note'] );
		}

		if ( array_key_exists( 'redirect_url', $input ) ) {
			$clean['redirect_url'] = esc_url_raw( (string) $input['redirect_url'], array( 'http', 'https' ) );
		}

		// WooCommerce events.
		if ( isset( $input['events'] ) && is_array( $input['events'] ) ) {
			$known  = array_keys( self::defaults()['events'] );
			$events = array();
			foreach ( $input['events'] as $event_key => $event ) {
				$event_key = sanitize_key( (string) $event_key );
				if ( ! in_array( $event_key, $known, true ) || ! is_array( $event ) ) {
					continue;
				}
				$recipients = array();
				if ( isset( $event['recipients'] ) && is_array( $event['recipients'] ) ) {
					foreach ( $event['recipients'] as $recipient ) {
						$recipient = sanitize_key( (string) $recipient );
						if ( in_array( $recipient, array( 'customer', 'vendor', 'admin' ), true ) ) {
							$recipients[] = $recipient;
						}
					}
				}
				$events[ $event_key ] = array(
					'label'      => self::defaults()['events'][ $event_key ]['label'],
					'enabled'    => empty( $event['enabled'] ) ? '0' : '1',
					'recipients' => array_values( array_unique( $recipients ) ),
					'tpl'        => sanitize_textarea_field( isset( $event['tpl'] ) ? (string) $event['tpl'] : '' ),
					'tpl_vendor' => sanitize_textarea_field( isset( $event['tpl_vendor'] ) ? (string) $event['tpl_vendor'] : '' ),
					'tpl_admin'  => sanitize_textarea_field( isset( $event['tpl_admin'] ) ? (string) $event['tpl_admin'] : '' ),
				);
			}
			$clean['events'] = $events;
		}

		$merged = self::merge( $existing, $clean );
		// Never allow a privileged role for OTP registration.
		$merged['register_role'] = self::safe_role( isset( $merged['register_role'] ) ? (string) $merged['register_role'] : 'subscriber' );

		return $merged;
	}

	/**
	 * Whitelist the registration role: existing role, never an admin-level one.
	 *
	 * @param string $role Role slug.
	 * @return string
	 */
	public static function safe_role( $role ) {
		$role = sanitize_key( $role );
		/**
		 * Filters the role a new account may receive.
		 *
		 * @param string $role Role slug.
		 */
		$role = apply_filters( 'barar_atik_register_role', $role );

		$allowed = array( 'subscriber', 'customer' );
		if ( ! in_array( $role, $allowed, true ) ) {
			$role = 'subscriber';
		}
		$wp_role = get_role( $role );
		if ( ! $wp_role ) {
			return 'subscriber';
		}
		$forbidden = array( 'manage_options', 'edit_users', 'promote_users', 'edit_files', 'install_plugins' );
		foreach ( $forbidden as $cap ) {
			if ( ! empty( $wp_role->capabilities[ $cap ] ) ) {
				return 'subscriber';
			}
		}
		return $role;
	}

	/**
	 * State of a configurable registration field: '0' hidden, '1' optional,
	 * '2' required. Invalid stored values fall back to the default.
	 *
	 * @param string $key     Settings key (reg_first_name, reg_last_name, reg_email).
	 * @param string $default Default state.
	 * @return string
	 */
	public static function field_mode( $key, $default = '1' ) {
		$value = (string) self::get( $key, $default );
		return in_array( $value, array( '0', '1', '2' ), true ) ? $value : (string) $default;
	}

	/**
	 * Label of the phone input (admin configurable, translated default).
	 *
	 * @return string
	 */
	public static function phone_label() {
		return self::label( 'phone_label' );
	}

	/**
	 * Masked representation of the stored API key, safe to print in admin HTML.
	 *
	 * @return string
	 */
	public static function api_key_hint() {
		$key = (string) self::get( 'api_key', '' );
		if ( '' === $key ) {
			return '';
		}
		$tail = substr( $key, -4 );
		return str_repeat( '•', 8 ) . $tail;
	}

	/**
	 * A form string from the settings, falling back to the translated default
	 * when the administrator left the field empty.
	 *
	 * @param string $key     Settings key.
	 * @param string $default Translated default.
	 * @return string
	 */
	public static function text( $key, $default ) {
		$value = trim( (string) self::get( $key, '' ) );
		return '' !== $value ? $value : $default;
	}

	/**
	 * A visible label, button text, heading or note typed in the settings.
	 *
	 * There is deliberately no built-in wording: what the administrator did
	 * not write is not shown. An empty string means "leave it out".
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	public static function label( $key ) {
		return trim( (string) self::get( $key, '' ) );
	}

	/**
	 * Whether the plugin must not print its own frontend styles.
	 *
	 * @return bool
	 */
	public static function styles_disabled() {
		return '1' === self::get( 'disable_styles', '0' );
	}

	/**
	 * WooCommerce email ids this plugin can mirror to SMS.
	 *
	 * @return string[]
	 */
	public static function known_email_types() {
		$types = array(
			'customer_new_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_on_hold_order',
			'customer_cancelled_order',
			'customer_failed_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
			'customer_reset_password',
			'new_order',
		);
		return (array) apply_filters( 'barar_atik_email_to_sms_types', $types );
	}
}
