<?php

namespace UFSC\Competitions\Front\Exports;

use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Club_Entries_Export_Controller {
	public function register(): void {
		add_action( 'admin_post_ufsc_competitions_export_club_csv', array( $this, 'handle_export' ) );
	}

	public function handle_export(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		check_admin_referer( 'ufsc_competitions_export_club_csv_' . $competition_id );

		$club_access = new ClubAccess();
		$club_id = $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			wp_die( esc_html__( 'Accès réservé aux clubs affiliés.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_repo = new CompetitionReadRepository();
		$competition = $competition_repo->get( $competition_id );
		if ( ! $competition ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$entry_repo = new EntryFrontRepository();
		$entries = $entry_repo->list_by_competition_and_club( $competition_id, (int) $club_id );
		$entries = $this->filter_validated_entries( $entries, $entry_repo );

		do_action( 'ufsc_competitions_club_export_before', $competition, $club_id, $entries );

		$columns = $this->get_csv_columns();

		$filename = sprintf( 'club-entries-%d.csv', $competition_id );
		$filename = apply_filters( 'ufsc_competitions_club_export_filename', $filename, $competition, $club_id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$handle = fopen( 'php://output', 'w' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		fwrite( $handle, "\xEF\xBB\xBF" );

		fputcsv( $handle, wp_list_pluck( $columns, 'label' ), ';' );

		foreach ( $entries as $entry ) {
			$row = $this->build_csv_row( $columns, $entry, $competition, $club_id );
			fputcsv( $handle, $row, ';' );
		}

		fclose( $handle );
		exit;
	}

	private function get_csv_columns(): array {
		$columns = array(
			'competition_id' => array(
				'label' => __( 'competition_id', 'ufsc-licence-competition' ),
				'key'   => 'competition_id',
			),
			'competition_name' => array(
				'label' => __( 'competition_name', 'ufsc-licence-competition' ),
				'key'   => 'competition_name',
			),
			'entry_id' => array(
				'label' => __( 'entry_id', 'ufsc-licence-competition' ),
				'key'   => 'entry_id',
			),
			'fighter_lastname' => array(
				'label' => __( 'fighter_lastname', 'ufsc-licence-competition' ),
				'key'   => 'fighter_lastname',
			),
			'fighter_firstname' => array(
				'label' => __( 'fighter_firstname', 'ufsc-licence-competition' ),
				'key'   => 'fighter_firstname',
			),
			'birthdate' => array(
				'label' => __( 'birthdate', 'ufsc-licence-competition' ),
				'key'   => 'birthdate',
			),
			'category' => array(
				'label' => __( 'category', 'ufsc-licence-competition' ),
				'key'   => 'category',
			),
			'weight' => array(
				'label' => __( 'weight', 'ufsc-licence-competition' ),
				'key'   => 'weight',
			),
			'status' => array(
				'label' => __( 'status', 'ufsc-licence-competition' ),
				'key'   => 'status',
			),
			'validated_at' => array(
				'label' => __( 'validated_at', 'ufsc-licence-competition' ),
				'key'   => 'validated_at',
			),
		);

		$columns = apply_filters( 'ufsc_competitions_club_csv_columns', $columns );

		return is_array( $columns ) ? $columns : array();
	}

	private function build_csv_row( array $headers, $entry, $competition, int $club_id ): array {
		$row = array(
			'competition_id' => (int) ( $competition->id ?? 0 ),
			'competition_name' => (string) ( $competition->name ?? '' ),
			'entry_id' => (int) ( $entry->id ?? 0 ),
			'fighter_lastname' => (string) ( $entry->last_name ?? $entry->lastname ?? '' ),
			'fighter_firstname' => (string) ( $entry->first_name ?? $entry->firstname ?? '' ),
			'birthdate' => (string) ( $entry->birth_date ?? $entry->birthdate ?? '' ),
			'category' => (string) ( $entry->category ?? $entry->category_name ?? '' ),
			'weight' => (string) ( $entry->weight ?? $entry->weight_kg ?? '' ),
			'status' => 'validated',
			'validated_at' => (string) ( $entry->validated_at ?? '' ),
		);

		$row = apply_filters( 'ufsc_competitions_club_csv_row', $row, $entry, $competition, $club_id );

		$out = array();
		foreach ( $headers as $header ) {
			$key = $header['key'] ?? '';
			$out[] = isset( $row[ $key ] ) ? $row[ $key ] : '';
		}

		return $out;
	}

	private function filter_validated_entries( array $entries, EntryFrontRepository $repo ): array {
		return array_values(
			array_filter(
				$entries,
				static function( $entry ) use ( $repo ) {
					return 'validated' === $repo->get_entry_status( $entry );
				}
			)
		);
	}
}
