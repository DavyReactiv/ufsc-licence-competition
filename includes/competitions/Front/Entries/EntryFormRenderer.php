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
		$competition               = $context['competition'] ?? null;
		$club_id                   = absint( $context['club_id'] ?? 0 );
		$entries                   = $context['entries'] ?? array();
		$editing_entry             = $context['editing_entry'] ?? null;
		$club_label                = (string) ( $context['club_label'] ?? '' );
		$registration_open         = (bool) ( $context['registration_open'] ?? true );
		$license_results           = $context['license_results'] ?? array();
		$selected_license          = $context['selected_license'] ?? null;
		$license_term              = (string) ( $context['license_term'] ?? '' );
		$license_number            = (string) ( $context['license_number'] ?? '' );
		$license_birthdate         = (string) ( $context['license_birthdate'] ?? '' );
		$license_id                = absint( $context['license_id'] ?? 0 );
		$return_url                = (string) ( $context['return_url'] ?? '' );
		$prefill                   = is_array( $context['prefill'] ?? null ) ? $context['prefill'] : array();
		$license_search_available  = has_filter( 'ufsc_competitions_front_license_search_results' )
			|| has_filter( 'ufsc_competitions_front_license_by_id' );
		$license_notice            = '';

		$repo = ( $context['entry_repo'] ?? null ) instanceof EntryFrontRepository
			? $context['entry_repo']
			: new EntryFrontRepository();

		if ( ! $competition ) {
			return '';
		}

		if ( '' === $return_url ) {
			$return_url = Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) );
		}

		if ( ! empty( $license_results ) && count( $license_results ) > 1 && ! $license_id ) {
			$license_notice = __( 'Plusieurs licenciés trouvés. Sélectionnez la bonne personne ci-dessous.', 'ufsc-licence-competition' );
		}

		ob_start();
		?>
		<div class="ufsc-competition-entries" id="ufsc-inscriptions">
			<span id="ufsc-competition-entries"></span>

			<h3><?php echo esc_html__( 'Inscriptions', 'ufsc-licence-competition' ); ?></h3>

			<p class="ufsc-competition-entries-status">
				<?php if ( $registration_open ) : ?>
					<span class="ufsc-badge ufsc-badge-open"><?php echo esc_html__( 'Inscriptions ouvertes', 'ufsc-licence-competition' ); ?></span>
				<?php else : ?>
					<span class="ufsc-badge ufsc-badge-closed"><?php echo esc_html__( 'Inscriptions fermées', 'ufsc-licence-competition' ); ?></span>
				<?php endif; ?>
			</p>

			<?php if ( ! $club_id ) : ?>
				<p><?php echo esc_html__( 'Accès réservé aux clubs affiliés.', 'ufsc-licence-competition' ); ?></p>
			</div>
			<?php
			return (string) ob_get_clean();
			endif;
			?>

			<div class="ufsc-competition-entries-list">
				<h4><?php echo esc_html__( 'Vos inscriptions', 'ufsc-licence-competition' ); ?></h4>

				<?php
				// Export CSV approuvées (club) – visible uniquement si club_id résolu.
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
				$engaged_status = isset( $_GET['ufsc_engaged_status'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_engaged_status'] ) ) : 'approved';
				if ( ! in_array( $engaged_status, array( 'approved', 'submitted' ), true ) ) {
					$engaged_status = 'approved';
				}

				$engaged_view     = isset( $_GET['ufsc_engaged_view'] ) ? absint( $_GET['ufsc_engaged_view'] ) : 0;
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

					<a class="button" href="<?php echo esc_url( $engaged_export_url ); ?>">
						<?php echo esc_html__( 'Télécharger CSV des engagés', 'ufsc-licence-competition' ); ?>
					</a>
				</div>

				<?php if ( $engaged_view ) : ?>
					<div class="ufsc-competition-engaged-table">
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
										$engaged_club         = (string) ( $entry->club_name ?? $club_label );
										$engaged_status_raw   = (string) $repo->get_entry_status( $entry );
										$engaged_status_norm  = EntriesWorkflow::normalize_status( $engaged_status_raw );
										$engaged_status_label = EntriesWorkflow::get_status_label( $engaged_status_norm );
										?>
										<tr>
											<td><?php echo esc_html( self::format_display_value( $engaged_name ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_club ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_birthdate ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_category ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_weight ) ); ?></td>
											<td><?php echo esc_html( self::format_display_value( $engaged_weight_class ) ); ?></td>
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
					<p><?php echo esc_html__( 'Aucune inscription trouvée.', 'ufsc-licence-competition' ); ?></p>
				<?php else : ?>
					<?php
					// Compatibilité large : différentes sources / versions peuvent exposer des clés différentes.
					$license_keys = array(
						'license_number',
						'licence_number',
						'licensee_number', // certaines sources exposent cette clé
						'licence',         // fallback legacy
						'license',         // fallback legacy
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
									<th><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Dernière maj.', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Actions', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $entries as $entry ) : ?>
									<?php
									$entry_id = absint( $entry->id ?? 0 );

									$status = function_exists( 'ufsc_is_entry_eligible' )
										? (string) ( ufsc_is_entry_eligible( $entry_id, 'front_club' )['status'] ?? '' )
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
										<td><?php echo esc_html( self::format_display_value( $category ) ); ?></td>
										<td><?php echo esc_html( self::format_display_value( $weight ) ); ?></td>
										<td><?php echo esc_html( self::format_display_value( $weight_class ) ); ?></td>
										<td>
											<span class="ufsc-badge <?php echo esc_attr( $status_class ); ?>">
												<?php echo esc_html( $status_label ); ?>
											</span>

											<?php if ( in_array( $status, array( 'submitted', 'pending' ), true ) ) : ?>
												<br /><small><?php echo esc_html__( 'En attente validation', 'ufsc-licence-competition' ); ?></small>
											<?php endif; ?>

											<?php if ( 'rejected' === $status && $rejected_reason ) : ?>
												<br /><small><?php echo esc_html( $rejected_reason ); ?></small>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( self::format_display_value( $updated_at ) ); ?></td>
										<td>
											<?php if ( 'approved' === $status || 'validated' === $status || 'valid' === $status || 'valide' === $status ) : ?>
												<span class="ufsc-entry-action-disabled">
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
														<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Supprimer cette inscription ?', 'ufsc-licence-competition' ) ); ?>');">
															<?php echo esc_html__( 'Supprimer', 'ufsc-licence-competition' ); ?>
														</button>
													</form>

													<form method="post" action="<?php echo esc_url( $post_action ); ?>" class="ufsc-inline-form" style="display:inline;">
														<input type="hidden" name="action" value="ufsc_entry_submit" />
														<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
														<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
														<input type="hidden" name="ufsc_return_url" value="<?php echo esc_url( $return_url ); ?>" />
														<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $submit_nonce ); ?>" />
														<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Soumettre cette inscription ?', 'ufsc-licence-competition' ) ); ?>');">
															<?php echo esc_html__( 'Soumettre', 'ufsc-licence-competition' ); ?>
														</button>
													</form>

												<?php elseif ( in_array( $status, array( 'submitted', 'pending', 'rejected' ), true ) ) : ?>
													<?php if ( $can_withdraw ) : ?>
														<form method="post" action="<?php echo esc_url( $post_action ); ?>" class="ufsc-inline-form" style="display:inline;">
															<input type="hidden" name="action" value="ufsc_entry_withdraw" />
															<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
															<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
															<input type="hidden" name="ufsc_return_url" value="<?php echo esc_url( $return_url ); ?>" />
															<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $withdraw_nonce ); ?>" />
															<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Retirer cette inscription ?', 'ufsc-licence-competition' ) ); ?>');">
																<?php echo 'rejected' === $status ? esc_html__( 'Ré-éditer', 'ufsc-licence-competition' ) : esc_html__( 'Retirer', 'ufsc-licence-competition' ); ?>
															</button>
														</form>
													<?php else : ?>
														<span class="ufsc-entry-action-disabled">—</span>
													<?php endif; ?>
												<?php else : ?>
													<span class="ufsc-entry-action-disabled">—</span>
												<?php endif; ?>
											<?php else : ?>
												<span class="ufsc-entry-action-disabled">—</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>

			<div class="ufsc-competition-entry-form" id="ufsc-entry-form">
				<h4>
					<?php echo $editing_entry ? esc_html__( 'Modifier une inscription', 'ufsc-licence-competition' ) : esc_html__( 'Ajouter une inscription', 'ufsc-licence-competition' ); ?>
				</h4>

				<?php
				$editing_status = $editing_entry ? (string) $repo->get_entry_status( $editing_entry ) : 'draft';
				$editing_status = EntriesWorkflow::normalize_status( $editing_status );

				$editing_locked = $editing_entry && 'draft' !== $editing_status;
				$timeline_label = EntriesWorkflow::get_timeline_label( $editing_status );
				?>

				<p class="ufsc-competition-entry-timeline">
					<strong><?php echo esc_html__( 'Workflow', 'ufsc-licence-competition' ); ?>:</strong>
					<?php echo esc_html( $timeline_label ); ?>
				</p>

				<?php if ( $club_id ) : ?>
					<div class="ufsc-competition-license-prefill">
						<label><?php echo esc_html__( 'Licencié UFSC (pré-remplir)', 'ufsc-licence-competition' ); ?></label>

						<?php if ( $license_search_available ) : ?>
							<form method="get" action="<?php echo esc_url( Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) ) ); ?>#ufsc-inscriptions" class="ufsc-license-search-form">
								<input type="text" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" placeholder="<?php echo esc_attr__( 'Nom, prénom…', 'ufsc-licence-competition' ); ?>" />
								<input type="text" name="ufsc_license_number" value="<?php echo esc_attr( $license_number ); ?>" placeholder="<?php echo esc_attr__( 'N° licence (si connu)', 'ufsc-licence-competition' ); ?>" />
								<input type="text" name="ufsc_license_birthdate" value="<?php echo esc_attr( $license_birthdate ); ?>" placeholder="<?php echo esc_attr__( 'Date de naissance (JJ/MM/AAAA)', 'ufsc-licence-competition' ); ?>" />
								<?php if ( $editing_entry ) : ?>
									<input type="hidden" name="ufsc_entry_edit" value="<?php echo esc_attr( (int) ( $editing_entry->id ?? 0 ) ); ?>" />
								<?php endif; ?>
								<button type="submit" class="button"><?php echo esc_html__( 'Rechercher', 'ufsc-licence-competition' ); ?></button>
							</form>
						<?php else : ?>
							<p class="description"><?php echo esc_html__( 'Recherche de licence indisponible.', 'ufsc-licence-competition' ); ?></p>
						<?php endif; ?>

						<?php if ( $license_notice ) : ?>
							<p class="description"><?php echo esc_html( $license_notice ); ?></p>
						<?php endif; ?>

						<p class="ufsc-license-search-feedback" aria-live="polite"></p>

						<?php
						$has_license_results = ! empty( $license_results );
						$select_form_style   = $has_license_results ? '' : 'style="display:none;"';
						?>
						<form method="get" action="<?php echo esc_url( Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) ) ); ?>#ufsc-inscriptions" class="ufsc-license-select-form" <?php echo $select_form_style; ?>>
							<input type="hidden" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" />
							<input type="hidden" name="ufsc_license_number" value="<?php echo esc_attr( $license_number ); ?>" />
							<input type="hidden" name="ufsc_license_birthdate" value="<?php echo esc_attr( $license_birthdate ); ?>" />
							<?php if ( $editing_entry ) : ?>
								<input type="hidden" name="ufsc_entry_edit" value="<?php echo esc_attr( (int) ( $editing_entry->id ?? 0 ) ); ?>" />
							<?php endif; ?>

							<select name="ufsc_license_id">
								<option value=""><?php echo esc_html__( 'Sélectionner un licencié', 'ufsc-licence-competition' ); ?></option>
								<?php if ( $has_license_results ) : ?>
									<?php foreach ( $license_results as $license_row ) : ?>
										<option value="<?php echo esc_attr( (int) ( $license_row['id'] ?? 0 ) ); ?>" <?php selected( (int) $license_id, (int) ( $license_row['id'] ?? 0 ) ); ?>>
											<?php echo esc_html( (string) ( $license_row['label'] ?? '' ) ); ?>
										</option>
									<?php endforeach; ?>
								<?php endif; ?>
							</select>

							<button type="submit" class="button"><?php echo esc_html__( 'Pré-remplir', 'ufsc-licence-competition' ); ?></button>
						</form>
					</div>
				<?php endif; ?>

				<?php if ( ! $registration_open ) : ?>
					<p class="ufsc-competition-entries-closed"><?php echo esc_html__( 'Les inscriptions sont fermées pour cette compétition.', 'ufsc-licence-competition' ); ?></p>
				<?php endif; ?>

				<?php if ( $editing_locked ) : ?>
					<p class="ufsc-competition-entries-locked">
						<?php echo esc_html__( 'Cette inscription est verrouillée. Retirez ou ré-éditez-la pour la modifier.', 'ufsc-licence-competition' ); ?>
					</p>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $editing_entry ? 'ufsc_competitions_entry_update' : 'ufsc_competitions_entry_create' ); ?>" />
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
					<input type="hidden" name="ufsc_return_url" value="<?php echo esc_url( $return_url ); ?>" />
					<?php if ( $editing_entry ) : ?>
						<input type="hidden" name="entry_id" value="<?php echo esc_attr( (int) ( $editing_entry->id ?? 0 ) ); ?>" />
					<?php endif; ?>
					<input type="hidden" name="ufsc_license_id" value="<?php echo esc_attr( $license_id ); ?>" />
					<?php if ( $license_term ) : ?>
						<input type="hidden" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" />
					<?php endif; ?>
					<?php if ( $license_number ) : ?>
						<input type="hidden" name="ufsc_license_number" value="<?php echo esc_attr( $license_number ); ?>" />
					<?php endif; ?>
					<?php if ( $license_birthdate ) : ?>
						<input type="hidden" name="ufsc_license_birthdate" value="<?php echo esc_attr( $license_birthdate ); ?>" />
					<?php endif; ?>

					<?php wp_nonce_field( $editing_entry ? 'ufsc_competitions_entry_update' : 'ufsc_competitions_entry_create' ); ?>

					<?php
					$schema = EntriesModule::get_fields_schema( $competition );

					$locked_fields = array();
					if ( $editing_entry && 'submitted' === $editing_status ) {
						$locked_fields = array_map(
							static function( $entry_field ) {
								return $entry_field['name'] ?? '';
							},
							$schema
						);

						$locked_fields = apply_filters( 'ufsc_entries_lock_fields_on_submitted', $locked_fields, $editing_entry, $competition );
						$locked_fields = array_filter( array_map( 'sanitize_key', (array) $locked_fields ) );
					}
					?>

					<?php
					$license_number_value = '';
					if ( $editing_entry ) {
						$license_number_value = self::get_entry_value(
							$editing_entry,
							array( 'license_number', 'licence_number', 'licensee_number', 'licence', 'license' )
						);
					} else {
						$license_number_value = (string) ( $prefill['license_number'] ?? '' );
					}

					if ( '' === $license_number_value && ! $editing_entry ) {
						$license_number_value = (string) ( $selected_license['license_number'] ?? $selected_license['licence_number'] ?? $selected_license['licensee_number'] ?? '' );
					}

					$license_number_selected = $club_id && ( $license_id || ! empty( $selected_license ) );
					$show_license_field      = $license_number_selected || '' !== $license_number_value;

					$section_titles = array(
						'identity' => __( 'Identité compétiteur', 'ufsc-licence-competition' ),
						'category' => __( 'Données sportives', 'ufsc-licence-competition' ),
					);

					$section_by_field = array(
						'first_name'     => 'identity',
						'last_name'      => 'identity',
						'license_number' => 'identity',
						'birth_date'     => 'identity',
						'sex'            => 'identity',
						'weight'         => 'category',
						'weight_class'   => 'category',
						'category'       => 'category',
						'level'          => 'category',
					);

					$current_section = '';
					?>

					<?php foreach ( $schema as $field ) : ?>
						<?php
						$field_name        = (string) ( $field['name'] ?? '' );
						$field_label       = (string) ( $field['label'] ?? '' );
						$field_type        = (string) ( $field['type'] ?? 'text' );
						$is_readonly       = ! empty( $field['readonly'] );
						$field_required    = ! empty( $field['required'] ) && ! $is_readonly;
						$field_placeholder = (string) ( $field['placeholder'] ?? '' );
						$field_options     = is_array( $field['options'] ?? null ) ? $field['options'] : array();
						$field_section     = $section_by_field[ $field_name ] ?? '';

						$field_columns = (array) ( $field['columns'] ?? array( $field_name ) );

						$value = $editing_entry
							? self::get_entry_value( $editing_entry, $field_columns )
							: (string) ( $prefill[ $field_name ] ?? '' );

						$is_locked_field   = $editing_entry && in_array( $field_name, $locked_fields, true );
						$is_field_disabled = ( ! $registration_open ) || $editing_locked || $is_locked_field || $is_readonly;
						$disabled_attr     = $is_field_disabled ? 'disabled' : '';

						if ( 'license_number' === $field_name && ! $show_license_field ) {
							continue;
						}

						if ( 'weight' === $field_name && empty( $field_placeholder ) ) {
							$field_placeholder = __( 'ex: 67.5', 'ufsc-licence-competition' );
						}
						if ( 'license_number' === $field_name && $license_number_selected && '' === $value ) {
							$field_placeholder = __( 'Non disponible', 'ufsc-licence-competition' );
						}

						if ( $field_section && $field_section !== $current_section ) {
							if ( '' !== $current_section ) {
								echo '</div>';
							}
							$current_section = $field_section;
							echo '<div class="ufsc-entries-section">';
							echo '<h5>' . esc_html( $section_titles[ $field_section ] ?? $field_section ) . '</h5>';
						}
						?>

						<div class="ufsc-field <?php echo esc_attr( 'weight' === $field_name ? 'ufsc-field-weight' : '' ); ?>">
							<label for="ufsc-entry-<?php echo esc_attr( $field_name ); ?>">
								<?php echo esc_html( $field_label ); ?>
								<?php if ( $field_required ) : ?> <span class="required">*</span><?php endif; ?>
							</label>

							<?php if ( 'select' === $field_type ) : ?>
								<select id="ufsc-entry-<?php echo esc_attr( $field_name ); ?>" name="<?php echo esc_attr( $field_name ); ?>" <?php echo esc_attr( $disabled_attr ); ?>>
									<option value=""><?php echo esc_html__( '—', 'ufsc-licence-competition' ); ?></option>
									<?php if ( '' !== $value && ! array_key_exists( $value, $field_options ) ) : ?>
										<option value="<?php echo esc_attr( $value ); ?>" selected>
											<?php echo esc_html( $value ); ?>
										</option>
									<?php endif; ?>
									<?php foreach ( $field_options as $option_value => $option_label ) : ?>
										<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>>
											<?php echo esc_html( (string) $option_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( 'category' === $field_name ) : ?>
									<small class="ufsc-entry-category-status" aria-live="polite"></small>
								<?php endif; ?>
								<?php if ( 'weight_class' === $field_name ) : ?>
									<small class="ufsc-entry-weight-status" aria-live="polite"></small>
								<?php endif; ?>
							<?php else : ?>
								<span class="ufsc-field-input">
									<input
										type="<?php echo esc_attr( $field_type ); ?>"
										id="ufsc-entry-<?php echo esc_attr( $field_name ); ?>"
										name="<?php echo esc_attr( $field_name ); ?>"
										value="<?php echo esc_attr( $value ); ?>"
										<?php if ( $field_placeholder ) : ?>placeholder="<?php echo esc_attr( $field_placeholder ); ?>"<?php endif; ?>
										<?php if ( $field_required ) : ?>required<?php endif; ?>
										<?php echo esc_attr( $disabled_attr ); ?>
										<?php echo 'weight' === $field_name ? 'style="max-width:140px;" step="0.1" min="0" inputmode="decimal" pattern="[0-9]+([\\.,][0-9]+)?"' : ''; ?>
									/>
									<?php if ( 'weight' === $field_name ) : ?>
										<span class="ufsc-field-suffix"><?php echo esc_html__( 'kg', 'ufsc-licence-competition' ); ?></span>
									<?php endif; ?>
									<?php if ( 'category' === $field_name ) : ?>
										<small class="ufsc-entry-category-status" aria-live="polite"></small>
									<?php endif; ?>
									<?php if ( 'license_number' === $field_name && $license_number_selected && '' === $value ) : ?>
										<small class="description"><?php echo esc_html__( 'Le numéro n’est pas remonté par la base licences.', 'ufsc-licence-competition' ); ?></small>
									<?php endif; ?>
								</span>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<?php if ( '' !== $current_section ) : ?>
						</div>
					<?php endif; ?>

					<button type="submit" class="button" <?php echo ( $registration_open && ! $editing_locked ) ? '' : 'disabled'; ?>>
						<?php echo $editing_entry ? esc_html__( 'Mettre à jour', 'ufsc-licence-competition' ) : esc_html__( 'Ajouter', 'ufsc-licence-competition' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	public static function render_notice( string $notice ): string {
		$notice_key = sanitize_key( $notice );
		if ( '' === $notice_key ) {
			return '';
		}

		$show_notice = (bool) apply_filters( 'ufsc_competitions_front_show_notices', true, $notice_key );
		if ( ! $show_notice ) {
			return '';
		}

		if ( 'access_denied' === $notice_key ) {
			$reason = isset( $_GET['ufsc_access_reason'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_access_reason'] ) ) : '';
			$scope = isset( $_GET['ufsc_access_scope'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_access_scope'] ) ) : 'register';
			if ( $reason ) {
				$result = AccessResult::deny(
					$reason,
					array(
						'scope' => $scope,
					)
				);
				if ( function_exists( 'ufsc_render_access_denied_notice' ) ) {
					return (string) ufsc_render_access_denied_notice( $result );
				}
				if ( class_exists( CompetitionAccess::class ) ) {
					$access = new CompetitionAccess();
					return sprintf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html( $access->get_denied_message( $result ) )
					);
				}
			}

			return sprintf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Accès refusé.', 'ufsc-licence-competition' )
			);
		}

		$messages = array(
			'created'                  => array( 'success', __( 'Inscription ajoutée.', 'ufsc-licence-competition' ) ),
			'updated'                  => array( 'success', __( 'Inscription modifiée.', 'ufsc-licence-competition' ) ),
			'deleted'                  => array( 'success', __( 'Inscription supprimée.', 'ufsc-licence-competition' ) ),

			'entry_created'            => array( 'success', __( 'Inscription ajoutée.', 'ufsc-licence-competition' ) ),
			'entry_updated'            => array( 'success', __( 'Inscription modifiée.', 'ufsc-licence-competition' ) ),
			'entry_deleted'            => array( 'success', __( 'Inscription supprimée.', 'ufsc-licence-competition' ) ),

			'entry_submitted'          => array( 'success', __( 'Inscription soumise.', 'ufsc-licence-competition' ) ),
			'entry_validated'          => array( 'success', __( 'Inscription approuvée.', 'ufsc-licence-competition' ) ),
			'entry_rejected'           => array( 'success', __( 'Inscription rejetée.', 'ufsc-licence-competition' ) ),
			'entry_withdrawn'          => array( 'success', __( 'Inscription retirée.', 'ufsc-licence-competition' ) ),
			'entry_cancelled'          => array( 'success', __( 'Inscription annulée.', 'ufsc-licence-competition' ) ),
			'entry_reopened'           => array( 'success', __( 'Inscription réouverte.', 'ufsc-licence-competition' ) ),

			'error'                    => array( 'error', __( 'Une erreur est survenue. Merci de réessayer.', 'ufsc-licence-competition' ) ),
			'error_forbidden'          => array( 'error', __( 'Accès refusé.', 'ufsc-licence-competition' ) ),
			'error_invalid_fields'     => array( 'error', __( 'Champs invalides.', 'ufsc-licence-competition' ) ),
			'error_closed'             => array( 'error', __( 'Compétition fermée.', 'ufsc-licence-competition' ) ),
			'error_not_found'          => array( 'error', __( 'Compétition introuvable.', 'ufsc-licence-competition' ) ),
			'error_invalid_status'     => array( 'error', __( 'Statut invalide.', 'ufsc-licence-competition' ) ),
			'error_withdraw_approved'  => array( 'error', __( 'Inscription validée — pour toute modification, contactez l’administration UFSC.', 'ufsc-licence-competition' ) ),
			'error_locked'             => array( 'error', __( 'Inscription verrouillée.', 'ufsc-licence-competition' ) ),
			'error_quota'              => array( 'error', __( 'Quota atteint pour cette compétition.', 'ufsc-licence-competition' ) ),
			'error_payment_required'   => array( 'error', __( 'Action indisponible actuellement.', 'ufsc-licence-competition' ) ),
			'error_weight_required'    => array( 'error', __( 'Veuillez renseigner le poids avant validation.', 'ufsc-licence-competition' ) ),

			'export_empty'             => array( 'info', __( 'Aucune inscription approuvée à exporter.', 'ufsc-licence-competition' ) ),
			'error_export_unavailable' => array( 'error', __( 'Export indisponible. Merci de réessayer.', 'ufsc-licence-competition' ) ),
		);

		$messages = apply_filters( 'ufsc_competitions_front_notice_map', $messages );
		if ( ! is_array( $messages ) ) {
			$messages = array();
		}

		if ( ! isset( $messages[ $notice_key ] ) ) {
			if ( 0 === strpos( $notice_key, 'success_' ) ) {
				return sprintf(
					'<div class="notice notice-success"><p>%s</p></div>',
					esc_html__( 'Opération réalisée avec succès.', 'ufsc-licence-competition' )
				);
			}
			return '';
		}

		list( $class, $message ) = $messages[ $notice_key ];

		return sprintf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	private static function get_entry_value( $entry, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $entry->{$key} ) && '' !== (string) $entry->{$key} ) {
				return (string) $entry->{$key};
			}
		}

		return '';
	}

	private static function resolve_category_label( $entry, $competition = null ): string {
		$label = self::get_entry_value( $entry, array( 'category', 'category_name', 'category_label', 'category_title' ) );
		if ( '' !== $label ) {
			return $label;
		}

		$category_id = absint( $entry->category_id ?? 0 );
		if ( $category_id && class_exists( CategoryRepository::class ) ) {
			static $cache = array();
			if ( ! array_key_exists( $category_id, $cache ) ) {
				$repo = new CategoryRepository();
				$category = $repo->get( $category_id, true );
				$cache[ $category_id ] = $category ? (string) ( $category->name ?? '' ) : '';
			}

			return (string) $cache[ $category_id ];
		}

		$birth_date = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob', 'licensee_birthdate' ) );
		if ( '' !== $birth_date && function_exists( 'ufsc_lc_compute_category_from_birthdate' ) ) {
			$season_end_year = '';
			if ( is_object( $competition ) && isset( $competition->season ) ) {
				$season_end_year = (string) $competition->season;
			}

			if ( '' !== $season_end_year ) {
				$computed = ufsc_lc_compute_category_from_birthdate( $birth_date, $season_end_year );
				if ( '' !== $computed ) {
					return $computed;
				}
			}
		}

		return '';
	}

	private static function format_display_value( $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = trim( $value );

		return '' !== $value ? $value : '—';
	}

	private static function get_birth_year( string $birthdate ): string {
		$birthdate = trim( $birthdate );
		if ( preg_match( '/^(\\d{4})-\\d{2}-\\d{2}$/', $birthdate, $matches ) ) {
			return $matches[1];
		}
		if ( preg_match( '/^(\\d{2})\\/(\\d{2})\\/(\\d{4})$/', $birthdate, $matches ) ) {
			return $matches[3];
		}

		return '';
	}
}
