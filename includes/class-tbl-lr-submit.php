<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Hooks into Terrific Forms' submit flow without changing that plugin.
 *
 * Terrific Forms submits over admin-ajax (tf_submit / tf_step) and fires
 * `terrific_forms/submission` / `terrific_forms/step` before it answers the
 * browser. On a routed form:
 *
 *  - each step is forwarded to the leads app (fire-and-forget), so abandoned
 *    multi-step forms still become tickets;
 *  - the final submission is sent and the answer awaited - the visitor sees
 *    "Please wait…" meanwhile - and the chosen travel designer is added to the
 *    forms plugin's JSON reply, via an output buffer, as `data.tbl_routing`.
 *    The calendar also goes onto the thank-you redirect URL (`tbl_cal`,
 *    `tbl_td`), so redirect mode works even if the browser script cannot run.
 *
 * A "Just send it" form is sent the same way, but its reply is left alone, so
 * the form behaves as it does without the add-on.
 */
class TBL_LR_Submit {

	/** What this request's reply should carry, once known. */
	protected static $routing = null;

	public static function init() {
		if ( ! tbl_lr_forms_available() ) { return; }
		add_action( 'terrific_forms/step', array( __CLASS__, 'on_step' ), 10, 6 );
		add_action( 'terrific_forms/submission', array( __CLASS__, 'on_submission' ), 10, 5 );
	}

	public static function on_step( $fields, $def, $post_id, $sub_id, $ctx, $step ) {
		if ( ! TBL_LR_Forms::is_enabled( $post_id ) ) { return; }
		$total = isset( $def['steps'] ) ? count( $def['steps'] ) : 0;
		TBL_LR_Client::send_step( TBL_LR_Client::payload( 'form_step', $fields, $def, $post_id, $ctx, $sub_id, array(
			'current' => (int) $step,
			'max'     => $total,
			'final'   => (int) $step >= $total,
		) ) );
	}

	public static function on_submission( $fields, $def, $post_id, $sub_id, $ctx ) {
		if ( ! TBL_LR_Forms::is_enabled( $post_id ) || ! wp_doing_ajax() ) { return; }
		$cfg   = TBL_LR_Forms::config( $post_id );
		$total = isset( $def['steps'] ) ? count( $def['steps'] ) : 0;
		$multi = isset( $def['type'] ) && 'multistep' === $def['type'] && $total > 1;

		$answer = TBL_LR_Client::send_submission( TBL_LR_Client::payload(
			'form_submission',
			$fields,
			$def,
			$post_id,
			$ctx,
			$sub_id,
			$multi ? array( 'current' => $total, 'max' => $total, 'final' => true ) : null
		) );
		// For the submission's "Open in Leads App" button.
		TBL_LR_Lead_Links::remember( $sub_id, $answer );

		if ( TBL_LR_Forms::sends_only( $cfg ) ) { return; }

		self::$routing = self::routing( $answer, $cfg, $fields );
		// Terrific Forms answers with wp_send_json_success() right after this
		// hook; the buffer lets us add to that reply before it leaves.
		ob_start( array( __CLASS__, 'rewrite_reply' ) );
	}

	/** The browser's instructions: which calendar, for whom, and how to show it. */
	protected static function routing( $answer, $cfg, $fields ) {
		$td       = ( is_array( $answer ) && isset( $answer['travel_designer'] ) && is_array( $answer['travel_designer'] ) ) ? $answer['travel_designer'] : null;
		$calendly = $td ? TBL_LR_Settings::calendly_url( isset( $td['calendly_url'] ) ? $td['calendly_url'] : '' ) : '';
		$fallback = $cfg['fallback_calendly'] ? $cfg['fallback_calendly'] : (string) TBL_LR_Settings::get( 'fallback_calendly' );
		$prefill  = ( is_array( $answer ) && isset( $answer['prefill'] ) && is_array( $answer['prefill'] ) ) ? $answer['prefill'] : self::guess_prefill( $fields );

		return array(
			'mode'          => 'popup' === $cfg['mode'] ? 'popup' : 'redirect',
			'calendly_url'  => '' !== $calendly ? $calendly : $fallback,
			'fallback'      => '' === $calendly,
			'td_name'       => $td && isset( $td['name'] ) ? (string) $td['name'] : '',
			'td_first_name' => $td && isset( $td['first_name'] ) ? (string) $td['first_name'] : '',
			'prefill'       => array(
				'name'  => isset( $prefill['name'] ) ? (string) $prefill['name'] : '',
				'email' => isset( $prefill['email'] ) ? (string) $prefill['email'] : '',
			),
			'lead_id'       => is_array( $answer ) && isset( $answer['lead_id'] ) ? (string) $answer['lead_id'] : '',
		);
	}

	/** Name and email straight from the form, for when the leads app did not answer. */
	protected static function guess_prefill( $fields ) {
		$email = '';
		foreach ( (array) $fields as $value ) {
			if ( is_string( $value ) && is_email( $value ) ) { $email = $value; break; }
		}
		$name = '';
		foreach ( array( 'full_name', 'name' ) as $key ) {
			if ( ! empty( $fields[ $key ] ) && is_string( $fields[ $key ] ) ) { $name = $fields[ $key ]; break; }
		}
		if ( '' === $name ) {
			$name = trim( ( isset( $fields['first_name'] ) ? $fields['first_name'] : '' ) . ' ' . ( isset( $fields['last_name'] ) ? $fields['last_name'] : '' ) );
		}
		return array( 'name' => $name, 'email' => $email );
	}

	/**
	 * Output-buffer callback: adds `tbl_routing` to Terrific Forms' JSON reply
	 * and the calendar to its thank-you redirect. Anything unexpected passes
	 * through untouched, so the form always behaves at least as it did before.
	 */
	public static function rewrite_reply( $buffer ) {
		$routing = self::$routing;
		if ( ! $routing || '' === $buffer ) { return $buffer; }
		$json = json_decode( $buffer, true );
		if ( ! is_array( $json ) || empty( $json['success'] ) || ! isset( $json['data'] ) || ! is_array( $json['data'] ) ) {
			return $buffer;
		}
		self::$routing = null; // once only

		if ( ! empty( $json['data']['redirect'] ) && '' !== $routing['calendly_url'] ) {
			$json['data']['redirect'] = add_query_arg(
				array_filter( array(
					'tbl_cal' => rawurlencode( $routing['calendly_url'] ),
					'tbl_td'  => '' !== $routing['td_first_name'] ? rawurlencode( $routing['td_first_name'] ) : null,
				) ),
				$json['data']['redirect']
			);
		}
		$json['data']['tbl_routing'] = $routing;

		$out = wp_json_encode( $json );
		return false === $out ? $buffer : $out;
	}
}
