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

		return $rows[0] ?? null;
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

		$prepared = $this->sanitize( $data );
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

		return is_array( $rows ) ? $rows : array();
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

	private function sanitize( array $data ) {
		$table = Db::entries_table();
		$allowed_status = array( 'draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled' );
		$status = \UFSC\Competitions\Entries\EntriesWorkflow::normalize_status( (string) ( $data['status'] ?? 'draft' ) );
		if ( ! in_array( $status, $allowed_status, true ) ) {
			$status = 'draft';
		}

		$payload = array(
			'competition_id' => absint( $data['competition_id'] ?? 0 ),
			'category_id'    => isset( $data['category_id'] ) && '' !== $data['category_id'] ? absint( $data['category_id'] ) : null,
			'club_id'        => isset( $data['club_id'] ) && '' !== $data['club_id'] ? absint( $data['club_id'] ) : null,
			'status'         => $status,
		);

		$licensee_value = absint( $data['licensee_id'] ?? $data['licence_id'] ?? 0 );
		$licensee_column = $this->get_licensee_id_column_for_write();
		if ( $licensee_column ) {
			$payload[ $licensee_column ] = $licensee_value;
		}

		if ( isset( $data['assigned_at'] ) && Db::has_table_column( $table, 'assigned_at' ) ) {
			$payload['assigned_at'] = sanitize_text_field( $data['assigned_at'] );
		}

		if ( Db::has_table_column( $table, 'weight_kg' ) ) {
			$weight = isset( $data['weight_kg'] ) ? (float) str_replace( ',', '.', (string) $data['weight_kg'] ) : null;
			if ( null !== $weight && ( $weight <= 0 || $weight > 300 ) ) {
				$weight = null;
			}
			$payload['weight_kg'] = $weight;
		}

		if ( Db::has_table_column( $table, 'weight_class' ) ) {
			$weight_class = isset( $data['weight_class'] ) ? sanitize_text_field( $data['weight_class'] ) : '';
			$payload['weight_class'] = '' !== $weight_class ? $weight_class : null;
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
			$variants = $this->get_status_variants( (string) $filters['status'] );
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

		$allowed = array( 'draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled' );
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

		$status_field = $this->get_status_storage_field();
		if ( ! empty( $filters['status'] ) && 'status' === $status_field ) {
			$variants = $this->get_status_variants( (string) $filters['status'] );
			if ( $variants ) {
				$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
				$where[] = $wpdb->prepare( "{$entries_alias}.status IN ({$placeholders})", $variants );
			}
		}

		$licences_table = $this->get_licences_table();
		$licence_columns = $licences_table ? Db::get_table_columns( $licences_table ) : array();
		$licensee_expr = $this->get_licensee_id_expression( $entries_alias );
		$license_club_column = false;
		$club_join_expr = "{$entries_alias}.club_id";

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
				$birthdate_select = in_array( 'date_naissance', $licence_columns, true ) ? 'l.date_naissance' : "''";
				$select .= ", {$last_name_expr} AS licensee_last_name, {$first_name_select} AS licensee_first_name";
				$select .= ", {$birthdate_select} AS licensee_birthdate";
				$select .= ", {$license_number_expr} AS license_number";
				$select .= $license_club_column ? ', l.club_id AS licensee_club_id' : ", NULL AS licensee_club_id";
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
					$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
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
				}

				if ( ! empty( $filters['search'] ) ) {
					$search_exprs[] = $wpdb->prepare( "{$licensee_expr} LIKE %s", $like );
					$where[] = '(' . implode( ' OR ', $search_exprs ) . ')';
				}
			}
		} elseif ( ! empty( $filters['search'] ) ) {
			$like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[] = $wpdb->prepare( "{$licensee_expr} LIKE %s", $like );
		}

		$clubs_table = $this->get_clubs_table();
		$scope_region = ! empty( $filters['scope_region'] ) ? sanitize_key( (string) $filters['scope_region'] ) : '';
		$region_column = $this->get_club_region_column();
		$needs_club_join = ( $clubs_table && ( ! $count || '' !== $scope_region ) );

		if ( $needs_club_join ) {
			$joins[] = "LEFT JOIN {$clubs_table} c ON c.id = {$club_join_expr}";
			if ( ! $count ) {
				$select .= ', c.nom AS club_name';
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

	private function get_licences_table(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return ( $exists === $table ) ? $table : '';
	}

	private function get_clubs_table(): string {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_clubs';
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return ( $exists === $table ) ? $table : '';
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

		$normalized = $this->normalize_status( $status );

		$submitted_variants = array(
			'submitted',
			'Soumise',
			'soumise',
			'Soumis',
			'soumis',
		);

		$pending_variants = array(
			'pending',
			'En attente',
			'en attente',
			'En attente validation',
			'en attente validation',
			'en_attente_validation',
			'en-attente-validation',
			'en_attente',
			'en-attente',
		);

		$approved_variants = array(
			'approved',
			'Approuvée',
			'approuvee',
			'approuvée',
			'validated',
			'Validée',
			'validee',
			'validée',
		);

		$rejected_variants = array(
			'rejected',
			'Rejetée',
			'rejetee',
			'rejetée',
			'Refusée',
			'refusee',
			'refusée',
		);

		$cancelled_variants = array(
			'cancelled',
			'withdrawn',
			'Annulée',
			'annulee',
			'annulée',
		);

		$draft_variants = array(
			'draft',
			'Brouillon',
			'brouillon',
		);

		switch ( $normalized ) {
			case 'submitted':
				return array_values( array_unique( array_merge( $submitted_variants, $pending_variants ) ) );
			case 'pending':
				return $pending_variants;
			case 'approved':
				return $approved_variants;
			case 'rejected':
				return $rejected_variants;
			case 'cancelled':
				return $cancelled_variants;
			case 'draft':
			default:
				return $draft_variants;
		}
	}

	private function normalize_status_for_count( string $status ): string {
		$slug = $this->normalize_status_slug( $status );
		if ( in_array( $slug, array( 'pending', 'en_attente', 'en_attente_validation' ), true ) ) {
			return 'pending';
		}

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
