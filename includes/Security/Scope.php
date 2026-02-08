<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licence scope helpers (region).
 *
 * Integrates with UFSC Gestion when available.
 */

if ( ! function_exists( 'ufsc_lc_user_has_all_regions' ) ) {
function ufsc_lc_user_has_all_regions( int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}

	if ( function_exists( 'ufsc_user_has_all_regions' ) ) {
		return (bool) ufsc_user_has_all_regions( $user_id );
	}

	return user_can( $user_id, UFSC_LC_Capabilities::SCOPE_ALL_REGIONS_CAPABILITY );
}
}

if ( ! function_exists( 'ufsc_lc_get_user_scope_region' ) ) {
function ufsc_lc_get_user_scope_region( int $user_id = 0 ): ?string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return null;
	}

	if ( ufsc_lc_user_has_all_regions( $user_id ) ) {
		return null;
	}

	if ( function_exists( 'ufsc_get_user_scope_region' ) ) {
		$scope = ufsc_get_user_scope_region( $user_id );
		$scope = is_string( $scope ) ? $scope : '';
		$scope = sanitize_key( $scope );
		return '' !== $scope ? $scope : null;
	}

	$scope = get_user_meta( $user_id, 'ufsc_scope_region', true );
	$scope = is_string( $scope ) ? $scope : '';
	$scope = sanitize_key( $scope );

	return '' !== $scope ? $scope : null;
}
}

if ( ! function_exists( 'ufsc_lc_assert_object_in_scope' ) ) {
function ufsc_lc_assert_object_in_scope( $object_region ): void {
	$scope = ufsc_lc_get_user_scope_region();
	if ( null === $scope || '' === $scope ) {
		return;
	}

	$object_region = is_string( $object_region ) ? sanitize_key( $object_region ) : '';

	if ( '' === $object_region || $object_region !== $scope ) {
		wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
	}
}
}

if ( ! function_exists( 'ufsc_lc_apply_scope_to_query_args' ) ) {
function ufsc_lc_apply_scope_to_query_args( array $args ): array {
	$scope = ufsc_lc_get_user_scope_region();
	if ( null === $scope || '' === $scope ) {
		return $args;
	}

	$args['scope_region'] = $scope;

	return $args;
}
}

if ( ! function_exists( 'ufsc_lc_apply_scope_to_sql' ) ) {
function ufsc_lc_apply_scope_to_sql( array &$where, array &$params, string $club_alias = 'c' ): void {
	if ( ! class_exists( 'UFSC_LC_Licence_Repository' ) ) {
		return;
	}

	$repository = new UFSC_LC_Licence_Repository();
	$repository->apply_scope_filter( $where, $params, $club_alias );
}
}

if ( ! class_exists( 'UFSC_Scope' ) ) {
	class UFSC_Scope {
		public static function user_has_all_regions( int $user_id = 0 ): bool {
			return ufsc_lc_user_has_all_regions( $user_id );
		}

		public static function get_user_scope_region( int $user_id = 0 ): ?string {
			return ufsc_lc_get_user_scope_region( $user_id );
		}

		public static function apply_scope_to_args( array $args ): array {
			return ufsc_lc_apply_scope_to_query_args( $args );
		}

		public static function apply_scope_to_sql( array &$where, array &$params, string $club_alias = 'c' ): void {
			ufsc_lc_apply_scope_to_sql( $where, $params, $club_alias );
		}

		public static function enforce_object_scope( int $object_id, string $object_type = 'licence' ): void {
			$object_id = absint( $object_id );
			if ( ! $object_id ) {
				wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
			}

			switch ( $object_type ) {
				case 'club':
					self::enforce_club_scope( $object_id );
					break;
				case 'entry':
					self::enforce_entry_scope( $object_id );
					break;
				case 'competition':
					self::enforce_competition_scope( $object_id );
					break;
				case 'licence':
				default:
					self::enforce_licence_scope( $object_id );
					break;
			}
		}

		public static function enforce_licence_scope( int $licence_id ): void {
			if ( ! class_exists( 'UFSC_LC_Licence_Repository' ) ) {
				return;
			}

			$repository = new UFSC_LC_Licence_Repository();
			$repository->assert_licence_in_scope( $licence_id );
		}

		public static function enforce_club_scope( int $club_id ): void {
			if ( ! class_exists( 'UFSC_LC_Licence_Repository' ) ) {
				return;
			}

			$repository = new UFSC_LC_Licence_Repository();
			if ( method_exists( $repository, 'assert_club_in_scope' ) ) {
				$repository->assert_club_in_scope( $club_id );
				return;
			}

			$region = method_exists( $repository, 'get_club_region' ) ? $repository->get_club_region( $club_id ) : null;
			ufsc_lc_assert_object_in_scope( $region );
		}

		public static function enforce_entry_scope( int $entry_id ): void {
			if ( class_exists( '\UFSC\Competitions\Repositories\EntryRepository' ) ) {
				$repo = new \UFSC\Competitions\Repositories\EntryRepository();
				if ( method_exists( $repo, 'assert_entry_in_scope' ) ) {
					$repo->assert_entry_in_scope( $entry_id );
				}
			}
		}

		public static function enforce_competition_scope( int $competition_id ): void {
			if ( class_exists( '\UFSC\Competitions\Repositories\CompetitionRepository' ) ) {
				$repo = new \UFSC\Competitions\Repositories\CompetitionRepository();
				if ( method_exists( $repo, 'assert_competition_in_scope' ) ) {
					$repo->assert_competition_in_scope( $competition_id );
				}
			}
		}
	}
}
