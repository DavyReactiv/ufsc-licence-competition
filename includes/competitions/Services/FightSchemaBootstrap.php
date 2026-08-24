<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores the canonical fights table on fresh or incomplete installations.
 *
 * Legacy installations already containing the table are left untouched and
 * continue to use Db::maybe_upgrade_fights_table() for additive migrations.
 */
class FightSchemaBootstrap {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'plugins_loaded', array( __CLASS__, 'ensure_table' ), 0 );
		if ( defined( 'UFSC_LC_FILE' ) ) {
			register_activation_hook( UFSC_LC_FILE, array( __CLASS__, 'ensure_table' ) );
		}
	}

	public static function ensure_table(): void {
		global $wpdb;

		$table = Db::fights_table();
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $exists === $table ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			competition_id bigint(20) unsigned NOT NULL,
			category_id bigint(20) unsigned NULL,
			fight_no int unsigned NOT NULL DEFAULT 0,
			ring varchar(50) NULL,
			round_no int unsigned NULL,
			red_entry_id bigint(20) unsigned NULL,
			blue_entry_id bigint(20) unsigned NULL,
			winner_entry_id bigint(20) unsigned NULL,
			status varchar(30) NOT NULL DEFAULT 'scheduled',
			result_method varchar(100) NULL,
			score_red varchar(50) NULL,
			score_blue varchar(50) NULL,
			result_type varchar(50) NULL,
			result_note text NULL,
			scheduled_at datetime NULL,
			scheduled_order int NULL,
			scheduled_time datetime NULL,
			timing_profile_id bigint(20) unsigned NULL,
			round_duration smallint(5) unsigned NULL,
			rounds smallint(5) unsigned NULL,
			break_duration smallint(5) unsigned NULL,
			fight_pause smallint(5) unsigned NULL,
			fight_duration smallint(5) unsigned NULL,
			surface_index int NULL,
			surface_name varchar(120) NULL,
			surface_short_label varchar(20) NULL,
			surface_type varchar(40) NULL,
			next_fight_id bigint(20) unsigned NULL,
			next_slot varchar(20) NULL,
			source_red_fight_id bigint(20) unsigned NULL,
			source_blue_fight_id bigint(20) unsigned NULL,
			phase varchar(50) NULL,
			group_key varchar(191) NULL,
			completed_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			deleted_at datetime NULL DEFAULT NULL,
			deleted_by bigint(20) unsigned NULL,
			PRIMARY KEY  (id),
			KEY idx_competition_id (competition_id),
			KEY idx_category_id (category_id),
			KEY idx_status (status),
			KEY idx_deleted_at (deleted_at),
			KEY idx_deleted_status (deleted_at,status),
			KEY idx_competition_status (competition_id,status),
			KEY idx_fight_no (fight_no)
		) {$charset_collate};";

		dbDelta( $sql );

		$created = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $created !== $table ) {
			error_log( 'UFSC Competitions: failed to create fights table: ' . $wpdb->last_error );
		}
	}
}
