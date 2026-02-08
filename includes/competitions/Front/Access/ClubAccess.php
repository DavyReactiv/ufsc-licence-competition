<?php

namespace UFSC\Competitions\Front\Access;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClubAccess {
	public function is_club_user( int $user_id ): bool {
		return (bool) $this->get_club_id_for_user( $user_id );
	}

	public function get_club_id_for_user( int $user_id ): ?int {
		$user_id = absint( $user_id );
		if ( ! $user_id ) {
			return null;
		}

		if ( function_exists( 'ufsc_lc_resolve_current_club_id' ) ) {
			$resolved = ufsc_lc_resolve_current_club_id( $user_id );
			$club_id  = absint( $resolved['club_id'] ?? 0 );
			return $club_id ? $club_id : null;
		}

		$filtered = apply_filters( 'ufsc_competitions_get_club_id_for_user', null, $user_id );
		if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
			return (int) $filtered;
		}

		$meta = get_user_meta( $user_id, 'ufsc_club_id', true );
		$meta_id = absint( $meta );

		return $meta_id > 0 ? $meta_id : null;
	}
}
