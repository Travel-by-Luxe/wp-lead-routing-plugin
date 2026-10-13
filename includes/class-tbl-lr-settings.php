<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Terrific Digital → TBL Lead Routing: where the leads app is, its API key, how
 * long a visitor may wait, and the defaults each form can override.
 */
class TBL_LR_Settings {

	const OPT   = 'tbl_lr_settings';
	const GROUP = 'tbl_lr_group';
	const SLUG  = 'tbl-lead-routing';
	const CAP   = 'edit_pages'; // matches Terrific Digital (see its PROJECT.md: manage_options is denied on the LP site)

	const DEFAULTS = array(
		'base_url'          => 'https://leads.travelbyluxe.com',
		'api_key'           => '',
		'timeout'           => 8,
		'fallback_calendly' => '',
		'wait_text'         => 'Finding your travel designer…',
		// The calendar's look, as on the site's thank-you page (hex, no #).
		'calendly_background'         => 'fdfbf9',
		'calendly_text'               => '21313b',
		'calendly_primary'            => '2fa3a3',
		'calendly_hide_cookie_banner' => 1,
		// A thank-you page the site builds itself (no [tbl_calendly] on it): the calendar
		// goes in its #calendly_widget, or in a section after this element. Blank: off.
		'thanks_after'                => '.ty-next-steps',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		// Before WordPress refuses the old address (it checks access right after admin_menu).
		add_action( 'admin_menu', array( __CLASS__, 'redirect_old_url' ), 1 );
		add_action( 'admin_post_tbl_lr_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	/**
	 * The screen sits in the Terrific Digital menu, next to Forms; under Settings
	 * only when that plugin is inactive, so its warning can still be read.
	 */
	protected static function parent() {
		return defined( 'TD_MENU' ) ? TD_MENU : 'options-general.php';
	}

	public static function url( $args = array() ) {
		$base = 'options-general.php' === self::parent() ? 'options-general.php' : 'admin.php';
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( $base ) );
	}

	/** 1.0.0 had the screen under Settings; send old links and bookmarks to the new place. */
	public static function redirect_old_url() {
		global $pagenow;
		if ( 'options-general.php' !== $pagenow || 'options-general.php' === self::parent() ) { return; }
		if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) { return; }
		$args = array_map( 'sanitize_text_field', wp_unslash( $_GET ) );
		unset( $args['page'] );
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public static function all() {
		$saved = get_option( self::OPT, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function menu() {
		add_submenu_page( self::parent(), 'TBL Lead Routing', 'TBL Lead Routing', self::CAP, self::SLUG, array( __CLASS__, 'screen' ) );
	}

	public static function register() {
		register_setting( self::GROUP, self::OPT, array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
		add_filter( 'option_page_capability_' . self::GROUP, function () { return self::CAP; } );
	}

	public static function sanitize( $in ) {
		$current = self::all();
		$in      = is_array( $in ) ? $in : array();
		$key     = isset( $in['api_key'] ) ? trim( (string) $in['api_key'] ) : '';
		return array(
			'base_url'          => isset( $in['base_url'] ) && '' !== trim( $in['base_url'] ) ? untrailingslashit( esc_url_raw( trim( $in['base_url'] ) ) ) : self::DEFAULTS['base_url'],
			// Blank keeps the stored key: it is never printed back into the form.
			'api_key'           => '' !== $key ? sanitize_text_field( $key ) : $current['api_key'],
			'timeout'           => isset( $in['timeout'] ) ? max( 2, min( 20, (int) $in['timeout'] ) ) : self::DEFAULTS['timeout'],
			'fallback_calendly' => self::calendly_url( isset( $in['fallback_calendly'] ) ? $in['fallback_calendly'] : '' ),
			'wait_text'         => isset( $in['wait_text'] ) && '' !== trim( $in['wait_text'] ) ? sanitize_text_field( $in['wait_text'] ) : self::DEFAULTS['wait_text'],
			'calendly_background'         => self::hex( $in, 'calendly_background' ),
			'calendly_text'               => self::hex( $in, 'calendly_text' ),
			'calendly_primary'            => self::hex( $in, 'calendly_primary' ),
			'calendly_hide_cookie_banner' => empty( $in['calendly_hide_cookie_banner'] ) ? 0 : 1,
			'thanks_after'                => isset( $in['thanks_after'] ) ? self::selector( $in['thanks_after'] ) : self::DEFAULTS['thanks_after'],
		);
	}

	/** A CSS selector, kept to characters selectors use. */
	protected static function selector( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );
		return preg_match( '/^[\w\s.#:>+~,\[\]="\'()*^$|-]*$/u', $value ) ? $value : self::DEFAULTS['thanks_after'];
	}

	/** A colour as Calendly takes it ("2fa3a3"); blank means Calendly's own, anything else the default. */
	protected static function hex( $in, $key ) {
		$value = isset( $in[ $key ] ) ? ltrim( trim( (string) $in[ $key ] ), '#' ) : self::DEFAULTS[ $key ];
		if ( '' === $value ) { return ''; }
		return preg_match( '/^[0-9a-f]{6}$/i', $value ) ? strtolower( $value ) : self::DEFAULTS[ $key ];
	}

	/** Calendly's embed parameters for the popup and the thank-you calendar. A link's own parameters win. */
	public static function calendly_theme() {
		$s     = self::all();
		$theme = array();
		if ( ! empty( $s['calendly_hide_cookie_banner'] ) ) { $theme['hide_gdpr_banner'] = '1'; }
		foreach ( array( 'background_color' => 'calendly_background', 'text_color' => 'calendly_text', 'primary_color' => 'calendly_primary' ) as $param => $key ) {
			if ( '' !== (string) $s[ $key ] ) { $theme[ $param ] = (string) $s[ $key ]; }
		}
		return $theme;
	}

	/** A Calendly link, or '' - only calendly.com links are ever shown to visitors. */
	public static function calendly_url( $value ) {
		$url = esc_url_raw( trim( (string) $value ) );
		if ( '' === $url ) { return ''; }
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) && $host && preg_match( '/(^|\.)calendly\.com$/', $host ) ) ? $url : '';
	}

	/* ------------------------------------------------------------- Screen */

	public static function screen() {
		if ( ! current_user_can( self::CAP ) ) { return; }
		$s    = self::all();
		$last = get_option( TBL_LR_Client::LAST_OPT );
		$test = isset( $_GET['tbl_lr_test'] ) ? sanitize_text_field( wp_unslash( $_GET['tbl_lr_test'] ) ) : '';
		$msg  = isset( $_GET['tbl_lr_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['tbl_lr_msg'] ) ) : '';
		?>
		<div class="wrap">
			<h1>TBL Lead Routing</h1>
			<?php
			// Settings pages show "Settings saved." by themselves; other menus don't.
			if ( 'options-general.php' !== self::parent() ) { settings_errors(); }
			?>
			<p style="max-width:780px">
				Sends leads from Terrific Forms to the <a href="<?php echo esc_url( $s['base_url'] ); ?>" target="_blank" rel="noopener">Travel by Luxe leads app</a>,
				which picks the travel designer and creates the Zendesk ticket, then shows the visitor that travel designer's Calendly.
				Turn it on per form, in the <strong>TBL Lead Routing</strong> box on the form's edit screen.
			</p>

			<?php if ( 'ok' === $test ) : ?>
				<div class="notice notice-success"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php elseif ( 'fail' === $test ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>

			<?php self::compat_box(); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="tbl_lr_base">Leads app address</label></th>
						<td><input type="url" id="tbl_lr_base" class="regular-text" name="<?php echo esc_attr( self::OPT ); ?>[base_url]" value="<?php echo esc_attr( $s['base_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="tbl_lr_key">API key</label></th>
						<td>
							<input type="password" id="tbl_lr_key" class="regular-text" autocomplete="off" name="<?php echo esc_attr( self::OPT ); ?>[api_key]" value="" placeholder="<?php echo $s['api_key'] ? esc_attr( 'Saved - leave blank to keep' ) : 'tbl_leads_…'; ?>">
							<p class="description">Create one in the leads app under Settings → API keys. It stays on this server and is never sent to visitors' browsers.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbl_lr_timeout">Longest wait</label></th>
						<td>
							<input type="number" id="tbl_lr_timeout" min="2" max="20" style="width:80px" name="<?php echo esc_attr( self::OPT ); ?>[timeout]" value="<?php echo esc_attr( $s['timeout'] ); ?>"> seconds
							<p class="description">If the leads app has not answered by then, the visitor gets the fallback calendar. The lead is still saved by Terrific Forms either way.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbl_lr_fallback">Fallback Calendly link</label></th>
						<td>
							<input type="url" id="tbl_lr_fallback" class="regular-text" name="<?php echo esc_attr( self::OPT ); ?>[fallback_calendly]" value="<?php echo esc_attr( $s['fallback_calendly'] ); ?>" placeholder="https://calendly.com/…">
							<p class="description">Shown when the leads app can't be reached in time or has no calendar to offer. Use the same link as the fallback owner in the leads app's Settings. A form can set its own.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Calendly colours</th>
						<td>
							<?php foreach ( array( 'calendly_background' => 'Background', 'calendly_text' => 'Text', 'calendly_primary' => 'Buttons and links' ) as $key => $label ) : ?>
								<label style="margin-right:16px"><input type="color" name="<?php echo esc_attr( self::OPT ); ?>[<?php echo esc_attr( $key ); ?>]" value="#<?php echo esc_attr( $s[ $key ] ? $s[ $key ] : self::DEFAULTS[ $key ] ); ?>" style="vertical-align:middle"> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
							<p><label><input type="checkbox" name="<?php echo esc_attr( self::OPT ); ?>[calendly_hide_cookie_banner]" value="1" <?php checked( ! empty( $s['calendly_hide_cookie_banner'] ) ); ?>> Hide Calendly's cookie banner</label></p>
							<p class="description">For the popup and the thank-you page calendar; the defaults match the site's own thank-you page. A Calendly link that sets its own colours keeps them.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbl_lr_thanks_after">Thank-you page built by the site</label></th>
						<td>
							<input type="text" id="tbl_lr_thanks_after" class="regular-text code" name="<?php echo esc_attr( self::OPT ); ?>[thanks_after]" value="<?php echo esc_attr( $s['thanks_after'] ); ?>" placeholder="Blank: off">
							<p class="description">For a thank-you page without <code>[tbl_calendly]</code> on it, such as one the site's own code builds. A visitor who was just routed gets the travel designer's calendar in the page's own Calendly spot (<code>#calendly_widget</code>) or, if there is none, in a "Book a time with …" section after this element. Other visitors see the page as before. Blank turns this off.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="tbl_lr_wait">"Please wait" text</label></th>
						<td><input type="text" id="tbl_lr_wait" class="regular-text" name="<?php echo esc_attr( self::OPT ); ?>[wait_text]" value="<?php echo esc_attr( $s['wait_text'] ); ?>"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="tbl_lr_test">
				<?php wp_nonce_field( 'tbl_lr_test' ); ?>
				<?php submit_button( 'Test connection', 'secondary', 'submit', false ); ?>
			</form>

			<h2 class="title" style="margin-top:2em">Last lead sent</h2>
			<?php if ( is_array( $last ) ) : ?>
				<p>
					<?php echo esc_html( wp_date( 'j M Y, H:i:s', (int) $last['time'] ) ); ?> -
					<strong style="color:<?php echo $last['ok'] ? '#008a20' : '#b32d2e'; ?>"><?php echo $last['ok'] ? 'answered' : 'failed'; ?></strong>
					in <?php echo esc_html( number_format_i18n( (float) $last['seconds'], 2 ) ); ?> s:
					<?php echo esc_html( $last['message'] ); ?>
				</p>
			<?php else : ?>
				<p class="description">Nothing sent yet.</p>
			<?php endif; ?>

			<h2 class="title" style="margin-top:2em">Thank-you page</h2>
			<p style="max-width:780px">For forms set to <em>redirect</em>, put this shortcode where the calendar should appear on the thank-you page (the form's own Redirect action decides which page that is):</p>
			<p><code>[tbl_calendly height="700"]</code> &nbsp; and, optionally, <code>[tbl_travel_designer fallback="our team"]</code> for the travel designer's first name.</p>
		</div>
		<?php
	}

	/** Shows whether Terrific Digital is present and at the version this add-on was tested with. */
	protected static function compat_box() {
		if ( ! tbl_lr_forms_available() ) {
			echo '<div class="notice notice-error inline"><p><strong>Terrific Digital is not active.</strong> This add-on works with its Terrific Forms and does nothing without it.</p></div>';
			return;
		}
		$version = tbl_lr_td_version();
		if ( version_compare( $version, TBL_LR_TESTED_TD_VERSION, '>' ) ) {
			printf(
				'<div class="notice notice-warning inline"><p><strong>Terrific Digital is now %1$s; this add-on was tested with %2$s.</strong> Submit a routed form once (on staging) to check the "Please wait" overlay, the popup and the thank-you calendar still work, then update the add-on. If they ever stop working, leads are still delivered and the form falls back to its normal thank-you behaviour.</p></div>',
				esc_html( $version ),
				esc_html( TBL_LR_TESTED_TD_VERSION )
			);
		}
	}

	public static function notices() {
		if ( tbl_lr_forms_available() || ! current_user_can( self::CAP ) ) { return; }
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'plugins' === $screen->id ) {
			echo '<div class="notice notice-warning"><p><strong>TBL Lead Routing</strong> needs the Terrific Digital plugin to be active.</p></div>';
		}
	}

	/** "Test connection": checks the address and key against the leads app. */
	public static function handle_test() {
		if ( ! current_user_can( self::CAP ) ) { wp_die( 'Not allowed.' ); }
		check_admin_referer( 'tbl_lr_test' );
		$result = TBL_LR_Client::check_key();
		wp_safe_redirect( self::url( array(
			'tbl_lr_test' => $result['ok'] ? 'ok' : 'fail',
			'tbl_lr_msg'  => rawurlencode( $result['message'] ),
		) ) );
		exit;
	}
}
