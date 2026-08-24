<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backward-compatible result persistence for historical fights schemas.
 *
 * Some installations contain result_method, others result_type, and some may
 * contain both. Writes are restricted to columns that actually exist.
 */
class FightResultPersistence {
	public static function get_method( $fight ): string {
		if ( is_array( $fight ) ) {
			$value = $fight['result_method'] ?? $fight['result_type'] ?? '';
		} elseif ( is_object( $fight ) ) {
			$value = $fight->result_method ?? $fight->result_type ?? '';
		} else {
			$value = '';
		}

		return sanitize_key( (string) $value );
	}

	public static function normalize( $fight ) {
		$method = self::get_method( $fight );
		if ( is_object( $fight ) ) {
			$fight->result_method = $method;
			$fight->result_type   = $method;
		} elseif ( is_array( $fight ) ) {
			$fight['result_method'] = $method;
			$fight['result_type']   = $method;
		}

		return $fight;
	}

	/**
	 * Detect whether a fight already contains result data.
	 *
	 * Historical rows may store the method in result_type instead of
	 * result_method, including no-contest style results without a winner.
	 */
	public static function has_result_payload( $fight ): bool {
		if ( ! is_array( $fight ) && ! is_object( $fight ) ) {
			return false;
		}

		$winner_entry_id = absint( is_array( $fight ) ? ( $fight['winner_entry_id'] ?? 0 ) : ( $fight->winner_entry_id ?? 0 ) );
		if ( $winner_entry_id > 0 || '' !== self::get_method( $fight ) ) {
			return true;
		}

		$score_red  = trim( (string) ( is_array( $fight ) ? ( $fight['score_red'] ?? '' ) : ( $fight->score_red ?? '' ) ) );
		$score_blue = trim( (string) ( is_array( $fight ) ? ( $fight['score_blue'] ?? '' ) : ( $fight->score_blue ?? '' ) ) );

		return '' !== $score_red || '' !== $score_blue;
	}

	/**
	 * Persist a complete result update using only columns present in the table.
	 */
	public static function update_result( int $fight_id, array $data ) {
		global $wpdb;

		$fight_id = absint( $fight_id );
		$table    = Db::fights_table();
		if ( ! $fight_id || ! Db::table_exists( $table ) ) {
			return false;
		}

		$columns = Db::get_table_columns( $table );
		if ( ! is_array( $columns ) || empty( $columns ) ) {
			return false;
		}

		$payload = array();
		$formats = array();

		self::add_if_column( $payload, $formats, $columns, 'winner_entry_id', absint( $data['winner_entry_id'] ?? 0 ), '%d' );
		self::add_if_column( $payload, $formats, $columns, 'status', sanitize_key( (string) ( $data['status'] ?? 'completed' ) ), '%s' );
		self::add_if_column( $payload, $formats, $columns, 'score_red', sanitize_text_field( (string) ( $data['score_red'] ?? '' ) ), '%s' );
		self::add_if_column( $payload, $formats, $columns, 'score_blue', sanitize_text_field( (string) ( $data['score_blue'] ?? '' ) ), '%s' );

		$method = sanitize_key( (string) ( $data['result_method'] ?? $data['result_type'] ?? '' ) );
		self::add_if_column( $payload, $formats, $columns, 'result_method', $method, '%s' );
		self::add_if_column( $payload, $formats, $columns, 'result_type', $method, '%s' );
		self::add_if_column( $payload, $formats, $columns, 'result_note', sanitize_textarea_field( (string) ( $data['result_note'] ?? '' ) ), '%s' );
		self::add_if_column( $payload, $formats, $columns, 'completed_at', sanitize_text_field( (string) ( $data['completed_at'] ?? current_time( 'mysql' ) ) ), '%s' );
		self::add_if_column( $payload, $formats, $columns, 'updated_at', current_time( 'mysql' ), '%s' );
		self::add_if_column( $payload, $formats, $columns, 'updated_by', absint( get_current_user_id() ), '%d' );

		if ( empty( $payload ) ) {
			return false;
		}

		return $wpdb->update(
			$table,
			$payload,
			array( 'id' => $fight_id ),
			$formats,
			array( '%d' )
		);
	}

	/** Update only a fight status without passing through legacy result fields. */
	public static function update_status( int $fight_id, string $status ) {
		global $wpdb;

		$fight_id = absint( $fight_id );
		$table    = Db::fights_table();
		if ( ! $fight_id || ! Db::table_exists( $table ) || ! Db::has_table_column( $table, 'status' ) ) {
			return false;
		}

		$columns = Db::get_table_columns( $table );
		$payload = array( 'status' => sanitize_key( $status ) );
		$formats = array( '%s' );
		self::add_if_column( $payload, $formats, $columns, 'updated_at', current_time( 'mysql' ), '%s' );
		self::add_if_column( $payload, $formats, $columns, 'updated_by', absint( get_current_user_id() ), '%d' );

		return $wpdb->update(
			$table,
			$payload,
			array( 'id' => $fight_id ),
			$formats,
			array( '%d' )
		);
	}

	private static function add_if_column( array &$payload, array &$formats, array $columns, string $column, $value, string $format ): void {
		if ( ! in_array( $column, $columns, true ) || array_key_exists( $column, $payload ) ) {
			return;
		}

		$payload[ $column ] = $value;
		$formats[]          = $format;
	}
}
