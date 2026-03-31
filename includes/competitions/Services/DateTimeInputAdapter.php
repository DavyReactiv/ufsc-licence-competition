<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateTimeInputAdapter {
	public static function to_sql_datetime( string $date, string $time = '' ): string {
		$date = trim( $date );
		$time = trim( $time );

		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$time = '' !== $time ? $time : '00:00';
			return self::normalize_sql_datetime( $date . ' ' . $time . ':00' );
		}

		if ( preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $date ) ) {
			$parts = explode( '/', $date );
			if ( 3 === count( $parts ) ) {
				$iso_date = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
				$time = '' !== $time ? $time : '00:00';
				return self::normalize_sql_datetime( $iso_date . ' ' . $time . ':00' );
			}
		}

		return self::normalize_sql_datetime( $date );
	}

	public static function normalize_sql_datetime( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$raw = preg_replace( '/\s+/', ' ', $raw );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ) {
			$raw .= ' 00:00:00';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $raw ) ) {
			$raw .= ':00';
		}

		$date = date_create_from_format( 'Y-m-d H:i:s', $raw );
		if ( ! $date instanceof \DateTimeInterface ) {
			return '';
		}

		$normalized = $date->format( 'Y-m-d H:i:s' );

		return $normalized === $raw ? $normalized : '';
	}
}
