<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Db {
	// Module DB version (bump when schema/index changes)
	const DB_VERSION = '1.11';
	const DB_VERSION_OPTION = 'ufsc_competitions_db_version';

	// Backwards-compatible constants (do not remove)
	const VERSION = '1.1.0';
	const VERSION_OPTION = 'ufsc_lc_competitions_db_version';

	public static function competitions_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_competitions';
	}

	public static function categories_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_competition_categories';
	}

	public static function logs_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_competition_logs';
	}

	public static function entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_competition_entries';
	}

	public static function fights_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_fights';
	}

	public static function timing_profiles_table() {
		global $wpdb;
		return $wpdb->prefix . 'ufsc_timing_profiles';
	}

	/**
	 * Maybe upgrade DB: compare option and run create_tables if needed.
	 */
	public static function maybe_upgrade() {
		static $did_run = false;

		if ( $did_run ) {
			return;
		}
		$did_run = true;

		$lock_key = 'ufsc_competitions_db_upgrade_lock';
		if ( get_transient( $lock_key ) ) {
			return;
		}
		set_transient( $lock_key, 1, 10 );

		$option              = get_option( self::DB_VERSION_OPTION, '' );
		$needs_version_upgrade = ( $option !== self::DB_VERSION );
		$needs_fights_upgrade  = self::fights_schema_needs_upgrade();

		// Nothing to do: release lock & exit.
		if ( ! $needs_version_upgrade && ! $needs_fights_upgrade ) {
			delete_transient( $lock_key );
			return;
		}

		try {
			if ( $needs_version_upgrade ) {
				self::create_tables();
				self::maybe_upgrade_entries_table();
			}

			if ( $needs_fights_upgrade ) {
				self::maybe_upgrade_fights_table();
			}

			if ( $needs_version_upgrade ) {
				update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
			}
		} catch ( \Throwable $e ) {
			// Never fatal: log and continue
			error_log( 'UFSC Competitions DB upgrade failed: ' . $e->getMessage() );
		} finally {
			delete_transient( $lock_key );
		}
	}

	/**
	 * Create or update tables using dbDelta (non-destructive).
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$competitions_table     = self::competitions_table();
		$categories_table       = self::categories_table();
		$logs_table             = self::logs_table();
		$entries_table          = self::entries_table();
		$fights_table           = self::fights_table();
		$timing_profiles_table  = self::timing_profiles_table();

		// Note: avoid SQL comments inside the CREATE TABLE string (dbDelta sensitivity)
		$competitions_sql = "CREATE TABLE {$competitions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			discipline varchar(190) NOT NULL,
			type varchar(100) NOT NULL,
			season varchar(50) NOT NULL,
			organizer_club_id bigint(20) unsigned NULL,
			organizer_club_name varchar(190) NULL,
			organizer_region varchar(190) NULL,
			venue_name varchar(190) NULL,
			venue_address1 varchar(190) NULL,
			venue_address2 varchar(190) NULL,
			venue_postcode varchar(20) NULL,
			venue_city varchar(190) NULL,
			venue_region varchar(190) NULL,
			event_start_datetime datetime NULL,
			event_end_datetime datetime NULL,
			registration_open_datetime datetime NULL,
			registration_close_datetime datetime NULL,
			weighin_start_datetime datetime NULL,
			weighin_end_datetime datetime NULL,
			contact_email varchar(190) NULL,
			contact_phone varchar(50) NULL,
			location varchar(190) NULL,
			registration_deadline date NULL,
			status varchar(50) NOT NULL,
			age_reference varchar(10) NOT NULL DEFAULT '12-31',
			weight_tolerance decimal(6,2) NOT NULL DEFAULT 1.00,
			allowed_formats varchar(255) NOT NULL DEFAULT '',
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			deleted_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_discipline (discipline),
			KEY idx_season (season),
			KEY idx_event_start_datetime (event_start_datetime),
			KEY idx_registration_deadline (registration_deadline),
			KEY idx_deleted_at (deleted_at),
			KEY idx_organizer_club_id (organizer_club_id),
			KEY idx_venue_region (venue_region)
		) {$charset_collate};";

		dbDelta( $competitions_sql );

		$categories_sql = "CREATE TABLE {$categories_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NULL,
			discipline varchar(190) NOT NULL,
			name varchar(190) NOT NULL,
			age_min smallint(5) unsigned NULL,
			age_max smallint(5) unsigned NULL,
			weight_min decimal(6,2) NULL,
			weight_max decimal(6,2) NULL,
			sex varchar(10) NULL,
			level varchar(50) NULL,
			format varchar(50) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_competition_id (competition_id)
		) {$charset_collate};";

		dbDelta( $categories_sql );

		// leave other tables as-is if present; they are created elsewhere if needed

		$timing_profiles_sql = "CREATE TABLE {$timing_profiles_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			discipline varchar(190) NULL,
			age_min smallint(5) unsigned NULL,
			age_max smallint(5) unsigned NULL,
			level varchar(50) NULL,
			format varchar(50) NULL,
			round_duration smallint(5) unsigned NOT NULL DEFAULT 2,
			rounds smallint(5) unsigned NOT NULL DEFAULT 1,
			break_duration smallint(5) unsigned NOT NULL DEFAULT 1,
			fight_pause smallint(5) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY (id),
			KEY idx_discipline (discipline),
			KEY idx_age_min (age_min),
			KEY idx_age_max (age_max)
		) {$charset_collate};";

		dbDelta( $timing_profiles_sql );
	}

	private static function maybe_upgrade_entries_table(): void {
		global $wpdb;

		$table  = self::entries_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$columns = $wpdb->get_col( "DESC {$table}", 0 );
		if ( ! is_array( $columns ) ) {
			$columns = array();
		}

		$desired = array(
			'status'          => "ALTER TABLE {$table} ADD COLUMN status varchar(50) NOT NULL DEFAULT 'draft'",
			'admin_note'      => "ALTER TABLE {$table} ADD COLUMN admin_note text NULL",
			'rejected_reason' => "ALTER TABLE {$table} ADD COLUMN rejected_reason text NULL",
			'submitted_at'    => "ALTER TABLE {$table} ADD COLUMN submitted_at datetime NULL",
			'validated_at'    => "ALTER TABLE {$table} ADD COLUMN validated_at datetime NULL",
			'updated_at'      => "ALTER TABLE {$table} ADD COLUMN updated_at datetime NULL",
			'updated_by'      => "ALTER TABLE {$table} ADD COLUMN updated_by bigint(20) unsigned NULL",
		);

		foreach ( $desired as $column => $sql ) {
			if ( in_array( $column, $columns, true ) ) {
				continue;
			}

			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				error_log(
					sprintf(
						'UFSC Competitions DB upgrade failed to add column %s: %s',
						$column,
						$wpdb->last_error
					)
				);
			}
		}
	}

	private static function maybe_upgrade_fights_table(): void {
		global $wpdb;

		$table  = self::fights_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return;
		}

		$columns = self::get_table_columns( $table );
		if ( ! is_array( $columns ) ) {
			$columns = array();
		}

		$desired = array(
			'fight_no'          => "ALTER TABLE {$table} ADD COLUMN fight_no int unsigned NOT NULL DEFAULT 0",
			'timing_profile_id' => "ALTER TABLE {$table} ADD COLUMN timing_profile_id bigint(20) unsigned NULL",
			'round_duration'    => "ALTER TABLE {$table} ADD COLUMN round_duration smallint(5) unsigned NULL",
			'rounds'            => "ALTER TABLE {$table} ADD COLUMN rounds smallint(5) unsigned NULL",
			'break_duration'    => "ALTER TABLE {$table} ADD COLUMN break_duration smallint(5) unsigned NULL",
			'fight_pause'       => "ALTER TABLE {$table} ADD COLUMN fight_pause smallint(5) unsigned NULL",
			'fight_duration'    => "ALTER TABLE {$table} ADD COLUMN fight_duration smallint(5) unsigned NULL",
			'deleted_at'        => "ALTER TABLE {$table} ADD COLUMN deleted_at datetime NULL DEFAULT NULL",
		);

		foreach ( $desired as $column => $sql ) {
			if ( in_array( $column, $columns, true ) ) {
				continue;
			}

			$result = $wpdb->query( $sql );
			if ( false === $result ) {
				error_log(
					sprintf(
						'UFSC Competitions DB upgrade failed to add column %s: %s',
						$column,
						$wpdb->last_error
					)
				);
			} else {
				$columns[] = $column;
			}
		}

		if ( in_array( 'deleted_at', $columns, true ) ) {
			$index_exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_deleted_at'" );
			if ( empty( $index_exists ) ) {
				$index_result = $wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_deleted_at (deleted_at)" );
				if ( false === $index_result ) {
					error_log(
						sprintf(
							'UFSC Competitions DB upgrade failed to add index idx_deleted_at: %s',
							$wpdb->last_error
						)
					);
				}
			}
		}

		if ( in_array( 'fight_no', $columns, true ) ) {
			$index_exists = $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'idx_fight_no'" );
			if ( empty( $index_exists ) ) {
				$index_result = $wpdb->query( "ALTER TABLE {$table} ADD INDEX idx_fight_no (fight_no)" );
				if ( false === $index_result ) {
					error_log(
						sprintf(
							'UFSC Competitions DB upgrade failed to add index idx_fight_no: %s',
							$wpdb->last_error
						)
					);
				}
			}

			// Optional safe backfill so ordering is stable on legacy rows.
			self::maybe_backfill_fight_no( $table );
		}
	}

	private static function fights_schema_needs_upgrade(): bool {
		global $wpdb;

		$table  = self::fights_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return false;
		}

		$columns = self::get_table_columns( $table );
		if ( ! is_array( $columns ) ) {
			return true;
		}

		foreach ( array( 'fight_no', 'deleted_at' ) as $required ) {
			if ( ! in_array( $required, $columns, true ) ) {
				return true;
			}
		}

		return false;
	}

	public static function get_table_columns( string $table ): array {
		global $wpdb;

		$columns = array();
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
				$table
			)
		);

		if ( is_array( $results ) ) {
			foreach ( $results as $row ) {
				if ( ! empty( $row->COLUMN_NAME ) ) {
					$columns[] = $row->COLUMN_NAME;
				}
			}
		}

		if ( $columns ) {
			return $columns;
		}

		$fallback = $wpdb->get_col( "DESC {$table}", 0 );
		return is_array( $fallback ) ? $fallback : array();
	}

	public static function has_table_column( string $table, string $column ): bool {
		static $cache = array();

		$cache_key = $table . ':' . $column;
		if ( array_key_exists( $cache_key, $cache ) ) {
			return $cache[ $cache_key ];
		}

		$wp_cache_key = 'ufsc_competitions_column_' . md5( $cache_key );
		$cached       = wp_cache_get( $wp_cache_key, 'ufsc_competitions' );

		if ( false !== $cached ) {
			$cache[ $cache_key ] = (bool) $cached;
			return $cache[ $cache_key ];
		}

		$columns              = self::get_table_columns( $table );
		$cache[ $cache_key ]  = in_array( $column, $columns, true );

		wp_cache_set( $wp_cache_key, $cache[ $cache_key ], 'ufsc_competitions', HOUR_IN_SECONDS );

		return $cache[ $cache_key ];
	}

	private static function maybe_backfill_fight_no( string $table ): void {
		global $wpdb;

		$option_key = 'ufsc_competitions_fights_fight_no_backfill_done';
		if ( get_option( $option_key ) ) {
			return;
		}

		$remaining = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE fight_no = 0 OR fight_no IS NULL" );
		if ( 0 === $remaining ) {
			update_option( $option_key, 1, false );
			return;
		}

		$updated = $wpdb->query(
			"UPDATE {$table} SET fight_no = id
			 WHERE fight_no = 0 OR fight_no IS NULL
			 ORDER BY id ASC
			 LIMIT 2000"
		);

		if ( false === $updated ) {
			error_log(
				sprintf(
					'UFSC Competitions DB upgrade failed to backfill fight_no: %s',
					$wpdb->last_error
				)
			);
			return;
		}

		$remaining_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE fight_no = 0 OR fight_no IS NULL" );
		if ( 0 === $remaining_after ) {
			update_option( $option_key, 1, false );
		}
	}
}
