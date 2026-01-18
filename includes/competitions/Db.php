<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Competitions DB helper.
 */
class Db {
	// Module DB version (bump when schema changes)
	const DB_VERSION = '1.1';
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
			location varchar(190) NULL,
			start_date date NULL,
			end_date date NULL,
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
			KEY idx_start_date (start_date),
			KEY idx_registration_deadline (registration_deadline),
			KEY idx_deleted_at (deleted_at)
		) {$charset_collate};";

		dbDelta( $competitions_sql );

		$categories_sql = "CREATE TABLE {$categories_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NULL,
			discipline varchar(190) NOT NULL,
			name varchar(190) NOT NULL,
			age_min smallint(5) unsigned NULL,
			age_max smallint(5) unsigned NULL,
			weight_min decimal(5,2) NULL,
			weight_max decimal(5,2) NULL,
			sex varchar(20) NULL,
			level varchar(100) NULL,
			format varchar(100) NULL,
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_competition (competition_id),
			KEY idx_discipline (discipline)
		) {$charset_collate};";

		dbDelta( $categories_sql );

		$logs_sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NULL,
			action varchar(50) NOT NULL,
			user_id bigint(20) unsigned NULL,
			message text NULL,
			meta text NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_competition (competition_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		dbDelta( $logs_sql );

		$entries_sql = "CREATE TABLE {$entries_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NULL,
			license_id bigint(20) unsigned NULL,
			club_id bigint(20) unsigned NULL,
			participant_name varchar(255) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_competition (competition_id)
		) {$charset_collate};";

		dbDelta( $entries_sql );

		$fights_sql = "CREATE TABLE {$fights_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NOT NULL,
			round smallint(5) unsigned NULL,
			red_entry_id bigint(20) unsigned NULL,
			blue_entry_id bigint(20) unsigned NULL,
			result varchar(50) NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_competition (competition_id)
		) {$charset_collate};";

		dbDelta( $fights_sql );
	}

	/**
	 * Maybe upgrade competitions DB schema.
	 *
	 * Called on admin_init. Only runs for capable admins to avoid accidental upgrades from front requests.
	 */
	public static function maybe_upgrade() {
		// Only run in admin context.
		if ( ! is_admin() ) {
			return;
		}

		// Only privileged users.
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		// Ensure only one concurrent upgrade attempt (short transient)
		if ( get_transient( 'ufsc_competitions_upgrading' ) ) {
			return;
		}

		set_transient( 'ufsc_competitions_upgrading', 1, 60 );

		// dbDelta will create missing columns if table exists.
		self::create_tables();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );

		delete_transient( 'ufsc_competitions_upgrading' );
	}
}
