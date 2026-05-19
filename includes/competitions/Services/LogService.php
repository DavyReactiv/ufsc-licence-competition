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

	public function audit( string $action, int $competition_id, string $object_type, int $object_id = 0, array $payload = array(), string $message = '' ): void {
		$action = sanitize_key( $action );
		if ( '' === $action ) {
			return;
		}

		$context = array(
			'audit'          => true,
			'action'         => $action,
			'competition_id' => absint( $competition_id ),
			'object_type'    => sanitize_key( $object_type ),
			'object_id'      => absint( $object_id ),
			'user_id'        => get_current_user_id() ?: null,
			'timestamp'      => current_time( 'mysql' ),
			'payload'        => $this->sanitize_payload( $payload ),
			'request_context'=> $this->get_request_context(),
		);

		if ( '' === $message ) {
			$message = sprintf( 'Audit action: %s', $action );
		}

		$this->log( $action, $object_type, $object_id ?: $competition_id, $message, $context );
	}

	private function sanitize_payload( array $payload ): array {
		$clean = array();
		foreach ( $payload as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean[ $key ] = $this->sanitize_payload_value( $value );
		}
		return $clean;
	}

	private function sanitize_payload_value( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$out[ is_int( $key ) ? $key : sanitize_key( (string) $key ) ] = $this->sanitize_payload_value( $item );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return $this->sanitize_payload_value( get_object_vars( $value ) );
		}
		if ( is_string( $value ) ) {
			return sanitize_textarea_field( $value );
		}
		return is_scalar( $value ) || null === $value ? $value : '';
	}

	private function get_request_context(): array {
		$context = array(
			'method' => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'page'   => isset( $_REQUEST['page'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['page'] ) ) : '',
		);
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$context['user_agent'] = substr( sanitize_text_field( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 190 );
		}
		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			$context['ip'] = sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return $context;
	}

}
