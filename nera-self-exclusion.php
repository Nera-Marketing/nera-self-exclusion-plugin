<?php
/**
 * Plugin Name: Nera – Self-Exclusion
 * Plugin URI: https://github.com/Nera-Marketing/nera-self-exclusion-plugin
 * Description: Lets players take a break (pause), suspend, or permanently close their account per the responsible-gambling voluntary code. Blocks competition entries and login while excluded, auto-reactivates timed breaks, and gives admins a self-exclusion management view.
 * Version: 1.0.0
 * Author: Nera
 * Text Domain: nera-self-exclusion
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.0
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NERA_SE_VERSION', '1.0.0' );
define( 'NERA_SE_PLUGIN_FILE', __FILE__ );
define( 'NERA_SE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_SE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NERA_SE_CRON_HOOK', 'nera_se_sweep' );
define( 'NERA_SE_ENDPOINT', 'account-status' );

/*
 * Class loading.
 *
 * NOTE: class-list-table.php is intentionally NOT required here — WP_List_Table is
 * only available in wp-admin. Nera_SE_Admin loads it lazily on its page render.
 */
require_once NERA_SE_PLUGIN_DIR . 'includes/class-state.php';
require_once NERA_SE_PLUGIN_DIR . 'includes/helpers.php';
require_once NERA_SE_PLUGIN_DIR . 'includes/class-cron.php';
require_once NERA_SE_PLUGIN_DIR . 'includes/class-admin.php';
require_once NERA_SE_PLUGIN_DIR . 'includes/class-account.php';
require_once NERA_SE_PLUGIN_DIR . 'includes/class-guard.php';
require_once NERA_SE_PLUGIN_DIR . 'includes/class-assets.php';

/**
 * Declare HPOS (custom order tables) compatibility. This plugin stores state in
 * user meta and only ever reads order data via the CRUD API, so it is compatible.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NERA_SE_PLUGIN_FILE, true );
		}
	}
);

/**
 * Bootstrap the plugin.
 */
function nera_se_init() {
	load_plugin_textdomain( 'nera-self-exclusion', false, dirname( plugin_basename( NERA_SE_PLUGIN_FILE ) ) . '/languages' );

	// Cron sweep and the admin view do not require WooCommerce.
	Nera_SE_Cron::init();
	if ( is_admin() ) {
		Nera_SE_Admin::init();
	}

	// Customer-facing enforcement + UI require WooCommerce.
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	Nera_SE_Account::init();
	Nera_SE_Guard::init();
	Nera_SE_Assets::init();
}
add_action( 'plugins_loaded', 'nera_se_init', 20 );

/**
 * Activation: register the account endpoint, flush rewrite rules, schedule cron.
 */
function nera_se_activate() {
	if ( method_exists( 'Nera_SE_Account', 'add_endpoint' ) ) {
		Nera_SE_Account::add_endpoint();
	}
	flush_rewrite_rules();

	if ( ! wp_next_scheduled( NERA_SE_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'daily', NERA_SE_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'nera_se_activate' );

/**
 * Deactivation: flush rewrite rules and clear the scheduled sweep.
 */
function nera_se_deactivate() {
	flush_rewrite_rules();
	wp_clear_scheduled_hook( NERA_SE_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'nera_se_deactivate' );
