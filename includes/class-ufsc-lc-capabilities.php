<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Capabilities {
	const READ_CAPABILITY = 'ufsc_licences_read';
	const CREATE_CAPABILITY = 'ufsc_licences_manage';
	const EDIT_CAPABILITY = 'ufsc_licences_manage';
	const VALIDATE_CAPABILITY = 'ufsc_licences_manage';
	const DELETE_CAPABILITY = 'ufsc_licences_manage';
	const SCOPE_ALL_REGIONS_CAPABILITY = 'ufsc_all_regions_access';
	const MANAGE_CAPABILITY = 'ufsc_licences_manage';
	const MANAGE_READ_CAPABILITY = 'ufsc_licences_read';
	const IMPORT_CAPABILITY = 'ufsc_licences_manage';
	const EXPORT_CAPABILITY = 'ufsc_licences_manage';
	const LEGACY_CAPABILITY = 'ufsc_manage_licences';
	const COMPETITIONS_CAPABILITY = 'ufsc_competitions_manage';
	const COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY = 'ufsc_competitions_validate_entries';

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( ! $role ) {
			return;
		}

		foreach (
			array(
				self::READ_CAPABILITY,
				self::MANAGE_CAPABILITY,
				self::COMPETITIONS_CAPABILITY,
				'ufsc_competitions_read',
				self::SCOPE_ALL_REGIONS_CAPABILITY,
			) as $capability
		) {
			if ( ! $role->has_cap( $capability ) ) {
				$role->add_cap( $capability );
			}
		}
	}

	public static function remove_caps() {
		// Canonical UFSC capabilities are owned by UFSC Gestion / WordPress roles.
		// Keep them untouched on deactivation to avoid locking administrators out.
	}

	public static function user_can_read() {
		$capability = self::get_read_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_create() {
		$capability = self::get_create_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_edit() {
		$capability = self::get_edit_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_manage() {
		$capability = self::get_manage_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		if ( function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( self::EDIT_CAPABILITY ) ) {
			return true;
		}

		if ( function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( self::LEGACY_CAPABILITY ) ) {
			return true;
		}

		return false;
	}

	public static function user_can_manage_read() {
		$capability = self::get_manage_read_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_manage_competitions() {
		if ( function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( self::COMPETITIONS_CAPABILITY ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_import() {
		$capability = self::get_import_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_export() {
		$capability = self::get_export_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_validate() {
		$capability = self::get_validate_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_delete() {
		$capability = self::get_delete_capability();
		if ( $capability && function_exists( 'ufsc_lc_user_can' ) && ufsc_lc_user_can( $capability ) ) {
			return true;
		}

		return false;
	}

	public static function get_read_capability() {
		return self::READ_CAPABILITY;
	}

	public static function get_create_capability() {
		return self::CREATE_CAPABILITY;
	}

	public static function get_edit_capability() {
		return self::EDIT_CAPABILITY;
	}

	public static function get_manage_capability() {
		if ( class_exists( 'UFSC_LC_Settings_Page' ) ) {
			return UFSC_LC_Settings_Page::get_manage_capability();
		}

		return self::EDIT_CAPABILITY;
	}

	public static function get_manage_read_capability() {
		return self::MANAGE_READ_CAPABILITY;
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

	public static function get_validate_capability() {
		return self::VALIDATE_CAPABILITY;
	}

	public static function get_delete_capability() {
		return self::DELETE_CAPABILITY;
	}
}
