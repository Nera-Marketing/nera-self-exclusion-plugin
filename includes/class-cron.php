<?php
/**
 * Daily sweep: auto-reactivate users whose timed break has ended.
 *
 * Queries user meta for paused/suspended accounts whose until timestamp is in
 * the past, then calls Nera_SE_State::clear() on each one. Permanently-closed
 * accounts have until=0 and are excluded from the query by the >0 constraint.
 *
 * This class has no WooCommerce dependency and is bootstrapped unconditionally
 * (before the WC-present check in the main plugin file).
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_Cron.
 *
 * Static methods only. Bootstrap calls Nera_SE_Cron::init() on every request
 * so the action handler is always registered and the belt-and-braces re-arm
 * can catch any missed scheduling edge cases.
 */
class Nera_SE_Cron {

	/**
	 * Register the cron action handler and re-arm the schedule if it is missing.
	 *
	 * The activation hook in the main plugin file also schedules the event. This
	 * re-arm is belt-and-braces to recover from edge cases such as a site migration
	 * or manual wp_clear_scheduled_hook() call that clears the event without
	 * triggering the deactivation/reactivation cycle.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( NERA_SE_CRON_HOOK, array( __CLASS__, 'sweep' ) );

		// Re-arm: schedule if missing (belt-and-braces; activation hook is primary).
		if ( ! wp_next_scheduled( NERA_SE_CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', NERA_SE_CRON_HOOK );
		}
	}

	/**
	 * Find all paused/suspended users whose break end time has passed and clear them.
	 *
	 * Closed accounts are never swept because their META_UNTIL is stored as 0 and
	 * the query requires META_UNTIL > 0.
	 *
	 * @return void
	 */
	public static function sweep() {
		$q = new WP_User_Query(
			array(
				'fields'     => 'ID',
				'number'     => -1,
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key'     => Nera_SE_State::META_STATUS,
						'value'   => array( Nera_SE_State::STATUS_PAUSED, Nera_SE_State::STATUS_SUSPENDED ),
						'compare' => 'IN',
					),
					array(
						'key'     => Nera_SE_State::META_UNTIL,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => Nera_SE_State::META_UNTIL,
						'value'   => 0,
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		foreach ( $q->get_results() as $uid ) {
			Nera_SE_State::clear( (int) $uid, 'reactivated' );
		}
	}
}
