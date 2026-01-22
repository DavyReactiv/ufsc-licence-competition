<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plateau_Pdf_Renderer {
	public function render_pdf( $competition, array $entries, string $mode ): string {
		$meta = array(
			__( 'Discipline', 'ufsc-licence-competition' ) => (string) ( $competition->discipline ?? '' ),
			__( 'Saison', 'ufsc-licence-competition' ) => (string) ( $competition->season ?? '' ),
			__( 'Type', 'ufsc-licence-competition' ) => (string) ( $competition->type ?? '' ),
		);

		$title = 'fiche' === $mode
			? __( 'Fiche compétition + plateau', 'ufsc-licence-competition' )
			: __( 'Contrôle plateau', 'ufsc-licence-competition' );

		$rows = $this->build_rows( $entries );

		$html = $this->build_html( $title, $competition, $meta, $rows, $mode );

		if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
			return '';
		}

		$dompdf = new \Dompdf\Dompdf();
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->loadHtml( $html );
		$dompdf->render();

		return $dompdf->output();
	}

	private function build_rows( array $entries ): array {
		$rows = array();
		foreach ( $entries as $entry ) {
			$rows[] = array(
				'last_name' => (string) ( $entry->last_name ?? $entry->lastname ?? '' ),
				'first_name' => (string) ( $entry->first_name ?? $entry->firstname ?? '' ),
				'birthdate' => (string) ( $entry->birth_date ?? $entry->birthdate ?? '' ),
				'category' => (string) ( $entry->category ?? $entry->category_name ?? '' ),
				'weight' => (string) ( $entry->weight ?? $entry->weight_kg ?? '' ),
				'club_id' => (string) ( $entry->club_id ?? '' ),
				'status' => (string) ( $entry->status ?? '' ),
			);
		}

		return $rows;
	}

	private function build_html( string $title, $competition, array $meta, array $rows, string $mode ): string {
		$styles = $this->get_styles();
		$header = $this->build_header_html( $title, $competition, $meta, $mode );
		$table = $this->build_table_html( $rows );

		return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>{$styles}</style>
</head>
<body>
{$header}
{$table}
</body>
</html>
HTML;
	}

	private function get_styles(): string {
		return '
			body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
			h1 { font-size: 16px; margin: 0 0 8px 0; }
			.meta { margin: 0 0 12px 0; padding: 0; list-style: none; }
			.meta li { margin: 0 0 2px 0; }
			table { width: 100%; border-collapse: collapse; }
			th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }
			th { background: #f5f5f5; }
		';
	}

	private function build_header_html( string $title, $competition, array $meta, string $mode ): string {
		$competition_name = esc_html( (string) ( $competition->name ?? '' ) );
		$title = esc_html( $title );

		$meta_items = '';
		foreach ( $meta as $label => $value ) {
			$meta_items .= '<li><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $value ) . '</li>';
		}

		$subtitle = '';
		if ( 'fiche' === $mode ) {
			$subtitle = '<p><strong>' . esc_html__( 'Compétition', 'ufsc-licence-competition' ) . ':</strong> ' . $competition_name . '</p>';
		}

		return sprintf(
			'<h1>%s</h1>%s<ul class="meta">%s</ul>',
			$title,
			$subtitle,
			$meta_items
		);
	}

	private function build_table_html( array $rows ): string {
		$thead = '<tr><th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Prénom', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Date de naissance', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Poids', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</th>'
			. '<th>' . esc_html__( 'Statut', 'ufsc-licence-competition' ) . '</th></tr>';

		$tbody = '';
		if ( ! $rows ) {
			$tbody = '<tr><td colspan="7">' . esc_html__( 'Aucune inscription.', 'ufsc-licence-competition' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$tbody .= '<tr>'
					. '<td>' . esc_html( $row['last_name'] ) . '</td>'
					. '<td>' . esc_html( $row['first_name'] ) . '</td>'
					. '<td>' . esc_html( $row['birthdate'] ) . '</td>'
					. '<td>' . esc_html( $row['category'] ) . '</td>'
					. '<td>' . esc_html( $row['weight'] ) . '</td>'
					. '<td>' . esc_html( $row['club_id'] ) . '</td>'
					. '<td>' . esc_html( $row['status'] ) . '</td>'
					. '</tr>';
			}
		}

		return '<table><thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table>';
	}
}
