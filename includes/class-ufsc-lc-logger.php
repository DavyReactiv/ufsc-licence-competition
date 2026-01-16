<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Logger {
	public static function log( $message, $context = array() ) {
		$logging_forced = class_exists( 'UFSC_LC_Settings_Page' ) && UFSC_LC_Settings_Page::is_logging_enabled();
		if ( ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) && ! $logging_forced ) {
			return;
		}

		if ( class_exists( 'UFSC_Audit_Logger' ) && method_exists( 'UFSC_Audit_Logger', 'log' ) ) {
			UFSC_Audit_Logger::log( $message, $context );
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log(
			sprintf(
				__( '[UFSC LC] %s %s', 'ufsc-licence-competition' ),
				$message,
				$payload
			)
		);
	}
}
