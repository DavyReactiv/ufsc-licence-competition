<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Licence_Repository {
	private $clubs_table_exists = null;
	private $licences_table_exists = null;
	private $club_region_column = null;

	public function get_licences_table(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'ufsc_licences';
		if ( null === $this->licences_table_exists ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->licences_table_exists = ( $exists === $table );
		}

		return $this->licences_table_exists ? $table : '';
	}

	public function get_clubs_table(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'ufsc_clubs';
		if ( null === $this->clubs_table_exists ) {
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->clubs_table_exists = ( $exists === $table );
		}

		return $this->clubs_table_exists ? $table : '';
	}

	public function get_club_region_column(): string {
		if ( null !== $this->club_region_column ) {
			return $this->club_region_column;
		}

		$table = $this->get_clubs_table();
		if ( '' === $table ) {
			$this->club_region_column = '';
			return $this->club_region_column;
		}

		global $wpdb;
		$column = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM {$table} LIKE %s",
				'region'
			)
		);

		$this->club_region_column = $column ? 'region' : '';

		return $this->club_region_column;
	}

	public function apply_scope_filter( array &$where, array &$params, string $club_alias = 'c' ): void {
		$scope = ufsc_lc_get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return;
		}

		$region_column = $this->get_club_region_column();
		if ( '' === $region_column ) {
			$where[] = '1=0';
			return;
		}

		$where[] = "{$club_alias}.{$region_column} = %s";
		$params[] = $scope;
	}

	public function get_licence_region( int $licence_id ): ?string {
		global $wpdb;

		$licence_id = absint( $licence_id );
		if ( ! $licence_id ) {
			return null;
		}

		$licences_table = $this->get_licences_table();
		$clubs_table    = $this->get_clubs_table();
		$region_column  = $this->get_club_region_column();

		if ( '' === $licences_table || '' === $clubs_table || '' === $region_column ) {
			return null;
		}

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT c.{$region_column} FROM {$licences_table} l LEFT JOIN {$clubs_table} c ON c.id = l.club_id WHERE l.id = %d",
				$licence_id
			)
		);

		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		return '' !== $value ? $value : null;
	}

	public function get_club_region( int $club_id ): ?string {
		global $wpdb;

		$club_id = absint( $club_id );
		if ( ! $club_id ) {
			return null;
		}

		$clubs_table   = $this->get_clubs_table();
		$region_column = $this->get_club_region_column();

		if ( '' === $clubs_table || '' === $region_column ) {
			return null;
		}

		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT {$region_column} FROM {$clubs_table} WHERE id = %d",
				$club_id
			)
		);

		$value = is_string( $value ) ? sanitize_key( $value ) : '';

		return '' !== $value ? $value : null;
	}

	public function assert_licence_in_scope( int $licence_id ): void {
		$scope = ufsc_lc_get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return;
		}

		$region = $this->get_licence_region( $licence_id );
		if ( null === $region || $region !== $scope ) {
			wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}

	public function assert_club_in_scope( int $club_id ): void {
		$scope = ufsc_lc_get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return;
		}

		$region = $this->get_club_region( $club_id );
		if ( null === $region || $region !== $scope ) {
			wp_die( esc_html__( 'Accès refusé : hors de votre région.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}
	}

	public function filter_licence_ids_in_scope( array $licence_ids ): array {
		$licence_ids = array_values( array_filter( array_map( 'absint', $licence_ids ) ) );
		if ( empty( $licence_ids ) ) {
			return array();
		}

		$scope = ufsc_lc_get_user_scope_region();
		if ( null === $scope || '' === $scope ) {
			return $licence_ids;
		}

		$licences_table = $this->get_licences_table();
		$clubs_table    = $this->get_clubs_table();
		$region_column  = $this->get_club_region_column();

		if ( '' === $licences_table || '' === $clubs_table || '' === $region_column ) {
			return array();
		}

		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $licence_ids ), '%d' ) );
		$sql = "SELECT l.id FROM {$licences_table} l INNER JOIN {$clubs_table} c ON c.id = l.club_id WHERE l.id IN ({$placeholders}) AND c.{$region_column} = %s";
		$params = array_merge( $licence_ids, array( $scope ) );

		$rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( 'absint', $rows );
	}
}
