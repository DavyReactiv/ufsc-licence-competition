<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	public const READ_CAPABILITY = 'ufsc_competition_read';
	public const CREATE_CAPABILITY = 'ufsc_competition_create';
	public const EDIT_CAPABILITY = 'ufsc_competition_edit';
	public const ENTRIES_MANAGE_CAPABILITY = 'ufsc_competition_entries_manage';
	public const EXPORT_CAPABILITY = 'ufsc_competition_export';
	public const VALIDATE_CAPABILITY = 'ufsc_competition_validate';
	public const DELETE_CAPABILITY = 'ufsc_competition_delete';
	public const MANAGE_ALL_CAPABILITY = 'ufsc_comp_manage_all';
	public const MANAGE_REGION_CAPABILITY = 'ufsc_comp_manage_region';
	public const VIEW_REGION_CAPABILITY = 'ufsc_comp_view_region';

	public static function user_can_read(): bool {
		return current_user_can( self::READ_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_create(): bool {
		return current_user_can( self::CREATE_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_edit(): bool {
		return current_user_can( self::EDIT_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_manage_entries(): bool {
		return current_user_can( self::ENTRIES_MANAGE_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_export(): bool {
		return current_user_can( self::EXPORT_CAPABILITY ) || self::user_can_manage();
	}

	public static function user_can_manage() {
		if ( current_user_can( self::MANAGE_ALL_CAPABILITY ) || current_user_can( self::MANAGE_REGION_CAPABILITY ) ) {
			return true;
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY' ) ) {
			if ( current_user_can( \UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY ) ) {
				return true;
			}
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) ) {
			return \UFSC_LC_Capabilities::user_can_manage();
		}

		return current_user_can( self::EDIT_CAPABILITY );
	}

	public static function user_can_delete() {
		return current_user_can( self::DELETE_CAPABILITY );
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
		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY' ) ) {
			return \UFSC_LC_Capabilities::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY;
		}

		return self::VALIDATE_CAPABILITY;
	}

	public static function user_can_validate_entries(): bool {
		$capability = self::get_validate_entries_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}
}
