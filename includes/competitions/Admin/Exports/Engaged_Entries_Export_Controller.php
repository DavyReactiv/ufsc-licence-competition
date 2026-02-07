<?php

namespace UFSC\Competitions\Admin\Exports;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Exports\Engaged_Entries_Export_Helper;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Engaged_Entries_Export_Controller {

	public function register(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'admin_post_ufsc_competitions_export_engaged_csv_admin', array( $this, 'handle_export' ) );
	}

	public function handle_export(): void {
		if ( ! Capabilities::user_can_export() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$status         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'approved';

		if ( ! in_array( $status, array( 'approved', 'submitted' ), true ) ) {
			$status = 'approved';
		}

		check_admin_referer( 'ufsc_competitions_export_engaged_csv_admin_' . $competition_id );

		if ( ! $competition_id ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}

		$competition_repo = new CompetitionRepository();
		$competition      = $competition_repo->get( $competition_id, true );
		if ( ! $competition ) {
			wp_die( esc_html__( 'Compétition introuvable.', 'ufsc-licence-competition' ), '', array( 'response' => 404 ) );
		}
		if ( method_exists( $competition_repo, 'assert_competition_in_scope' ) ) {
			$competition_repo->assert_competition_in_scope( $competition_id );
		}

		$entry_repo = new EntryFrontRepository();
		$entries    = $entry_repo->list_by_competition(
			$competition_id,
			array(
				'status' => $status,
			),
			0,
			0
		);

		if ( empty( $entries ) ) {
			wp_die( esc_html__( 'Aucun engagé à exporter.', 'ufsc-licence-competition' ) );
		}

		$columns = Engaged_Entries_Export_Helper::get_csv_columns();
		$filename = sprintf( 'engages-%d-%s.csv', $competition_id, $status );
		$filename = sanitize_file_name( $filename );

		Engaged_Entries_Export_Helper::prepare_export_headers();
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
		$labels = array_map( array( Engaged_Entries_Export_Helper::class, 'safe_csv_cell' ), wp_list_pluck( $columns, 'label' ) );
		fputcsv( $handle, $labels, ';' );

		foreach ( $entries as $entry ) {
			$row = Engaged_Entries_Export_Helper::build_csv_row( $columns, $entry, $competition );
			fputcsv( $handle, $row, ';' );
		}

		fclose( $handle );
		exit;
	}
}
