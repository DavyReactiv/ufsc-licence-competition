<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical functions for LC plugin: ufsc_lc_competitions_*
 * + Backward compatible wrappers: ufsc_competitions_* (only if missing)
 */

if ( ! function_exists( 'ufsc_lc_competitions_user_has_all_regions' ) ) {
	function ufsc_lc_competitions_user_has_all_regions( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		// Prefer master plugin helper if available.
		if ( function_exists( 'ufsc_user_has_all_regions' ) ) {
			return (bool) ufsc_user_has_all_regions( $user_id );
		}

		return user_can( $user_id, \UFSC_LC_Capabilities::SCOPE_ALL_REGIONS_CAPABILITY );
	}
}

if ( ! function_exists( 'ufsc_lc_competitions_get_user_scope_region' ) ) {
	function ufsc_lc_competitions_get_user_scope_region( int $user_id = 0 ): ?string {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		if ( $user_id <= 0 ) {
			return null;
		}

		if ( ufsc_lc_competitions_user_has_all_regions( $user_id ) ) {
			return null;
		}

		// Prefer master plugin helper if available.
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

if ( ! function_exists( 'ufsc_lc_competitions_assert_object_in_scope' ) ) {
	function ufsc_lc_competitions_assert_object_in_scope( $object_region ): void {
		$scope = ufsc_lc_competitions_get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return;
		}

		$object_region = is_string( $object_region ) ? sanitize_key( $object_region ) : '';

		if ( '' === $object_region || $object_region !== $scope ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}
}

if ( ! function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
	function ufsc_lc_competitions_apply_scope_to_query_args( array $args ): array {
		$scope = ufsc_lc_competitions_get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return $args;
		}

		$args['scope_region'] = $scope;

		return $args;
	}
}

if ( ! function_exists( 'ufsc_lc_competitions_apply_scope_to_args' ) ) {
	function ufsc_lc_competitions_apply_scope_to_args( array $args ): array {
		return ufsc_lc_competitions_apply_scope_to_query_args( $args );
	}
}

/**
 * Backward compatibility wrappers (only if not already provided by master competitions plugin).
 * These prevent regressions for legacy calls.
 */
if ( ! function_exists( 'ufsc_competitions_user_has_all_regions' ) ) {
	function ufsc_competitions_user_has_all_regions( int $user_id = 0 ): bool {
		return ufsc_lc_competitions_user_has_all_regions( $user_id );
	}
}

if ( ! function_exists( 'ufsc_competitions_get_user_scope_region' ) ) {
	function ufsc_competitions_get_user_scope_region( int $user_id = 0 ): ?string {
		return ufsc_lc_competitions_get_user_scope_region( $user_id );
	}
}

if ( ! function_exists( 'ufsc_competitions_assert_object_in_scope' ) ) {
	function ufsc_competitions_assert_object_in_scope( $object_region ): void {
		ufsc_lc_competitions_assert_object_in_scope( $object_region );
	}
}

if ( ! function_exists( 'ufsc_competitions_apply_scope_to_query_args' ) ) {
	function ufsc_competitions_apply_scope_to_query_args( array $args ): array {
		return ufsc_lc_competitions_apply_scope_to_query_args( $args );
	}
}

if ( ! function_exists( 'ufsc_competitions_apply_scope_to_args' ) ) {
	function ufsc_competitions_apply_scope_to_args( array $args ): array {
		return ufsc_lc_competitions_apply_scope_to_args( $args );
	}
}
