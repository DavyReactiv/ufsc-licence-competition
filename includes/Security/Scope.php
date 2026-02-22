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

		if ( user_can( $user_id, UFSC_LC_Capabilities::SCOPE_ALL_REGIONS_CAPABILITY ) ) {
			return true;
		}

		return user_can( $user_id, 'manage_options' );
	}
}

if ( ! function_exists( 'ufsc_lc_get_user_scope_region' ) ) {
	function ufsc_lc_get_user_scope_region( int $user_id = 0 ): ?string {
		if ( class_exists( 'UFSC_LC_Scope' ) && method_exists( 'UFSC_LC_Scope', 'get_user_scope_region' ) ) {
			return UFSC_LC_Scope::get_user_scope_region( $user_id );
		}

		return null;
	}
}

/**
 * Debug helper (opt-in only).
 * Enable by hooking: add_filter('ufsc_enable_scope_debug', '__return_true');
 */
if ( ! function_exists( 'ufsc_lc_scope_debug_log' ) ) {
	function ufsc_lc_scope_debug_log( string $event, array $context = array() ): void {
		$enabled = (bool) apply_filters( 'ufsc_enable_scope_debug', false, $event, $context );
		if ( ! $enabled ) {
			return;
		}

		if ( class_exists( 'UFSC_LC_Logger' ) && method_exists( 'UFSC_LC_Logger', 'log' ) ) {
			UFSC_LC_Logger::log( 'UFSC scope debug: ' . $event, $context );
			return;
		}

		error_log( 'UFSC scope debug: ' . $event . ' ' . wp_json_encode( $context ) );
	}
}

/**
 * Empty scope sentinel helper.
 * Note: we DO NOT return this sentinel by default (to avoid permission regressions).
 * It is kept for compatibility and future UI/normalization needs.
 */
