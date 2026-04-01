<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateTimeInputAdapter {
	public static function to_sql_datetime( string $date, string $time = '' ): string {
		$date = trim( $date );
		$time = self::normalize_time_input( $time );

		if ( '' === $date ) {
			return '';
		}

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$time = '' !== $time ? $time : '00:00';
			return self::normalize_sql_datetime( $date . ' ' . $time );
		}

		if ( preg_match( '/^\d{2}\/\d{2}\/\d{4}$/', $date ) ) {
			$parts = explode( '/', $date );
			if ( 3 === count( $parts ) ) {
				$iso_date = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
				$time = '' !== $time ? $time : '00:00';
				return self::normalize_sql_datetime( $iso_date . ' ' . $time );
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
		$raw = str_replace( 'T', ' ', $raw );

		$formats = array(
			'Y-m-d H:i:s',
			'Y-m-d H:i',
			'Y-m-d',
			'd/m/Y H:i:s',
			'd/m/Y H:i',
			'd/m/Y',
			'd-m-Y H:i:s',
			'd-m-Y H:i',
			'd-m-Y',
		);

		foreach ( $formats as $format ) {
			$date = date_create_from_format( $format, $raw );
			if ( ! $date instanceof \DateTimeInterface ) {
				continue;
			}
			$errors = \DateTime::getLastErrors();
			if ( is_array( $errors ) && ( ! empty( $errors['warning_count'] ) || ! empty( $errors['error_count'] ) ) ) {
				continue;
			}

			$normalized = $date->format( 'Y-m-d H:i:s' );
			$check = $date->format( $format );
			if ( $check === $raw ) {
				return $normalized;
			}
		}

		return '';
	}

	private static function normalize_time_input( string $time ): string {
		$time = trim( $time );
		if ( '' === $time ) {
			return '';
		}

		if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
			return $time . ':00';
		}

		if ( preg_match( '/^\d{2}:\d{2}:\d{2}$/', $time ) ) {
			return $time;
		}

		return $time;
	}
}
