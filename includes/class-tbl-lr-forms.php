<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Per-form settings, in a "TBL Lead Routing" box on the Terrific Forms edit
 * screen. Stored as post meta on the form (the forms plugin keeps its own data
 * in its own table, so this never touches it).
 */
class TBL_LR_Forms {

	const META  = '_tbl_lr';
	const NONCE = 'tbl_lr_form';

	const DEFAULTS = array(
		'enabled'           => false,
		'mode'              => 'redirect', // redirect | popup | send
		'fallback_calendly' => '',
		'wait_text'         => '',
	);

	public static function init() {
		if ( ! tbl_lr_forms_available() ) { return; }
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . TF_CPT, array( __CLASS__, 'save' ), 30, 1 );
	}

	/** A form's settings, defaults filled in. */
	public static function config( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META, true );
		$cfg = is_array( $raw ) ? $raw : array();
		return wp_parse_args( $cfg, self::DEFAULTS );
	}

	public static function is_enabled( $post_id ) {
		$cfg = self::config( $post_id );
		return ! empty( $cfg['enabled'] );
	}

	/**
	 * "Just send it": the lead goes to the leads app, and the form then behaves
	 * exactly as it always has - no "Please wait", no calendar. For forms that
	 * are not sales leads, such as the travel agents' partner form.
	 */
	public static function sends_only( $cfg ) {
		return isset( $cfg['mode'] ) && 'send' === $cfg['mode'];
	}

	/** Every form with routing on: post id => settings the browser needs. */
	public static function enabled_forms() {
		$ids = get_posts( array(
			'post_type'      => TF_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'meta_key'       => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery -- a handful of forms
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $ids as $id ) {
			$cfg = self::config( $id );
			if ( empty( $cfg['enabled'] ) ) { continue; }
			$out[ (string) $id ] = $cfg;
		}
		return $out;
	}

	public static function meta_box() {
		add_meta_box( 'tbl-lr-box', 'TBL Lead Routing', array( __CLASS__, 'render' ), TF_CPT, 'side', 'default' );
	}

	public static function render( $post ) {
		$cfg = self::config( $post->ID );
		wp_nonce_field( self::NONCE, self::NONCE );
		$webhook = self::active_webhook( $post->ID );
		?>
		<p>
			<label>
				<input type="checkbox" name="tbl_lr[enabled]" value="1" <?php checked( ! empty( $cfg['enabled'] ) ); ?>>
				<strong>Route leads from this form</strong>
			</label><br>
			<span class="description">Sends each submission to the leads app, which picks the travel designer and creates the Zendesk ticket.</span>
		</p>

		<?php if ( $webhook && ! empty( $cfg['enabled'] ) ) : ?>
			<p style="background:#fcf0f1;border-left:3px solid #d63638;padding:6px 8px">
				<strong>This form still sends to a webhook</strong> (<?php echo esc_html( wp_parse_url( $webhook, PHP_URL_HOST ) ?: $webhook ); ?>).
				Turn that Webhook action off, or the lead also reaches Make and gets a second ticket.
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $cfg['enabled'] ) && 'redirect' === $cfg['mode'] && ! self::has_redirect( $post->ID ) ) : ?>
			<p style="background:#fcf9e8;border-left:3px solid #dba617;padding:6px 8px">
				This form has no Redirect action, so visitors stay on the page and the calendar opens in a popup. Add a Redirect action to send them to a thank-you page.
			</p>
		<?php endif; ?>

		<p><strong>After submit</strong></p>
		<p style="margin-top:0">
			<label><input type="radio" name="tbl_lr[mode]" value="redirect" <?php checked( 'redirect' === $cfg['mode'] ); ?>> Go to the thank-you page, with the travel designer's calendar there</label><br>
			<span class="description" style="margin-left:24px;display:block">Uses this form's Redirect action. Put <code>[tbl_calendly]</code> on that page.</span>
			<label><input type="radio" name="tbl_lr[mode]" value="popup" <?php checked( 'popup' === $cfg['mode'] ); ?>> Stay on this page and open the calendar in a popup</label><br>
			<label><input type="radio" name="tbl_lr[mode]" value="send" <?php checked( 'send' === $cfg['mode'] ); ?>> Just send it: no calendar</label>
			<span class="description" style="margin-left:24px;display:block">The form shows its own message or redirect, as without this add-on. For forms that are not sales leads, such as a partner form.</span>
		</p>

		<p>
			<label for="tbl_lr_fallback"><strong>Fallback Calendly link</strong></label>
			<input type="url" id="tbl_lr_fallback" class="widefat" name="tbl_lr[fallback_calendly]" value="<?php echo esc_attr( $cfg['fallback_calendly'] ); ?>" placeholder="<?php echo esc_attr( TBL_LR_Settings::get( 'fallback_calendly' ) ?: 'https://calendly.com/…' ); ?>">
			<span class="description">When no travel designer could be picked. Blank uses the one in Terrific Digital → TBL Lead Routing.</span>
		</p>

		<p>
			<label for="tbl_lr_wait"><strong>"Please wait" text</strong></label>
			<input type="text" id="tbl_lr_wait" class="widefat" name="tbl_lr[wait_text]" value="<?php echo esc_attr( $cfg['wait_text'] ); ?>" placeholder="<?php echo esc_attr( TBL_LR_Settings::get( 'wait_text' ) ); ?>">
		</p>
		<?php
	}

	/** The URL of this form's enabled Webhook action, or ''. */
	public static function active_webhook( $post_id ) {
		foreach ( tf_get_actions( $post_id ) as $action ) {
			if ( ! empty( $action['enabled'] ) && isset( $action['type'] ) && 'webhook' === $action['type'] && function_exists( 'tf_resolve_webhook_url' ) ) {
				return (string) tf_resolve_webhook_url( $action );
			}
		}
		return '';
	}

	/** Whether this form has an enabled Redirect action with a destination. */
	public static function has_redirect( $post_id ) {
		foreach ( tf_get_actions( $post_id ) as $action ) {
			if ( ! empty( $action['enabled'] ) && isset( $action['type'] ) && 'redirect' === $action['type'] ) {
				return function_exists( 'tf_resolve_redirect_url' ) ? '' !== (string) tf_resolve_redirect_url( $action ) : true;
			}
		}
		return false;
	}

	public static function save( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) { return; }
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		$in = isset( $_POST['tbl_lr'] ) && is_array( $_POST['tbl_lr'] ) ? wp_unslash( $_POST['tbl_lr'] ) : array();
		update_post_meta( $post_id, self::META, self::sanitize_config( $in ) );
	}

	/** A form's settings as they are stored (the edit screen and the REST API both save through this). */
	public static function sanitize_config( $in ) {
		return array(
			'enabled'           => ! empty( $in['enabled'] ),
			'mode'              => ( isset( $in['mode'] ) && in_array( $in['mode'], array( 'popup', 'send' ), true ) ) ? $in['mode'] : 'redirect',
			'fallback_calendly' => TBL_LR_Settings::calendly_url( isset( $in['fallback_calendly'] ) ? $in['fallback_calendly'] : '' ),
			'wait_text'         => isset( $in['wait_text'] ) ? sanitize_text_field( $in['wait_text'] ) : '',
		);
	}
}
