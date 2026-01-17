<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Db {
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
			age_reference varchar(20) NOT NULL DEFAULT '12-31',
			weight_tolerance decimal(4,2) NOT NULL DEFAULT 1.00,
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
			deleted_at datetime NULL,
			deleted_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY idx_competition_id (competition_id),
			KEY idx_format (format),
			KEY idx_deleted_at (deleted_at)
		) {$charset_collate};";

		dbDelta( $categories_sql );

		$entries_sql = "CREATE TABLE {$entries_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NULL,
			club_id bigint(20) unsigned NULL,
			licensee_id bigint(20) unsigned NOT NULL,
			status varchar(50) NOT NULL DEFAULT 'draft',
			assigned_at datetime NULL,
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			deleted_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_competition_licensee (competition_id, licensee_id),
			KEY idx_category_status (category_id, status),
			KEY idx_competition_status (competition_id, status)
		) {$charset_collate};";

		dbDelta( $entries_sql );

		$fights_sql = "CREATE TABLE {$fights_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NOT NULL,
			fight_no int(11) unsigned NOT NULL DEFAULT 0,
			ring varchar(50) NULL,
			round_no smallint(5) unsigned NULL,
			red_entry_id bigint(20) unsigned NULL,
			blue_entry_id bigint(20) unsigned NULL,
			winner_entry_id bigint(20) unsigned NULL,
			status varchar(50) NOT NULL DEFAULT 'scheduled',
			result_method varchar(100) NULL,
			score_red varchar(50) NULL,
			score_blue varchar(50) NULL,
			scheduled_at datetime NULL,
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			deleted_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY idx_competition_category (competition_id, category_id),
			KEY idx_status_fight (status, fight_no)
		) {$charset_collate};";

		dbDelta( $fights_sql );

		$logs_sql = "CREATE TABLE {$logs_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			object_type varchar(100) NOT NULL,
			object_id bigint(20) unsigned NULL,
			action varchar(100) NOT NULL,
			message varchar(255) NOT NULL,
			context longtext NULL,
			user_id bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_object (object_type, object_id),
			KEY idx_created_at (created_at)
		) {$charset_collate};";

		dbDelta( $logs_sql );

		update_option( self::VERSION_OPTION, self::VERSION, false );
	}
}
