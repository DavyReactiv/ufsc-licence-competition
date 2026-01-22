<?php

namespace UFSC\Competitions\Admin\Exports;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Export_Controller {
	public function register(): void {
		add_action( 'admin_post_ufsc_competitions_export_plateau_csv', array( $this, 'handle_csv_export' ) );
		add_action( 'admin_post_ufsc_competitions_download_plateau_pdf', array( $this, 'handle_pdf_download' ) );
	}

	public function handle_csv_export(): void {
		if ( ! Capabilities::user_can_validate_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_export_plateau_csv' );

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$competition_repo = new CompetitionRepository();
		$competition = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( '' !== $status && ! in_array( $status, array( 'draft', 'submitted', 'validated', 'rejected' ), true ) ) {
			$status = '';
		}

		do_action( 'ufsc_competitions_plateau_export_before', $competition, $status );

		$entries = $this->get_plateau_entries( $competition_id, $status );
		$headers = $this->get_csv_columns();

		$filename = sprintf( 'plateau-competition-%d.csv', $competition_id );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$handle = fopen( 'php://output', 'w' );
		if ( ! $handle ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		// UTF-8 BOM for Excel compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" );

		fputcsv( $handle, wp_list_pluck( $headers, 'label' ), ';' );

		foreach ( $entries as $entry ) {
			$row = $this->build_csv_row( $headers, $entry, $competition );
			fputcsv( $handle, $row, ';' );
		}

		fclose( $handle );
		exit;
	}

	public function handle_pdf_download(): void {
		if ( ! Capabilities::user_can_validate_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_download_plateau_pdf' );

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$competition_repo = new CompetitionRepository();
		$competition = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'plateau';
		if ( ! in_array( $mode, array( 'plateau', 'fiche' ), true ) ) {
			$mode = 'plateau';
		}

		do_action( 'ufsc_competitions_plateau_pdf_before', $competition, $mode );

		$renderer = new \UFSC\Competitions\Services\Plateau_Pdf_Renderer();
		$pdf = $renderer->render_pdf( $competition, $this->get_plateau_entries( $competition_id, '' ), $mode );
		if ( empty( $pdf ) ) {
			wp_die( esc_html__( 'PDF indisponible.', 'ufsc-licence-competition' ) );
		}

		$filename = sprintf( 'plateau-competition-%d.pdf', $competition_id );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
			'club_id' => array(
				'label' => __( 'club_id', 'ufsc-licence-competition' ),
				'key'   => 'club_id',
			),
			'club_name' => array(
				'label' => __( 'club_name', 'ufsc-licence-competition' ),
				'key'   => 'club_name',
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
			'discipline' => array(
				'label' => __( 'discipline', 'ufsc-licence-competition' ),
				'key'   => 'discipline',
			),
			'type' => array(
				'label' => __( 'type', 'ufsc-licence-competition' ),
				'key'   => 'type',
			),
			'status' => array(
				'label' => __( 'status', 'ufsc-licence-competition' ),
				'key'   => 'status',
			),
			'submitted_at' => array(
				'label' => __( 'submitted_at', 'ufsc-licence-competition' ),
				'key'   => 'submitted_at',
			),
			'validated_at' => array(
				'label' => __( 'validated_at', 'ufsc-licence-competition' ),
				'key'   => 'validated_at',
			),
			'rejected_reason' => array(
				'label' => __( 'rejected_reason', 'ufsc-licence-competition' ),
				'key'   => 'rejected_reason',
			),
		);

		$columns = apply_filters( 'ufsc_competitions_plateau_csv_columns', $columns );

		return is_array( $columns ) ? $columns : array();
	}

	private function get_plateau_entries( int $competition_id, string $status ): array {
		$filters = array(
			'view' => 'all',
			'competition_id' => $competition_id,
		);

		if ( '' !== $status ) {
			$filters['status'] = $status;
		}

		$filters = apply_filters( 'ufsc_competitions_plateau_entries_filters', $filters, $competition_id, $status );

		$repository = new EntryRepository();
		$entries = $repository->list( $filters, 2000, 0 );

		return is_array( $entries ) ? $entries : array();
	}

	private function build_csv_row( array $headers, $entry, $competition ): array {
		$row = array(
			'competition_id' => (int) ( $competition->id ?? 0 ),
			'competition_name' => (string) ( $competition->name ?? '' ),
			'club_id' => (int) ( $entry->club_id ?? 0 ),
			'club_name' => (string) ( $entry->club_name ?? '' ),
			'entry_id' => (int) ( $entry->id ?? 0 ),
			'fighter_lastname' => (string) ( $entry->last_name ?? $entry->lastname ?? '' ),
			'fighter_firstname' => (string) ( $entry->first_name ?? $entry->firstname ?? '' ),
			'birthdate' => (string) ( $entry->birth_date ?? $entry->birthdate ?? '' ),
			'category' => (string) ( $entry->category ?? $entry->category_name ?? '' ),
			'weight' => (string) ( $entry->weight ?? $entry->weight_kg ?? '' ),
			'discipline' => (string) ( $competition->discipline ?? '' ),
			'type' => (string) ( $competition->type ?? '' ),
			'status' => (string) ( $entry->status ?? '' ),
			'submitted_at' => (string) ( $entry->submitted_at ?? '' ),
			'validated_at' => (string) ( $entry->validated_at ?? '' ),
			'rejected_reason' => (string) ( $entry->rejected_reason ?? '' ),
		);

		$row = apply_filters( 'ufsc_competitions_plateau_csv_row', $row, $entry, $competition );

		$out = array();
		foreach ( $headers as $header ) {
			$key = $header['key'] ?? '';
			$out[] = isset( $row[ $key ] ) ? $row[ $key ] : '';
		}

		return $out;
	}
}
