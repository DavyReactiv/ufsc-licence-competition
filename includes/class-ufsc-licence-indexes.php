<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Licence_Indexes {
	public function ensure_indexes() {
		global $wpdb;

		$this->ensure_licence_indexes( $wpdb->prefix . 'ufsc_licences' );
		$this->ensure_documents_indexes( $wpdb->prefix . 'ufsc_licence_documents' );
	}

	private function ensure_licence_indexes( $table ) {
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$indexes = $this->get_index_names( $table );

		$this->add_index_if_missing( $table, $indexes, 'idx_club_id', array( 'club_id' ) );
		$this->add_index_if_missing( $table, $indexes, 'idx_nom_licence', array( 'nom_licence' ) );
		$this->add_index_if_missing( $table, $indexes, 'idx_prenom', array( 'prenom' ) );
		$this->add_index_if_missing( $table, $indexes, 'idx_statut', array( 'statut' ) );
		if ( $this->column_exists( $table, 'categorie' ) ) {
			$this->add_index_if_missing( $table, $indexes, 'idx_categorie', array( 'categorie' ) );
		}
		if ( $this->column_exists( $table, 'category' ) ) {
			$this->add_index_if_missing( $table, $indexes, 'idx_category', array( 'category' ) );
		}
		if ( $this->column_exists( $table, 'season_end_year' ) ) {
			$this->add_index_if_missing( $table, $indexes, 'idx_season_end_year', array( 'season_end_year' ) );
		}
	}

	private function ensure_documents_indexes( $table ) {
		if ( ! $this->table_exists( $table ) ) {
			return;
		}

		$indexes = $this->get_index_names( $table );

		$this->add_index_if_missing( $table, $indexes, 'idx_licence_source', array( 'licence_id', 'source' ) );
		$this->add_index_if_missing( $table, $indexes, 'uniq_source_number', array( 'source', 'source_licence_number' ), true );
		$this->add_index_if_missing( $table, $indexes, 'idx_source_created_at', array( 'source_created_at' ) );
	}

	private function table_exists( $table ) {
		global $wpdb;

		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	private function column_exists( $table, $column ) {
		global $wpdb;

		$column = sanitize_key( $column );

		return (bool) $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	}

	private function get_index_names( $table ) {
		global $wpdb;

		$results = $wpdb->get_results( "SHOW INDEX FROM {$table}" );
		$indexes = array();

		foreach ( $results as $row ) {
			$indexes[ $row->Key_name ] = true;
		}

		return $indexes;
	}

	private function add_index_if_missing( $table, $indexes, $index_name, $columns, $unique = false ) {
		global $wpdb;

		if ( isset( $indexes[ $index_name ] ) ) {
			return;
		}

		$columns = array_map( 'sanitize_key', $columns );
		$columns_sql = '`' . implode( '`,`', $columns ) . '`';
		$index_type  = $unique ? 'UNIQUE INDEX' : 'INDEX';

		$wpdb->query( "ALTER TABLE {$table} ADD {$index_type} `{$index_name}` ({$columns_sql})" );
	}
}
