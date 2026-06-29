<?php
/**
 * WP_List_Table implementation for the Self-Exclusion admin page.
 *
 * NOT auto-loaded by the plugin bootstrap; required explicitly inside
 * Nera_SE_Admin::render_page() after WP_List_Table itself is loaded.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_List_Table.
 *
 * Displays all self-excluded accounts with sortable columns, status-view tabs,
 * per-status pagination, and an inline Reinstate action for paused/suspended rows.
 */
class Nera_SE_List_Table extends WP_List_Table {

	/** @var int Rows per page. */
	const PER_PAGE = 20;

	// -------------------------------------------------------------------------
	// Construction
	// -------------------------------------------------------------------------

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'account',
				'plural'   => 'accounts',
				'ajax'     => false,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Column definitions
	// -------------------------------------------------------------------------

	/**
	 * All columns shown in the table.
	 *
	 * @return array col_key => label
	 */
	public function get_columns() {
		return array(
			'user'    => __( 'User', 'nera-self-exclusion' ),
			'email'   => __( 'Email', 'nera-self-exclusion' ),
			'state'   => __( 'State', 'nera-self-exclusion' ),
			'set_at'  => __( 'Set at', 'nera-self-exclusion' ),
			'until'   => __( 'Until', 'nera-self-exclusion' ),
			'set_by'  => __( 'Set by', 'nera-self-exclusion' ),
			'actions' => __( 'Actions', 'nera-self-exclusion' ),
		);
	}

	/**
	 * Sortable columns and their default direction.
	 *
	 * @return array col_key => [orderby_key, is_currently_sorted]
	 */
	protected function get_sortable_columns() {
		return array(
			'set_at' => array( 'set_at', true ),
			'until'  => array( 'until', false ),
		);
	}

	// -------------------------------------------------------------------------
	// Data preparation
	// -------------------------------------------------------------------------

	/**
	 * Build the query, apply pagination, and populate $this->items.
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(), // hidden columns
			$this->get_sortable_columns(),
		);

		// Which statuses to show (view tabs or "all three").
		$status_filter = $this->current_status_filter();

		// Sorting.
		$orderby_raw = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'set_at';
		$order_raw   = isset( $_GET['order'] )   ? sanitize_key( wp_unslash( $_GET['order'] ) )   : 'desc';

		$allowed_orderby = array( 'set_at', 'until' );
		$orderby = in_array( $orderby_raw, $allowed_orderby, true ) ? $orderby_raw : 'set_at';
		$order   = 'asc' === strtolower( $order_raw ) ? 'ASC' : 'DESC';

		// Map our column keys to meta key names.
		$meta_key_map = array(
			'set_at' => Nera_SE_State::META_SET_AT,
			'until'  => Nera_SE_State::META_UNTIL,
		);
		$sort_meta_key = $meta_key_map[ $orderby ];

		// Build meta query — IN operator for possibly multiple statuses.
		$meta_query = array(
			array(
				'key'     => Nera_SE_State::META_STATUS,
				'value'   => $status_filter,
				'compare' => 'IN',
			),
		);

		// Count total matching records for pagination.
		$count_args = array(
			'fields'     => 'ID',
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'number'     => -1,
		);
		$count_query = new WP_User_Query( $count_args );
		$total_items = count( $count_query->get_results() );

		// Paginate.
		$current_page = $this->get_pagenum();
		$per_page     = self::PER_PAGE;

		// Fetch paginated results, sorted by the chosen meta key.
		$query_args = array(
			'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_key'   => $sort_meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery
			'orderby'    => 'meta_value_num',
			'order'      => $order,
			'number'     => $per_page,
			'offset'     => ( $current_page - 1 ) * $per_page,
			'fields'     => 'all',
		);

		$user_query = new WP_User_Query( $query_args );
		$users      = $user_query->get_results();

		// Build row data arrays.
		$this->items = array();
		foreach ( $users as $user ) {
			$uid = (int) $user->ID;
			$this->items[] = array(
				'ID'           => $uid,
				'user_login'   => $user->user_login,
				'display_name' => $user->display_name,
				'email'        => $user->user_email,
				'status'       => (string) get_user_meta( $uid, Nera_SE_State::META_STATUS, true ),
				'until'        => (int)    get_user_meta( $uid, Nera_SE_State::META_UNTIL,  true ),
				'set_at'       => (int)    get_user_meta( $uid, Nera_SE_State::META_SET_AT, true ),
				'set_by'       => Nera_SE_State::set_by( $uid ),
			);
		}

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	// -------------------------------------------------------------------------
	// View tabs (All / Paused / Suspended / Closed)
	// -------------------------------------------------------------------------

	/**
	 * Build the view-tab links with per-status counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$statuses   = Nera_SE_State::statuses();
		$current    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page_url   = admin_url( 'users.php?page=nera-se-accounts' );
		$views      = array();

		// Count all excluded users (any of the three statuses).
		$total_count = $this->count_by_status( $statuses );

		$all_class = ( '' === $current ) ? 'current' : '';
		$views['all'] = sprintf(
			'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
			esc_url( $page_url ),
			$all_class ? ' class="current"' : '',
			esc_html__( 'All', 'nera-self-exclusion' ),
			$total_count
		);

		$status_labels = array(
			Nera_SE_State::STATUS_PAUSED    => __( 'Paused', 'nera-self-exclusion' ),
			Nera_SE_State::STATUS_SUSPENDED => __( 'Suspended', 'nera-self-exclusion' ),
			Nera_SE_State::STATUS_CLOSED    => __( 'Closed', 'nera-self-exclusion' ),
		);

		foreach ( $status_labels as $status => $label ) {
			$count      = $this->count_by_status( array( $status ) );
			$link_class = ( $current === $status ) ? ' class="current"' : '';
			$url        = add_query_arg( 'status', $status, $page_url );
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$link_class,
				esc_html( $label ),
				$count
			);
		}

		return $views;
	}

	// -------------------------------------------------------------------------
	// Column renderers
	// -------------------------------------------------------------------------

	/**
	 * Default column renderer — dispatches by column key.
	 *
	 * @param array  $item Current row data.
	 * @param string $col  Column key.
	 * @return string HTML.
	 */
	public function column_default( $item, $col ) {
		switch ( $col ) {

			case 'user':
				$edit_link = get_edit_user_link( $item['ID'] );
				return sprintf(
					'<a href="%s">%s</a><br><span class="description">%s</span>',
					esc_url( $edit_link ),
					esc_html( $item['display_name'] ),
					esc_html( $item['user_login'] )
				);

			case 'email':
				return esc_html( $item['email'] );

			case 'state':
				$badge = Nera_SE_Admin::badge_html( $item['status'] );
				return $badge !== '' ? $badge : '&mdash;';

			case 'set_at':
				return $item['set_at'] > 0
					? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['set_at'] ) )
					: '&mdash;';

