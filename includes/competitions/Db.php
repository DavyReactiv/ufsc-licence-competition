<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Db {
	const VERSION = '1.0.0';
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

	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$competitions_table = self::competitions_table();
		$categories_table   = self::categories_table();
		$logs_table         = self::logs_table();

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
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			deleted_at datetime NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status),
			KEY idx_season (season),
			KEY idx_start_date (start_date),
			KEY idx_deleted_at (deleted_at)
		) {$charset_collate};";

		dbDelta( $competitions_sql );

		$categories_sql = "CREATE TABLE {$categories_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NULL,
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
			PRIMARY KEY  (id),
			KEY idx_competition_id (competition_id),
			KEY idx_deleted_at (deleted_at)
		) {$charset_collate};";

		dbDelta( $categories_sql );

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
