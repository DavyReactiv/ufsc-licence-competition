<?php

namespace UFSC\Competitions\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ClubRepository {
	/**
	 * Retourne une liste id => label pour select
	 *
	 * @return array
	 */
	public function list_for_select() {
		global $wpdb;
		$table = $wpdb->prefix . 'ufsc_clubs';

		$sql = "SELECT id, nom, ville, region FROM {$table} ORDER BY nom ASC LIMIT 1000";
		$rows = $wpdb->get_results( $sql );

		$result = array();
		if ( $rows ) {
			foreach ( $rows as $r ) {
				$label = trim( $r->nom );
				if ( $r->ville ) {
					$label .= ' — ' . trim( $r->ville );
				}
				if ( $r->region ) {
					$label .= ' (' . trim( $r->region ) . ')';
				}
				$result[ (int) $r->id ] = $label;
			}
		}

		return $result;
	}

	/**
	 * Retourne la ligne complète du club
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
		$table = $wpdb->prefix . 'ufsc_clubs';
		$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id );
		return $wpdb->get_row( $sql );
	}
}
