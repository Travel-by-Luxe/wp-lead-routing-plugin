<?php
/**
 * Plugin Name:       TBL Lead Routing
 * Plugin URI:        https://github.com/Travel-by-Luxe/wp-lead-routing-plugin
 * Description:       Sends Terrific Forms leads to the Travel by Luxe leads app (leads.travelbyluxe.com), which picks the travel designer, and shows the visitor that travel designer's Calendly - on the thank-you page or in a popup, chosen per form.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  terrific-digital
 * Author:            Travel by Luxe
 * License:           GPL-2.0+
 * Text Domain:       tbl-lead-routing
 * Update URI:        https://github.com/Travel-by-Luxe/wp-lead-routing-plugin
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'TBL_LR_VERSION', '1.0.0' );
define( 'TBL_LR_FILE', __FILE__ );
define( 'TBL_LR_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBL_LR_URL', plugin_dir_url( __FILE__ ) );

/**
 * The Terrific Digital plugin version this add-on was last tested against.
 * The add-on hooks into that plugin's submit flow without changing it (see
 * PROJECT.md), so a newer version is flagged in the admin until re-tested.
 */
define( 'TBL_LR_TESTED_TD_VERSION', '2.51.0' );

require_once TBL_LR_DIR . 'includes/class-tbl-lr-settings.php';
require_once TBL_LR_DIR . 'includes/class-tbl-lr-forms.php';
require_once TBL_LR_DIR . 'includes/class-tbl-lr-client.php';
require_once TBL_LR_DIR . 'includes/class-tbl-lr-submit.php';
require_once TBL_LR_DIR . 'includes/class-tbl-lr-frontend.php';

/**
 * Updates come from this public repo's GitHub releases (a release.yml workflow
 * attaches tbl-lead-routing.zip to each vX.Y.Z tag), so WordPress shows
 * "Update available" like any other plugin. Public repo: no token needed.
 */
require_once TBL_LR_DIR . 'vendor/plugin-update-checker/plugin-update-checker.php';
$tbl_lr_updater = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/Travel-by-Luxe/wp-lead-routing-plugin/',
	__FILE__,
	'tbl-lead-routing'
);
$tbl_lr_updater->setBranch( 'main' );
$tbl_lr_updater->getVcsApi()->enableReleaseAssets();

add_action( 'plugins_loaded', function () {
	TBL_LR_Settings::init();
	TBL_LR_Forms::init();
	TBL_LR_Submit::init();
	TBL_LR_Frontend::init();
}, 20 ); // after Terrific Digital (default priority), so its classes exist

/** True when the Terrific Digital forms plugin is active and has what we hook into. */
function tbl_lr_forms_available() {
	return defined( 'TF_CPT' ) && class_exists( 'TF_Submissions' ) && function_exists( 'tf_get_actions' );
}

/** Installed Terrific Digital version, or '' when it is not active. */
function tbl_lr_td_version() {
	return defined( 'TD_VERSION' ) ? TD_VERSION : '';
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( TBL_LR_Settings::url() ) . '">Settings</a>' );
	return $links;
} );
