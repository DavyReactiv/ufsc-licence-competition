<?php

namespace UFSC\Competitions\Entries;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryDataNormalizer {
	private static $debug_samples = 0;

	public static function get_text_value( $item, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = self::read_item_value( $item, $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}

	public static function resolve_last_name( $item ): string {
		$last = self::get_text_value( $item, array( 'licensee_last_name', 'last_name', 'lastname', 'nom', 'family_name' ) );
		if ( '' !== $last ) {
			return $last;
		}

		$split = self::split_participant_name(
			self::get_text_value( $item, array( 'participant_name', 'athlete_name', 'full_name', 'name', 'licensee_name' ) )
		);

		return $split['last'];
	}

	public static function resolve_first_name( $item ): string {
		$first = self::get_text_value( $item, array( 'licensee_first_name', 'first_name', 'firstname', 'prenom', 'given_name' ) );
		if ( '' !== $first ) {
			return $first;
		}

		$split = self::split_participant_name(
			self::get_text_value( $item, array( 'participant_name', 'athlete_name', 'full_name', 'name', 'licensee_name' ) )
		);

		return $split['first'];
	}

	public static function resolve_display_name( $item ): string {
		$last  = self::resolve_last_name( $item );
		$first = self::resolve_first_name( $item );
		$name  = trim( $last . ' ' . $first );
		if ( '' !== $name ) {
			return $name;
		}

		return self::get_text_value( $item, array( 'participant_name', 'athlete_name', 'full_name', 'name', 'licensee_name' ) );
	}

	public static function resolve_birth_date( $item ): string {
		return self::get_text_value( $item, array( 'licensee_birthdate', 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'date_naissance', 'naissance' ) );
	}

	public static function resolve_birth_year( $item ): string {
		$year = self::get_text_value( $item, array( 'birth_year', 'annee_naissance', 'year_of_birth', 'year' ) );
		if ( preg_match( '/^\d{4}$/', $year ) ) {
			return $year;
		}

		$birthdate = self::resolve_birth_date( $item );
		if ( preg_match( '/^(\d{4})-\d{2}-\d{2}(?:\s+\d{2}:\d{2}:\d{2})?$/', $birthdate, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '/^\d{2}[\/-]\d{2}[\/-](\d{4})$/', $birthdate, $matches ) ) {
			return $matches[1];
		}

		return '';
	}

	public static function resolve_club_name( $item ): string {
		return self::get_text_value( $item, array( 'club_name', 'club_nom', 'structure_name', 'club', 'club_label', 'club_import', 'club_raw', 'club_value' ) );
	}

	public static function resolve_license_number( $item ): string {
		return self::get_text_value(
			$item,
			array(
				'numero_licence',
				'numero_licence_ufsc',
				'ufsc_licence_number',
				'numero_licence_asptt',
				'numero_asptt',
				'asptt_number',
				'license_number',
				'licence_number',
				'licensee_number',
				'license',
				'licence',
			)
		);
	}

	public static function resolve_submitted_at( $item ): string {
		return self::get_text_value(
			$item,
			array(
				'submitted_at',
				'submitted',
				'date_submitted',
				'created_at',
				'imported_at',
			)
		);
	}

	public static function normalize_for_admin( $item ): array {
		$data = array(
			'display_name'   => self::resolve_display_name( $item ),
			'first_name'     => self::resolve_first_name( $item ),
			'last_name'      => self::resolve_last_name( $item ),
			'birth_date'     => self::resolve_birth_date( $item ),
			'birth_year'     => self::resolve_birth_year( $item ),
			'club_name'      => self::resolve_club_name( $item ),
			'license_number' => self::resolve_license_number( $item ),
			'fighter_number' => self::get_text_value( $item, array( 'fighter_number', 'competition_number', 'dossard' ) ),
			'submitted_at'   => self::resolve_submitted_at( $item ),
			'category'       => self::get_text_value( $item, array( 'category', 'category_name' ) ),
			'weight'         => self::get_text_value( $item, array( 'weight', 'weight_kg', 'poids' ) ),
			'weight_class'   => self::get_text_value( $item, array( 'weight_class', 'weight_category', 'weight_cat', 'categorie_poids' ) ),
			'level'          => self::get_text_value( $item, array( 'level', 'class', 'classe', 'niveau' ) ),
			'participant_type' => self::get_text_value( $item, array( 'participant_type' ) ),
			'discipline'     => self::get_text_value( $item, array( 'discipline' ) ),
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && self::$debug_samples < 5 ) {
			self::$debug_samples++;
			error_log( 'UFSC EntryDataNormalizer entry_normalizer_output_sample ' . wp_json_encode( $data ) );
		}

		return $data;
	}

	private static function split_participant_name( string $participant_name ): array {
		$participant_name = trim( $participant_name );
		if ( '' === $participant_name ) {
			return array( 'first' => '', 'last' => '' );
		}

		$parts = preg_split( '/\s+/', $participant_name );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return array( 'first' => '', 'last' => '' );
		}
		if ( 1 === count( $parts ) ) {
			return array( 'first' => '', 'last' => sanitize_text_field( (string) $parts[0] ) );
		}

		$last  = (string) array_pop( $parts );
		$first = trim( implode( ' ', $parts ) );

		return array(
			'first' => sanitize_text_field( $first ),
			'last'  => sanitize_text_field( $last ),
		);
	}

	private static function read_item_value( $item, string $key ) {
		if ( is_array( $item ) && array_key_exists( $key, $item ) ) {
			return $item[ $key ];
		}
		if ( is_object( $item ) && property_exists( $item, $key ) ) {
			return $item->{$key};
		}

		return '';
	}
}
