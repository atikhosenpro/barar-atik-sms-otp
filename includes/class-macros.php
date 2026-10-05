<?php
/**
 * Dynamic macro / template engine.
 *
 * Macros are resolved at send time from the live WordPress/WooCommerce
 * context, so administrators never paste order data by hand.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Macros {

	/**
	 * Macro definitions for the admin "Available Macros" panel.
	 *
	 * Each entry: group => array( code => description ).
	 *
	 * @return array
	 */
	public static function definitions() {
		return array(
			__( 'Order', 'barar-atik-sms-otp' )    => array(
				'{order_id}'       => __( 'Internal order ID.', 'barar-atik-sms-otp' ),
				'{order_number}'   => __( 'Customer facing order number.', 'barar-atik-sms-otp' ),
				'{order_status}'   => __( 'Human readable order status.', 'barar-atik-sms-otp' ),
				'{order_total}'    => __( 'Order total including currency symbol.', 'barar-atik-sms-otp' ),
				'{currency}'       => __( 'Order currency code, e.g. BDT.', 'barar-atik-sms-otp' ),
				'{payment_method}' => __( 'Payment method title.', 'barar-atik-sms-otp' ),
				'{order_date}'     => __( 'Order creation date.', 'barar-atik-sms-otp' ),
				'{items}'          => __( 'All line items with quantities (alias: {product_names}).', 'barar-atik-sms-otp' ),
			),
			__( 'Customer', 'barar-atik-sms-otp' ) => array(
				'{customer_name}'     => __( 'Customer full name.', 'barar-atik-sms-otp' ),
				'{first_name}'        => __( 'Customer first name.', 'barar-atik-sms-otp' ),
				'{last_name}'         => __( 'Customer last name.', 'barar-atik-sms-otp' ),
				'{customer_phone}'    => __( 'Billing phone number.', 'barar-atik-sms-otp' ),
				'{customer_email}'    => __( 'Billing email address.', 'barar-atik-sms-otp' ),
				'{billing_first_name}'=> __( 'Billing first name.', 'barar-atik-sms-otp' ),
				'{billing_last_name}' => __( 'Billing last name.', 'barar-atik-sms-otp' ),
				'{billing_phone}'     => __( 'Billing phone number.', 'barar-atik-sms-otp' ),
				'{billing_email}'     => __( 'Billing email address.', 'barar-atik-sms-otp' ),
				'{billing_address}'   => __( 'Formatted billing address.', 'barar-atik-sms-otp' ),
				'{shipping_first_name}'=> __( 'Shipping first name.', 'barar-atik-sms-otp' ),
				'{shipping_last_name}' => __( 'Shipping last name.', 'barar-atik-sms-otp' ),
				'{shipping_phone}'    => __( 'Shipping phone number.', 'barar-atik-sms-otp' ),
				'{shipping_address}'  => __( 'Formatted shipping address.', 'barar-atik-sms-otp' ),
			),
			__( 'Product', 'barar-atik-sms-otp' )  => array(
				'{product_name}'     => __( 'First product in the order (use {items} for all).', 'barar-atik-sms-otp' ),
				'{product_id}'       => __( 'First product ID.', 'barar-atik-sms-otp' ),
				'{product_sku}'      => __( 'First product SKU.', 'barar-atik-sms-otp' ),
				'{product_quantity}' => __( 'First product quantity.', 'barar-atik-sms-otp' ),
				'{product_price}'    => __( 'First product line total.', 'barar-atik-sms-otp' ),
			),
			__( 'Recipients', 'barar-atik-sms-otp' ) => array(
				'{recipient_name}'  => __( 'Name of the person receiving this SMS.', 'barar-atik-sms-otp' ),
				'{recipient_phone}' => __( 'Phone number receiving this SMS.', 'barar-atik-sms-otp' ),
				'{vendor_name}'     => __( 'Vendor / product author name.', 'barar-atik-sms-otp' ),
				'{vendor_phone}'    => __( 'Vendor / product author phone.', 'barar-atik-sms-otp' ),
				'{admin_phone}'     => __( 'Administrator phone.', 'barar-atik-sms-otp' ),
			),
			__( 'Site', 'barar-atik-sms-otp' )     => array(
				'{site_name}' => __( 'Site title.', 'barar-atik-sms-otp' ),
				'{site_url}'  => __( 'Site URL.', 'barar-atik-sms-otp' ),
			),
			__( 'OTP', 'barar-atik-sms-otp' )      => array(
				'{otp}'         => __( 'The generated verification code (OTP template only).', 'barar-atik-sms-otp' ),
				'{otp_expiry}'  => __( 'OTP validity in minutes.', 'barar-atik-sms-otp' ),
			),
		);
	}

	/**
	 * Render a template against a context.
	 *
	 * Context keys:
	 *  - order           WC_Order|null
	 *  - recipient_type  customer|vendor|admin|test
	 *  - recipient_phone normalized number
	 *  - recipient_name  name for the recipient
	 *  - vendor          array( name, phone )
	 *  - extra           array( otp, otp_expiry )
	 *
	 * @param string $template Template text.
	 * @param array  $context  Context.
	 * @return string
	 */
	public static function render( $template, $context = array() ) {
		$template = (string) $template;
		if ( '' === $template ) {
			return '';
		}

		$values = self::values( $context );

		$out = preg_replace_callback(
			'/\{([a-z0-9_]+)\}/i',
			function ( $matches ) use ( $values ) {
				$key = strtolower( $matches[0] );
				return array_key_exists( $key, $values ) ? (string) $values[ $key ] : $matches[0];
			},
			$template
		);

		return trim( (string) $out );
	}

	/**
	 * Build the macro value map for a context.
	 *
	 * @param array $context Context.
	 * @return array
	 */
	private static function values( $context ) {
		$order    = isset( $context['order'] ) && is_object( $context['order'] ) ? $context['order'] : null;
		$extra    = isset( $context['extra'] ) && is_array( $context['extra'] ) ? $context['extra'] : array();
		$vendor   = isset( $context['vendor'] ) && is_array( $context['vendor'] ) ? $context['vendor'] : array();
		$r_type   = isset( $context['recipient_type'] ) ? (string) $context['recipient_type'] : '';
		$r_phone  = isset( $context['recipient_phone'] ) ? (string) $context['recipient_phone'] : '';
		$r_name   = isset( $context['recipient_name'] ) ? (string) $context['recipient_name'] : '';

		$values = array(
			'{site_name}' => get_bloginfo( 'name' ),
			'{site_url}'  => home_url( '/' ),
			'{otp}'       => isset( $extra['otp'] ) ? (string) $extra['otp'] : '',
			'{otp_expiry}'=> isset( $extra['otp_expiry'] ) ? (string) $extra['otp_expiry'] : (string) Barar_Atik_Settings::get( 'otp_expiry', '5' ),
			'{admin_phone}' => Barar_Atik_WooCommerce::admin_phone( false ),
		);

		// Defaults so single-product macros never leak braces without an order.
		foreach ( array( '{order_id}', '{order_number}', '{order_status}', '{order_total}', '{currency}', '{payment_method}', '{order_date}', '{items}', '{product_names}', '{customer_name}', '{first_name}', '{last_name}', '{customer_phone}', '{customer_email}', '{billing_first_name}', '{billing_last_name}', '{billing_phone}', '{billing_email}', '{billing_address}', '{shipping_first_name}', '{shipping_last_name}', '{shipping_phone}', '{shipping_address}', '{product_name}', '{product_id}', '{product_sku}', '{product_quantity}', '{product_price}' ) as $code ) {
			$values[ $code ] = '';
		}
		$values['{product_names}'] = '';
		$values['{items}']         = '';

		if ( $order ) {
			$items_list = self::order_items( $order );
			$first_item = reset( $items_list['items'] );

			$billing_first = (string) $order->get_billing_first_name();
			$billing_last  = (string) $order->get_billing_last_name();
			$full_name     = trim( $billing_first . ' ' . $billing_last );
			if ( '' === $full_name ) {
				$full_name = (string) $order->get_formatted_billing_full_name();
			}

			$status_label = function_exists( 'wc_get_order_status_name' )
				? wc_get_order_status_name( $order->get_status() )
				: $order->get_status();

			$currency = (string) $order->get_currency();
			$total    = (string) $order->get_total();
			$total    = wp_strip_all_tags( (string) wc_price( $total, array( 'currency' => $currency ) ) );

			$method = (string) $order->get_payment_method();
			if ( function_exists( 'wc_get_payment_method_title' ) ) {
				$method = (string) wc_get_payment_method_title( $method );
			}

			$order_date = '';
			$created    = $order->get_date_created();
			if ( $created ) {
				$order_date = wp_date( wc_date_format() . ' ' . wc_time_format(), $created->getTimestamp() );
			}

			$values['{order_id}']       = (string) $order->get_id();
			$values['{order_number}']   = (string) $order->get_order_number();
			$values['{order_status}']   = (string) $status_label;
			$values['{order_total}']    = $total;
			$values['{currency}']       = $currency;
			$values['{payment_method}'] = trim( $method );
			$values['{order_date}']     = $order_date;
			$values['{items}']          = $items_list['text'];
			$values['{product_names}']  = $items_list['text'];

			$values['{customer_name}']      = $full_name;
			$values['{first_name}']         = $billing_first ? $billing_first : $full_name;
			$values['{last_name}']          = $billing_last;
			$values['{customer_phone}']     = self::pretty_phone( $order->get_billing_phone() );
			$values['{customer_email}']     = (string) $order->get_billing_email();
			$values['{billing_first_name}'] = $billing_first;
			$values['{billing_last_name}']  = $billing_last;
			$values['{billing_phone}']      = self::pretty_phone( $order->get_billing_phone() );
			$values['{billing_email}']      = (string) $order->get_billing_email();
			$values['{billing_address}']    = self::address( array(
				$order->get_billing_address_1(),
				$order->get_billing_address_2(),
				$order->get_billing_city(),
				$order->get_billing_state(),
				$order->get_billing_postcode(),
			) );

			$values['{shipping_first_name}'] = (string) $order->get_shipping_first_name();
			$values['{shipping_last_name}']  = (string) $order->get_shipping_last_name();
			$values['{shipping_phone}']      = self::pretty_phone( $order->get_shipping_phone() );
			$values['{shipping_address}']    = self::address( array(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				$order->get_shipping_city(),
				$order->get_shipping_state(),
				$order->get_shipping_postcode(),
			) );

			if ( $first_item ) {
				$values['{product_name}']     = $first_item['name'];
				$values['{product_id}']       = (string) $first_item['product_id'];
				$values['{product_sku}']      = $first_item['sku'];
				$values['{product_quantity}'] = (string) $first_item['quantity'];
				$values['{product_price}']    = $first_item['total'];
			}
		}

		// Vendor context.
		if ( ! empty( $vendor['name'] ) ) {
			$values['{vendor_name}'] = (string) $vendor['name'];
		}
		if ( ! empty( $vendor['phone'] ) ) {
			$values['{vendor_phone}'] = self::pretty_phone( $vendor['phone'] );
		}

		// Recipient context.
		$values['{recipient_phone}'] = self::pretty_phone( $r_phone );
		if ( '' !== $r_name ) {
			$values['{recipient_name}'] = $r_name;
		} elseif ( 'customer' === $r_type ) {
			$values['{recipient_name}'] = $values['{first_name}'];
		} elseif ( 'vendor' === $r_type ) {
			$values['{recipient_name}'] = $values['{vendor_name}'];
		}

		return $values;
	}

	/**
	 * Collect line items from an order without touching post tables.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function order_items( $order ) {
		$items = array();
		$text  = array();

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$variation  = $item->get_variation_id();
			$product    = $product_id ? wc_get_product( $variation ? $variation : $product_id ) : false;

			$row = array(
				'name'       => (string) $item->get_name(),
				'product_id' => $product_id,
				'sku'        => $product ? (string) $product->get_sku() : '',
				'quantity'   => (int) $item->get_quantity(),
				'total'      => wp_strip_all_tags( (string) wc_price( $item->get_total(), array( 'currency' => $order->get_currency() ) ) ),
			);
			$items[] = $row;
			$text[]  = $row['name'] . ' x' . $row['quantity'];
		}

		return array(
			'items' => $items,
			'text'  => implode( ', ', $text ),
		);
	}

	/**
	 * Join address parts for SMS.
	 *
	 * @param array $parts Address parts.
	 * @return string
	 */
	private static function address( $parts ) {
		$parts = array_filter( array_map( 'trim', array_map( 'strval', (array) $parts ) ) );
		return implode( ', ', $parts );
	}

	/**
	 * Show a phone number in international form when possible.
	 *
	 * @param string $phone Raw phone.
	 * @return string
	 */
	private static function pretty_phone( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		$normalized = Barar_Atik_Phone::normalize( $phone );
		return $normalized ? $normalized : $phone;
	}

	/**
	 * Preview a template with a real order.
	 *
	 * @param string $template     Template.
	 * @param int    $order_id     Order ID.
	 * @param string $recipient_type Recipient type.
	 * @return string|WP_Error Rendered message or error.
	 */
	public static function preview_order( $template, $order_id, $recipient_type = 'customer' ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'no_woocommerce', __( 'WooCommerce is not active.', 'barar-atik-sms-otp' ) );
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return new WP_Error( 'no_order', __( 'Order not found.', 'barar-atik-sms-otp' ) );
		}

		$phone = '';
		$name  = '';
		if ( 'customer' === $recipient_type ) {
			$phone = (string) $order->get_billing_phone();
			$name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		} elseif ( 'admin' === $recipient_type ) {
			$phone = Barar_Atik_WooCommerce::admin_phone( false );
			$name  = __( 'Administrator', 'barar-atik-sms-otp' );
		} elseif ( 'vendor' === $recipient_type ) {
			$vendor = Barar_Atik_WooCommerce::first_vendor( $order );
			$phone  = $vendor['phone'];
			$name   = $vendor['name'];
		}

		return self::render(
			$template,
			array(
				'order'          => $order,
				'recipient_type' => $recipient_type,
				'recipient_phone' => Barar_Atik_Phone::normalize( $phone ),
				'recipient_name' => $name,
				'vendor'         => 'vendor' === $recipient_type ? Barar_Atik_WooCommerce::first_vendor( $order ) : array(),
			)
		);
	}

	/**
	 * SMS segmentation info for the live character counter.
	 *
	 * @param string $text Message text.
	 * @return array
	 */
	public static function segments( $text ) {
		$text     = (string) $text;
		$encoding = self::is_gsm7( $text ) ? 'GSM-7' : 'UCS-2';
		$length   = self::strlen_utf8( $text );

		if ( 'GSM-7' === $encoding ) {
			$single = 160;
			$multi  = 153;
		} else {
			$single = 70;
			$multi  = 67;
		}

		$segments = 0;
		if ( $length > 0 ) {
			$segments = ( $length <= $single ) ? 1 : (int) ceil( $length / $multi );
		}

		return array(
			'encoding'     => $encoding,
			'length'       => $length,
			'single_limit' => $single,
			'seg_limit'    => $multi,
			'segments'     => $segments,
		);
	}

	/**
	 * Does the text fit the GSM-7 alphabet (one Unicode char outside switches to UCS-2)?
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	private static function is_gsm7( $text ) {
		// Commonly used GSM-7 mapped characters outside plain ASCII.
		$extras = '€¡£¥¤§¿ÄÖÑÜäöñüàèéùìòÇØøÅåÆæßÉΔΦΓΛΩΠΨΣΘΞ';

		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return false;
		}
		foreach ( $chars as $char ) {
			$len = strlen( $char );
			if ( 1 === $len ) {
				$ord = ord( $char );
				if ( $ord < 32 && "\n" !== $char && "\r" !== $char && "\t" !== $char ) {
					return false;
				}
				if ( $ord > 126 ) {
					return false;
				}
				continue;
			}
			if ( false === strpos( $extras, $char ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * UTF-8 aware string length without requiring mbstring.
	 *
	 * @param string $text Text.
	 * @return int
	 */
	private static function strlen_utf8( $text ) {
		if ( '' === $text ) {
			return 0;
		}
		if ( function_exists( 'mb_strlen' ) ) {
			return (int) mb_strlen( $text, 'UTF-8' );
		}
		$count = preg_match_all( '/./u', $text, $matches );
		return false === $count ? strlen( $text ) : (int) $count;
	}
}
