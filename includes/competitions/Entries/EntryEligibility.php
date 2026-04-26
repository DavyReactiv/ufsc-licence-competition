<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_lc_is_entry_eligible' ) ) {
	/**
	 * Central business eligibility check for competition entries.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $context  Context (admin_entries, admin_validation, front_club, fights, exports, exports_club).
	 * @return array{eligible:bool,status:string,entry:object|null,reasons:array}
	 */
	function ufsc_lc_is_entry_eligible( int $entry_id, string $context ): array {
		$entry_id = absint( $entry_id );
		$context  = sanitize_key( $context );

		$result = array(
			'eligible' => false,
			'status'   => 'draft',
			'entry'    => null,
			'reasons'  => array(),
		);

		if ( ! $entry_id ) {
			$result['reasons'][] = 'entry_missing';
			return apply_filters( 'ufsc_entry_eligibility', $result, $entry_id, $context );
		}

		$repo  = new \UFSC\Competitions\Repositories\EntryRepository();
		$entry = method_exists( $repo, 'get_with_details' )
			? $repo->get_with_details( $entry_id, true )
			: $repo->get( $entry_id, true );

		if ( ! $entry ) {
			$result['reasons'][] = 'entry_not_found';
			return apply_filters( 'ufsc_entry_eligibility', $result, $entry_id, $context );
		}

		return ufsc_lc_is_entry_eligible_from_entry( $entry, $context, $repo, $entry_id );
	}
}

