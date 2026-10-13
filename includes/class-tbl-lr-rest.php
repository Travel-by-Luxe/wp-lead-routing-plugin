<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * An admin-only REST API, so the add-on and its forms can be looked after from
 * outside wp-admin (with an Application Password): read and change the
 * settings, test the connection, see every form's routing and actions, switch
 * a form's actions on or off, read the recent sends, clear the page cache and
 * install the newest release.
 *
 * Namespace tbl-lead-routing/v1. Every route needs a logged-in user who may
 * edit pages (the same right as the settings screen); updating the plugin
 * needs update_plugins. Secrets are never returned: the API key shows only
 * whether one is set, and webhook addresses only their host.
 */
class TBL_LR_Rest {

	const NS = 'tbl-lead-routing/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$editor = array( __CLASS__, 'can_edit' );
		register_rest_route( self::NS, '/status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'status' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_settings' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/test', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'test' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/forms', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'forms' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/forms/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'form' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/forms/(?P<id>\d+)/routing', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'save_routing' ),
			'permission_callback' => array( __CLASS__, 'can_edit_form' ),
		) );
		register_rest_route( self::NS, '/forms/(?P<id>\d+)/actions', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'toggle_action' ),
			'permission_callback' => array( __CLASS__, 'can_edit_form' ),
		) );
		register_rest_route( self::NS, '/log', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'log' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/cache/clear', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'clear_cache' ),
			'permission_callback' => $editor,
		) );
		register_rest_route( self::NS, '/update', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'self_update' ),
			'permission_callback' => function () { return current_user_can( 'update_plugins' ); },
		) );
	}

	public static function can_edit() {
		return current_user_can( TBL_LR_Settings::CAP );
	}

	public static function can_edit_form( WP_REST_Request $request ) {
		return self::can_edit() && current_user_can( 'edit_post', (int) $request['id'] );
	}

	/* ------------------------------------------------------------- Status & settings */

	public static function status() {
		$settings = TBL_LR_Settings::all();
		$key      = (string) $settings['api_key'];
		$theme    = wp_get_theme();
		return array(
			'plugin'            => array(
				'version'           => TBL_LR_VERSION,
				'tested_td_version' => TBL_LR_TESTED_TD_VERSION,
			),
			'site'              => array(
				'url'        => home_url(),
				'is_staging' => false !== strpos( (string) wp_parse_url( home_url(), PHP_URL_HOST ), 'staging.' ),
				'wordpress'  => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
				'theme'      => $theme ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '',
			),
			'terrific_digital'  => array(
				'active'    => tbl_lr_forms_available(),
				'version'   => tbl_lr_td_version(),
				'is_tested' => tbl_lr_td_version() !== '' && version_compare( tbl_lr_td_version(), TBL_LR_TESTED_TD_VERSION, '<=' ),
			),
			'settings'          => array(
				'base_url'          => $settings['base_url'],
				'api_key_set'       => '' !== $key,
				'api_key_hint'      => '' !== $key ? substr( $key, 0, 12 ) . '…' : '',
				'timeout'           => (int) $settings['timeout'],
				'fallback_calendly' => $settings['fallback_calendly'],
				'wait_text'         => $settings['wait_text'],
			),
			'last_lead'         => get_option( TBL_LR_Client::LAST_OPT, null ),
			'forms_routed'      => count( TBL_LR_Forms::enabled_forms() ),
			'cache'             => array( 'breeze' => self::breeze_active() ),
		);
	}

	/** Only the keys sent are changed; the rest keep their saved values. */
	public static function save_settings( WP_REST_Request $request ) {
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) { return new WP_Error( 'tbl_lr_bad_json', 'Send the settings as a JSON object.', array( 'status' => 400 ) ); }
		$allowed = array_keys( TBL_LR_Settings::DEFAULTS );
		$unknown = array_diff( array_keys( $in ), $allowed );
		if ( $unknown ) {
			return new WP_Error( 'tbl_lr_unknown_setting', 'Unknown settings: ' . implode( ', ', $unknown ) . '. Known: ' . implode( ', ', $allowed ) . '.', array( 'status' => 400 ) );
		}
		$merged = array_merge( TBL_LR_Settings::all(), array( 'api_key' => '' ), $in );
		update_option( TBL_LR_Settings::OPT, TBL_LR_Settings::sanitize( $merged ) );
		return self::status();
	}

	public static function test() {
		return TBL_LR_Client::check_key();
	}

	/* ------------------------------------------------------------- Forms */

	public static function forms() {
		if ( ! tbl_lr_forms_available() ) {
			return new WP_Error( 'tbl_lr_no_forms', 'The Terrific Digital plugin is not active.', array( 'status' => 409 ) );
		}
		$ids = get_posts( array(
			'post_type'      => TF_CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		$used = self::form_usage();
		return array_map( function ( $id ) use ( $used ) { return self::describe_form( $id, $used ); }, $ids );
	}

	public static function form( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! tbl_lr_forms_available() || TF_CPT !== get_post_type( $id ) ) {
			return new WP_Error( 'tbl_lr_not_a_form', 'No Terrific form with that id.', array( 'status' => 404 ) );
		}
		$out = self::describe_form( $id, self::form_usage() );
		$def = function_exists( 'tf_get_form' ) ? tf_get_form( $id ) : array();
		$out['fields'] = array();
		foreach ( isset( $def['steps'] ) && is_array( $def['steps'] ) ? $def['steps'] : array() as $n => $step ) {
			foreach ( isset( $step['fields'] ) && is_array( $step['fields'] ) ? $step['fields'] : array() as $field ) {
				$out['fields'][] = array(
					'step'     => $n + 1,
					'name'     => isset( $field['name'] ) ? (string) $field['name'] : '',
					'label'    => isset( $field['label'] ) ? wp_strip_all_tags( (string) $field['label'] ) : '',
					'type'     => isset( $field['type'] ) ? (string) $field['type'] : '',
					'required' => ! empty( $field['required'] ),
				);
			}
		}
		return $out;
	}

	/** routing: { enabled, mode: redirect|popup, fallback_calendly, wait_text } - only the keys sent change. */
	public static function save_routing( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! tbl_lr_forms_available() || TF_CPT !== get_post_type( $id ) ) {
			return new WP_Error( 'tbl_lr_not_a_form', 'No Terrific form with that id.', array( 'status' => 404 ) );
		}
		$in = $request->get_json_params();
		if ( ! is_array( $in ) ) { return new WP_Error( 'tbl_lr_bad_json', 'Send the routing as a JSON object.', array( 'status' => 400 ) ); }
		$unknown = array_diff( array_keys( $in ), array_keys( TBL_LR_Forms::DEFAULTS ) );
		if ( $unknown ) {
			return new WP_Error( 'tbl_lr_unknown_setting', 'Unknown routing settings: ' . implode( ', ', $unknown ) . '.', array( 'status' => 400 ) );
		}
		update_post_meta( $id, TBL_LR_Forms::META, TBL_LR_Forms::sanitize_config( array_merge( TBL_LR_Forms::config( $id ), $in ) ) );
		return self::describe_form( $id, self::form_usage() );
	}

	/**
	 * Switches one of the form's own actions on or off: { type: webhook|redirect|datalayer,
	 * enabled: bool, index?: n among actions of that type }. Nothing else about the
	 * action changes, and it is saved through the forms plugin's own sanitizer.
	 */
	public static function toggle_action( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! tbl_lr_forms_available() || TF_CPT !== get_post_type( $id ) || ! class_exists( 'TF_Forms' ) ) {
			return new WP_Error( 'tbl_lr_not_a_form', 'No Terrific form with that id.', array( 'status' => 404 ) );
		}
		$type    = sanitize_key( (string) $request->get_param( 'type' ) );
		$enabled = $request->get_param( 'enabled' );
		$index   = (int) $request->get_param( 'index' );
		if ( ! in_array( $type, array( 'webhook', 'redirect', 'datalayer' ), true ) || ! is_bool( $enabled ) ) {
			return new WP_Error( 'tbl_lr_bad_action', 'Send { "type": "webhook" | "redirect" | "datalayer", "enabled": true | false }.', array( 'status' => 400 ) );
		}
		$actions = tf_get_actions( $id );
		$seen    = 0;
		$found   = false;
		foreach ( $actions as $i => $action ) {
			if ( ( isset( $action['type'] ) ? $action['type'] : '' ) !== $type ) { continue; }
			if ( $seen++ !== $index ) { continue; }
			$actions[ $i ]['enabled'] = $enabled;
			$found                    = true;
			break;
		}
		if ( ! $found ) {
			return new WP_Error( 'tbl_lr_no_action', sprintf( 'This form has no %s action #%d.', $type, $index ), array( 'status' => 404 ) );
		}
		$clean = class_exists( 'TF_Builder' ) && method_exists( 'TF_Builder', 'sanitize_actions' ) ? TF_Builder::sanitize_actions( $actions ) : $actions;
		TF_Forms::save( $id, array( 'actions' => wp_json_encode( array_values( $clean ) ) ) );
		return self::describe_form( $id, self::form_usage() );
	}

	/** One form, as the API shows it: routing, actions (webhooks by host only) and the pages it is on. */
	protected static function describe_form( $id, $used ) {
		$def     = function_exists( 'tf_get_form' ) ? tf_get_form( $id ) : array();
		$steps   = isset( $def['steps'] ) && is_array( $def['steps'] ) ? count( $def['steps'] ) : 0;
		$routing = TBL_LR_Forms::config( $id );
		$actions = array();
		foreach ( tf_get_actions( $id ) as $action ) {
			$type   = isset( $action['type'] ) ? (string) $action['type'] : '';
			$target = '';
			if ( 'webhook' === $type && function_exists( 'tf_resolve_webhook_url' ) ) {
				$url    = (string) tf_resolve_webhook_url( $action );
				$target = '' !== $url ? (string) wp_parse_url( $url, PHP_URL_HOST ) : '';
			} elseif ( 'redirect' === $type && function_exists( 'tf_resolve_redirect_url' ) ) {
				$target = (string) tf_resolve_redirect_url( $action );
			} elseif ( 'datalayer' === $type ) {
				$target = isset( $action['config']['event'] ) ? (string) $action['config']['event'] : '';
			}
			$actions[] = array(
				'type'    => $type,
				'enabled' => ! empty( $action['enabled'] ),
				'target'  => $target,
				'source'  => isset( $action['config']['source'] ) ? (string) $action['config']['source'] : null,
			);
		}

		$webhook  = TBL_LR_Forms::active_webhook( $id );
		$warnings = array();
		if ( ! empty( $routing['enabled'] ) && '' !== $webhook ) {
			$warnings[] = 'Routing is on and the Webhook action still sends to ' . wp_parse_url( $webhook, PHP_URL_HOST ) . ': each lead would get a second ticket.';
		}
		if ( ! empty( $routing['enabled'] ) && 'redirect' === $routing['mode'] && ! TBL_LR_Forms::has_redirect( $id ) ) {
			$warnings[] = 'Routing shows the Calendly on the thank-you page, but the form has no Redirect action.';
		}

		$post = get_post( $id );
		return array(
			'id'       => $id,
			'title'    => $post ? $post->post_title : '',
			'status'   => $post ? $post->post_status : '',
			'form_id'  => class_exists( 'TF_Forms' ) ? TF_Forms::get_form_id( $id ) : '',
			'intent'   => class_exists( 'TF_Forms' ) ? TF_Forms::get_intent( $id ) : '',
			'type'     => isset( $def['type'] ) ? (string) $def['type'] : '',
			'steps'    => $steps,
			'routing'  => $routing,
			'sends_to' => ! empty( $routing['enabled'] ) ? ( '' !== $webhook ? 'leads app and webhook' : 'leads app' ) : ( '' !== $webhook ? 'webhook' : 'nowhere' ),
			'actions'  => $actions,
			'pages'    => isset( $used[ $id ] ) ? array_values( $used[ $id ] ) : array(),
			'warnings' => $warnings,
			'edit_url' => get_edit_post_link( $id, 'raw' ),
		);
	}

	/** form post id => pages it appears on (shortcode, block, or Elementor). */
	protected static function form_usage() {
		global $wpdb;
		$used = array();
		$add  = function ( $form_id, $page_id ) use ( &$used ) {
			$page = get_post( $page_id );
			if ( ! $page || 'revision' === $page->post_type ) { return; }
			$used[ (int) $form_id ][ $page_id ] = array(
				'id'     => (int) $page_id,
				'title'  => $page->post_title,
				'status' => $page->post_status,
				'url'    => get_permalink( $page_id ),
			);
		};
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- an admin-only lookup over a handful of pages.
		$rows = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','" . esc_sql( TF_CPT ) . "') AND post_status IN ('publish','draft','private','pending','future') AND (post_content LIKE '%terrific_form%' OR post_content LIKE '%terrific-digital/form%')" );
		foreach ( $rows as $row ) {
			if ( preg_match_all( '/\[terrific_form[^\]]*\bid=["\']?(\d+)/', $row->post_content, $m ) ) {
				foreach ( $m[1] as $form ) { $add( $form, (int) $row->ID ); }
			}
			if ( preg_match_all( '/wp:terrific-digital\/form\s+\{[^}]*"formId":"?(\d+)/', $row->post_content, $m ) ) {
				foreach ( $m[1] as $form ) { $add( $form, (int) $row->ID ); }
			}
		}
		$meta = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE '%terrific_form%'" );
		foreach ( $meta as $row ) {
			if ( preg_match_all( '/\[terrific_form[^\]]*\bid=\\\\?["\']?(\d+)/', $row->meta_value, $m ) ) {
				foreach ( $m[1] as $form ) { $add( $form, (int) $row->post_id ); }
			}
		}
		// phpcs:enable
		return $used;
	}

	/* ------------------------------------------------------------- Log, cache, update */

	public static function log() {
		$log = get_option( TBL_LR_Client::LOG_OPT, array() );
		return array_reverse( is_array( $log ) ? $log : array() );
	}

	protected static function breeze_active() {
		return defined( 'BREEZE_VERSION' ) || has_action( 'breeze_clear_all_cache' );
	}

	public static function clear_cache() {
		$done = array();
		if ( self::breeze_active() ) {
			do_action( 'breeze_clear_all_cache' );
			$done[] = 'Breeze page cache';
		}
		if ( wp_cache_flush() ) {
			$done[] = 'object cache';
		}
		return array( 'cleared' => $done );
	}

	/** Installs the newest GitHub release, the same way "Update now" does on the Plugins screen. */
	public static function self_update() {
		$updater = isset( $GLOBALS['tbl_lr_updater'] ) ? $GLOBALS['tbl_lr_updater'] : null;
		if ( ! $updater ) {
			return new WP_Error( 'tbl_lr_no_updater', 'The update checker is not loaded.', array( 'status' => 500 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$before = TBL_LR_VERSION;
		$updater->checkForUpdates();
		$update = $updater->getUpdate();
		if ( ! $update ) {
			return array( 'updated' => false, 'version' => $before, 'message' => 'Already on the newest release.' );
		}
		wp_clean_plugins_cache( false );
		$plugin   = plugin_basename( TBL_LR_FILE );
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( array( $plugin ) );
		$errors   = $skin->get_errors();
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return new WP_Error( 'tbl_lr_update_failed', 'The update failed: ' . implode( ' ', $errors->get_error_messages() ), array( 'status' => 500 ) );
		}
		if ( ! is_array( $result ) || empty( $result[ $plugin ] ) || is_wp_error( $result[ $plugin ] ) ) {
			return new WP_Error( 'tbl_lr_update_failed', 'The update did not install.', array( 'status' => 500 ) );
		}
		$data = get_plugin_data( TBL_LR_FILE, false, false );
		return array(
			'updated' => true,
			'from'    => $before,
			'version' => isset( $data['Version'] ) ? $data['Version'] : $update->version,
			'active'  => is_plugin_active( $plugin ),
		);
	}
}
