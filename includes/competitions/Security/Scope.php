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

		if ( class_exists( 'UFSC_LC_Scope' ) && method_exists( 'UFSC_LC_Scope', 'get_user_region' ) ) {
			$scope = UFSC_LC_Scope::get_user_region( $user_id );
			return '' !== $scope ? $scope : null;
		}

		return null;
	}
}

if ( ! function_exists( 'ufsc_lc_competitions_scope_profile_fields' ) ) {
	function ufsc_lc_competitions_scope_profile_fields( $user ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$value = class_exists( 'UFSC_LC_Scope' ) && method_exists( 'UFSC_LC_Scope', 'get_user_region' )
			? UFSC_LC_Scope::get_user_region( (int) $user->ID )
			: '';
		?>
		<h2><?php esc_html_e( 'UFSC Compétitions - Scope', 'ufsc-licence-competition' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ufsc_region_scope"><?php esc_html_e( 'Scope région', 'ufsc-licence-competition' ); ?></label></th>
				<td>
					<input type="text" name="ufsc_region_scope" id="ufsc_region_scope" value="<?php echo esc_attr( $value ); ?>" class="regular-text" placeholder="all / AURA / PACA">
					<p class="description"><?php esc_html_e( 'all = Super Admin, sinon code région.', 'ufsc-licence-competition' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}
}

if ( ! function_exists( 'ufsc_lc_competitions_save_scope_profile_fields' ) ) {
	function ufsc_lc_competitions_save_scope_profile_fields( int $user_id ): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( ! isset( $_POST['ufsc_region_scope'] ) ) {
			return;
		}
		$scope = sanitize_key( wp_unslash( $_POST['ufsc_region_scope'] ) );
		update_user_meta( $user_id, 'ufsc_region_scope', $scope );
		update_user_meta( $user_id, 'ufsc_scope_region', $scope );
	}
}

add_action( 'show_user_profile', 'ufsc_lc_competitions_scope_profile_fields' );
add_action( 'edit_user_profile', 'ufsc_lc_competitions_scope_profile_fields' );
add_action( 'personal_options_update', 'ufsc_lc_competitions_save_scope_profile_fields' );
add_action( 'edit_user_profile_update', 'ufsc_lc_competitions_save_scope_profile_fields' );

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
