<?php

namespace UFSC\Competitions\Front\Shortcodes;

use UFSC\Competitions\Front\Access\ClubAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CompetitionsListShortcode {
	public function register(): void {
		add_shortcode( 'ufsc_competitions', array( $this, 'render' ) );
	}

	public function render( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'view'          => 'open',
				'season'        => '',
				'discipline'    => '',
				'type'          => '',
				'per_page'      => 10,
				'show_filters'  => 1,
				'require_login' => 1,
				'require_club'  => 0,
			),
			$atts,
			'ufsc_competitions'
		);

		$require_login = (int) $atts['require_login'] === 1;
		if ( ! is_user_logged_in() ) {
			if ( $require_login ) {
				return $this->render_notice( esc_html__( 'Vous devez être connecté pour accéder aux compétitions.', 'ufsc-licence-competition' ) );
			}

			return '';
		}

		if ( (int) $atts['require_club'] === 1 ) {
			$access = new ClubAccess();
			if ( ! $access->is_club_user( get_current_user_id() ) ) {
				return $this->render_notice( esc_html__( 'Accès réservé aux clubs affiliés.', 'ufsc-licence-competition' ) );
			}
		}

		$view = in_array( $atts['view'], array( 'open', 'all' ), true ) ? $atts['view'] : 'open';

		$filters = array(
			'view'       => $view,
			'season'     => sanitize_text_field( (string) $atts['season'] ),
			'discipline' => sanitize_text_field( (string) $atts['discipline'] ),
			'type'       => sanitize_text_field( (string) $atts['type'] ),
			's'          => '',
		);

		$show_filters = (int) $atts['show_filters'] === 1;
		if ( $show_filters ) {
			if ( isset( $_GET['ufsc_season'] ) ) {
				$filters['season'] = sanitize_text_field( wp_unslash( $_GET['ufsc_season'] ) );
			}
			if ( isset( $_GET['ufsc_discipline'] ) ) {
				$filters['discipline'] = sanitize_text_field( wp_unslash( $_GET['ufsc_discipline'] ) );
			}
			if ( isset( $_GET['ufsc_type'] ) ) {
				$filters['type'] = sanitize_text_field( wp_unslash( $_GET['ufsc_type'] ) );
			}
			if ( isset( $_GET['s'] ) ) {
				$filters['s'] = sanitize_text_field( wp_unslash( $_GET['s'] ) );
			}
		}

		$per_page = max( 1, (int) $atts['per_page'] );
		$current_page = isset( $_GET['ufsc_page'] ) ? max( 1, absint( wp_unslash( $_GET['ufsc_page'] ) ) ) : 1;
		$offset = ( $current_page - 1 ) * $per_page;

		$repository = new CompetitionReadRepository();
		$total = $repository->count( $filters );
		$items = $repository->list( $filters, $per_page, $offset );

		$output = '';

		if ( $show_filters ) {
			$output .= $this->render_filters_form( $filters );
		}

		$output .= $this->render_table( $items );
		$output .= $this->render_pagination( $total, $per_page, $current_page );

		return $output;
	}

	private function render_filters_form( array $filters ): string {
		$action = esc_url( remove_query_arg( array( 'ufsc_page' ) ) );

		return sprintf(
			'<form class="ufsc-competitions-filters" method="get" action="%s">
				<div class="ufsc-competitions-filters__row">
					<label>
						<span class="screen-reader-text">%s</span>
						<input type="text" name="ufsc_season" value="%s" placeholder="%s" />
					</label>
					<label>
						<span class="screen-reader-text">%s</span>
						<input type="text" name="ufsc_discipline" value="%s" placeholder="%s" />
					</label>
					<label>
						<span class="screen-reader-text">%s</span>
						<input type="text" name="ufsc_type" value="%s" placeholder="%s" />
					</label>
					<label>
						<span class="screen-reader-text">%s</span>
						<input type="search" name="s" value="%s" placeholder="%s" />
					</label>
					<button type="submit" class="button">%s</button>
				</div>
			</form>',
			$action,
			esc_html__( 'Saison', 'ufsc-licence-competition' ),
			esc_attr( $filters['season'] ?? '' ),
			esc_attr__( 'Saison', 'ufsc-licence-competition' ),
			esc_html__( 'Discipline', 'ufsc-licence-competition' ),
			esc_attr( $filters['discipline'] ?? '' ),
			esc_attr__( 'Discipline', 'ufsc-licence-competition' ),
			esc_html__( 'Type', 'ufsc-licence-competition' ),
			esc_attr( $filters['type'] ?? '' ),
			esc_attr__( 'Type', 'ufsc-licence-competition' ),
			esc_html__( 'Recherche', 'ufsc-licence-competition' ),
			esc_attr( $filters['s'] ?? '' ),
			esc_attr__( 'Rechercher...', 'ufsc-licence-competition' ),
			esc_html__( 'Filtrer', 'ufsc-licence-competition' )
		);
	}

	private function render_table( array $items ): string {
		if ( empty( $items ) ) {
			return sprintf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Aucune compétition trouvée.', 'ufsc-licence-competition' )
			);
		}

		$rows = '';
		foreach ( $items as $item ) {
			$detail_url = Front::get_competition_details_url( (int) ( $item->id ?? 0 ) );
			$detail_url = apply_filters( 'ufsc_competitions_front_competition_url', $detail_url, $item );

			$rows .= sprintf(
				'<tr>
					<td data-label="%s">%s</td>
					<td data-label="%s">%s</td>
					<td data-label="%s">%s</td>
					<td data-label="%s">%s</td>
					<td data-label="%s">%s</td>
					<td data-label="%s">%s</td>
					<td data-label="%s"><a class="button" href="%s">%s</a></td>
				</tr>',
				esc_attr__( 'Nom', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->name ?? '' ) ),
				esc_attr__( 'Discipline', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->discipline ?? '' ) ),
				esc_attr__( 'Type', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->type ?? '' ) ),
				esc_attr__( 'Saison', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->season ?? '' ) ),
				esc_attr__( 'Statut', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->status ?? '' ) ),
				esc_attr__( 'Début', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->event_start_datetime ?? '' ) ),
				esc_attr__( 'Voir', 'ufsc-licence-competition' ),
				esc_url( $detail_url ),
				esc_html__( 'Voir', 'ufsc-licence-competition' )
			);
		}

		return sprintf(
			'<div class="ufsc-competitions-table-wrapper">
				<table class="ufsc-competitions-table wp-list-table widefat striped">
					<thead>
						<tr>
							<th>%s</th>
							<th>%s</th>
							<th>%s</th>
							<th>%s</th>
							<th>%s</th>
							<th>%s</th>
							<th>%s</th>
						</tr>
					</thead>
					<tbody>%s</tbody>
				</table>
			</div>',
			esc_html__( 'Nom', 'ufsc-licence-competition' ),
			esc_html__( 'Discipline', 'ufsc-licence-competition' ),
			esc_html__( 'Type', 'ufsc-licence-competition' ),
			esc_html__( 'Saison', 'ufsc-licence-competition' ),
			esc_html__( 'Statut', 'ufsc-licence-competition' ),
			esc_html__( 'Début', 'ufsc-licence-competition' ),
			esc_html__( 'Actions', 'ufsc-licence-competition' ),
			$rows
		);
	}

	private function render_pagination( int $total, int $per_page, int $current_page ): string {
		if ( $total <= $per_page ) {
			return '';
		}

		$total_pages = (int) ceil( $total / $per_page );
		$links = array();

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$url = add_query_arg( 'ufsc_page', $page );
			$links[] = sprintf(
				'<a class="ufsc-competitions-page%s" href="%s">%s</a>',
				$page === $current_page ? ' is-current' : '',
				esc_url( $url ),
				esc_html( (string) $page )
			);
		}

		return sprintf(
			'<nav class="ufsc-competitions-pagination" aria-label="%s">%s</nav>',
			esc_attr__( 'Pagination', 'ufsc-licence-competition' ),
			implode( ' ', $links )
		);
	}

	private function render_notice( string $message ): string {
		return sprintf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