if ( ! function_exists( 'ufsc_lc_is_entry_eligible_from_entry' ) ) {
	/**
	 * Eligibility evaluation from an already-loaded entry object.
	 *
	 * @param object      $entry Loaded entry row.
	 * @param string      $context Context (admin_entries, admin_validation, front_club, fights, exports, exports_club).
	 * @param object|null $repo Optional repository used to resolve status.
	 * @param int|null    $entry_id Optional id used in filter payload.
	 * @return array{eligible:bool,status:string,entry:object|null,reasons:array}
	 */
	function ufsc_lc_is_entry_eligible_from_entry( $entry, string $context, $repo = null, ?int $entry_id = null ): array {
		$context = sanitize_key( $context );
		$resolved_entry_id = null !== $entry_id ? absint( $entry_id ) : absint( $entry->id ?? 0 );

		$result = array(
			'eligible' => false,
			'status'   => 'draft',
			'entry'    => $entry,
			'reasons'  => array(),
		);

		if ( ! is_object( $entry ) ) {
			$result['entry']      = null;
			$result['reasons'][]  = 'entry_not_found';
			return apply_filters( 'ufsc_entry_eligibility', $result, $resolved_entry_id, $context );
		}

		if ( $repo && method_exists( $repo, 'get_entry_status' ) ) {
			$result['status'] = $repo->get_entry_status( $entry );
		} else {
			$result['status'] = class_exists( '\UFSC\Competitions\Entries\EntriesWorkflow' )
				? \UFSC\Competitions\Entries\EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) )
				: sanitize_key( (string) ( $entry->status ?? '' ) );
		}

		$is_deleted = ! empty( $entry->deleted_at );
		if ( $is_deleted && ! in_array( $context, array( 'admin_entries', 'admin_validation' ), true ) ) {
			$result['reasons'][] = 'entry_deleted';
			return apply_filters( 'ufsc_entry_eligibility', $result, $resolved_entry_id, $context );
		}

		$weight = null;
		foreach ( array( 'weight', 'weight_kg', 'poids' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$weight = (float) str_replace( ',', '.', (string) $entry->{$key} );
				break;
			}
		}

		$weight_class = '';
		foreach ( array( 'weight_class', 'weight_cat', 'weight_category', 'categorie_poids', 'category_weight' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$weight_class = sanitize_text_field( (string) $entry->{$key} );
				break;
			}
		}

		$license_id     = absint( $entry->licensee_id ?? $entry->licence_id ?? 0 );
		$license_number = '';
		foreach ( array( 'license_number', 'licence_number', 'numero_licence', 'numero_licence_ufsc', 'ufsc_licence_number', 'numero_licence_asptt', 'numero_asptt', 'asptt_number', 'licensee_number' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== trim( (string) $entry->{$key} ) ) {
				$license_number = sanitize_text_field( (string) $entry->{$key} );
				break;
			}
		}
		$has_license    = ( $license_id > 0 ) || '' !== $license_number;
		$participant_type = sanitize_key( (string) ( $entry->participant_type ?? 'licensed_ufsc' ) );
		if ( ! in_array( $participant_type, array( 'licensed_ufsc', 'external_non_licensed' ), true ) ) {
			$participant_type = 'licensed_ufsc';
		}

		$club_id  = absint( $entry->club_id ?? 0 );
		$has_club = ( $club_id > 0 ) || ! empty( $entry->club_name );

		$eligible = true;
		$reasons  = array();

		switch ( $context ) {
			case 'admin_validation':
				if ( ! in_array( $result['status'], array( 'submitted', 'pending' ), true ) ) {
					$eligible  = false;
					$reasons[] = 'status_not_pending';
				}
				break;
			case 'exports':
				if ( in_array( $result['status'], array( 'draft', 'rejected', 'cancelled' ), true ) ) {
					$eligible  = false;
					$reasons[] = 'status_not_exportable';
				}
				break;
			case 'exports_club':
				if ( 'approved' !== $result['status'] ) {
					$eligible  = false;
					$reasons[] = 'status_not_approved';
				}
				break;
			case 'fights':
				if ( 'approved' !== $result['status'] ) {
					$eligible  = false;
					$reasons[] = 'status_not_approved';
				}
				if ( null === $weight || $weight <= 0 ) {
					$eligible  = false;
					$reasons[] = 'weight_missing';
				}
				if ( '' === $weight_class ) {
					$eligible  = false;
					$reasons[] = 'weight_class_missing';
				}
				if ( 'licensed_ufsc' === $participant_type && ! $has_license ) {
					$eligible  = false;
					$reasons[] = 'license_missing';
				}
				if ( 'external_non_licensed' === $participant_type ) {
					if ( class_exists( '\\UFSC\\Competitions\\Services\\ExternalParticipantEligibility' ) ) {
						$pick_external_value = static function ( $entry_obj, array $keys ): string {
							foreach ( $keys as $key ) {
								if ( isset( $entry_obj->{$key} ) ) {
									$value = trim( (string) $entry_obj->{$key} );
									if ( '' !== $value ) {
										return sanitize_text_field( $value );
									}
								}
							}

							return '';
						};
						$normalize_external_birth_date = static function ( string $value ): string {
							$value = trim( $value );
							if ( '' === $value ) {
								return '';
							}
							if ( preg_match( '/^(\d{4})-\d{2}-\d{2}/', $value, $matches ) ) {
								return $matches[0];
							}
							if ( preg_match( '/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches ) ) {
								return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
							}
							if ( preg_match( '/^(\d{2})-(\d{2})-(\d{4})$/', $value, $matches ) ) {
								return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
							}

							return '';
						};
						$normalize_external_sex = static function ( string $raw, string $category = '' ): string {
							$raw = sanitize_key( $raw );
							if ( in_array( $raw, array( 'f', 'female', 'feminin', 'femme' ), true ) ) {
								return 'f';
							}
							if ( in_array( $raw, array( 'm', 'h', 'male', 'masculin', 'homme' ), true ) ) {
								return 'm';
							}

							$upper_category = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $category ) : strtoupper( $category );
							if ( '' !== $upper_category ) {
								if ( preg_match( '/(?:\s|^)(F|FEM|FEMININ|FEMME)(?:\s|$)/u', $upper_category ) ) {
									return 'f';
								}
								if ( preg_match( '/(?:\s|^)(H|M|HOM|HOMME|MASC|MASCULIN)(?:\s|$)/u', $upper_category ) ) {
									return 'm';
								}
							}

							return '';
						};

						$category_value = $pick_external_value( $entry, array( 'category', 'category_name', 'categorie' ) );
						$normalized_external = clone $entry;
						$normalized_external->first_name = $pick_external_value( $entry, array( 'first_name', 'licensee_first_name', 'prenom', 'given_name' ) );
						$normalized_external->last_name  = $pick_external_value( $entry, array( 'last_name', 'licensee_last_name', 'nom', 'family_name' ) );
						$normalized_external->birth_date = $normalize_external_birth_date(
							$pick_external_value( $entry, array( 'birth_date', 'date_naissance', 'birthdate', 'dob', 'date_of_birth', 'naissance', 'licensee_birthdate' ) )
						);
						$normalized_external->sex = $normalize_external_sex(
							$pick_external_value( $entry, array( 'sex', 'sexe', 'gender', 'genre', 'fighter_sex', 'participant_gender', 'licensee_sex' ) ),
							$category_value
						);
						$normalized_external->weight = $pick_external_value( $entry, array( 'weight', 'weight_kg', 'poids' ) );
						$normalized_external->category = $category_value;

						$competition_meta = array( 'allow_external_non_licensed' => true );
						$competition_id = absint( $entry->competition_id ?? 0 );
						if ( $competition_id && class_exists( '\\UFSC\\Competitions\\Services\\CompetitionMeta' ) ) {
							$competition_meta = \UFSC\Competitions\Services\CompetitionMeta::get( $competition_id );
						}
						$external_check = \UFSC\Competitions\Services\ExternalParticipantEligibility::validate_for_competition(
							$normalized_external,
							is_array( $competition_meta ) ? $competition_meta : array(),
							array( 'require_sport_data' => true )
						);
						if ( is_array( $external_check ) && ! empty( $external_check['reasons'] ) ) {
							$external_reasons = array_values(
								array_filter(
									array_map( 'sanitize_key', (array) $external_check['reasons'] ),
									static function ( string $reason ): bool {
										return 'external_not_allowed_for_competition' !== $reason;
									}
								)
							);
							if ( empty( $external_reasons ) ) {
								$external_reasons = array();
							}
							if ( ! empty( $external_reasons ) ) {
								$eligible = false;
								$reasons  = array_merge( $reasons, $external_reasons );
							}
						}
					} else {
						$first_name = sanitize_text_field( (string) ( $entry->first_name ?? $entry->licensee_first_name ?? '' ) );
						$last_name  = sanitize_text_field( (string) ( $entry->last_name ?? $entry->licensee_last_name ?? '' ) );
						$birth_date = sanitize_text_field( (string) ( $entry->birth_date ?? $entry->licensee_birthdate ?? '' ) );
						$sex_value  = sanitize_key( (string) ( $entry->sex ?? $entry->licensee_sex ?? '' ) );
						if ( '' === $first_name || '' === $last_name || '' === $birth_date || '' === $sex_value ) {
							$eligible  = false;
							$reasons[] = 'external_identity_incomplete';
						}
					}
				}
				if ( ! $has_club ) {
					$eligible  = false;
					$reasons[] = 'club_missing';
				}
				if ( class_exists( '\\UFSC\\Competitions\\Services\\UfscReference\\UfscReferenceFacade' ) ) {
					$age_from_birthdate = 0;
					if ( ! empty( $entry->birth_date ) ) {
						$birthdate_obj = date_create( (string) $entry->birth_date );
						if ( $birthdate_obj instanceof \DateTimeInterface ) {
							$age_from_birthdate = (int) $birthdate_obj->diff( date_create( 'now' ) )->y;
						}
					}
					$reference = \UFSC\Competitions\Services\UfscReference\UfscReferenceFacade::resolve_obligations(
						array(
							'discipline' => sanitize_key( (string) ( $entry->discipline ?? '' ) ),
							'age' => $age_from_birthdate,
							'certificate_medical' => ! empty( $entry->certificate_medical ),
							'fundus' => ! empty( $entry->fundus ),
							'ecg' => ! empty( $entry->ecg ),
						)
					);
					if ( is_array( $reference ) ) {
						$warnings = isset( $reference['warnings'] ) && is_array( $reference['warnings'] ) ? $reference['warnings'] : array();
						foreach ( $warnings as $warning ) {
							$reasons[] = 'reference_' . sanitize_key( (string) $warning );
						}
						$strict = (bool) apply_filters( 'ufsc_competitions_reference_obligations_strict', false, $entry, $context );
						if ( $strict && ! empty( $warnings ) ) {
							$eligible = false;
						}
					}
				}
				break;
			case 'front_club':
			case 'admin_entries':
			default:
				$eligible = true;
				break;
		}

		$result['eligible'] = $eligible;
		$result['reasons']  = array_values( array_unique( $reasons ) );

		return apply_filters( 'ufsc_entry_eligibility', $result, $resolved_entry_id, $context );
	}
}

if ( ! function_exists( 'ufsc_lc_format_datetime' ) ) {
	/**
	 * Format date/time consistently across admin + front.
	 *
	 * @param string $value Raw date/datetime string.
	 * @param string $fallback Fallback if empty/invalid.
	 * @return string
	 */
	function ufsc_lc_format_datetime( $value, string $fallback = '—' ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( $value );

		if ( '' === $value ) {
			return $fallback;
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( wp_timezone_string() ?: 'UTC' );
			$date = date_create( $value, $timezone );
			$timestamp = $date instanceof \DateTimeInterface ? $date->getTimestamp() : 0;
		}

		if ( ! $timestamp ) {
			return $value;
		}

		return date_i18n( 'l d/m/Y \\à H:i', $timestamp );
	}
}
