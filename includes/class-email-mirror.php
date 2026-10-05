<?php
/**
 * Email → SMS mirror: send an SMS whenever WooCommerce emails the customer.
 *
 * The existing order automations (class-woocommerce.php) already cover order
 * status changes; this component covers everything else WooCommerce mails out
 * (invoices, notes, refunds, ...) and is opt-in.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Email_Mirror {

	/**
	 * Hook the mirror.
	 */
	public function __construct() {
		add_action( 'woocommerce_email_sent', array( $this, 'mirror' ), 10, 3 );
		// WooCommerce ≥ 10.9 skips the mail entirely when the order has no
		// email address; the SMS is then the only notification the customer
		// gets, so the skipped outcome has to be mirrored as well.
		add_action( 'woocommerce_email_skipped', array( $this, 'mirror_skipped' ), 10, 3 );
	}

	/**
	 * Mirror one WooCommerce email to the customer's phone number.
	 *
	 * The success flag is deliberately ignored: a mail that could not be
	 * delivered still deserves its SMS copy.
	 *
	 * @param bool     $sent     Whether wp_mail() accepted the message.
	 * @param string   $email_id WooCommerce email id (customer_new_order, ...).
	 * @param WC_Email $email    Email object.
	 * @return void
	 */
	public function mirror( $sent, $email_id, $email ) {
		$this->send_mirror( $email );
	}

	/**
	 * Mirror an email that WooCommerce did not attempt to send.
	 *
	 * Only "no recipient address" is mirrored — an admin switching an email
	 * off stays off.
	 *
	 * @param string   $reason   Skip reason (WC_Email::SKIP_REASON_NO_RECIPIENT).
	 * @param string   $email_id WooCommerce email id.
	 * @param WC_Email $email    Email object.
	 * @return void
	 */
	public function mirror_skipped( $reason, $email_id, $email ) {
		if ( class_exists( 'WC_Email' ) && defined( 'WC_Email::SKIP_REASON_NO_RECIPIENT' ) ) {
			if ( WC_Email::SKIP_REASON_NO_RECIPIENT !== $reason ) {
				return;
			}
		} elseif ( 'no_recipient' !== (string) $reason ) {
			return;
		}
		$this->send_mirror( $email );
	}

	/**
	 * Build and send the mirrored SMS.
	 *
	 * @param WC_Email $email Email object.
	 * @return void
	 */
	private function send_mirror( $email ) {
		if ( '1' !== Barar_Atik_Settings::get( 'email_to_sms', '0' ) ) {
			return;
		}
		if ( ! is_object( $email ) || ! isset( $email->id ) ) {
			return;
		}

		$types = (array) Barar_Atik_Settings::get( 'email_to_sms_types', array() );
		if ( ! in_array( (string) $email->id, $types, true ) ) {
			return;
		}

		$order = $this->order_of( $email );
		if ( ! $order ) {
			return;
		}

		// Admin-facing notifications (new order, failed order, …) address the
		// shop owner and must never reach the customer's phone.
		$recipient = method_exists( $email, 'get_recipient' ) ? trim( (string) $email->get_recipient() ) : '';
		if ( '' !== $recipient && 0 !== strcasecmp( $recipient, trim( (string) $order->get_billing_email() ) ) ) {
			return;
		}

		$phone = $this->customer_phone( $order );
		if ( '' === $phone ) {
			return;
		}

		$subject = method_exists( $email, 'get_subject' ) ? (string) $email->get_subject() : '';
		$tpl     = (string) Barar_Atik_Settings::get(
			'email_to_sms_tpl',
			__( '{email_subject}. Order {order_number}.', 'barar-atik-sms-otp' )
		);
		$tpl     = str_ireplace( '{email_subject}', $subject, $tpl );

		$message = Barar_Atik_Macros::render(
			$tpl,
			array(
				'order'           => $order,
				'recipient_type'  => 'customer',
				'recipient_phone' => $phone,
				'recipient_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			)
		);

		if ( '' === trim( (string) $message ) ) {
			return;
		}

		$sms = Barar_Atik_Plugin::instance()->sms;
		if ( ! $sms ) {
			return;
		}

		$sms->send(
			array(
				'recipient' => $phone,
				'message'   => $message,
				'source'    => 'wc_order',
				'event'     => sanitize_key( (string) $email->id ),
				'ref_type'  => 'order',
				'ref_id'    => $order->get_id(),
				'dedup'     => 'email:' . $order->get_id() . ':' . sanitize_key( (string) $email->id ),
				'retry'     => true,
			)
		);
	}

	/**
	 * The order (or refund) attached to an email object.
	 *
	 * @param object $email Email object.
	 * @return WC_Order|null
	 */
	private function order_of( $email ) {
		$object = isset( $email->object ) ? $email->object : null;
		if ( is_a( $object, 'WC_Order' ) ) {
			return $object;
		}
		if ( is_a( $object, 'WC_Order_Refund' ) && method_exists( $object, 'get_order' ) ) {
			$order = $object->get_order();
			return is_a( $order, 'WC_Order' ) ? $order : null;
		}
		return null;
	}

	/**
	 * Customer phone for an order: billing phone first, then the account.
	 *
	 * @param WC_Order $order Order.
	 * @return string Normalized number ('' when unknown).
	 */
	private function customer_phone( $order ) {
		$phone = Barar_Atik_Phone::normalize( (string) $order->get_billing_phone() );
		if ( '' !== $phone ) {
			return $phone;
		}

		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id ) {
			foreach ( Barar_Atik_Phone::meta_keys() as $key ) {
				$value = get_user_meta( $customer_id, $key, true );
				$phone = Barar_Atik_Phone::normalize( is_scalar( $value ) ? (string) $value : '' );
				if ( '' !== $phone ) {
					return $phone;
				}
			}
		}

		return '';
	}
}
