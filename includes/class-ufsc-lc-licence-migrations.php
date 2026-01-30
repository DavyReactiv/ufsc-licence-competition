<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Licence migrations with defensive ALTER and FK handling.
 */
class UFSC_LC_Licence_Migrations {
	const BATCH_SIZE = 250;

	public function ensure_licence_columns() {
		global $wpdb;

		$table = $this->get_licences_table();
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$columns = array(
			'season_end_year'      => 'season_end_year int(4) NULL',
			'category'             => 'category varchar(50) NULL',
			'age_ref'              => 'age_ref int(3) NULL',
			'numero_licence_asptt' => 'numero_licence_asptt varchar(32) NULL',
			'import_batch_id'      => 'import_batch_id varchar(64) NULL',
		);

		foreach ( $columns as $col => $definition ) {
			if ( $this->has_column( $table, $col ) ) {
				continue;
			}

			$sql = "ALTER TABLE {$table} ADD COLUMN {$definition}";
			$res = $wpdb->query( $sql );

			if ( false === $res ) {
				$msg = "UFSC Licence Migrations: failed to add column {$col} to {$table}: {$wpdb->last_error}";
				error_log( $msg );
				// continue: do not abort activation
			}
		}

		// Defensive FK addition: licences.club_id -> ufsc_clubs.id
		$clubs_table = $this->get_clubs_table();

		if ( $this->table_exists( $table ) && $this->table_exists( $clubs_table ) && $this->column_exists( $table, 'club_id' ) && $this->column_exists( $clubs_table, 'id' ) ) {
			$lic_table_name   = str_replace( $wpdb->prefix, '', $table );
			$clubs_table_name = str_replace( $wpdb->prefix, '', $clubs_table );

			$lic_engine = $wpdb->get_var( $wpdb->prepare(
				"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$lic_table_name
			) );
			$club_engine = $wpdb->get_var( $wpdb->prepare(
				"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$clubs_table_name
			) );

			if ( 'InnoDB' === $lic_engine && 'InnoDB' === $club_engine ) {
				$exists = $wpdb->get_var( $wpdb->prepare(
					"SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s AND REFERENCED_TABLE_NAME = %s",
					$lic_table_name,
					'club_id',
					$clubs_table_name
				) );

				if ( empty( $exists ) ) {
					$has_index = $wpdb->get_var( $wpdb->prepare(
						"SELECT COUNT(1) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
						$lic_table_name,
						'club_id'
					) );

					if ( empty( $has_index ) ) {
						$idx_sql = "ALTER TABLE {$table} ADD INDEX idx_club_id (club_id)";
						$idx_res = $wpdb->query( $idx_sql );
						if ( false === $idx_res ) {
							error_log( "UFSC Licence Migrations: failed to add index idx_club_id on {$table}: {$wpdb->last_error}" );
						}
					}

					$constraint_name = $wpdb->prefix . 'fk_licence_club_id';
					$constraint_name = preg_replace( '/[^a-zA-Z0-9_]/', '_', $constraint_name );
					$fk_sql = "ALTER TABLE {$table} ADD CONSTRAINT {$constraint_name} FOREIGN KEY (club_id) REFERENCES {$clubs_table}(id) ON DELETE SET NULL ON UPDATE CASCADE";
					$fk_res = $wpdb->query( $fk_sql );
					if ( false === $fk_res ) {
						error_log( "UFSC Licence Migrations: failed to add FK {$constraint_name} on {$table}: {$wpdb->last_error}" );
					}
				}
			} else {
				error_log( "UFSC Licence Migrations: skipping FK add for {$table} -> {$clubs_table} because engine is not InnoDB (lic: {$lic_engine}, clubs: {$club_engine})." );
			}
		}

		$this->migrate_asptt_number_column( $table );
	}

	private function migrate_asptt_number_column( $table ) {
		global $wpdb;

		if ( ! $this->has_column( $table, 'numero_licence_asptt' ) || ! $this->has_column( $table, 'numero_licence_delegataire' ) ) {
			return;
		}

		$updated = $wpdb->query(
			"UPDATE {$table}
				SET numero_licence_asptt = numero_licence_delegataire
				WHERE (numero_licence_asptt IS NULL OR numero_licence_asptt = '')
				AND numero_licence_delegataire REGEXP '^[0-9]{5,}$'"
		);

		if ( false === $updated ) {
			error_log( "UFSC Licence Migrations: failed to migrate numero_licence_delegataire to numero_licence_asptt on {$table}: {$wpdb->last_error}" );
			return;
		}

		$cleared = $wpdb->query(
			"UPDATE {$table}
				SET numero_licence_delegataire = NULL
				WHERE (numero_licence_asptt IS NOT NULL AND numero_licence_asptt != '')
				AND numero_licence_delegataire REGEXP '^[0-9]{5,}$'"
		);

		if ( false === $cleared ) {
			error_log( "UFSC Licence Migrations: failed to clear numero_licence_delegataire values on {$table}: {$wpdb->last_error}" );
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

	private function get_clubs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_clubs';
	}

	private function column_exists( $table, $column ) {
		return $this->has_column( $table, $column );
	}
}
