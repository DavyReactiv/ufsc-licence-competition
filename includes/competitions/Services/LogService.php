<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogService {
	public function log( $action, $object_type, $object_id, $message, array $context = array() ) {
		global $wpdb;

		$action = is_scalar( $action ) ? (string) $action : '';
		$object_type = is_scalar( $object_type ) ? (string) $object_type : '';
		$message = is_scalar( $message ) ? (string) $message : '';

		$wpdb->insert(
			Db::logs_table(),
			array(
				'action'      => sanitize_text_field( $action ),
				'object_type' => sanitize_text_field( $object_type ),
				'object_id'   => $object_id ? absint( $object_id ) : null,
				'message'     => sanitize_text_field( $message ),
				'context'     => $context ? wp_json_encode( $context ) : null,
				'user_id'     => get_current_user_id() ?: null,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( class_exists( 'UFSC_LC_Logger' ) ) {
			\UFSC_LC_Logger::log( $message, $context );
		}
	}
}
