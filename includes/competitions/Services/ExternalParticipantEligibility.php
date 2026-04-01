<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Entries\ParticipantTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalParticipantEligibility {
	public static function resolve_type( $entry, ?array $external_data = null ): string {
		if ( is_array( $external_data ) && ! empty( $external_data['participant_type'] ) ) {
			return ParticipantTypes::normalize( (string) $external_data['participant_type'] );
		}

		if ( is_object( $entry ) && ! empty( $entry->participant_type ) ) {
			return ParticipantTypes::normalize( (string) $entry->participant_type );
		}

		return ParticipantTypes::get_default();
	}

	public static function is_external_entry_allowed( array $competition_meta ): bool {
		return ! empty( $competition_meta['allow_external_non_licensed'] );
	}

	public static function validate_for_competition( $entry, array $competition_meta = array(), array $context = array() ): array {
		$reasons = array();

		if ( ! self::is_external_entry_allowed( $competition_meta ) ) {
			$reasons[] = 'external_not_allowed_for_competition';
		}

		$first_name = trim( (string) ( $entry->first_name ?? $entry->licensee_first_name ?? '' ) );
		$last_name  = trim( (string) ( $entry->last_name ?? $entry->licensee_last_name ?? '' ) );
		$birth_date = trim( (string) ( $entry->birth_date ?? $entry->licensee_birthdate ?? '' ) );
		$sex        = sanitize_key( (string) ( $entry->sex ?? $entry->licensee_sex ?? '' ) );

		if ( '' === $first_name || '' === $last_name || '' === $birth_date || '' === $sex ) {
			$reasons[] = 'external_identity_incomplete';
		}

		$dob = null;
		if ( '' !== $birth_date ) {
			$dob = date_create_from_format( 'Y-m-d', $birth_date );
			if ( ! $dob instanceof \DateTimeInterface || $dob->format( 'Y-m-d' ) !== $birth_date ) {
				$reasons[] = 'external_birth_date_invalid';
			} else {
				$now = date_create( 'now' );
				if ( $now instanceof \DateTimeInterface && $dob > $now ) {
					$reasons[] = 'external_birth_date_future';
				}
			}
		}

		if ( '' !== $sex && ! in_array( $sex, array( 'm', 'f', 'x' ), true ) ) {
			$reasons[] = 'external_sex_invalid';
		}

		$is_minor = false;
		if ( $dob instanceof \DateTimeInterface ) {
			$now = date_create( 'now' );
			$is_minor = ( $now instanceof \DateTimeInterface ) ? ( (int) $dob->diff( $now )->y < 18 ) : false;
		}
		if ( $is_minor && '' === trim( (string) ( $entry->legal_guardian_name ?? '' ) ) ) {
			$reasons[] = 'external_minor_guardian_missing';
		}

		$guardian_email = trim( (string) ( $entry->legal_guardian_email ?? '' ) );
		if ( '' !== $guardian_email && ! is_email( $guardian_email ) ) {
			$reasons[] = 'external_guardian_email_invalid';
		}

		$guardian_phone = trim( (string) ( $entry->legal_guardian_phone ?? '' ) );
		if ( '' !== $guardian_phone ) {
			$phone = preg_replace( '/[^0-9+]/', '', $guardian_phone );
			if ( ! is_string( $phone ) || strlen( $phone ) < 6 ) {
				$reasons[] = 'external_guardian_phone_invalid';
			}
		}

		if ( ! empty( $context['require_sport_data'] ) ) {
			$weight = trim( (string) ( $entry->weight ?? $entry->weight_kg ?? '' ) );
			$category = trim( (string) ( $entry->category ?? $entry->category_name ?? '' ) );
			if ( '' === $weight || '' === $category ) {
				$reasons[] = 'external_missing_required_sport_data';
			}
		}

		$reasons = array_values( array_unique( array_map( 'sanitize_key', $reasons ) ) );

		return array(
			'eligible' => empty( $reasons ),
			'reasons' => $reasons,
		);
	}
}
