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
		$notice               = $context['notice'] ?? '';
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

			<?php if ( $notice ) : ?>
				<?php echo self::render_notice( (string) $notice ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php endif; ?>

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
					<div class="ufsc-competition-entries-table">
						<table>
							<thead>
								<tr>
									<th><?php echo esc_html__( 'Nom / Prénom', 'ufsc-licence-competition' ); ?></th>
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

					<?php foreach ( $schema as $field ) : ?>
						<?php
						$field_name        = (string) ( $field['name'] ?? '' );
						$field_label       = (string) ( $field['label'] ?? '' );
						$field_type        = (string) ( $field['type'] ?? 'text' );
						$field_required    = ! empty( $field['required'] );
						$field_placeholder = (string) ( $field['placeholder'] ?? '' );
						$field_options     = is_array( $field['options'] ?? null ) ? $field['options'] : array();

						$field_columns = (array) ( $field['columns'] ?? array( $field_name ) );

						$value = $editing_entry
							? self::get_entry_value( $editing_entry, $field_columns )
							: (string) ( $prefill[ $field_name ] ?? '' );

						$is_locked_field    = $editing_entry && in_array( $field_name, $locked_fields, true );
						$is_field_disabled  = ( ! $registration_open ) || $editing_locked || $is_locked_field;
						$disabled_attr      = $is_field_disabled ? 'disabled' : '';
						?>

						<div class="ufsc-field">
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
								<input
									type="<?php echo esc_attr( $field_type ); ?>"
									id="ufsc-entry-<?php echo esc_attr( $field_name ); ?>"
									name="<?php echo esc_attr( $field_name ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									<?php if ( $field_placeholder ) : ?>placeholder="<?php echo esc_attr( $field_placeholder ); ?>"<?php endif; ?>
									<?php if ( $field_required ) : ?>required<?php endif; ?>
									<?php echo esc_attr( $disabled_attr ); ?>
								/>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<button type="submit" class="button" <?php echo ( $registration_open && ! $editing_locked ) ? '' : 'disabled'; ?>>
						<?php echo $editing_entry ? esc_html__( 'Mettre à jour', 'ufsc-licence-competition' ) : esc_html__( 'Ajouter', 'ufsc-licence-competition' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	private static function render_notice( string $notice ): string {
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

			'error'                    => array( 'error', __( 'Une erreur est survenue. Merci de réessayer.', 'ufsc-licence-competition' ) ),
			'error_forbidden'          => array( 'error', __( 'Action non autorisée.', 'ufsc-licence-competition' ) ),
			'error_invalid_fields'     => array( 'error', __( 'Champs invalides.', 'ufsc-licence-competition' ) ),
			'error_closed'             => array( 'error', __( 'Compétition fermée.', 'ufsc-licence-competition' ) ),
			'error_not_found'          => array( 'error', __( 'Inscription introuvable.', 'ufsc-licence-competition' ) ),
			'error_invalid_status'     => array( 'error', __( 'Statut invalide.', 'ufsc-licence-competition' ) ),
			'error_locked'             => array( 'error', __( 'Inscription verrouillée.', 'ufsc-licence-competition' ) ),

			'export_empty'             => array( 'warning', __( 'Aucune inscription validée à exporter.', 'ufsc-licence-competition' ) ),
			'error_export_unavailable' => array( 'error', __( 'Export indisponible. Merci de réessayer.', 'ufsc-licence-competition' ) ),

			// IMPORTANT: pas de notion paiement/quota ici (gérée dans un autre plugin).
			'error_quota'              => array( 'error', __( 'Quota atteint.', 'ufsc-licence-competition' ) ),
			'error_payment_required'   => array( 'error', __( 'Paiement requis.', 'ufsc-licence-competition' ) ),
		);

		// Si tu veux supprimer totalement ces 2 notices (quota/paiement) du plugin :
		// enlève les clés 'error_quota' et 'error_payment_required' ci-dessus, ET les éventuels redirects qui les utilisent.

		if ( ! isset( $messages[ $notice ] ) ) {
			return '';
		}

		list( $class, $message ) = $messages[ $notice ];

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
