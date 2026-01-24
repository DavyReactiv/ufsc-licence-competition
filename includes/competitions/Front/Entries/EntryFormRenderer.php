<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Front\Front;
use UFSC\Competitions\Front\Repositories\EntryFrontRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryFormRenderer {

	public static function render( array $context ): string {
		$competition          = $context['competition'] ?? null;
		$club_id              = absint( $context['club_id'] ?? 0 );
		$entries              = $context['entries'] ?? array();
		$editing_entry        = $context['editing_entry'] ?? null;
		$registration_open    = (bool) ( $context['registration_open'] ?? true );
		$license_results      = $context['license_results'] ?? array();
		$selected_license     = $context['selected_license'] ?? null;
		$license_term         = (string) ( $context['license_term'] ?? '' );
		$license_id           = absint( $context['license_id'] ?? 0 );
		$prefill              = is_array( $context['prefill'] ?? null ) ? $context['prefill'] : array();
		$license_block_available = ! empty( $license_results )
			|| ! empty( $selected_license )
			|| has_filter( 'ufsc_competitions_front_license_search_results' )
			|| has_filter( 'ufsc_competitions_front_license_by_id' );

		$repo = ( $context['entry_repo'] ?? null ) instanceof EntryFrontRepository
			? $context['entry_repo']
			: new EntryFrontRepository();

		if ( ! $competition ) {
			return '';
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
				// Export CSV validées (club) – visible uniquement si club_id résolu.
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
							<?php echo esc_html__( 'Exporter CSV validées', 'ufsc-licence-competition' ); ?>
						</a>
					</p>
				<?php endif; ?>

				<?php if ( empty( $entries ) ) : ?>
					<p><?php echo esc_html__( 'Aucune inscription trouvée.', 'ufsc-licence-competition' ); ?></p>
				<?php else : ?>
					<?php
					$license_keys = array( 'licensee_id', 'licence_id', 'license_id', 'license_number', 'licence_number', 'licence', 'licensee_number' );
					$show_license_column = false;
					foreach ( $entries as $entry ) {
						$license_value = self::get_entry_value( $entry, $license_keys );
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
									<th><?php echo esc_html__( 'Date de naissance', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Catégorie', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Poids', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Statut', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Dernière maj.', 'ufsc-licence-competition' ); ?></th>
									<th><?php echo esc_html__( 'Actions', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $entries as $entry ) : ?>
									<?php
									$entry_id        = absint( $entry->id ?? 0 );
									$status          = $repo->get_entry_status( $entry );
									$name            = self::get_entry_value( $entry, array( 'athlete_name', 'full_name', 'name' ) );

									if ( '' === $name ) {
										$first = self::get_entry_value( $entry, array( 'first_name', 'firstname', 'prenom' ) );
										$last  = self::get_entry_value( $entry, array( 'last_name', 'lastname', 'nom' ) );
										$name  = trim( $first . ' ' . $last );
									}

									$birth_date      = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) );
									$category        = self::get_entry_value( $entry, array( 'category', 'category_name' ) );
									$weight          = self::get_entry_value( $entry, array( 'weight', 'weight_kg', 'poids' ) );
									$license_number  = $show_license_column ? self::get_entry_value( $entry, $license_keys ) : '';

									$details_url = Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) );
									$edit_url    = $details_url ? add_query_arg( 'ufsc_entry_edit', $entry_id, $details_url ) : '';
									$edit_url    = $edit_url ? $edit_url . '#ufsc-entry-form' : '';

									$post_action     = admin_url( 'admin-post.php' );
									$delete_nonce    = wp_create_nonce( 'ufsc_competitions_entry_delete' );
									$submit_nonce    = wp_create_nonce( 'ufsc_entry_submit' );
									$withdraw_nonce  = wp_create_nonce( 'ufsc_entry_withdraw' );

									$status_label    = EntriesWorkflow::get_status_label( $status );
									$status_class    = EntriesWorkflow::get_status_badge_class( $status );
									$updated_at      = isset( $entry->updated_at ) ? (string) $entry->updated_at : '';
									$rejected_reason = isset( $entry->rejected_reason ) ? (string) $entry->rejected_reason : '';

									$can_withdraw = (bool) apply_filters( 'ufsc_entries_can_withdraw', true, $entry, $competition, $club_id );
									?>
									<tr>
										<td><?php echo esc_html( $name ); ?></td>
										<?php if ( $show_license_column ) : ?>
											<td><?php echo esc_html( $license_number ); ?></td>
										<?php endif; ?>
										<td><?php echo esc_html( $birth_date ); ?></td>
										<td><?php echo esc_html( $category ); ?></td>
										<td><?php echo esc_html( $weight ); ?></td>
										<td>
											<span class="ufsc-badge <?php echo esc_attr( $status_class ); ?>">
												<?php echo esc_html( $status_label ); ?>
											</span>

											<?php if ( 'submitted' === $status ) : ?>
												<br /><small><?php echo esc_html__( 'En attente validation', 'ufsc-licence-competition' ); ?></small>
											<?php endif; ?>

											<?php if ( 'rejected' === $status && $rejected_reason ) : ?>
												<br /><small><?php echo esc_html( $rejected_reason ); ?></small>
											<?php endif; ?>
										</td>
										<td><?php echo $updated_at ? esc_html( $updated_at ) : '—'; ?></td>
										<td>
											<?php if ( $registration_open ) : ?>
												<?php if ( 'draft' === $status ) : ?>
													<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Modifier', 'ufsc-licence-competition' ); ?></a>

													<form method="post" action="<?php echo esc_url( $post_action ); ?>" class="ufsc-inline-form" style="display:inline;">
														<input type="hidden" name="action" value="ufsc_competitions_entry_delete" />
														<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
														<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
														<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>" />
														<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Supprimer cette inscription ?', 'ufsc-licence-competition' ) ); ?>');">
															<?php echo esc_html__( 'Supprimer', 'ufsc-licence-competition' ); ?>
														</button>
													</form>

													<form method="post" action="<?php echo esc_url( $post_action ); ?>" class="ufsc-inline-form" style="display:inline;">
														<input type="hidden" name="action" value="ufsc_entry_submit" />
														<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
														<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
														<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $submit_nonce ); ?>" />
														<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Soumettre cette inscription ?', 'ufsc-licence-competition' ) ); ?>');">
															<?php echo esc_html__( 'Soumettre', 'ufsc-licence-competition' ); ?>
														</button>
													</form>

												<?php elseif ( in_array( $status, array( 'submitted', 'rejected' ), true ) ) : ?>
													<?php if ( $can_withdraw ) : ?>
														<form method="post" action="<?php echo esc_url( $post_action ); ?>" class="ufsc-inline-form" style="display:inline;">
															<input type="hidden" name="action" value="ufsc_entry_withdraw" />
															<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) ( $competition->id ?? 0 ) ); ?>" />
															<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
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
				$editing_status  = $editing_entry ? $repo->get_entry_status( $editing_entry ) : 'draft';
				$editing_locked  = $editing_entry && 'draft' !== $editing_status;
				$timeline_label  = EntriesWorkflow::get_timeline_label( $editing_status );
				?>

				<p class="ufsc-competition-entry-timeline">
					<strong><?php echo esc_html__( 'Workflow', 'ufsc-licence-competition' ); ?>:</strong>
					<?php echo esc_html( $timeline_label ); ?>
				</p>

				<?php if ( $license_block_available && $club_id ) : ?>
					<div class="ufsc-competition-license-prefill">
						<label><?php echo esc_html__( 'Licencié UFSC (pré-remplir)', 'ufsc-licence-competition' ); ?></label>

						<form method="get" action="<?php echo esc_url( Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) ) ); ?>#ufsc-inscriptions" class="ufsc-license-search-form">
							<input type="text" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" placeholder="<?php echo esc_attr__( 'Nom, prénom…', 'ufsc-licence-competition' ); ?>" />
							<?php if ( $editing_entry ) : ?>
								<input type="hidden" name="ufsc_entry_edit" value="<?php echo esc_attr( (int) ( $editing_entry->id ?? 0 ) ); ?>" />
							<?php endif; ?>
							<button type="submit" class="button"><?php echo esc_html__( 'Rechercher', 'ufsc-licence-competition' ); ?></button>
						</form>

						<?php if ( ! empty( $license_results ) ) : ?>
							<form method="get" action="<?php echo esc_url( Front::get_competition_details_url( (int) ( $competition->id ?? 0 ) ) ); ?>#ufsc-inscriptions" class="ufsc-license-select-form">
								<input type="hidden" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" />
								<?php if ( $editing_entry ) : ?>
									<input type="hidden" name="ufsc_entry_edit" value="<?php echo esc_attr( (int) ( $editing_entry->id ?? 0 ) ); ?>" />
								<?php endif; ?>

								<select name="ufsc_license_id">
									<option value=""><?php echo esc_html__( 'Sélectionner un licencié', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $license_results as $license_row ) : ?>
										<option value="<?php echo esc_attr( (int) ( $license_row['id'] ?? 0 ) ); ?>" <?php selected( (int) $license_id, (int) ( $license_row['id'] ?? 0 ) ); ?>>
											<?php echo esc_html( (string) ( $license_row['label'] ?? '' ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>

								<button type="submit" class="button"><?php echo esc_html__( 'Pré-remplir', 'ufsc-licence-competition' ); ?></button>
							</form>
						<?php endif; ?>
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
					<?php if ( $editing_entry ) : ?>
						<input type="hidden" name="entry_id" value="<?php echo esc_attr( (int) ( $editing_entry->id ?? 0 ) ); ?>" />
					<?php endif; ?>
					<?php if ( $license_id ) : ?>
						<input type="hidden" name="ufsc_license_id" value="<?php echo esc_attr( $license_id ); ?>" />
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
					$show_license_field = '' !== $license_number_value;

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
						$field_required    = ! empty( $field['required'] );
						$field_placeholder = (string) ( $field['placeholder'] ?? '' );
						$field_options     = is_array( $field['options'] ?? null ) ? $field['options'] : array();
						$field_section     = $section_by_field[ $field_name ] ?? '';

						$field_columns = (array) ( $field['columns'] ?? array( $field_name ) );

						$value = $editing_entry
							? self::get_entry_value( $editing_entry, $field_columns )
							: (string) ( $prefill[ $field_name ] ?? '' );

						$is_locked_field    = $editing_entry && in_array( $field_name, $locked_fields, true );
						$is_field_disabled  = ( ! $registration_open ) || $editing_locked || $is_locked_field;
						$disabled_attr      = $is_field_disabled ? 'disabled' : '';

						if ( 'license_number' === $field_name && ! $show_license_field ) {
							continue;
						}

						if ( 'weight' === $field_name && empty( $field_placeholder ) ) {
							$field_placeholder = __( 'ex: 67.5', 'ufsc-licence-competition' );
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
									<?php foreach ( $field_options as $option_value => $option_label ) : ?>
										<option value="<?php echo esc_attr( (string) $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>>
											<?php echo esc_html( (string) $option_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
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

		$messages = array(
			'created'                  => array( 'success', __( 'Inscription ajoutée.', 'ufsc-licence-competition' ) ),
			'updated'                  => array( 'success', __( 'Inscription modifiée.', 'ufsc-licence-competition' ) ),
			'deleted'                  => array( 'success', __( 'Inscription supprimée.', 'ufsc-licence-competition' ) ),

			'entry_created'            => array( 'success', __( 'Inscription ajoutée.', 'ufsc-licence-competition' ) ),
			'entry_updated'            => array( 'success', __( 'Inscription modifiée.', 'ufsc-licence-competition' ) ),
			'entry_deleted'            => array( 'success', __( 'Inscription supprimée.', 'ufsc-licence-competition' ) ),

			'entry_submitted'          => array( 'success', __( 'Inscription soumise.', 'ufsc-licence-competition' ) ),
			'entry_validated'          => array( 'success', __( 'Inscription validée.', 'ufsc-licence-competition' ) ),
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
			'error_locked'             => array( 'error', __( 'Inscription verrouillée.', 'ufsc-licence-competition' ) ),
			'error_quota'              => array( 'error', __( 'Quota atteint pour cette compétition.', 'ufsc-licence-competition' ) ),
			'error_payment_required'   => array( 'error', __( 'Action indisponible actuellement.', 'ufsc-licence-competition' ) ),

			'export_empty'             => array( 'info', __( 'Aucune inscription validée à exporter.', 'ufsc-licence-competition' ) ),
			'error_export_unavailable' => array( 'error', __( 'Export indisponible. Merci de réessayer.', 'ufsc-licence-competition' ) ),
			'entry_reopened'           => array( 'success', __( 'Inscription réouverte.', 'ufsc-licence-competition' ) ),
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
}
