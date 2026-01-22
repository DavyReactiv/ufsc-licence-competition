<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Capabilities {
	const MANAGE_CAPABILITY = 'ufsc_lc_manage';
	const IMPORT_CAPABILITY = 'ufsc_lc_import';
	const EXPORT_CAPABILITY = 'ufsc_lc_export';
	const LEGACY_CAPABILITY = 'ufsc_manage_licences';
	const COMPETITIONS_CAPABILITY = 'ufsc_competitions_manage';
	const COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY = 'ufsc_competitions_validate_entries';

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::MANAGE_CAPABILITY );
			$role->add_cap( self::IMPORT_CAPABILITY );
			$role->add_cap( self::EXPORT_CAPABILITY );
			$role->add_cap( self::COMPETITIONS_CAPABILITY );
			$role->add_cap( self::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY );
		}
	}

	public static function remove_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->remove_cap( self::MANAGE_CAPABILITY );
			$role->remove_cap( self::IMPORT_CAPABILITY );
			$role->remove_cap( self::EXPORT_CAPABILITY );
			$role->remove_cap( self::LEGACY_CAPABILITY );
			$role->remove_cap( self::COMPETITIONS_CAPABILITY );
			$role->remove_cap( self::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY );
		}
	}

	public static function user_can_manage() {
		$capability = self::get_manage_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		if ( current_user_can( self::LEGACY_CAPABILITY ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}

	public static function user_can_manage_competitions() {
		if ( current_user_can( self::COMPETITIONS_CAPABILITY ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_import() {
		$capability = self::get_import_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_export() {
		$capability = self::get_export_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function get_manage_capability() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_manage_capability();
		}

		return self::MANAGE_CAPABILITY;
	}

	public static function get_import_capability() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_import_capability();
		}

		return self::IMPORT_CAPABILITY;
	}

	public static function get_export_capability() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_export_capability();
		}

		return self::EXPORT_CAPABILITY;
	}
}
