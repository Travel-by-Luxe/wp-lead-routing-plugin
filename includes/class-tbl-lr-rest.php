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
 * edit pages (the same right as the settings screen); writing a snippet also
 * needs unfiltered_html, and updating the plugin needs update_plugins. Secrets are never returned: the API key shows only
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
		register_rest_route( self::NS, '/forms/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'duplicate_form' ),
			'permission_callback' => array( __CLASS__, 'can_edit_form' ),
		) );
		register_rest_route( self::NS, '/pages/(?P<id>\d+)/duplicate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'duplicate_page' ),
			'permission_callback' => array( __CLASS__, 'can_edit_form' ),
		) );
		register_rest_route( self::NS, '/snippets', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'snippets' ),
				'permission_callback' => $editor,
			),
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_snippet' ),
				'permission_callback' => array( __CLASS__, 'can_write_code' ),
			),
		) );
		register_rest_route( self::NS, '/snippets/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'update_snippet' ),
			'permission_callback' => array( __CLASS__, 'can_write_code' ),
		) );
		register_rest_route( self::NS, '/snippets/(?P<id>\d+)/pages', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'snippet_pages' ),
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

	/** A snippet is raw HTML or JavaScript on the site's pages: only for users WordPress already lets post any HTML. */
	public static function can_write_code() {
		return current_user_can( TBL_LR_Settings::CAP ) && current_user_can( 'unfiltered_html' );
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
				'calendly_background'         => $settings['calendly_background'],
				'calendly_text'               => $settings['calendly_text'],
				'calendly_primary'            => $settings['calendly_primary'],
				'calendly_hide_cookie_banner' => (bool) $settings['calendly_hide_cookie_banner'],
				'thanks_after'                => $settings['thanks_after'],
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
					// The forms plugin calls a field's submitted name its "key" (static fields have none).
					'key'      => isset( $field['key'] ) ? (string) $field['key'] : ( isset( $field['name'] ) ? (string) $field['name'] : '' ),
					'id'       => isset( $field['id'] ) ? (string) $field['id'] : '',
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
	 * enabled: bool, index?: n among actions of that type }. A Redirect action can also be
	 * pointed elsewhere: { type: redirect, page_id: n } or { type: redirect, url: "https://…" }.
	 * Nothing else about the action changes, and it is saved through the forms plugin's
	 * own sanitizer.
	 */
	public static function toggle_action( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! tbl_lr_forms_available() || TF_CPT !== get_post_type( $id ) || ! class_exists( 'TF_Forms' ) ) {
			return new WP_Error( 'tbl_lr_not_a_form', 'No Terrific form with that id.', array( 'status' => 404 ) );
		}
		$type    = sanitize_key( (string) $request->get_param( 'type' ) );
		$enabled = $request->get_param( 'enabled' );
		$index   = (int) $request->get_param( 'index' );
		$page_id = $request->get_param( 'page_id' );
		$url     = $request->get_param( 'url' );
		$moves   = 'redirect' === $type && ( null !== $page_id || null !== $url );
		if ( ! in_array( $type, array( 'webhook', 'redirect', 'datalayer' ), true ) || ( null !== $enabled && ! is_bool( $enabled ) ) || ( null === $enabled && ! $moves ) ) {
			return new WP_Error( 'tbl_lr_bad_action', 'Send { "type": "webhook" | "redirect" | "datalayer", "enabled": true | false }, and for a redirect "page_id" or "url" to change where it goes.', array( 'status' => 400 ) );
		}
		if ( null !== $page_id && null !== $url ) {
			return new WP_Error( 'tbl_lr_bad_action', 'Send "page_id" or "url", not both.', array( 'status' => 400 ) );
		}
		if ( null !== $page_id ) {
			$page_id = (int) $page_id;
			if ( 'page' !== get_post_type( $page_id ) || 'publish' !== get_post_status( $page_id ) ) {
				return new WP_Error( 'tbl_lr_not_a_page', sprintf( 'Page %d is not a published page.', $page_id ), array( 'status' => 400 ) );
			}
		}
		if ( null !== $url ) {
			$url = esc_url_raw( trim( (string) $url ), array( 'https', 'http' ) );
			if ( '' === $url ) {
				return new WP_Error( 'tbl_lr_bad_url', 'Send a full http(s) address as "url".', array( 'status' => 400 ) );
			}
		}
		$actions = tf_get_actions( $id );
		$seen    = 0;
		$found   = false;
		foreach ( $actions as $i => $action ) {
			if ( ( isset( $action['type'] ) ? $action['type'] : '' ) !== $type ) { continue; }
			if ( $seen++ !== $index ) { continue; }
			if ( null !== $enabled ) { $actions[ $i ]['enabled'] = $enabled; }
			if ( $moves ) {
				$config = isset( $action['config'] ) && is_array( $action['config'] ) ? $action['config'] : array();
				$actions[ $i ]['config'] = null !== $page_id
					? array_merge( $config, array( 'mode' => 'page', 'page_id' => $page_id ) )
					: array_merge( $config, array( 'mode' => 'url', 'url' => $url ) );
			}
			$found = true;
			break;
		}
		if ( ! $found ) {
			return new WP_Error( 'tbl_lr_no_action', sprintf( 'This form has no %s action #%d.', $type, $index ), array( 'status' => 404 ) );
		}
		$clean = class_exists( 'TF_Builder' ) && method_exists( 'TF_Builder', 'sanitize_actions' ) ? TF_Builder::sanitize_actions( $actions ) : $actions;
		TF_Forms::save( $id, array( 'actions' => wp_json_encode( array_values( $clean ) ) ) );
		return self::describe_form( $id, self::form_usage() );
	}

	/**
	 * A copy of a form, made the way the forms plugin's own export/import does it:
	 * the same definition, texts, CSS and actions, saved through its own data
	 * functions, under a new form ID. The routing settings are copied too.
	 * { title?, status?: publish|draft|private }
	 */
	public static function duplicate_form( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! tbl_lr_forms_available() || TF_CPT !== get_post_type( $id ) || ! class_exists( 'TF_Forms' ) ) {
			return new WP_Error( 'tbl_lr_not_a_form', 'No Terrific form with that id.', array( 'status' => 404 ) );
		}
		$in     = (array) $request->get_json_params();
		$source = get_post( $id );
		$title  = isset( $in['title'] ) && '' !== trim( (string) $in['title'] ) ? sanitize_text_field( (string) $in['title'] ) : $source->post_title . ' (copy)';
		$status = isset( $in['status'] ) ? (string) $in['status'] : $source->post_status;
		$status = in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'draft';

		$new = wp_insert_post( array( 'post_type' => TF_CPT, 'post_title' => $title, 'post_status' => $status ), true );
		if ( is_wp_error( $new ) ) { return $new; }

		$def        = tf_get_form( $id );
		$definition = array(
			'type'  => isset( $def['type'] ) ? $def['type'] : 'regular',
			'steps' => isset( $def['steps'] ) ? $def['steps'] : array(),
		);
		if ( isset( $def['submission_step'] ) ) { $definition['submission_step'] = (int) $def['submission_step']; }
		if ( class_exists( 'TF_Builder' ) && method_exists( 'TF_Builder', 'sanitize_def' ) ) { $definition = TF_Builder::sanitize_def( $definition ); }
		$actions = tf_get_actions( $id );
		if ( class_exists( 'TF_Builder' ) && method_exists( 'TF_Builder', 'sanitize_actions' ) ) { $actions = TF_Builder::sanitize_actions( $actions ); }

		TF_Forms::save( $new, array(
			'definition'      => wp_json_encode( $definition ),
			'intent'          => function_exists( 'tf_get_intent' ) ? tf_get_intent( $id ) : TF_Forms::get_intent( $id ),
			'submit_text'     => TF_Forms::get_submit_text( $id ),
			'success_message' => TF_Forms::get_success_message( $id ),
			'wrapper_class'   => TF_Forms::get_wrapper_class( $id ),
			'custom_css'      => TF_Forms::get_custom_css( $id ),
			'actions'         => wp_json_encode( array_values( $actions ) ),
		) );
		if ( function_exists( 'tf_ensure_form_id' ) ) { tf_ensure_form_id( $new ); }
		$routing = get_post_meta( $id, TBL_LR_Forms::META, true );
		if ( is_array( $routing ) ) { update_post_meta( $new, TBL_LR_Forms::META, $routing ); }

		return array_merge( array( 'copied_from' => $id ), self::describe_form( $new, self::form_usage() ) );
	}

	/**
	 * A copy of a page, like the Duplicate Page plugin makes it (content, template,
	 * every custom field, terms), with its forms swapped for others:
	 * { title?, slug?, status?: publish|draft|private, forms?: { "77": 123 } }.
	 */
	public static function duplicate_page( WP_REST_Request $request ) {
		$id     = (int) $request['id'];
		$source = get_post( $id );
		if ( ! $source || 'page' !== $source->post_type ) {
			return new WP_Error( 'tbl_lr_not_a_page', 'No page with that id.', array( 'status' => 404 ) );
		}
		$in     = (array) $request->get_json_params();
		$status = isset( $in['status'] ) ? (string) $in['status'] : 'draft';
		$status = in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'draft';
		if ( 'publish' === $status && ! current_user_can( 'publish_pages' ) ) {
			return new WP_Error( 'tbl_lr_cannot_publish', 'You may not publish pages.', array( 'status' => 403 ) );
		}
		$map = array();
		foreach ( isset( $in['forms'] ) && is_array( $in['forms'] ) ? $in['forms'] : array() as $from => $to ) {
			if ( ! tbl_lr_forms_available() || TF_CPT !== get_post_type( (int) $to ) ) {
				return new WP_Error( 'tbl_lr_not_a_form', sprintf( 'Form %d does not exist.', (int) $to ), array( 'status' => 400 ) );
			}
			$map[ (int) $from ] = (int) $to;
		}
		$swap = function ( $text ) use ( $map ) {
			foreach ( $map as $from => $to ) {
				$text = preg_replace( '/(\[terrific_form[^\]]*\bid=\\\\?["\']?)' . $from . '\b/', '${1}' . $to, $text );
				$text = preg_replace( '/("formId":\s*"?)' . $from . '\b/', '${1}' . $to, $text );
			}
			return $text;
		};

		$new = wp_insert_post( wp_slash( array(
			'post_type'      => 'page',
			'post_title'     => isset( $in['title'] ) && '' !== trim( (string) $in['title'] ) ? sanitize_text_field( (string) $in['title'] ) : $source->post_title . ' (copy)',
			'post_name'      => isset( $in['slug'] ) ? sanitize_title( (string) $in['slug'] ) : '',
			'post_status'    => $status,
			'post_content'   => $swap( $source->post_content ),
			'post_excerpt'   => $source->post_excerpt,
			'post_parent'    => $source->post_parent,
			'menu_order'     => $source->menu_order,
			'comment_status' => $source->comment_status,
			'ping_status'    => $source->ping_status,
			'post_author'    => get_current_user_id(),
		) ), true );
		if ( is_wp_error( $new ) ) { return $new; }

		$skip = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
		foreach ( get_post_meta( $id ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) { continue; }
			foreach ( $values as $value ) {
				$value = maybe_unserialize( $value );
				if ( is_string( $value ) ) { $value = $swap( $value ); }
				add_post_meta( $new, $key, wp_slash( $value ) );
			}
		}
		foreach ( get_object_taxonomies( 'page' ) as $taxonomy ) {
			$terms = wp_get_object_terms( $id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) { wp_set_object_terms( $new, $terms, $taxonomy ); }
		}

		$page = get_post( $new );
		return array(
			'id'          => (int) $new,
			'copied_from' => $id,
			'title'       => $page->post_title,
			'slug'        => $page->post_name,
			'status'      => $page->post_status,
			'url'         => get_permalink( $new ),
			'forms'       => $map,
			'edit_url'    => get_edit_post_link( $new, 'raw' ),
		);
	}

	/* ------------------------------------------------------------- Snippets (Terrific Digital) */

	/** Every snippet and where it applies; ?page=ID adds whether it applies to that page. Code is not returned. */
	public static function snippets( WP_REST_Request $request ) {
		if ( ! class_exists( 'TD_Snippets' ) || ! class_exists( 'TD_Snippet_Store' ) ) {
			return new WP_Error( 'tbl_lr_no_snippets', 'Terrific Digital snippets are not available.', array( 'status' => 409 ) );
		}
		$page = (int) $request->get_param( 'page' );
		$ids  = get_posts( array( 'post_type' => TD_Snippets::CPT, 'post_status' => 'any', 'posts_per_page' => 200, 'fields' => 'ids', 'no_found_rows' => true ) );
		return array_map( function ( $id ) use ( $page ) { return self::describe_snippet( $id, $page ); }, $ids );
	}

	/** One snippet as the API shows it; with $page, also whether it runs on that page. */
	protected static function describe_snippet( $id, $page = 0 ) {
		$row  = (array) TD_Snippet_Store::get_row( $id );
		$cond = isset( $row['condition_type'] ) ? (string) $row['condition_type'] : 'all';
		$item = array(
			'id'          => (int) $id,
			'title'       => get_the_title( $id ),
			'status'      => get_post_status( $id ),
			'enabled'     => ! empty( $row['enabled'] ),
			'type'        => isset( $row['code_type'] ) ? (string) $row['code_type'] : '',
			'location'    => isset( $row['location'] ) ? (string) $row['location'] : '',
			'condition'   => $cond,
			'pages'       => TD_Snippet_Store::get_pages( $id ),
			'url_match'   => isset( $row['url_match'] ) ? (string) $row['url_match'] : '',
			'code_length' => isset( $row['code'] ) ? strlen( (string) $row['code'] ) : 0,
		);
		if ( $page ) {
			$url                     = (string) get_permalink( $page );
			$needle                  = $item['url_match'];
			$item['applies_to_page'] = 'all' === $cond
				|| ( 'specific' === $cond && in_array( $page, $item['pages'], true ) )
				|| ( 'url_contains' === $cond && '' !== $needle && false !== stripos( $url, $needle ) )
				|| ( 'url_not_contains' === $cond && ( '' === $needle || false === stripos( $url, $needle ) ) );
		}
		return $item;
	}

	/**
	 * A new Terrific Digital snippet, stored through its own data functions:
	 * { title, code, type?: html|js, location?, condition?: all|specific|url_contains|url_not_contains,
	 *   pages?: [ids], url_match?, enabled? }. Defaults: html, before_body_close, specific, off.
	 */
	public static function create_snippet( WP_REST_Request $request ) {
		if ( ! class_exists( 'TD_Snippets' ) || ! class_exists( 'TD_Snippet_Store' ) ) {
			return new WP_Error( 'tbl_lr_no_snippets', 'Terrific Digital snippets are not available.', array( 'status' => 409 ) );
		}
		$in    = (array) $request->get_json_params();
		$title = isset( $in['title'] ) ? sanitize_text_field( (string) $in['title'] ) : '';
		if ( '' === $title || ! isset( $in['code'] ) || '' === trim( (string) $in['code'] ) ) {
			return new WP_Error( 'tbl_lr_bad_snippet', 'Send at least a "title" and the "code".', array( 'status' => 400 ) );
		}
		$fields = self::snippet_fields( array_merge( array( 'type' => 'html', 'location' => 'before_body_close', 'condition' => 'specific', 'pages' => array(), 'url_match' => '', 'enabled' => false ), $in ) );
		if ( is_wp_error( $fields ) ) { return $fields; }

		$id = wp_insert_post( array( 'post_type' => TD_Snippets::CPT, 'post_title' => $title, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $id ) ) { return $id; }
		TD_Snippet_Store::save( $id, $fields );
		return self::describe_snippet( $id );
	}

	/** Changes a snippet: any of title, code, type, location, condition, pages, url_match, enabled. */
	public static function update_snippet( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! class_exists( 'TD_Snippets' ) || TD_Snippets::CPT !== get_post_type( $id ) ) {
			return new WP_Error( 'tbl_lr_not_a_snippet', 'No snippet with that id.', array( 'status' => 404 ) );
		}
		$in     = (array) $request->get_json_params();
		$fields = self::snippet_fields( $in );
		if ( is_wp_error( $fields ) ) { return $fields; }
		if ( isset( $in['title'] ) && '' !== trim( (string) $in['title'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => sanitize_text_field( (string) $in['title'] ) ) );
		}
		if ( $fields ) { TD_Snippet_Store::save( $id, $fields ); }
		return self::describe_snippet( $id );
	}

	/** The store's columns for the keys sent, checked against the forms plugin's own lists. */
	protected static function snippet_fields( $in ) {
		$fields = array();
		if ( isset( $in['code'] ) ) { $fields['code'] = (string) $in['code']; }
		if ( isset( $in['type'] ) ) {
			if ( ! isset( TD_Snippets::TYPES[ $in['type'] ] ) ) {
				return new WP_Error( 'tbl_lr_bad_snippet', 'type is one of: ' . implode( ', ', array_keys( TD_Snippets::TYPES ) ) . '.', array( 'status' => 400 ) );
			}
			$fields['code_type'] = $in['type'];
		}
		if ( isset( $in['location'] ) ) {
			if ( ! isset( TD_Snippets::LOCATIONS[ $in['location'] ] ) ) {
				return new WP_Error( 'tbl_lr_bad_snippet', 'location is one of: ' . implode( ', ', array_keys( TD_Snippets::LOCATIONS ) ) . '.', array( 'status' => 400 ) );
			}
			$fields['location'] = $in['location'];
		}
		if ( isset( $in['condition'] ) ) {
			if ( ! isset( TD_Snippets::CONDITIONS[ $in['condition'] ] ) ) {
				return new WP_Error( 'tbl_lr_bad_snippet', 'condition is one of: ' . implode( ', ', array_keys( TD_Snippets::CONDITIONS ) ) . '.', array( 'status' => 400 ) );
			}
			$fields['condition_type'] = $in['condition'];
		}
		if ( isset( $in['pages'] ) ) {
			$pages = array_values( array_unique( array_map( 'intval', (array) $in['pages'] ) ) );
			foreach ( $pages as $page ) {
				if ( 'page' !== get_post_type( $page ) ) {
					return new WP_Error( 'tbl_lr_not_a_page', sprintf( 'Page %d does not exist.', $page ), array( 'status' => 400 ) );
				}
			}
			$fields['pages'] = wp_json_encode( $pages );
		}
		if ( isset( $in['url_match'] ) ) { $fields['url_match'] = sanitize_text_field( (string) $in['url_match'] ); }
		if ( isset( $in['enabled'] ) ) { $fields['enabled'] = (bool) $in['enabled']; }
		return $fields;
	}

	/** Adds or removes pages on a snippet shown on specific pages only: { add?: [ids], remove?: [ids] }. */
	public static function snippet_pages( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( ! class_exists( 'TD_Snippets' ) || TD_Snippets::CPT !== get_post_type( $id ) ) {
			return new WP_Error( 'tbl_lr_not_a_snippet', 'No snippet with that id.', array( 'status' => 404 ) );
		}
		if ( 'specific' !== (string) TD_Snippet_Store::get_field( $id, 'condition_type', 'all' ) ) {
			return new WP_Error( 'tbl_lr_not_specific', 'This snippet is not limited to specific pages, so it has no page list.', array( 'status' => 409 ) );
		}
		$add    = array_map( 'intval', (array) $request->get_param( 'add' ) );
		$remove = array_map( 'intval', (array) $request->get_param( 'remove' ) );
		foreach ( $add as $page ) {
			if ( 'page' !== get_post_type( $page ) ) {
				return new WP_Error( 'tbl_lr_not_a_page', sprintf( 'Page %d does not exist.', $page ), array( 'status' => 400 ) );
			}
		}
		$pages = array_values( array_diff( array_unique( array_merge( TD_Snippet_Store::get_pages( $id ), $add ) ), $remove ) );
		TD_Snippet_Store::save( $id, array( 'pages' => wp_json_encode( $pages ) ) );
		return array( 'id' => $id, 'title' => get_the_title( $id ), 'pages' => TD_Snippet_Store::get_pages( $id ) );
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
