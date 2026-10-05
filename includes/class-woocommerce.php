<?php
/**
 * WooCommerce integration: order SMS automations with Customer / Vendor /
 * Admin recipients, HPOS-safe CRUD access, duplicate prevention and
 * complete failure isolation from checkout.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_WooCommerce {

	/**
	 * Send pipeline.
	 *
	 * @var Barar_Atik_SMS
	 */
	private $sms;

	/**
	 * Message log.
	 *
	 * @var Barar_Atik_Message_Log
	 */
	private $log;

	/**
	 * Dependencies.
	 *
	 * @param Barar_Atik_SMS          $sms Send pipeline.
	 * @param Barar_Atik_Message_Log  $log Message log.
	 */
	public function __construct( $sms, $log ) {
		$this->sms  = $sms;
		$this->log  = $log;

		add_action( 'woocommerce_new_order', array( $this, 'on_new_order' ), 20, 2 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * Configured events (extensible through a filter).
	 *
	 * @return array
	 */
	public static function events() {
		$events = Barar_Atik_Settings::get( 'events', array() );
		return (array) apply_filters( 'barar_atik_wc_events', is_array( $events ) ? $events : array() );
	}

	/**
	 * Handle a newly created order.
	 *
	 * @param int                $order_id Order ID.
	 * @param WC_Order|null      $order    Order object when provided.
	 * @return void
	 */
	public function on_new_order( $order_id, $order = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		}
		if ( ! $order ) {
			return;
		}

		$this->trigger( $order, 'new_order' );

		// An order can be created directly with a tracked status (admin/COD).
		// The status hook below may also fire; dedup keys make that harmless.
		$status = $order->get_status();
		if ( in_array( $status, array( 'processing', 'completed', 'cancelled', 'failed' ), true ) ) {
			$this->trigger( $order, $status );
		}
	}

	/**
	 * Handle an order status transition. The target status is read from the
	 * order object itself, so hook argument order never matters.
	 *
	 * @param int      $order_id Order ID.
	 * @param string   $from     From status.
	 * @param string   $to       To status.
	 * @param WC_Order $order    Order object.
	 * @return void
	 */
	public function on_status_changed( $order_id, $from = '', $to = '', $order = null ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		}
		if ( ! $order ) {
			return;
		}

		$this->trigger( $order, $order->get_status() );
	}

	/**
	 * Send the configured notifications for one event.
	 *
	 * Failures are recorded and swallowed: order processing never breaks.
	 *
	 * @param WC_Order $order      Order.
	 * @param string   $event_key  Event key.
	 * @return void
	 */
	public function trigger( $order, $event_key ) {
		try {
			$events = self::events();
			if ( ! isset( $events[ $event_key ] ) ) {
				return;
			}
			$event = $events[ $event_key ];
			if ( empty( $event['enabled'] ) || '1' !== (string) $event['enabled'] ) {
				return;
			}

			$recipients = isset( $event['recipients'] ) && is_array( $event['recipients'] ) ? $event['recipients'] : array();
			$recipients = array_filter( array_map( 'strval', $recipients ) );
			if ( empty( $recipients ) ) {
				return;
			}

			$main_tpl = isset( $event['tpl'] ) ? (string) $event['tpl'] : '';
			if ( '' === trim( $main_tpl ) ) {
				return;
			}

			foreach ( array_unique( $recipients ) as $type ) {
				foreach ( $this->resolve_recipients( $order, $type ) as $recipient ) {
					$this->send_one( $order, $event_key, $type, $recipient, $event, $main_tpl );
				}
			}
		} catch ( Throwable $e ) {
			// Isolate the plugin from WooCommerce completely.
			do_action( 'barar_atik_sms_error', $e->getMessage() );
		}
	}

	/**
	 * Build and send one message.
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $event_key Event key.
	 * @param string   $type      Recipient type.
	 * @param array    $recipient Recipient data (phone, name, vendor).
	 * @param array    $event     Event settings.
	 * @param string   $main_tpl  Main template.
	 * @return void
	 */
	private function send_one( $order, $event_key, $type, $recipient, $event, $main_tpl ) {
		$template = $main_tpl;
		if ( 'vendor' === $type && ! empty( $event['tpl_vendor'] ) ) {
			$template = (string) $event['tpl_vendor'];
		} elseif ( 'admin' === $type && ! empty( $event['tpl_admin'] ) ) {
			$template = (string) $event['tpl_admin'];
		}

		$phone = Barar_Atik_Phone::normalize( isset( $recipient['phone'] ) ? $recipient['phone'] : '' );

		$context = array(
			'order'           => $order,
			'recipient_type'  => $type,
			'recipient_phone' => $phone,
			'recipient_name'  => isset( $recipient['name'] ) ? $recipient['name'] : '',
			'vendor'          => isset( $recipient['vendor'] ) ? $recipient['vendor'] : array(),
		);

		$message = Barar_Atik_Macros::render( $template, $context );

		$dedup = 'wc:' . sha1( $order->get_id() . '|' . $event_key . '|' . $type . '|' . $phone . '|' . md5( $message ) );

		if ( '' === $phone ) {
			// Record the skip so administrators can diagnose missing numbers.
			$this->log->insert(
				array(
					'dedup_key' => $dedup,
					'status'    => 'failed',
					'source'    => 'wc_order',
					'event'     => $event_key,
					'ref_type'  => 'order',
					'ref_id'    => $order->get_id(),
					'recipient' => '',
					'message'   => $message,
					'error'     => __( 'Skipped: no valid recipient phone number for this recipient type.', 'barar-atik-sms-otp' ),
					'failed_at' => current_time( 'mysql' ),
				)
			);
			return;
		}

		$result = $this->sms->send(
			array(
				'recipient' => $phone,
				'message'   => $message,
				'source'    => 'wc_order',
				'event'     => $event_key,
				'ref_type'  => 'order',
				'ref_id'    => $order->get_id(),
				'dedup'     => $dedup,
				'retry'     => true,
			)
		);

		// Record the refusal (e.g. an empty rendered template) for diagnostics.
		// Never bubbles up: checkout must not be affected either way.
		if ( is_wp_error( $result ) ) {
			$this->log->insert(
				array(
					'dedup_key' => $dedup,
					'status'    => 'failed',
					'source'    => 'wc_order',
					'event'     => $event_key,
					'ref_type'  => 'order',
					'ref_id'    => $order->get_id(),
					'recipient' => $phone,
					'message'   => $message,
					'error'     => $result->get_error_message(),
					'failed_at' => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Resolve the phone numbers for a recipient type.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $type  customer|vendor|admin.
	 * @return array List of array( phone, name, vendor ).
	 */
	public function resolve_recipients( $order, $type ) {
		$out = array();

		if ( 'customer' === $type ) {
			$phone = (string) $order->get_billing_phone();
			$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$out[] = array(
				'phone' => $phone,
				'name'  => $name ? $name : (string) $order->get_formatted_billing_full_name(),
				'vendor'=> array(),
			);
		} elseif ( 'admin' === $type ) {
			$out[] = array(
				'phone' => self::admin_phone( false ),
				'name'  => __( 'Administrator', 'barar-atik-sms-otp' ),
				'vendor'=> array(),
			);
		} elseif ( 'vendor' === $type ) {
			$seen_phone = array();
			foreach ( self::order_vendors( $order ) as $vendor ) {
				$normalized = Barar_Atik_Phone::normalize( $vendor['phone'] );
				if ( '' === $normalized || isset( $seen_phone[ $normalized ] ) ) {
					continue;
				}
				$seen_phone[ $normalized ] = true;
				$out[]                     = array(
					'phone'  => $vendor['phone'],
					'name'   => $vendor['name'],
					'vendor' => $vendor,
				);
			}
		}

		return (array) apply_filters( 'barar_atik_order_recipients', $out, $type, $order );
	}

	/**
	 * Vendors / product authors for an order.
	 *
	 * Uses product post authorship (products are posts in every WooCommerce
	 * storage mode; only orders are HPOS-sensitive).
	 *
	 * @param WC_Order $order Order.
	 * @return array list of array( user_id, name, phone )
	 */
	public static function order_vendors( $order ) {
		$vendors = array();
		if ( ! $order ) {
			return $vendors;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$variation  = (int) $item->get_variation_id();
			$post_id    = $variation ? $variation : $product_id;
			if ( ! $post_id ) {
				continue;
			}

			$user_id = (int) apply_filters( 'barar_atik_vendor_user_id', (int) get_post_field( 'post_author', $post_id ), $post_id, $order );
			if ( $user_id <= 0 || isset( $vendors[ $user_id ] ) ) {
				continue;
			}

			$user = get_userdata( $user_id );
			if ( ! $user ) {
				continue;
			}

			$vendors[ $user_id ] = array(
				'user_id' => $user_id,
				'name'    => $user->display_name,
				'phone'   => self::user_phone( $user_id ),
			);
		}

		return array_values( $vendors );
	}

	/**
	 * First vendor of an order (used by macro previews).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function first_vendor( $order ) {
		$vendors = self::order_vendors( $order );
		if ( empty( $vendors ) ) {
			return array(
				'name'  => '',
				'phone' => '',
			);
		}
		return array(
			'name'  => $vendors[0]['name'],
			'phone' => $vendors[0]['phone'],
		);
	}

	/**
	 * Best phone number for a user account.
	 *
	 * @param int $user_id User ID.
	 * @return string Raw value (may be empty).
	 */
	public static function user_phone( $user_id ) {
		$user_id = (int) $user_id;
		$keys    = Barar_Atik_Phone::meta_keys();

		$phone = (string) apply_filters( 'barar_atik_user_phone', '', $user_id, $keys );
		if ( '' !== $phone ) {
			return $phone;
		}

		foreach ( $keys as $key ) {
			$value = get_user_meta( $user_id, $key, true );
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Administrator phone: manual override first, then the first admin
	 * account that has a usable number.
	 *
	 * @param bool $normalize Return normalized number (default) or raw.
	 * @return string
	 */
	public static function admin_phone( $normalize = true ) {
		$manual = trim( (string) Barar_Atik_Settings::get( 'admin_phone', '' ) );
		if ( '' !== $manual ) {
			$number = Barar_Atik_Phone::normalize( $manual );
			if ( $number ) {
				return $normalize ? $number : $manual;
			}
		}

		$admins = get_users(
			array(
				'role__in' => array( 'administrator' ),
				'number'   => 10,
				'orderby'  => 'ID',
				'order'    => 'ASC',
				'fields'   => array( 'ID' ),
			)
		);

		foreach ( (array) $admins as $admin ) {
			$user_id = is_object( $admin ) ? (int) $admin->ID : (int) $admin;
			foreach ( Barar_Atik_Phone::meta_keys() as $key ) {
				$value = get_user_meta( $user_id, $key, true );
				if ( ! is_string( $value ) || '' === trim( $value ) ) {
					continue;
				}
				$number = Barar_Atik_Phone::normalize( $value );
				if ( $number ) {
					return $normalize ? $number : $value;
				}
			}
		}

		return '';
	}
}
