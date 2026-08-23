<?php

namespace UFSC\Competitions\Services;

use UFSC\Competitions\Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Builds a strictly whitelisted, read-only public snapshot for competition live pages. */
class LiveCompetitionService {
	private const CACHE_TTL = 5;

	public static function is_enabled( int $competition_id ): bool {
		$meta = CompetitionMeta::get( $competition_id );
		return ! empty( $meta['live_enabled'] );
	}

	public static function get_snapshot( int $competition_id, string $surface = '' ): array {
		$competition_id = absint( $competition_id );
		$surface        = sanitize_text_field( $surface );
		if ( $competition_id <= 0 || ! self::is_enabled( $competition_id ) ) {
			return array();
		}

		$cache_key = 'ufsc_live_' . $competition_id . '_' . md5( strtolower( $surface ) );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$competition = self::get_competition( $competition_id );
		if ( ! $competition ) {
			return array();
		}

		$fights = self::get_fights( $competition_id );
		$entry_ids = array();
		$category_ids = array();
		foreach ( $fights as $fight ) {
			$entry_ids[]    = absint( $fight->red_entry_id ?? 0 );
			$entry_ids[]    = absint( $fight->blue_entry_id ?? 0 );
			$category_ids[] = absint( $fight->category_id ?? 0 );
		}

		$entries    = self::get_rows_by_ids( Db::entries_table(), $entry_ids );
		$categories = self::get_rows_by_ids( Db::categories_table(), $category_ids );
		$surfaces   = array();

		foreach ( $fights as $fight ) {
			$surface_name = self::resolve_surface( $fight );
			if ( '' !== $surface && 0 !== strcasecmp( $surface, $surface_name ) ) {
				continue;
			}

			if ( ! isset( $surfaces[ $surface_name ] ) ) {
				$surfaces[ $surface_name ] = array(
					'name'     => $surface_name,
					'current'  => null,
					'upcoming' => array(),
					'recent'   => array(),
				);
			}

			$item   = self::serialize_fight( $fight, $entries, $categories );
			$status = (string) $item['status'];
			if ( 'running' === $status ) {
				if ( null === $surfaces[ $surface_name ]['current'] ) {
					$surfaces[ $surface_name ]['current'] = $item;
				}
				continue;
			}
			if ( in_array( $status, array( 'scheduled', 'called', 'delayed' ), true ) ) {
				$surfaces[ $surface_name ]['upcoming'][] = $item;
				continue;
			}
			if ( in_array( $status, array( 'completed', 'bye', 'locked' ), true ) ) {
				$surfaces[ $surface_name ]['recent'][] = $item;
			}
		}

		foreach ( $surfaces as &$surface_data ) {
			$surface_data['upcoming'] = array_slice( $surface_data['upcoming'], 0, 5 );
			$surface_data['recent']   = array_slice( array_reverse( $surface_data['recent'] ), 0, 5 );
		}
		unset( $surface_data );

		$meta = CompetitionMeta::get( $competition_id );
		$snapshot = array(
			'competition' => array(
				'id'         => $competition_id,
				'name'       => sanitize_text_field( (string) ( $competition->name ?? '' ) ),
				'discipline' => sanitize_text_field( (string) ( $competition->discipline ?? '' ) ),
				'type'       => sanitize_text_field( (string) ( $competition->type ?? '' ) ),
				'start'      => sanitize_text_field( (string) ( $competition->event_start_datetime ?? '' ) ),
				'location'   => sanitize_text_field( (string) ( $meta['lieu_name'] ?? '' ) ),
			),
			'surfaces'    => array_values( $surfaces ),
			'generatedAt' => gmdate( 'c' ),
			'refresh'     => 10,
		);

		set_transient( $cache_key, $snapshot, self::CACHE_TTL );
		return $snapshot;
	}

