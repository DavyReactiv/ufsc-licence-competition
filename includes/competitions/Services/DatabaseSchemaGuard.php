<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies the schema actually produced by dbDelta/ALTER statements before the
 * competition DB version is marked as current. No repair or destructive SQL.
 */
class DatabaseSchemaGuard {
	private const HEALTH_OPTION = 'ufsc_competitions_schema_health_last';
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'pre_update_option_' . Db::DB_VERSION_OPTION, array( __CLASS__, 'guard_version_update' ), 20, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notice' ), 15 );
	}

	public static function guard_version_update( $new_value, $old_value, $option ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( (string) $new_value === (string) $old_value ) {
			return $new_value;
		}

		$report = self::inspect();
		update_option( self::HEALTH_OPTION, $report, false );
		if ( ! empty( $report['ok'] ) ) {
			return $new_value;
		}

		return $old_value;
	}

	public static function inspect(): array {
		$requirements = array(
			'competitions' => array(
				'table'   => Db::competitions_table(),
				'columns' => array( 'id', 'name', 'discipline', 'type', 'season', 'status', 'event_start_datetime', 'updated_at', 'deleted_at' ),
				'indexes' => array( 'PRIMARY', 'idx_status', 'idx_season' ),
			),
			'categories' => array(
				'table'   => Db::categories_table(),
				'columns' => array( 'id', 'competition_id', 'discipline', 'name', 'updated_at', 'deleted_at' ),
				'indexes' => array( 'PRIMARY', 'idx_competition_id' ),
			),
			'entries' => array(
				'table'   => Db::entries_table(),
				'columns' => array( 'id', 'competition_id', 'category_id', 'status', 'assigned_at', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'idx_competition_status' ),
			),
			'timing_profiles' => array(
				'table'   => Db::timing_profiles_table(),
				'columns' => array( 'id', 'name', 'round_duration', 'rounds', 'break_duration' ),
				'indexes' => array( 'PRIMARY' ),
			),
			'weighins' => array(
				'table'   => Db::weighins_table(),
				'columns' => array( 'id', 'competition_id', 'entry_id', 'weight_measured', 'status', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'uniq_competition_entry' ),
			),
			'external_participants' => array(
				'table'   => Db::external_participants_table(),
				'columns' => array( 'id', 'entry_id', 'participant_type', 'validation_status', 'updated_at' ),
				'indexes' => array( 'PRIMARY', 'uniq_entry_id' ),
			),
		);

		$missing_tables  = array();
		$missing_columns = array();
		$missing_indexes = array();

		foreach ( $requirements as $name => $requirement ) {
			$table  = (string) $requirement['table'];
			$schema = self::read_table_schema( $table );
			if ( empty( $schema['exists'] ) ) {
				$missing_tables[] = $name;
				continue;
			}

			foreach ( $requirement['columns'] as $column ) {
				if ( ! in_array( $column, $schema['columns'], true ) ) {
					$missing_columns[] = $name . '.' . $column;
				}
			}
			foreach ( $requirement['indexes'] as $index ) {
				if ( ! in_array( $index, $schema['indexes'], true ) ) {
					$missing_indexes[] = $name . '.' . $index;
				}
			}
		}

		$entries_schema  = self::read_table_schema( Db::entries_table() );
		$has_license_key = in_array( 'licensee_id', $entries_schema['columns'], true ) || in_array( 'licence_id', $entries_schema['columns'], true );
		if ( ! $has_license_key && ! in_array( 'entries', $missing_tables, true ) ) {
			$missing_columns[] = 'entries.licensee_id|licence_id';
		}

		$blocking = count( $missing_tables ) + count( $missing_columns ) + count( $missing_indexes );
		return array(
			'ok'              => 0 === $blocking,
			'db_version'      => Db::DB_VERSION,
			'missing_tables'  => $missing_tables,
			'missing_columns' => $missing_columns,
			'missing_indexes' => $missing_indexes,
			'checked_at_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function get_last_report(): array {
		$report = get_option( self::HEALTH_OPTION, array() );
		return is_array( $report ) ? $report : array();
	}

	public static function render_admin_notice(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = self::get_last_report();
		if ( empty( $report ) || ! empty( $report['ok'] ) ) {
			return;
		}

		$missing = array_merge(
			(array) ( $report['missing_tables'] ?? array() ),
			(array) ( $report['missing_columns'] ?? array() ),
			(array) ( $report['missing_indexes'] ?? array() )
		);
		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
			esc_html__( 'Base compétition : mise à niveau incomplète.', 'ufsc-licence-competition' ),
			esc_html(
				sprintf(
					/* translators: %s: comma-separated missing schema items */
					__( 'La version DB n’a pas été validée. Éléments manquants : %s', 'ufsc-licence-competition' ),
					implode( ', ', array_map( 'sanitize_text_field', $missing ) )
				)
			)
		);
	}

	private static function read_table_schema( string $table ): array {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists !== $table ) {
			return array( 'exists' => false, 'columns' => array(), 'indexes' => array() );
		}

		$column_rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$index_rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$columns     = array();
		$indexes     = array();
		foreach ( (array) $column_rows as $row ) {
			$row_data = (array) $row;
			$field    = isset( $row_data['Field'] ) ? (string) $row_data['Field'] : '';
			if ( '' !== $field ) {
				$columns[] = $field;
			}
		}
		foreach ( (array) $index_rows as $row ) {
			$row_data = (array) $row;
			$key      = isset( $row_data['Key_name'] ) ? (string) $row_data['Key_name'] : '';
			if ( '' !== $key ) {
				$indexes[] = $key;
			}
		}

		return array(
			'exists'  => true,
			'columns' => array_values( array_unique( $columns ) ),
			'indexes' => array_values( array_unique( $indexes ) ),
		);
	}
}
