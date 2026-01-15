<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Exporter {
	const CHUNK_SIZE = 500;

	public function stream_licences_csv( $filters ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}

		ignore_user_abort( true );

		$filename = 'licences-export-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		if ( function_exists( 'wp_ob_end_flush_all' ) ) {
			wp_ob_end_flush_all();
		}

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		$columns = $this->get_export_columns( $filters );

		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv( $output, wp_list_pluck( $columns, 'label' ) );

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$offset = 0;
		$limit  = self::CHUNK_SIZE;

		do {
			$rows = $list_table->get_export_rows_chunk( $filters, $limit, $offset );
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $columns as $column ) {
					$line[] = isset( $row[ $column['key'] ] ) ? $row[ $column['key'] ] : '';
				}
				fputcsv( $output, $line );
			}

			$offset += $limit;

			if ( function_exists( 'fflush' ) ) {
				fflush( $output );
			}
			if ( function_exists( 'flush' ) ) {
				flush();
			}
		} while ( ! empty( $rows ) );

		fclose( $output );
		exit;
	}

	private function get_export_columns( $filters ) {
		$available = array(
			'club'        => array( 'label' => 'club', 'key' => 'club_name' ),
			'nom'         => array( 'label' => 'nom', 'key' => 'nom_licence' ),
			'prenom'      => array( 'label' => 'prenom', 'key' => 'prenom' ),
			'dob'         => array( 'label' => 'dob', 'key' => 'date_naissance' ),
			'statut'      => array( 'label' => 'statut', 'key' => 'statut' ),
			'categorie'   => array( 'label' => 'categorie', 'key' => 'categorie' ),
			'competition' => array( 'label' => 'competition', 'key' => 'competition' ),
			'n_asptt'     => array( 'label' => 'n_asptt', 'key' => 'asptt_number' ),
			'date_asptt'  => array( 'label' => 'date_asptt', 'key' => 'date_asptt' ),
			'has_pdf'     => array( 'label' => 'has_pdf', 'key' => 'has_pdf' ),
		);

		$requested = isset( $filters['export_columns'] ) ? (array) $filters['export_columns'] : array();
		$requested = array_values( array_intersect( $requested, array_keys( $available ) ) );
		if ( empty( $requested ) ) {
			return array_values( $available );
		}

		$columns = array();
		foreach ( $requested as $column_key ) {
			$columns[] = $available[ $column_key ];
		}

		return $columns;
	}
}
