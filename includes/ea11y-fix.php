<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ensure the EA11y (pojo-accessibility) stats table exists to avoid SQL errors.
 */
if ( ! function_exists( 'ufsc_lc_maybe_create_ea11y_table' ) ) {
function ufsc_lc_maybe_create_ea11y_table(): void {
	global $wpdb;

	$should_run = false;
	if ( defined( 'EA11Y_VERSION' ) || class_exists( 'EA11y' ) || class_exists( 'Pojo_Accessibility' ) ) {
		$should_run = true;
	} elseif ( function_exists( 'is_plugin_active' ) ) {
		$should_run = is_plugin_active( 'pojo-accessibility/pojo-accessibility.php' )
			|| is_plugin_active( 'ea11y/ea11y.php' );
	} else {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		if ( function_exists( 'is_plugin_active' ) ) {
			$should_run = is_plugin_active( 'pojo-accessibility/pojo-accessibility.php' )
				|| is_plugin_active( 'ea11y/ea11y.php' );
		}
	}

	$should_run = (bool) apply_filters( 'ufsc_lc_should_repair_ea11y_table', $should_run );
	if ( ! $should_run ) {
		return;
	}

	$table = $wpdb->prefix . 'ea11y_page_scanned';
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists === $table ) {
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		page_id bigint(20) unsigned DEFAULT NULL,
		post_id bigint(20) unsigned DEFAULT NULL,
		post_type varchar(50) DEFAULT '' NOT NULL,
		status varchar(20) DEFAULT '' NOT NULL,
		score int(11) DEFAULT NULL,
		scan_hash varchar(64) DEFAULT '' NOT NULL,
		issues longtext,
		report longtext,
		url text,
		scan_date datetime DEFAULT NULL,
		scanned_at datetime DEFAULT NULL,
		created_at datetime DEFAULT NULL,
		updated_at datetime DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY page_id (page_id),
		KEY post_id (post_id),
		KEY scan_date (scan_date)
	) {$charset_collate};";

	dbDelta( $sql );
}
}

add_action( 'init', 'ufsc_lc_maybe_create_ea11y_table', 5 );
