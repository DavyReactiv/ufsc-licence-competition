<?php

namespace UFSC\Competitions\Front\Licenses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LicenseBridge {

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
		$license_columns   = $this->resolve_available_columns( $table, array( 'numero_licence_asptt', 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number', 'asptt_number' ) );
		$last_name_columns = $this->resolve_available_columns( $table, array( 'nom', 'nom_licence', 'last_name' ) );
		$first_name_columns = $this->resolve_available_columns( $table, array( 'prenom', 'prenom_licence', 'first_name' ) );
		$birthdate_column  = $this->resolve_first_column( $table, array( 'date_naissance', 'naissance', 'birthdate', 'date_of_birth' ) );
		$sex_column        = $this->resolve_first_column( $table, array( 'sexe', 'sex', 'gender' ) );

		$term           = sanitize_text_field( $term );
		$license_number = sanitize_text_field( $license_number );
		$birthdate      = sanitize_text_field( $birthdate );
		$normalized_term = $this->normalize_search( $term );
		$normalized_number = $this->normalize_search( $license_number );
		$normalized_birthdate = $this->normalize_birthdate( $birthdate );

		if ( '' === $term && '' === $license_number && '' === $normalized_birthdate ) {
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

		$last_name_expr  = $this->build_coalesce_expression( $last_name_columns );
		$first_name_expr = $this->build_coalesce_expression( $first_name_columns );

		// Free text term: search in last/first name (if present) + license column (if present)
		if ( '' !== $term ) {
			$like   = '%' . $wpdb->esc_like( $term ) . '%';
			$normalized_like = '' !== $normalized_term ? '%' . $wpdb->esc_like( $normalized_term ) . '%' : '';
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
				}
			}
			if ( "''" !== $first_name_expr ) {
				$clause[] = "{$first_name_expr} LIKE %s";
				$params[] = $like;
				if ( $normalized_like ) {
					$clause[] = "LOWER({$first_name_expr}) LIKE %s";
					$params[] = $normalized_like;
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
				return array();
			}

			$where[] = '(' . implode( ' OR ', $clause ) . ')';
		}

		// Dedicated license number term (only if column exists)
		if ( '' !== $license_number && ! empty( $license_columns ) ) {
			$number_like = '%' . $wpdb->esc_like( $license_number ) . '%';
			$number_clause = array();
			foreach ( $license_columns as $license_column ) {
				$number_clause[] = "{$license_column} LIKE %s";
				$params[] = $number_like;
				if ( '' !== $normalized_number ) {
					$number_clause[] = "LOWER({$license_column}) LIKE %s";
					$params[] = '%' . $wpdb->esc_like( $normalized_number ) . '%';
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

		$sql  = "SELECT {$select} FROM {$table} {$where_sql} ORDER BY {$order_last} ASC, {$order_first} ASC, id ASC LIMIT 20";
		$this->debug_log(
			'license_search_sql',
			array(
				'sql' => $sql,
				'params_count' => count( $params ),
			)
		);

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$items = array();

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

		$this->debug_log(
			'license_search_results',
			array(
				'count' => count( $items ),
			)
		);

		return $items;
	}

	public function get_by_id( int $id, int $club_id ): ?array {
		if ( ! $club_id || ! $id ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$license_column    = $this->resolve_first_column( $table, array( 'numero_licence_asptt', 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number' ) );
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

		return array(
			'id'             => absint( $row['id'] ?? 0 ),
			'label'          => implode( ' · ', $label_bits ),
			'first_name'     => $first_name,
			'last_name'      => $last_name,
			'birthdate'      => $birthdate,
			'sex'            => sanitize_text_field( $row['sex'] ?? '' ),
			'license_number' => sanitize_text_field( $row['license_number'] ?? '' ),
		);
	}

	private static function is_available(): bool {
		// Soft availability check: avoids hard dependency.
		return class_exists( 'UFSC_LC_Plugin' )
			|| class_exists( 'UFSC_LC_Competition_Licences_List_Table' )
			|| class_exists( 'UFSC_LC_Club_Licences_Shortcode' );
	}

	private function table_exists( string $table ): bool {
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
		$value = preg_replace( '/\s+/', ' ', $value );
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

		if ( preg_match( '/^\\d{4}-\\d{2}-\\d{2}$/', $value ) ) {
			return $value;
		}

		$date = date_create( $value );
		if ( $date ) {
			return $date->format( 'Y-m-d' );
		}

		return '';
	}

	private function debug_log( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$payload = $context ? wp_json_encode( $context ) : '';
		error_log( 'UFSC Competitions LicenseBridge: ' . $message . ( $payload ? ' ' . $payload : '' ) );
	}
}
