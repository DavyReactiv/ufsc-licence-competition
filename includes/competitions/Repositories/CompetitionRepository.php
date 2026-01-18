<?php
// ... en-tête inchangé ...

class CompetitionRepository {
	private $logger;

	/**
	 * Cache of table columns per table name to avoid repeated DESCRIBE queries.
	 * @var array<string, string[]>
	 */
	private static $table_columns_cache = array();

	/**
	 * Return array of column names for competitions table (cached).
	 *
	 * @return string[]
	 */
	private function get_table_columns() {
		global $wpdb;

		$table = Db::competitions_table();
		if ( isset( self::$table_columns_cache[ $table ] ) ) {
			return self::$table_columns_cache[ $table ];
		}

		$cols = array();
		$rows = $wpdb->get_results( "DESCRIBE {$table}" );
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r->Field ) ) {
					$cols[] = $r->Field;
				}
			}
		}

		self::$table_columns_cache[ $table ] = $cols;
		return $cols;
	}

	public function __construct() {
		$this->logger = new LogService();
	}

	// ... sanitize() etc. inchangés ...

	public function insert( array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );

		/*
		 * Order of keys must match get_insert_format():
		 * name, discipline, type, season, location, start_date, end_date,
		 * registration_deadline, status, age_reference, weight_tolerance,
		 * allowed_formats, created_by, updated_by, created_at, updated_at
		 */
		$prepared['created_by'] = get_current_user_id() ?: null;
		$prepared['updated_by'] = get_current_user_id() ?: null;
		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );

		// Align prepared fields with actual table columns and formats (safe fallback if table schema is out-of-date).
		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $prepared, $this->get_insert_format() );

		if ( empty( $filtered_prepared ) ) {
			$this->logger->log( 'error', 'competition', 0, 'Competition insert failed: no valid columns after filtering.', array( 'data' => $prepared ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC Competition insert aborted: no valid columns after filtering — data: ' . print_r( $prepared, true ) );
			}
			return 0;
		}

		$result = $wpdb->insert( Db::competitions_table(), $filtered_prepared, $filtered_formats );

		// ... reste inchangé ...
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );
		$prepared['updated_by'] = get_current_user_id() ?: null;

		list( $filtered_prepared, $filtered_formats ) = $this->filter_prepared_and_formats_for_db( $prepared, $this->get_update_format() );

		if ( empty( $filtered_prepared ) ) {
			$this->logger->log( 'error', 'competition', $id, 'Competition update aborted: no valid columns after filtering.', array( 'data' => $prepared ) );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC Competition update aborted (id ' . intval( $id ) . '): no valid columns after filtering — data: ' . print_r( $prepared, true ) );
			}
			return false;
		}

		$updated = $wpdb->update(
			Db::competitions_table(),
			$filtered_prepared,
			array( 'id' => absint( $id ) ),
			$filtered_formats,
			array( '%d' )
		);

		// ... reste inchangé ...
	}

	/**
	 * Given a $prepared associative array and a formats array (ordered), remove keys that are not present
	 * in the actual DB table and return the filtered prepared array and corresponding formats preserving order.
	 *
	 * @param array $prepared assoc name => value
	 * @param array $formats indexed formats aligned with keys order expected
	 * @return array [ filtered_prepared_assoc, filtered_formats_indexed ]
	 */
	private function filter_prepared_and_formats_for_db( array $prepared, array $formats ) {
		$columns = $this->get_table_columns();
		$filtered_prepared = array();
		$filtered_formats = array();

		// Ensure formats mapping aligns with the $prepared keys order.
		$keys = array_keys( $prepared );
		foreach ( $keys as $i => $key ) {
			if ( in_array( $key, $columns, true ) ) {
				$filtered_prepared[ $key ] = $prepared[ $key ];
				// If formats array is shorter than keys, fallback to %s
				$fmt = isset( $formats[ $i ] ) ? $formats[ $i ] : '%s';
				$filtered_formats[] = $fmt;
			}
		}

		return array( $filtered_prepared, $filtered_formats );
	}

	// ... reste du fichier inchangé ...
}
