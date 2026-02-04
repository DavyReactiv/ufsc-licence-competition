<?php

use UFSC\Competitions\Front\Access\ClubAccess;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_get_current_club_id' ) ) {
	/**
	 * Resolve current club id for a given user (or current user if omitted).
	 *
	 * @param int $user_id Optional user id.
	 * @return int
	 */
	function ufsc_get_current_club_id( int $user_id = 0 ): int {
		$user_id = $user_id > 0 ? $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		if ( ! $user_id ) {
			return 0;
		}

		$access = new ClubAccess();
		$club_id = $access->get_club_id_for_user( $user_id );

		return $club_id ? (int) $club_id : 0;
	}
}