			case 'until':
				if ( Nera_SE_State::STATUS_CLOSED === $item['status'] ) {
					return '<em>' . esc_html__( 'Permanent', 'nera-self-exclusion' ) . '</em>';
				}
				if ( 0 === $item['until'] ) {
					return '&mdash;';
				}
				return esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $item['until'] ) );

			case 'set_by':
				// Convert snake_case reason keys to human-readable text.
				return esc_html( str_replace( '_', ' ', $item['set_by'] ) );

			case 'actions':
				return $this->render_actions( $item );

			default:
				return '&mdash;';
		}
	}

	/**
	 * Render the Actions cell: Reinstate link for paused/suspended; muted note for closed.
	 *
	 * @param array $item Row data.
	 * @return string HTML.
	 */
	protected function render_actions( array $item ) {
		$status = $item['status'];

		if ( Nera_SE_State::STATUS_CLOSED === $status ) {
			return '<span class="nera-se-permanent">' . esc_html__( 'Permanent', 'nera-self-exclusion' ) . '</span>';
		}

		if ( in_array( $status, array( Nera_SE_State::STATUS_PAUSED, Nera_SE_State::STATUS_SUSPENDED ), true ) ) {
			$nonce_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=nera_se_reinstate&user=' . (int) $item['ID'] ),
				'nera_se_reinstate_' . (int) $item['ID']
			);
			return sprintf(
				'<a href="%s" class="nera-se-reinstate">%s</a>',
				esc_url( $nonce_url ),
				esc_html__( 'Reinstate', 'nera-self-exclusion' )
			);
		}

		return '&mdash;';
	}

	/**
	 * Message shown when no items match the current view.
	 */
	public function no_items() {
		esc_html_e( 'No self-excluded accounts.', 'nera-self-exclusion' );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Resolve which statuses to display for the current view tab.
	 *
	 * @return string[] Array of status keys.
	 */
	protected function current_status_filter() {
		$all = Nera_SE_State::statuses();

		if ( ! isset( $_GET['status'] ) ) {
			return $all;
		}

		$requested = sanitize_key( wp_unslash( $_GET['status'] ) );
		return in_array( $requested, $all, true ) ? array( $requested ) : $all;
	}

	/**
	 * Count users whose status meta equals one of the given statuses.
	 *
	 * @param string[] $statuses Array of status keys.
	 * @return int
	 */
	protected function count_by_status( array $statuses ) {
		$q = new WP_User_Query(
			array(
				'fields'     => 'ID',
				'number'     => -1,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'key'     => Nera_SE_State::META_STATUS,
						'value'   => $statuses,
						'compare' => 'IN',
					),
				),
			)
		);
		return count( $q->get_results() );
	}
}
