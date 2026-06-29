<?php
/**
 * Login gate and competition-entry blocking for self-excluded users.
 *
 * Registers WP and WooCommerce hooks that prevent excluded users from logging
 * in or adding lottery tickets to the cart. All WC-specific hooks are guarded
 * with function_exists() so the class is safe to load even when WooCommerce is
 * temporarily unavailable. gate_login() is pure-WP and carries no WC dependency.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_Guard.
 *
 * Static methods only. Bootstrap calls Nera_SE_Guard::init() inside the
 * WooCommerce-present branch of nera_se_init().
 */
class Nera_SE_Guard {

	/**
	 * Register all hooks. Called from the main bootstrap when WooCommerce is active.
	 *
	 * gate_login is a pure-WP filter and carries no WC dependency; the remaining
	 * hooks all require WC but are registered here together for clarity.
	 *
	 * @return void
	 */
	public static function init() {
		// Login gate — pure WP, no WC dependency.
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'gate_login' ), 20, 1 );

		// Add-to-cart validation — priority 5 so we run before most other validators.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_add_to_cart' ), 5, 3 );

		// Cart and checkout guards.
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'block_cart' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'block_checkout' ), 10, 2 );

		// Backstop for classic checkout order creation.
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'block_order' ), 5 );

		// Backstop for block-based / Store API checkout (fires before the lottery
		// plugin creates tickets on the same action).
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'block_order_object' ), 5 );
	}

	// -------------------------------------------------------------------------
	// Login gate
	// -------------------------------------------------------------------------

	/**
	 * Prevent self-excluded users from logging in.
	 *
	 * Hooked to wp_authenticate_user (priority 20). Receives either a WP_User on
	 * success so far, or a WP_Error from an earlier validator. Passes WP_Error
	 * objects through unchanged — we never clear a prior error.
	 *
	 * @param WP_User|WP_Error $user Result from wp_authenticate_username_password.
	 * @return WP_User|WP_Error Unchanged $user, or a WP_Error when excluded.
	 */
	public static function gate_login( $user ) {
		if ( ! ( $user instanceof WP_User ) ) {
			// Already an error — let it propagate.
			return $user;
		}

		if ( ! Nera_SE_State::is_excluded( $user->ID ) ) {
			return $user;
		}

		$state  = Nera_SE_State::state( $user->ID );
		$status = $state['status'];

		if ( Nera_SE_State::STATUS_CLOSED === $status ) {
			$message = __( 'Your account has been permanently closed and can no longer be used to log in. Please contact support if you need help.', 'nera-self-exclusion' );
		} else {
			$message = sprintf(
				/* translators: 1: lower-case status label (e.g. "paused"), 2: localised date the break ends */
				__( 'Your account is currently %1$s until %2$s. It will reactivate automatically — you cannot log in until then. Please contact support if you need help.', 'nera-self-exclusion' ),
				strtolower( Nera_SE_State::label( $status ) ),
				date_i18n( get_option( 'date_format' ), (int) $state['until'] )
			);
		}

		return new WP_Error( 'nera_se_excluded', $message );
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether the currently logged-in user is self-excluded.
	 *
	 * @return bool
	 */
	protected static function current_user_blocked() {
		return is_user_logged_in() && Nera_SE_State::is_excluded( get_current_user_id() );
	}

	/**
	 * Build the user-facing message shown when an entry is blocked.
	 *
	 * @param int $user_id User ID.
	 * @return string Plain-text or simple HTML suitable for wc_add_notice().
	 */
	protected static function block_message( $user_id ) {
		$state  = Nera_SE_State::state( (int) $user_id );
		$status = $state['status'];

		if ( Nera_SE_State::STATUS_CLOSED === $status ) {
			return __( 'Your account is closed, so you cannot enter competitions.', 'nera-self-exclusion' );
		}

		return sprintf(
			/* translators: 1: lower-case status label (e.g. "paused"), 2: localised reactivation date */
			__( 'Your account is currently %1$s, so you cannot enter competitions. It will reactivate on %2$s.', 'nera-self-exclusion' ),
			strtolower( Nera_SE_State::label( $status ) ),
			date_i18n( get_option( 'date_format' ), (int) $state['until'] )
		);
	}

	// -------------------------------------------------------------------------
	// WooCommerce entry guards
	// -------------------------------------------------------------------------

	/**
	 * Block adding a product to the cart when the user is self-excluded.
	 *
	 * Hooked to woocommerce_add_to_cart_validation (priority 5).
	 *
	 * @param bool $passed     Current pass/fail state from earlier validators.
	 * @param int  $product_id Product being added.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function block_add_to_cart( $passed, $product_id, $qty ) {
		unset( $product_id, $qty );

		if ( ! self::current_user_blocked() ) {
			return $passed;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::block_message( get_current_user_id() ), 'error' );
		}

		return false;
	}

	/**
	 * Block proceeding with a cart that belongs to a self-excluded user.
	 *
	 * Hooked to woocommerce_check_cart_items (action).
	 *
	 * @return void
	 */
	public static function block_cart() {
		if ( ! self::current_user_blocked() ) {
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( self::block_message( get_current_user_id() ), 'error' );
		}
	}

	/**
	 * Add a validation error to the checkout form for a self-excluded user.
	 *
	 * Hooked to woocommerce_after_checkout_validation (priority 10).
	 *
	 * @param array    $data   Posted checkout field values.
	 * @param WP_Error $errors WooCommerce checkout error bag.
	 * @return void
	 */
	public static function block_checkout( $data, $errors ) {
		unset( $data );

		if ( ! self::current_user_blocked() ) {
			return;
		}

		if ( $errors instanceof WP_Error ) {
			$errors->add( 'nera_se_blocked', self::block_message( get_current_user_id() ) );
		}
	}

	/**
	 * Backstop: mark an order as failed when the customer is self-excluded.
	 *
	 * Hooked to woocommerce_checkout_update_order_meta (priority 5). Catches
	 * Store API / REST order creation that can bypass the earlier cart/checkout
	 * filters. Requires wc_get_order() to be available.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return void
	 */
	public static function block_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( $order ) {
			self::maybe_fail_order( $order );
		}
	}

	/**
	 * Store API / block checkout backstop. Receives the WC_Order object directly.
	 *
	 * Hooked to woocommerce_store_api_checkout_order_processed (priority 5).
	 *
	 * @param mixed $order WC_Order from the Store API.
	 * @return void
	 */
	public static function block_order_object( $order ) {
		if ( $order instanceof WC_Order ) {
			self::maybe_fail_order( $order );
		}
	}

	/**
	 * Mark an order failed (with a note) when its customer is self-excluded.
	 *
	 * @param WC_Order $order Order to inspect.
	 * @return void
	 */
	protected static function maybe_fail_order( $order ) {
		$uid = (int) $order->get_customer_id();
		if ( $uid > 0 && Nera_SE_State::is_excluded( $uid ) ) {
			$order->add_order_note(
				__( 'Order blocked: customer is self-excluded. Marked failed by Nera Self-Exclusion.', 'nera-self-exclusion' )
			);
			$order->update_status( 'failed' );
		}
	}
}
