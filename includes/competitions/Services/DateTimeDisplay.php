<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DateTimeDisplay {
	public static function format( $value, string $fallback = '—', string $mode = 'datetime' ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return $fallback;
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}(:\\d{2})?$/', $value ) ) {
			$value = str_replace( 'T', ' ', $value );
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			$timezone = function_exists( 'wp_timezone' )
				? wp_timezone()
				: new \DateTimeZone( wp_timezone_string() ?: 'UTC' );
			$date = date_create( $value, $timezone );
			$timestamp = $date instanceof \DateTimeInterface ? $date->getTimestamp() : 0;
		}

		if ( ! $timestamp ) {
			return $fallback;
		}

		switch ( $mode ) {
			case 'date':
				$format = 'd/m/Y';
				break;
			case 'long':
				$format = 'l d F Y \\à H\\hi';
				break;
			case 'datetime':
			default:
				$format = 'd/m/Y \\à H:i';
				break;
		}

		return date_i18n( $format, $timestamp );
	}
}
