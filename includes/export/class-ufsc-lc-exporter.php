<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Exporter {
	const CHUNK_SIZE = 500;

	public function stream_licences_csv( $filters ) {
		$filename = 'licences-export-' . gmdate( 'Ymd-His' ) . '.csv';
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$output = fopen( 'php://output', 'w' );
		if ( false === $output ) {
			wp_die( esc_html__( 'Export impossible.', 'ufsc-licence-competition' ) );
		}

		fwrite( $output, "\xEF\xBB\xBF" );
		fputcsv(
			$output,
			array(
				'club',
				'nom',
				'prenom',
				'dob',
				'statut',
				'categorie',
				'saison',
				'age_ref',
				'competition',
				'n_asptt',
				'date_asptt',
				'has_pdf',
			)
		);

		$list_table = new UFSC_LC_Competition_Licences_List_Table();
		$offset = 0;
		$limit  = self::CHUNK_SIZE;

		do {
			$rows = $list_table->get_export_rows_chunk( $filters, $limit, $offset );
			foreach ( $rows as $row ) {
				fputcsv(
					$output,
					array(
						$row['club_name'],
						$row['nom_licence'],
						$row['prenom'],
						$row['date_naissance'],
						$row['statut'],
						$row['category'],
						$row['season_end_year'],
						$row['age_ref'],
						$row['competition'],
						$row['asptt_number'],
						$row['date_asptt'],
						$row['has_pdf'],
					)
				);
			}

			$offset += $limit;
		} while ( ! empty( $rows ) );

		fclose( $output );
		exit;
	}
}
