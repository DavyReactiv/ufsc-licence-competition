<?php

namespace UFSC\Competitions\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClubRepository {

	/** @var bool|null */
	private $table_exists_cache = null;

	public function __construct() {
		// noop
	}

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_clubs';
	}

	/**
	 * Check if clubs table exists (cached per request).
	 *
	 * @return bool
	 */
	private function table_exists() {
		if ( null !== $this->table_exists_cache ) {
			return (bool) $this->table_exists_cache;
		}

		global $wpdb;
		$table = $this->table_name();

		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		$this->table_exists_cache = ( $exists === $table );

		return (bool) $this->table_exists_cache;
	}

	/**
	 * Get a club row by id.
	 *
	 * Expected columns (best case): id, nom, region
	 * But we remain defensive if the schema differs.
	 *
	 * @param int $id
	 * @return object|null
	 */
	public function get( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		if ( ! $this->table_exists() ) {
			return null;
		}

		$table = $this->table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );

		return $row ?: null;
	}

	/**
	 * Return [id => "Nom (Région)"] for select.
	 *
	 * @param int $limit
	 * @return array<int,string>
	 */
	public function list_for_select( $limit = 2000 ) {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$table = $this->table_name();
		$limit = max( 1, (int) $limit );

		/**
		 * NOTE: We try to fetch (id, nom, region).
		 * If the table differs, this query could fail — but on UFSC env it should exist.
		 */
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, nom, region FROM {$table} ORDER BY nom ASC LIMIT %d",
				$limit
			)
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$id = (int) ( $r->id ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}

			$nom_raw    = isset( $r->nom ) ? (string) $r->nom : '';
			$region_raw = isset( $r->region ) ? (string) $r->region : '';

			$nom    = trim( wp_strip_all_tags( $nom_raw ) );
			$region = trim( wp_strip_all_tags( $region_raw ) );

			if ( '' === $nom ) {
				continue;
			}

			$label = $nom;
			if ( '' !== $region ) {
				$label .= ' (' . $region . ')';
			}

			$out[ $id ] = $label;
		}

		return $out;
	}

	/**
	 * Return distinct regions for select inputs.
	 *
	 * @param int $limit
	 * @return array<int,string>
	 */
	public function list_regions( $limit = 2000 ): array {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		$table = $this->table_name();
		$limit = max( 1, (int) $limit );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT region FROM {$table} WHERE region IS NOT NULL AND region != '' ORDER BY region ASC LIMIT %d",
				$limit
			)
		);

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		$duplicates = array();
		foreach ( $rows as $row ) {
			$region_raw = trim( wp_strip_all_tags( (string) ( $row->region ?? '' ) ) );
			if ( '' === $region_raw ) {
				continue;
			}

			$region_key = function_exists( 'ufsc_lc_normalize_region_key' )
				? ufsc_lc_normalize_region_key( $region_raw )
				: ( function_exists( 'ufsc_lc_normalize_region' ) ? ufsc_lc_normalize_region( $region_raw ) : strtoupper( $region_raw ) );
			$region_key = trim( $region_key );
			if ( '' === $region_key ) {
				continue;
			}

			if ( isset( $out[ $region_key ] ) ) {
				if ( $out[ $region_key ] !== $region_raw ) {
					$duplicates[ $region_key ] = true;
				}
				continue;
			}

			$out[ $region_key ] = $region_raw;
		}

		if ( ! empty( $duplicates ) && defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
			static $logged = false;
			if ( ! $logged ) {
				$logged = true;
				error_log(
					sprintf(
						'UFSC Competitions: doublons détectés dans les régions (%d).',
						count( $duplicates )
					)
				);
			}
		}

		return array_values( $out );
	}

	/**
	 * Optional helper: builds a "Nom (Région)" label from a club row.
	 *
	 * @param object $club
	 * @return string
	 */
	public function get_region_label( $club ) {
		if ( ! is_object( $club ) ) {
			return '';
		}
		$nom    = trim( wp_strip_all_tags( (string) ( $club->nom ?? '' ) ) );
		$region = trim( wp_strip_all_tags( (string) ( $club->region ?? '' ) ) );

		if ( '' === $nom ) {
			return '';
		}
		return ( '' !== $region ) ? $nom . ' (' . $region . ')' : $nom;
	}
}
