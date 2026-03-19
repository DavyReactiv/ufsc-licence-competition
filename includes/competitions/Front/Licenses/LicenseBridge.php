<?php

namespace UFSC\Competitions\Front\Licenses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LicenseBridge {
	private const DEFAULT_SEARCH_LIMIT = 20;

	public static function register(): void {
		// Register only if the licenses plugin/table is likely available.
		// We still guard at query-time with table/columns checks.
		if ( ! self::is_available() ) {
			return;
		}

		add_filter( 'ufsc_competitions_front_license_search_results', array( __CLASS__, 'filter_search_results' ), 10, 5 );
		add_filter( 'ufsc_competitions_front_license_by_id', array( __CLASS__, 'filter_license_by_id' ), 10, 3 );
	}

	/**
	 * @param array  $results Existing results (ignored / replaced).
	 * @param string $term Search term (name / free text).
	 * @param int    $club_id Club id.
	 * @param string $license_number Optional license number term.
	 * @param string $birthdate Optional birthdate (YYYY-MM-DD or dd/mm/YYYY).
	 */
	public static function filter_search_results( array $results, string $term, int $club_id, string $license_number = '', string $birthdate = '' ): array {
		$bridge = new self();
		return $bridge->search( $term, $club_id, $license_number, $birthdate );
	}

	/**
	 * @param mixed $result Existing result (ignored / replaced).
	 * @param int   $id License row id.
	 * @param int   $club_id Club id.
	 */
	public static function filter_license_by_id( $result, int $id, int $club_id ) {
		$bridge = new self();
		return $bridge->get_by_id( $id, $club_id );
	}

