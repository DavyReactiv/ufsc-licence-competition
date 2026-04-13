<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Db;
use UFSC\Competitions\Entries\EntryDataNormalizer;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\WeighInRepository;
use UFSC\Competitions\Services\WeightCategoryResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WeighIns_Page {
	private $competitions;
	private $entries;
	private $categories;
	private $weighins;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->entries      = new EntryRepository();
		$this->categories   = new CategoryRepository();
		$this->weighins     = new WeighInRepository();
	}

	public function render(): void {
		if ( ! Capabilities::user_can_manage_entries() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_context = $this->resolve_competition_context();
		$competition_id = (int) $competition_context['competition_id'];
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				'UFSC WeighIns_Page competition_context ' . wp_json_encode(
					array(
						'competition_id' => $competition_id,
						'source'         => (string) $competition_context['source'],
					)
				)
			);
		}
		if ( $competition_id && method_exists( $this->competitions, 'assert_competition_in_scope' ) ) {
			$this->competitions->assert_competition_in_scope( $competition_id );
		}

		$notice = $this->maybe_handle_actions( $competition_id );

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );

		$status_filter   = isset( $_GET['weighin_status'] ) ? sanitize_key( (string) wp_unslash( $_GET['weighin_status'] ) ) : 'all';
		$category_filter = isset( $_GET['category_filter'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['category_filter'] ) ) : '';
		$search_filter   = isset( $_GET['s'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['s'] ) ) : '';

		$entries = array();
		$stats   = array(
			'total' => 0,
			'weighed' => 0,
			'not_weighed' => 0,
			'out_of_limit' => 0,
			'pending' => 0,
			'eligible' => 0,
		);
		$categories = array();

		if ( $competition_id > 0 ) {
			$competition = $this->competitions->get( $competition_id, true );
			$category_rows = $this->categories->list(
				array(
					'view' => 'all',
					'competition_id' => $competition_id,
				),
				500,
				0
			);

			foreach ( $category_rows as $row ) {
				$categories[ (int) $row->id ] = $row;
			}

			$entry_statuses = array_merge( EntriesWorkflow::get_review_queue_statuses(), array( 'approved' ) );
			$entry_statuses = array_values( array_unique( array_filter( array_map( 'sanitize_key', $entry_statuses ) ) ) );
			$entry_filters = array(
				'view' => 'all',
				'competition_id' => $competition_id,
				'status' => $entry_statuses,
			);
			if ( '' !== $search_filter ) {
				$entry_filters['search'] = $search_filter;
			}
			if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
				$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
			}
			$entries = $this->entries->list_with_details( $entry_filters, 1000, 0 );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					'UFSC WeighIns_Page entries_loaded ' . wp_json_encode(
						array(
							'competition_id' => $competition_id,
							'count'          => count( $entries ),
							'statuses'       => $entry_filters['status'],
						)
					)
				);
			}
			$entry_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
			$weighins  = $this->weighins->get_for_entries( $competition_id, $entry_ids );

			$entries = array_values(
				array_filter(
					$entries,
						function( $entry ) use ( $category_filter, $status_filter, $categories, $weighins, $competition, &$stats ) {
							if ( (int) ( $entry->competition_id ?? 0 ) !== (int) ( $competition->id ?? 0 ) ) {
								if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
									error_log( 'UFSC WeighIns_Page entry_excluded competition_mismatch ' . wp_json_encode( array( 'entry_id' => (int) ( $entry->id ?? 0 ), 'entry_competition_id' => (int) ( $entry->competition_id ?? 0 ), 'competition_id' => (int) ( $competition->id ?? 0 ) ) ) );
								}
								return false;
							}
							$entry_id = (int) $entry->id;
						$category_label = (string) ( $entry->category_name ?? $entry->category ?? '' );
						if ( '' === $category_label && ! empty( $entry->category_id ) && isset( $categories[ (int) $entry->category_id ] ) ) {
							$category_label = (string) $categories[ (int) $entry->category_id ]->name;
						}
						$row = $weighins[ $entry_id ] ?? null;
						$meta = $this->extract_meta( $row ? (string) ( $row->notes ?? '' ) : '' );
						$status = $this->normalize_weighin_status( (string) ( $row->status ?? '' ), $meta );
						$status_label = $this->get_status_label( $status );
						$within_limit = $this->weighins->is_valid_weighin_row( $row, (float) ( $competition->weight_tolerance ?? 1 ), isset( $entry->weight_kg ) ? (float) $entry->weight_kg : null );
						$reclass_pending = ! empty( $meta['reclass_pending'] );
						$is_eligible = $within_limit && ! $reclass_pending;

						$stats['total']++;
						if ( $within_limit && ! $reclass_pending ) {
							$stats['weighed']++;
						} else {
							$stats['not_weighed']++;
						}
						if ( 'out_of_limit' === $status ) {
							$stats['out_of_limit']++;
						}
						if ( in_array( $status, array( 'pending', 'awaiting_reclassification' ), true ) ) {
							$stats['pending']++;
						}
						if ( $is_eligible ) {
							$stats['eligible']++;
						}

						$entry->_ufsc_weighin = array(
							'row' => $row,
							'meta' => $meta,
							'status' => $status,
							'status_label' => $status_label,
							'within_limit' => $within_limit,
							'is_eligible' => $is_eligible,
							'category_label' => $category_label,
						);

						if ( '' !== $category_filter && $category_filter !== $category_label ) {
							return false;
						}

						if ( 'all' !== $status_filter && $status_filter !== $status ) {
							return false;
						}

						return true;
					}
				)
			);
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Contrôle terrain', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Pesées', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Valider la pesée, attribuer un numéro combattant et préparer la génération des combats sans ambiguïté.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Validez ici les compétiteurs pesés et attribuez leur numéro. Un statut “hors limite” ou “en attente” bloque la génération automatique des combats.', 'ufsc-licence-competition' ); ?></p></div>

			<form method="get" class="ufsc-competitions-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_WEIGHINS ); ?>" />
				<select name="competition_id" required>
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $competition_item ) : ?>
						<option value="<?php echo esc_attr( $competition_item->id ); ?>" <?php selected( $competition_id, (int) $competition_item->id ); ?>><?php echo esc_html( $competition_item->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( $competition_id > 0 ) : ?>
					<input
						type="search"
						name="s"
						value="<?php echo esc_attr( $search_filter ); ?>"
						placeholder="<?php esc_attr_e( 'Nom, prénom, club, licence, n° combattant…', 'ufsc-licence-competition' ); ?>"
					/>
					<select name="category_filter">
						<option value=""><?php esc_html_e( 'Toutes les catégories', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $this->get_distinct_categories( $entries ) as $category_label ) : ?>
							<option value="<?php echo esc_attr( $category_label ); ?>" <?php selected( $category_filter, $category_label ); ?>><?php echo esc_html( $category_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="weighin_status">
						<?php foreach ( $this->get_status_choices() as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status_filter, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<?php submit_button( __( 'Filtrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				<?php if ( '' !== $search_filter ) : ?>
					<a class="button button-link" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_WEIGHINS, 'competition_id' => $competition_id ) ) ); ?>"><?php esc_html_e( 'Réinitialiser la recherche', 'ufsc-licence-competition' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( $competition_id > 0 ) : ?>
				<section class="ufsc-kpis ufsc-kpis--premium">
					<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Total inscrits', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong></article>
					<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Pesés', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $stats['weighed'] ) ); ?></strong></article>
					<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Non pesés', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $stats['not_weighed'] ) ); ?></strong></article>
					<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Hors limite', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $stats['out_of_limit'] ) ); ?></strong></article>
					<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'En attente', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $stats['pending'] ) ); ?></strong></article>
					<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Éligibles combats', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $stats['eligible'] ) ); ?></strong></article>
				</section>

				<div class="ufsc-competitions-table-wrap">
					<table class="widefat striped ufsc-weighins-table">
						<thead>
						<tr>
							<th><?php esc_html_e( 'N° combattant', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Nom', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Prénom', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Club', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Catégorie prévue', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Poids inscription', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Poids pesé', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Écart', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Statut pesée', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Reclassement proposé', 'ufsc-licence-competition' ); ?></th>
							<th><?php esc_html_e( 'Action', 'ufsc-licence-competition' ); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php if ( empty( $entries ) ) : ?>
							<tr><td colspan="11"><?php esc_html_e( 'Aucun inscrit correspondant aux filtres.', 'ufsc-licence-competition' ); ?></td></tr>
						<?php endif; ?>
						<?php foreach ( $entries as $entry ) : ?>
							<?php $this->render_entry_row( $entry, $competition_id, $categories ); ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_entry_row( $entry, int $competition_id, array $categories ): void {
		$entry_id = (int) $entry->id;
		$weighin = $entry->_ufsc_weighin;
		$meta = $weighin['meta'];
		$row = $weighin['row'];
		$current_weight = isset( $row->weight_measured ) ? (string) $row->weight_measured : '';
		$fighter_number = isset( $meta['fighter_number'] ) ? (string) $meta['fighter_number'] : '';
		if ( '' === $fighter_number ) {
			$fighter_number = $this->get_item_value_from_keys( $entry, array( 'fighter_number', 'competition_number', 'dossard' ) );
		}
		$fighter_number_int = absint( $fighter_number );
		$fighter_number_display = $fighter_number_int > 0 ? str_pad( (string) $fighter_number_int, 2, '0', STR_PAD_LEFT ) : '';
		$reclass_category_id = isset( $meta['reclass_category_id'] ) ? (int) $meta['reclass_category_id'] : 0;
		$suggested = $this->suggest_reclassification_categories( $entry, $categories, $current_weight );
		$normalized_entry = EntryDataNormalizer::normalize_for_admin( $entry );
		$last_name = (string) ( $normalized_entry['last_name'] ?? '' );
		$first_name = (string) ( $normalized_entry['first_name'] ?? '' );
		$club_name = (string) ( $normalized_entry['club_name'] ?? '' );
		$entry_weight = $this->get_item_value_from_keys( $entry, array( 'weight_kg', 'weight', 'poids' ) );
		$entry_weight_float = '' !== $entry_weight ? (float) str_replace( ',', '.', $entry_weight ) : null;
		$delta = ( null !== $entry_weight_float && '' !== $current_weight ) ? ( (float) str_replace( ',', '.', $current_weight ) - $entry_weight_float ) : null;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'UFSC WeighIns_Page entry_weight_source ' . wp_json_encode( array( 'entry_id' => $entry_id, 'competition_id' => $competition_id, 'entry_weight' => $entry_weight, 'measured_weight' => $current_weight ) ) );
		}

		$status_badge = in_array( $weighin['status'], array( 'weighed', 'validated', 'reclassified' ), true )
			? 'ufsc-badge--success'
			: ( in_array( $weighin['status'], array( 'out_of_limit', 'refused' ), true ) ? 'ufsc-badge--danger' : 'ufsc-badge--warning' );
		?>
		<tr>
			<form method="post">
				<?php wp_nonce_field( 'ufsc_weighin_save_' . $entry_id ); ?>
				<input type="hidden" name="ufsc_weighin_action" value="save" />
				<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
				<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
				<td><input type="number" class="small-text" min="1" max="9999" name="fighter_number" value="<?php echo esc_attr( $fighter_number_int > 0 ? (string) $fighter_number_int : '' ); ?>" placeholder="<?php echo esc_attr( $fighter_number_display ); ?>" /></td>
				<td><?php echo esc_html( '' !== $last_name ? $last_name : '—' ); ?></td>
				<td><?php echo esc_html( '' !== $first_name ? $first_name : '—' ); ?></td>
				<td><?php echo esc_html( '' !== $club_name ? $club_name : '—' ); ?></td>
				<td><?php echo esc_html( (string) $weighin['category_label'] ); ?></td>
				<td><?php echo esc_html( '' !== $entry_weight ? $entry_weight . ' kg' : '—' ); ?></td>
				<td><input type="number" step="0.1" min="0" max="300" name="weight_measured" value="<?php echo esc_attr( $current_weight ); ?>" class="small-text" /></td>
				<td><?php echo esc_html( null !== $delta ? sprintf( '%+.1f kg', $delta ) : '—' ); ?></td>
				<td>
					<select name="weighin_status">
						<?php foreach ( $this->get_mutation_status_choices() as $status_value => $status_label ) : ?>
							<option value="<?php echo esc_attr( $status_value ); ?>" <?php selected( $weighin['status'], $status_value ); ?>><?php echo esc_html( $status_label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="ufsc-badge <?php echo esc_attr( $status_badge ); ?>"><?php echo esc_html( $weighin['status_label'] ); ?></span>
				</td>
				<td>
					<select name="reclass_category_id">
						<option value="0"><?php esc_html_e( 'Aucun', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $suggested as $category_option ) : ?>
							<option value="<?php echo esc_attr( (int) $category_option->id ); ?>" <?php selected( $reclass_category_id, (int) $category_option->id ); ?>><?php echo esc_html( (string) $category_option->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<td>
					<?php submit_button( __( 'Enregistrer', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
					<?php if ( ! $weighin['is_eligible'] ) : ?>
						<p class="description"><?php esc_html_e( 'Non générable tant que la pesée/reclassification n’est pas validée.', 'ufsc-licence-competition' ); ?></p>
					<?php endif; ?>
				</td>
			</form>
		</tr>
		<?php
	}

	private function maybe_handle_actions( int $competition_id ): ?array {
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] || empty( $_POST['ufsc_weighin_action'] ) ) {
			return null;
		}

		if ( ! Capabilities::user_can_manage_entries() ) {
			return array( 'type' => 'error', 'message' => __( 'Permissions insuffisantes.', 'ufsc-licence-competition' ) );
		}

		$entry_id = isset( $_POST['entry_id'] ) ? absint( $_POST['entry_id'] ) : 0;
		if ( ! $entry_id ) {
			return array( 'type' => 'error', 'message' => __( 'Inscrit invalide.', 'ufsc-licence-competition' ) );
		}

		check_admin_referer( 'ufsc_weighin_save_' . $entry_id );

		if ( ! $competition_id ) {
			$competition_id = isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0;
		}
		if ( ! $competition_id ) {
			return array( 'type' => 'error', 'message' => __( 'Compétition invalide.', 'ufsc-licence-competition' ) );
		}

		$entry = $this->entries->get_with_details( $entry_id, true );
		if ( ! $entry || (int) $entry->competition_id !== $competition_id ) {
			return array( 'type' => 'error', 'message' => __( 'Inscrit introuvable pour cette compétition.', 'ufsc-licence-competition' ) );
		}

		$weight_raw = isset( $_POST['weight_measured'] ) ? str_replace( ',', '.', (string) wp_unslash( $_POST['weight_measured'] ) ) : '';
		$weight = '' !== trim( $weight_raw ) ? (float) $weight_raw : null;
		if ( null !== $weight && ( $weight <= 0 || $weight > 300 ) ) {
			return array( 'type' => 'error', 'message' => __( 'Poids invalide.', 'ufsc-licence-competition' ) );
		}

		$status = isset( $_POST['weighin_status'] ) ? sanitize_key( (string) wp_unslash( $_POST['weighin_status'] ) ) : 'pending';
		if ( ! isset( $this->get_mutation_status_choices()[ $status ] ) ) {
			$status = 'pending';
		}

		$existing = $this->weighins->get_for_entry( $competition_id, $entry_id );
		$existing_meta = $this->extract_meta( $existing ? (string) ( $existing->notes ?? '' ) : '' );
		$existing_fighter_number = absint( $existing_meta['fighter_number'] ?? 0 );
		if ( $existing_fighter_number <= 0 ) {
			$existing_fighter_number = absint( $entry->fighter_number ?? $entry->competition_number ?? 0 );
		}

		$fighter_number = isset( $_POST['fighter_number'] ) ? absint( $_POST['fighter_number'] ) : 0;
		$status_allows_fighter_number = $this->status_allows_auto_fighter_number( $status );
		if ( $fighter_number <= 0 && $existing_fighter_number > 0 ) {
			$fighter_number = $existing_fighter_number;
		} elseif ( $fighter_number <= 0 && $status_allows_fighter_number ) {
			$fighter_number = $this->next_available_fighter_number( $competition_id, $entry_id );
			if ( $fighter_number > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'UFSC WeighIns_Page fighter_number_assigned ' . wp_json_encode( array( 'competition_id' => $competition_id, 'entry_id' => $entry_id, 'fighter_number' => $fighter_number, 'source' => 'auto_on_validation' ) ) );
			}
		} elseif ( ! $status_allows_fighter_number && $existing_fighter_number <= 0 ) {
			$fighter_number = $this->next_available_fighter_number( $competition_id, $entry_id );
		}

		$reclass_category_id = isset( $_POST['reclass_category_id'] ) ? absint( $_POST['reclass_category_id'] ) : 0;
		$fighter_number_reassigned = false;
		if ( $fighter_number > 0 ) {
			$duplicate_entry_id = $this->find_duplicate_fighter_number_entry( $competition_id, $fighter_number, $entry_id );
			if ( $duplicate_entry_id > 0 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'UFSC WeighIns_Page fighter_number_conflict ' . wp_json_encode( array( 'competition_id' => $competition_id, 'entry_id' => $entry_id, 'fighter_number' => $fighter_number, 'duplicate_entry_id' => $duplicate_entry_id ) ) );
				}
				$fighter_number = $this->next_available_fighter_number( $competition_id, $entry_id );
				$fighter_number_reassigned = true;
			}
		}

		$meta = $existing_meta;
		$meta['fighter_number'] = $fighter_number > 0 ? $fighter_number : '';
		$meta['reclass_category_id'] = $reclass_category_id > 0 ? $reclass_category_id : '';
		$meta['reclass_pending'] = in_array( $status, array( 'awaiting_reclassification', 'out_of_limit' ), true ) ? 1 : 0;

		if ( 'reclassified' === $status && $reclass_category_id > 0 ) {
			$category = $this->categories->get( $reclass_category_id, true );
			if ( $category && (int) $category->competition_id === $competition_id ) {
				$this->entries->update(
					$entry_id,
					array(
						'category_id' => (int) $category->id,
						'category_name' => (string) $category->name,
						'category' => (string) $category->name,
					)
				);
			}
		}

		global $wpdb;
		if ( ! $this->weighins->has_table() ) {
			return array( 'type' => 'error', 'message' => __( 'Table des pesées introuvable.', 'ufsc-licence-competition' ) );
		}
		$payload = array(
			'competition_id' => $competition_id,
			'entry_id' => $entry_id,
			'weight_measured' => $weight,
			'status' => $status,
			'weighed_at' => current_time( 'mysql' ),
			'weighed_by' => get_current_user_id() ?: null,
			'notes' => wp_json_encode( $meta ),
			'updated_at' => current_time( 'mysql' ),
		);
		if ( ! $existing ) {
			$payload['created_at'] = current_time( 'mysql' );
		}

		$result = $wpdb->replace( Db::weighins_table(), $payload );
		if ( false !== $result ) {
			$entry_update = array();
			if ( null !== $weight ) {
				$entry_update['weight_kg'] = $weight;
			}
			if ( $fighter_number > 0 ) {
				$entry_update['fighter_number'] = $fighter_number;
				$entry_update['competition_number'] = $fighter_number;
				$entry_update['dossard'] = $fighter_number;
			}
			if ( in_array( $status, array( 'weighed', 'validated', 'reclassified' ), true ) ) {
				$entry_update['status'] = 'approved';
			}
			if ( ! empty( $entry_update ) ) {
				$this->entries->update( $entry_id, $entry_update );
			}
		}

		if ( false === $result ) {
			return array( 'type' => 'error', 'message' => __( 'Enregistrement de pesée impossible.', 'ufsc-licence-competition' ) );
		}

		$message = __( 'Pesée mise à jour.', 'ufsc-licence-competition' );
		if ( $fighter_number_reassigned ) {
			$message = sprintf(
				/* translators: %d: fighter number reassigned automatically. */
				__( 'Pesée mise à jour. Numéro déjà utilisé : réattribution automatique au n°%d.', 'ufsc-licence-competition' ),
				(int) $fighter_number
			);
		}

		return array( 'type' => 'success', 'message' => $message );
	}

	private function extract_meta( string $notes ): array {
		$decoded = json_decode( $notes, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function get_distinct_categories( array $entries ): array {
		$labels = array();
		foreach ( $entries as $entry ) {
			$label = (string) ( $entry->_ufsc_weighin['category_label'] ?? '' );
			if ( '' !== $label ) {
				$labels[ $label ] = $label;
			}
		}
		ksort( $labels );

		return array_values( $labels );
	}

	private function get_status_choices(): array {
		return array(
			'all' => __( 'Tous statuts', 'ufsc-licence-competition' ),
			'pending' => __( 'En attente', 'ufsc-licence-competition' ),
			'weighed' => __( 'Pesé', 'ufsc-licence-competition' ),
			'validated' => __( 'Validé', 'ufsc-licence-competition' ),
			'out_of_limit' => __( 'Hors limite', 'ufsc-licence-competition' ),
			'awaiting_reclassification' => __( 'Reclassement en attente', 'ufsc-licence-competition' ),
			'reclassified' => __( 'Reclassé', 'ufsc-licence-competition' ),
			'refused' => __( 'Refusé', 'ufsc-licence-competition' ),
		);
	}

	private function get_mutation_status_choices(): array {
		$choices = $this->get_status_choices();
		unset( $choices['all'] );
		return $choices;
	}

	private function get_status_label( string $status ): string {
		$choices = $this->get_status_choices();
		return $choices[ $status ] ?? __( 'En attente', 'ufsc-licence-competition' );
	}

	private function normalize_weighin_status( string $status, array $meta ): string {
		$status = sanitize_key( $status );
		if ( '' === $status || 'ok' === $status ) {
			$status = 'weighed';
		}
		if ( in_array( $status, array( 'awaiting_reclass', 'waiting_reclassification' ), true ) ) {
			$status = 'awaiting_reclassification';
		}
		if ( ! isset( $this->get_status_choices()[ $status ] ) ) {
			$status = 'pending';
		}
		if ( ! empty( $meta['reclass_pending'] ) && in_array( $status, array( 'weighed', 'validated' ), true ) ) {
			return 'awaiting_reclassification';
		}

		return $status;
	}

	private function suggest_reclassification_categories( $entry, array $categories, string $weight_measured ): array {
		$weight = (float) str_replace( ',', '.', $weight_measured );
		if ( $weight <= 0 ) {
			return array();
		}

		$birth_date = EntryDataNormalizer::resolve_birth_date( $entry );
		$sex = sanitize_text_field( (string) ( $entry->licensee_sex ?? $entry->sex ?? $entry->gender ?? '' ) );
		$competition_id = (int) ( $entry->competition_id ?? 0 );
		$competition = $competition_id > 0 ? $this->competitions->get( $competition_id, true ) : null;
		$context = array(
			'discipline'      => sanitize_key( (string) ( $competition->discipline ?? '' ) ),
			'age_reference'   => sanitize_text_field( (string) ( $competition->age_reference ?? '12-31' ) ),
			'season_end_year' => isset( $competition->season ) ? (int) $competition->season : 0,
		);
		$resolved = WeightCategoryResolver::resolve_with_details( $birth_date, $sex, $weight, $context );
		$label = sanitize_text_field( (string) ( $resolved['label'] ?? '' ) );
		if ( '' === $label || WeightCategoryResolver::OUT_OF_RANGE_LABEL === $label ) {
			return array();
		}

		$candidates = array();
		foreach ( $categories as $category ) {
			if ( sanitize_text_field( (string) ( $category->name ?? '' ) ) === $label ) {
				$candidates[] = $category;
			}
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'UFSC WeighIns_Page reclass_resolved ' . wp_json_encode( array( 'entry_id' => (int) ( $entry->id ?? 0 ), 'competition_id' => $competition_id, 'weight_measured' => $weight, 'resolved_label' => $label, 'status' => (string) ( $resolved['status'] ?? '' ) ) ) );
		}

		return $candidates;
	}

	private function status_allows_auto_fighter_number( string $status ): bool {
		return in_array( sanitize_key( $status ), array( 'weighed', 'validated' ), true );
	}

	private function next_available_fighter_number( int $competition_id, int $entry_id = 0 ): int {
		$competition_id = absint( $competition_id );
		if ( $competition_id <= 0 ) {
			return 0;
		}

		$used = array();
		$entries = $this->entries->list_with_details(
			array(
				'view' => 'all',
				'competition_id' => $competition_id,
			),
			2000,
			0
		);
		$entry_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
		$weighins = $this->weighins->get_for_entries( $competition_id, $entry_ids );

		foreach ( $entries as $entry ) {
			$current_entry_id = (int) ( $entry->id ?? 0 );
			if ( $entry_id > 0 && $current_entry_id === $entry_id ) {
				continue;
			}
			$number = absint( $entry->fighter_number ?? $entry->competition_number ?? 0 );
			if ( $number <= 0 && isset( $weighins[ $current_entry_id ] ) ) {
				$meta = $this->extract_meta( (string) ( $weighins[ $current_entry_id ]->notes ?? '' ) );
				$number = absint( $meta['fighter_number'] ?? 0 );
			}
			if ( $number > 0 ) {
				if ( isset( $used[ $number ] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( 'UFSC WeighIns_Page fighter_number_conflict ' . wp_json_encode( array( 'competition_id' => $competition_id, 'entry_id' => $current_entry_id, 'fighter_number' => $number ) ) );
				}
				$used[ $number ] = true;
			}
		}

		$next = 1;
		while ( isset( $used[ $next ] ) ) {
			$next++;
		}

		return $next;
	}

	private function find_duplicate_fighter_number_entry( int $competition_id, int $fighter_number, int $exclude_entry_id = 0 ): int {
		$competition_id = absint( $competition_id );
		$fighter_number = absint( $fighter_number );
		$exclude_entry_id = absint( $exclude_entry_id );
		if ( $competition_id <= 0 || $fighter_number <= 0 ) {
			return 0;
		}

		$duplicate_entry_id = $this->weighins->find_entry_id_by_fighter_number( $competition_id, $fighter_number, $exclude_entry_id );
		if ( $duplicate_entry_id > 0 ) {
			return $duplicate_entry_id;
		}

		$entries = $this->entries->list_with_details(
			array(
				'view' => 'all',
				'competition_id' => $competition_id,
			),
			2000,
			0
		);
		foreach ( $entries as $entry ) {
			$entry_id = absint( $entry->id ?? 0 );
			if ( $entry_id <= 0 || $entry_id === $exclude_entry_id ) {
				continue;
			}
			$current = absint( $entry->fighter_number ?? $entry->competition_number ?? 0 );
			if ( $current === $fighter_number ) {
				return $entry_id;
			}
		}

		return 0;
	}

	private function get_item_value( $item, string $key ) {
		if ( is_array( $item ) && array_key_exists( $key, $item ) ) {
			return $item[ $key ];
		}
		if ( is_object( $item ) && property_exists( $item, $key ) ) {
			return $item->{$key};
		}

		return '';
	}

	private function get_item_value_from_keys( $item, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->get_item_value( $item, $key );
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return sanitize_text_field( (string) $value );
			}
		}

		return '';
	}

	private function split_participant_name( string $participant_name ): array {
		$participant_name = trim( $participant_name );
		if ( '' === $participant_name ) {
			return array( 'first' => '', 'last' => '' );
		}

		$parts = preg_split( '/\s+/', $participant_name );
		if ( ! is_array( $parts ) || empty( $parts ) ) {
			return array( 'first' => '', 'last' => '' );
		}
		if ( 1 === count( $parts ) ) {
			return array( 'first' => '', 'last' => sanitize_text_field( (string) $parts[0] ) );
		}

		$last  = (string) array_pop( $parts );
		$first = trim( implode( ' ', $parts ) );

		return array(
			'first' => sanitize_text_field( $first ),
			'last'  => sanitize_text_field( $last ),
		);
	}

	private function resolve_entry_last_name( $entry ): string {
		return EntryDataNormalizer::resolve_last_name( $entry );
	}

	private function resolve_entry_first_name( $entry ): string {
		return EntryDataNormalizer::resolve_first_name( $entry );
	}

	private function resolve_entry_club( $entry ): string {
		return EntryDataNormalizer::resolve_club_name( $entry );
	}

	private function resolve_competition_context(): array {
		$sources = array(
			'competition_id_request' => isset( $_REQUEST['competition_id'] ) ? absint( $_REQUEST['competition_id'] ) : 0,
			'competition_id_get'     => isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0,
			'competition_id_post'    => isset( $_POST['competition_id'] ) ? absint( $_POST['competition_id'] ) : 0,
			'ufsc_competition_id'    => isset( $_REQUEST['ufsc_competition_id'] ) ? absint( $_REQUEST['ufsc_competition_id'] ) : 0,
		);
		foreach ( $sources as $source => $value ) {
			if ( $value > 0 ) {
				return array( 'competition_id' => $value, 'source' => $source );
			}
		}

		return array( 'competition_id' => 0, 'source' => 'none' );
	}
}
