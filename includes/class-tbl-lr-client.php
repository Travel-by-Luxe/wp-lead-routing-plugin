<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Talks to the leads app. Submissions are sent in the forms plugin's own
 * webhook format (its PROJECT.md "Webhook payload"), so every Travel by Luxe
 * field mapping lives in the leads app, not here.
 */
class TBL_LR_Client {

	const PATH     = '/api/v1/leads/terrific-forms';
	const LAST_OPT = 'tbl_lr_last';

	/**
	 * The forms plugin's webhook payload, rebuilt from what its
	 * terrific_forms/submission and terrific_forms/step hooks receive.
	 * $step = null | array( current, max, final ).
	 */
	public static function payload( $event, $fields, $def, $post_id, $ctx, $sub_id, $step = null ) {
		$submission = array(
			'id'        => $sub_id ? (int) $sub_id : null,
			'timestamp' => gmdate( 'c' ),
		);
		if ( $sub_id ) {
			$submission['submission_link'] = is_callable( array( 'TF_Submissions', 'page_url' ) )
				? TF_Submissions::page_url( array( 'view' => (int) $sub_id ) )
				: add_query_arg( array( 'page' => 'tf-submissions', 'view' => (int) $sub_id ), admin_url( 'admin.php' ) );
		}
		$submission['page_url']   = isset( $ctx['page_url'] ) ? $ctx['page_url'] : '';
		$submission['page_title'] = isset( $ctx['page_title'] ) ? $ctx['page_title'] : '';
		$submission['page_ref']   = isset( $ctx['page_ref'] ) ? $ctx['page_ref'] : '';
		if ( is_array( $step ) ) {
			$submission['current_step'] = (int) $step['current'];
			$submission['max_step']     = (int) $step['max'];
			$submission['final_step']   = (bool) $step['final'];
		}

		$payload = array(
			'event'      => $event,
			'data'       => $fields,
			'form'       => array(
				'id'     => isset( $def['form_id'] ) ? $def['form_id'] : '',
				'name'   => isset( $def['form_name'] ) ? $def['form_name'] : '',
				'intent' => isset( $ctx['intent'] ) ? $ctx['intent'] : '',
				'wp_id'  => (int) $post_id,
			),
			'submission' => $submission,
			'device'     => isset( $ctx['device'] ) ? $ctx['device'] : array(),
			'cookies'    => isset( $ctx['cookies'] ) ? $ctx['cookies'] : array(),
		);
		if ( ! empty( $ctx['session'] ) ) { $payload['session'] = $ctx['session']; }
		return $payload;
	}

	protected static function request_args( $body, $timeout, $blocking ) {
		return array(
			'timeout'  => $timeout,
			'blocking' => $blocking,
			'headers'  => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
				'X-API-Key'    => (string) TBL_LR_Settings::get( 'api_key' ),
				'User-Agent'   => 'TBL-Lead-Routing/' . TBL_LR_VERSION . '; ' . home_url( '/' ),
			),
			'body'     => null === $body ? null : wp_json_encode( $body ),
		);
	}

	protected static function endpoint( $path ) {
		return untrailingslashit( (string) TBL_LR_Settings::get( 'base_url' ) ) . $path;
	}

	/** A multi-step form's step: fire-and-forget, like the forms plugin's own webhooks. */
	public static function send_step( $payload ) {
		if ( ! TBL_LR_Settings::get( 'api_key' ) ) { return; }
		wp_remote_post( self::endpoint( self::PATH ), self::request_args( $payload, 5, false ) );
	}

	/**
	 * The final submission: waits for the leads app's answer (the visitor sees
	 * "Please wait…" meanwhile). Returns the decoded answer, or null on any
	 * failure - the caller then falls back to the fallback calendar.
	 */
	public static function send_submission( $payload ) {
		if ( ! TBL_LR_Settings::get( 'api_key' ) ) {
			self::remember( false, 0, 'No API key in Settings → TBL Lead Routing.' );
			return null;
		}
		$started  = microtime( true );
		$response = wp_remote_post( self::endpoint( self::PATH ), self::request_args( $payload, (int) TBL_LR_Settings::get( 'timeout' ), true ) );
		$seconds  = microtime( true ) - $started;

		if ( is_wp_error( $response ) ) {
			self::remember( false, $seconds, 'Could not reach the leads app: ' . $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			$why = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
			self::remember( false, $seconds, 'The leads app refused the lead: ' . $why );
			return null;
		}

		$td = isset( $body['travel_designer'] ) && is_array( $body['travel_designer'] ) ? $body['travel_designer'] : null;
		self::remember(
			true,
			$seconds,
			$td
				? sprintf( '%s takes the lead (%s)', isset( $td['name'] ) ? $td['name'] : '?', isset( $body['status'] ) ? $body['status'] : '' )
				: ( isset( $body['reason'] ) ? $body['reason'] : 'Answered without a travel designer.' )
		);
		return $body;
	}

	/** "Test connection" on the settings screen. */
	public static function check_key() {
		if ( ! TBL_LR_Settings::get( 'api_key' ) ) {
			return array( 'ok' => false, 'message' => 'Save an API key first.' );
		}
		$response = wp_remote_get( self::endpoint( '/api/v1/auth-check' ), self::request_args( null, 10, true ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'message' => 'Could not reach the leads app: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && ! empty( $body['ok'] ) ) {
			return array( 'ok' => true, 'message' => 'Connected. The leads app accepted the key "' . ( isset( $body['key']['name'] ) ? $body['key']['name'] : '' ) . '".' );
		}
		$why = is_array( $body ) && isset( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
		return array( 'ok' => false, 'message' => 'The leads app said: ' . $why );
	}

	/** Kept for the settings screen's "Last lead sent" line. */
	protected static function remember( $ok, $seconds, $message ) {
		update_option( self::LAST_OPT, array(
			'time'    => time(),
			'ok'      => (bool) $ok,
			'seconds' => round( (float) $seconds, 2 ),
			'message' => (string) $message,
		), false );
	}
}