	public function search( string $term, int $club_id, string $license_number = '', string $birthdate = '' ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		// Resolve schema differences across installs.
		$license_columns   = $this->resolve_available_columns( $table, array( 'numero_licence_asptt', 'numero_asptt', 'asptt_number', 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number' ) );
		$last_name_columns = $this->resolve_available_columns( $table, array( 'nom', 'nom_licence', 'last_name' ) );
		$first_name_columns = $this->resolve_available_columns( $table, array( 'prenom', 'prenom_licence', 'first_name' ) );
		$birthdate_column  = $this->resolve_first_column( $table, array( 'date_naissance', 'naissance', 'birthdate', 'date_of_birth' ) );
		$sex_column        = $this->resolve_first_column( $table, array( 'sexe', 'sex', 'gender' ) );
		$status_column     = $this->resolve_first_column( $table, array( 'statut', 'status' ) );
		$season_column     = $this->resolve_first_column( $table, array( 'season_end_year', 'saison', 'season' ) );

		$term           = sanitize_text_field( $term );
		$license_number = sanitize_text_field( $license_number );
		$birthdate      = sanitize_text_field( $birthdate );
		$normalized_term = $this->normalize_search( $term );
		$compact_term = $this->normalize_search_compact( $term );
		$normalized_number = $this->normalize_search( $license_number );
		$normalized_birthdate = $this->normalize_birthdate( $birthdate );
		$term_parts = array();

		if ( '' === $term && '' === $license_number && '' === $normalized_birthdate ) {
			$this->debug_log(
				'license_search_empty_filters',
				array(
					'club_scoped' => (bool) $club_id,
					'has_term' => false,
					'has_number' => false,
					'has_birthdate' => false,
				)
			);
			return array();
		}

		$this->debug_log(
			'license_search_params',
			array(
				'term' => $term,
				'license_number' => $license_number,
				'birthdate' => $birthdate,
				'club_id' => $club_id,
			)
		);

		$where  = array();
		$params = array();

		if ( $this->has_column( $table, 'club_id' ) && $club_id ) {
			$where[] = 'club_id = %d';
			$params[] = $club_id;
		}

		if ( '' !== $status_column ) {
			$invalid_statuses = array(
				'inactive',
				'inactif',
				'invalide',
				'invalid',
				'expired',
				'expire',
				'suspended',
				'suspendu',
				'cancelled',
				'annule',
				'deleted',
				'supprime',
				'rejected',
				'refused',
				'blocked',
				'bloque',
			);
			$invalid_placeholders = implode( ', ', array_fill( 0, count( $invalid_statuses ), '%s' ) );
			$where[] = "( {$status_column} IS NULL OR {$status_column} = '' OR LOWER(TRIM({$status_column})) NOT IN ({$invalid_placeholders}) )";
			$params = array_merge( $params, $invalid_statuses );
		}

		$current_season = function_exists( 'ufsc_lc_get_current_season_end_year' ) ? (int) ufsc_lc_get_current_season_end_year() : 0;
		$enforce_current_season = (bool) apply_filters(
			'ufsc_competitions_front_license_enforce_current_season',
			false,
			$club_id,
			$current_season
		);
		if ( $enforce_current_season && $current_season > 0 && '' !== $season_column ) {
			$where[] = "{$season_column} = %s";
			$params[] = (string) $current_season;
		}

		$last_name_expr  = $this->build_coalesce_expression( $last_name_columns );
		$first_name_expr = $this->build_coalesce_expression( $first_name_columns );
		$normalized_last_expr = "''" !== $last_name_expr ? $this->build_normalized_expression( $last_name_expr ) : "''";
		$normalized_first_expr = "''" !== $first_name_expr ? $this->build_normalized_expression( $first_name_expr ) : "''";
		$compact_last_expr = "''" !== $last_name_expr ? $this->build_compact_expression( $last_name_expr ) : "''";
		$compact_first_expr = "''" !== $first_name_expr ? $this->build_compact_expression( $first_name_expr ) : "''";

		// Free text term: search in last/first name (if present) + license column (if present)
		if ( '' !== $term ) {
			$like   = '%' . $wpdb->esc_like( $term ) . '%';
			$normalized_like = '' !== $normalized_term ? '%' . $wpdb->esc_like( $normalized_term ) . '%' : '';
			$compact_like = '' !== $compact_term ? '%' . $wpdb->esc_like( $compact_term ) . '%' : '';
			$clause = array();

			$term_parts = array_values( array_filter( array_map( 'trim', explode( ' ', $normalized_term ) ) ) );

			if ( "''" !== $last_name_expr && "''" !== $first_name_expr && count( $term_parts ) >= 2 ) {
				$part_a = '%' . $wpdb->esc_like( $term_parts[0] ) . '%';
				$part_b = '%' . $wpdb->esc_like( $term_parts[1] ) . '%';
				$clause[] = "(LOWER({$last_name_expr}) LIKE %s AND LOWER({$first_name_expr}) LIKE %s)";
				$params[] = $part_a;
				$params[] = $part_b;
				$clause[] = "(LOWER({$last_name_expr}) LIKE %s AND LOWER({$first_name_expr}) LIKE %s)";
				$params[] = $part_b;
				$params[] = $part_a;
			}

			if ( "''" !== $last_name_expr ) {
				$clause[] = "{$last_name_expr} LIKE %s";
				$params[] = $like;
				if ( $normalized_like ) {
					$clause[] = "LOWER({$last_name_expr}) LIKE %s";
					$params[] = $normalized_like;
					if ( "''" !== $normalized_last_expr ) {
						$clause[] = "{$normalized_last_expr} LIKE %s";
						$params[] = $normalized_like;
					}
				}
				if ( $compact_like && "''" !== $compact_last_expr ) {
					$clause[] = "{$compact_last_expr} LIKE %s";
					$params[] = $compact_like;
				}
			}
			if ( "''" !== $first_name_expr ) {
				$clause[] = "{$first_name_expr} LIKE %s";
				$params[] = $like;
				if ( $normalized_like ) {
					$clause[] = "LOWER({$first_name_expr}) LIKE %s";
					$params[] = $normalized_like;
					if ( "''" !== $normalized_first_expr ) {
						$clause[] = "{$normalized_first_expr} LIKE %s";
						$params[] = $normalized_like;
					}
				}
				if ( $compact_like && "''" !== $compact_first_expr ) {
					$clause[] = "{$compact_first_expr} LIKE %s";
					$params[] = $compact_like;
				}
			}
			if ( ! empty( $license_columns ) ) {
				foreach ( $license_columns as $license_column ) {
					$clause[] = "{$license_column} LIKE %s";
					$params[] = $like;
					if ( $normalized_like ) {
						$clause[] = "LOWER({$license_column}) LIKE %s";
						$params[] = $normalized_like;
					}
				}
			}

			// If we cannot search anything, exit safely.
			if ( empty( $clause ) ) {
				$this->debug_log(
					'license_search_no_searchable_columns',
					array(
						'club_scoped' => (bool) $club_id,
						'has_term' => true,
						'has_number' => '' !== $license_number,
						'has_birthdate' => '' !== $normalized_birthdate,
						'has_last_name_columns' => ! empty( $last_name_columns ),
						'has_first_name_columns' => ! empty( $first_name_columns ),
						'license_columns_count' => count( $license_columns ),
					)
				);
				return array();
			}

			$where[] = '(' . implode( ' OR ', $clause ) . ')';
		}

		// Dedicated license number term (only if column exists)
		if ( '' !== $license_number && ! empty( $license_columns ) ) {
			$compact_number = preg_replace( '/[^a-z0-9]/i', '', $license_number );
			$number_clause = array();
			foreach ( $license_columns as $license_column ) {
				$number_clause[] = "TRIM(COALESCE({$license_column}, '')) = %s";
				$params[] = $license_number;
				if ( '' !== $normalized_number ) {
					$number_clause[] = "LOWER(TRIM(COALESCE({$license_column}, ''))) = %s";
					$params[] = $normalized_number;
				}
				if ( '' !== $compact_number ) {
					$number_clause[] = "REPLACE(REPLACE(LOWER(TRIM(COALESCE({$license_column}, ''))), ' ', ''), '-', '') = %s";
					$params[] = strtolower( $compact_number );
				}
			}
			$where[] = '(' . implode( ' OR ', $number_clause ) . ')';
		}

		if ( '' !== $normalized_birthdate && $birthdate_column ) {
			$birthdate_clause = array( "{$birthdate_column} = %s" );
			$params[] = $normalized_birthdate;

			$birthdate_alt = $this->format_birthdate_for_storage( $normalized_birthdate );
			if ( '' !== $birthdate_alt && $birthdate_alt !== $normalized_birthdate ) {
				$birthdate_clause[] = "{$birthdate_column} = %s";
				$params[] = $birthdate_alt;
			}

			$where[] = '(' . implode( ' OR ', $birthdate_clause ) . ')';
		}

		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';

		$context = array(
			'table' => $table,
			'club_id' => $club_id,
			'term' => $term,
			'license_number' => $license_number,
			'birthdate' => $birthdate,
		);
		$join      = apply_filters( 'ufsc_competitions_license_search_join', '', $context );
		$raw_where_sql = $where_sql;
		$raw_params    = $params;
		$where_sql = apply_filters( 'ufsc_competitions_license_search_where', $where_sql, $context );
		$params    = apply_filters( 'ufsc_competitions_license_search_params', $params, $context );

		$join     = trim( (string) $join );
		$join_sql = '' !== $join ? ' ' . $join : '';


		// Select columns as normalized aliases expected by the competitions module.
		$select_columns   = array( 'id' );
		$select_columns[] = "''" !== $last_name_expr ? "{$last_name_expr} AS last_name" : "'' AS last_name";
		$select_columns[] = "''" !== $first_name_expr ? "{$first_name_expr} AS first_name" : "'' AS first_name";
		$select_columns[] = $birthdate_column ? "{$birthdate_column} AS birthdate" : "'' AS birthdate";
		$select_columns[] = $sex_column ? "{$sex_column} AS sex" : "'' AS sex";
		$select_columns[] = ! empty( $license_columns ) ? "{$license_columns[0]} AS license_number" : "'' AS license_number";

		$select = implode( ', ', $select_columns );

		// Dynamic ordering depending on available columns (stable & safe: names are whitelisted + verified existing).
		$order_last  = ! empty( $last_name_columns ) ? $last_name_columns[0] : 'id';
		$order_first = ! empty( $first_name_columns ) ? $first_name_columns[0] : $order_last;

		$limit = (int) apply_filters( 'ufsc_competitions_license_search_limit', self::DEFAULT_SEARCH_LIMIT, $context );
		$limit = max( 1, min( 100, $limit ) );
		$order_clauses = array();
		if ( '' !== $license_number && ! empty( $license_columns ) ) {
			foreach ( $license_columns as $license_column ) {
				$order_clauses[] = $wpdb->prepare( "CASE WHEN TRIM(COALESCE({$license_column}, '')) = %s THEN 0 ELSE 1 END", $license_number );
			}
		}
		$order_clauses[] = "{$order_last} ASC";
		$order_clauses[] = "{$order_first} ASC";
		$order_clauses[] = 'id ASC';
		$order_sql = implode( ', ', $order_clauses );

		$sql   = "SELECT {$select} FROM {$table}{$join_sql} {$where_sql} ORDER BY {$order_sql} LIMIT " . ( $limit + 1 );
		$this->debug_log(
			'license_search_sql',
			array(
				'sql' => $sql,
				'params_count' => count( $params ),
				'params_preview' => array_slice( $params, 0, 25 ),
				'license_columns' => $license_columns,
				'last_name_columns' => $last_name_columns,
				'first_name_columns' => $first_name_columns,
				'birthdate_column' => $birthdate_column,
				'sex_column' => $sex_column,
				'status_column' => $status_column,
				'season_column' => $season_column,
				'season_filter' => $enforce_current_season && $current_season > 0 && '' !== $season_column ? (string) $current_season : '',
				'enforce_current_season' => $enforce_current_season,
				'join_sql' => $join_sql,
				'where_sql_before_filters' => $raw_where_sql,
				'where_sql_after_filters' => $where_sql,
				'where_changed_by_filter' => $raw_where_sql !== $where_sql,
				'params_changed_by_filter' => $raw_params !== $params,
			)
		);

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$items = array();

		$is_truncated = count( $rows ) > $limit;
		if ( $is_truncated ) {
			$rows = array_slice( $rows, 0, $limit );
		}

		foreach ( $rows as $row ) {
			$first_name = sanitize_text_field( $row['first_name'] ?? '' );
			$last_name  = sanitize_text_field( $row['last_name'] ?? '' );
			$birthdate  = sanitize_text_field( $row['birthdate'] ?? '' );

			$label_bits = array_filter(
				array(
					trim( $last_name . ' ' . $first_name ),
					$birthdate,
				)
			);

			$items[] = array(
				'id'             => absint( $row['id'] ?? 0 ),
				'label'          => implode( ' · ', $label_bits ),
				'first_name'     => $first_name,
				'last_name'      => $last_name,
				'birthdate'      => $birthdate,
				'sex'            => sanitize_text_field( $row['sex'] ?? '' ),
				'license_number' => sanitize_text_field( $row['license_number'] ?? '' ),
			);
		}

		if ( empty( $items ) ) {
			$zero_reason = '';
			if ( ! $club_id ) {
				$zero_reason = 'club_id_missing';
			} elseif ( '' !== $license_number && empty( $license_columns ) ) {
				$zero_reason = 'license_number_provided_but_no_license_column';
			} elseif ( '' !== $term && empty( $last_name_columns ) && empty( $first_name_columns ) && empty( $license_columns ) ) {
				$zero_reason = 'term_provided_but_no_searchable_columns';
			} elseif ( '' !== $normalized_birthdate && '' === $birthdate_column ) {
				$zero_reason = 'birthdate_provided_but_no_birthdate_column';
			} else {
				$zero_reason = 'query_returned_no_rows';
			}

			$this->debug_log(
				'license_search_zero_results',
				array(
					'reason' => $zero_reason,
					'club_scoped' => (bool) $club_id,
					'has_term' => '' !== $term,
					'has_number' => '' !== $license_number,
					'has_birthdate' => '' !== $normalized_birthdate,
					'term_tokens_count' => count( $term_parts ),
					'has_last_name_columns' => ! empty( $last_name_columns ),
					'has_first_name_columns' => ! empty( $first_name_columns ),
					'license_columns_count' => count( $license_columns ),
					'birthdate_filter_applied' => '' !== $normalized_birthdate,
				)
			);
		}

		$this->debug_log(
			'license_search_results',
			array(
				'count' => count( $items ),
				'ids' => array_map(
					static function( $item ) {
						return (int) ( $item['id'] ?? 0 );
					},
					$items
				),
				'limit' => $limit,
				'is_truncated' => $is_truncated,
				'has_term' => '' !== $term,
				'has_number' => '' !== $license_number,
				'has_birthdate' => '' !== $normalized_birthdate,
				'club_scoped' => (bool) $club_id,
				'term_tokens_count' => count( $term_parts ),
			)
		);

		return $items;
	}

	public function get_by_id( int $id, int $club_id ): ?array {
		if ( ! $club_id || ! $id ) {
			$this->debug_log(
				'license_get_by_id_skipped',
				array(
					'id' => $id,
					'club_id' => $club_id,
					'reason' => ! $club_id ? 'missing_club_id' : 'missing_license_id',
				)
			);
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$license_column    = $this->resolve_first_column( $table, array( 'numero_licence_asptt', 'numero_asptt', 'asptt_number', 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number' ) );
		$last_name_column  = $this->resolve_first_column( $table, array( 'nom_licence', 'nom', 'last_name' ) );
		$first_name_column = $this->resolve_first_column( $table, array( 'prenom', 'prenom_licence', 'first_name' ) );
		$birthdate_column  = $this->resolve_first_column( $table, array( 'date_naissance', 'naissance', 'birthdate' ) );
		$sex_column        = $this->resolve_first_column( $table, array( 'sexe', 'sex', 'gender' ) );

		$select_columns   = array( 'id' );
		$select_columns[] = $last_name_column ? "{$last_name_column} AS last_name" : "'' AS last_name";
		$select_columns[] = $first_name_column ? "{$first_name_column} AS first_name" : "'' AS first_name";
		$select_columns[] = $birthdate_column ? "{$birthdate_column} AS birthdate" : "'' AS birthdate";
		$select_columns[] = $sex_column ? "{$sex_column} AS sex" : "'' AS sex";
		$select_columns[] = $license_column ? "{$license_column} AS license_number" : "'' AS license_number";

		$select = implode( ', ', $select_columns );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$select} FROM {$table} WHERE id = %d AND club_id = %d",
				$id,
				$club_id
			),
			ARRAY_A
		);

		if ( ! $row ) {
			$this->debug_log(
				'license_get_by_id_not_found',
				array(
					'id' => $id,
					'club_id' => $club_id,
					'license_column' => $license_column,
					'last_name_column' => $last_name_column,
					'first_name_column' => $first_name_column,
					'birthdate_column' => $birthdate_column,
				)
			);
			return null;
		}

		$first_name = sanitize_text_field( $row['first_name'] ?? '' );
		$last_name  = sanitize_text_field( $row['last_name'] ?? '' );
		$birthdate  = sanitize_text_field( $row['birthdate'] ?? '' );

		$label_bits = array_filter(
			array(
				trim( $last_name . ' ' . $first_name ),
				$birthdate,
			)
		);

		$item = array(
			'id'             => absint( $row['id'] ?? 0 ),
			'label'          => implode( ' · ', $label_bits ),
			'first_name'     => $first_name,
			'last_name'      => $last_name,
			'birthdate'      => $birthdate,
			'sex'            => sanitize_text_field( $row['sex'] ?? '' ),
			'license_number' => sanitize_text_field( $row['license_number'] ?? '' ),
		);
		$this->debug_log(
			'license_get_by_id_found',
			array(
				'id' => (int) $item['id'],
				'club_id' => $club_id,
				'label' => (string) $item['label'],
			)
		);

		return $item;
	}

	private static function is_available(): bool {
		// Soft availability check: avoids hard dependency.
		return class_exists( 'UFSC_LC_Plugin' )
			|| class_exists( 'UFSC_LC_Competition_Licences_List_Table' )
			|| class_exists( 'UFSC_LC_Club_Licences_Shortcode' );
	}

	private function table_exists( string $table ): bool {
		if ( class_exists( '\UFSC_LC_Schema_Cache' ) ) {
			return \UFSC_LC_Schema_Cache::table_exists( $table );
		}

		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return $found === $table;
	}

	private function has_column( string $table, string $column ): bool {
		global $wpdb;

		$column = sanitize_key( $column );
		if ( '' === $column ) {
			return false;
		}

		if ( class_exists( '\UFSC_LC_Schema_Cache' ) ) {
			return \UFSC_LC_Schema_Cache::column_exists( $table, $column );
		}

		$result = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		return (bool) $result;
	}

	private function resolve_first_column( string $table, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( $this->has_column( $table, (string) $candidate ) ) {
				return (string) $candidate;
			}
		}
		return '';
	}

	private function resolve_available_columns( string $table, array $candidates ): array {
		$columns = array();
		foreach ( $candidates as $candidate ) {
			if ( $this->has_column( $table, (string) $candidate ) ) {
				$columns[] = (string) $candidate;
			}
		}
		return $columns;
	}

	private function build_coalesce_expression( array $columns ): string {
		if ( empty( $columns ) ) {
			return "''";
		}

		$parts = array();
		foreach ( $columns as $column ) {
			$parts[] = 'NULLIF(' . $column . ", '')";
		}

		return 'COALESCE(' . implode( ', ', $parts ) . ')';
	}

	private function format_birthdate_for_storage( string $normalized ): string {
		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $normalized ) ) {
			$date = date_create( $normalized );
			if ( $date ) {
				return $date->format( 'd/m/Y' );
			}
		}

