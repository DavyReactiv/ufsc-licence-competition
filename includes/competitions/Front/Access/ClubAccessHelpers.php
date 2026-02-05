<?php

use UFSC\Competitions\Repositories\ClubRepository;

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
	 * @return array{club_id:int,source:string,source_meta_key:string}
	 */
	function ufsc_resolve_current_club_id( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		if ( ! $user_id ) {
			return array(
				'club_id' => 0,
				'source'  => 'missing_user',
				'source_meta_key' => '',
			);
		}

		$club_id = 0;
		$source  = 'fallback';
		$source_meta_key = '';

		if ( ! $club_id ) {
			$user_meta_keys = array( 'ufsc_club_id', 'club_id' );
			foreach ( $user_meta_keys as $key ) {
				$meta = get_user_meta( $user_id, $key, true );
				$meta_id = absint( $meta );
				if ( $meta_id > 0 ) {
					$club_id = $meta_id;
					$source  = 'user_meta';
					$source_meta_key = $key;
					break;
				}
			}
		}

		if ( ! $club_id ) {
			$um_keys = array( 'um_club_id', 'um_club', 'um_ufsc_club_id', 'um_clubid' );
			foreach ( $um_keys as $key ) {
				$meta = get_user_meta( $user_id, $key, true );
				$meta_id = absint( $meta );
				if ( $meta_id > 0 ) {
					$club_id = $meta_id;
					$source  = 'um_meta';
					$source_meta_key = $key;
					break;
				}
			}
		}

		if ( ! $club_id ) {
			$user = get_user_by( 'id', $user_id );
			$user_roles = $user ? (array) $user->roles : array();
			$club_roles = apply_filters( 'ufsc_competitions_club_roles', array( 'club', 'ufsc_club' ), $user_id );
			if ( array_intersect( $user_roles, $club_roles ) ) {
				$role_club_id = apply_filters( 'ufsc_competitions_resolve_role_club_id', 0, $user_id, $user_roles );
				$role_club_id = absint( $role_club_id );
				if ( $role_club_id > 0 ) {
					$club_id = $role_club_id;
					$source = 'role';
					$source_meta_key = 'ufsc_competitions_resolve_role_club_id';
				}
			}
		}

		if ( ! $club_id ) {
			$user = get_user_by( 'id', $user_id );
			$email = $user ? (string) $user->user_email : '';
			if ( '' !== $email ) {
				$email_club_id = apply_filters( 'ufsc_competitions_resolve_club_id_by_email', 0, $email, $user_id );
				$email_club_id = absint( $email_club_id );
				if ( $email_club_id > 0 ) {
					$club_id = $email_club_id;
					$source = 'email';
					$source_meta_key = 'ufsc_competitions_resolve_club_id_by_email';
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
						$source_meta_key = $key;
						break;
					}
				}
			}
		}

		if ( ! $club_id ) {
			$filtered = apply_filters( 'ufsc_competitions_get_club_id_for_user', null, $user_id );
			if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
				$club_id = (int) $filtered;
				$source  = 'filter';
				$source_meta_key = 'ufsc_competitions_get_club_id_for_user';
			}
		}

		if ( ! $club_id ) {
			$fallback = apply_filters( 'ufsc_competitions_resolve_club_id', 0, $user_id );
			$fallback_id = absint( $fallback );
			if ( $fallback_id > 0 ) {
				$club_id = $fallback_id;
				$source  = 'fallback';
				$source_meta_key = 'ufsc_competitions_resolve_club_id';
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
							'UFSC club resolution: user_id=%d source=%s club_id=%d meta_key=%s',
							$user_id,
							$source,
							$club_id,
							$source_meta_key
						)
					);
				}
			}
		}

		return array(
			'club_id' => $club_id ? (int) $club_id : 0,
			'source'  => $source,
			'source_meta_key' => $source_meta_key,
		);
	}
}

if ( ! function_exists( 'ufsc_current_club_context' ) ) {
	/**
	 * Resolve current club context for a user (or current user if omitted).
	 *
	 * @param int $user_id Optional user id.
	 * @return array{club_id:int,club_name:string,region:string,affiliated:bool,source:string,source_meta_key:string}
	 */
	function ufsc_current_club_context( int $user_id = 0 ): array {
		$user_id = $user_id > 0 ? $user_id : ( is_user_logged_in() ? (int) get_current_user_id() : 0 );
		$resolved = function_exists( 'ufsc_resolve_current_club_id' ) ? ufsc_resolve_current_club_id( $user_id ) : array();

		$club_id = absint( $resolved['club_id'] ?? 0 );
		$source = (string) ( $resolved['source'] ?? '' );
		$source_meta_key = (string) ( $resolved['source_meta_key'] ?? '' );

		$club_name = '';
		$region = '';
		if ( $club_id && class_exists( ClubRepository::class ) ) {
			$club_repo = new ClubRepository();
			$club = $club_repo->get( $club_id );
			if ( $club ) {
				$club_name = trim( wp_strip_all_tags( (string) ( $club->nom ?? '' ) ) );
				$region_fields = array( 'region', 'ufsc_region', 'club_region', 'region_name' );
				foreach ( $region_fields as $field ) {
					if ( isset( $club->{$field} ) ) {
						$value = trim( wp_strip_all_tags( (string) $club->{$field} ) );
						if ( '' !== $value ) {
							$region = $value;
							break;
						}
					}
				}
			}
		}

		$affiliated = false;
		if ( $user_id > 0 && $club_id > 0 ) {
			$required_capability = class_exists( 'UFSC_LC_Settings_Page' ) ? \UFSC_LC_Settings_Page::get_club_access_capability() : '';
			$required_capability = apply_filters( 'ufsc_competitions_access_affiliation_capability', $required_capability, $club_id, $user_id );
			$affiliated = '' === $required_capability ? true : user_can( $user_id, $required_capability );
		}

		return array(
			'club_id' => $club_id,
			'club_name' => $club_name,
			'region' => $region,
			'affiliated' => $affiliated,
			'source' => $source,
			'source_meta_key' => $source_meta_key,
		);
	}
}
