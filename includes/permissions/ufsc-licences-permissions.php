<?php
/**
 * UFSC Licences / Compétitions permission compatibility layer.
 *
 * This plugin consumes the canonical UFSC capabilities and optional global helpers
 * provided by UFSC Gestion, but remains safe when UFSC Gestion is disabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'UFSC_LC_CAP_LICENCES_READ' ) ) {
	define( 'UFSC_LC_CAP_LICENCES_READ', 'ufsc_licences_read' );
}
if ( ! defined( 'UFSC_LC_CAP_LICENCES_MANAGE' ) ) {
	define( 'UFSC_LC_CAP_LICENCES_MANAGE', 'ufsc_licences_manage' );
}
if ( ! defined( 'UFSC_LC_CAP_COMPETITIONS_READ' ) ) {
	define( 'UFSC_LC_CAP_COMPETITIONS_READ', 'ufsc_competitions_read' );
}
if ( ! defined( 'UFSC_LC_CAP_COMPETITIONS_MANAGE' ) ) {
	define( 'UFSC_LC_CAP_COMPETITIONS_MANAGE', 'ufsc_competitions_manage' );
}
if ( ! defined( 'UFSC_LC_CAP_ALL_REGIONS' ) ) {
	define( 'UFSC_LC_CAP_ALL_REGIONS', 'ufsc_all_regions_access' );
}

if ( ! function_exists( 'ufsc_lc_is_administrator' ) ) {
	function ufsc_lc_is_administrator( int $user_id = 0 ): bool {
		$user_id = $user_id > 0 ? $user_id : get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}
}

if ( ! function_exists( 'ufsc_lc_user_can' ) ) {
	function ufsc_lc_user_can( $capability, $user_id = null ): bool {
		$capability = sanitize_key( (string) $capability );
		if ( '' === $capability ) {
			return false;
		}

		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ufsc_lc_is_administrator( $user_id ) ) {
			return true;
		}

		if ( function_exists( 'ufsc_user_can' ) ) {
			return (bool) ufsc_user_can( $capability, $user_id );
		}

		return user_can( $user_id, $capability );
	}
}

if ( ! function_exists( 'ufsc_lc_sanitize_regions_list' ) ) {
	function ufsc_lc_sanitize_regions_list( $regions ): array {
		if ( null === $regions || false === $regions || '' === $regions ) {
			return array();
		}

		if ( is_string( $regions ) ) {
			$decoded = json_decode( $regions, true );
			if ( is_array( $decoded ) ) {
				$regions = $decoded;
			} else {
				$regions = preg_split( '/[;,|\n]+/', $regions );
			}
		}

		if ( ! is_array( $regions ) ) {
			return array();
		}

		$clean = array();
		foreach ( $regions as $region ) {
			$region = sanitize_text_field( (string) $region );
			$region = trim( $region );
			if ( '' !== $region ) {
				$clean[] = $region;
			}
		}

		return array_values( array_unique( $clean ) );
	}
}

if ( ! function_exists( 'ufsc_lc_normalize_permission_region' ) ) {
	function ufsc_lc_normalize_permission_region( $region ): string {
		$region = sanitize_text_field( (string) $region );
		if ( function_exists( 'ufsc_lc_normalize_region_key' ) ) {
			return ufsc_lc_normalize_region_key( $region );
		}

		return sanitize_key( $region );
	}
}

if ( ! function_exists( 'ufsc_lc_user_can_access_region' ) ) {
	function ufsc_lc_user_can_access_region( $region, $user_id = null ): bool {
		$region = sanitize_text_field( (string) $region );
		if ( '' === trim( $region ) ) {
			return ufsc_lc_is_administrator( null === $user_id ? 0 : absint( $user_id ) );
		}

		$user_id = null === $user_id ? get_current_user_id() : absint( $user_id );
		if ( $user_id <= 0 ) {
			return false;
		}

		if ( ufsc_lc_is_administrator( $user_id ) || user_can( $user_id, UFSC_LC_CAP_ALL_REGIONS ) ) {
			return true;
		}

		if ( function_exists( 'ufsc_user_can_access_region' ) ) {
			return (bool) ufsc_user_can_access_region( $region, $user_id );
		}

		$target = ufsc_lc_normalize_permission_region( $region );
		foreach ( ufsc_lc_sanitize_regions_list( get_user_meta( $user_id, '_ufsc_allowed_regions', true ) ) as $allowed_region ) {
			if ( $target === ufsc_lc_normalize_permission_region( $allowed_region ) ) {
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'ufsc_lc_current_user_allowed_regions' ) ) {
	function ufsc_lc_current_user_allowed_regions() {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return array();
		}

		// null means unrestricted access for administrators / all-regions users.
		if ( ufsc_lc_is_administrator( $user_id ) || user_can( $user_id, UFSC_LC_CAP_ALL_REGIONS ) ) {
			return null;
		}

		if ( function_exists( 'ufsc_current_user_allowed_regions' ) ) {
			$regions = ufsc_current_user_allowed_regions();
			return null === $regions ? null : ufsc_lc_sanitize_regions_list( $regions );
		}

		return ufsc_lc_sanitize_regions_list( get_user_meta( $user_id, '_ufsc_allowed_regions', true ) );
	}
}

if ( ! function_exists( 'ufsc_lc_is_readonly_context' ) ) {
	function ufsc_lc_is_readonly_context( $module ): bool {
		$module = sanitize_key( (string) $module );
		if ( in_array( $module, array( 'licence', 'licences', 'license', 'licenses' ), true ) ) {
			return ufsc_lc_user_can( UFSC_LC_CAP_LICENCES_READ ) && ! ufsc_lc_user_can( UFSC_LC_CAP_LICENCES_MANAGE );
		}
		if ( in_array( $module, array( 'competition', 'competitions' ), true ) ) {
			return ufsc_lc_user_can( UFSC_LC_CAP_COMPETITIONS_READ ) && ! ufsc_lc_user_can( UFSC_LC_CAP_COMPETITIONS_MANAGE );
		}

		return false;
	}
}

if ( ! function_exists( 'ufsc_lc_get_license_region' ) ) {
	function ufsc_lc_get_license_region( $license_id ): ?string {
		$license_id = absint( $license_id );
		if ( ! $license_id || ! class_exists( 'UFSC_LC_Licence_Repository' ) ) {
			return null;
		}

		$repository = new UFSC_LC_Licence_Repository();
		if ( ! method_exists( $repository, 'get_licence_region' ) ) {
			return null;
		}

		$region = $repository->get_licence_region( $license_id );
		return is_string( $region ) && '' !== $region ? $region : null;
	}
}

if ( ! function_exists( 'ufsc_lc_get_competition_region' ) ) {
	function ufsc_lc_get_competition_region( $competition_id ): ?string {
		global $wpdb;

		$competition_id = absint( $competition_id );
		if ( ! $competition_id || ! class_exists( '\\UFSC\\Competitions\\Db' ) ) {
			return null;
		}

		if ( class_exists( '\UFSC\Competitions\Services\CompetitionMeta' ) ) {
			$meta = \UFSC\Competitions\Services\CompetitionMeta::get( $competition_id );
			foreach ( array( 'allowed_regions_keys', 'allowed_regions' ) as $meta_key ) {
				if ( ! empty( $meta[ $meta_key ] ) && is_array( $meta[ $meta_key ] ) ) {
					$first = reset( $meta[ $meta_key ] );
					$first = is_string( $first ) ? sanitize_text_field( $first ) : '';
					if ( '' !== $first ) {
						return $first;
					}
				}
			}
		}

		$table = \UFSC\Competitions\Db::competitions_table();
		$columns = array();
		if ( class_exists( 'UFSC_LC_Schema_Cache' ) ) {
			foreach ( array( 'organizer_region', 'venue_region' ) as $column ) {
				if ( UFSC_LC_Schema_Cache::column_exists( $table, $column ) ) {
					$columns[] = $column;
				}
			}
		}

		if ( empty( $columns ) ) {
			return null;
		}

		$select_parts = array_map(
			static function ( $column ) {
				return 'NULLIF(' . $column . ", '')";
			},
			$columns
		);
		$select = 'COALESCE(' . implode( ', ', $select_parts ) . ')';
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT {$select} FROM {$table} WHERE id = %d LIMIT 1", $competition_id ) );
		$value = is_string( $value ) ? sanitize_text_field( $value ) : '';

		return '' !== $value ? $value : null;
	}
}

if ( ! function_exists( 'ufsc_lc_current_user_can_access_license' ) ) {
	function ufsc_lc_current_user_can_access_license( $license_id ): bool {
		if ( ufsc_lc_user_can( UFSC_LC_CAP_ALL_REGIONS ) ) {
			return true;
		}

		$region = ufsc_lc_get_license_region( $license_id );
		if ( null === $region ) {
			return ufsc_lc_is_administrator();
		}

		return ufsc_lc_user_can_access_region( $region );
	}
}

if ( ! function_exists( 'ufsc_lc_current_user_can_access_competition' ) ) {
	function ufsc_lc_current_user_can_access_competition( $competition_id ): bool {
		if ( ufsc_lc_user_can( UFSC_LC_CAP_ALL_REGIONS ) ) {
			return true;
		}

		if ( class_exists( '\UFSC\Competitions\Services\CompetitionMeta' ) ) {
			$meta = \UFSC\Competitions\Services\CompetitionMeta::get( absint( $competition_id ) );
			$regions = array();
			foreach ( array( 'allowed_regions_keys', 'allowed_regions' ) as $meta_key ) {
				if ( ! empty( $meta[ $meta_key ] ) && is_array( $meta[ $meta_key ] ) ) {
					$regions = array_merge( $regions, $meta[ $meta_key ] );
				}
			}
			foreach ( ufsc_lc_sanitize_regions_list( $regions ) as $region ) {
				if ( ufsc_lc_user_can_access_region( $region ) ) {
					return true;
				}
			}
			if ( ! empty( $regions ) ) {
				return false;
			}
		}

		$region = ufsc_lc_get_competition_region( $competition_id );
		if ( null === $region ) {
			return ufsc_lc_is_administrator();
		}

		return ufsc_lc_user_can_access_region( $region );
	}
}


if ( ! function_exists( 'ufsc_lc_current_user_can_access_club' ) ) {
	function ufsc_lc_current_user_can_access_club( $club_id ): bool {
		$club_id = absint( $club_id );
		if ( ! $club_id ) {
			return false;
		}

		if ( ufsc_lc_user_can( UFSC_LC_CAP_ALL_REGIONS ) ) {
			return true;
		}

		if ( ! class_exists( 'UFSC_LC_Licence_Repository' ) ) {
			return ufsc_lc_is_administrator();
		}

		$repository = new UFSC_LC_Licence_Repository();
		$region = method_exists( $repository, 'get_club_region' ) ? $repository->get_club_region( $club_id ) : null;
		if ( null === $region ) {
			return ufsc_lc_is_administrator();
		}

		return ufsc_lc_user_can_access_region( $region );
	}
}

if ( ! function_exists( 'ufsc_lc_enforce_license_access' ) ) {
	function ufsc_lc_enforce_license_access( $license_id ): void {
		if ( ! ufsc_lc_current_user_can_access_license( $license_id ) ) {
			wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}
}

if ( ! function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
	function ufsc_lc_enforce_competition_access( $competition_id ): void {
		if ( ! ufsc_lc_current_user_can_access_competition( $competition_id ) ) {
			wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}
}

if ( ! function_exists( 'ufsc_lc_enforce_club_access' ) ) {
	function ufsc_lc_enforce_club_access( $club_id ): void {
		if ( ! ufsc_lc_current_user_can_access_club( $club_id ) ) {
			wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}
}

if ( ! function_exists( 'ufsc_lc_add_fallback_admin_caps' ) ) {
	function ufsc_lc_add_fallback_admin_caps(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		foreach ( array( UFSC_LC_CAP_LICENCES_READ, UFSC_LC_CAP_LICENCES_MANAGE, UFSC_LC_CAP_COMPETITIONS_READ, UFSC_LC_CAP_COMPETITIONS_MANAGE, UFSC_LC_CAP_ALL_REGIONS ) as $capability ) {
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}
}

add_filter(
	'user_has_cap',
	static function ( $allcaps, $caps, $args, $user ) {
		if ( ! $user instanceof WP_User ) {
			return $allcaps;
		}

		$cap_map = array(
			// Legacy licence capabilities consumed by this plugin.
			'ufsc_licence_read'       => UFSC_LC_CAP_LICENCES_READ,
			'ufsc_manage_read'        => UFSC_LC_CAP_LICENCES_READ,
			'ufsc_lc_manage'          => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_licence_create'     => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_licence_edit'       => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_licence_manage'     => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_licence_validate'   => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_licence_delete'     => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_lc_import'          => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_lc_export'          => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_manage_licences'    => UFSC_LC_CAP_LICENCES_MANAGE,
			'ufsc_scope_all_regions'  => UFSC_LC_CAP_ALL_REGIONS,
			// Legacy competition capabilities consumed by this plugin.
			'ufsc_competition_read'              => UFSC_LC_CAP_COMPETITIONS_READ,
			'ufsc_competition_create'            => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_edit'              => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_manage'            => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_entries_manage'    => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_export'            => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_validate'          => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_delete'            => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_weighin_manage'    => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_weighin_override'  => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_fight_manage'      => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_fight_generate'    => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_fight_regenerate'  => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_plateau_manage'    => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_result_record'     => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_result_correct'    => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_audit_view'        => UFSC_LC_CAP_COMPETITIONS_READ,
			'ufsc_competition_sensitive_ops'     => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_competition_delete_permanent'  => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_comp_manage_all'               => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_manage_competitions'           => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_comp_manage_region'            => UFSC_LC_CAP_COMPETITIONS_MANAGE,
			'ufsc_comp_view_region'              => UFSC_LC_CAP_COMPETITIONS_READ,
			'ufsc_competitions_validate_entries' => UFSC_LC_CAP_COMPETITIONS_MANAGE,
		);

		$is_admin = ! empty( $allcaps['manage_options'] );
		foreach ( $cap_map as $legacy_cap => $canonical_cap ) {
			if ( $is_admin || ! empty( $allcaps[ $canonical_cap ] ) ) {
				$allcaps[ $legacy_cap ] = true;
			}
		}

		if ( $is_admin ) {
			foreach ( array_unique( array_merge( array_values( $cap_map ), array( UFSC_LC_CAP_ALL_REGIONS ) ) ) as $canonical_cap ) {
				$allcaps[ $canonical_cap ] = true;
			}
		}

		return $allcaps;
	},
	10,
	4
);
