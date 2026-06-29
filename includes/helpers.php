<?php
/**
 * Global helper wrappers around Nera_SE_State, for readable use in templates.
 *
 * @package Nera_Self_Exclusion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'nera_se_state' ) ) {
	/**
	 * Normalised exclusion state for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array{status:string,until:int,active:bool}
	 */
	function nera_se_state( $user_id ) {
		return Nera_SE_State::state( $user_id );
	}
}

if ( ! function_exists( 'nera_se_is_excluded' ) ) {
	/**
	 * Whether a user is currently self-excluded.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	function nera_se_is_excluded( $user_id ) {
		return Nera_SE_State::is_excluded( $user_id );
	}
}

if ( ! function_exists( 'nera_se_status_label' ) ) {
	/**
	 * Human-readable label for a status key.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	function nera_se_status_label( $status ) {
		return Nera_SE_State::label( $status );
	}
}
