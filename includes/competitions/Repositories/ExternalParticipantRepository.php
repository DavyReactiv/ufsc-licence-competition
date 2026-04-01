<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExternalParticipantRepository {
	public function get_by_entry_id( int $entry_id ) {
		global $wpdb;

		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return null;
		}

		$table = Db::external_participants_table();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE entry_id = %d LIMIT 1", $entry_id )
		);
	}

	public function upsert_for_entry( int $entry_id, array $data ): bool {
		global $wpdb;

		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return false;
		}

		$table = Db::external_participants_table();
		$now   = current_time( 'mysql' );

		$payload               = $this->sanitize( $data );
		$payload['entry_id']   = $entry_id;
		$payload['updated_at'] = $now;

		$existing = $this->get_by_entry_id( $entry_id );
		if ( $existing ) {
			$result = $wpdb->update(
				$table,
				$payload,
				array( 'entry_id' => $entry_id ),
				$this->formats_for( $payload ),
				array( '%d' )
			);

			return false !== $result;
		}

		$payload['created_at'] = $now;
		$result                = $wpdb->insert( $table, $payload, $this->formats_for( $payload ) );

		return false !== $result;
	}

	private function sanitize( array $data ): array {
		$participant_type = isset( $data['participant_type'] ) ? sanitize_key( (string) $data['participant_type'] ) : 'licensed_ufsc';
		$status           = isset( $data['validation_status'] ) ? sanitize_key( (string) $data['validation_status'] ) : 'draft';

		return array(
			'participant_type'     => $participant_type,
			'first_name'           => sanitize_text_field( (string) ( $data['first_name'] ?? '' ) ),
			'last_name'            => sanitize_text_field( (string) ( $data['last_name'] ?? '' ) ),
			'birth_date'           => $this->sanitize_date( (string) ( $data['birth_date'] ?? '' ) ),
			'sex'                  => sanitize_key( (string) ( $data['sex'] ?? '' ) ),
			'club_name'            => sanitize_text_field( (string) ( $data['club_name'] ?? '' ) ),
			'structure_name'       => sanitize_text_field( (string) ( $data['structure_name'] ?? '' ) ),
			'city'                 => sanitize_text_field( (string) ( $data['city'] ?? '' ) ),
			'discipline'           => sanitize_text_field( (string) ( $data['discipline'] ?? '' ) ),
			'category_label'       => sanitize_text_field( (string) ( $data['category_label'] ?? '' ) ),
			'weight_kg'            => $this->sanitize_decimal( $data['weight_kg'] ?? null ),
			'weight_class'         => sanitize_text_field( (string) ( $data['weight_class'] ?? '' ) ),
			'level'                => sanitize_text_field( (string) ( $data['level'] ?? '' ) ),
			'medical_notes'        => sanitize_textarea_field( (string) ( $data['medical_notes'] ?? '' ) ),
			'legal_guardian_name'  => sanitize_text_field( (string) ( $data['legal_guardian_name'] ?? '' ) ),
			'legal_guardian_phone' => sanitize_text_field( (string) ( $data['legal_guardian_phone'] ?? '' ) ),
			'legal_guardian_email' => sanitize_email( (string) ( $data['legal_guardian_email'] ?? '' ) ),
			'validation_status'    => $status,
			'anomaly_flags'        => isset( $data['anomaly_flags'] ) && is_array( $data['anomaly_flags'] ) ? wp_json_encode( array_values( array_unique( array_map( 'sanitize_key', $data['anomaly_flags'] ) ) ) ) : '',
		);
	}

	private function sanitize_date( string $value ): ?string {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		$date = date_create_from_format( 'Y-m-d', $value );
		if ( ! $date instanceof \DateTimeInterface ) {
			return null;
		}

		$normalized = $date->format( 'Y-m-d' );

		return $normalized === $value ? $normalized : null;
	}

	private function sanitize_decimal( $value ): ?float {
		if ( null === $value || '' === $value ) {
			return null;
		}

		$decimal = (float) str_replace( ',', '.', (string) $value );
		if ( $decimal <= 0 || $decimal > 300 ) {
			return null;
		}

		return $decimal;
	}

	private function formats_for( array $payload ): array {
		$formats = array();
		foreach ( $payload as $key => $value ) {
			if ( in_array( $key, array( 'entry_id' ), true ) ) {
				$formats[] = '%d';
			} elseif ( in_array( $key, array( 'weight_kg' ), true ) ) {
				$formats[] = null === $value ? '%s' : '%f';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}
}
