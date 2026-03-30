<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Access\AccessResult;
use UFSC\Competitions\Access\CompetitionAccess;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;
use UFSC\Competitions\Repositories\CategoryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryFormRenderer {

	public static function render( array $context ): string {
		$competition              = $context['competition'] ?? null;
		$club_id                  = absint( $context['club_id'] ?? 0 );
		$entries                  = $context['entries'] ?? array();
		$editing_entry            = $context['editing_entry'] ?? null;
		$club_label               = (string) ( $context['club_label'] ?? '' );
		$registration_open        = (bool) ( $context['registration_open'] ?? true );
		$license_results          = $context['license_results'] ?? array();
		$selected_license         = $context['selected_license'] ?? null;
		$license_term             = (string) ( $context['license_term'] ?? '' );
		$license_number           = (string) ( $context['license_number'] ?? '' );
		$license_birthdate        = (string) ( $context['license_birthdate'] ?? '' );
		$license_id               = absint( $context['license_id'] ?? 0 );
		$return_url               = (string) ( $context['return_url'] ?? '' );
		$prefill                  = is_array( $context['prefill'] ?? null ) ? $context['prefill'] : array();
		$access_result            = ( $context['access_result'] ?? null ) instanceof AccessResult ? $context['access_result'] : null;
		$license_search_available = has_filter( 'ufsc_competitions_front_license_search_results' )
			|| has_filter( 'ufsc_competitions_front_license_by_id' );
		$license_notice = '';

		$repo = ( $context['entry_repo'] ?? null ) instanceof EntryFrontRepository
			? $context['entry_repo']
			: new EntryFrontRepository();

		if ( ! $competition ) {
			return '';
		}

		self::debug_log(
			'entry_form_render_context',
			array(
				'competition_id'        => (int) ( $competition->id ?? 0 ),
				'club_id'               => $club_id,
				'editing_entry_id'      => (int) ( $editing_entry->id ?? 0 ),
				'license_term'          => $license_term,
				'license_number'        => $license_number,
				'license_birthdate'     => $license_birthdate,
				'license_id'            => $license_id,
				'license_results_count' => is_array( $license_results ) ? count( $license_results ) : 0,
			)
		);

		if ( '' === $return_url ) {
			$return_url = Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) );
		}

		if ( ! empty( $license_results ) && count( $license_results ) > 1 && ! $license_id ) {
			$license_notice = __( 'Plusieurs licenciés correspondent à votre recherche. Vérifiez la date de naissance et sélectionnez la bonne personne avant de continuer.', 'ufsc-licence-competition' );
		}

		$workflow_status = $editing_entry ? EntriesWorkflow::normalize_status( (string) $repo->get_entry_status( $editing_entry ) ) : 'draft';
		$workflow_steps  = array(
			'draft'        => __( 'Brouillon', 'ufsc-licence-competition' ),
			'submitted'    => __( 'Soumise', 'ufsc-licence-competition' ),
			'approved'     => __( 'Approuvée', 'ufsc-licence-competition' ),
			'rejected'     => __( 'Rejetée', 'ufsc-licence-competition' ),
			'review_queue' => __( 'En validation', 'ufsc-licence-competition' ),
		);

		ob_start();
		?>
		<div class="ufsc-competition-entries" id="ufsc-inscriptions">
			<span id="ufsc-competition-entries"></span>

			<h3 class="ufsc-section-title"><?php echo esc_html__( 'Inscriptions', 'ufsc-licence-competition' ); ?></h3>

			<p class="ufsc-competition-entries-status">
				<?php if ( $registration_open ) : ?>
					<span class="ufsc-badge ufsc-badge-open"><?php echo esc_html__( 'Inscriptions ouvertes', 'ufsc-licence-competition' ); ?></span>
				<?php else : ?>
					<span class="ufsc-badge ufsc-badge-closed"><?php echo esc_html__( 'Inscriptions fermées', 'ufsc-licence-competition' ); ?></span>
				<?php endif; ?>
			</p>

			<div class="ufsc-entry-stepper ufsc-panel ufsc-panel--soft" aria-label="<?php esc_attr_e( 'Parcours inscription', 'ufsc-licence-competition' ); ?>">
				<span class="ufsc-entry-stepper__title"><?php echo esc_html__( 'Parcours inscription', 'ufsc-licence-competition' ); ?></span>
				<ol class="ufsc-entry-stepper__list">
					<li class="is-current"><?php echo esc_html__( '1. Recherche licencié UFSC', 'ufsc-licence-competition' ); ?></li>
					<li><?php echo esc_html__( '2. Vérification identité', 'ufsc-licence-competition' ); ?></li>
					<li><?php echo esc_html__( '3. Données sportives', 'ufsc-licence-competition' ); ?></li>
					<li><?php echo esc_html__( '4. Validation club', 'ufsc-licence-competition' ); ?></li>
				</ol>
				<p class="ufsc-entry-stepper__status">
					<?php echo esc_html__( 'Statut courant :', 'ufsc-licence-competition' ) . ' '; ?>
					<span class="ufsc-status-badge ufsc-status-badge--<?php echo esc_attr( $workflow_status ); ?>">
						<?php echo esc_html( $workflow_steps[ $workflow_status ] ?? EntriesWorkflow::get_status_label( $workflow_status ) ); ?>
					</span>
				</p>
			</div>

			<?php if ( ! $club_id ) : ?>
				<p><?php echo esc_html__( 'Accès réservé aux clubs affiliés.', 'ufsc-licence-competition' ); ?></p>
		</div>
				<?php
				return (string) ob_get_clean();
			endif;
			?>

			<div class="ufsc-competition-entries-list ufsc-panel">
				<h4><?php echo esc_html__( 'Vos inscriptions', 'ufsc-licence-competition' ); ?></h4>

				<?php
				$show_export = $club_id
					? (bool) apply_filters( 'ufsc_competitions_show_club_export', true, $competition, $club_id )
					: false;

				if ( $show_export ) :
					$export_url = wp_nonce_url(
						add_query_arg(
							array(
								'action'         => 'ufsc_competitions_export_club_csv',
								'competition_id' => (int) ( $competition->id ?? 0 ),
							),
							admin_url( 'admin-post.php' )
						),
						'ufsc_competitions_export_club_csv_' . (int) ( $competition->id ?? 0 )
					);
					?>
					<p class="ufsc-competition-entries-export">
						<a class="button" href="<?php echo esc_url( $export_url ); ?>">
							<?php echo esc_html__( 'Exporter CSV approuvées', 'ufsc-licence-competition' ); ?>
						</a>
					</p>
				<?php endif; ?>

				<?php
				$official_notice = '';
				if ( $club_id && ! empty( $entries ) && ! empty( $competition->event_start_datetime ) ) {
					$event_start = (string) $competition->event_start_datetime;
					$event_ts    = strtotime( $event_start );
					if ( $event_ts ) {
						$publish_ts   = $event_ts - ( 15 * DAY_IN_SECONDS );
						$publish_date = function_exists( 'ufsc_lc_format_datetime' )
							? ufsc_lc_format_datetime( date_i18n( 'Y-m-d H:i:s', $publish_ts ) )
							: date_i18n( 'Y-m-d H:i:s', $publish_ts );
						if ( current_time( 'timestamp' ) < $publish_ts ) {
							$official_notice = sprintf(
								/* translators: %s: date */
								__( 'La liste officielle sera publiée le %s.', 'ufsc-licence-competition' ),
								$publish_date
							);
						} else {
							$official_notice = __( 'La liste officielle est publiée.', 'ufsc-licence-competition' );
						}
					}
				}
				?>
				<?php if ( $official_notice ) : ?>
					<p class="ufsc-competition-official-list"><?php echo esc_html( $official_notice ); ?></p>
				<?php endif; ?>

				<?php
				$can_view_engaged_list = $access_result ? (bool) $access_result->can_view_engaged_list : true;
				$can_export_engaged    = $access_result ? (bool) $access_result->can_export_engaged : true;

				$engaged_status = isset( $_GET['ufsc_engaged_status'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_engaged_status'] ) ) : 'approved';
				if ( 'submitted' === $engaged_status ) {
					$engaged_status = 'review_queue';
				}
				if ( ! in_array( $engaged_status, array( 'approved', 'review_queue' ), true ) ) {
					$engaged_status = 'approved';
				}

				$engaged_view     = $can_view_engaged_list && isset( $_GET['ufsc_engaged_view'] ) ? absint( $_GET['ufsc_engaged_view'] ) : 0;
				$engaged_page     = isset( $_GET['ufsc_engaged_page'] ) ? max( 1, absint( $_GET['ufsc_engaged_page'] ) ) : 1;
				$engaged_per_page = 20;
				$engaged_total    = 0;
				$engaged_entries  = array();

				if ( $engaged_view ) {
					$engaged_total = $repo->count_by_competition(
						(int) ( $competition->id ?? 0 ),
						array(
							'status' => $engaged_status,
						)
					);
					$engaged_entries = $repo->list_by_competition(
						(int) ( $competition->id ?? 0 ),
						array(
							'status' => $engaged_status,
						),
						$engaged_per_page,
						( $engaged_page - 1 ) * $engaged_per_page
					);
				}

				$engaged_base_url = Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) );
				$engaged_base_url = $engaged_base_url ? $engaged_base_url : $return_url;
				$engaged_list_url = add_query_arg(
					array(
						'ufsc_engaged_view'   => 1,
						'ufsc_engaged_status' => $engaged_status,
					),
					$engaged_base_url
				);
				$engaged_list_url .= '#ufsc-engaged-list';

				$engaged_hide_url = $engaged_base_url . '#ufsc-engaged-list';

				$engaged_export_url = wp_nonce_url(
					add_query_arg(
						array(
							'action'         => 'ufsc_competitions_export_engaged_csv',
							'competition_id' => (int) ( $competition->id ?? 0 ),
							'status'         => $engaged_status,
						),
						admin_url( 'admin-post.php' )
					),
					'ufsc_competitions_export_engaged_csv_' . (int) ( $competition->id ?? 0 )
				);
				?>

				<?php if ( $can_view_engaged_list ) : ?>
					<div class="ufsc-competition-engaged-actions" id="ufsc-engaged-list">
						<?php if ( ! $engaged_view ) : ?>
							<a class="button" href="<?php echo esc_url( $engaged_list_url ); ?>">
								<?php echo esc_html__( 'Consulter la liste des licenciés engagés', 'ufsc-licence-competition' ); ?>
							</a>
						<?php else : ?>
							<a class="button" href="<?php echo esc_url( $engaged_hide_url ); ?>">
								<?php echo esc_html__( 'Masquer la liste des licenciés engagés', 'ufsc-licence-competition' ); ?>
							</a>
						<?php endif; ?>

						<?php if ( $can_export_engaged ) : ?>
							<a class="button" href="<?php echo esc_url( $engaged_export_url ); ?>">
								<?php echo esc_html__( 'Télécharger CSV des engagés', 'ufsc-licence-competition' ); ?>
							</a>
						<?php endif; ?>
					</div>
				<?php elseif ( $access_result && function_exists( 'ufsc_lc_render_access_denied_notice' ) ) : ?>
					<?php echo wp_kses_post( ufsc_lc_render_access_denied_notice( $access_result ) ); ?>
				<?php endif; ?>

				<?php if ( $engaged_view ) : ?>
					<div class="ufsc-competition-engaged-table ufsc-panel">
						<h4><?php echo esc_html__( 'Licenciés engagés', 'ufsc-licence-competition' ); ?></h4>
						<?php if ( empty( $engaged_entries ) ) : ?>
							<p><?php echo esc_html__( 'Aucun licencié engagé trouvé.', 'ufsc-licence-competition' ); ?></p>
						<?php else : ?>
							<table>
								<thead>
									<tr>
										<th><?php echo esc_html__( 'Nom / Prénom', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Club', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Poids', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Catégorie poids', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Niveau / Classe', 'ufsc-licence-competition' ); ?></th>
										<th><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $engaged_entries as $entry ) : ?>
										<?php
										$engaged_name = self::get_entry_value( $entry, array( 'athlete_name', 'full_name', 'name', 'licensee_name' ) );
										if ( '' === $engaged_name ) {
											$engaged_first = self::get_entry_value( $entry, array( 'first_name', 'firstname', 'prenom', 'licensee_first_name' ) );
											$engaged_last  = self::get_entry_value( $entry, array( 'last_name', 'lastname', 'nom', 'licensee_last_name' ) );
											$engaged_name  = trim( $engaged_first . ' ' . $engaged_last );
										}

										$engaged_birthdate    = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'licensee_birthdate' ) );
										$engaged_category     = self::resolve_category_label( $entry, $competition );
										$engaged_weight       = self::get_entry_value( $entry, array( 'weight', 'weight_kg', 'poids' ) );
										$engaged_weight_class = self::get_entry_value( $entry, array( 'weight_class', 'weight_cat', 'weight_category', 'weight_class_label', 'weight_category_label', 'weight_cat_label' ) );
										$engaged_level        = self::get_entry_value( $entry, array( 'level', 'class', 'classe' ) );
										$engaged_club         = (string) ( $entry->club_name ?? $club_label );
										$engaged_status_raw   = (string) $repo->get_entry_status( $entry );
										$engaged_status_norm  = EntriesWorkflow::normalize_status( $engaged_status_raw );
										$engaged_status_label = EntriesWorkflow::get_status_label( $engaged_status_norm );
										?>
										<tr>
											<td><?php echo esc_html( self::format_display_value( $engaged_name ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_club ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_birthdate ) ); ?></td>
											<td><?php echo wp_kses_post( self::format_value_with_empty_badge( $engaged_category, __( 'Non renseignée', 'ufsc-licence-competition' ) ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_weight ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_weight_class ) ); ?></td>
											<td><?php echo wp_kses_post( self::format_value_with_empty_badge( $engaged_level, __( 'Non défini', 'ufsc-licence-competition' ) ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_status_label ) ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<?php
							$total_pages = $engaged_total ? (int) ceil( $engaged_total / $engaged_per_page ) : 1;
							if ( $total_pages > 1 ) :
								?>
								<nav class="ufsc-competition-engaged-pagination">
									<?php for ( $page = 1; $page <= $total_pages; $page++ ) : ?>
										<?php
										$page_url = add_query_arg(
											array(
												'ufsc_engaged_view'   => 1,
												'ufsc_engaged_status' => $engaged_status,
												'ufsc_engaged_page'   => $page,
											),
											$engaged_base_url
										);
										$page_url .= '#ufsc-engaged-list';
										?>
										<a class="<?php echo $page === $engaged_page ? 'is-active' : ''; ?>" href="<?php echo esc_url( $page_url ); ?>">
											<?php echo esc_html( (string) $page ); ?>
										</a>
									<?php endfor; ?>
								</nav>
							<?php endif; ?>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( empty( $entries ) ) : ?>
					<div class="ufsc-empty-state">
						<p><?php echo esc_html__( 'Aucune inscription trouvée.', 'ufsc-licence-competition' ); ?></p>
						<a href="#ufsc-entry-form" class="button button-primary"><?php echo esc_html__( 'Ajouter une inscription', 'ufsc-licence-competition' ); ?></a>
					</div>
				<?php else : ?>
					<?php
					$license_keys = array(
						'license_number',
						'licence_number',
						'licensee_number',
						'licence',
						'license',
					);

					$license_id_keys = array( 'licensee_id', 'licence_id', 'license_id' );

					$get_license_number = static function( $entry ) use ( $license_keys, $license_id_keys, $club_id ): string {
						static $license_number_cache = array();

						$license_value = EntryFormRenderer::get_entry_value( $entry, $license_keys );
						if ( '' !== $license_value ) {
							return $license_value;
						}

						$license_id = 0;
						foreach ( $license_id_keys as $key ) {
							if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
								$license_id = absint( $entry->{$key} );
								if ( $license_id ) {
									break;
								}
							}
						}

						if ( ! $license_id ) {
							return '';
						}

						if ( ! array_key_exists( $license_id, $license_number_cache ) ) {
							$license_data = apply_filters( 'ufsc_competitions_front_license_by_id', null, $license_id, $club_id );

							if ( is_array( $license_data ) ) {
								$license_number_cache[ $license_id ] = (string) (
									$license_data['license_number']
									?? $license_data['licence_number']
									?? $license_data['licensee_number']
									?? $license_data['licence']
									?? $license_data['license']
									?? ''
								);
							} elseif ( is_scalar( $license_data ) ) {
								$license_number_cache[ $license_id ] = (string) $license_data;
							} else {
								$license_number_cache[ $license_id ] = '';
							}
						}

						return (string) $license_number_cache[ $license_id ];
					};

					$show_license_column = false;
					foreach ( $entries as $entry ) {
						$license_value = $get_license_number( $entry );
						if ( '' !== $license_value ) {
							$show_license_column = true;
							break;
						}
					}
					?>
					<div class="ufsc-competition-entries-table">
						<table>
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Nom / Prénom', 'ufsc-licence-competition' ); ?></th>
									<?php if ( $show_license_column ) : ?>
										<th><?php echo esc_html__( 'N° licence', 'ufsc-licence-competition' ); ?></th>
									<?php endif; ?>
									<th><?php echo esc_html__( 'Club', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Année', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Poids', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Catégorie poids', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Niveau / Classe', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Dernière maj.', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Actions', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $entries as $entry ) : ?>
									<?php
									$entry_id = absint( $entry->id ?? 0 );

									$status = function_exists( 'ufsc_lc_is_entry_eligible' )
										? (string) ( ufsc_lc_is_entry_eligible( $entry_id, 'front_club' )['status'] ?? '' )
										: (string) $repo->get_entry_status( $entry );

									$status = EntriesWorkflow::normalize_status( $status );

									$name = self::get_entry_value( $entry, array( 'athlete_name', 'full_name', 'name', 'licensee_name' ) );
									if ( '' === $name ) {
										$first = self::get_entry_value( $entry, array( 'first_name', 'firstname', 'prenom', 'licensee_first_name' ) );
										$last  = self::get_entry_value( $entry, array( 'last_name', 'lastname', 'nom', 'licensee_last_name' ) );
										$name  = trim( $first . ' ' . $last );
									}

									$birth_date     = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'licensee_birthdate' ) );
									$birth_year     = self::get_birth_year( $birth_date );
									$category       = self::resolve_category_label( $entry, $competition );
									$weight         = self::get_entry_value( $entry, array( 'weight', 'weight_kg', 'poids' ) );
									$weight_class   = self::get_entry_value( $entry, array( 'weight_class', 'weight_cat', 'weight_category', 'weight_class_label', 'weight_category_label', 'weight_cat_label' ) );
									$level          = self::get_entry_value( $entry, array( 'level', 'class', 'classe' ) );
									$license_number = $show_license_column ? $get_license_number( $entry ) : '';
									$club_name      = $club_label ?: (string) ( $entry->club_name ?? '' );

									$details_url = Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) );
									$edit_url    = $details_url ? add_query_arg( 'ufsc_entry_edit', $entry_id, $details_url ) : '';
									$edit_url    = $edit_url ? $edit_url . '#ufsc-entry-form' : '';

									$post_action    = admin_url( 'admin-post.php' );
									$delete_nonce   = wp_create_nonce( 'ufsc_competitions_entry_delete' );
									$submit_nonce   = wp_create_nonce( 'ufsc_entry_submit' );
									$withdraw_nonce = wp_create_nonce( 'ufsc_entry_withdraw' );

									$status_label = EntriesWorkflow::get_status_label( $status );
									$status_class = EntriesWorkflow::get_status_badge_class( $status );

									$updated_at = isset( $entry->updated_at ) ? (string) $entry->updated_at : '';
									if ( '' === $updated_at && isset( $entry->created_at ) ) {
										$updated_at = (string) $entry->created_at;
									}
									if ( function_exists( 'ufsc_lc_format_datetime' ) ) {
										$updated_at = ufsc_lc_format_datetime( $updated_at );
									}

									$rejected_reason = isset( $entry->rejected_reason ) ? (string) $entry->rejected_reason : '';

									$can_withdraw = (bool) apply_filters( 'ufsc_entries_can_withdraw', true, $entry, $competition, $club_id );
									$can_withdraw = $can_withdraw && in_array( $status, array( 'draft', 'submitted', 'pending', 'rejected' ), true );
									?>
									<tr>
										<td><?php echo esc_html( self::format_display_value( $name ) ); ?></td>
										<?php if ( $show_license_column ) : ?>
											<td><?php echo esc_html( self::format_display_value( $license_number ) ); ?></td>
										<?php endif; ?>
										<td><?php echo esc_html( self::format_display_value( $club_name ) ); ?></td>
										<td><?php echo esc_html( self::format_display_value( $birth_date ) ); ?></td>
										<td><?php echo esc_html( self::format_display_value( $birth_year ) ); ?></td>
										<td><?php echo wp_kses_post( self::format_value_with_empty_badge( $category, __( 'Non renseignée', 'ufsc-licence-competition' ) ) ); ?></td>
										<td><?php echo esc_html( self::format_display_value( $weight ) ); ?></td>
										<td><?php echo esc_html( self::format_display_value( $weight_class ) ); ?></td>
										<td><?php echo wp_kses_post( self::format_value_with_empty_badge( $level, __( 'Non défini', 'ufsc-licence-competition' ) ) ); ?></td>
										<td>
											<span class="ufsc-badge <?php echo esc_attr( $status_class ); ?>">
												<?php echo esc_html( $status_label ); ?>
											</span>

											<?php if ( 'draft' === $status ) : ?>
												<br /><small><?php echo esc_html__( 'Brouillon : non transmis à l’administration.', 'ufsc-licence-competition' ); ?></small>
											<?php elseif ( in_array( $status, array( 'submitted', 'pending' ), true ) ) : ?>
												<br /><small><?php echo esc_html__( 'En attente de validation par l’administration UFSC.', 'ufsc-licence-competition' ); ?></small>
											<?php elseif ( 'approved' === $status ) : ?>
												<br /><small><?php echo esc_html__( 'Validée : le licencié est engagé sur la compétition.', 'ufsc-licence-competition' ); ?></small>
											<?php elseif ( 'cancelled' === $status ) : ?>
												<br /><small><?php echo esc_html__( 'Annulée : inscription retirée du flux de validation.', 'ufsc-licence-competition' ); ?></small>
											<?php endif; ?>

											<?php if ( 'rejected' === $status && $rejected_reason ) : ?>
												<br /><small><?php echo esc_html( $rejected_reason ); ?></small>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( self::format_display_value( $updated_at ) ); ?></td>
										<td>
											<?php if ( 'approved' === $status || 'validated' === $status || 'valid' === $status || 'valide' === $status ) : ?>
												<span class="ufsc-entry-action-disabled ufsc-entry-action-disabled--locked">
													<?php echo esc_html__( 'Inscription validée — pour toute modification, contactez l’administration UFSC.', 'ufsc-licence-competition' ); ?>
												</span>
											<?php elseif ( $registration_open ) : ?>
												<?php if ( 'draft' === $status ) : ?>
													<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Modifier', 'ufsc-licence-competition' ); ?></a>

													<form method="post" action="<?php echo esc_url( $post_action ); ?>" class="ufsc-inline-form" style="display:inline;">
														<input type="hidden" name="action" value="ufsc_competitions_entry_delete" />
														<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
														<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
														<input type="hidden" name="ufsc_return_url" value="<?php echo esc_url( $return_url ); ?>" />
														<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>" />
														