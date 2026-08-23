<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Read-only integrity checks for competition-domain data. */
class CompetitionIntegrityService {
	public function audit( int $competition_id = 0 ): array {
		global $wpdb;

		$competition_id = absint( $competition_id );
		$tables = array(
			'competitions' => Db::competitions_table(),
			'entries'      => Db::entries_table(),
			'fights'       => Db::fights_table(),
			'weighins'     => Db::weighins_table(),
			'categories'   => Db::categories_table(),
		);

		$missing_tables = array();
		foreach ( $tables as $name => $table ) {
			if ( ! Db::table_exists( $table ) ) {
				$missing_tables[] = $name;
			}
		}

		if ( $missing_tables ) {
			return array(
				'ok'             => false,
				'competition_id' => $competition_id,
				'missing_tables' => $missing_tables,
				'issues'         => array(),
				'total_issues'   => count( $missing_tables ),
			);
		}

		$scope_entries  = $competition_id ? $wpdb->prepare( ' AND e.competition_id = %d', $competition_id ) : '';
		$scope_fights   = $competition_id ? $wpdb->prepare( ' AND f.competition_id = %d', $competition_id ) : '';
		$scope_weighin  = $competition_id ? $wpdb->prepare( ' AND w.competition_id = %d', $competition_id ) : '';
		$scope_category = $competition_id ? $wpdb->prepare( ' AND c.competition_id = %d', $competition_id ) : '';

		$issues = array();

		$sql = "SELECT COUNT(*) FROM {$tables['entries']} e LEFT JOIN {$tables['competitions']} cp ON cp.id = e.competition_id WHERE cp.id IS NULL {$scope_entries}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['entries_without_competition'] = self::count_query( $sql );

		$sql = "SELECT COUNT(*) FROM {$tables['categories']} c LEFT JOIN {$tables['competitions']} cp ON cp.id = c.competition_id WHERE c.competition_id IS NOT NULL AND cp.id IS NULL {$scope_category}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['categories_without_competition'] = self::count_query( $sql );

		$sql = "SELECT COUNT(*) FROM {$tables['weighins']} w LEFT JOIN {$tables['entries']} e ON e.id = w.entry_id WHERE e.id IS NULL {$scope_weighin}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['weighins_without_entry'] = self::count_query( $sql );

		$sql = "SELECT COUNT(*) FROM {$tables['fights']} f LEFT JOIN {$tables['competitions']} cp ON cp.id = f.competition_id WHERE cp.id IS NULL {$scope_fights}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['fights_without_competition'] = self::count_query( $sql );

		$sql = "SELECT COUNT(*) FROM {$tables['fights']} f LEFT JOIN {$tables['entries']} e ON e.id = f.red_entry_id WHERE f.red_entry_id IS NOT NULL AND f.red_entry_id > 0 AND e.id IS NULL {$scope_fights}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['fights_with_missing_red_entry'] = self::count_query( $sql );

		$sql = "SELECT COUNT(*) FROM {$tables['fights']} f LEFT JOIN {$tables['entries']} e ON e.id = f.blue_entry_id WHERE f.blue_entry_id IS NOT NULL AND f.blue_entry_id > 0 AND e.id IS NULL {$scope_fights}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['fights_with_missing_blue_entry'] = self::count_query( $sql );

		$sql = "SELECT COUNT(*) FROM {$tables['fights']} f WHERE f.winner_entry_id IS NOT NULL AND f.winner_entry_id > 0 AND f.winner_entry_id <> COALESCE(f.red_entry_id, 0) AND f.winner_entry_id <> COALESCE(f.blue_entry_id, 0) {$scope_fights}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$issues['invalid_fight_winner'] = self::count_query( $sql );

		$licensee_column = Db::has_table_column( $tables['entries'], 'licensee_id' ) ? 'licensee_id' : ( Db::has_table_column( $tables['entries'], 'licence_id' ) ? 'licence_id' : '' );
		$issues['duplicate_active_entries'] = 0;
		if ( '' !== $licensee_column ) {
			$deleted_filter = Db::has_table_column( $tables['entries'], 'deleted_at' ) ? ' AND e.deleted_at IS NULL' : '';
			$sql = "SELECT COUNT(*) FROM (SELECT e.competition_id, e.{$licensee_column} FROM {$tables['entries']} e WHERE e.{$licensee_column} IS NOT NULL AND e.{$licensee_column} > 0 {$deleted_filter} {$scope_entries} GROUP BY e.competition_id, e.{$licensee_column} HAVING COUNT(*) > 1) duplicated_entries"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$issues['duplicate_active_entries'] = self::count_query( $sql );
		}

		$total = array_sum( array_map( 'intval', $issues ) );
		return array(
			'ok'             => 0 === $total,
			'competition_id' => $competition_id,
			'missing_tables' => array(),
			'issues'         => $issues,
			'total_issues'   => $total,
			'checked_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private static function count_query( string $sql ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
