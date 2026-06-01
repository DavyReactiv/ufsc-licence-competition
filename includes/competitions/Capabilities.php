<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	public const READ_CAPABILITY = 'ufsc_competitions_read';
	public const CREATE_CAPABILITY = 'ufsc_competitions_manage';
	public const EDIT_CAPABILITY = 'ufsc_competitions_manage';
	public const ENTRIES_MANAGE_CAPABILITY = 'ufsc_competition_entries_manage';
	public const EXPORT_CAPABILITY = 'ufsc_competition_export';
	public const VALIDATE_CAPABILITY = 'ufsc_competition_validate';
	public const DELETE_CAPABILITY = 'ufsc_competition_delete';
	public const WEIGHIN_MANAGE_CAPABILITY = 'ufsc_competition_weighin_manage';
	public const WEIGHIN_OVERRIDE_CAPABILITY = 'ufsc_competition_weighin_override';
	public const FIGHT_MANAGE_CAPABILITY = 'ufsc_competition_fight_manage';
	public const FIGHT_GENERATE_CAPABILITY = 'ufsc_competition_fight_generate';
	public const FIGHT_REGENERATE_CAPABILITY = 'ufsc_competition_fight_regenerate';
	public const PLATEAU_MANAGE_CAPABILITY = 'ufsc_competition_plateau_manage';
	public const RESULT_RECORD_CAPABILITY = 'ufsc_competition_result_record';
	public const RESULT_CORRECT_CAPABILITY = 'ufsc_competition_result_correct';
	public const AUDIT_VIEW_CAPABILITY = 'ufsc_competition_audit_view';
	public const SENSITIVE_OPS_CAPABILITY = 'ufsc_competition_sensitive_ops';
	public const DELETE_PERMANENT_CAPABILITY = 'ufsc_competition_delete_permanent';
	public const MANAGE_ALL_CAPABILITY = 'ufsc_comp_manage_all';
	public const MANAGE_REGION_CAPABILITY = 'ufsc_comp_manage_region';
	public const VIEW_REGION_CAPABILITY = 'ufsc_comp_view_region';

	public static function user_can_read(): bool {
		return \ufsc_lc_user_can( self::READ_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_create(): bool {
		return \ufsc_lc_user_can( self::CREATE_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_edit(): bool {
		return \ufsc_lc_user_can( self::EDIT_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_manage_entries(): bool {
		return \ufsc_lc_user_can( self::ENTRIES_MANAGE_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_export(): bool {
		return \ufsc_lc_user_can( self::EXPORT_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_manage() {
		if ( \ufsc_lc_user_can( self::MANAGE_ALL_CAPABILITY ) || \ufsc_lc_user_can( self::MANAGE_REGION_CAPABILITY ) ) {
			return true;
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY' ) ) {
			if ( \ufsc_lc_user_can( \UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY ) ) {
				return true;
			}
		}

		return \ufsc_lc_user_can( self::EDIT_CAPABILITY );
	}

	public static function user_can_delete() {
		return \ufsc_lc_user_can( self::DELETE_CAPABILITY ) || self::user_can_manage();
	}

	public static function current_user_can( string $capability, int $competition_id = 0 ): bool {
		$capability = sanitize_key( $capability );
		if ( '' === $capability ) {
			return false;
		}

		if ( \ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		$canonical_capability = self::get_canonical_capability_for( $capability );
		if ( $canonical_capability && $canonical_capability !== $capability && \ufsc_lc_user_can( $canonical_capability ) ) {
			return true;
		}

		// Backward compatibility: WordPress administrators and legacy UFSC competition
		// super-managers keep Lot 1 rights. Canonical mapping above also keeps
		// limited competition managers working when UFSC Gestion only exposes the
		// ufsc_competitions_* capabilities.
		return self::user_is_legacy_super_manager();
	}

	private static function get_canonical_capability_for( string $capability ): string {
		$read_caps = array(
			'ufsc_competition_read',
			self::AUDIT_VIEW_CAPABILITY,
			self::VIEW_REGION_CAPABILITY,
		);

		if ( in_array( $capability, $read_caps, true ) ) {
			return self::READ_CAPABILITY;
		}

		$manage_caps = array(
			'ufsc_competition_create',
			'ufsc_competition_edit',
			'ufsc_competition_manage',
			'ufsc_manage_competitions',
			self::ENTRIES_MANAGE_CAPABILITY,
			self::EXPORT_CAPABILITY,
			self::VALIDATE_CAPABILITY,
			self::DELETE_CAPABILITY,
			self::WEIGHIN_MANAGE_CAPABILITY,
			self::WEIGHIN_OVERRIDE_CAPABILITY,
			self::FIGHT_MANAGE_CAPABILITY,
			self::FIGHT_GENERATE_CAPABILITY,
			self::FIGHT_REGENERATE_CAPABILITY,
			self::PLATEAU_MANAGE_CAPABILITY,
			self::RESULT_RECORD_CAPABILITY,
			self::RESULT_CORRECT_CAPABILITY,
			self::SENSITIVE_OPS_CAPABILITY,
			self::DELETE_PERMANENT_CAPABILITY,
			self::MANAGE_ALL_CAPABILITY,
			self::MANAGE_REGION_CAPABILITY,
		);

		if ( in_array( $capability, $manage_caps, true ) ) {
			return self::EDIT_CAPABILITY;
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY' ) ) {
			if ( $capability === \UFSC_LC_Capabilities::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY ) {
				return self::EDIT_CAPABILITY;
			}
		}

		return '';
	}

	private static function user_is_legacy_super_manager(): bool {
		if ( \ufsc_lc_user_can( 'manage_options' ) || \ufsc_lc_user_can( self::MANAGE_ALL_CAPABILITY ) ) {
			return true;
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::MANAGE_CAPABILITY' ) ) {
			if ( \ufsc_lc_user_can( \UFSC_LC_Capabilities::MANAGE_CAPABILITY ) ) {
				return true;
			}
		}

		return false;
	}

	public static function user_can_manage_weighins(): bool {
		return self::current_user_can( self::WEIGHIN_MANAGE_CAPABILITY ) || self::user_can_manage_entries();
	}

	public static function user_can_override_weighins(): bool {
		return self::current_user_can( self::WEIGHIN_OVERRIDE_CAPABILITY ) || self::current_user_can( self::SENSITIVE_OPS_CAPABILITY );
	}

	public static function user_can_manage_fights(): bool {
		return self::current_user_can( self::FIGHT_MANAGE_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_generate_fights(): bool {
		return self::current_user_can( self::FIGHT_GENERATE_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_regenerate_fights(): bool {
		return self::current_user_can( self::FIGHT_REGENERATE_CAPABILITY ) || self::current_user_can( self::SENSITIVE_OPS_CAPABILITY );
	}

	public static function user_can_record_results(): bool {
		return self::current_user_can( self::RESULT_RECORD_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_correct_results(): bool {
		return self::current_user_can( self::RESULT_CORRECT_CAPABILITY ) || self::current_user_can( self::SENSITIVE_OPS_CAPABILITY );
	}

	public static function user_can_permanently_delete(): bool {
		return self::current_user_can( self::DELETE_PERMANENT_CAPABILITY );
	}

	public static function get_read_capability(): string {
		return self::READ_CAPABILITY;
	}

	public static function get_create_capability(): string {
		return self::CREATE_CAPABILITY;
	}

	public static function get_edit_capability(): string {
		return self::EDIT_CAPABILITY;
	}

	public static function get_entries_manage_capability(): string {
		return self::ENTRIES_MANAGE_CAPABILITY;
	}

	public static function get_export_capability(): string {
		return self::EXPORT_CAPABILITY;
	}

	public static function get_validate_entries_capability(): string {
		return self::EDIT_CAPABILITY;
	}

	public static function user_can_validate_entries(): bool {
		$capability = self::get_validate_entries_capability();
		if ( $capability && \ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}
}
