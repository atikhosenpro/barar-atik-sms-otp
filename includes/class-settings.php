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

		return $merged;
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
		$flags = array( 'retry_enabled', 'otp_wp_login', 'otp_wp_register', 'otp_wc_login', 'otp_wc_register', 'password_login', 'passwordless_register', 'auto_login_register' );
		foreach ( $flags as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$clean[ $flag ] = empty( $input[ $flag ] ) ? '0' : '1';
			}
		}

		$numbers = array(
			'retry_count'      => array( 0, 5 ),
			'otp_length'       => array( 4, 8 ),
			'otp_expiry'       => array( 1, 60 ),
			'otp_resend'       => array( 10, 3600 ),
			'otp_max_attempts' => array( 1, 10 ),
			'otp_limit_phone'  => array( 1, 100 ),
			'otp_limit_ip'     => array( 1, 200 ),
		);
		foreach ( $numbers as $num => $range ) {
			if ( array_key_exists( $num, $input ) ) {
				$clean[ $num ] = (string) max( $range[0], min( $range[1], (int) $input[ $num ] ) );
			}
		}

		if ( array_key_exists( 'otp_template', $input ) ) {
			$clean['otp_template'] = sanitize_textarea_field( (string) $input['otp_template'] );
		}

		if ( array_key_exists( 'register_role', $input ) ) {
			$clean['register_role'] = self::safe_role( (string) $input['register_role'] );
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
		$label = trim( (string) self::get( 'phone_label', '' ) );
		return '' !== $label ? $label : __( 'Phone number', 'barar-atik-sms-otp' );
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
}
