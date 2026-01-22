<?php

namespace UFSC\Competitions\Front\Entries;

use UFSC\Competitions\Front\Front;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EntryFormRenderer {
	public static function render( array $context ): string {
		$competition = $context['competition'] ?? null;
		$club_id = absint( $context['club_id'] ?? 0 );
		$entries = $context['entries'] ?? array();
		$editing_entry = $context['editing_entry'] ?? null;
		$notice = $context['notice'] ?? '';
		$registration_open = (bool) ( $context['registration_open'] ?? true );
		$license_results = $context['license_results'] ?? array();
		$selected_license = $context['selected_license'] ?? null;
		$license_term = (string) ( $context['license_term'] ?? '' );
		$license_id = absint( $context['license_id'] ?? 0 );
		$prefill = is_array( $context['prefill'] ?? null ) ? $context['prefill'] : array();
		$license_block_available = ! empty( $license_results ) || ! empty( $selected_license ) || has_filter( 'ufsc_competitions_front_license_search_results' ) || has_filter( 'ufsc_competitions_front_license_by_id' );

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
				<?php echo self::render_notice( $notice ); ?>
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
									<th><?php echo esc_html__( 'Actions', 'ufsc-licence-competition' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $entries as $entry ) : ?>
									<?php
										$entry_id = absint( $entry->id ?? 0 );
										$name = self::get_entry_value( $entry, array( 'athlete_name', 'full_name', 'name' ) );
										if ( '' === $name ) {
											$first = self::get_entry_value( $entry, array( 'first_name', 'firstname', 'prenom' ) );
											$last = self::get_entry_value( $entry, array( 'last_name', 'lastname', 'nom' ) );
											$name = trim( $first . ' ' . $last );
										}
										$birth_date = self::get_entry_value( $entry, array( 'birth_date', 'birthdate', 'date_of_birth', 'dob' ) );
										$category = self::get_entry_value( $entry, array( 'category', 'category_name' ) );
										$weight = self::get_entry_value( $entry, array( 'weight', 'weight_kg', 'poids' ) );
										$edit_url = add_query_arg( 'ufsc_entry_edit', $entry_id, Front::get_competition_details_url( (int) $competition->id ) );
										$edit_url = $edit_url ? $edit_url . '#ufsc-entry-form' : '';
										$delete_action = admin_url( 'admin-post.php' );
										$delete_nonce = wp_create_nonce( 'ufsc_competitions_entry_delete' );
									?>
									<tr>
										<td><?php echo esc_html( $name ); ?></td>
										<td><?php echo esc_html( $birth_date ); ?></td>
										<td><?php echo esc_html( $category ); ?></td>
										<td><?php echo esc_html( $weight ); ?></td>
										<td>
											<?php if ( $registration_open ) : ?>
												<a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html__( 'Modifier', 'ufsc-licence-competition' ); ?></a>
												<form method="post" action="<?php echo esc_url( $delete_action ); ?>" class="ufsc-inline-form" style="display:inline;">
													<input type="hidden" name="action" value="ufsc_competitions_entry_delete" />
													<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) $competition->id ); ?>" />
													<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
													<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $delete_nonce ); ?>" />
													<button type="submit" class="button-link" onclick="return confirm('<?php echo esc_js( __( 'Supprimer cette inscription ?', 'ufsc-licence-competition' ) ); ?>');">
														<?php echo esc_html__( 'Supprimer', 'ufsc-licence-competition' ); ?>
													</button>
												</form>
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
				<h4><?php echo $editing_entry ? esc_html__( 'Modifier une inscription', 'ufsc-licence-competition' ) : esc_html__( 'Ajouter une inscription', 'ufsc-licence-competition' ); ?></h4>
				<?php if ( $license_block_available && $club_id ) : ?>
					<div class="ufsc-competition-license-prefill">
						<label><?php echo esc_html__( 'Licencié UFSC (pré-remplir)', 'ufsc-licence-competition' ); ?></label>
						<form method="get" action="<?php echo esc_url( Front::get_competition_details_url( (int) $competition->id ) ); ?>#ufsc-inscriptions" class="ufsc-license-search-form">
							<input type="text" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" placeholder="<?php echo esc_attr__( 'Nom, prénom…', 'ufsc-licence-competition' ); ?>" />
							<?php if ( $editing_entry ) : ?>
								<input type="hidden" name="ufsc_entry_edit" value="<?php echo esc_attr( (int) $editing_entry->id ); ?>" />
							<?php endif; ?>
							<button type="submit" class="button"><?php echo esc_html__( 'Rechercher', 'ufsc-licence-competition' ); ?></button>
						</form>
						<?php if ( ! empty( $license_results ) ) : ?>
							<form method="get" action="<?php echo esc_url( Front::get_competition_details_url( (int) $competition->id ) ); ?>#ufsc-inscriptions" class="ufsc-license-select-form">
								<input type="hidden" name="ufsc_license_term" value="<?php echo esc_attr( $license_term ); ?>" />
								<?php if ( $editing_entry ) : ?>
									<input type="hidden" name="ufsc_entry_edit" value="<?php echo esc_attr( (int) $editing_entry->id ); ?>" />
								<?php endif; ?>
								<select name="ufsc_license_id">
									<option value=""><?php echo esc_html__( 'Sélectionner un licencié', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $license_results as $license_row ) : ?>
										<option value="<?php echo esc_attr( (int) $license_row['id'] ); ?>" <?php selected( (int) $license_id, (int) $license_row['id'] ); ?>>
											<?php echo esc_html( $license_row['label'] ); ?>
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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $editing_entry ? 'ufsc_competitions_entry_update' : 'ufsc_competitions_entry_create' ); ?>" />
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) $competition->id ); ?>" />
					<?php if ( $editing_entry ) : ?>
						<input type="hidden" name="entry_id" value="<?php echo esc_attr( (int) $editing_entry->id ); ?>" />
					<?php endif; ?>
					<?php if ( $license_id ) : ?>
						<input type="hidden" name="ufsc_license_id" value="<?php echo esc_attr( $license_id ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( $editing_entry ? 'ufsc_competitions_entry_update' : 'ufsc_competitions_entry_create' ); ?>

					<?php foreach ( EntriesModule::get_fields_schema( $competition ) as $field ) : ?>
						<?php
							$name = $field['name'] ?? '';
							$label = $field['label'] ?? '';
							$type = $field['type'] ?? 'text';
							$required = ! empty( $field['required'] );
							$placeholder = $field['placeholder'] ?? '';
							$value = $editing_entry ? self::get_entry_value( $editing_entry, (array) ( $field['columns'] ?? array( $name ) ) ) : (string) ( $prefill[ $name ] ?? '' );
							$select_options = $field['options'] ?? array();
							$disabled = $registration_open ? '' : 'disabled';
						?>
						<div class="ufsc-field">
							<label for="ufsc-entry-<?php echo esc_attr( $name ); ?>">
								<?php echo esc_html( $label ); ?><?php if ( $required ) : ?> <span class="required">*</span><?php endif; ?>
							</label>
							<?php if ( 'select' === $type ) : ?>
								<select id="ufsc-entry-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php echo esc_attr( $disabled ); ?>>
									<option value=""><?php echo esc_html__( '—', 'ufsc-licence-competition' ); ?></option>
									<?php foreach ( $select_options as $option_value => $option_label ) : ?>
										<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( (string) $value, (string) $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
									<?php endforeach; ?>
								</select>
							<?php else : ?>
								<input
									type="<?php echo esc_attr( $type ); ?>"
									id="ufsc-entry-<?php echo esc_attr( $name ); ?>"
									name="<?php echo esc_attr( $name ); ?>"
									value="<?php echo esc_attr( $value ); ?>"
									<?php if ( $placeholder ) : ?>placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php endif; ?>
									<?php if ( $required ) : ?>required<?php endif; ?>
									<?php echo esc_attr( $disabled ); ?>
								/>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<button type="submit" class="button" <?php echo $registration_open ? '' : 'disabled'; ?>>
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
			'created' => array( 'success', __( 'Inscription ajoutée.', 'ufsc-licence-competition' ) ),
			'updated' => array( 'success', __( 'Inscription modifiée.', 'ufsc-licence-competition' ) ),
			'deleted' => array( 'success', __( 'Inscription supprimée.', 'ufsc-licence-competition' ) ),
			'error' => array( 'error', __( 'Une erreur est survenue. Merci de réessayer.', 'ufsc-licence-competition' ) ),
			'forbidden' => array( 'error', __( 'Action non autorisée.', 'ufsc-licence-competition' ) ),
			'invalid_fields' => array( 'error', __( 'Champs invalides.', 'ufsc-licence-competition' ) ),
			'closed' => array( 'error', __( 'Compétition fermée.', 'ufsc-licence-competition' ) ),
			'not_found' => array( 'error', __( 'Inscription introuvable.', 'ufsc-licence-competition' ) ),
		);

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
