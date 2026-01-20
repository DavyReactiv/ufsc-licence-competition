<?php

namespace UFSC\Competitions\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small helper trait for repositories to log DB errors when WP_DEBUG is enabled
 * or logging explicitly allowed. Logs $wpdb->last_error and $wpdb->last_query only.
 *
 * No sensitive data (POST/GET) is included.
 */
trait RepositoryHelpers {
	/**
	 * Log last DB error (if any) for the calling repository.
	 *
	 * @param string $context Short context label (method/operation).
	 * @return void
	 */
	protected function maybe_log_db_error( $context = '' ) {
		global $wpdb;

		if ( empty( $wpdb->last_error ) ) {
			return;
		}

		$logging_enabled = ( defined( 'WP_DEBUG' ) && WP_DEBUG );
		// If plugin exposes a settings flag for logging, allow it as well.
		if ( ! $logging_enabled && class_exists( '\\UFSC_LC_Settings_Page' ) && method_exists( '\\UFSC_LC_Settings_Page', 'is_logging_enabled' ) ) {
			$logging_enabled = \UFSC_LC_Settings_Page::is_logging_enabled();
		}

		if ( ! $logging_enabled ) {
			return;
		}

		$msg = sprintf( 'UFSC Competitions DB error [%s]: %s', $context, $wpdb->last_error );

		$context_data = array(
			'last_query' => is_string( $wpdb->last_query ) ? $wpdb->last_query : '',
		);

		if ( class_exists( '\\UFSC_LC_Logger' ) ) {
			\UFSC_LC_Logger::log( $msg, $context_data );
		} else {
			error_log( $msg . ' -- query: ' . $context_data['last_query'] );
		}
	}
}
