<?php
/**
 * My Account endpoint: Manage my account (self-exclusion).
 *
 * Registers the WooCommerce My Account endpoint, renders the template, and
 * handles the three POST actions (pause / suspend / close).
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_Account
 */
class Nera_SE_Account {

	/**
	 * Bootstrap all hooks. Called from the main plugin file when WooCommerce is active.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu_item' ) );
		add_action( 'woocommerce_account_account-status_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_request' ), 5 );
		add_action( 'woocommerce_before_customer_login_form', array( __CLASS__, 'excluded_notice' ) );
	}

	/**
	 * Register the rewrite endpoint.
	 *
	 * Also called from the activation hook, so it must be safe to call standalone.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( 'account-status', EP_ROOT | EP_PAGES );
	}

	/**
	 * Add the endpoint to WooCommerce's recognised query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars['account-status'] = 'account-status';
		return $vars;
	}

	/**
	 * Insert "Manage my account" before the logout link in the My Account nav.
	 *
	 * @param array $items Existing menu items.
	 * @return array
	 */
	public static function menu_item( $items ) {
		$new   = array();
		$added = false;

		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key && ! $added ) {
				$new['account-status'] = __( 'Manage my account', 'nera-self-exclusion' );
				$added                  = true;
			}
			$new[ $key ] = $label;
		}

		// Fallback: append if customer-logout was not found.
		if ( ! $added ) {
			$new['account-status'] = __( 'Manage my account', 'nera-self-exclusion' );
		}

		return $new;
	}

	/**
	 * Render the endpoint template via the WooCommerce template loader
	 * (supports theme override in the active theme's woocommerce/ folder).
	 */
	public static function render_endpoint() {
		wc_get_template(
			'account-status.php',
			array(),
			'',
			NERA_SE_PLUGIN_DIR . 'templates/'
		);
	}

	/**
	 * Handle POST submissions from the account-status forms.
	 *
	 * Runs at priority 5 on template_redirect so it fires before WC's own
	 * template rendering. Validates nonces, ownership, and input bounds before
	 * calling Nera_SE_State::apply().
	 */
	public static function handle_request() {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			return;
		}

		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';

		$valid_actions = array( 'nera_account_pause', 'nera_account_suspend', 'nera_account_close' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		// --- Nonce verification ---
		$nonce_map = array(
			'nera_account_pause'   => 'nera_account_pause_nonce',
			'nera_account_suspend' => 'nera_account_suspend_nonce',
			'nera_account_close'   => 'nera_account_close_nonce',
		);

		$nonce_field = $nonce_map[ $action ];
		$nonce_value = isset( $_POST[ $nonce_field ] )
			? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) )
			: '';

		if ( ! wp_verify_nonce( $nonce_value, $action ) ) {
			wc_add_notice(
				__( 'Security check failed. Please refresh the page and try again.', 'nera-self-exclusion' ),
				'error'
			);
			wp_safe_redirect( wc_get_account_endpoint_url( 'account-status' ) );
			exit;
		}

		// --- Ownership check ---
		$posted_user_id = absint( isset( $_POST['nera_account_user_id'] ) ? $_POST['nera_account_user_id'] : 0 );
		if ( $posted_user_id !== get_current_user_id() ) {
			wc_add_notice(
				__( 'Invalid request. Please try again.', 'nera-self-exclusion' ),
				'error'
			);
			wp_safe_redirect( wc_get_account_endpoint_url( 'account-status' ) );
			exit;
		}

		$user_id = get_current_user_id();
		$status  = '';
		$until   = 0;

		// --- Action-specific validation and bounds ---
		if ( 'nera_account_pause' === $action ) {
			$days = absint( isset( $_POST['pause_days'] ) ? $_POST['pause_days'] : 0 );
			if ( $days < 1 || $days > 183 ) {
				wc_add_notice(
					__( 'Please choose a pause duration between 1 and 183 days.', 'nera-self-exclusion' ),
					'error'
				);
				wp_safe_redirect( wc_get_account_endpoint_url( 'account-status' ) );
				exit;
			}
			$status = Nera_SE_State::STATUS_PAUSED;
			$until  = time() + $days * DAY_IN_SECONDS;

		} elseif ( 'nera_account_suspend' === $action ) {
			$months = absint( isset( $_POST['suspend_months'] ) ? $_POST['suspend_months'] : 0 );
			if ( $months < 6 || $months > 60 ) {
				wc_add_notice(
					__( 'Please choose a suspension duration between 6 and 60 months.', 'nera-self-exclusion' ),
					'error'
				);
				wp_safe_redirect( wc_get_account_endpoint_url( 'account-status' ) );
				exit;
			}
			$status = Nera_SE_State::STATUS_SUSPENDED;
			$until  = strtotime( "+{$months} months" );

		} elseif ( 'nera_account_close' === $action ) {
			$confirm_text  = sanitize_text_field( wp_unslash( isset( $_POST['confirm_text'] ) ? $_POST['confirm_text'] : '' ) );
			$confirm_check = isset( $_POST['confirm_check'] ) ? $_POST['confirm_check'] : '';

			if ( 'CLOSE' !== $confirm_text || '1' !== $confirm_check ) {
				wc_add_notice(
					__( 'Please tick the confirmation checkbox and type CLOSE to permanently close your account.', 'nera-self-exclusion' ),
					'error'
				);
				wp_safe_redirect( wc_get_account_endpoint_url( 'account-status' ) );
				exit;
			}
			$status = Nera_SE_State::STATUS_CLOSED;
			$until  = 0;
		}

		// --- Apply the exclusion (this also destroys active sessions) ---
		Nera_SE_State::apply( $user_id, $status, $until, 'self' );

		// User is now logged out. Redirect to My Account page with a query arg
		// so excluded_notice() can show a confirmation message.
		wp_safe_redirect(
			add_query_arg(
				'nera_excluded',
				rawurlencode( $status ),
				wc_get_page_permalink( 'myaccount' )
			)
		);
		exit;
	}

	/**
	 * Show a confirmation notice on the login form after a successful self-exclusion.
	 * Reads the ?nera_excluded= query arg set by handle_request().
	 */
	public static function excluded_notice() {
		// Only show when the user is not logged in (they were just excluded → logged out).
		if ( is_user_logged_in() ) {
			return;
		}

		$raw_status = isset( $_GET['nera_excluded'] )
			? sanitize_key( wp_unslash( $_GET['nera_excluded'] ) )
			: '';

		if ( '' === $raw_status ) {
			return;
		}

		$allowed = array(
			Nera_SE_State::STATUS_PAUSED,
			Nera_SE_State::STATUS_SUSPENDED,
			Nera_SE_State::STATUS_CLOSED,
		);

		if ( ! in_array( $raw_status, $allowed, true ) ) {
			return;
		}

		$label = Nera_SE_State::label( $raw_status );

		if ( Nera_SE_State::STATUS_CLOSED === $raw_status ) {
			$message = sprintf(
				/* translators: %s: status label. */
				__( 'Your account has been permanently closed (%s). You will not be able to log in again. Please contact support if you need help.', 'nera-self-exclusion' ),
				esc_html( $label )
			);
		} else {
			$message = sprintf(
				/* translators: %s: status label. */
				__( 'Your self-exclusion request has been received (%s). You have been logged out. Your account will reactivate automatically when the period ends.', 'nera-self-exclusion' ),
				esc_html( $label )
			);
		}

		wc_add_notice( $message, 'notice' );
	}
}
