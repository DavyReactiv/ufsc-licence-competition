<?php

namespace UFSC\Competitions\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClubRepository {

	public function __construct() {
		// noop
	}

	private function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_clubs';
	}

	public function get( $id ) {
		global $wpdb;
		$id = absint( $id );
		if ( ! $id ) {
			return null;
		}

		$table = $this->table_name();

		// Defensive: table may not exist on some envs
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			return null;
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ) );
		return $row ?: null;
	}

	/**
	 * Return [id => "Nom (Région)"] for select.
	 */
	public function list_for_select( $limit = 2000 ) {
		global $wpdb;

		$table = $this->table_name();
		$exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) );
		if ( $exists !== $table ) {
			return array();
		}

		$limit = max( 1, (int) $limit );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, nom, region FROM {$table} ORDER BY nom ASC LIMIT %d", $limit ) );

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				$id = (int) ( $r->id ?? 0 );
				if ( ! $id ) {
					continue;
				}
				$nom    = (string) ( $r->nom ?? '' );
				$region = (string) ( $r->region ?? '' );
				$label  = $nom;
				if ( $region ) {
					$label .= ' (' . $region . ')';
				}
				$out[ $id ] = $label;
			}
		}

		return $out;
	}
}
