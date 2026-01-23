<?php

namespace UFSC\Competitions\Front\Exports;

use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Club_Entries_Export_Controller {

	public function register(): void {
		add_action( 'admin_post_ufsc_competitions_export_club_csv', array( $this, 'handle_export' ) );
		add_action( 'admin_post_nopriv_ufsc_competitions_export_club_csv', array( $this, 'handle_export' ) );
	}

	public function handle_export(): void {
		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;

		// Hard block: no external club_id override allowed (prevents IDOR attempts).
		if ( isset( $_GET['club_id'] ) ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		if ( ! $competition_id ) {
			$this->redirect_with_notice( 0, 'error_not_found' );
		}

		if ( ! is_user_logged_in() ) {
			$this->redirect_with_notice( $competition_id, 'error_forbidden' );
		}

		// Soft nonce check (redirect + notice instead of wp_die).
		if ( ! check_admin_referer( 'ufsc_competitions_export_club_csv_' . $competition_id, '_wpnonce', false ) ) {
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

		$entry_repo = new EntryFrontRepository();

		// Repo should already scope to competition + club; we still enforce ownership defensively below.
		$entries = $entry_repo->list_by_competition_and_club( $competition_id, $club_id );
		$entries = $this->filter_validated_entries( is_array( $entries ) ? $entries : array(), $entry_repo, $club_id );

		if ( empty( $entries ) ) {
			$this->redirect_with_notice( $competition_id, 'export_empty' );
		}

		do_action( 'ufsc_competitions_club_export_before', $competition, $club_id, $entries );

		$columns = $this->get_csv_columns();

		$filename = sprintf( 'club-entries-%d.csv', $competition_id );
		$filename = apply_filters( 'ufsc_competitions_club_export_filename', $filename, $competition, $club_id );
		$filename = sanitize_file_name( $filename );

		$this->prepare_export_headers();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$handle = fopen( 'php://output', 'w' );
		if ( ! $handle ) {
			$this->redirect_with_notice( $competition_id, 'error_export_unavailable' );
		}

		// UTF-8 BOM for Excel compatibility.
		fwrite( $handle, "\xEF\xBB\xBF" );

		$labels = array_map( array( $this, 'safe_csv_cell' ), wp_list_pluck( $columns, 'label' ) );
		fputcsv( $handle, $labels, ';' );

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
			'competition_id'   => (int) ( $competition->id ?? 0 ),
			'competition_name' => (string) ( $competition->name ?? '' ),
			'entry_id'         => (int) ( $entry->id ?? 0 ),
			'fighter_lastname' => (string) ( $entry->last_name ?? $entry->lastname ?? '' ),
			'fighter_firstname'=> (string) ( $entry->first_name ?? $entry->firstname ?? '' ),
			'birthdate'        => (string) ( $entry->birth_date ?? $entry->birthdate ?? '' ),
			'category'         => (string) ( $entry->category ?? $entry->category_name ?? '' ),
			'weight'           => (string) ( $entry->weight ?? $entry->weight_kg ?? '' ),
			'status'           => 'validated',
			'validated_at'     => (string) ( $entry->validated_at ?? '' ),
		);

		$row = apply_filters( 'ufsc_competitions_club_csv_row', $row, $entry, $competition, $club_id );

		$out = array();
		foreach ( $headers as $header ) {
			$key   = $header['key'] ?? '';
			$value = isset( $row[ $key ] ) ? $row[ $key ] : '';
			$out[] = $this->safe_csv_cell( $value );
		}

		return $out;
	}

	private function filter_validated_entries( array $entries, EntryFrontRepository $repo, int $club_id ): array {
		$filtered = array();

		foreach ( $entries as $entry ) {
			// Defensive ownership check even if repo already scopes.
			if ( absint( $entry->club_id ?? 0 ) !== $club_id ) {
				$this->redirect_with_notice( 0, 'error_forbidden' );
			}

			if ( 'validated' !== $repo->get_entry_status( $entry ) ) {
				continue;
			}

			$filtered[] = $entry;
		}

		return $filtered;
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