		return '';
	}

	private function normalize_search( string $value ): string {
		$value = remove_accents( $value );
		$value = str_replace( array( '’', '\'', '-' ), ' ', $value );
		$value = preg_replace( '/[^\\p{L}\\p{N}\\s]+/u', ' ', $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value );

		if ( function_exists( 'mb_strtolower' ) ) {
			$value = mb_strtolower( $value );
		} else {
			$value = strtolower( $value );
		}

		return $value;
	}

	private function normalize_search_compact( string $value ): string {
		$value = remove_accents( $value );
		$value = preg_replace( '/[^\\p{L}\\p{N}]+/u', '', $value );
		$value = trim( $value );

		if ( function_exists( 'mb_strtolower' ) ) {
			$value = mb_strtolower( $value );
		} else {
			$value = strtolower( $value );
		}

		return $value;
	}

	private function normalize_birthdate( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^\\d{2}\\/\\d{2}\\/\\d{4}$/', $value ) ) {
			$parts = explode( '/', $value );
			if ( 3 === count( $parts ) ) {
				$value = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
			}
		}
		if ( preg_match( '/^\\d{2}-\\d{2}-\\d{4}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( 3 === count( $parts ) ) {
				$value = sprintf( '%04d-%02d-%02d', (int) $parts[2], (int) $parts[1], (int) $parts[0] );
			}
		}

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return $value;
		}

		$date = date_create( $value );
		if ( $date ) {
			return $date->format( 'Y-m-d' );
		}

		return '';
	}

	private function build_normalized_expression( string $expression ): string {
		$replaced = "REPLACE(REPLACE(REPLACE({$expression}, '-', ' '), \"'\", ' '), '’', ' ')";
		return "LOWER({$replaced})";
	}

	private function build_compact_expression( string $expression ): string {
		$replaced = "REPLACE(REPLACE(REPLACE({$expression}, '-', ''), \"'\", ''), '’', '')";
		return "LOWER({$replaced})";
	}

	private function debug_log( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( 'UFSC Competitions LicenseBridge: ' . $message . ( $payload ? ' ' . $payload : '' ) );
	}
}
