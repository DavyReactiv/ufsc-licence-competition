<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Capabilities {
	const READ_CAPABILITY = 'ufsc_licence_read';
	const CREATE_CAPABILITY = 'ufsc_licence_create';
	const EDIT_CAPABILITY = 'ufsc_licence_edit';
	const VALIDATE_CAPABILITY = 'ufsc_licence_validate';
	const DELETE_CAPABILITY = 'ufsc_licence_delete';
	const SCOPE_ALL_REGIONS_CAPABILITY = 'ufsc_scope_all_regions';
	const MANAGE_CAPABILITY = 'ufsc_lc_manage';
	const MANAGE_READ_CAPABILITY = 'ufsc_manage_read';
	const IMPORT_CAPABILITY = 'ufsc_lc_import';
	const EXPORT_CAPABILITY = 'ufsc_lc_export';
	const LEGACY_CAPABILITY = 'ufsc_manage_licences';
	const COMPETITIONS_CAPABILITY = 'ufsc_competitions_manage';
	const COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY = 'ufsc_competitions_validate_entries';

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::READ_CAPABILITY );
			$role->add_cap( self::CREATE_CAPABILITY );
			$role->add_cap( self::EDIT_CAPABILITY );
			$role->add_cap( self::VALIDATE_CAPABILITY );
			$role->add_cap( self::DELETE_CAPABILITY );
			$role->add_cap( self::SCOPE_ALL_REGIONS_CAPABILITY );
			$role->add_cap( self::MANAGE_CAPABILITY );
			$role->add_cap( self::MANAGE_READ_CAPABILITY );
			$role->add_cap( self::IMPORT_CAPABILITY );
			$role->add_cap( self::EXPORT_CAPABILITY );
			$role->add_cap( self::COMPETITIONS_CAPABILITY );
			$role->add_cap( self::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY );
			$role->add_cap( 'ufsc_competition_read' );
			$role->add_cap( 'ufsc_competition_create' );
			$role->add_cap( 'ufsc_competition_edit' );
			$role->add_cap( 'ufsc_competition_entries_manage' );
			$role->add_cap( 'ufsc_competition_export' );
			$role->add_cap( 'ufsc_competition_validate' );
			$role->add_cap( 'ufsc_competition_delete' );
		}

		if ( ! get_role( 'ufsc_responsable_ligue' ) ) {
			add_role(
				'ufsc_responsable_ligue',
				__( 'Responsable de Ligue', 'ufsc-licence-competition' ),
				array(
					self::READ_CAPABILITY => true,
					self::CREATE_CAPABILITY => true,
					self::EDIT_CAPABILITY => true,
					self::EXPORT_CAPABILITY => true,
					self::VALIDATE_CAPABILITY => true,
					self::MANAGE_READ_CAPABILITY => true,
					'ufsc_competition_read' => true,
					'ufsc_competition_create' => true,
					'ufsc_competition_edit' => true,
					'ufsc_competition_entries_manage' => true,
					'ufsc_competition_export' => true,
					'ufsc_competition_validate' => true,
				)
			);
		}
	}

	public static function remove_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->remove_cap( self::READ_CAPABILITY );
			$role->remove_cap( self::CREATE_CAPABILITY );
			$role->remove_cap( self::EDIT_CAPABILITY );
			$role->remove_cap( self::VALIDATE_CAPABILITY );
			$role->remove_cap( self::DELETE_CAPABILITY );
			$role->remove_cap( self::SCOPE_ALL_REGIONS_CAPABILITY );
			$role->remove_cap( self::MANAGE_CAPABILITY );
			$role->remove_cap( self::MANAGE_READ_CAPABILITY );
			$role->remove_cap( self::IMPORT_CAPABILITY );
			$role->remove_cap( self::EXPORT_CAPABILITY );
			$role->remove_cap( self::LEGACY_CAPABILITY );
			$role->remove_cap( self::COMPETITIONS_CAPABILITY );
			$role->remove_cap( self::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY );
			$role->remove_cap( 'ufsc_competition_read' );
			$role->remove_cap( 'ufsc_competition_create' );
			$role->remove_cap( 'ufsc_competition_edit' );
			$role->remove_cap( 'ufsc_competition_entries_manage' );
			$role->remove_cap( 'ufsc_competition_export' );
			$role->remove_cap( 'ufsc_competition_validate' );
			$role->remove_cap( 'ufsc_competition_delete' );
		}
	}

	public static function user_can_read() {
		$capability = self::get_read_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_create() {
		$capability = self::get_create_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_edit() {
		$capability = self::get_edit_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_manage() {
		$capability = self::get_manage_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		if ( current_user_can( self::EDIT_CAPABILITY ) ) {
			return true;
		}

		if ( current_user_can( self::LEGACY_CAPABILITY ) ) {
			return true;
		}

		return false;
	}

	public static function user_can_manage_read() {
		$capability = self::get_manage_read_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
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

	public static function user_can_validate() {
		$capability = self::get_validate_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}

	public static function user_can_delete() {
		$capability = self::get_delete_capability();
		if ( $capability && current_user_can( $capability ) ) {
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
