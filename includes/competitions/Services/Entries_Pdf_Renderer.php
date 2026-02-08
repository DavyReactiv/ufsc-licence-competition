<?php

namespace UFSC\Competitions\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Pdf_Renderer {
	public function render_pdf( $competition, array $entries ): string {
		$rows = $this->build_rows( $entries );
		$groups = $this->group_rows( $rows );
		$html = $this->build_html( $competition, $groups );

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
			$birthdate = (string) ( $entry->birth_date ?? $entry->birthdate ?? $entry->licensee_birthdate ?? '' );
			$year = '';
			if ( $birthdate && preg_match( '/^(\\d{4})-\\d{2}-\\d{2}$/', $birthdate, $matches ) ) {
				$year = $matches[1];
			}

			$status = function_exists( 'ufsc_lc_is_entry_eligible' )
				? (string) ( ufsc_lc_is_entry_eligible( (int) ( $entry->id ?? 0 ), 'exports' )['status'] ?? '' )
				: (string) ( $entry->status ?? '' );

			$rows[] = array(
				'license' => (string) ( $entry->license_number ?? $entry->licence_number ?? '' ),
				'last_name' => (string) ( $entry->last_name ?? $entry->lastname ?? $entry->licensee_last_name ?? '' ),
				'first_name' => (string) ( $entry->first_name ?? $entry->firstname ?? $entry->licensee_first_name ?? '' ),
				'year' => $year,
				'club' => (string) ( $entry->club_name ?? '' ),
				'city' => (string) ( $entry->club_city ?? '' ),
				'category' => (string) ( $entry->category ?? $entry->category_name ?? '' ),
				'weight_class' => (string) ( $entry->weight_class ?? '' ),
				'status' => $status,
			);
		}

		return $rows;
	}

	private function group_rows( array $rows ): array {
		$grouped = array();

		foreach ( $rows as $row ) {
			$category = trim( $row['category'] );
			$weight_class = trim( $row['weight_class'] );
			$category_key = '' !== $category ? $category : __( 'Sans catégorie', 'ufsc-licence-competition' );
			$weight_key = '' !== $weight_class ? $weight_class : __( 'Sans poids', 'ufsc-licence-competition' );

			if ( ! isset( $grouped[ $category_key ] ) ) {
				$grouped[ $category_key ] = array();
			}
			if ( ! isset( $grouped[ $category_key ][ $weight_key ] ) ) {
				$grouped[ $category_key ][ $weight_key ] = array();
			}

			$grouped[ $category_key ][ $weight_key ][] = $row;
		}

		ksort( $grouped, SORT_NATURAL | SORT_FLAG_CASE );
		foreach ( $grouped as $category => $weights ) {
			ksort( $weights, SORT_NATURAL | SORT_FLAG_CASE );
			foreach ( $weights as $weight => $items ) {
				usort(
					$items,
					static function( $a, $b ) {
						$cmp = strcasecmp( $a['last_name'], $b['last_name'] );
						if ( 0 === $cmp ) {
							return strcasecmp( $a['first_name'], $b['first_name'] );
						}
						return $cmp;
					}
				);
				$grouped[ $category ][ $weight ] = $items;
			}
		}

		return $grouped;
	}

	private function build_html( $competition, array $groups ): string {
		$title = esc_html( (string) ( $competition->name ?? '' ) );
		$subtitle_bits = array();
		$event_date = (string) ( $competition->event_start_datetime ?? '' );
		if ( $event_date && function_exists( 'ufsc_lc_format_datetime' ) ) {
			$subtitle_bits[] = ufsc_lc_format_datetime( $event_date );
		}
		$location = (string) ( $competition->lieu_name ?? $competition->location ?? '' );
		if ( $location ) {
			$subtitle_bits[] = $location;
		}
		$subtitle = esc_html( implode( ' · ', $subtitle_bits ) );
		$generated_at = function_exists( 'ufsc_lc_format_datetime' )
			? ufsc_lc_format_datetime( current_time( 'mysql' ) )
			: current_time( 'mysql' );

		$body = '';
		if ( empty( $groups ) ) {
			$body = '<p>' . esc_html__( 'Aucune inscription.', 'ufsc-licence-competition' ) . '</p>';
		} else {
			foreach ( $groups as $category => $weights ) {
				$body .= '<h3>' . esc_html( $category ) . '</h3>';
				foreach ( $weights as $weight => $rows ) {
					$body .= '<h4>' . esc_html( $weight ) . '</h4>';
					$body .= '<table><thead><tr>'
						. '<th>' . esc_html__( 'Licence', 'ufsc-licence-competition' ) . '</th>'
						. '<th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th>'
						. '<th>' . esc_html__( 'Prénom', 'ufsc-licence-competition' ) . '</th>'
						. '<th>' . esc_html__( 'Année', 'ufsc-licence-competition' ) . '</th>'
						. '<th>' . esc_html__( 'Club', 'ufsc-licence-competition' ) . '</th>'
						. '<th>' . esc_html__( 'Ville', 'ufsc-licence-competition' ) . '</th>'
						. '</tr></thead><tbody>';

					foreach ( $rows as $row ) {
						$pending = 'approved' === $row['status'] ? '' : ' ' . esc_html__( 'En attente', 'ufsc-licence-competition' );
						$body .= '<tr>'
							. '<td>' . esc_html( $row['license'] ) . '</td>'
							. '<td>' . esc_html( $row['last_name'] . $pending ) . '</td>'
							. '<td>' . esc_html( $row['first_name'] ) . '</td>'
							. '<td>' . esc_html( $row['year'] ) . '</td>'
							. '<td>' . esc_html( $row['club'] ) . '</td>'
							. '<td>' . esc_html( $row['city'] ) . '</td>'
							. '</tr>';
					}

					$body .= '</tbody></table>';
				}
			}
		}

		$styles = '
			body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
			h1 { font-size: 16px; margin: 0 0 6px 0; }
			h2 { font-size: 12px; margin: 0 0 12px 0; }
			h3 { font-size: 12px; margin: 16px 0 6px 0; }
			h4 { font-size: 11px; margin: 8px 0 4px 0; }
			table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
			th, td { border: 1px solid #ddd; padding: 4px; text-align: left; }
			th { background: #f5f5f5; }
			.footer { margin-top: 16px; font-size: 9px; color: #555; }
		';

		return <<<HTML
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>{$styles}</style>
</head>
<body>
<h1>{$title}</h1>
<h2>{$subtitle}</h2>
{$body}
<div class="footer">
<p>Généré le {$generated_at}</p>
<p>Pour toute information, contactez le secrétariat UFSC : secretaire@ufsc-france.org</p>
</div>
</body>
</html>
HTML;
	}
}
