<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryDeduplication {
	const UNIQUE_INDEX_NAME = 'uniq_competition_licensee';

	public static function register_cli_commands(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'ufsc competitions entries-dedup-audit', array( __CLASS__, 'cli_audit' ) );
		\WP_CLI::add_command( 'ufsc competitions entries-dedup-resolve', array( __CLASS__, 'cli_resolve' ) );
		\WP_CLI::add_command( 'ufsc competitions entries-dedup-rollback', array( __CLASS__, 'cli_rollback' ) );
	}

	public static function cli_audit( array $args, array $assoc_args ): void {
		$report = self::audit_duplicates();
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	public static function cli_resolve( array $args, array $assoc_args ): void {
		$dry_run = ! isset( $assoc_args['apply'] );
		$result  = self::resolve_duplicates( $dry_run );
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	public static function cli_rollback( array $args, array $assoc_args ): void {
		$result = self::drop_unique_index();
		if ( class_exists( '\\WP_CLI' ) ) {
			\WP_CLI::line( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	public static function get_business_key_definition(): array {
		$table          = Db::entries_table();
		$licensee_col   = self::get_licensee_column();
		$has_deleted_at = Db::has_table_column( $table, 'deleted_at' );

		return array(
			'table'                  => $table,
			'licensee_column'        => $licensee_col,
			'unique_index_name'      => self::UNIQUE_INDEX_NAME,
			'business_key'           => $licensee_col ? array( 'competition_id', $licensee_col ) : array(),
			'active_row_filter'      => $has_deleted_at ? 'deleted_at IS NULL' : 'none',
			'deleted_at_column'      => $has_deleted_at,
			'legacy_compat_fallback' => Db::has_table_column( $table, 'licence_id' ),
		);
	}

	public static function audit_duplicates(): array {
		global $wpdb;

		$table = Db::entries_table();
		$key   = self::get_business_key_definition();
		$column = $key['licensee_column'];
		if ( '' === $column ) {
			return array(
				'ok'      => false,
				'message' => 'No licensee column found (licensee_id/licence_id).',
			);
		}

		$where = array( "{$column} IS NOT NULL", "{$column} > 0" );
		if ( ! empty( $key['deleted_at_column'] ) ) {
			$where[] = 'deleted_at IS NULL';
		}
		$where_sql = 'WHERE ' . implode( ' AND ', $where );

		$groups = $wpdb->get_results(
			"SELECT competition_id, {$column} AS licensee_key, COUNT(*) AS duplicate_count,
				MIN(id) AS keeper_candidate_id,
				GROUP_CONCAT(id ORDER BY created_at ASC, id ASC SEPARATOR ',') AS entry_ids
			FROM {$table}
			{$where_sql}
			GROUP BY competition_id, {$column}
			HAVING COUNT(*) > 1
			ORDER BY duplicate_count DESC, competition_id ASC, licensee_key ASC"
		);

		$total_extra = 0;
		$normalized_groups = array();
		if ( is_array( $groups ) ) {
			foreach ( $groups as $group ) {
				$count = (int) ( $group->duplicate_count ?? 0 );
				if ( $count < 2 ) {
					continue;
				}
				$total_extra += ( $count - 1 );
				$normalized_groups[] = array(
					'competition_id'      => (int) ( $group->competition_id ?? 0 ),
					'licensee_key'        => (int) ( $group->licensee_key ?? 0 ),
					'duplicate_count'     => $count,
					'keeper_candidate_id' => (int) ( $group->keeper_candidate_id ?? 0 ),
					'entry_ids'           => array_filter( array_map( 'absint', explode( ',', (string) ( $group->entry_ids ?? '' ) ) ) ),
				);
			}
		}

		$index_exists = (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", self::UNIQUE_INDEX_NAME )
		);

		return array(
			'ok'                => true,
			'key'               => $key,
			'unique_index'      => array(
				'name'   => self::UNIQUE_INDEX_NAME,
				'exists' => $index_exists,
			),
			'duplicated_groups' => count( $normalized_groups ),
			'extra_rows'        => $total_extra,
			'groups'            => $normalized_groups,
			'generated_at_gmt'  => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function maybe_backfill_licensee_id(): int {
		global $wpdb;

		$table = Db::entries_table();
		if ( ! Db::has_table_column( $table, 'licensee_id' ) || ! Db::has_table_column( $table, 'licence_id' ) ) {
			return 0;
		}

		$updated = $wpdb->query(
			"UPDATE {$table}
			SET licensee_id = licence_id
			WHERE (licensee_id IS NULL OR licensee_id = 0)
			  AND licence_id IS NOT NULL
			  AND licence_id > 0"
		);

		return false === $updated ? 0 : (int) $updated;
	}

	public static function resolve_duplicates( bool $dry_run = true ): array {
		global $wpdb;

		$report = self::audit_duplicates();
		if ( empty( $report['ok'] ) ) {
			return $report;
		}

		$key         = $report['key'];
		$has_deleted = ! empty( $key['deleted_at_column'] );
		$table       = Db::entries_table();

		$operations  = array();
		$affected    = 0;
		foreach ( $report['groups'] as $group ) {
			$ids = $group['entry_ids'];
			if ( count( $ids ) < 2 ) {
				continue;
			}

			$keeper_id = (int) $group['keeper_candidate_id'];
			$to_remove = array_values( array_diff( $ids, array( $keeper_id ) ) );
			if ( ! $to_remove ) {
				continue;
			}

			$operations[] = array(
				'competition_id' => (int) $group['competition_id'],
				'licensee_key'   => (int) $group['licensee_key'],
				'keep_id'        => $keeper_id,
				'target_ids'     => $to_remove,
				'strategy'       => $has_deleted ? 'soft_delete_newer' : 'hard_delete_newer',
			);

			if ( $dry_run ) {
				continue;
			}

			$placeholders = implode( ',', array_fill( 0, count( $to_remove ), '%d' ) );
			if ( $has_deleted ) {
				$sql = $wpdb->prepare(
					"UPDATE {$table}
					 SET deleted_at = %s,
					     updated_at = %s,
					     updated_by = %d,
					     deleted_by = %d
					 WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
					array_merge( array( current_time( 'mysql' ), current_time( 'mysql' ), get_current_user_id() ?: 0, get_current_user_id() ?: 0 ), $to_remove )
				);
				$result = $wpdb->query( $sql );
			} else {
				$sql = $wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ({$placeholders})",
					$to_remove
				);
				$result = $wpdb->query( $sql );
			}
			$affected += false === $result ? 0 : (int) $result;
		}

		if ( ! $dry_run ) {
			update_option(
				'ufsc_competitions_entries_dedup_last_resolution',
				array(
					'ran_at_gmt'    => gmdate( 'Y-m-d H:i:s' ),
					'affected_rows' => $affected,
					'operations'    => $operations,
				),
				false
			);
		}

		return array(
			'ok'          => true,
			'dry_run'     => $dry_run,
			'affected'    => $affected,
			'operations'  => $operations,
			'generated_at_gmt' => gmdate( 'Y-m-d H:i:s' ),
		);
	}

	public static function maybe_add_unique_index_if_clean(): array {
		global $wpdb;

		$table = Db::entries_table();
		$key   = self::get_business_key_definition();
		if ( empty( $key['licensee_column'] ) ) {
			return array( 'ok' => false, 'reason' => 'missing_licensee_column' );
		}

		$duplicate_report = self::audit_duplicates();
		update_option( 'ufsc_competitions_entries_dedup_last_audit', $duplicate_report, false );

		if ( ! empty( $duplicate_report['duplicated_groups'] ) ) {
			self::debug_log( 'unique_index_skipped_dirty_data', $duplicate_report );
			return array( 'ok' => false, 'reason' => 'duplicates_found', 'report' => $duplicate_report );
		}

		$index_exists = (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", self::UNIQUE_INDEX_NAME )
		);
		if ( $index_exists ) {
			return array( 'ok' => true, 'added' => false, 'reason' => 'already_exists' );
		}

		$column = sanitize_key( $key['licensee_column'] );
		if ( '' === $column ) {
			return array( 'ok' => false, 'reason' => 'invalid_key_column' );
		}

		$result = $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE INDEX " . self::UNIQUE_INDEX_NAME . " (competition_id, {$column})" );
		if ( false === $result ) {
			self::debug_log(
				'unique_index_add_failed',
				array(
					'error' => (string) $wpdb->last_error,
				)
			);
			return array( 'ok' => false, 'reason' => 'sql_error', 'error' => (string) $wpdb->last_error );
		}

		return array( 'ok' => true, 'added' => true, 'index' => self::UNIQUE_INDEX_NAME, 'column' => $column );
	}

	public static function drop_unique_index(): array {
		global $wpdb;

		$table = Db::entries_table();
		$exists = (bool) $wpdb->get_var(
			$wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", self::UNIQUE_INDEX_NAME )
		);
		if ( ! $exists ) {
			return array( 'ok' => true, 'dropped' => false, 'reason' => 'not_found' );
		}

		$result = $wpdb->query( "ALTER TABLE {$table} DROP INDEX " . self::UNIQUE_INDEX_NAME );

		return array(
			'ok'      => false !== $result,
			'dropped' => false !== $result,
			'error'   => false === $result ? (string) $wpdb->last_error : '',
		);
	}

	public static function is_duplicate_key_error( string $last_error ): bool {
		$error = strtolower( trim( $last_error ) );
		if ( '' === $error ) {
			return false;
		}

		return false !== strpos( $error, 'duplicate entry' )
			|| false !== strpos( $error, self::UNIQUE_INDEX_NAME );
	}

	private static function get_licensee_column(): string {
		$table = Db::entries_table();
		if ( Db::has_table_column( $table, 'licensee_id' ) ) {
			return 'licensee_id';
		}
		if ( Db::has_table_column( $table, 'licence_id' ) ) {
			return 'licence_id';
		}

		return '';
	}

	private static function debug_log( string $message, array $context = array() ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		error_log( 'UFSC Competitions EntryDeduplication: ' . $message . ' ' . wp_json_encode( $context ) );
	}
}
