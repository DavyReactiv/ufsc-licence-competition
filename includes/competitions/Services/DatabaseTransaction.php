<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Lightweight transaction wrapper for multi-write competition operations. */
class DatabaseTransaction {
	private static $depth = 0;

	public static function run( callable $callback ) {
		global $wpdb;

		$outermost = 0 === self::$depth;
		if ( $outermost ) {
			$started = $wpdb->query( 'START TRANSACTION' );
			if ( false === $started ) {
				throw new \RuntimeException( 'Unable to start database transaction.' );
			}
		}

		++self::$depth;
		try {
			$result = $callback();
			--self::$depth;

			if ( $outermost ) {
				$committed = $wpdb->query( 'COMMIT' );
				if ( false === $committed ) {
					$wpdb->query( 'ROLLBACK' );
					throw new \RuntimeException( 'Unable to commit database transaction.' );
				}
			}

			return $result;
		} catch ( \Throwable $error ) {
			self::$depth = max( 0, self::$depth - 1 );
			if ( $outermost ) {
				$wpdb->query( 'ROLLBACK' );
			}
			throw $error;
		}
	}
}
