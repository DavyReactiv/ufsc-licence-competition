<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Logger {
	public static function log( $message, $context = array() ) {
		if ( class_exists( 'UFSC_Audit_Logger' ) && method_exists( 'UFSC_Audit_Logger', 'log' ) ) {
			UFSC_Audit_Logger::log( $message, $context );
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( sprintf( '[UFSC_LC] %s %s', $message, $payload ) );
	}
}
