<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Licence_Migrations {
	const BATCH_SIZE = 250;

	public function ensure_licence_columns() {
		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$changes = array();

		if ( ! $this->has_column( $table, 'season_end_year' ) ) {
			$changes[] = 'season_end_year int(4) NULL';
		}

		if ( ! $this->has_column( $table, 'category' ) ) {
			$changes[] = 'category varchar(50) NULL';
		}

		if ( ! $this->has_column( $table, 'age_ref' ) ) {
			$changes[] = 'age_ref int(3) NULL';
		}

		if ( empty( $changes ) ) {
			return;
		}

		$schema_row = $wpdb->get_row( "SHOW CREATE TABLE {$table}", ARRAY_N );
		if ( empty( $schema_row[1] ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$schema_sql = $schema_row[1];
		$schema_sql = preg_replace(
			'/\\)\\s*ENGINE/i',
			', ' . implode( ', ', $changes ) . ') ENGINE',
			$schema_sql,
			1
		);

		if ( $schema_sql ) {
			dbDelta( $schema_sql );
		}
	}

	public function backfill_categories() {
		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		if ( ! $this->has_column( $table, 'season_end_year' ) || ! $this->has_column( $table, 'category' ) || ! $this->has_column( $table, 'age_ref' ) ) {
			return;
		}

		$offset = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, date_naissance, season_end_year FROM {$table}
					WHERE season_end_year IS NOT NULL AND season_end_year != ''
					AND (category IS NULL OR category = '' OR age_ref IS NULL)
					ORDER BY id ASC
					LIMIT %d OFFSET %d",
					self::BATCH_SIZE,
					$offset
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$computed = UFSC_LC_Categories::category_from_birthdate( $row['date_naissance'], $row['season_end_year'] );
				$category = $computed['category'];
				$age_ref  = $computed['age'];

				$age_ref_sql = null === $age_ref ? 'NULL' : '%d';
				$params = array( $category );
				if ( null !== $age_ref ) {
					$params[] = $age_ref;
				}
				$params[] = (int) $row['id'];

				$sql = $wpdb->prepare(
					"UPDATE {$table} SET category = %s, age_ref = {$age_ref_sql} WHERE id = %d",
					$params
				);
				$wpdb->query( $sql );
			}

			$offset += self::BATCH_SIZE;
		} while ( ! empty( $rows ) );
	}

	private function has_column( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );

		return ! empty( $exists );
	}

	private function table_exists( $table ) {
		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	private function get_licences_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_licences';
	}
}
