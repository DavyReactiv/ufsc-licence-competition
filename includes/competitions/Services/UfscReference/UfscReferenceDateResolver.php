<?php

namespace UFSC\Competitions\Services\UfscReference;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UfscReferenceDateResolver {
	public static function resolve(array $context = array()): \DateTimeImmutable {
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );

		foreach ( array( 'reference_date', 'competition_date', 'event_start_datetime', 'age_reference_date' ) as $key ) {
			$raw = isset( $context[ $key ] ) ? (string) $context[ $key ] : '';
			$date = self::from_string( $raw, $timezone );
			if ( $date ) {
				return $date;
			}
		}

		$season_end_year = isset( $context['season_end_year'] ) ? (int) $context['season_end_year'] : 0;
		$age_reference = isset( $context['age_reference'] ) ? (string) $context['age_reference'] : '12-31';
		if ( $season_end_year > 0 && preg_match( '/^\d{2}-\d{2}$/', $age_reference ) ) {
			$date = self::from_string( $season_end_year . '-' . $age_reference, $timezone );
			if ( $date ) {
				return $date;
			}
		}

		return new \DateTimeImmutable( 'now', $timezone );
	}

	private static function from_string( string $raw, \DateTimeZone $timezone ): ?\DateTimeImmutable {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $raw, $timezone );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
