<?php

namespace UFSC\Competitions\Front\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\RepositoryHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryFrontRepository {
	use RepositoryHelpers;

	private $columns = array();

	public function __construct() {
		$this->columns = $this->get_columns();
	}

	public function list_by_competition_and_club( int $competition_id, int $club_id ): array {
		global $wpdb;

		$competition_id = absint( $competition_id );
		$club_id = absint( $club_id );

		if ( ! $competition_id || ! $club_id ) {
			return array();
		}

		if ( ! $this->has_column( 'competition_id' ) || ! $this->has_column( 'club_id' ) ) {
			return array();
		}

		$table = Db::entries_table();

		$where = array( 'competition_id = %d', 'club_id = %d' );
		if ( $this->has_column( 'deleted_at' ) ) {
			$where[] = 'deleted_at IS NULL';
		}

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC',
			$competition_id,
			$club_id
		);

		$rows = $wpdb->get_results( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':list' );

		return is_array( $rows ) ? $rows : array();
	}

	public function get( int $entry_id ) {
		global $wpdb;

		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return null;
		}

		$table = Db::entries_table();
		$where = array( 'id = %d' );
		if ( $this->has_column( 'deleted_at' ) ) {
			$where[] = 'deleted_at IS NULL';
		}

		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' LIMIT 1',
			$entry_id
		);

		$row = $wpdb->get_row( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':get' );

		return $row ?: null;
	}

	public function insert( array $payload ): int {
		global $wpdb;

		$data = $this->prepare_payload( $payload, true );
		if ( empty( $data ) ) {
			return 0;
		}

		$table = Db::entries_table();
		$inserted = $wpdb->insert( $table, $data, $this->build_formats( $data ) );

		$this->maybe_log_db_error( __METHOD__ . ':insert' );

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	public function update( int $entry_id, array $payload ): int {
		global $wpdb;

		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return 0;
		}

		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return 0;
		}

		if ( 'draft' !== $this->get_entry_status( $entry ) ) {
			return 0;
		}

		$data = $this->prepare_payload( $payload, false );
		if ( empty( $data ) ) {
			return 0;
		}

		$table = Db::entries_table();
		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $entry_id ),
			$this->build_formats( $data ),
			array( '%d' )
		);

		$this->maybe_log_db_error( __METHOD__ . ':update' );

		return (int) $updated;
	}

	public function delete( int $entry_id ): int {
		global $wpdb;

		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return 0;
		}

		$table = Db::entries_table();

		if ( $this->has_column( 'deleted_at' ) ) {
			$data = array(
				'deleted_at' => current_time( 'mysql' ),
			);
			if ( $this->has_column( 'deleted_by' ) ) {
				$data['deleted_by'] = get_current_user_id() ?: null;
			}
			if ( $this->has_column( 'updated_at' ) ) {
				$data['updated_at'] = current_time( 'mysql' );
			}
			if ( $this->has_column( 'updated_by' ) ) {
				$data['updated_by'] = get_current_user_id() ?: null;
			}

			$updated = $wpdb->update(
				$table,
				$data,
				array( 'id' => $entry_id ),
				$this->build_formats( $data ),
				array( '%d' )
			);

			$this->maybe_log_db_error( __METHOD__ . ':soft_delete' );

			return (int) $updated;
		}

		$deleted = $wpdb->delete( $table, array( 'id' => $entry_id ), array( '%d' ) );

		$this->maybe_log_db_error( __METHOD__ . ':delete' );

		return (int) $deleted;
	}

	public function normalize_license_result( array $license ): array {
		$license_number = sanitize_text_field(
			(string) ( $license['license_number']
				?? $license['licence_number']
				?? $license['licensee_number']
				?? $license['license']
				?? $license['licence']
				?? $license['numero_licence']
				?? $license['numero_licence_asptt']
				?? $license['numero_licence_delegataire']
				?? '' )
		);

		$normalized = array(
			'id' => absint( $license['id'] ?? 0 ),
			'label' => sanitize_text_field( $license['label'] ?? '' ),
			'first_name' => sanitize_text_field( $license['first_name'] ?? $license['firstname'] ?? '' ),
			'last_name' => sanitize_text_field( $license['last_name'] ?? $license['lastname'] ?? '' ),
			'birthdate' => sanitize_text_field( $license['birthdate'] ?? $license['birth_date'] ?? '' ),
			'sex' => sanitize_text_field( $license['sex'] ?? $license['gender'] ?? '' ),
			'weight' => isset( $license['weight'] ) ? $this->sanitize_float_value( $license['weight'] ) : null,
			'weight_class' => sanitize_text_field( $license['weight_class'] ?? '' ),
			'level' => sanitize_text_field( $license['level'] ?? '' ),
			'license_number' => $license_number,
		);

		if ( '' !== $normalized['birthdate'] && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $normalized['birthdate'] ) ) {
			$normalized['birthdate'] = '';
		}

		return $normalized;
	}

	public function normalize_license_results( array $results, int $limit = 20 ): array {
		$normalized = array();

		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				continue;
			}
			$item = $this->normalize_license_result( $result );
			if ( empty( $item['id'] ) || '' === $item['label'] ) {
				continue;
			}
			$normalized[] = $item;
			if ( count( $normalized ) >= $limit ) {
				break;
			}
		}

		return $normalized;
	}

	public function merge_license_payload( array $data, array $license ): array {
		if ( empty( $license['id'] ) ) {
			return $data;
		}

		$defaults = array(
			'first_name' => $license['first_name'] ?? '',
			'last_name' => $license['last_name'] ?? '',
			'birth_date' => $license['birthdate'] ?? '',
			'sex' => $license['sex'] ?? '',
			'weight' => $license['weight'] ?? '',
			'weight_class' => $license['weight_class'] ?? '',
			'license_number' => $license['license_number'] ?? '',
		);

		foreach ( $defaults as $key => $value ) {
			if ( '' === (string) ( $data[ $key ] ?? '' ) && '' !== (string) $value ) {
				$data[ $key ] = $value;
			}
		}

		if ( empty( $data['licensee_id'] ) ) {
			$data['licensee_id'] = (int) $license['id'];
		}

		return $data;
	}

	public function get_status_storage_field(): string {
		if ( $this->has_column( 'status' ) ) {
			return 'status';
		}
		if ( $this->has_column( 'notes' ) ) {
			return 'notes';
		}
		if ( $this->has_column( 'meta' ) ) {
			return 'meta';
		}
		if ( $this->has_column( 'metadata' ) ) {
			return 'metadata';
		}

		return '';
	}

	public function get_allowed_statuses(): array {
		$statuses = array( 'draft', 'submitted', 'pending', 'validated', 'rejected', 'cancelled', 'withdrawn' );
		$statuses = apply_filters( 'ufsc_entries_allowed_statuses', $statuses );
		if ( ! is_array( $statuses ) ) {
			return array( 'draft', 'submitted', 'pending', 'validated', 'rejected', 'cancelled', 'withdrawn' );
		}

		return array_values( array_unique( array_map( 'sanitize_key', $statuses ) ) );
	}

	public function get_entry_status( $entry ): string {
		if ( ! $entry ) {
			return 'draft';
		}

		$status_field = $this->get_status_storage_field();
		if ( 'status' === $status_field && isset( $entry->status ) ) {
			$status = sanitize_key( (string) $entry->status );
			return $this->normalize_status( $status );
		}

		if ( $status_field && isset( $entry->{$status_field} ) ) {
			$status = $this->extract_status_from_note( (string) $entry->{$status_field} );
			if ( $status ) {
				return $this->normalize_status( $status );
			}
		}

		return 'draft';
	}

	public function submit( int $entry_id, int $club_id ): array {
		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( absint( $entry->club_id ?? 0 ) !== absint( $club_id ) ) {
			return $this->build_result( false, __( 'Action non autorisée.', 'ufsc-licence-competition' ) );
		}

		if ( 'draft' !== $this->get_entry_status( $entry ) ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}

		$data = array();
		if ( $this->has_column( 'submitted_at' ) ) {
			$data['submitted_at'] = current_time( 'mysql' );
		}

		return $this->update_status( $entry, 'submitted', $data );
	}

	public function withdraw( int $entry_id, int $club_id ): array {
		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( absint( $entry->club_id ?? 0 ) !== absint( $club_id ) ) {
			return $this->build_result( false, __( 'Action non autorisée.', 'ufsc-licence-competition' ) );
		}

		$current = $this->get_entry_status( $entry );
		$can_reopen = (bool) apply_filters( 'ufsc_entries_allow_reopen_by_club', false, $entry, $club_id );
		if ( 'rejected' === $current && ! $can_reopen ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}
		if ( ! in_array( $current, array( 'submitted', 'pending', 'rejected' ), true ) ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}

		return $this->update_status( $entry, 'draft', array() );
	}

	public function cancel( int $entry_id, int $club_id ): array {
		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( absint( $entry->club_id ?? 0 ) !== absint( $club_id ) ) {
			return $this->build_result( false, __( 'Action non autorisée.', 'ufsc-licence-competition' ) );
		}

		$current = $this->get_entry_status( $entry );
		if ( ! in_array( $current, array( 'draft', 'submitted', 'pending' ), true ) ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}

		return $this->update_status( $entry, 'cancelled', array() );
	}

	public function validate( int $entry_id, int $admin_user_id ): array {
		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( ! in_array( $this->get_entry_status( $entry ), array( 'submitted', 'pending' ), true ) ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}

		$data = array();
		if ( $this->has_column( 'validated_at' ) ) {
			$data['validated_at'] = current_time( 'mysql' );
		}
		if ( $this->has_column( 'admin_note' ) ) {
			$data['admin_note'] = sprintf(
				/* translators: %d is the user id. */
				__( 'Validation UFSC par l’utilisateur #%d.', 'ufsc-licence-competition' ),
				$admin_user_id
			);
		}

		return $this->update_status( $entry, 'validated', $data, $admin_user_id );
	}

	public function reject( int $entry_id, int $admin_user_id, string $reason ): array {
		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( ! in_array( $this->get_entry_status( $entry ), array( 'submitted', 'pending' ), true ) ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}

		$data = array();
		if ( $this->has_column( 'rejected_reason' ) ) {
			$data['rejected_reason'] = $this->sanitize_text_value( $reason );
		}
		if ( $this->has_column( 'admin_note' ) ) {
			$data['admin_note'] = sprintf(
				/* translators: %d is the user id. */
				__( 'Rejet UFSC par l’utilisateur #%d.', 'ufsc-licence-competition' ),
				$admin_user_id
			);
		}

		return $this->update_status( $entry, 'rejected', $data, $admin_user_id );
	}

	public function reopen( int $entry_id, int $admin_user_id ): array {
		$entry = $this->get( $entry_id );
		if ( ! $entry ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		if ( 'rejected' !== $this->get_entry_status( $entry ) ) {
			return $this->build_result( false, __( 'Statut invalide.', 'ufsc-licence-competition' ) );
		}

		return $this->update_status( $entry, 'draft', array(), $admin_user_id );
	}

	public function append_status_note( string $existing, string $status ): string {
		$existing = trim( $this->sanitize_text_value( $existing ) );
		$status = sanitize_key( $status );
		if ( '' === $status ) {
			return $existing;
		}

		if ( '' === $existing ) {
			return 'status:' . $status;
		}

		if ( false !== strpos( $existing, 'status:' . $status ) ) {
			return $existing;
		}

		return $existing . ' | status:' . $status;
	}

	public function has_entry_column( string $name ): bool {
		return $this->has_column( $name );
	}

	private function prepare_payload( array $payload, bool $is_insert ): array {
		$data = array();

		if ( isset( $payload['competition_id'] ) && $this->has_column( 'competition_id' ) ) {
			$data['competition_id'] = absint( $payload['competition_id'] );
		}

		if ( isset( $payload['club_id'] ) && $this->has_column( 'club_id' ) ) {
			$data['club_id'] = absint( $payload['club_id'] );
		}

		if ( isset( $payload['category_id'] ) && $this->has_column( 'category_id' ) ) {
			$data['category_id'] = absint( $payload['category_id'] );
		}

		$licensee_column = $this->resolve_licensee_column();
		if ( $licensee_column && isset( $payload['licensee_id'] ) ) {
			$data[ $licensee_column ] = absint( $payload['licensee_id'] );
		} elseif ( $licensee_column && isset( $payload['licence_id'] ) ) {
			$data[ $licensee_column ] = absint( $payload['licence_id'] );
		} elseif ( $licensee_column && $is_insert ) {
			$data[ $licensee_column ] = 0;
		}

		if ( $this->has_column( 'status' ) ) {
			if ( $is_insert || array_key_exists( 'status', $payload ) ) {
				$default_status = $is_insert ? 'draft' : '';
				$status = sanitize_key( $payload['status'] ?? $default_status );
				$allowed_status = $this->get_allowed_statuses();
				if ( '' === $status || ! in_array( $status, $allowed_status, true ) ) {
					$status = $default_status;
				}
				if ( '' !== $status ) {
					$data['status'] = $status;
				}
			}
		}

		if ( isset( $payload['assigned_at'] ) && $this->has_column( 'assigned_at' ) ) {
			$data['assigned_at'] = sanitize_text_field( $payload['assigned_at'] );
		}

		$first_name = $this->sanitize_text_value( $payload['first_name'] ?? '' );
		$last_name = $this->sanitize_text_value( $payload['last_name'] ?? '' );
		$full_name = trim( $first_name . ' ' . $last_name );

		$this->map_string_value( $data, $first_name, array( 'first_name', 'firstname', 'prenom', 'given_name' ) );
		$this->map_string_value( $data, $last_name, array( 'last_name', 'lastname', 'nom', 'family_name' ) );

		if ( $full_name ) {
			$this->map_string_value( $data, $full_name, array( 'athlete_name', 'full_name', 'name', 'licensee_name' ) );
		}

		$birth_date = $this->sanitize_date_value( $payload['birth_date'] ?? '' );
		$this->map_string_value( $data, $birth_date, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) );

		$sex = $this->sanitize_text_value( $payload['sex'] ?? '' );
		$this->map_string_value( $data, $sex, array( 'sex', 'gender' ) );

		$weight = $this->sanitize_float_value( $payload['weight'] ?? '' );
		$this->map_float_value( $data, $weight, array( 'weight', 'weight_kg', 'poids' ) );

		$weight_class = $this->sanitize_text_value( $payload['weight_class'] ?? '' );
		$this->map_string_value( $data, $weight_class, array( 'weight_class', 'weight_cat', 'weight_category' ) );

		$category = $this->sanitize_text_value( $payload['category'] ?? '' );
		$this->map_string_value( $data, $category, array( 'category', 'category_name' ) );

		$level = $this->sanitize_text_value( $payload['level'] ?? '' );
		$this->map_string_value( $data, $level, array( 'level', 'class', 'classe' ) );

		$license_number = $this->sanitize_text_value( $payload['license_number'] ?? $payload['licence_number'] ?? '' );
		$this->map_string_value( $data, $license_number, array( 'license_number', 'licence_number', 'licensee_number', 'license', 'licence' ) );

		if ( isset( $payload['notes'] ) && $this->has_column( 'notes' ) ) {
			$data['notes'] = $this->sanitize_text_value( $payload['notes'] );
		}

		if ( isset( $payload['meta'] ) && $this->has_column( 'meta' ) ) {
			$data['meta'] = $this->sanitize_text_value( $payload['meta'] );
		}

		if ( isset( $payload['metadata'] ) && $this->has_column( 'metadata' ) ) {
			$data['metadata'] = $this->sanitize_text_value( $payload['metadata'] );
		}

		// Dates & authors.
		if ( $this->has_column( 'updated_at' ) ) {
			$data['updated_at'] = current_time( 'mysql' );
		}

		if ( $this->has_column( 'updated_by' ) ) {
			$data['updated_by'] = get_current_user_id() ?: null;
		}

		if ( $is_insert ) {
			if ( $this->has_column( 'created_at' ) ) {
				$data['created_at'] = current_time( 'mysql' );
			}
			if ( $this->has_column( 'created_by' ) ) {
				$data['created_by'] = get_current_user_id() ?: null;
			}
		}

		return $data;
	}

	private function map_string_value( array &$data, string $value, array $candidates ): void {
		if ( '' === $value ) {
			return;
		}

		foreach ( $candidates as $column ) {
			if ( $this->has_column( $column ) ) {
				$data[ $column ] = $value;
				return;
			}
		}
	}

	private function map_float_value( array &$data, $value, array $candidates ): void {
		if ( null === $value || '' === $value ) {
			return;
		}

		foreach ( $candidates as $column ) {
			if ( $this->has_column( $column ) ) {
				$data[ $column ] = $value;
				return;
			}
		}
	}

	private function sanitize_text_value( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = sanitize_text_field( $value );
		return $value;
	}

	private function sanitize_date_value( $value ): string {
		$value = $this->sanitize_text_value( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return '';
		}

		return $value;
	}

	private function sanitize_float_value( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}

		return (float) str_replace( ',', '.', $value );
	}

	private function build_formats( array $data ): array {
		$formats = array();

		foreach ( $data as $value ) {
			if ( is_int( $value ) ) {
				$formats[] = '%d';
			} elseif ( is_float( $value ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}

		return $formats;
	}

	private function get_columns(): array {
		static $cached = null;

		if ( null !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = Db::entries_table();
		$cols = $wpdb->get_col( "DESC {$table}", 0 );

		if ( ! is_array( $cols ) ) {
			$cols = array();
		}

		$cached = $cols;

		return $cached;
	}

	private function has_column( string $name ): bool {
		return in_array( $name, $this->columns, true );
	}

	private function extract_status_from_note( string $note ): string {
		if ( '' === $note ) {
			return '';
		}

		if ( preg_match_all( '/status:([a-z0-9_-]+)/', $note, $matches ) && ! empty( $matches[1] ) ) {
			$last = end( $matches[1] );
			return sanitize_key( (string) $last );
		}

		return '';
	}

	private function normalize_status( string $status ): string {
		if ( class_exists( EntriesWorkflow::class ) && method_exists( EntriesWorkflow::class, 'normalize_status' ) ) {
			$normalized = EntriesWorkflow::normalize_status( $status );
		} else {
			$normalized = sanitize_key( $status );
		}

		$allowed = $this->get_allowed_statuses();
		if ( ! in_array( $normalized, $allowed, true ) ) {
			return 'draft';
		}

		return $normalized;
	}

	private function resolve_licensee_column(): string {
		if ( $this->has_column( 'licensee_id' ) ) {
			return 'licensee_id';
		}
		if ( $this->has_column( 'licence_id' ) ) {
			return 'licence_id';
		}

		return '';
	}

	private function update_status( $entry, string $status, array $data, int $user_id = 0 ): array {
		global $wpdb;

		$entry_id = absint( $entry->id ?? 0 );
		if ( ! $entry_id ) {
			return $this->build_result( false, __( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		$status = $this->normalize_status( $status );
		$status_field = $this->get_status_storage_field();
		if ( '' === $status_field ) {
			return $this->build_result( false, __( 'Statut indisponible.', 'ufsc-licence-competition' ) );
		}

		if ( 'status' === $status_field ) {
			$data['status'] = $status;
		} else {
			$current_value = isset( $entry->{$status_field} ) ? (string) $entry->{$status_field} : '';
			$data[ $status_field ] = $this->append_status_note( $current_value, $status );
		}

		if ( $this->has_column( 'updated_at' ) ) {
			$data['updated_at'] = current_time( 'mysql' );
		}
		if ( $this->has_column( 'updated_by' ) ) {
			$data['updated_by'] = $user_id ? $user_id : ( get_current_user_id() ?: null );
		}

		$table = Db::entries_table();
		$updated = $wpdb->update(
			$table,
			$data,
			array( 'id' => $entry_id ),
			$this->build_formats( $data ),
			array( '%d' )
		);

		$this->maybe_log_db_error( __METHOD__ . ':update_status' );

		if ( false === $updated ) {
			return $this->build_result( false, __( 'Une erreur est survenue.', 'ufsc-licence-competition' ) );
		}

		$refreshed = $this->get( $entry_id );

		return $this->build_result( true, __( 'Statut mis à jour.', 'ufsc-licence-competition' ), $refreshed );
	}

	private function build_result( bool $ok, string $message, $entry = null ): array {
		return array(
			'ok' => $ok,
			'message' => $message,
			'entry' => $entry,
		);
	}
}
