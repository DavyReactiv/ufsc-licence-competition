<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalParticipantValidator {
	public static function validate( array $data ): array {
		$errors = array();

		$first_name = trim( (string) ( $data['first_name'] ?? '' ) );
		$last_name  = trim( (string) ( $data['last_name'] ?? '' ) );
		$birth_date = trim( (string) ( $data['birth_date'] ?? '' ) );
		$sex        = sanitize_key( (string) ( $data['sex'] ?? '' ) );

		if ( '' === $first_name ) {
			$errors[] = 'first_name_required';
			$errors[] = 'external_identity_incomplete';
		}
		if ( '' === $last_name ) {
			$errors[] = 'last_name_required';
			$errors[] = 'external_identity_incomplete';
		}
		if ( '' === $birth_date ) {
			$errors[] = 'birth_date_required';
			$errors[] = 'external_identity_incomplete';
		}
		if ( '' === $sex ) {
			$errors[] = 'sex_required';
			$errors[] = 'external_identity_incomplete';
		} elseif ( ! in_array( $sex, array( 'm', 'f', 'x' ), true ) ) {
			$errors[] = 'external_sex_invalid';
		}

		if ( '' !== $birth_date ) {
			$date = date_create_from_format( 'Y-m-d', $birth_date );
			if ( ! $date instanceof \DateTimeInterface || $date->format( 'Y-m-d' ) !== $birth_date ) {
				$errors[] = 'birth_date_invalid';
				$errors[] = 'external_birth_date_invalid';
			} else {
				$now = date_create( 'now' );
				if ( $now instanceof \DateTimeInterface && $date > $now ) {
					$errors[] = 'external_birth_date_future';
				}
			}
		}

		$is_minor = false;
		if ( ! in_array( 'birth_date_invalid', $errors, true ) && ! in_array( 'external_birth_date_invalid', $errors, true ) && '' !== $birth_date ) {
			$dob = date_create_from_format( 'Y-m-d', $birth_date );
			if ( $dob instanceof \DateTimeInterface ) {
				$now      = date_create( 'now' );
				$is_minor = ( $now instanceof \DateTimeInterface ) ? ( (int) $dob->diff( $now )->y < 18 ) : false;
			}
		}

		if ( $is_minor && '' === trim( (string) ( $data['legal_guardian_name'] ?? '' ) ) ) {
			$errors[] = 'legal_guardian_required_for_minor';
			$errors[] = 'external_minor_guardian_missing';
		}

		$guardian_email = trim( (string) ( $data['legal_guardian_email'] ?? '' ) );
		if ( '' !== $guardian_email && ! is_email( $guardian_email ) ) {
			$errors[] = 'external_guardian_email_invalid';
		}

		$guardian_phone = trim( (string) ( $data['legal_guardian_phone'] ?? '' ) );
		if ( '' !== $guardian_phone ) {
			$phone = preg_replace( '/[^0-9+]/', '', $guardian_phone );
			if ( ! is_string( $phone ) || strlen( $phone ) < 6 ) {
				$errors[] = 'external_guardian_phone_invalid';
			}
		}

		return array_values( array_unique( $errors ) );
	}
}
