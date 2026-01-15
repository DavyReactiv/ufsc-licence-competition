<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Categories {
	const CATEGORY_OUT_OF_RANGE = 'Hors catégorie';
	const MIN_SEASON_YEAR = 1900;
	const MAX_SEASON_YEAR = 2100;

	public static function season_ref_date( $season_end_year ) {
		$year = self::sanitize_season_end_year( $season_end_year );
		if ( null === $year ) {
			return null;
		}

		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', sprintf( '%d-12-31', $year ), $timezone );

		return $date ?: null;
	}

	public static function age_at( $birthdate, DateTimeInterface $reference_date ) {
		$birth_date = self::parse_birthdate( $birthdate );
		if ( ! $birth_date ) {
			return null;
		}

		if ( $birth_date > $reference_date ) {
			return null;
		}

		$diff = $birth_date->diff( $reference_date );

		return (int) $diff->y;
	}

	public static function category_from_birthdate( $birthdate, $season_end_year ) {
		$reference_date = self::season_ref_date( $season_end_year );
		if ( ! $reference_date ) {
			return array(
				'category' => self::CATEGORY_OUT_OF_RANGE,
				'age'      => null,
			);
		}

		$age = self::age_at( $birthdate, $reference_date );
		if ( null === $age ) {
			return array(
				'category' => self::CATEGORY_OUT_OF_RANGE,
				'age'      => null,
			);
		}

		return array(
			'category' => self::category_from_age( $age ),
			'age'      => $age,
		);
	}

	public static function sanitize_season_end_year( $value ) {
		$year = absint( $value );
		if ( ! $year ) {
			return null;
		}

		if ( $year < self::MIN_SEASON_YEAR || $year > self::MAX_SEASON_YEAR ) {
			return null;
		}

		return $year;
	}

	private static function category_from_age( $age ) {
		if ( $age >= 6 && $age <= 7 ) {
			return 'Poussins';
		}
		if ( $age >= 8 && $age <= 9 ) {
			return 'Pupilles';
		}
		if ( $age >= 10 && $age <= 11 ) {
			return 'Benjamins';
		}
		if ( $age >= 12 && $age <= 13 ) {
			return 'Minimes';
		}
		if ( $age >= 14 && $age <= 15 ) {
			return 'Cadets';
		}
		if ( $age >= 16 && $age <= 17 ) {
			return 'Juniors';
		}
		if ( $age >= 18 && $age <= 40 ) {
			return 'Séniors';
		}
		if ( $age >= 41 && $age <= 50 ) {
			return 'Vétérans';
		}

		return self::CATEGORY_OUT_OF_RANGE;
	}

	private static function parse_birthdate( $birthdate ) {
		$birthdate = trim( (string) $birthdate );
		if ( '' === $birthdate ) {
			return null;
		}

		$formats = array( 'Y-m-d', 'd/m/Y', 'd-m-Y' );
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		foreach ( $formats as $format ) {
			$parsed = DateTimeImmutable::createFromFormat( '!' . $format, $birthdate, $timezone );
			if ( $parsed && $parsed->format( $format ) === $birthdate ) {
				return $parsed;
			}
		}

		$timestamp = strtotime( $birthdate );
		if ( false !== $timestamp ) {
			return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $timezone );
		}

		return null;
	}
}
