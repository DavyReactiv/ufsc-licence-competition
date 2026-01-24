<?php

namespace UFSC\Competitions\Front\Licenses;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LicenseBridge {
	public static function register(): void {
		if ( ! self::is_available() ) {
			return;
		}

		add_filter( 'ufsc_competitions_front_license_search_results', array( __CLASS__, 'filter_search_results' ), 10, 4 );
		add_filter( 'ufsc_competitions_front_license_by_id', array( __CLASS__, 'filter_license_by_id' ), 10, 3 );
	}

	public static function filter_search_results( array $results, string $term, int $club_id, string $license_number = '' ): array {
		$bridge = new self();
		return $bridge->search( $term, $club_id, $license_number );
	}

	public static function filter_license_by_id( $result, int $id, int $club_id ) {
		$bridge = new self();
		return $bridge->get_by_id( $id, $club_id );
	}

	public function search( string $term, int $club_id, string $license_number = '' ): array {
		if ( ! self::is_available() || ! $club_id ) {
			return array();
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		if ( ! $this->table_exists( $table ) ) {
			return array();
		}

		$license_column = $this->resolve_first_column(
			$table,
			array( 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number' )
		);
		$last_name_column = $this->resolve_first_column( $table, array( 'nom_licence', 'nom' ) );
		$first_name_column = $this->resolve_first_column( $table, array( 'prenom', 'prenom_licence' ) );
		$birthdate_column = $this->resolve_first_column( $table, array( 'date_naissance', 'naissance', 'birthdate' ) );
		$sex_column = $this->resolve_first_column( $table, array( 'sexe', 'sex', 'gender' ) );

		$where  = array( 'club_id = %d' );
		$params = array( $club_id );

		$term = sanitize_text_field( $term );
		$license_number = sanitize_text_field( $license_number );

		if ( '' === $term && '' === $license_number ) {
			return array();
		}

		if ( '' !== $term ) {
			$like   = '%' . $wpdb->esc_like( $term ) . '%';
			$clause = array();

			if ( $last_name_column ) {
				$clause[] = "{$last_name_column} LIKE %s";
				$params[] = $like;
			}
			if ( $first_name_column ) {
				$clause[] = "{$first_name_column} LIKE %s";
				$params[] = $like;
			}

			if ( empty( $clause ) ) {
				return array();
			}

			if ( $license_column ) {
				$clause[] = "{$license_column} LIKE %s";
				$params[] = $like;
			}

			$where[] = '(' . implode( ' OR ', $clause ) . ')';
		}

		if ( '' !== $license_number && $license_column ) {
			$number_like = '%' . $wpdb->esc_like( $license_number ) . '%';
			$where[]     = "{$license_column} LIKE %s";
			$params[]    = $number_like;
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );
		$select_columns = array( 'id' );
		$select_columns[] = $last_name_column ? "{$last_name_column} AS last_name" : "'' AS last_name";
		$select_columns[] = $first_name_column ? "{$first_name_column} AS first_name" : "'' AS first_name";
		$select_columns[] = $birthdate_column ? "{$birthdate_column} AS birthdate" : "'' AS birthdate";
		$select_columns[] = $sex_column ? "{$sex_column} AS sex" : "'' AS sex";
		if ( $license_column ) {
			$select_columns[] = "{$license_column} AS license_number";
		} else {
			$select_columns[] = "'' AS license_number";
		}
		$select = implode( ', ', $select_columns );

		$order_last  = $last_name_column ? $last_name_column : 'id';
		$order_first = $first_name_column ? $first_name_column : $order_last;
		$sql         = "SELECT {$select} FROM {$table} {$where_sql} ORDER BY {$order_last} ASC, {$order_first} ASC, id ASC LIMIT 20";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		$items = array();

		foreach ( $rows as $row ) {
			$first_name = sanitize_text_field( $row['first_name'] ?? '' );
			$last_name  = sanitize_text_field( $row['last_name'] ?? '' );
			$birthdate  = sanitize_text_field( $row['birthdate'] ?? '' );
			$label_bits = array_filter( array( trim( $last_name . ' ' . $first_name ), $birthdate ) );

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

		return $items;
	}

	public function get_by_id( int $id, int $club_id ): ?array {
		if ( ! self::is_available() || ! $club_id || ! $id ) {
			return null;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'ufsc_licences';
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$license_column = $this->resolve_first_column(
			$table,
			array( 'numero_licence_delegataire', 'numero_licence', 'num_licence', 'licence_numero', 'licence_number' )
		);
		$last_name_column = $this->resolve_first_column( $table, array( 'nom_licence', 'nom' ) );
		$first_name_column = $this->resolve_first_column( $table, array( 'prenom', 'prenom_licence' ) );
		$birthdate_column = $this->resolve_first_column( $table, array( 'date_naissance', 'naissance', 'birthdate' ) );
		$sex_column = $this->resolve_first_column( $table, array( 'sexe', 'sex', 'gender' ) );
		$select_columns = array( 'id' );
		$select_columns[] = $last_name_column ? "{$last_name_column} AS last_name" : "'' AS last_name";
		$select_columns[] = $first_name_column ? "{$first_name_column} AS first_name" : "'' AS first_name";
		$select_columns[] = $birthdate_column ? "{$birthdate_column} AS birthdate" : "'' AS birthdate";
		$select_columns[] = $sex_column ? "{$sex_column} AS sex" : "'' AS sex";
		if ( $license_column ) {
			$select_columns[] = "{$license_column} AS license_number";
		} else {
			$select_columns[] = "'' AS license_number";
		}
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
		$label_bits = array_filter( array( trim( $last_name . ' ' . $first_name ), $birthdate ) );

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
		return class_exists( 'UFSC_LC_Plugin' )
			|| class_exists( 'UFSC_LC_Competition_Licences_List_Table' )
			|| class_exists( 'UFSC_LC_Club_Licences_Shortcode' );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
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
			if ( $this->has_column( $table, $candidate ) ) {
				return $candidate;
			}
		}

		return '';
	}
}
