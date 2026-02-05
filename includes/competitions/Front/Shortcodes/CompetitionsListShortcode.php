<?php

namespace UFSC\Competitions\Front\Shortcodes;

use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\CompetitionReadRepository;
use UFSC\Competitions\Services\CompetitionFilters;

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
				'status'        => '',
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
			$user_id = (int) get_current_user_id();
			$club_id = function_exists( 'ufsc_get_current_club_id' ) ? (int) ufsc_get_current_club_id( $user_id ) : 0;
			if ( ! $club_id ) {
				return $this->render_notice( esc_html__( 'Accès réservé aux clubs affiliés.', 'ufsc-licence-competition' ) );
			}
		}

		$view = in_array( $atts['view'], array( 'open', 'all' ), true ) ? $atts['view'] : 'open';

		$filters = array(
			'view'       => $view,
			'season'     => sanitize_text_field( (string) $atts['season'] ),
			'discipline' => sanitize_text_field( (string) $atts['discipline'] ),
			'type'       => sanitize_text_field( (string) $atts['type'] ),
			'status'     => sanitize_text_field( (string) $atts['status'] ),
			's'          => '',
		);

		$show_filters = (int) $atts['show_filters'] === 1;
		if ( $show_filters ) {
			$filters['season'] = $this->get_query_value( array( 'ufsc_season', 'season' ), $filters['season'] );
			$filters['discipline'] = $this->get_query_value( array( 'ufsc_discipline', 'discipline' ), $filters['discipline'] );
			$filters['type'] = $this->get_query_value( array( 'ufsc_type', 'type' ), $filters['type'] );
			$filters['status'] = $this->get_query_value( array( 'ufsc_status', 'status' ), $filters['status'] );
			$filters['s'] = $this->get_query_value( array( 's' ), $filters['s'] );
		}
		$filters['status'] = sanitize_key( (string) $filters['status'] );

		$per_page = max( 1, (int) $atts['per_page'] );
		$current_page = isset( $_GET['ufsc_page'] ) ? max( 1, absint( wp_unslash( $_GET['ufsc_page'] ) ) ) : 1;
		$offset = ( $current_page - 1 ) * $per_page;

		$repository = new CompetitionReadRepository();
		$total = $repository->count( $filters );
		$items = $repository->list( $filters, $per_page, $offset );

		$output = '';

		if ( $show_filters ) {
			$output .= $this->render_filters_form(
				$filters,
				array(
					'seasons'     => CompetitionFilters::get_seasons(),
					'disciplines' => CompetitionFilters::get_disciplines(),
					'types'       => CompetitionFilters::get_type_choices(),
					'statuses'    => CompetitionFilters::get_status_choices(),
				)
			);
		}

		$output .= $this->render_table( $items );
		$output .= $this->render_pagination( $total, $per_page, $current_page );

		return $output;
	}

	private function render_filters_form( array $filters, array $choices ): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			$request_uri = home_url( '/' );
		}
		$action = esc_url( remove_query_arg( array( 'ufsc_page' ), $request_uri ) );
		$reset_url = esc_url(
			remove_query_arg(
				array( 'ufsc_page', 'ufsc_season', 'season', 'ufsc_discipline', 'discipline', 'ufsc_type', 'type', 'ufsc_status', 'status', 's' ),
				$request_uri
			)
		);

		$season = (string) ( $filters['season'] ?? '' );
		$discipline = (string) ( $filters['discipline'] ?? '' );
		$type = CompetitionFilters::normalize_type_key( (string) ( $filters['type'] ?? '' ) );
		$status = (string) ( $filters['status'] ?? '' );
		$search = (string) ( $filters['s'] ?? '' );

		$season_options = $this->render_select_options(
			$choices['seasons'] ?? array(),
			$season,
			esc_html__( 'Toutes les saisons', 'ufsc-licence-competition' )
		);
		$discipline_options = $this->render_select_options(
			$choices['disciplines'] ?? array(),
			$discipline,
			esc_html__( 'Toutes les disciplines', 'ufsc-licence-competition' )
		);
		$type_options = $this->render_select_options(
			$choices['types'] ?? array(),
			$type,
			esc_html__( 'Tous les types', 'ufsc-licence-competition' )
		);
		$status_options = $this->render_select_options(
			$choices['statuses'] ?? array(),
			$status,
			esc_html__( 'Tous les statuts', 'ufsc-licence-competition' )
		);

		return sprintf(
			'<form class="ufsc-competitions-filters" method="get" action="%s">
				<div class="ufsc-competitions-filters__row">
					<label class="ufsc-competitions-filter">
						<span>%s</span>
						<select name="ufsc_season">%s</select>
					</label>
					<label class="ufsc-competitions-filter">
						<span>%s</span>
						<select name="ufsc_discipline">%s</select>
					</label>
					<label class="ufsc-competitions-filter">
						<span>%s</span>
						<select name="ufsc_type">%s</select>
					</label>
					<label class="ufsc-competitions-filter">
						<span>%s</span>
						<select name="ufsc_status">%s</select>
					</label>
					<label class="ufsc-competitions-filter ufsc-competitions-filter--search">
						<span>%s</span>
						<input type="search" name="s" value="%s" placeholder="%s" />
					</label>
					<div class="ufsc-competitions-filter-actions">
						<button type="submit" class="button button-primary">%s</button>
						<a class="button button-secondary" href="%s">%s</a>
					</div>
				</div>
			</form>',
			$action,
			esc_html__( 'Saison', 'ufsc-licence-competition' ),
			$season_options,
			esc_html__( 'Discipline', 'ufsc-licence-competition' ),
			$discipline_options,
			esc_html__( 'Type', 'ufsc-licence-competition' ),
			$type_options,
			esc_html__( 'Statut', 'ufsc-licence-competition' ),
			$status_options,
			esc_html__( 'Recherche', 'ufsc-licence-competition' ),
			esc_attr( $search ),
			esc_attr__( 'Rechercher...', 'ufsc-licence-competition' ),
			esc_html__( 'Filtrer', 'ufsc-licence-competition' ),
			$reset_url,
			esc_html__( 'Réinitialiser', 'ufsc-licence-competition' )
		);
	}

	private function render_table( array $items ): string {
		if ( empty( $items ) ) {
			return sprintf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'Aucune compétition trouvée.', 'ufsc-licence-competition' )
			);
		}

		$access = new CompetitionAccess();
		$user_id = is_user_logged_in() ? (int) get_current_user_id() : 0;
		$club_id = function_exists( 'ufsc_get_current_club_id' ) ? (int) ufsc_get_current_club_id( $user_id ) : 0;

		$rows = '';
		foreach ( $items as $item ) {
			$access_result = $access->can_view_competition( (int) ( $item->id ?? 0 ), $club_id, $user_id );
			$restricted_badge = '';
			if ( ! $access_result->allowed ) {
				$restricted_badge = sprintf(
					' <span class="ufsc-badge ufsc-badge-closed">%s</span>',
					esc_html__( 'Accès restreint', 'ufsc-licence-competition' )
				);
			}

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
				esc_html( (string) ( $item->name ?? '' ) ) . $restricted_badge,
				esc_attr__( 'Discipline', 'ufsc-licence-competition' ),
				esc_html( CompetitionFilters::get_discipline_label( (string) ( $item->discipline ?? '' ) ) ),
				esc_attr__( 'Type', 'ufsc-licence-competition' ),
				esc_html( CompetitionFilters::get_type_label( (string) ( $item->type ?? '' ) ) ),
				esc_attr__( 'Saison', 'ufsc-licence-competition' ),
				esc_html( (string) ( $item->season ?? '' ) ),
				esc_attr__( 'Statut', 'ufsc-licence-competition' ),
				esc_html( CompetitionFilters::get_status_label( (string) ( $item->status ?? '' ) ) ),
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

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		if ( '' === $request_uri ) {
			$request_uri = home_url( '/' );
		}

		$total_pages = (int) ceil( $total / $per_page );
		$links = array();

		for ( $page = 1; $page <= $total_pages; $page++ ) {
			$url = add_query_arg( 'ufsc_page', $page, $request_uri );
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

	private function get_query_value( array $keys, string $default ): string {
		foreach ( $keys as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}

		return $default;
	}

	private function render_select_options( array $choices, string $selected, string $placeholder ): string {
		$options = sprintf(
			'<option value="">%s</option>',
			esc_html( $placeholder )
		);

		foreach ( $choices as $value => $label ) {
			if ( is_int( $value ) ) {
				$value = $label;
			}
			$options .= sprintf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				selected( $selected, (string) $value, false ),
				esc_html( (string) $label )
			);
		}

		return $options;
	}
}
