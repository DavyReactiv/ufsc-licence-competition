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
		$resolved = function_exists( 'ufsc_resolve_current_club_id' ) ? ufsc_resolve_current_club_id( $user_id ) : array();
		$club_id  = absint( $resolved['club_id'] ?? 0 );

		return $club_id ? $club_id : 0;
	}
}

if ( ! function_exists( 'ufsc_resolve_current_club_id' ) ) {
	/**
	 * Resolve current club id with fallback sources.
	 *
	 * @param int $user_id Optional user id.
	 * @return array{club_id:int,source:string}
	 */
	function ufsc_resolve_current_club_id( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		if ( ! $user_id ) {
			return array(
				'club_id' => 0,
				'source'  => 'missing_user',
			);
		}

		$club_id = 0;
		$source  = 'fallback';

		$filtered = apply_filters( 'ufsc_competitions_get_club_id_for_user', null, $user_id );
		if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
			$club_id = (int) $filtered;
			$source  = 'filter';
		}

		if ( ! $club_id ) {
			$um_keys = array( 'um_club_id', 'um_club', 'um_ufsc_club_id', 'um_clubid' );
			foreach ( $um_keys as $key ) {
				$meta = get_user_meta( $user_id, $key, true );
				$meta_id = absint( $meta );
				if ( $meta_id > 0 ) {
					$club_id = $meta_id;
					$source  = 'um_meta';
					break;
				}
			}
		}

		if ( ! $club_id ) {
			$user_meta_keys = array( 'ufsc_club_id', 'club_id' );
			foreach ( $user_meta_keys as $key ) {
				$meta = get_user_meta( $user_id, $key, true );
				$meta_id = absint( $meta );
				if ( $meta_id > 0 ) {
					$club_id = $meta_id;
					$source  = 'user_meta';
					break;
				}
			}
		}

		if ( ! $club_id ) {
			$claim_keys = array( 'um_user_claims', 'user_claims', 'claims', 'jwt_claims', 'um_jwt_claims' );
			foreach ( $claim_keys as $key ) {
				$claims = get_user_meta( $user_id, $key, true );
				if ( is_string( $claims ) && '' !== $claims ) {
					$decoded = json_decode( $claims, true );
					if ( is_array( $decoded ) ) {
						$claims = $decoded;
					}
				}
				if ( is_array( $claims ) ) {
					$candidate = $claims['club_id'] ?? $claims['clubId'] ?? $claims['club'] ?? 0;
					$candidate_id = absint( $candidate );
					if ( $candidate_id > 0 ) {
						$club_id = $candidate_id;
						$source  = 'claims';
						break;
					}
				}
			}
		}

		if ( ! $club_id ) {
			$fallback = apply_filters( 'ufsc_competitions_resolve_club_id', 0, $user_id );
			$fallback_id = absint( $fallback );
			if ( $fallback_id > 0 ) {
				$club_id = $fallback_id;
				$source  = 'fallback';
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$can_log = class_exists( '\UFSC\Competitions\Capabilities' )
				? \UFSC\Competitions\Capabilities::user_can_manage()
				: current_user_can( 'manage_options' );

			if ( $can_log ) {
				static $logged = false;
				if ( ! $logged ) {
					$logged = true;
					error_log(
						sprintf(
							'UFSC club resolution: user_id=%d source=%s club_id=%d',
							$user_id,
							$source,
							$club_id
						)
					);
				}
			}
		}

		return array(
			'club_id' => $club_id ? (int) $club_id : 0,
			'source'  => $source,
		);
	}
}
