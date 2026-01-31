<?php

namespace UFSC\Competitions\Repositories;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TimingProfileRepository {
	public function list( array $filters = array(), int $limit = 200, int $offset = 0 ): array {
		global $wpdb;

		$where = array( '1=1' );

		if ( ! empty( $filters['discipline'] ) ) {
			$where[] = $wpdb->prepare( 'discipline = %s', sanitize_text_field( $filters['discipline'] ) );
		}

		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$sql = "SELECT * FROM " . Db::timing_profiles_table() . " {$where_sql} ORDER BY name ASC";
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', absint( $limit ), absint( $offset ) );

		$rows = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	public function get( int $id ) {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM " . Db::timing_profiles_table() . ' WHERE id = %d',
				absint( $id )
			)
		);
	}

	public function insert( array $data ): int {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['created_at'] = current_time( 'mysql' );
		$prepared['updated_at'] = current_time( 'mysql' );

		$wpdb->insert( Db::timing_profiles_table(), $prepared, $this->get_insert_format() );
		return (int) $wpdb->insert_id;
	}

	public function update( int $id, array $data ): bool {
		global $wpdb;

		$prepared = $this->sanitize( $data );
		$prepared['updated_at'] = current_time( 'mysql' );

		$result = $wpdb->update(
			Db::timing_profiles_table(),
			$prepared,
			array( 'id' => absint( $id ) ),
			$this->get_update_format(),
			array( '%d' )
		);

		return false !== $result;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		return false !== $wpdb->delete(
			Db::timing_profiles_table(),
			array( 'id' => absint( $id ) ),
			array( '%d' )
		);
	}

	private function sanitize( array $data ): array {
		$name = isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '';

		$discipline = isset( $data['discipline'] ) ? sanitize_text_field( (string) $data['discipline'] ) : '';
		$discipline = '' !== $discipline ? $discipline : null;

		$age_min = isset( $data['age_min'] ) && '' !== $data['age_min'] ? absint( $data['age_min'] ) : null;
		$age_max = isset( $data['age_max'] ) && '' !== $data['age_max'] ? absint( $data['age_max'] ) : null;
		$level = isset( $data['level'] ) ? sanitize_text_field( (string) $data['level'] ) : '';
		$level = '' !== $level ? $level : null;
		$format = isset( $data['format'] ) ? sanitize_text_field( (string) $data['format'] ) : '';
		$format = '' !== $format ? $format : null;

		$round_duration = isset( $data['round_duration'] ) ? max( 1, absint( $data['round_duration'] ) ) : 2;
		$rounds = isset( $data['rounds'] ) ? max( 1, absint( $data['rounds'] ) ) : 1;
		$break_duration = isset( $data['break_duration'] ) ? max( 0, absint( $data['break_duration'] ) ) : 1;
		$fight_pause = isset( $data['fight_pause'] ) ? max( 0, absint( $data['fight_pause'] ) ) : 0;

		return array(
			'name' => $name,
			'discipline' => $discipline,
			'age_min' => $age_min,
			'age_max' => $age_max,
			'level' => $level,
			'format' => $format,
			'round_duration' => $round_duration,
			'rounds' => $rounds,
			'break_duration' => $break_duration,
			'fight_pause' => $fight_pause,
		);
	}

	private function get_insert_format(): array {
		return array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' );
	}

	private function get_update_format(): array {
		return array( '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%s' );
	}
}
