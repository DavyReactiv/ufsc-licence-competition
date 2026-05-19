<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GenerationLockService {
	private const OPTION_PREFIX = 'ufsc_competition_generation_lock_';

	public static function get_lock( int $competition_id ): array {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return array();
		}

		$lock = get_option( self::OPTION_PREFIX . $competition_id, array() );
		return is_array( $lock ) ? $lock : array();
	}

	public static function is_generation_locked( int $competition_id ): bool {
		$lock = self::get_lock( $competition_id );
		return ! empty( $lock['generation_locked_at'] );
	}

	public static function lock_after_generation( int $competition_id, array $context = array() ): array {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return array();
		}

		$now  = current_time( 'mysql' );
		$lock = array_merge(
			self::get_lock( $competition_id ),
			array(
				'competition_id'       => $competition_id,
				'generation_locked_at' => $now,
				'generation_locked_by' => get_current_user_id() ?: null,
				'entries_locked_at'    => $now,
				'categories_locked_at' => $now,
				'context'              => self::sanitize_context( $context ),
			)
		);

		update_option( self::OPTION_PREFIX . $competition_id, $lock, false );

		return $lock;
	}

	private static function sanitize_context( array $context ): array {
		$clean = array();
		foreach ( $context as $key => $value ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$clean[ $key ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
			}
		}
		return $clean;
	}
}
