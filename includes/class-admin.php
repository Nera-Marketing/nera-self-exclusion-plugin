<?php
/**
 * Admin management surfaces for the Nera Self-Exclusion plugin.
 *
 * Registers the Users > Self-Exclusion submenu page, the reinstate admin-post
 * handler, a custom column on the core Users list, and a status filter dropdown.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_Admin.
 *
 * All methods are static; instantiation is never needed.
 */
class Nera_SE_Admin {

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	/**
	 * Register all admin hooks. Call from the plugin bootstrap when is_admin().
	 */
	public static function init() {
		add_action( 'admin_menu',             array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_nera_se_reinstate', array( __CLASS__, 'handle_reinstate' ) );
		add_filter( 'manage_users_columns',   array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
		add_action( 'restrict_manage_users',  array( __CLASS__, 'users_filter' ) );
		add_action( 'pre_get_users',          array( __CLASS__, 'filter_query' ) );
		add_action( 'admin_enqueue_scripts',  array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices',          array( __CLASS__, 'reinstate_notice' ) );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	/**
	 * Register the submenu page under Users.
	 */
	public static function menu() {
		add_submenu_page(
			'users.php',
			__( 'Self-Exclusion', 'nera-self-exclusion' ),
			__( 'Self-Exclusion', 'nera-self-exclusion' ),
			'manage_options',
			'nera-se-accounts',
			array( __CLASS__, 'render_page' )
		);
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	/**
	 * Render the Self-Exclusion management page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'nera-self-exclusion' ) );
		}

		// WP_List_Table base class — only available inside wp-admin.
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		// Our list table — must be required here so WP_List_Table is already
		// loaded when PHP evaluates the `extends WP_List_Table` declaration.
		require_once NERA_SE_PLUGIN_DIR . 'includes/class-list-table.php';

		$table = new Nera_SE_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Self-Exclusion Accounts', 'nera-self-exclusion' ) . '</h1>';

		$table->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="nera-se-accounts">';
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	// -------------------------------------------------------------------------
	// Reinstate handler
	// -------------------------------------------------------------------------

	/**
	 * Handle the admin-post reinstate action.
	 */
	public static function handle_reinstate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'nera-self-exclusion' ) );
		}

		$uid = absint( isset( $_GET['user'] ) ? $_GET['user'] : 0 );

		check_admin_referer( 'nera_se_reinstate_' . $uid );

		// Read raw stored status — do NOT use Nera_SE_State::state() which applies
		// staff exemption and may return '' for admins.
		$status = (string) get_user_meta( $uid, Nera_SE_State::META_STATUS, true );

		if ( Nera_SE_State::STATUS_CLOSED === $status ) {
			// Closed accounts are permanent; never reinstate via admin UI.
			$code = 'closed_denied';
		} elseif ( in_array( $status, array( Nera_SE_State::STATUS_PAUSED, Nera_SE_State::STATUS_SUSPENDED ), true ) ) {
			Nera_SE_State::clear( $uid, 'reinstated_by_admin' );
			$code = 'reinstated';
		} else {
			$code = 'invalid';
		}

		$redirect = add_query_arg(
			'nera_se_msg',
			$code,
			admin_url( 'users.php?page=nera-se-accounts' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin notice
	// -------------------------------------------------------------------------

	/**
	 * Display a result notice on the Self-Exclusion page after a reinstate action.
	 */
	public static function reinstate_notice() {
		// Only show on our page.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'users_page_nera-se-accounts' !== $screen->id ) {
			return;
		}

		$msg = isset( $_GET['nera_se_msg'] ) ? sanitize_key( wp_unslash( $_GET['nera_se_msg'] ) ) : '';
		if ( '' === $msg ) {
			return;
		}

		switch ( $msg ) {
			case 'reinstated':
				$class = 'notice-success';
				$text  = __( 'Account reinstated successfully.', 'nera-self-exclusion' );
				break;

			case 'closed_denied':
				$class = 'notice-error';
				$text  = __( 'Closed accounts cannot be reinstated.', 'nera-self-exclusion' );
				break;

			case 'invalid':
			default:
				$class = 'notice-error';
				$text  = __( 'Invalid or already-active account — no changes made.', 'nera-self-exclusion' );
				break;
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $text )
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue the admin stylesheet on the SE page and the core Users list.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue( $hook ) {
		if ( 'users_page_nera-se-accounts' !== $hook && 'users.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'nera-se-admin',
			NERA_SE_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			NERA_SE_VERSION
		);
	}

	// -------------------------------------------------------------------------
	// Users-list column
	// -------------------------------------------------------------------------

	/**
	 * Add a Self-exclusion column to the core Users list table.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public static function users_column( $cols ) {
		$cols['nera_se'] = __( 'Self-exclusion', 'nera-self-exclusion' );
		return $cols;
	}

	/**
	 * Render the cell value for the Self-exclusion column.
	 *
	 * @param string $val    Current cell value (from previous filter).
	 * @param string $col    Column key.
	 * @param int    $uid    User ID.
	 * @return string
	 */
	public static function users_column_value( $val, $col, $uid ) {
		if ( 'nera_se' !== $col ) {
			return $val;
		}

		$status = (string) get_user_meta( (int) $uid, Nera_SE_State::META_STATUS, true );

		if ( '' === $status ) {
			return '&mdash;';
		}

		return self::badge_html( $status );
	}

	// -------------------------------------------------------------------------
	// Users-list filter dropdown
	// -------------------------------------------------------------------------

	/**
	 * Output a status filter <select> above the Users list table.
	 *
	 * @param string $which 'top' or 'bottom' position.
	 */
	public static function users_filter( $which ) {
		// Render once — only in the top bar.
		if ( 'top' !== $which ) {
			return;
		}

		$current = isset( $_GET['nera_se_filter'] )
			? sanitize_key( wp_unslash( $_GET['nera_se_filter'] ) )
			: '';

		$options = array(
			''           => __( 'All users', 'nera-self-exclusion' ),
			'paused'     => __( 'Paused', 'nera-self-exclusion' ),
			'suspended'  => __( 'Suspended', 'nera-self-exclusion' ),
			'closed'     => __( 'Closed', 'nera-self-exclusion' ),
		);

		echo '<select name="nera_se_filter" id="nera-se-filter">';
		foreach ( $options as $val => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $val ),
				selected( $current, $val, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the status filter to the Users query when the dropdown is used.
	 *
	 * @param WP_User_Query $query Current user query.
	 */
	public static function filter_query( $query ) {
		// Note: pre_get_users passes a WP_User_Query, which has NO is_main_query()
		// method — calling it would fatal. We scope by admin context + screen below.
		if ( ! is_admin() ) {
			return;
		}

		// Confirm we are on the Users list screen.
		if ( function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
			if ( ! $screen || 'users' !== $screen->id ) {
				return;
			}
		} elseif ( ! isset( $_GET['nera_se_filter'] ) ) {
			return;
		}

		$filter = isset( $_GET['nera_se_filter'] )
			? sanitize_key( wp_unslash( $_GET['nera_se_filter'] ) )
			: '';

		if ( '' === $filter || ! in_array( $filter, Nera_SE_State::statuses(), true ) ) {
			return;
		}

		// Append our clause without clobbering any existing meta_query.
		$existing = $query->get( 'meta_query' );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$existing[] = array(
			'key'   => Nera_SE_State::META_STATUS,
			'value' => $filter,
		);

		$query->set( 'meta_query', $existing );
	}

	// -------------------------------------------------------------------------
	// Shared helper
	// -------------------------------------------------------------------------

	/**
	 * Build a styled badge <span> for a given self-exclusion status.
	 *
	 * @param string $status Status key (paused|suspended|closed) or empty.
	 * @return string HTML or empty string.
	 */
	public static function badge_html( $status ) {
		$status = (string) $status;
		if ( '' === $status || ! in_array( $status, Nera_SE_State::statuses(), true ) ) {
			return '';
		}

		return sprintf(
			'<span class="nera-se-badge nera-se-badge--%s">%s</span>',
			esc_attr( $status ),
			esc_html( Nera_SE_State::label( $status ) )
		);
	}
}
