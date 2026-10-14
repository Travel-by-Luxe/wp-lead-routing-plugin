<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * The visitor's side: the "Please wait…" overlay and the popup on pages with a
 * routed form, and the [tbl_calendly] / [tbl_travel_designer] shortcodes for
 * the thank-you page.
 */
class TBL_LR_Frontend {

	const HANDLE = 'tbl-lead-routing';

	protected static $configured = false;

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_shortcode( 'tbl_calendly', array( __CLASS__, 'calendly_shortcode' ) );
		add_shortcode( 'tbl_travel_designer', array( __CLASS__, 'travel_designer_shortcode' ) );
	}

	protected static function register() {
		if ( wp_script_is( self::HANDLE, 'registered' ) ) { return; }
		wp_register_script( self::HANDLE, TBL_LR_URL . 'assets/tbl-lead-routing.js', array(), TBL_LR_VERSION, true );
		wp_register_style( self::HANDLE, TBL_LR_URL . 'assets/tbl-lead-routing.css', array(), TBL_LR_VERSION );
	}

	/** The script and its settings: which forms are routed, and how. */
	protected static function load() {
		self::register();
		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );
		if ( self::$configured ) { return; }
		self::$configured = true;

		$forms = array();
		if ( tbl_lr_forms_available() ) {
			foreach ( TBL_LR_Forms::enabled_forms() as $id => $cfg ) {
				// Nothing for the browser to do on a "Just send it" form.
				if ( TBL_LR_Forms::sends_only( $cfg ) ) { continue; }
				$forms[ $id ] = array(
					'mode'      => $cfg['mode'],
					'wait_text' => $cfg['wait_text'],
				);
			}
		}
		wp_localize_script( self::HANDLE, 'TBL_LR', array(
			'forms'    => (object) $forms,
			'waitText' => (string) TBL_LR_Settings::get( 'wait_text' ),
			'fallback' => (string) TBL_LR_Settings::get( 'fallback_calendly' ),
			'theme'       => (object) TBL_LR_Settings::calendly_theme(),
			'thanksAfter' => (string) TBL_LR_Settings::get( 'thanks_after' ),
			'booking'     => TBL_LR_Settings::booking_rule(),
		) );
	}

	/**
	 * Loaded on every front-end page while any form is routed: forms can sit in
	 * templates, blocks or page builders, so "is there a routed form here?" is
	 * only knowable in the browser. The script is ~3 KB and does nothing on a
	 * page without one.
	 */
	public static function enqueue() {
		if ( is_admin() || ! tbl_lr_forms_available() ) { return; }
		// The booking rule judges every form, routed or not, so it needs the script too.
		if ( ! TBL_LR_Forms::enabled_forms() && ! TBL_LR_Settings::booking_rule() ) { return; }
		self::load();
	}

	/** [tbl_calendly height="700" fallback="https://calendly.com/…"] - the routed travel designer's calendar. */
	public static function calendly_shortcode( $atts ) {
		$atts     = shortcode_atts( array( 'height' => '700', 'fallback' => '' ), $atts, 'tbl_calendly' );
		$fallback = TBL_LR_Settings::calendly_url( $atts['fallback'] );
		if ( '' === $fallback ) { $fallback = (string) TBL_LR_Settings::get( 'fallback_calendly' ); }
		self::load();
		return sprintf(
			'<div class="tbl-calendly" data-fallback="%1$s" style="min-width:320px;height:%2$dpx"></div>',
			esc_url( $fallback ),
			max( 400, (int) $atts['height'] )
		);
	}

	/** [tbl_travel_designer fallback="our team"] - the routed travel designer's first name. */
	public static function travel_designer_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'fallback' => 'our team' ), $atts, 'tbl_travel_designer' );
		self::load();
		return '<span class="tbl-travel-designer">' . esc_html( $atts['fallback'] ) . '</span>';
	}
}
