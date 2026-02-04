<?php

namespace UFSC\Competitions\Front\Exports;

use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Exports\Engaged_Entries_Export_Helper;
use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

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

		add_action( 'admin_post_ufsc_competitions_export_engaged_csv', array( $this, 'handle_export' ) );
	}

	public function handle_export(): void {
		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$status         = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'approved';

		if ( ! in_array( $status, array( 'approved', 'submitted' ), true ) ) {
			$status = 'approved';
		}

		if ( ! $competition_id ) {
			$this->redirect_with_notice( 0, 'error_not_found' );
		}

		if ( ! is_user_logged_in() ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		$required_capability = class_exists( '\\UFSC_LC_Settings_Page' ) ? \UFSC_LC_Settings_Page::get_club_access_capability() : '';
		$required_capability = apply_filters( 'ufsc_competitions_front_entry_capability', $required_capability );
		if ( $required_capability && ! current_user_can( $required_capability ) ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		if ( ! check_admin_referer( 'ufsc_competitions_export_engaged_csv_' . $competition_id, '_wpnonce', false ) ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		$club_access = new ClubAccess();
		$club_id     = (int) $club_access->get_club_id_for_user( get_current_user_id() );
		if ( ! $club_id ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		$competition_repo = new CompetitionReadRepository();
		$competition      = $competition_repo->get( $competition_id );
		if ( ! $competition ) {
			$this->redirect_with_notice( $competition_id, 'error_not_found' );
		}

		$access = new CompetitionAccess();
		$view_result = $access->can_view_competition( $competition_id, $club_id, get_current_user_id() );
		if ( ! $view_result->allowed ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
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
			$this->redirect_with_notice( $competition_id, 'export_empty' );
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
			$this->redirect_with_notice( $competition_id, 'error_export_unavailable' );
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

	private function redirect_with_notice( int $competition_id, string $notice ): void {
		$url = $competition_id ? Front::get_competition_details_url( $competition_id ) : '';

		if ( ! $url ) {
			$url = wp_get_referer();
		}

		if ( ! $url ) {
			$url = home_url( '/' );
		}

		$url = add_query_arg( 'ufsc_notice', $notice, $url );
		$url .= '#ufsc-inscriptions';

		wp_safe_redirect( $url );
		exit;
	}
}
