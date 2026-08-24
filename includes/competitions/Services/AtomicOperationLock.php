<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic short-lived lock based on WordPress option-name uniqueness.
 * Prevents double generation / simultaneous result writes without a new table.
 */
class AtomicOperationLock {
	private const PREFIX = 'ufsc_comp_lock_';

	public static function acquire( string $lock_name, int $ttl = 30 ): string {
		$lock_name = trim( $lock_name );
		if ( '' === $lock_name ) {
			return '';
		}

		$ttl     = max( 5, min( 300, $ttl ) );
		$key     = self::option_key( $lock_name );
		$token   = wp_generate_uuid4();
		$user_id = get_current_user_id();
		$data    = array(
			'token'      => $token,
			'expires_at' => time() + $ttl,
			'user_id'    => $user_id ? $user_id : 0,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);

		if ( add_option( $key, $data, '', false ) ) {
			return $token;
		}

		$existing = get_option( $key, array() );
		$expires  = is_array( $existing ) ? absint( $existing['expires_at'] ?? 0 ) : 0;
		if ( $expires > 0 && $expires < time() ) {
			delete_option( $key );
			if ( add_option( $key, $data, '', false ) ) {
				return $token;
			}
		}

		return '';
	}

	public static function release( string $lock_name, string $token ): void {
		if ( '' === trim( $lock_name ) || '' === trim( $token ) ) {
			return;
		}

		$key      = self::option_key( $lock_name );
		$existing = get_option( $key, array() );
		if ( ! is_array( $existing ) || ! hash_equals( (string) ( $existing['token'] ?? '' ), $token ) ) {
			return;
		}

		delete_option( $key );
	}

	public static function describe( string $lock_name ): array {
		$data = get_option( self::option_key( $lock_name ), array() );
		return is_array( $data ) ? $data : array();
	}

	private static function option_key( string $lock_name ): string {
		return self::PREFIX . md5( $lock_name );
	}
}
