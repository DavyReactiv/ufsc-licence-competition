<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalParticipantValidator {
	public static function validate( array $data ): array {
		$errors = array();

		if ( '' === trim( (string) ( $data['first_name'] ?? '' ) ) ) {
			$errors[] = 'first_name_required';
		}
		if ( '' === trim( (string) ( $data['last_name'] ?? '' ) ) ) {
			$errors[] = 'last_name_required';
		}
		if ( '' === trim( (string) ( $data['birth_date'] ?? '' ) ) ) {
			$errors[] = 'birth_date_required';
		}
		if ( '' === trim( (string) ( $data['sex'] ?? '' ) ) ) {
			$errors[] = 'sex_required';
		}

		$birth_date = trim( (string) ( $data['birth_date'] ?? '' ) );
		if ( '' !== $birth_date ) {
			$date = date_create_from_format( 'Y-m-d', $birth_date );
			if ( ! $date instanceof \DateTimeInterface || $date->format( 'Y-m-d' ) !== $birth_date ) {
				$errors[] = 'birth_date_invalid';
			}
		}

		$is_minor = false;
		if ( ! in_array( 'birth_date_invalid', $errors, true ) && '' !== $birth_date ) {
			$dob = date_create_from_format( 'Y-m-d', $birth_date );
			if ( $dob instanceof \DateTimeInterface ) {
				$now      = date_create( 'now' );
				$is_minor = ( $now instanceof \DateTimeInterface ) ? ( (int) $dob->diff( $now )->y < 18 ) : false;
			}
		}

		if ( $is_minor && '' === trim( (string) ( $data['legal_guardian_name'] ?? '' ) ) ) {
			$errors[] = 'legal_guardian_required_for_minor';
		}

		return array_values( array_unique( $errors ) );
	}
}
