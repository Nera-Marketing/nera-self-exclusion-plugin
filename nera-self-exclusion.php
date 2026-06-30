<?php
/**
 * Plugin Name: Nera – Self-Exclusion
 * Plugin URI: https://github.com/Nera-Marketing/nera-self-exclusion-plugin
 * Description: Lets players take a break (pause), suspend, or permanently close their account per the responsible-gambling voluntary code. Blocks competition entries and login while excluded, auto-reactivates timed breaks, and gives admins a self-exclusion management view.
 * Version: 1.0.2
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

use YahnisElsts\PluginUpdateChecker\v5p5\Vcs\GitHubApi;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NERA_SE_VERSION', '1.0.2' );
define( 'NERA_SE_PLUGIN_FILE', __FILE__ );
define( 'NERA_SE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NERA_SE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'NERA_SE_CRON_HOOK', 'nera_se_sweep' );
define( 'NERA_SE_ENDPOINT', 'account-status' );

/**
 * GitHub updates (Plugin Update Checker v5.5). On by default when `lib/plugin-update-checker/load-v5p5.php` exists.
 * Parity with nera-spending-amount-limit-plugin / nera-responsible-play-plugin.
 *
 * Disable:      define( 'NERA_SE_DISABLE_GITHUB_UPDATES', true );
 * Private repo: define( 'NERA_SE_GITHUB_TOKEN', 'ghp_...' );
 * Custom URL:   define( 'NERA_SE_GITHUB_REPO_URL', 'https://github.com/Owner/repo/' );  (or filter nera_se_github_repo_url)
 *
 * PUC reads the `Version` header from the GitHub ref it selects. Bump `Version` + `NERA_SE_VERSION` for every
 * release, then tag/push to match (release.sh does this). A custom setReleaseFilter (always true) + maxReleases > 1
 * makes GitHubApi use the paginated /releases endpoint instead of /latest (which 404s without a GitHub "latest"
 * release). enableReleaseAssets() prefers the attached zip over the tag tarball.
 *
 * @link https://github.com/YahnisElsts/plugin-update-checker
 */
if ( ! defined( 'NERA_SE_DISABLE_GITHUB_UPDATES' ) || ! NERA_SE_DISABLE_GITHUB_UPDATES ) {
	$nera_se_github_repo_default = 'https://github.com/Nera-Marketing/nera-self-exclusion-plugin/';
	if ( defined( 'NERA_SE_GITHUB_REPO_URL' ) && is_string( NERA_SE_GITHUB_REPO_URL ) && NERA_SE_GITHUB_REPO_URL !== '' ) {
		$nera_se_github_repo_default = NERA_SE_GITHUB_REPO_URL;
	}
	$nera_se_github_repo = apply_filters( 'nera_se_github_repo_url', $nera_se_github_repo_default );

	$nera_se_puc_loader = NERA_SE_PLUGIN_DIR . 'lib/plugin-update-checker/load-v5p5.php';
	if ( is_readable( $nera_se_puc_loader ) ) {
		require_once $nera_se_puc_loader;
		// Fourth argument: check period in hours (PUC default is 12).
		$nera_se_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			$nera_se_github_repo,
			__FILE__,
			'nera-self-exclusion',
			6
		);
		$nera_se_update_checker->setBranch( 'main' );

		if ( defined( 'NERA_SE_GITHUB_TOKEN' ) && is_string( NERA_SE_GITHUB_TOKEN ) && NERA_SE_GITHUB_TOKEN !== '' ) {
			$nera_se_update_checker->setAuthentication( NERA_SE_GITHUB_TOKEN );
		}

		// GitHub-hosted updates carry no plugin icon, so the Dashboard → Updates and
		// Plugins screens show a blank logo. Inject the bundled logo.png as the icon.
		$nera_se_update_checker->addResultFilter(
			static function ( $plugin_info ) {
				if ( is_object( $plugin_info ) && is_readable( NERA_SE_PLUGIN_DIR . 'logo.png' ) ) {
					$logo               = NERA_SE_PLUGIN_URL . 'logo.png';
					$plugin_info->icons = array(
						'1x'      => $logo,
						'2x'      => $logo,
						'default' => $logo,
					);
				}
				return $plugin_info;
			}
		);

		$nera_se_puc_vcs = $nera_se_update_checker->getVcsApi();
		if ( $nera_se_puc_vcs instanceof GitHubApi ) {
			$nera_se_puc_vcs->setReleaseFilter(
				static function ( $version_number, $release_object ) {
					unset( $version_number, $release_object );
					return true;
				},
				\YahnisElsts\PluginUpdateChecker\v5p5\Vcs\Api::RELEASE_FILTER_SKIP_PRERELEASE,
				20
			);
			$nera_se_puc_vcs->enableReleaseAssets();
		}
	}
}

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
