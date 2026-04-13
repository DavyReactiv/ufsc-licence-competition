<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryRepository {
	private $logger;

	public function __construct() {
		$this->logger = new LogService();
	}

	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$where_deleted = $include_deleted ? '' : 'AND deleted_at IS NULL';

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::entries_table() . " WHERE id = %d {$where_deleted}",
				absint( $id )
			)
		);
	}

	public function get_with_details( int $entry_id, bool $include_deleted = false ) {
		$entry_id = absint( $entry_id );
		if ( ! $entry_id ) {
			return null;
		}

		$filters = array(
			'entry_id' => $entry_id,
		);
		if ( $include_deleted ) {
			$filters['include_deleted'] = true;
		}

		$rows = $this->list_with_details( $filters, 1, 0 );
		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return null;
		}

		$row = $rows[0] ?? null;
		if ( is_object( $row ) ) {
			$this->debug_repository_external_fallback_used( $row );
		}

		return $row;
	}

	public function assert_entry_in_scope( int $entry_id ): void {
		$scope_region = function_exists( 'ufsc_lc_competitions_get_user_scope_region' )
			? ufsc_lc_competitions_get_user_scope_region()
			: '';
		$scope_region = is_string( $scope_region ) ? sanitize_key( $scope_region ) : '';
		if ( '' === $scope_region ) {
			return;
		}

		$filters = array(
			'entry_id' => $entry_id,
			'view' => 'all',
			'scope_region' => $scope_region,
			'include_deleted' => true,
		);

		$rows = $this->list_with_details( $filters, 1, 0 );
		if ( empty( $rows ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}

	public function get_by_competition_licensee( $competition_id, $licensee_id ) {
		global $wpdb;

		$licensee_expr = $this->get_licensee_id_expression();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::entries_table() . " WHERE competition_id = %d AND {$licensee_expr} = %d",
				absint( $competition_id ),
				absint( $licensee_id )
			)
		);
	}

	public function list( array $filters, $limit, $offset ) {
		global $wpdb;

		$where = $this->build_where( $filters );
		$sql   = "SELECT * FROM " . Db::entries_table() . " {$where} ORDER BY created_at DESC";
		$sql  .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		return $wpdb->get_results( $sql );
	}

	public function count( array $filters ) {
		global $wpdb;

		$where = $this->build_where( $filters );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . Db::entries_table() . " {$where}" );
	}

	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared = $this->enforce_unique_fighter_number_payload( $prepared, 0 );
		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$inserted = $wpdb->insert( Db::entries_table(), $prepared, $this->build_formats( $prepared ) );
		$id = (int) $wpdb->insert_id;

		if ( false === $inserted ) {
			$this->logger->log( 'error', 'entry', 0, 'Entry insert failed.', array( 'error' => $wpdb->last_error ) );
			return 0;
		}

		$this->logger->log( 'create', 'entry', $id, 'Entry created.', array( 'data' => $prepared ) );

		return $id;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data, true );
		$prepared = $this->enforce_unique_fighter_number_payload( $prepared, absint( $id ) );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		$updated = $wpdb->update(
			Db::entries_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->build_formats( $prepared ),
			array( '%d' )
		);

		if ( false === $updated ) {
			$this->logger->log( 'error', 'entry', $id, 'Entry update failed.', array( 'error' => $wpdb->last_error ) );
		}

		$this->logger->log( 'update', 'entry', $id, 'Entry updated.', array( 'data' => $prepared ) );

		return $updated;
	}

	public function soft_delete( $id ) {
		return $this->set_deleted_at( $id, current_time( 'mysql' ), 'trash' );
	}

	public function restore( $id ) {
		return $this->set_deleted_at( $id, null, 'restore' );
	}

	public function delete( $id ) {
		global $wpdb;

		$deleted = $wpdb->delete( Db::entries_table(), array( 'id' => absint( $id ) ), array( '%d' ) );

		$this->logger->log( 'delete', 'entry', $id, 'Entry deleted permanently.', array() );

		return $deleted;
	}

	public function get_status_storage_field(): string {
		$columns = array( 'status', 'notes', 'meta', 'metadata' );
		foreach ( $columns as $column ) {
			if ( $this->has_entry_column( $column ) ) {
				return $column;
			}
		}

		return '';
	}

	public function get_entry_status( $entry ): string {
		if ( ! $entry ) {
			return 'draft';
		}

		$status_field = $this->get_status_storage_field();
		if ( 'status' === $status_field && isset( $entry->status ) ) {
			return $this->normalize_status( (string) $entry->status );
		}

		if ( $status_field && isset( $entry->{$status_field} ) ) {
			$status = $this->extract_status_from_note( (string) $entry->{$status_field} );
			if ( '' !== $status ) {
				return $this->normalize_status( $status );
			}
		}

		return 'draft';
	}

	public function has_entry_column( string $name ): bool {
		if ( '' === $name ) {
			return false;
		}

		return Db::has_table_column( Db::entries_table(), $name );
	}

	public function list_with_details( array $filters, int $limit, int $offset ): array {
		global $wpdb;

		$sql = $this->build_details_query( $filters, $limit, $offset, false );
		if ( '' === $sql ) {
			return array();
		}

		$rows = $wpdb->get_results( $sql );
		$rows = is_array( $rows ) ? $rows : array();
		$this->debug_repository_select_sample( $rows );

		return $rows;
	}

	public function count_with_details( array $filters ): int {
		global $wpdb;

		$sql = $this->build_details_query( $filters, 0, 0, true );
		if ( '' === $sql ) {
			return 0;
		}

		return (int) $wpdb->get_var( $sql );
	}

	public function get_competition_counters( array $competition_ids ): array {
		global $wpdb;

		$competition_ids = array_filter( array_map( 'absint', $competition_ids ) );
		if ( ! $competition_ids ) {
			return array();
		}

		if ( ! $this->has_entry_column( 'status' ) ) {
			return array();
		}

		$table = Db::entries_table();
		$where = array();
		$placeholders = implode( ',', array_fill( 0, count( $competition_ids ), '%d' ) );
		$where[] = $wpdb->prepare( "competition_id IN ({$placeholders})", $competition_ids );

		if ( $this->has_entry_column( 'deleted_at' ) ) {
			$where[] = 'deleted_at IS NULL';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$sql = "SELECT competition_id, status, COUNT(*) AS total FROM {$table} {$where_sql} GROUP BY competition_id, status";

		$rows = $wpdb->get_results( $sql );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$base = array(
			'total' => 0,
			'submitted' => 0,
			'pending' => 0,
			'approved' => 0,
			'rejected' => 0,
			'cancelled' => 0,
			'draft' => 0,
		);

		$output = array();
		foreach ( $competition_ids as $competition_id ) {
			$output[ $competition_id ] = $base;
		}

		foreach ( $rows as $row ) {
			$competition_id = (int) ( $row->competition_id ?? 0 );
			if ( ! $competition_id ) {
				continue;
			}
			$bucket = $this->normalize_status_for_count( (string) ( $row->status ?? '' ) );
			if ( '' === $bucket ) {
				$bucket = 'draft';
			}
			if ( ! isset( $output[ $competition_id ] ) ) {
				$output[ $competition_id ] = $base;
			}
			if ( ! isset( $output[ $competition_id ][ $bucket ] ) ) {
				$output[ $competition_id ][ $bucket ] = 0;
			}
			$output[ $competition_id ][ $bucket ] += (int) ( $row->total ?? 0 );
			$output[ $competition_id ]['total'] += (int) ( $row->total ?? 0 );
		}

		return $output;
	}

	public function count_by_status( int $competition_id, array $filters = array() ): array {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return array();
		}

		$counters = $this->get_competition_counters( array( $competition_id ) );

		return $counters[ $competition_id ] ?? array();
	}

	private function set_deleted_at( $id, $deleted_at, $action ) {
		global $wpdb;

		$updated = $wpdb->update(
			Db::entries_table(),
			array(
				'deleted_at' => $deleted_at,
				'updated_at' => current_time( 'mysql' ),
				'updated_by' => get_current_user_id() ?: null,
				'deleted_by' => $deleted_at ? ( get_current_user_id() ?: null ) : null,
			),
			array( 'id' => absint( $id ) ),
			array( '%s', '%s', '%d', '%d' ),
			array( '%d' )
		);

		$this->logger->log( $action, 'entry', $id, 'Entry status changed.', array( 'deleted_at' => $deleted_at ) );

		return $updated;
	}

	private function sanitize( array $data, bool $is_update = false ) {
		$table = Db::entries_table();
		$allowed_status = class_exists( EntriesWorkflow::class )
			? EntriesWorkflow::get_storage_statuses()
			: array( 'draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled' );
		$status = \UFSC\Competitions\Entries\EntriesWorkflow::normalize_status( (string) ( $data['status'] ?? 'draft' ) );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'draft';
		}

		$payload = array();
		if ( ! $is_update || array_key_exists( 'competition_id', $data ) ) {
			$candidate_competition_id = absint( $data['competition_id'] ?? 0 );
			if ( ! $is_update || $candidate_competition_id > 0 ) {
				$payload['competition_id'] = $candidate_competition_id;
			}
		}
		if ( ! $is_update || array_key_exists( 'category_id', $data ) ) {
			$payload['category_id'] = isset( $data['category_id'] ) && '' !== $data['category_id'] ? absint( $data['category_id'] ) : null;
		}
		if ( ! $is_update || array_key_exists( 'club_id', $data ) ) {
			$payload['club_id'] = isset( $data['club_id'] ) && '' !== $data['club_id'] ? absint( $data['club_id'] ) : null;
		}
		if ( ! $is_update || array_key_exists( 'status', $data ) ) {
			$payload['status'] = $status;
		}

		$licensee_value = absint( $data['licensee_id'] ?? $data['licence_id'] ?? 0 );
		$licensee_column = $this->get_licensee_id_column_for_write();
		$has_explicit_licensee = array_key_exists( 'licensee_id', $data ) || array_key_exists( 'licence_id', $data );
		if ( $licensee_column && ( ! $is_update || $has_explicit_licensee ) ) {
			$payload[ $licensee_column ] = $licensee_value;
		}

		if ( isset( $data['assigned_at'] ) && Db::has_table_column( $table, 'assigned_at' ) ) {
			$payload['assigned_at'] = sanitize_text_field( $data['assigned_at'] );
		}

		if ( Db::has_table_column( $table, 'weight_kg' ) && ( ! $is_update || array_key_exists( 'weight_kg', $data ) ) ) {
			$weight = isset( $data['weight_kg'] ) ? (float) str_replace( ',', '.', (string) $data['weight_kg'] ) : null;
			if ( null !== $weight && ( $weight <= 0 || $weight > 300 ) ) {
				$weight = null;
			}
			$payload['weight_kg'] = $weight;
		}

		if ( Db::has_table_column( $table, 'weight_class' ) && ( ! $is_update || array_key_exists( 'weight_class', $data ) ) ) {
			$weight_class = isset( $data['weight_class'] ) ? sanitize_text_field( $data['weight_class'] ) : '';
			$payload['weight_class'] = '' !== $weight_class ? $weight_class : null;
		}

		$category_label = isset( $data['category'] ) ? sanitize_text_field( (string) $data['category'] ) : '';
		if ( '' === $category_label && isset( $data['category_name'] ) ) {
			$category_label = sanitize_text_field( (string) $data['category_name'] );
		}
		$has_explicit_category_label = array_key_exists( 'category', $data ) || array_key_exists( 'category_name', $data );
		if ( Db::has_table_column( $table, 'category' ) && ( ! $is_update || $has_explicit_category_label ) ) {
			$payload['category'] = '' !== $category_label ? $category_label : null;
		}
		if ( Db::has_table_column( $table, 'category_name' ) && ( ! $is_update || $has_explicit_category_label ) ) {
			$payload['category_name'] = '' !== $category_label ? $category_label : null;
		}

		$optional_text_columns = array(
			'first_name'   => array( 'first_name', 'prenom' ),
			'last_name'    => array( 'last_name', 'nom' ),
			'birth_date'   => array( 'birth_date', 'date_naissance', 'birthdate' ),
			'birth_year'   => array( 'birth_year', 'annee_naissance' ),
			'sex'          => array( 'sex', 'sexe' ),
			'club_name'    => array( 'club_name', 'club_nom', 'structure_name' ),
			'club_nom'     => array( 'club_nom', 'club_name', 'structure_name' ),
			'discipline'   => array( 'discipline' ),
			'level'        => array( 'level', 'classe', 'class' ),
			'participant_type' => array( 'participant_type' ),
		);
		foreach ( $optional_text_columns as $column_name => $keys ) {
			if ( ! Db::has_table_column( $table, $column_name ) ) {
				continue;
			}
			if ( $is_update ) {
				$has_explicit_value = false;
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $data ) ) {
						$has_explicit_value = true;
						break;
					}
				}
				if ( ! $has_explicit_value ) {
					continue;
				}
			}
			$value = '';
			foreach ( $keys as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$value = sanitize_text_field( (string) $data[ $key ] );
					if ( '' !== $value ) {
						break;
					}
				}
			}
			$payload[ $column_name ] = '' !== $value ? $value : null;
		}

		$optional_int_columns = array(
			'fighter_number'     => array( 'fighter_number', 'competition_number', 'dossard' ),
			'competition_number' => array( 'competition_number', 'fighter_number', 'dossard' ),
			'dossard'            => array( 'dossard', 'fighter_number', 'competition_number' ),
		);
		foreach ( $optional_int_columns as $column_name => $keys ) {
			if ( ! Db::has_table_column( $table, $column_name ) ) {
				continue;
			}
			if ( $is_update ) {
				$has_explicit_value = false;
				foreach ( $keys as $key ) {
					if ( array_key_exists( $key, $data ) ) {
						$has_explicit_value = true;
						break;
					}
				}
				if ( ! $has_explicit_value ) {
					continue;
				}
			}
			$value = 0;
			foreach ( $keys as $key ) {
				if ( isset( $data[ $key ] ) ) {
					$value = absint( $data[ $key ] );
					if ( $value > 0 ) {
						break;
					}
				}
			}
			$payload[ $column_name ] = $value > 0 ? $value : null;
		}

		return $payload;
	}

	private function build_where( array $filters ) {
		global $wpdb;

		$where = array( '1=1' );
		$view  = $filters['view'] ?? 'all';

		if ( 'trash' === $view ) {
			$where[] = 'deleted_at IS NOT NULL';
		} else {
			$where[] = 'deleted_at IS NULL';
		}

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( 'competition_id = %d', absint( $filters['competition_id'] ) );
		}

		if ( ! empty( $filters['competition_ids'] ) && is_array( $filters['competition_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', $filters['competition_ids'] ) );
			if ( $ids ) {
				$where[] = 'competition_id IN (' . implode( ',', $ids ) . ')';
			}
		}

		if ( ! empty( $filters['status'] ) && $this->has_entry_column( 'status' ) ) {
			$variants = $this->resolve_status_filter_variants( $filters['status'] );
			if ( $variants ) {
				$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
				$where[] = $wpdb->prepare( "status IN ({$placeholders})", $variants );
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$licensee_expr = $this->get_licensee_id_expression();
			$where[] = $wpdb->prepare( "{$licensee_expr} LIKE %s", $like );
		}

		if ( ! empty( $filters['group_label'] ) && $this->has_entry_column( 'group_label' ) ) {
			$where[] = $wpdb->prepare( 'group_label = %s', sanitize_text_field( (string) $filters['group_label'] ) );
		}

		if ( ! empty( $filters['club_affiliation'] ) ) {
			$affiliation = sanitize_key( (string) $filters['club_affiliation'] );
			if ( 'non_affiliated' === $affiliation ) {
				if ( $this->has_entry_column( 'club_source' ) ) {
					$where[] = "(club_id IS NULL OR club_id = 0) AND club_source IN ('csv','external','invited')";
				} else {
					$where[] = '(club_id IS NULL OR club_id = 0)';
				}
			} elseif ( 'noclub' === $affiliation ) {
				if ( $this->has_entry_column( 'club_nom' ) ) {
					$where[] = "(club_id IS NULL OR club_id = 0) AND LOWER(TRIM(club_nom)) = 'noclub'";
				}
			}
		}

		if ( ! empty( $filters['scope_region'] ) ) {
			$scope_region = sanitize_key( (string) $filters['scope_region'] );
			$clubs_table = $this->get_clubs_table();
			$region_column = $this->get_club_region_column();
			if ( '' !== $scope_region && $clubs_table && $region_column ) {
				$where[] = $wpdb->prepare(
					"club_id IN (SELECT id FROM {$clubs_table} WHERE {$region_column} = %s)",
					$scope_region
				);
			} elseif ( '' !== $scope_region ) {
				$where[] = '1=0';
			}
		}

		return 'WHERE ' . implode( ' AND ', $where );
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

	private function enforce_unique_fighter_number_payload( array $payload, int $entry_id = 0 ): array {
		$table = Db::entries_table();
		$has_fighter_number = Db::has_table_column( $table, 'fighter_number' );
		$has_competition_number = Db::has_table_column( $table, 'competition_number' );
		$has_dossard = Db::has_table_column( $table, 'dossard' );
		$fighter_keys = array();
		if ( $has_fighter_number ) {
			$fighter_keys[] = 'fighter_number';
		}
		if ( $has_competition_number ) {
			$fighter_keys[] = 'competition_number';
		}
		if ( $has_dossard ) {
			$fighter_keys[] = 'dossard';
		}

		if ( empty( $fighter_keys ) ) {
			return $payload;
		}

		$requested_number = 0;
		foreach ( $fighter_keys as $key ) {
			if ( isset( $payload[ $key ] ) ) {
				$requested_number = absint( $payload[ $key ] );
				if ( $requested_number > 0 ) {
					break;
				}
			}
		}
		if ( $requested_number <= 0 ) {
			return $payload;
		}

		$competition_id = isset( $payload['competition_id'] ) ? absint( $payload['competition_id'] ) : 0;
		if ( $competition_id <= 0 && $entry_id > 0 ) {
			$current = $this->get_with_details( $entry_id, true );
			$competition_id = absint( $current->competition_id ?? 0 );
		}
		if ( $competition_id <= 0 ) {
			foreach ( $fighter_keys as $key ) {
				unset( $payload[ $key ] );
			}
			return $payload;
		}

		$duplicate_entry_id = $this->find_entry_id_by_fighter_number( $competition_id, $requested_number, $entry_id );
		$final_number       = $requested_number;
		if ( $duplicate_entry_id > 0 ) {
			$final_number = $this->next_available_fighter_number( $competition_id, $entry_id );
		}

		if ( $final_number <= 0 ) {
			return $payload;
		}

		foreach ( $fighter_keys as $key ) {
			$payload[ $key ] = $final_number;
		}

		return $payload;
	}

	private function find_entry_id_by_fighter_number( int $competition_id, int $fighter_number, int $exclude_entry_id = 0 ): int {
		$entries = $this->list_with_details(
			array(
				'view' => 'all',
				'competition_id' => $competition_id,
			),
			2000,
			0
		);

		foreach ( $entries as $entry ) {
			$current_entry_id = absint( $entry->id ?? 0 );
			if ( $current_entry_id <= 0 || $current_entry_id === $exclude_entry_id ) {
				continue;
			}
			$current_fighter_number = absint( $entry->fighter_number ?? $entry->competition_number ?? $entry->dossard ?? 0 );
			if ( $current_fighter_number === $fighter_number ) {
				return $current_entry_id;
			}
		}

		return 0;
	}

	private function next_available_fighter_number( int $competition_id, int $exclude_entry_id = 0 ): int {
		$used    = array();
		$entries = $this->list_with_details(
			array(
				'view' => 'all',
				'competition_id' => $competition_id,
			),
			2000,
			0
		);
		foreach ( $entries as $entry ) {
			$current_entry_id = absint( $entry->id ?? 0 );
			if ( $current_entry_id <= 0 || $current_entry_id === $exclude_entry_id ) {
				continue;
			}
			$current_fighter_number = absint( $entry->fighter_number ?? $entry->competition_number ?? $entry->dossard ?? 0 );
			if ( $current_fighter_number > 0 ) {
				$used[ $current_fighter_number ] = true;
			}
		}

		$next = 1;
		while ( isset( $used[ $next ] ) ) {
			$next++;
		}

		return $next;
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
			return EntriesWorkflow::normalize_status( $status );
		}

		$status = sanitize_key( $status );

		$allowed = class_exists( EntriesWorkflow::class )
			? EntriesWorkflow::get_storage_statuses()
			: array( 'draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return 'draft';
		}

		return $status;
	}

	private function build_details_query( array $filters, int $limit, int $offset, bool $count ): string {
		global $wpdb;

		$table = Db::entries_table();
		$entries_alias = 'e';
		$select = $count ? 'COUNT(*)' : "{$entries_alias}.*";
		$joins = array();
		$where = array( '1=1' );
		$entry_search_exprs = array();
		$search_likes       = array();
		$entry_columns      = Db::get_table_columns( $table );
		$entry_birth_date_expr = $this->build_entry_date_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'birth_date', 'birthdate', 'date_naissance', 'date_of_birth', 'dob' )
		);
		$entry_birth_year_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'birth_year', 'annee_naissance', 'year_of_birth', 'year' )
		);
		$entry_club_name_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'club_name', 'club_nom', 'structure_name', 'club', 'club_label', 'club_import', 'club_raw', 'club_value' )
		);
		$entry_submitted_at_expr = $this->build_entry_date_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'submitted_at', 'submitted', 'date_submitted', 'created_at', 'imported_at' )
		);
		$entry_weight_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'weight_kg', 'weight', 'poids' )
		);
		$entry_weight_class_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'weight_class', 'weight_category', 'weight_cat', 'categorie_poids' )
		);
		$entry_category_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'category_name', 'category', 'category_label' )
		);
		$entry_discipline_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'discipline' )
		);
		$entry_level_expr = $this->build_entry_text_expression(
			$entries_alias . '.',
			$entry_columns,
			array( 'level', 'classe', 'class', 'niveau' )
		);

		$view            = $filters['view'] ?? 'all';
		$include_deleted = ! empty( $filters['include_deleted'] );
		if ( ! $include_deleted ) {
			if ( 'trash' === $view && $this->has_entry_column( 'deleted_at' ) ) {
				$where[] = "{$entries_alias}.deleted_at IS NOT NULL";
			} elseif ( $this->has_entry_column( 'deleted_at' ) ) {
				$where[] = "{$entries_alias}.deleted_at IS NULL";
			}
		}

		if ( ! empty( $filters['competition_id'] ) ) {
			$where[] = $wpdb->prepare( "{$entries_alias}.competition_id = %d", absint( $filters['competition_id'] ) );
		}

		if ( ! empty( $filters['competition_ids'] ) && is_array( $filters['competition_ids'] ) ) {
			$ids = array_filter( array_map( 'absint', $filters['competition_ids'] ) );
			if ( $ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$where[] = $wpdb->prepare( "{$entries_alias}.competition_id IN ({$placeholders})", $ids );
			}
		}

		if ( ! empty( $filters['club_id'] ) ) {
			$where[] = $wpdb->prepare( "{$entries_alias}.club_id = %d", absint( $filters['club_id'] ) );
		}

		if ( ! empty( $filters['entry_id'] ) ) {
			$where[] = $wpdb->prepare( "{$entries_alias}.id = %d", absint( $filters['entry_id'] ) );
		}
		if ( ! empty( $filters['entry_ids'] ) && is_array( $filters['entry_ids'] ) ) {
			$entry_ids = array_values( array_filter( array_map( 'absint', $filters['entry_ids'] ) ) );
			if ( $entry_ids ) {
				$placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
				$where[] = $wpdb->prepare( "{$entries_alias}.id IN ({$placeholders})", $entry_ids );
			}
		}

		$status_field = $this->get_status_storage_field();
		if ( ! empty( $filters['status'] ) && 'status' === $status_field ) {
			$variants = $this->resolve_status_filter_variants( $filters['status'] );
			if ( $variants ) {
				$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
				$where[] = $wpdb->prepare( "{$entries_alias}.status IN ({$placeholders})", $variants );
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$search_likes    = $this->build_search_like_values( (string) $filters['search'] );
			$searchable_map = array(
				'first_name',
				'prenom',
				'firstname',
				'last_name',
				'nom',
				'lastname',
				'license_number',
				'licence_number',
				'numero_licence',
				'numero_licence_asptt',
				'club_name',
				'club_nom',
				'weight_kg',
				'weight_class',
				'category',
				'category_name',
				'discipline',
				'level',
				'birth_date',
				'birth_year',
				'participant_type',
				'fighter_number',
				'competition_number',
				'dossard',
			);
			foreach ( $searchable_map as $column ) {
				if ( in_array( $column, $entry_columns, true ) ) {
					foreach ( $search_likes as $like ) {
						$entry_search_exprs[] = $wpdb->prepare( "{$entries_alias}.{$column} LIKE %s", $like );
					}
				}
			}
			foreach ( array( $entry_weight_expr, $entry_weight_class_expr, $entry_category_expr, $entry_discipline_expr, $entry_level_expr, $entry_birth_date_expr, $entry_birth_year_expr ) as $expression ) {
				if ( "''" === $expression ) {
					continue;
				}
				foreach ( $search_likes as $like ) {
					$entry_search_exprs[] = $wpdb->prepare( "{$expression} LIKE %s", $like );
				}
			}
		}

		if ( ! empty( $filters['group_label'] ) && $this->has_entry_column( 'group_label' ) ) {
			$where[] = $wpdb->prepare( "{$entries_alias}.group_label = %s", sanitize_text_field( (string) $filters['group_label'] ) );
		}

		if ( ! empty( $filters['club_affiliation'] ) ) {
			$affiliation = sanitize_key( (string) $filters['club_affiliation'] );
			if ( 'non_affiliated' === $affiliation ) {
				if ( $this->has_entry_column( 'club_source' ) ) {
					$where[] = "({$entries_alias}.club_id IS NULL OR {$entries_alias}.club_id = 0) AND {$entries_alias}.club_source IN ('csv','external','invited')";
				} else {
					$where[] = "({$entries_alias}.club_id IS NULL OR {$entries_alias}.club_id = 0)";
				}
			} elseif ( 'noclub' === $affiliation && $this->has_entry_column( 'club_nom' ) ) {
				$where[] = "({$entries_alias}.club_id IS NULL OR {$entries_alias}.club_id = 0) AND LOWER(TRIM({$entries_alias}.club_nom)) = 'noclub'";
			}
		}

		$clubs_table = $this->get_clubs_table();
		$licences_table = $this->get_licences_table();
		$licence_columns = $licences_table ? Db::get_table_columns( $licences_table ) : array();
		$licensee_expr = $this->get_licensee_id_expression( $entries_alias );
		$license_club_column = false;
		$club_join_expr = "{$entries_alias}.club_id";
		$entry_first_name_expr = $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'first_name', 'firstname', 'prenom', 'given_name' ) );
		$entry_last_name_expr  = $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'last_name', 'lastname', 'nom', 'family_name' ) );
		$entry_fighter_expr    = $this->build_entry_int_expression( $entries_alias . '.', $entry_columns, array( 'fighter_number', 'competition_number', 'dossard' ) );
		$entry_license_number_expr = $this->build_entry_license_number_expression( $entries_alias . '.', $entry_columns );
		$external_participant_type_expr = "'licensed_ufsc'";
		$external_first_name_expr       = "''";
		$external_last_name_expr        = "''";
		$external_birth_date_expr       = "''";
		$external_birth_year_expr       = "''";
		$external_club_name_expr        = "''";
		$external_discipline_expr       = "''";
		$external_level_expr            = "''";
		$external_email_expr            = "''";
		$external_phone_expr            = "''";
		$external_comment_expr          = "''";
		$external_table = Db::external_participants_table();
		$external_exists = Db::table_exists( $external_table );
		if ( $external_exists ) {
			$external_columns = Db::get_table_columns( $external_table );
			$joins[] = "LEFT JOIN {$external_table} ep ON ep.entry_id = {$entries_alias}.id";
			$external_participant_type_expr = "COALESCE(NULLIF(ep.participant_type, ''), CASE WHEN (NULLIF({$entry_license_number_expr}, '') IS NULL AND {$licensee_expr} >= 1000000000) THEN 'external_non_licensed' ELSE 'licensed_ufsc' END)";
			$external_first_name_expr       = "NULLIF(ep.first_name, '')";
			$external_last_name_expr        = "NULLIF(ep.last_name, '')";
			$external_birth_date_expr       = "CASE WHEN ep.birth_date IS NULL THEN '' ELSE CAST(ep.birth_date AS CHAR) END";
			$external_birth_year_expr       = "CASE WHEN ep.birth_date IS NULL THEN '' ELSE SUBSTRING(CAST(ep.birth_date AS CHAR), 1, 4) END";
			$external_club_name_expr        = "NULLIF(ep.club_name, '')";
			if ( in_array( 'discipline', $external_columns, true ) ) {
				$external_discipline_expr = "NULLIF(ep.discipline, '')";
			}
			if ( in_array( 'level', $external_columns, true ) ) {
				$external_level_expr = "NULLIF(ep.level, '')";
			}
			if ( in_array( 'legal_guardian_email', $external_columns, true ) ) {
				$external_email_expr = "NULLIF(ep.legal_guardian_email, '')";
			}
			if ( in_array( 'legal_guardian_phone', $external_columns, true ) ) {
				$external_phone_expr = "NULLIF(ep.legal_guardian_phone, '')";
			}
			if ( in_array( 'medical_notes', $external_columns, true ) ) {
				$external_comment_expr = "NULLIF(ep.medical_notes, '')";
			}
		}

		if ( ! empty( $filters['participant_type'] ) ) {
			$participant_type = sanitize_key( (string) $filters['participant_type'] );
			if ( in_array( $participant_type, array( 'licensed_ufsc', 'external_non_licensed' ), true ) ) {
				$where[] = $wpdb->prepare( "{$external_participant_type_expr} = %s", $participant_type );
			}
		}

		if ( ! $count ) {
			$select .= ", {$external_participant_type_expr} AS participant_type";
			$select .= ", COALESCE(NULLIF(" . $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'discipline' ) ) . ", ''), {$external_discipline_expr}) AS discipline";
			$select .= ", COALESCE(NULLIF(" . $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'level', 'classe', 'class', 'niveau' ) ) . ", ''), {$external_level_expr}) AS level";
			$select .= ", COALESCE(NULLIF(" . $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'email', 'mail', 'contact_email' ) ) . ", ''), {$external_email_expr}) AS email";
			$select .= ", COALESCE(NULLIF(" . $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'phone', 'telephone', 'tel', 'contact_phone' ) ) . ", ''), {$external_phone_expr}) AS phone";
			$select .= ", COALESCE(NULLIF(" . $this->build_entry_text_expression( $entries_alias . '.', $entry_columns, array( 'comment', 'comments', 'notes', 'medical_notes' ) ) . ", ''), {$external_comment_expr}) AS comment";
		}

		if ( ! $count && ( ! $licences_table || ! $licence_columns ) ) {
			$select .= ", {$entry_first_name_expr} AS first_name";
			$select .= ", {$entry_last_name_expr} AS last_name";
			$select .= ", {$entry_birth_date_expr} AS birth_date";
			$select .= ", COALESCE(NULLIF({$entry_birth_year_expr}, ''), NULLIF(SUBSTRING({$entry_birth_date_expr}, 1, 4), '')) AS birth_year";
			$select .= ", {$entry_license_number_expr} AS license_number";
			$select .= ", {$entry_fighter_expr} AS fighter_number";
			$select .= ", {$entry_submitted_at_expr} AS submitted_at";
		}

		if ( $licences_table && $licence_columns ) {
			$joins[] = "LEFT JOIN {$licences_table} l ON l.id = {$licensee_expr}";

			$license_club_column = in_array( 'club_id', $licence_columns, true );
			if ( $license_club_column ) {
				$club_join_expr = "COALESCE({$entries_alias}.club_id, l.club_id)";
			}

			if ( ! $count ) {
				$last_name_columns = array();
				foreach ( array( 'nom', 'nom_licence' ) as $column ) {
					if ( in_array( $column, $licence_columns, true ) ) {
						$last_name_columns[] = $column;
					}
				}
				$last_name_expr = $this->build_coalesce_expression( 'l.', $last_name_columns, '' );
				$license_number_expr = $this->build_license_number_expression( $licence_columns );
				$first_name_select = in_array( 'prenom', $licence_columns, true ) ? 'l.prenom' : "''";
				$birthdate_select = in_array( 'date_naissance', $licence_columns, true ) ? 'CAST(l.date_naissance AS CHAR)' : 'NULL';
				$sex_select = "''";
				foreach ( array( 'sex', 'sexe', 'gender' ) as $sex_column ) {
					if ( in_array( $sex_column, $licence_columns, true ) ) {
						$sex_select = 'l.' . $sex_column;
						break;
					}
				}
				$select .= ", {$last_name_expr} AS licensee_last_name, {$first_name_select} AS licensee_first_name";
				$select .= ", {$birthdate_select} AS licensee_birthdate";
				$select .= ", {$sex_select} AS licensee_sex";
				$select .= ", COALESCE(NULLIF({$entry_license_number_expr}, ''), {$license_number_expr}) AS license_number";
				$select .= $license_club_column ? ', l.club_id AS licensee_club_id' : ", NULL AS licensee_club_id";
				$select .= ", COALESCE(NULLIF({$entry_first_name_expr}, ''), {$external_first_name_expr}, NULLIF({$first_name_select}, '')) AS first_name";
				$select .= ", COALESCE(NULLIF({$entry_last_name_expr}, ''), {$external_last_name_expr}, NULLIF({$last_name_expr}, '')) AS last_name";
				$select .= ", COALESCE(NULLIF({$entry_birth_date_expr}, ''), {$external_birth_date_expr}, {$birthdate_select}) AS birth_date";
				$select .= ", COALESCE(NULLIF({$entry_birth_year_expr}, ''), NULLIF(SUBSTRING({$entry_birth_date_expr}, 1, 4), ''), {$external_birth_year_expr}, NULLIF(SUBSTRING({$birthdate_select}, 1, 4), '')) AS birth_year";
				$select .= ", CASE WHEN NULLIF({$entry_first_name_expr}, '') IS NOT NULL THEN 'entry' WHEN {$external_first_name_expr} IS NOT NULL THEN 'external_participants' WHEN NULLIF({$first_name_select}, '') IS NOT NULL THEN 'license' ELSE '' END AS _source_first_name";
				$select .= ", CASE WHEN NULLIF({$entry_last_name_expr}, '') IS NOT NULL THEN 'entry' WHEN {$external_last_name_expr} IS NOT NULL THEN 'external_participants' WHEN NULLIF({$last_name_expr}, '') IS NOT NULL THEN 'license' ELSE '' END AS _source_last_name";
				$select .= ", CASE WHEN NULLIF({$entry_birth_date_expr}, '') IS NOT NULL THEN 'entry' WHEN {$external_birth_date_expr} <> '' THEN 'external_participants' WHEN {$birthdate_select} IS NOT NULL AND {$birthdate_select} <> '' THEN 'license' ELSE '' END AS _source_birth_date";
				$select .= ", {$entry_fighter_expr} AS fighter_number";
				$select .= ", {$entry_submitted_at_expr} AS submitted_at";
			}

			if ( $count || ! empty( $filters['search'] ) ) {
				$name_columns = array();
				foreach ( array( 'nom', 'nom_licence' ) as $column ) {
					if ( in_array( $column, $licence_columns, true ) ) {
						$name_columns[] = $column;
					}
				}
				$name_expr = $this->build_coalesce_expression( 'l.', $name_columns, '' );
				$search_exprs = array();
				if ( ! empty( $filters['search'] ) ) {
					if ( ! empty( $entry_search_exprs ) ) {
						$search_exprs = array_merge( $search_exprs, $entry_search_exprs );
					}
					foreach ( $search_likes as $like ) {
						if ( "''" !== $name_expr ) {
							$search_exprs[] = $wpdb->prepare( "{$name_expr} LIKE %s", $like );
						}
						if ( in_array( 'prenom', $licence_columns, true ) ) {
							$search_exprs[] = $wpdb->prepare( 'l.prenom LIKE %s', $like );
						}
						$license_number_expr = $this->build_license_number_expression( $licence_columns );
						if ( "''" !== $license_number_expr ) {
							$search_exprs[] = $wpdb->prepare( "{$license_number_expr} LIKE %s", $like );
						}
						$search_exprs[] = $wpdb->prepare( "{$entry_fighter_expr} LIKE %s", $like );
						$search_exprs[] = $wpdb->prepare( "{$external_participant_type_expr} LIKE %s", $like );
						if ( $clubs_table ) {
							$search_exprs[] = $wpdb->prepare( 'c.nom LIKE %s', $like );
						}
					}
				}

				if ( ! empty( $filters['search'] ) ) {
					foreach ( $search_likes as $like ) {
						$search_exprs[] = $wpdb->prepare( "{$licensee_expr} LIKE %s", $like );
					}
					$where[] = '(' . implode( ' OR ', $search_exprs ) . ')';
				}
			}
		} elseif ( ! empty( $filters['search'] ) ) {
			$search_exprs = $entry_search_exprs;
			foreach ( $search_likes as $like ) {
				$search_exprs[] = $wpdb->prepare( "{$licensee_expr} LIKE %s", $like );
			}
			$where[] = '(' . implode( ' OR ', $search_exprs ) . ')';
		}

		$scope_region = ! empty( $filters['scope_region'] ) ? sanitize_key( (string) $filters['scope_region'] ) : '';
		$region_column = $this->get_club_region_column();
		$needs_club_join = ( $clubs_table && ( ! $count || '' !== $scope_region || ! empty( $filters['search'] ) ) );

		if ( $needs_club_join ) {
			$joins[] = "LEFT JOIN {$clubs_table} c ON c.id = {$club_join_expr}";
			if ( ! $count ) {
				$select .= ", COALESCE({$external_club_name_expr}, NULLIF({$entry_club_name_expr}, ''), NULLIF(c.nom, '')) AS club_name";
				$select .= ", CASE WHEN {$external_club_name_expr} IS NOT NULL THEN 'external_participants' WHEN NULLIF({$entry_club_name_expr}, '') IS NOT NULL THEN 'entry' WHEN NULLIF(c.nom, '') IS NOT NULL THEN 'club_join' ELSE '' END AS _source_club_name";
				$club_columns = Db::get_table_columns( $clubs_table );
				if ( is_array( $club_columns ) ) {
					if ( in_array( 'ville', $club_columns, true ) ) {
						$select .= ', c.ville AS club_city';
					} elseif ( in_array( 'city', $club_columns, true ) ) {
						$select .= ', c.city AS club_city';
					} else {
						$select .= ', NULL AS club_city';
					}
				}
			}
		} elseif ( ! $count ) {
			$select .= ", COALESCE({$external_club_name_expr}, NULLIF({$entry_club_name_expr}, '')) AS club_name";
			$select .= ", CASE WHEN {$external_club_name_expr} IS NOT NULL THEN 'external_participants' WHEN NULLIF({$entry_club_name_expr}, '') IS NOT NULL THEN 'entry' ELSE '' END AS _source_club_name";
		}

		if ( '' !== $scope_region ) {
			if ( $region_column ) {
				$where[] = $wpdb->prepare( "c.{$region_column} = %s", $scope_region );
			} else {
				$where[] = '1=0';
			}
		}
		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$join_sql = $joins ? ' ' . implode( ' ', $joins ) : '';

		if ( $count ) {
			return "SELECT {$select} FROM {$table} {$entries_alias}{$join_sql} {$where_sql}";
		}

		$order_by = 'created_at DESC';
		if ( $this->has_entry_column( 'updated_at' ) ) {
			$order_by = "{$entries_alias}.updated_at DESC, {$entries_alias}.created_at DESC";
		}

		$sql = "SELECT {$select} FROM {$table} {$entries_alias}{$join_sql} {$where_sql} ORDER BY {$order_by}";
		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );
		}

		return $sql;
	}

	private function get_licensee_id_column_for_write(): string {
		$table = Db::entries_table();
		if ( Db::has_table_column( $table, 'licensee_id' ) ) {
			return 'licensee_id';
		}
		if ( Db::has_table_column( $table, 'licence_id' ) ) {
			return 'licence_id';
		}

		return '';
	}

	private function get_licensee_id_expression( string $alias = '' ): string {
		$table = Db::entries_table();
		$prefix = $alias ? $alias . '.' : '';
		$has_licensee = Db::has_table_column( $table, 'licensee_id' );
		$has_licence = Db::has_table_column( $table, 'licence_id' );

		if ( $has_licensee && $has_licence ) {
			return "COALESCE({$prefix}licensee_id, {$prefix}licence_id)";
		}
		if ( $has_licensee ) {
			return "{$prefix}licensee_id";
		}
		if ( $has_licence ) {
			return "{$prefix}licence_id";
		}

		return "{$prefix}licensee_id";
	}

	private function build_coalesce_expression( string $prefix, array $columns, string $fallback ): string {
		$parts = array();
		foreach ( $columns as $column ) {
			$parts[] = "NULLIF({$prefix}{$column}, '')";
		}

		if ( ! $parts ) {
			return "''";
		}

		$fallback = esc_sql( $fallback );
		$parts[] = "'" . $fallback . "'";

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function build_search_like_values( string $raw_search ): array {
		global $wpdb;

		$raw_search = trim( sanitize_text_field( $raw_search ) );
		if ( '' === $raw_search ) {
			return array();
		}

		$variants = array( $raw_search );
		$without_hash = ltrim( $raw_search, "# \t\n\r\0\x0B" );
		if ( '' !== $without_hash && $without_hash !== $raw_search ) {
			$variants[] = $without_hash;
		}

		$likes = array();
		foreach ( array_unique( $variants ) as $variant ) {
			$likes[] = '%' . $wpdb->esc_like( $variant ) . '%';
		}

		return $likes;
	}

	private function build_license_number_expression( array $columns ): string {
		$candidates = array(
			'numero_licence_asptt',
			'numero_licence_delegataire',
			'numero_licence',
			'licence_number',
			'license_number',
			'licensee_number',
			'licence',
			'license',
		);

		$available = array();
		foreach ( $candidates as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$available[] = $column;
			}
		}

		if ( ! $available ) {
			return "''";
		}

		$parts = array();
		foreach ( $available as $column ) {
			$parts[] = "NULLIF(l.{$column}, '')";
		}

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function build_entry_license_number_expression( string $prefix, $columns ): string {
		if ( ! is_array( $columns ) ) {
			return "''";
		}

		$candidates = array(
			'license_number',
			'licence_number',
			'licensee_number',
			'license',
			'licence',
			'numero_licence',
			'numero_licence_asptt',
		);

		$available = array();
		foreach ( $candidates as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$available[] = $column;
			}
		}

		if ( ! $available ) {
			return "''";
		}

		$parts = array();
		foreach ( $available as $column ) {
			$parts[] = "NULLIF({$prefix}{$column}, '')";
		}
		$parts[] = "''";

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function build_entry_text_expression( string $prefix, $columns, array $candidates ): string {
		if ( ! is_array( $columns ) ) {
			return "''";
		}

		$available = array();
		foreach ( $candidates as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$available[] = $column;
			}
		}
		if ( ! $available ) {
			return "''";
		}

		$parts = array();
		foreach ( $available as $column ) {
			$parts[] = "NULLIF({$prefix}{$column}, '')";
		}
		$parts[] = "''";

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function build_entry_date_expression( string $prefix, $columns, array $candidates ): string {
		if ( ! is_array( $columns ) ) {
			return "''";
		}

		$parts = array();
		foreach ( $candidates as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$parts[] = "NULLIF(CAST({$prefix}{$column} AS CHAR), '')";
			}
		}
		if ( ! $parts ) {
			return "''";
		}
		$parts[] = "''";

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function build_entry_int_expression( string $prefix, $columns, array $candidates ): string {
		if ( ! is_array( $columns ) ) {
			return '0';
		}

		$parts = array();
		foreach ( $candidates as $column ) {
			if ( in_array( $column, $columns, true ) ) {
				$parts[] = "NULLIF({$prefix}{$column}, 0)";
			}
		}
		if ( ! $parts ) {
			return '0';
		}
		$parts[] = '0';

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function debug_repository_select_sample( array $rows ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || empty( $rows ) ) {
			return;
		}

		$first = $rows[0];
		if ( ! is_object( $first ) ) {
			return;
		}

		$keys = array_keys( get_object_vars( $first ) );
		error_log(
			'UFSC EntryRepository repository_select_sample_keys ' .
			wp_json_encode(
				array(
					'count' => count( $rows ),
					'keys'  => $keys,
				)
			)
		);
	}

	private function debug_repository_external_fallback_used( $row ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! is_object( $row ) ) {
			return;
		}

		$participant_type = sanitize_key( (string) ( $row->participant_type ?? '' ) );
		if ( 'external_non_licensed' !== $participant_type ) {
			return;
		}

		$entry_id = (int) ( $row->id ?? 0 );
		$fields = array(
			'first_name' => '_source_first_name',
			'last_name'  => '_source_last_name',
			'birth_date' => '_source_birth_date',
			'club_name'  => '_source_club_name',
		);

		foreach ( $fields as $field_name => $source_field ) {
			$selected_value = $this->get_object_non_empty_value( $row, array( $field_name ) );
			$chosen_source  = $this->get_object_non_empty_value( $row, array( $source_field ) );
			if ( '' === $selected_value || '' === $chosen_source || 'entry' === $chosen_source ) {
				continue;
			}

			error_log(
				'UFSC EntryRepository repository_external_fallback_used ' .
				wp_json_encode(
					array(
						'entry_id'       => $entry_id,
						'field_name'     => $field_name,
						'chosen_source'  => $chosen_source,
					)
				)
			);
		}
	}

	private function get_object_non_empty_value( $item, array $keys ): string {
		if ( ! is_object( $item ) ) {
			return '';
		}

		foreach ( $keys as $key ) {
			if ( property_exists( $item, $key ) ) {
				$value = $item->{$key};
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					return sanitize_text_field( (string) $value );
				}
			}
		}

		return '';
	}

	private function get_licences_table(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		return Db::table_exists( $table ) ? $table : '';
	}

	private function get_clubs_table(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_clubs';
		return Db::table_exists( $table ) ? $table : '';
	}

	private function get_club_region_column(): string {
		$clubs_table = $this->get_clubs_table();
		if ( '' === $clubs_table ) {
			return '';
		}

		return Db::has_table_column( $clubs_table, 'region' ) ? 'region' : '';
	}

	private function get_status_variants( string $status ): array {
		$status = trim( (string) $status );
		if ( '' === $status ) {
			return array();
		}

		if ( class_exists( EntriesWorkflow::class ) && method_exists( EntriesWorkflow::class, 'get_status_variants' ) ) {
			return EntriesWorkflow::get_status_variants( $status );
		}

		return array( $this->normalize_status( $status ) );
	}

	private function resolve_status_filter_variants( $status_filter ): array {
		$requested = is_array( $status_filter ) ? $status_filter : array( $status_filter );
		$variants  = array();
		foreach ( $requested as $status ) {
			$status = sanitize_key( (string) $status );
			if ( '' === $status ) {
				continue;
			}
			$variants = array_merge( $variants, $this->get_status_variants( $status ) );
		}

		return array_values( array_unique( array_filter( $variants ) ) );
	}

	private function normalize_status_for_count( string $status ): string {
		$normalized = $this->normalize_status( $status );
		if ( 'validated' === $normalized ) {
			return 'approved';
		}

		return $normalized;
	}

	private function normalize_status_slug( string $status ): string {
		$status = trim( (string) $status );
		if ( '' === $status ) {
			return '';
		}

		$status = strtolower( $status );
		$status = strtr(
			$status,
			array(
				'é' => 'e',
				'è' => 'e',
				'ê' => 'e',
				'à' => 'a',
				'â' => 'a',
				'ç' => 'c',
				'ù' => 'u',
				'û' => 'u',
				'ï' => 'i',
				'î' => 'i',
			)
		);
		$status = preg_replace( '/[^a-z0-9]+/', '_', $status );
		$status = trim( (string) $status, '_' );

		return sanitize_key( $status );
	}
}
