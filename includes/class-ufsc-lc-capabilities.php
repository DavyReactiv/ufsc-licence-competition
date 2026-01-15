<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Capabilities {
	const CAPABILITY = 'ufsc_manage_licences';

	public static function add_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( self::CAPABILITY );
		}
	}

	public static function remove_caps() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->remove_cap( self::CAPABILITY );
		}
	}

	public static function user_can_manage() {
		if ( current_user_can( self::CAPABILITY ) ) {
			return true;
		}

		return current_user_can( 'manage_options' );
	}
}
