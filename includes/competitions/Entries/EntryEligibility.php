<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_is_entry_eligible' ) ) {
	/**
	 * Central business eligibility check for competition entries.
	 *
	 * @param int    $entry_id Entry ID.
	 * @param string $context  Context (admin_entries, admin_validation, front_club, fights, exports, exports_club).
	 * @return array{eligible:bool,status:string,entry:object|null,reasons:array}
	 */
	function ufsc_is_entry_eligible( int $entry_id, string $context ): array {
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

		$result['entry'] = $entry;
		if ( method_exists( $repo, 'get_entry_status' ) ) {
			$result['status'] = $repo->get_entry_status( $entry );
		} else {
			$result['status'] = class_exists( '\UFSC\Competitions\Entries\EntriesWorkflow' )
				? \UFSC\Competitions\Entries\EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) )
				: sanitize_key( (string) ( $entry->status ?? '' ) );
		}

		$is_deleted = ! empty( $entry->deleted_at );
		if ( $is_deleted && ! in_array( $context, array( 'admin_entries', 'admin_validation' ), true ) ) {
			$result['reasons'][] = 'entry_deleted';
			return apply_filters( 'ufsc_entry_eligibility', $result, $entry_id, $context );
		}

		$weight = null;
		foreach ( array( 'weight', 'weight_kg', 'poids' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$weight = (float) str_replace( ',', '.', (string) $entry->{$key} );
				break;
			}
		}

		$weight_class = '';
		foreach ( array( 'weight_class', 'weight_cat', 'weight_category' ) as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				$weight_class = sanitize_text_field( (string) $entry->{$key} );
				break;
			}
		}

		$license_id = absint( $entry->licensee_id ?? $entry->licence_id ?? 0 );
		$license_number = sanitize_text_field( (string) ( $entry->license_number ?? '' ) );
		$has_license = ( $license_id > 0 ) || '' !== $license_number;

		$club_id = absint( $entry->club_id ?? 0 );
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
				if ( ! $has_license ) {
					$eligible  = false;
					$reasons[] = 'license_missing';
				}
				if ( ! $has_club ) {
					$eligible  = false;
					$reasons[] = 'club_missing';
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

		return apply_filters( 'ufsc_entry_eligibility', $result, $entry_id, $context );
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
