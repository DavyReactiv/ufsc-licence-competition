<?php
/**
 * DB schema for competitions module
 */

class Db {
	// Module DB version (bump when schema/index changes)
	const DB_VERSION = '1.4';
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
	 * Create (or update) tables used by competitions module.
	 * Uses dbDelta so existing tables get altered to include missing columns.
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

		$competitions_sql = "CREATE TABLE {$competitions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			discipline varchar(190) NOT NULL,
			type varchar(100) NOT NULL,
			season varchar(50) NOT NULL,
			-- organizer (club snapshot)
			organizer_club_id bigint(20) unsigned NULL,
			organizer_region varchar(64) NULL,
			organizer_email varchar(190) NULL,
			organizer_phone varchar(50) NULL,
			-- venue (manifestation location separate from club)
			venue_name varchar(191) NULL,
			venue_address1 varchar(191) NULL,
			venue_address2 varchar(191) NULL,
			venue_postcode varchar(16) NULL,
			venue_city varchar(64) NULL,
			venue_region varchar(64) NULL,
			venue_country varchar(2) NOT NULL DEFAULT 'FR',
			venue_maps_url varchar(255) NULL,
			venue_access_info text NULL,
			-- basic (legacy)
			location varchar(190) NULL,
			-- event dates & times
			event_start_date date NULL,
			event_end_date date NULL,
			event_start_time time NULL,
			event_end_time time NULL,
			-- registration windows
			reg_open_date date NULL,
			reg_open_time time NULL,
			reg_close_date date NULL,
			reg_close_time time NULL,
			-- weighin
			weighin_date date NULL,
			weighin_start_time time NULL,
			weighin_end_time time NULL,
			weighin_location_text text NULL,
			-- existing fields
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
			KEY idx_start_date (event_start_date),
			KEY idx_registration_deadline (registration_deadline),
			KEY idx_deleted_at (deleted_at),
			KEY idx_organizer_club_id (organizer_club_id),
			KEY idx_event_start_date (event_start_date),
			KEY idx_reg_close_date (reg_close_date),
			KEY idx_weighin_date (weighin_date),
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

		// Other tables unchanged: logs, entries, fights (existing definitions)
	}
}
