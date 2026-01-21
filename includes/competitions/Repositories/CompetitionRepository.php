<?php
/**
 * CompetitionRepository (UFSC Competitions)
 * - Compatible nouveau schéma (event_start_datetime, etc.)
 * - Compatible ancien schéma (start_date, end_date, registration_deadline)
 * - WHERE préparé correctement (pas de placeholders “orphelins”)
 * - ORDER BY sûr (fallback si colonnes absentes)
 */

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionRepository {

	use RepositoryHelpers;

	/** @var LogService */
	private $logger;

	/**
	 * Cache colonnes par table (clé = nom table).
	 * @var array<string, string[]>
	 */
	private static $table_columns_cache = array();

	/**
	 * Colonnes autorisées (logiques) pour tri.
	 * Certaines sont mappées vers les colonnes réelles via col().
	 * @var string[]
	 */
	private $allowed_order_cols = array(
		'event_start_datetime',
		'event_end_datetime',
		'registration_open_datetime',
		'registration_close_datetime',
		'weighin_start_datetime',
		'weighin_end_datetime',
		'name',
		'discipline',
		'type',
		'season',
		'status',
		'updated_at',
		'created_at',
	);

	public function __construct() {
		$this->logger = new LogService();
	}

	/**
	 * Get single competition by id.
	 *
	 * @param int  $id
	 * @param bool $include_deleted
	 * @return object|null
	 */
	public function get( $id, $include_deleted = false ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$table = Db::competitions_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );

		$this->maybe_log_db_error( __METHOD__ . ':get' );

		// Si la colonne deleted_at n’existe pas, on ne filtre pas.
		if ( $row && ! $include_deleted && $this->has_col( 'deleted_at' ) && ! empty( $row->deleted_at ) ) {
			return null;
		}

		return $row ?: null;
	}

	/**
	 * List competitions with filters.
	 *
	 * @param array $filters
	 * @param int   $limit
	 * @param int   $offset
	 * @return array
	 */
	public function list( array $filters = array(), $limit = 20, $offset = 0 ) {
		global $wpdb;

		$table  = Db::competitions_table();
		$where  = $this->build_where( $filters ); // string "WHERE ..." or ""
		$limit  = max( 1, (int) $limit );
		$offset = max( 0, (int) $offset );

		$order_by = $this->build_order_by( $filters );

		// IMPORTANT: $where est déjà préparé (pas de placeholders dedans).
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table} {$where} ORDER BY {$order_by} LIMIT %d OFFSET %d",
			$limit,
			$offset
		);

		$rows = $wpdb->get_results( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':list' );

		// Debug (optionnel)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$last_query = isset( $wpdb->last_query ) ? $wpdb->last_query : $sql;
			$last_error = isset( $wpdb->last_error ) ? $wpdb->last_error : '';
			$debug_msg  = sprintf(
				'CompetitionRepository::list executed. filters=%s ; query=%s ; last_error=%s ; rows=%d',
				wp_json_encode( $filters ),
				$last_query,
				$last_error,
				is_array( $rows ) ? count( $rows ) : 0
			);
			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( $debug_msg );
			} else {
				error_log( $debug_msg );
			}
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count competitions with filters.
	 *
	 * @param array $filters
	 * @return int
	 */
	public function count( array $filters = array() ) {
		global $wpdb;

		$table = Db::competitions_table();
		$where = $this->build_where( $filters ); // string "WHERE ..." or ""

		$sql = "SELECT COUNT(1) FROM {$table} {$where}";
		$val = $wpdb->get_var( $sql );

		$this->maybe_log_db_error( __METHOD__ . ':count' );

		// Debug (optionnel)
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$last_query = isset( $wpdb->last_query ) ? $wpdb->last_query : $sql;
			$last_error = isset( $wpdb->last_error ) ? $wpdb->last_error : '';
			$debug_msg  = sprintf(
				'CompetitionRepository::count executed. filters=%s ; query=%s ; last_error=%s ; count=%d',
				wp_json_encode( $filters ),
				$last_query,
				$last_error,
				(int) $val
			);
			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( $debug_msg );
			} else {
				error_log( $debug_msg );
			}
		}

		return (int) $val;
	}

	/* =========================================================
	 * WHERE / ORDER BY (compat schéma legacy + nouveau)
	 * ======================================================= */

	/**
	 * Build WHERE clause (prepared and safe).
	 *
	 * Supported filters:
	 * - view: all|trash
	 * - status
	 * - discipline
	 * - season
	 * - s (search)
	 *
	 * @param array $filters
	 * @return string "WHERE ..." or ""
	 */
	private function build_where( array $filters ): string {
		global $wpdb;

		// Normalisation
		$view       = isset( $filters['view'] ) ? (string) $filters['view'] : 'all';
		$status     = isset( $filters['status'] ) ? (string) $filters['status'] : '';
		$discipline = isset( $filters['discipline'] ) ? (string) $filters['discipline'] : '';
		$season     = isset( $filters['season'] ) ? (string) $filters['season'] : '';
		$search     = isset( $filters['s'] ) ? (string) $filters['s'] : '';

		if ( ! in_array( $view, array( 'all', 'trash' ), true ) ) {
			$view = 'all';
		}

		/**
		 * DEFAULT STATUS:
		 * Si aucun filtre statut et vue "all", on montre les compétitions actives.
		 * (Compatible avec tes données: status=open)
		 */
		if ( '' === $status && 'all' === $view && $this->has_col( 'status' ) ) {
			$status = 'open';
		}

		$clauses = array();
		$params  = array();

		// Deleted / trash logic (uniquement si colonne existe)
		if ( $this->has_col( 'deleted_at' ) ) {
			if ( 'trash' === $view ) {
				$clauses[] = 'deleted_at IS NOT NULL';
			} else {
				$clauses[] = 'deleted_at IS NULL';
			}
		}

		// Status
		if ( '' !== $status && $this->has_col( 'status' ) ) {
			$clauses[] = 'status = %s';
			$params[]  = $status;
		}

		// Discipline
		if ( '' !== $discipline && $this->has_col( 'discipline' ) ) {
			$clauses[] = 'discipline = %s';
			$params[]  = $discipline;
		}

		// Season
		if ( '' !== $season && $this->has_col( 'season' ) ) {
			$clauses[] = 'season = %s';
			$params[]  = $season;
		}

		// Search (name + éventuellement location si existe)
		$search = trim( $search );
		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$search_sub = array();
			if ( $this->has_col( 'name' ) ) {
				$search_sub[] = 'name LIKE %s';
				$params[]     = $like;
			}
			if ( $this->has_col( 'location' ) ) {
				$search_sub[] = 'location LIKE %s';
				$params[]     = $like;
			}
			if ( $this->has_col( 'organizer_club_name' ) ) {
				$search_sub[] = 'organizer_club_name LIKE %s';
				$params[]     = $like;
			}

			if ( ! empty( $search_sub ) ) {
				$clauses[] = '(' . implode( ' OR ', $search_sub ) . ')';
			}
		}

		if ( empty( $clauses ) ) {
			return '';
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $clauses );

		// Prepare only if we have params/placeholders
		if ( ! empty( $params ) ) {
			$where_sql = $wpdb->prepare( $where_sql, $params );
		}

		return $where_sql;
	}

	/**
	 * Build ORDER BY clause safely.
	 *
	 * Accepts:
	 * - filters['order_by'] logical or real column
	 * - filters['order_dir'] ASC|DESC
	 *
	 * @param array $filters
	 * @return string (no placeholders)
	 */
	private function build_order_by( array $filters ): string {
		$dir = isset( $filters['order_dir'] ) && strtoupper( (string) $filters['order_dir'] ) === 'ASC' ? 'ASC' : 'DESC';

		// Default order
		$default_col = $this->col( 'updated_at' );
		if ( ! $this->has_col( $default_col ) ) {
			$default_col = $this->has_col( 'created_at' ) ? 'created_at' : 'id';
		}
		$default = $default_col . ' DESC';

		$requested = isset( $filters['order_by'] ) ? (string) $filters['order_by'] : '';
		if ( '' === $requested ) {
			return $default;
		}

		// If requested is a logical column, map it
		if ( in_array( $requested, $this->allowed_order_cols, true ) ) {
			$mapped = $this->col( $requested );
			if ( $mapped && $this->has_col( $mapped ) ) {
				return $mapped . ' ' . $dir;
			}
		}

		// If requested is a raw column, verify it exists
		if ( $this->has_col( $requested ) ) {
			return $requested . ' ' . $dir;
		}

		return $default;
	}

	/* =========================================================
	 * Schema compatibility helpers
	 * ======================================================= */

	/**
	 * Return table columns (cached).
	 *
	 * @return string[]
	 */
	private function get_table_columns(): array {
		global $wpdb;

		$table = Db::competitions_table();

		if ( isset( self::$table_columns_cache[ $table ] ) && is_array( self::$table_columns_cache[ $table ] ) ) {
			return self::$table_columns_cache[ $table ];
		}

		$cols    = array();
		$results = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" );

		if ( is_array( $results ) ) {
			foreach ( $results as $r ) {
				if ( isset( $r->Field ) ) {
					$cols[] = (string) $r->Field;
				}
			}
		}

		self::$table_columns_cache[ $table ] = $cols;

		return $cols;
	}

	private function has_col( string $col ): bool {
		$cols = $this->get_table_columns();
		return in_array( $col, $cols, true );
	}

	/**
	 * Map logical columns to real columns depending on schema.
	 *
	 * @param string $logical
	 * @return string
	 */
	private function col( string $logical ): string {
		// New schema first, then legacy fallback
		$map = array(
			'event_start_datetime'        => $this->has_col( 'event_start_datetime' ) ? 'event_start_datetime' : ( $this->has_col( 'start_date' ) ? 'start_date' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ) ),
			'event_end_datetime'          => $this->has_col( 'event_end_datetime' ) ? 'event_end_datetime' : ( $this->has_col( 'end_date' ) ? 'end_date' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ) ),

			'registration_open_datetime'  => $this->has_col( 'registration_open_datetime' ) ? 'registration_open_datetime' : ( $this->has_col( 'registration_deadline' ) ? 'registration_deadline' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ) ),
			'registration_close_datetime' => $this->has_col( 'registration_close_datetime' ) ? 'registration_close_datetime' : ( $this->has_col( 'registration_deadline' ) ? 'registration_deadline' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ) ),

			'weighin_start_datetime'      => $this->has_col( 'weighin_start_datetime' ) ? 'weighin_start_datetime' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ),
			'weighin_end_datetime'        => $this->has_col( 'weighin_end_datetime' ) ? 'weighin_end_datetime' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ),

			'updated_at'                  => $this->has_col( 'updated_at' ) ? 'updated_at' : ( $this->has_col( 'created_at' ) ? 'created_at' : 'id' ),
			'created_at'                  => $this->has_col( 'created_at' ) ? 'created_at' : 'id',
		);

		return $map[ $logical ] ?? $logical;
	}

	/* =========================================================
	 * Logging helper
	 * ======================================================= */

	private function maybe_log_db_error( string $context ): void {
		global $wpdb;

		if ( ! isset( $wpdb->last_error ) || '' === (string) $wpdb->last_error ) {
			return;
		}

		$msg = sprintf(
			'UFSC Competitions DB error (%s): %s | query=%s',
			$context,
			(string) $wpdb->last_error,
			isset( $wpdb->last_query ) ? (string) $wpdb->last_query : ''
		);

		// Si ton LogService a des méthodes dédiées, tu peux adapter ici.
		if ( class_exists( '\\UFSC_LC_Logger' ) ) {
			\UFSC_LC_Logger::log( $msg );
		} else {
			error_log( $msg );
		}
	}

	/* =========================================================
	 * CRUD avancé (stubs défensifs)
	 * Si tu as déjà save/trash/restore/delete ailleurs, garde les tiens.
	 * ======================================================= */

	/**
	 * Save a competition (create/update).
	 * NOTE: Cette implémentation est volontairement simple et défensive.
	 * Si tu as déjà une version complète, remplace ce bloc par la tienne.
	 *
	 * @param array $data
	 * @return int Saved ID or 0
	 */
	public function save( array $data ): int {
		global $wpdb;

		$table = Db::competitions_table();

		$id         = isset( $data['id'] ) ? absint( $data['id'] ) : 0;
		$name       = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';
		$discipline = isset( $data['discipline'] ) ? sanitize_text_field( (string) $data['discipline'] ) : '';
		$type       = isset( $data['type'] ) ? sanitize_text_field( (string) $data['type'] ) : '';
		$season     = isset( $data['season'] ) ? sanitize_text_field( (string) $data['season'] ) : '';
		$status     = isset( $data['status'] ) ? sanitize_text_field( (string) $data['status'] ) : 'open';

		if ( '' === $name ) {
			return 0;
		}

		$now = current_time( 'mysql' );

		$payload = array();
		$formats = array();

		if ( $this->has_col( 'name' ) ) {
			$payload['name'] = $name; $formats[] = '%s';
		}
		if ( $this->has_col( 'discipline' ) ) {
			$payload['discipline'] = $discipline; $formats[] = '%s';
		}
		if ( $this->has_col( 'type' ) ) {
			$payload['type'] = $type; $formats[] = '%s';
		}
		if ( $this->has_col( 'season' ) ) {
			$payload['season'] = $season; $formats[] = '%s';
		}
		if ( $this->has_col( 'status' ) ) {
			$payload['status'] = $status; $formats[] = '%s';
		}

		// timestamps
		if ( $this->has_col( 'updated_at' ) ) {
			$payload['updated_at'] = $now; $formats[] = '%s';
		}
		if ( ! $id && $this->has_col( 'created_at' ) ) {
			$payload['created_at'] = $now; $formats[] = '%s';
		}

		if ( $id ) {
			$ok = $wpdb->update( $table, $payload, array( 'id' => $id ), $formats, array( '%d' ) );
			$this->maybe_log_db_error( __METHOD__ . ':update' );
			return ( false === $ok ) ? 0 : $id;
		}

		$ok = $wpdb->insert( $table, $payload, $formats );
		$this->maybe_log_db_error( __METHOD__ . ':insert' );

		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public function trash( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		// Si pas de deleted_at, on ne peut pas "trash".
		if ( ! $this->has_col( 'deleted_at' ) ) {
			return false;
		}

		$payload = array(
			'deleted_at' => current_time( 'mysql' ),
		);
		$formats = array( '%s' );

		if ( $this->has_col( 'updated_at' ) ) {
			$payload['updated_at'] = current_time( 'mysql' );
			$formats[]             = '%s';
		}

		$ok = $wpdb->update( $table, $payload, array( 'id' => $id ), $formats, array( '%d' ) );
		$this->maybe_log_db_error( __METHOD__ . ':trash' );

		return false !== $ok;
	}

	public function restore( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();

		if ( ! $this->has_col( 'deleted_at' ) ) {
			return false;
		}

		$payload = array(
			'deleted_at' => null,
		);
		$formats = array( '%s' );

		// WPDB update avec NULL: il faut passer null sans format strict => on passe '%s' et laisser WP gérer,
		// sinon tu peux faire une requête custom. Ici on fait safe via query.
		$sql = $wpdb->prepare( "UPDATE {$table} SET deleted_at = NULL WHERE id = %d", $id );
		$ok  = $wpdb->query( $sql );
		$this->maybe_log_db_error( __METHOD__ . ':restore' );

		return false !== $ok;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return false;
		}

		$table = Db::competitions_table();
		$ok    = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		$this->maybe_log_db_error( __METHOD__ . ':delete' );

		return false !== $ok;
	}
}
