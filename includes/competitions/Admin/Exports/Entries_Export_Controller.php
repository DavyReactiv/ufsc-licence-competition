<?php

namespace UFSC\Competitions\Admin\Exports;

use UFSC\Competitions\Admin\Menu;
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
		add_action( 'admin_post_ufsc_competitions_export_entries_pdf', array( $this, 'handle_entries_pdf_export' ) );
		add_action( 'admin_post_ufsc_competitions_download_entries_export', array( $this, 'handle_entries_export_download' ) );
	}

	public function handle_csv_export(): void {
		if ( ! Capabilities::user_can_export() ) {
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
		if ( method_exists( $competition_repo, 'assert_competition_in_scope' ) ) {
			$competition_repo->assert_competition_in_scope( $competition_id );
		}

		$filters = $this->get_requested_filters();
		do_action( 'ufsc_competitions_plateau_export_before', $competition, $filters['status'], $filters );

		$entries = $this->get_plateau_entries( $competition_id, $filters );
		$headers = $this->get_csv_columns();

		$filename = sprintf( 'plateau-competition-%d.csv', $competition_id );
		$filename = sanitize_file_name( $filename );

		$this->prepare_export_headers();
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

		$labels = array_map( array( $this, 'safe_csv_cell' ), wp_list_pluck( $headers, 'label' ) );
		fputcsv( $handle, $labels, ';' );

		foreach ( $entries as $entry ) {
			$row = $this->build_csv_row( $headers, $entry, $competition );
			fputcsv( $handle, $row, ';' );
		}

		fclose( $handle );
		exit;
	}

	public function handle_pdf_download(): void {
		if ( ! Capabilities::user_can_export() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			$this->redirect_with_notice( 'not_found' );
		}

		$competition_repo = new CompetitionRepository();
		$competition = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			$this->redirect_with_notice( 'not_found' );
		}
		if ( method_exists( $competition_repo, 'assert_competition_in_scope' ) ) {
			$competition_repo->assert_competition_in_scope( $competition_id );
		}

		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'plateau';
		if ( ! in_array( $mode, array( 'plateau', 'fiche', 'controle', 'fiche_complete' ), true ) ) {
			$mode = 'plateau';
		}

		$is_fiche     = in_array( $mode, array( 'fiche', 'fiche_complete' ), true );
		$nonce_action = $is_fiche ? 'ufsc_competitions_download_fiche_pdf' : 'ufsc_competitions_download_plateau_pdf';
		check_admin_referer( $nonce_action );

		do_action( 'ufsc_competitions_plateau_pdf_before', $competition, $mode );

		$filters = $this->get_requested_filters();
		$entries = $this->get_plateau_entries( $competition_id, $filters );

		if ( function_exists( 'ufsc_lc_comp_log' ) ) {
			ufsc_lc_comp_log(
				'pdf_export_request',
				array(
					'competition_id' => $competition_id,
					'mode' => $mode,
					'entries_count' => count( $entries ),
					'filters' => $filters,
				)
			);
		}

		if ( empty( $entries ) ) {
			if ( function_exists( 'ufsc_lc_comp_log' ) ) {
				ufsc_lc_comp_log(
					'pdf_export_empty',
					array(
						'competition_id' => $competition_id,
						'mode' => $mode,
					)
				);
			}
			$this->redirect_with_notice( 'pdf_no_entries' );
		}

		if ( ! class_exists( '\UFSC\Competitions\Services\Plateau_Pdf_Renderer' ) ) {
			if ( function_exists( 'ufsc_lc_comp_log' ) ) {
				ufsc_lc_comp_log(
					'pdf_export_missing_renderer',
					array(
						'competition_id' => $competition_id,
						'mode' => $mode,
					)
				);
			}
			$this->redirect_with_notice( 'pdf_generation_failed' );
		}

		$renderer = new \UFSC\Competitions\Services\Plateau_Pdf_Renderer();
		$pdf = $renderer->render_pdf( $competition, $entries, $mode );
		if ( empty( $pdf ) ) {
			if ( function_exists( 'ufsc_lc_comp_log' ) ) {
				ufsc_lc_comp_log(
					'pdf_export_failed',
					array(
						'competition_id' => $competition_id,
						'mode' => $mode,
						'entries_count' => count( $entries ),
						'pdf_length' => is_string( $pdf ) ? strlen( $pdf ) : 0,
					)
				);
			}
			$this->redirect_with_notice( 'pdf_generation_failed' );
		}

		if ( function_exists( 'ufsc_lc_comp_log' ) ) {
			ufsc_lc_comp_log(
				'pdf_export_ready',
				array(
					'competition_id' => $competition_id,
					'mode' => $mode,
					'entries_count' => count( $entries ),
					'pdf_length' => strlen( $pdf ),
				)
			);
		}

		$filename = sprintf( 'plateau-competition-%d.pdf', $competition_id );
		$filename = sanitize_file_name( $filename );

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$this->prepare_export_headers();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function redirect_with_notice( string $notice ): void {
		$referer = wp_get_referer();
		$url = $referer ? $referer : add_query_arg( 'page', Menu::MENU_SLUG, admin_url( 'admin.php' ) );
		$url = add_query_arg( 'ufsc_notice', sanitize_key( $notice ), $url );
		wp_safe_redirect( $url );
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
			'weight_class' => array(
				'label' => __( 'weight_class', 'ufsc-licence-competition' ),
				'key'   => 'weight_class',
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

	private function get_plateau_entries( int $competition_id, array $requested_filters ): array {
		$filters = array(
			'view' => 'all',
			'competition_id' => $competition_id,
		);

		if ( ! empty( $requested_filters['status'] ) ) {
			$filters['status'] = $requested_filters['status'];
		}

		$filters = apply_filters(
			'ufsc_competitions_plateau_entries_filters',
			$filters,
			$competition_id,
			$requested_filters['status'],
			$requested_filters['club_id'],
			$requested_filters['category']
		);

		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}

		$repository = new EntryRepository();
		$entries = $repository->list_with_details( $filters, 2000, 0 );

		$entries = is_array( $entries ) ? $entries : array();

		return $this->filter_entries( $entries, $requested_filters );
	}

	private function build_csv_row( array $headers, $entry, $competition ): array {
		$status = class_exists( '\\UFSC\\Competitions\\Entries\\EntriesWorkflow' )
			? \UFSC\Competitions\Entries\EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) )
			: (string) ( $entry->status ?? '' );

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
			'weight_class' => (string) ( $entry->weight_class ?? '' ),
			'discipline' => (string) ( $competition->discipline ?? '' ),
			'type' => (string) ( $competition->type ?? '' ),
			'status' => (string) $status,
			'submitted_at' => (string) ( $entry->submitted_at ?? '' ),
			'validated_at' => (string) ( $entry->validated_at ?? '' ),
			'rejected_reason' => (string) ( $entry->rejected_reason ?? '' ),
		);

		$row = apply_filters( 'ufsc_competitions_plateau_csv_row', $row, $entry, $competition );

		$out = array();
		foreach ( $headers as $header ) {
			$key = $header['key'] ?? '';
			$value = isset( $row[ $key ] ) ? $row[ $key ] : '';
			$out[] = $this->safe_csv_cell( $value );
		}

		return $out;
	}

	private function get_requested_filters(): array {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		if ( '' !== $status && ! in_array( $status, array( 'draft', 'submitted', 'pending', 'approved', 'rejected', 'cancelled' ), true ) ) {
			$status = '';
		}

		$club_id = isset( $_GET['club_id'] ) ? absint( $_GET['club_id'] ) : 0;
		$category = isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '';

		$filters = array(
			'status' => $status,
			'club_id' => $club_id,
			'category' => $category,
		);

		return $filters;
	}

	private function filter_entries( array $entries, array $filters ): array {
		if ( empty( $filters['club_id'] ) && '' === $filters['category'] ) {
			return $entries;
		}

		$club_id = absint( $filters['club_id'] );
		$category = strtolower( trim( (string) $filters['category'] ) );

		return array_values(
			array_filter(
				$entries,
				static function( $entry ) use ( $club_id, $category ) {
					if ( $club_id && absint( $entry->club_id ?? 0 ) !== $club_id ) {
						return false;
					}

					if ( '' !== $category ) {
						$entry_category = (string) ( $entry->category ?? $entry->category_name ?? '' );
						if ( '' === $entry_category ) {
							return false;
						}
						if ( strtolower( $entry_category ) !== $category ) {
							return false;
						}
					}

					if ( function_exists( 'ufsc_lc_is_entry_eligible' ) ) {
						$eligibility = ufsc_lc_is_entry_eligible( (int) ( $entry->id ?? 0 ), 'exports' );
						if ( empty( $eligibility['eligible'] ) ) {
							return false;
						}
					}

					return true;
				}
			)
		);
	}

	public function handle_entries_pdf_export(): void {
		if ( ! Capabilities::user_can_export() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'ufsc_competitions_export_entries_pdf' );

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		if ( ! $competition_id ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$competition_repo = new CompetitionRepository();
		$competition = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}
		if ( method_exists( $competition_repo, 'assert_competition_in_scope' ) ) {
			$competition_repo->assert_competition_in_scope( $competition_id );
		}

		$filters = $this->get_requested_filters();
		$entries = $this->get_plateau_entries( $competition_id, $filters );

		$renderer = new \UFSC\Competitions\Services\Entries_Pdf_Renderer();
		$pdf = $renderer->render_pdf( $competition, $entries );
		if ( empty( $pdf ) ) {
			wp_die( esc_html__( 'PDF indisponible.', 'ufsc-licence-competition' ) );
		}

		$export = $this->store_pdf_export( $competition_id, $pdf );
		if ( empty( $export['file'] ) ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		$this->send_pdf_file( $export['file'], $export['filename'], true );
		exit;
	}

	public function handle_entries_export_download(): void {
		if ( ! Capabilities::user_can_export() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$export_id = isset( $_GET['export_id'] ) ? sanitize_text_field( wp_unslash( $_GET['export_id'] ) ) : '';
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'inline';

		if ( ! $competition_id || '' === $export_id ) {
			wp_die( esc_html__( 'Export introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		check_admin_referer( 'ufsc_competitions_download_entries_export_' . $export_id );

		$competition_repo = new CompetitionRepository();
		if ( method_exists( $competition_repo, 'assert_competition_in_scope' ) ) {
			$competition_repo->assert_competition_in_scope( $competition_id );
		}

		$history = $this->get_exports_history( $competition_id );
		foreach ( $history as $export ) {
			if ( (string) ( $export['id'] ?? '' ) === $export_id ) {
				$file = $export['file'] ?? '';
				$filename = $export['filename'] ?? basename( $file );
				$this->send_pdf_file( $file, $filename, 'download' === $mode );
				exit;
			}
		}

		wp_die( esc_html__( 'Export introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
	}

	public function get_exports_history( int $competition_id ): array {
		$history = get_option( 'ufsc_competitions_entries_exports_' . $competition_id, array() );
		return is_array( $history ) ? $history : array();
	}

	private function store_pdf_export( int $competition_id, string $pdf ): array {
		$upload = wp_upload_dir();
		if ( empty( $upload['basedir'] ) ) {
			return array();
		}

		$timestamp = current_time( 'timestamp' );
		$dir = trailingslashit( $upload['basedir'] ) . 'ufsc-exports/competitions/' . $competition_id;
		wp_mkdir_p( $dir );

		$filename = sprintf( 'inscriptions-%d-%s.pdf', $competition_id, date_i18n( 'Ymd-His', $timestamp ) );
		$file = trailingslashit( $dir ) . $filename;

		if ( false === file_put_contents( $file, $pdf ) ) {
			return array();
		}

		$history = $this->get_exports_history( $competition_id );
		$export_id = uniqid( 'export_', true );
		$history[] = array(
			'id' => $export_id,
			'file' => $file,
			'filename' => $filename,
			'generated_at' => current_time( 'mysql' ),
			'generated_by' => get_current_user_id(),
		);

		update_option( 'ufsc_competitions_entries_exports_' . $competition_id, $history, false );

		return array(
			'id' => $export_id,
			'file' => $file,
			'filename' => $filename,
		);
	}

	private function send_pdf_file( string $file, string $filename, bool $download ): void {
		if ( ! $file || ! file_exists( $file ) ) {
			wp_die( esc_html__( 'Fichier introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$this->prepare_export_headers();
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function prepare_export_headers(): void {
		if ( headers_sent() ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	private function safe_csv_cell( $value ): string {
		if ( is_bool( $value ) ) {
			$value = $value ? '1' : '0';
		}

		if ( is_null( $value ) ) {
			return '';
		}

		$value = (string) $value;

		if ( $value !== '' && preg_match( '/^[=\\+\\-@\\t]/', $value ) ) {
			$value = "'" . $value;
		}

		return $value;
	}
}
