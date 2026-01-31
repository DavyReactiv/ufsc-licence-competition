<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plateau_Pdf_Renderer {

	public function render_pdf( $competition, array $entries, string $mode ): string {
		$mode = $this->normalize_mode( $mode );

		$meta = array(
			__( 'Discipline', 'ufsc-licence-competition' ) => (string) ( $competition->discipline ?? '' ),
			__( 'Saison', 'ufsc-licence-competition' )     => (string) ( $competition->season ?? '' ),
			__( 'Type', 'ufsc-licence-competition' )       => (string) ( $competition->type ?? '' ),
		);

		if ( 'fiche_complete' === $mode ) {
			$meta = array_merge( $meta, $this->get_fiche_complete_meta( $competition ) );
		}

		$meta = apply_filters( 'ufsc_competitions_plateau_pdf_meta', $meta, $competition, $mode );

		$title = 'fiche' === $mode
			? __( 'Fiche compétition + plateau', 'ufsc-licence-competition' )
			: ( 'fiche_complete' === $mode
				? __( 'Fiche compétition complète', 'ufsc-licence-competition' )
				: ( 'controle' === $mode
					? __( 'Contrôle plateau (compact)', 'ufsc-licence-competition' )
					: __( 'Contrôle plateau', 'ufsc-licence-competition' )
				)
			);

		$rows = $this->build_rows( $entries );

		$html = $this->build_html( $title, $competition, $meta, $rows, $mode );
		$html = apply_filters( 'ufsc_competitions_plateau_pdf_html', $html, $competition, $entries, $mode );

		if ( ! class_exists( 'Dompdf\\Dompdf' ) ) {
			$fallback = apply_filters( 'ufsc_competitions_plateau_pdf_fallback', '', $competition, $entries, $mode, $html );
			return is_string( $fallback ) ? $fallback : '';
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
				'last_name'  => (string) ( $entry->last_name ?? $entry->lastname ?? '' ),
				'first_name' => (string) ( $entry->first_name ?? $entry->firstname ?? '' ),
				'birthdate'  => (string) ( $entry->birth_date ?? $entry->birthdate ?? '' ),
				'category'   => (string) ( $entry->category ?? $entry->category_name ?? '' ),
				'weight'     => (string) ( $entry->weight ?? $entry->weight_kg ?? '' ),
				'weight_class' => (string) ( $entry->weight_class ?? '' ),
				'club_id'    => (string) ( $entry->club_id ?? '' ),
				'status'     => (string) ( $entry->status ?? '' ),
			);
		}

		return $rows;
	}

	private function build_html( string $title, $competition, array $meta, array $rows, string $mode ): string {
		$styles = $this->get_styles();
		$header = $this->build_header_html( $title, $competition, $meta, $mode );
		$table  = $this->build_table_html( $rows, $competition, $mode );
		$footer = $this->build_footer_html( $competition, $mode );

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
{$footer}
</body>
</html>
HTML;
	}

	private function get_styles(): string {
		return '
			body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
			h1 { font-size: 16px; margin: 0 0 8px 0; }
			.ufsc-pdf-header { display: flex; align-items: flex-start; gap: 12px; margin: 0 0 12px 0; }
			.ufsc-pdf-logo { width: 90px; height: auto; }
			.ufsc-pdf-header-content { flex: 1; }
			.meta { margin: 0 0 12px 0; padding: 0; list-style: none; }
			.meta li { margin: 0 0 2px 0; }
			.ufsc-pdf-footer { margin-top: 16px; font-size: 9px; color: #555; }
			table { width: 100%; border-collapse: collapse; }
			th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }
			th { background: #f5f5f5; }
		';
	}

	private function build_header_html( string $title, $competition, array $meta, string $mode ): string {
		$competition_name = esc_html( (string) ( $competition->name ?? '' ) );
		$title            = esc_html( $title );

		$logo_html  = $this->build_logo_html( $competition, $mode );
		$meta_items = '';

		foreach ( $meta as $label => $value ) {
			$meta_items .= '<li><strong>' . esc_html( $label ) . '</strong> ' . esc_html( $value ) . '</li>';
		}

		$subtitle = '';
		if ( in_array( $mode, array( 'fiche', 'fiche_complete' ), true ) ) {
			$subtitle = '<p><strong>' . esc_html__( 'Compétition', 'ufsc-licence-competition' ) . ':</strong> ' . $competition_name . '</p>';
		}

		$default_header = sprintf(
			'<div class="ufsc-pdf-header">%s<div class="ufsc-pdf-header-content"><h1>%s</h1>%s<ul class="meta">%s</ul></div></div>',
			$logo_html ? $logo_html : '',
			$title,
			$subtitle,
			$meta_items
		);

		$filtered = apply_filters( 'ufsc_competitions_plateau_pdf_header_html', '', $competition, $mode, $title, $meta );
		if ( is_string( $filtered ) && '' !== $filtered ) {
			// Safety: sanitize custom HTML.
			return wp_kses_post( $filtered );
		}

		return $default_header;
	}

	private function build_table_html( array $rows, $competition, string $mode ): string {
		$columns = array(
			'last_name'  => esc_html__( 'Nom', 'ufsc-licence-competition' ),
			'first_name' => esc_html__( 'Prénom', 'ufsc-licence-competition' ),
			'birthdate'  => esc_html__( 'Date de naissance', 'ufsc-licence-competition' ),
			'category'   => esc_html__( 'Catégorie', 'ufsc-licence-competition' ),
			'weight'     => esc_html__( 'Poids', 'ufsc-licence-competition' ),
			'weight_class' => esc_html__( 'Catégorie poids', 'ufsc-licence-competition' ),
			'club_id'    => esc_html__( 'Club', 'ufsc-licence-competition' ),
			'status'     => esc_html__( 'Statut', 'ufsc-licence-competition' ),
		);

		if ( 'controle' === $mode ) {
			unset( $columns['status'] );
		}

		$columns = apply_filters( 'ufsc_competitions_plateau_pdf_columns', $columns, $competition, $mode );

		$thead = '<tr>';
		foreach ( $columns as $label ) {
			$thead .= '<th>' . esc_html( $label ) . '</th>';
		}
		$thead .= '</tr>';

		$tbody    = '';
		$colspan  = max( 1, count( $columns ) );

		if ( empty( $rows ) ) {
			$tbody = '<tr><td colspan="' . (int) $colspan . '">' . esc_html__( 'Aucune inscription.', 'ufsc-licence-competition' ) . '</td></tr>';
		} else {
			foreach ( $rows as $row ) {
				$tbody .= '<tr>';
				foreach ( array_keys( $columns ) as $key ) {
					$tbody .= '<td>' . esc_html( $row[ $key ] ?? '' ) . '</td>';
				}
				$tbody .= '</tr>';
			}
		}

		return '<table><thead>' . $thead . '</thead><tbody>' . $tbody . '</tbody></table>';
	}

	private function build_logo_html( $competition, string $mode ): string {
		$logo = apply_filters( 'ufsc_competitions_plateau_pdf_logo', '', $competition, $mode );

		if ( is_string( $logo ) && '' !== $logo ) {
			// If filter returns HTML, sanitize it.
			if ( false !== strpos( $logo, '<' ) ) {
				return wp_kses_post( $logo );
			}

			// Otherwise, treat it as an URL.
			$logo = esc_url_raw( $logo );
			if ( '' === $logo ) {
				return '';
			}

			return '<img src="' . esc_url( $logo ) . '" alt="" class="ufsc-pdf-logo" />';
		}

		return '';
	}

	private function build_footer_html( $competition, string $mode ): string {
		$footer = apply_filters( 'ufsc_competitions_plateau_pdf_footer_html', '', $competition, $mode );

		if ( is_string( $footer ) && '' !== $footer ) {
			// Safety: sanitize custom HTML.
			return '<div class="ufsc-pdf-footer">' . wp_kses_post( $footer ) . '</div>';
		}

		return '';
	}

	private function get_fiche_complete_meta( $competition ): array {
		$meta = array();

		$start = (string) ( $competition->event_start_datetime ?? '' );
		$end   = (string) ( $competition->event_end_datetime ?? '' );

		if ( $start || $end ) {
			$meta[ __( 'Dates', 'ufsc-licence-competition' ) ] = trim( $start . ( $end ? ' → ' . $end : '' ) );
		}

		$location = (string) ( $competition->location ?? $competition->venue_name ?? '' );
		if ( $location ) {
			$meta[ __( 'Lieu', 'ufsc-licence-competition' ) ] = $location;
		}

		$contact = (string) ( $competition->contact_name ?? '' );
		if ( $contact ) {
			$meta[ __( 'Contact', 'ufsc-licence-competition' ) ] = $contact;
		}

		$contact_email = (string) ( $competition->contact_email ?? '' );
		if ( $contact_email ) {
			$meta[ __( 'Email', 'ufsc-licence-competition' ) ] = $contact_email;
		}

		$contact_phone = (string) ( $competition->contact_phone ?? '' );
		if ( $contact_phone ) {
			$meta[ __( 'Téléphone', 'ufsc-licence-competition' ) ] = $contact_phone;
		}

		return $meta;
	}

	private function normalize_mode( string $mode ): string {
		$mode    = sanitize_key( $mode );
		$allowed = array( 'plateau', 'fiche', 'controle', 'fiche_complete' );

		if ( ! in_array( $mode, $allowed, true ) ) {
			$mode = 'plateau';
		}

		$mode = apply_filters( 'ufsc_competitions_plateau_pdf_mode', $mode );

		if ( ! in_array( $mode, $allowed, true ) ) {
			return 'plateau';
		}

		return $mode;
	}
}