if ( ! function_exists( 'ufsc_lc_get_empty_scope_sentinel' ) ) {
	function ufsc_lc_get_empty_scope_sentinel(): string {
		return (string) apply_filters( 'ufsc_lc_empty_scope_region_sentinel', '__no_region__' );
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
			ufsc_lc_scope_debug_log(
				'assert_object_in_scope_denied',
				array(
					'scope'         => $scope,
					'object_region' => $object_region,
				)
			);

			wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
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

if ( ! function_exists( 'ufsc_lc_safe_enforce_object_scope' ) ) {
	function ufsc_lc_safe_enforce_object_scope( int $object_id, string $object_type = 'licence' ): void {
		if ( class_exists( 'UFSC_LC_Scope' ) ) {
			if ( method_exists( 'UFSC_LC_Scope', 'enforce_object_scope' ) ) {
				UFSC_LC_Scope::enforce_object_scope( $object_id, $object_type );
				return;
			}
			if ( method_exists( 'UFSC_LC_Scope', 'enforce_scope' ) ) {
				UFSC_LC_Scope::enforce_scope( $object_id, $object_type );
				return;
			}
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) && ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}
}

if ( class_exists( 'UFSC_Scope' ) && ! class_exists( 'UFSC_LC_Scope' ) ) {
	class UFSC_LC_Scope extends UFSC_Scope {
		public static function get_user_region( $user_id = 0 ) {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			if ( $user_id <= 0 ) {
				return '';
			}

			$scope = get_user_meta( $user_id, 'ufsc_scope_region', true );
			$scope = is_string( $scope ) ? sanitize_key( $scope ) : '';

			if ( '' === $scope ) {
				$legacy_scope = get_user_meta( $user_id, 'ufsc_region_scope', true );
				$scope        = is_string( $legacy_scope ) ? sanitize_key( $legacy_scope ) : '';
			}

			if ( '' === $scope && function_exists( 'ufsc_get_user_scope_region' ) ) {
				$master_scope = ufsc_get_user_scope_region( $user_id );
				$scope        = is_string( $master_scope ) ? sanitize_key( $master_scope ) : '';
			}

			if ( 'all' === $scope ) {
				return '';
			}

			return $scope;
		}

		public static function get_user_scope_region( int $user_id = 0 ): ?string {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			if ( $user_id <= 0 ) {
				return null;
			}

			if ( self::user_has_all_regions( $user_id ) ) {
				return null;
			}

			$scope = self::get_user_region( $user_id );

			// Zéro régression : empty scope => null (no regional scope).
			return '' !== $scope ? $scope : null;
		}

		public static function is_regional_admin( $user_id = 0 ) {
			return null !== self::get_user_scope_region( $user_id );
		}

		public static function apply_licence_scope_sql( &$where, &$params, $club_alias = 'c' ) {
			ufsc_lc_apply_scope_to_sql( $where, $params, $club_alias );
		}

		public static function assert_licence_in_scope( $licence_id ) {
			self::enforce_licence_scope( $licence_id );
		}

		public static function assert_club_in_scope( $club_id ) {
			self::enforce_club_scope( $club_id );
		}

		public static function enforce_object_scope( $object_id, $object_type = 'licence' ) {
			if ( method_exists( 'UFSC_Scope', 'enforce_object_scope' ) ) {
				UFSC_Scope::enforce_object_scope( $object_id, $object_type );
				return;
			}

			$object_id = absint( $object_id );
			if ( ! $object_id ) {
				wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
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

		public static function enforce_licence_scope( $licence_id ) {
			if ( method_exists( 'UFSC_Scope', 'enforce_licence_scope' ) ) {
				UFSC_Scope::enforce_licence_scope( $licence_id );
				return;
			}

			if ( ! class_exists( 'UFSC_LC_Licence_Repository' ) ) {
				return;
			}

			$repository = new UFSC_LC_Licence_Repository();
			$repository->assert_licence_in_scope( $licence_id );
		}

		public static function enforce_club_scope( $club_id ) {
			if ( method_exists( 'UFSC_Scope', 'enforce_club_scope' ) ) {
				UFSC_Scope::enforce_club_scope( $club_id );
				return;
			}

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

		public static function enforce_entry_scope( $entry_id ) {
			if ( method_exists( 'UFSC_Scope', 'enforce_entry_scope' ) ) {
				UFSC_Scope::enforce_entry_scope( $entry_id );
				return;
			}

			if ( class_exists( '\UFSC\Competitions\Repositories\EntryRepository' ) ) {
				$repo = new \UFSC\Competitions\Repositories\EntryRepository();
				if ( method_exists( $repo, 'assert_entry_in_scope' ) ) {
					$repo->assert_entry_in_scope( $entry_id );
				}
			}
		}

		public static function enforce_competition_scope( $competition_id ) {
			if ( method_exists( 'UFSC_Scope', 'enforce_competition_scope' ) ) {
				UFSC_Scope::enforce_competition_scope( $competition_id );
				return;
			}

			if ( class_exists( '\UFSC\Competitions\Repositories\CompetitionRepository' ) ) {
				$repo = new \UFSC\Competitions\Repositories\CompetitionRepository();
				if ( method_exists( $repo, 'assert_competition_in_scope' ) ) {
					$repo->assert_competition_in_scope( $competition_id );
				}
			}
		}
	}
} elseif ( ! class_exists( 'UFSC_LC_Scope' ) ) {
	class UFSC_LC_Scope {
		public static function user_has_all_regions( int $user_id = 0 ): bool {
			return ufsc_lc_user_has_all_regions( $user_id );
		}

		public static function get_user_region( int $user_id = 0 ): string {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			if ( $user_id <= 0 ) {
				return '';
			}

			$scope = get_user_meta( $user_id, 'ufsc_scope_region', true );
			$scope = is_string( $scope ) ? sanitize_key( $scope ) : '';

			if ( '' === $scope ) {
				$legacy_scope = get_user_meta( $user_id, 'ufsc_region_scope', true );
				$scope        = is_string( $legacy_scope ) ? sanitize_key( $legacy_scope ) : '';
			}

			if ( '' === $scope && function_exists( 'ufsc_get_user_scope_region' ) ) {
				$master_scope = ufsc_get_user_scope_region( $user_id );
				$scope        = is_string( $master_scope ) ? sanitize_key( $master_scope ) : '';
			}

			if ( 'all' === $scope ) {
				return '';
			}

			return $scope;
		}

		public static function get_user_scope_region( int $user_id = 0 ): ?string {
			$user_id = $user_id > 0 ? $user_id : get_current_user_id();
			if ( $user_id <= 0 ) {
				return null;
			}

			if ( self::user_has_all_regions( $user_id ) ) {
				return null;
			}

			$scope = self::get_user_region( $user_id );

			// Zéro régression : empty scope => null (no regional scope).
			return '' !== $scope ? $scope : null;
		}

		public static function is_regional_admin( int $user_id = 0 ): bool {
			return null !== self::get_user_scope_region( $user_id );
		}

		public static function apply_licence_scope_sql( array &$where, array &$params, string $club_alias = 'c' ): void {
			self::apply_scope_to_sql( $where, $params, $club_alias );
		}

		public static function assert_licence_in_scope( int $licence_id ): void {
			self::enforce_licence_scope( $licence_id );
		}

		public static function assert_club_in_scope( int $club_id ): void {
			self::enforce_club_scope( $club_id );
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