	private static function get_competition( int $competition_id ) {
		global $wpdb;
		$table = Db::competitions_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $competition_id ) );
	}

	private static function get_fights( int $competition_id ): array {
		global $wpdb;
		$table   = Db::fights_table();
		$columns = Db::get_table_columns( $table );
		$where   = in_array( 'deleted_at', $columns, true ) ? " AND (deleted_at IS NULL OR deleted_at = '')" : '';
		$order   = self::first_existing_column( $columns, array( 'scheduled_order', 'surface_order', 'fight_no', 'id' ) );
		$sql     = $wpdb->prepare( "SELECT * FROM {$table} WHERE competition_id = %d{$where} ORDER BY {$order} ASC, id ASC", $competition_id );
		$rows    = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	private static function get_rows_by_ids( string $table, array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( ! $ids || ! Db::table_exists( $table ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql          = $wpdb->prepare( "SELECT * FROM {$table} WHERE id IN ({$placeholders})", $ids );
		$rows         = $wpdb->get_results( $sql );
		$indexed      = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$indexed[ absint( $row->id ?? 0 ) ] = $row;
		}
		return $indexed;
	}

	private static function serialize_fight( $fight, array $entries, array $categories ): array {
		$red_id       = absint( $fight->red_entry_id ?? 0 );
		$blue_id      = absint( $fight->blue_entry_id ?? 0 );
		$winner_id    = absint( $fight->winner_entry_id ?? 0 );
		$category_id  = absint( $fight->category_id ?? 0 );
		$status       = self::normalize_status( (string) ( $fight->status ?? '' ), $fight );
		$result       = FightResultPersistence::get_method( $fight );

		return array(
			'id'       => absint( $fight->id ?? 0 ),
			'number'   => absint( $fight->fight_no ?? 0 ),
			'status'   => $status,
			'label'    => self::status_label( $status ),
			'category' => self::category_label( $categories[ $category_id ] ?? null, $category_id ),
			'red'      => self::participant( $entries[ $red_id ] ?? null, $red_id, $winner_id === $red_id ),
			'blue'     => self::participant( $entries[ $blue_id ] ?? null, $blue_id, $winner_id === $blue_id ),
			'result'   => sanitize_text_field( (string) $result ),
		);
	}

	private static function participant( $entry, int $entry_id, bool $winner ): array {
		$first = self::first_value( $entry, array( 'licensee_first_name', 'first_name', 'firstname', 'prenom' ) );
		$last  = self::first_value( $entry, array( 'licensee_last_name', 'last_name', 'lastname', 'nom' ) );
		$name  = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			$name = $entry_id > 0 ? sprintf( __( 'Participant #%d', 'ufsc-licence-competition' ), $entry_id ) : __( 'À déterminer', 'ufsc-licence-competition' );
		}
		$club = self::first_value( $entry, array( 'club_nom', 'club_name', 'structure_name' ) );

		return array(
			'id'     => $entry_id,
			'name'   => sanitize_text_field( $name ),
			'club'   => sanitize_text_field( $club ),
			'winner' => $winner,
		);
	}

	private static function category_label( $category, int $category_id ): string {
		$label = self::first_value( $category, array( 'name', 'label', 'category_name', 'title' ) );
		return '' !== $label ? sanitize_text_field( $label ) : ( $category_id > 0 ? sprintf( __( 'Catégorie #%d', 'ufsc-licence-competition' ), $category_id ) : '' );
	}

	private static function first_value( $row, array $keys ): string {
		if ( ! is_object( $row ) ) {
			return '';
		}
		foreach ( $keys as $key ) {
			if ( isset( $row->{$key} ) && '' !== trim( (string) $row->{$key} ) ) {
				return trim( (string) $row->{$key} );
			}
		}
		return '';
	}

	private static function resolve_surface( $fight ): string {
		foreach ( array( 'surface_name', 'surface', 'ring', 'tatami' ) as $key ) {
			$value = trim( (string) ( $fight->{$key} ?? '' ) );
			if ( '' !== $value ) {
				return sanitize_text_field( $value );
			}
		}
		$number = absint( $fight->surface_no ?? 0 );
		return $number > 0 ? sprintf( __( 'Surface %d', 'ufsc-licence-competition' ), $number ) : __( 'Surface principale', 'ufsc-licence-competition' );
	}

	private static function normalize_status( string $status, $fight ): string {
		$status = sanitize_key( strtolower( trim( $status ) ) );
		$aliases = array( 'draft' => 'scheduled', 'planned' => 'scheduled', 'planifie' => 'scheduled', 'planifiee' => 'scheduled', 'en_cours' => 'running', 'termine' => 'completed', 'finished' => 'completed' );
		$status = $aliases[ $status ] ?? $status;
		if ( 'completed' !== $status && FightResultPersistence::has_result_payload( $fight ) ) {
			return 'completed';
		}
		return in_array( $status, array( 'scheduled', 'called', 'running', 'completed', 'bye', 'delayed', 'cancelled', 'locked' ), true ) ? $status : 'scheduled';
	}

	private static function status_label( string $status ): string {
		$labels = array(
			'scheduled' => __( 'Prévu', 'ufsc-licence-competition' ),
			'called'    => __( 'Appelé', 'ufsc-licence-competition' ),
			'running'   => __( 'En cours', 'ufsc-licence-competition' ),
			'completed' => __( 'Terminé', 'ufsc-licence-competition' ),
			'bye'       => __( 'BYE', 'ufsc-licence-competition' ),
			'delayed'   => __( 'Retardé', 'ufsc-licence-competition' ),
			'cancelled' => __( 'Annulé', 'ufsc-licence-competition' ),
			'locked'    => __( 'Verrouillé', 'ufsc-licence-competition' ),
		);
		return $labels[ $status ] ?? __( 'Prévu', 'ufsc-licence-competition' );
	}

	private static function first_existing_column( array $columns, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $candidate, $columns, true ) ) {
				return $candidate;
			}
		return 'id';
	}
}
