<?php
/**
 * Self-exclusion account state: storage, gate, and transitions.
 *
 * Single source of truth for whether a user is currently self-excluded, the
 * status meta keys, and the apply/clear transitions. All other classes depend
 * on this one. No WooCommerce dependency — pure user-meta storage.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Nera_SE_State.
 *
 * Static API. Meta model (per user):
 *  - _nera_se_status : '' | paused | suspended | closed   (queryable)
 *  - _nera_se_until  : int unix ts (0 for closed/active)  (sortable)
 *  - _nera_se_set_at : int unix ts of last change
 *  - _nera_se_log    : JSON array of {state, from, until, set_at, set_by}
 */
class Nera_SE_State {

	const META_STATUS = '_nera_se_status';
	const META_UNTIL  = '_nera_se_until';
	const META_SET_AT = '_nera_se_set_at';
	const META_LOG    = '_nera_se_log';

	const STATUS_PAUSED    = 'paused';
	const STATUS_SUSPENDED = 'suspended';
	const STATUS_CLOSED    = 'closed';

	const LOG_MAX = 50;

	/**
	 * Allowed self-exclusion statuses.
	 *
	 * @return string[]
	 */
	public static function statuses() {
		return array( self::STATUS_PAUSED, self::STATUS_SUSPENDED, self::STATUS_CLOSED );
	}

	/**
	 * Normalised exclusion state for a user.
	 *
	 * Applies the staff exemption and lazy reactivation of expired timed breaks.
	 *
	 * @param int $user_id User ID.
	 * @return array{status:string,until:int,active:bool}
	 */
	public static function state( $user_id ) {
		$user_id  = (int) $user_id;
		$inactive = array(
			'status' => '',
			'until'  => 0,
			'active' => false,
		);

		if ( $user_id < 1 ) {
			return $inactive;
		}

		// Staff are never self-excluded — never lock out admins / shop managers.
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return $inactive;
		}

		$status = (string) get_user_meta( $user_id, self::META_STATUS, true );
		if ( ! in_array( $status, self::statuses(), true ) ) {
			return $inactive;
		}

		// Closed never expires.
		if ( self::STATUS_CLOSED === $status ) {
			return array(
				'status' => $status,
				'until'  => 0,
				'active' => true,
			);
		}

		$until = (int) get_user_meta( $user_id, self::META_UNTIL, true );

		// Lazy reactivation: a paused/suspended break whose end has passed.
		if ( $until > 0 && $until <= time() ) {
			self::clear( $user_id, 'reactivated' );
			return $inactive;
		}

		return array(
			'status' => $status,
			'until'  => $until,
			'active' => true,
		);
	}

	/**
	 * Whether the user is currently excluded (active break or closed).
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public static function is_excluded( $user_id ) {
		$state = self::state( $user_id );
		return ! empty( $state['active'] );
	}

	/**
	 * Apply a self-exclusion. Writes meta, logs the change, and ends live sessions.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  One of paused|suspended|closed.
	 * @param int    $until   Unix ts the break ends (ignored/zeroed for closed).
	 * @param string $set_by  Who set it: 'self' or an admin reason key.
	 * @return bool
	 */
	public static function apply( $user_id, $status, $until = 0, $set_by = 'self' ) {
		$user_id = (int) $user_id;
		$until   = (int) $until;

		if ( $user_id < 1 || ! in_array( $status, self::statuses(), true ) ) {
			return false;
		}

		if ( self::STATUS_CLOSED === $status ) {
			$until = 0;
		}

		$prev = (string) get_user_meta( $user_id, self::META_STATUS, true );
		$now  = time();

		update_user_meta( $user_id, self::META_STATUS, $status );
		update_user_meta( $user_id, self::META_UNTIL, $until );
		update_user_meta( $user_id, self::META_SET_AT, $now );

		self::append_log(
			$user_id,
			array(
				'state'  => $status,
				'from'   => $prev,
				'until'  => $until,
				'set_at' => $now,
				'set_by' => sanitize_key( $set_by ),
			)
		);

		// End any active sessions so the exclusion takes effect immediately.
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}

		/**
		 * Fires after a self-exclusion has been applied.
		 *
		 * @param int    $user_id User ID.
		 * @param string $status  Applied status.
		 * @param int    $until   End timestamp (0 for closed).
		 * @param string $set_by  Origin of the change.
		 */
		do_action( 'nera_se_applied', $user_id, $status, $until, $set_by );

		return true;
	}

	/**
	 * Clear a self-exclusion (auto-reactivation or admin reinstatement).
	 *
	 * @param int    $user_id User ID.
	 * @param string $reason  Why it was cleared (e.g. reactivated|reinstated_by_admin).
	 * @return bool
	 */
	public static function clear( $user_id, $reason = 'reactivated' ) {
		$user_id = (int) $user_id;
		if ( $user_id < 1 ) {
			return false;
		}

		$prev = (string) get_user_meta( $user_id, self::META_STATUS, true );

		update_user_meta( $user_id, self::META_STATUS, '' );
		update_user_meta( $user_id, self::META_UNTIL, 0 );

		self::append_log(
			$user_id,
			array(
				'state'  => '',
				'from'   => $prev,
				'until'  => 0,
				'set_at' => time(),
				'set_by' => sanitize_key( $reason ),
			)
		);

		/**
		 * Fires after a self-exclusion has been cleared.
		 *
		 * @param int    $user_id User ID.
		 * @param string $prev    Previous status.
		 * @param string $reason  Clear reason.
		 */
		do_action( 'nera_se_cleared', $user_id, $prev, $reason );

		return true;
	}

	/**
	 * Append an entry to the user's audit log, trimmed to LOG_MAX entries.
	 *
	 * @param int   $user_id User ID.
	 * @param array $entry   Log entry.
	 */
	protected static function append_log( $user_id, array $entry ) {
		$log = self::log( $user_id );
		$log[] = $entry;
		if ( count( $log ) > self::LOG_MAX ) {
			$log = array_slice( $log, -self::LOG_MAX );
		}
		update_user_meta( $user_id, self::META_LOG, wp_json_encode( $log ) );
	}

	/**
	 * Read the audit log as an array.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public static function log( $user_id ) {
		$raw = get_user_meta( (int) $user_id, self::META_LOG, true );
		$log = json_decode( (string) $raw, true );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * The set_by value of the most recent log entry (who set the current state).
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	public static function set_by( $user_id ) {
		$log = self::log( $user_id );
		if ( empty( $log ) ) {
			return 'self';
		}
		$last = end( $log );
		return isset( $last['set_by'] ) && '' !== $last['set_by'] ? (string) $last['set_by'] : 'self';
	}

	/**
	 * Human-readable label for a status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function label( $status ) {
		switch ( $status ) {
			case self::STATUS_PAUSED:
				return __( 'Paused', 'nera-self-exclusion' );
			case self::STATUS_SUSPENDED:
				return __( 'Suspended', 'nera-self-exclusion' );
			case self::STATUS_CLOSED:
				return __( 'Closed', 'nera-self-exclusion' );
			default:
				return __( 'Active', 'nera-self-exclusion' );
		}
	}
}
