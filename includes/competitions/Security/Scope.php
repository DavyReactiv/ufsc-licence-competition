<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ufsc_competitions_user_has_all_regions( int $user_id = 0 ): bool {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return false;
	}

	if ( function_exists( 'ufsc_user_has_all_regions' ) ) {
		return (bool) ufsc_user_has_all_regions( $user_id );
	}

	return user_can( $user_id, \UFSC_LC_Capabilities::SCOPE_ALL_REGIONS_CAPABILITY );
}

function ufsc_competitions_get_user_scope_region( int $user_id = 0 ): ?string {
	$user_id = $user_id > 0 ? $user_id : get_current_user_id();
	if ( $user_id <= 0 ) {
		return null;
	}

	if ( ufsc_competitions_user_has_all_regions( $user_id ) ) {
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

function ufsc_competitions_assert_object_in_scope( $object_region ): void {
	$scope = ufsc_competitions_get_user_scope_region();
	if ( null === $scope || '' === $scope ) {
		return;
	}

	$object_region = is_string( $object_region ) ? sanitize_key( $object_region ) : '';

	if ( '' === $object_region || $object_region !== $scope ) {
		wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
	}
}

function ufsc_competitions_apply_scope_to_query_args( array $args ): array {
	$scope = ufsc_competitions_get_user_scope_region();
	if ( null === $scope || '' === $scope ) {
		return $args;
	}

	$args['scope_region'] = $scope;

	return $args;
}

function ufsc_competitions_apply_scope_to_args( array $args ): array {
	return ufsc_competitions_apply_scope_to_query_args( $args );
}
