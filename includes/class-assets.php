<?php
/**
 * Enqueue frontend CSS and JS on the self-exclusion My Account endpoint.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_Assets
 */
class Nera_SE_Assets {

	/**
	 * Bootstrap enqueue hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	/**
	 * Conditionally enqueue styles and scripts on the account-status endpoint.
	 */
	public static function enqueue() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$on_endpoint = function_exists( 'is_account_page' )
			&& is_account_page()
			&& function_exists( 'is_wc_endpoint_url' )
			&& is_wc_endpoint_url( 'account-status' );

		if ( ! $on_endpoint ) {
			return;
		}

		wp_enqueue_style(
			'nera-se',
			NERA_SE_PLUGIN_URL . 'assets/css/self-exclusion.css',
			array(),
			NERA_SE_VERSION
		);

		wp_enqueue_script(
			'nera-se-account',
			NERA_SE_PLUGIN_URL . 'assets/js/account-status.js',
			array(),
			NERA_SE_VERSION,
			true
		);

		wp_localize_script(
			'nera-se-account',
			'neraSelfExclusion',
			array(
				'confirmClose' => __( 'This will permanently close your account and cannot be reversed. Continue?', 'nera-self-exclusion' ),
			)
		);
	}
}
