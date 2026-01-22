<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Capabilities {
	public static function user_can_manage() {
		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY' ) ) {
			if ( current_user_can( \UFSC_LC_Capabilities::COMPETITIONS_CAPABILITY ) ) {
				return true;
			}
		}

		if ( class_exists( 'UFSC_LC_Capabilities' ) ) {
			return \UFSC_LC_Capabilities::user_can_manage();
		}

		return current_user_can( 'manage_options' );
	}

	public static function user_can_delete() {
		return current_user_can( 'manage_options' );
	}

	public static function get_validate_entries_capability(): string {
		if ( class_exists( 'UFSC_LC_Capabilities' ) && defined( 'UFSC_LC_Capabilities::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY' ) ) {
			return \UFSC_LC_Capabilities::COMPETITIONS_VALIDATE_ENTRIES_CAPABILITY;
		}

		return 'manage_options';
	}

	public static function user_can_validate_entries(): bool {
		$capability = self::get_validate_entries_capability();
		if ( $capability && current_user_can( $capability ) ) {
			return true;
		}

		return self::user_can_manage();
	}
}
