<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Db {
	// Module DB version (bump when schema/index changes)
	const DB_VERSION = '1.5';
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

	/**
	 * Maybe upgrade DB: compare option and run create_tables if needed.
	 */
	public static function maybe_upgrade() {
		$option = get_option( self::DB_VERSION_OPTION, '' );
		if ( $option === self::DB_VERSION ) {
			return;
		}

		try {
			self::create_tables();
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		} catch ( \Throwable $e ) {
			// Never fatal: log and continue
			error_log( 'UFSC Competitions DB upgrade failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Create or update tables using dbDelta (non-destructive).
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$competitions_table = self::competitions_table();
		$categories_table   = self::categories_table();
		$logs_table         = self::logs_table();
		$entries_table      = self::entries_table();
		$fights_table       = self::fights_table();

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
	}
}
