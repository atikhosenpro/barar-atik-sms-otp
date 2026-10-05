<?php
/**
 * SMS send pipeline: validate, log, send through TextBee, track status,
 * and schedule safe, bounded retries. Never throws into WooCommerce flows.
 *
 * @package Barar_Atik
 */

defined( 'ABSPATH' ) || exit;

class Barar_Atik_SMS {

	/**
	 * TextBee client.
	 *
	 * @var Barar_Atik_TextBee
	 */
	private $client;

	/**
	 * Message log.
	 *
	 * @var Barar_Atik_Message_Log
	 */
	private $log;

	/**
	 * Wire dependencies.
	 *
	 * @param Barar_Atik_TextBee      $client API client.
	 * @param Barar_Atik_Message_Log  $log    Message log.
	 */
	public function __construct( $client, $log ) {
		$this->client = $client;
		$this->log    = $log;
	}

	/**
	 * Send a message.
	 *
	 * @param array $args {
	 *     @type string $recipient       Raw or normalized phone number.
	 *     @type string $message         Message text.
	 *     @type string $source          wc_order|otp|test|manual|sync.
	 *     @type string $event           Event slug (new_order, otp_login, ...).
	 *     @type string $ref_type        order|user|none.
	 *     @type int    $ref_id          Related object ID.
	 *     @type string $dedup           Dedup key for automated sends.
	 *     @type bool   $retry           Allow bounded retries.
	 *     @type string $redact          Text to hide from the stored copy.
	 * }
	 * @return array|WP_Error
	 */
	public function send( $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'recipient' => '',
				'message'   => '',
				'source'    => 'system',
				'event'     => '',
				'ref_type'  => '',
				'ref_id'    => 0,
				'dedup'     => '',
				'retry'     => false,
				'redact'    => '',
			)
		);

		$phone = Barar_Atik_Phone::normalize( $args['recipient'] );
		if ( '' === $phone ) {
			return new WP_Error( 'invalid_phone', __( 'The phone number is missing or invalid.', 'barar-atik-sms-otp' ) );
		}

		$message = trim( Barar_Atik_Settings::plain( $args['message'] ) );
		if ( '' === $message ) {
			return new WP_Error( 'empty_message', __( 'The message is empty.', 'barar-atik-sms-otp' ) );
		}

		// Never store a code in the local log.
		$stored_message = $message;
		if ( '' !== (string) $args['redact'] ) {
			$stored_message = str_replace( (string) $args['redact'], str_repeat( '*', 6 ), $stored_message );
		}

		$dedup = (string) $args['dedup'];

		$log_id = $this->log->insert(
			array(
				'dedup_key'    => '' !== $dedup ? substr( $dedup, 0, 64 ) : null,
				'direction'    => 'sent',
				'status'       => 'pending',
				'source'       => sanitize_key( $args['source'] ),
				'event'        => sanitize_key( $args['event'] ),
				'ref_type'     => sanitize_key( $args['ref_type'] ),
				'ref_id'       => (int) $args['ref_id'],
				'recipient'    => $phone,
				'message'      => $stored_message,
				'requested_at' => current_time( 'mysql' ),
			)
		);

		if ( 0 === $log_id ) {
			// Duplicate: the notification already exists for this context.
			$existing = '' !== $dedup ? $this->log->by_dedup( $dedup ) : null;
			if ( $existing ) {
				return array(
					'ok'        => true,
					'duplicate' => true,
					'queued'    => false,
					'log_id'    => (int) $existing['id'],
					'batch_id'  => (string) $existing['sms_batch_id'],
					'error'     => '',
					'status'    => 200,
				);
			}
			// Insert failed and nothing was found: the log table is not writable.
			return new WP_Error( 'log_failed', __( 'The message log could not be written to.', 'barar-atik-sms-otp' ) );
		}

		return $this->dispatch( $log_id, $phone, $message, (bool) $args['retry'] );
	}

	/**
	 * Push an existing log row to TextBee and store the outcome.
	 *
	 * @param int    $log_id   Log row ID.
	 * @param string $phone    Normalized recipient.
	 * @param string $message  Message text.
	 * @param bool   $retryable Whether retries may be scheduled after a failure.
	 * @return array
	 */
	private function dispatch( $log_id, $phone, $message, $retryable ) {
		$row     = $this->log->get( $log_id );
		$attempt = $row ? ( (int) $row['attempts'] ) + 1 : 1;

		$res = $this->client->send_sms( $phone, $message );

		$out = array(
			'ok'        => false,
			'duplicate' => false,
			'queued'    => false,
			'log_id'    => $log_id,
			'batch_id'  => '',
			'error'     => '',
			'status'    => (int) $res['status'],
		);

		if ( ! empty( $res['queued'] ) ) {
			$this->log->update(
				$log_id,
				array(
					'status'       => 'pending',
					'sms_batch_id' => (string) $res['batch_id'],
					'attempts'     => $attempt,
					'error'        => '',
				)
			);
			Barar_Atik_Stats::inc( 'sms_queued' );
			$out['ok']       = true;
			$out['queued']   = true;
			$out['batch_id'] = (string) $res['batch_id'];
			return $out;
		}

		$error = (string) ( ! empty( $res['error'] ) ? $res['error'] : __( 'Unknown TextBee error.', 'barar-atik-sms-otp' ) );
		$this->log->update(
			$log_id,
			array(
				'status'    => 'failed',
				'attempts'  => $attempt,
				'error'     => $error,
				'failed_at' => current_time( 'mysql' ),
			)
		);
		Barar_Atik_Stats::inc( 'sms_failed' );

		$out['error'] = $error;
		if ( $retryable && ! empty( $res['retryable'] ) ) {
			$this->schedule_retry( $log_id, $attempt );
		}

		return $out;
	}

	/**
	 * Schedule one bounded retry (Action Scheduler when available, else WP-Cron).
	 *
	 * The retry updates the SAME row, so a retry can never duplicate a message.
	 *
	 * @param int $log_id   Row ID.
	 * @param int $attempt  Attempts made so far.
	 * @return void
	 */
	private function schedule_retry( $log_id, $attempt ) {
		if ( '1' !== Barar_Atik_Settings::get( 'retry_enabled', '1' ) ) {
			return;
		}
		$max = (int) Barar_Atik_Settings::get( 'retry_count', '2' );
		if ( $attempt > $max ) {
			return;
		}

		$delay = 1 === $attempt ? 120 : ( 600 * ( $attempt - 1 ) );
		$hook  = 'barar_atik_retry';

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, $hook, array( $log_id ), 'barar-atik-sms-otp' );
		} else {
			wp_schedule_single_event( time() + $delay, $hook, array( $log_id ) );
		}
	}

	/**
	 * Retry handler (hooked to barar_atik_retry).
	 *
	 * @param int $log_id Row ID.
	 * @return void
	 */
	public function run_retry( $log_id ) {
		$row = $this->log->get( (int) $log_id );
		if ( ! $row ) {
			return;
		}
		// Only automated order messages retry, and only while still failed.
		if ( 'failed' !== $row['status'] || 'wc_order' !== $row['source'] ) {
			return;
		}
		$max = (int) Barar_Atik_Settings::get( 'retry_count', '2' );
		if ( (int) $row['attempts'] > $max ) {
			return;
		}
		if ( ! Barar_Atik_Phone::is_valid( $row['recipient'] ) ) {
			return;
		}

		$this->dispatch( (int) $row['id'], $row['recipient'], (string) $row['message'], true );
	}

	/**
	 * Convenience helper for the admin Test SMS tool.
	 *
	 * @param string $phone   Recipient.
	 * @param string $message Message.
	 * @return array|WP_Error
	 */
	public function send_test( $phone, $message ) {
		return $this->send(
			array(
				'recipient' => $phone,
				'message'   => $message,
				'source'    => 'test',
				'event'     => 'test',
			)
		);
	}
}
