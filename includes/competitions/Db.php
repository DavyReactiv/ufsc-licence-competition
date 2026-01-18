<?php

namespace UFSC\Competitions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Db {
	// DB version for competitions module. Bump when schema changes.
	const DB_VERSION = '1.1';
	const DB_VERSION_OPTION = 'ufsc_competitions_db_version';
	// Backwards-compatible constants (do not remove).
	const VERSION = '1.1.0';
	const VERSION_OPTION = 'ufsc_lc_competitions_db_version';

	public static function competitions_table() {
		global $wpdb;

		return $wpdb->prefix . 'ufsc_competitions';
	}

	// ... autres méthodes inchangées ...

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

		// ... reste de la création des autres tables ...
	}

	/**
	 * Maybe upgrade competitions DB schema.
	 *
	 * Runs only in admin and when current user can manage options.
	 * Uses DB_VERSION / DB_VERSION_OPTION defined above.
	 */
	public static function maybe_upgrade() {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			// Ensure only privileged users trigger schema changes.
			return;
		}

		$installed = get_option( self::DB_VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		// run dbDelta via create_tables()
		self::create_tables();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
