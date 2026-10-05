<?php
/**
 * Secure TextBee webhook endpoint for delivery status updates.
 *
 * Follows https://textbee.dev/docs/webhooks :
 *  - POST body is signed with HMAC-SHA256 (lowercase hex) in X-Signature;
 *  - deliveries are deduplicated by idempotencyKey;
 *  - status never moves backwards on a local row.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_Webhook {

	/**
	 * Events TextBee emits today.
	 *
	 * @var array
	 */
	private $known_events = array(
		'MESSAGE_RECEIVED',
		'MESSAGE_SENT',
		'MESSAGE_DELIVERED',
		'MESSAGE_FAILED',
		'UNKNOWN_STATE',
	);

	/**
	 * Message log.
	 *
	 * @var Barar_Atik_Message_Log
	 */
	private $log;

	/**
	 * Dependencies.
	 *
	 * @param Barar_Atik_Message_Log $log Message log.
	 */
	public function __construct( $log ) {
		$this->log = $log;
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Public endpoint URL for the TextBee dashboard / API.
	 *
	 * @return string
	 */
	public static function url() {
		return rest_url( 'barar-atik/v1/webhook' );
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function routes() {
		register_rest_route(
			'barar-atik/v1',
			'/webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an incoming delivery.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( $request ) {
		$enabled = '1' === Barar_Atik_Settings::get( 'webhook_enabled', '0' );
		$secret  = (string) Barar_Atik_Settings::get( 'webhook_secret', '' );

		if ( ! $enabled || '' === $secret ) {
			return new WP_Error( 'not_found', 'Not found', array( 'status' => 404 ) );
		}

		$raw       = (string) $request->get_body();
		$signature = strtolower( trim( (string) $request->get_header( 'x-signature' ) ) );

		if ( '' === $signature || ! hash_equals( strtolower( hash_hmac( 'sha256', $raw, $secret ) ), $signature ) ) {
			return new WP_Error( 'bad_signature', 'Invalid signature', array( 'status' => 401 ) );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'bad_json', 'Invalid payload', array( 'status' => 400 ) );
		}

		$event = isset( $payload['webhookEvent'] ) ? (string) $payload['webhookEvent'] : '';
		$sms_id = isset( $payload['smsId'] ) ? sanitize_text_field( (string) $payload['smsId'] ) : '';

		if ( ! in_array( $event, $this->known_events, true ) || '' === $sms_id ) {
			// Acknowledge anything we do not handle so TextBee stops retrying.
			return new WP_REST_Response( array( 'ok' => true, 'ignored' => true ), 200 );
		}

		// Idempotency: every retry of one delivery repeats this key.
		$idem = isset( $payload['idempotencyKey'] ) ? (string) $payload['idempotencyKey'] : '';
		if ( '' !== $idem ) {
			$ikey = 'barar_atik_wk_' . substr( hash( 'sha256', $idem ), 0, 32 );
			if ( get_transient( $ikey ) ) {
				return new WP_REST_Response( array( 'ok' => true, 'duplicate' => true ), 200 );
			}
			set_transient( $ikey, 1, 5 * DAY_IN_SECONDS );
		}

		if ( 'MESSAGE_RECEIVED' === $event ) {
			$this->log->merge_remote(
				array(
					'smsId'      => $sms_id,
					'message'    => isset( $payload['message'] ) ? (string) $payload['message'] : '',
					'sender'     => isset( $payload['sender'] ) ? (string) $payload['sender'] : '',
					'direction'  => 'received',
					'status'     => 'received',
					'receivedAt' => isset( $payload['receivedAt'] ) ? (string) $payload['receivedAt'] : '',
				),
				array(
					'source' => 'webhook',
				)
			);
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$status_map = array(
			'MESSAGE_SENT'      => 'sent',
			'MESSAGE_DELIVERED' => 'delivered',
			'MESSAGE_FAILED'    => 'failed',
			'UNKNOWN_STATE'     => 'unknown',
		);

		$status = isset( $status_map[ $event ] ) ? $status_map[ $event ] : 'unknown';

		$remote = array(
			'smsId'       => $sms_id,
			'direction'   => 'sent',
			'status'      => $status,
			'recipient'   => isset( $payload['recipient'] ) ? (string) $payload['recipient'] : '',
			'smsBatch'    => isset( $payload['smsBatchId'] ) ? (string) $payload['smsBatchId'] : '',
			'sentAt'      => isset( $payload['sentAt'] ) ? (string) $payload['sentAt'] : '',
			'deliveredAt' => isset( $payload['deliveredAt'] ) ? (string) $payload['deliveredAt'] : '',
			'failedAt'    => isset( $payload['failedAt'] ) ? (string) $payload['failedAt'] : '',
		);

		if ( isset( $payload['errorMessage'] ) ) {
			$remote['errorMessage'] = (string) $payload['errorMessage'];
		}
		if ( isset( $payload['errorCode'] ) ) {
			$remote['errorCode'] = (string) $payload['errorCode'];
		}

		// Never write the event's message text: it may contain an OTP.
		$this->log->merge_remote(
			$remote,
			array(
				'source'        => 'webhook',
				'store_message' => false,
			)
		);

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
