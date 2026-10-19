<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * "Open in Leads App" on a Terrific Forms submission (Terrific Digital →
 * Submissions → one submission).
 *
 * When a form is sent, the leads app answers with the lead it made; its id is
 * kept here against the submission. The button opens the leads app's
 * /leads/wordpress, which goes straight to that lead, or finds it by the
 * submission's own link: for submissions sent before this was kept, or when
 * the leads app did not answer in time. Going through that address (rather
 * than the lead's page) also brings someone who still has to sign in back to
 * the lead afterwards.
 *
 * Terrific Digital has no hook on that screen and is not changed: the button
 * is added by a few lines of script, on that screen only.
 */
class TBL_LR_Lead_Links {

	const OPT = 'tbl_lr_lead_links';
	/** Leads kept for the most recent submissions only; older ones are found by their link. */
	const MAX = 3000;

	public static function init() {
		add_action( 'admin_footer', array( __CLASS__, 'button' ) );
	}

	/** Keeps the lead the leads app made from a submission. */
	public static function remember( $sub_id, $answer ) {
		$sub_id  = (int) $sub_id;
		$lead_id = is_array( $answer ) && isset( $answer['lead_id'] ) ? (string) $answer['lead_id'] : '';
		if ( ! $sub_id || ! preg_match( '/^[a-z0-9]{20,40}$/', $lead_id ) ) { return; }

		$links = get_option( self::OPT, array() );
		$links = is_array( $links ) ? $links : array();
		unset( $links[ $sub_id ] ); // newest last, so the oldest are the ones dropped
		$links[ $sub_id ] = $lead_id;
		if ( count( $links ) > self::MAX ) {
			$links = array_slice( $links, -self::MAX, null, true );
		}
		update_option( self::OPT, $links, false );
	}

	/** The lead kept for a submission, or ''. */
	public static function lead_id( $sub_id ) {
		$links = get_option( self::OPT, array() );
		return is_array( $links ) && isset( $links[ (int) $sub_id ] ) ? (string) $links[ (int) $sub_id ] : '';
	}

	/** The leads app address that opens a submission's lead. */
	public static function url( $sub_id ) {
		$args    = array();
		$lead_id = self::lead_id( $sub_id );
		if ( '' !== $lead_id ) { $args['lead'] = $lead_id; }
		$args['entry'] = TBL_LR_Client::submission_link( $sub_id );
		return add_query_arg(
			array_map( 'rawurlencode', $args ),
			untrailingslashit( (string) TBL_LR_Settings::get( 'base_url' ) ) . '/leads/wordpress'
		);
	}

	/**
	 * Whether a submission can have a lead: one was kept for it, or it came
	 * from a routed form and was not flagged as spam (spam fires no actions).
	 */
	protected static function has_lead( $sub_id ) {
		if ( '' !== self::lead_id( $sub_id ) ) { return true; }
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . TF_Submissions::table() . ' WHERE id = %d', $sub_id ) ); // phpcs:ignore
		if ( ! $row || empty( $row->form_post_id ) ) { return false; }
		if ( isset( $row->flag ) && 'spam' === strtolower( (string) $row->flag ) ) { return false; }
		return TBL_LR_Forms::is_enabled( (int) $row->form_post_id );
	}

	/** Adds the button next to "Back to submissions" on a submission's screen. */
	public static function button() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reading the screen's own query args
		if ( ! isset( $_GET['page'], $_GET['view'] ) || 'tf-submissions' !== $_GET['page'] ) { return; }
		$sub_id = absint( $_GET['view'] );
		// phpcs:enable
		if ( ! $sub_id || ! current_user_can( 'edit_pages' ) || ! tbl_lr_forms_available() ) { return; }
		if ( ! TBL_LR_Settings::get( 'base_url' ) || ! self::has_lead( $sub_id ) ) { return; }
		?>
		<script>
		( function () {
			var wrap = document.querySelector( '#wpbody-content .wrap' );
			if ( ! wrap || wrap.querySelector( '.tbl-lr-open-lead' ) ) { return; }
			var link = document.createElement( 'a' );
			link.href = <?php echo wp_json_encode( self::url( $sub_id ) ); ?>;
			link.target = '_blank';
			link.rel = 'noopener';
			link.className = 'page-title-action tbl-lr-open-lead';
			link.title = 'Open the lead this submission became in the leads app';
			// The leads app's own icon (a lead dropping into the inbox), set into the
			// line like text so the button keeps WordPress's own size and alignment.
			link.innerHTML = '<svg viewBox="0 0 64 64" width="14" height="14" aria-hidden="true" style="vertical-align:-2px;margin-right:5px"><rect width="64" height="64" rx="14" fill="#1f56a8"/><path d="M32 10v20M23.5 22l8.5 8.5 8.5-8.5" fill="none" stroke="#fff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/><path d="M11 38h11.5l4 6h11l4-6H53v9a7 7 0 0 1-7 7H18a7 7 0 0 1-7-7Z" fill="#fff"/></svg>';
			link.appendChild( document.createTextNode( 'Open in Leads App' ) );
			// Right after "← Back to submissions" (the first of the screen's buttons).
			var after = wrap.querySelector( 'a.page-title-action' ) || wrap.querySelector( 'h1' );
			if ( ! after ) { return; }
			after.insertAdjacentElement( 'afterend', link );
			// The plugin's own buttons are spaced by the whitespace between them in its markup.
			link.insertAdjacentText( 'beforebegin', ' ' );
		} )();
		</script>
		<?php
	}
}
