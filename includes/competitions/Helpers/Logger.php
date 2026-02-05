<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_comp_log' ) ) {
	function ufsc_comp_log( string $message, array $context = array() ): void {
		$enabled = ( defined( 'UFSC_LC_DEBUG' ) && UFSC_LC_DEBUG )
			|| ( defined( 'WP_DEBUG' ) && WP_DEBUG );

		if ( ! $enabled ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( sprintf( '[UFSC_COMP] %s %s', $message, $payload ) );
	}
}
