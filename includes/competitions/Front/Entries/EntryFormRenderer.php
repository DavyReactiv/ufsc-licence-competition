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

		if ( ! $competition ) {
			return '';
		}

		ob_start();
		?>
		<div class="ufsc-competition-entries" id="ufsc-competition-entries">
			<h3><?php echo esc_html__( 'Inscriptions', 'ufsc-licence-competition' ); ?></h3>

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
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( $editing_entry ? 'ufsc_competitions_entry_update' : 'ufsc_competitions_entry_create' ); ?>" />
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( (int) $competition->id ); ?>" />
					<?php if ( $editing_entry ) : ?>
						<input type="hidden" name="entry_id" value="<?php echo esc_attr( (int) $editing_entry->id ); ?>" />
					<?php endif; ?>
					<?php wp_nonce_field( $editing_entry ? 'ufsc_competitions_entry_update' : 'ufsc_competitions_entry_create' ); ?>

					<?php foreach ( EntriesModule::get_fields_schema( $competition ) as $field ) : ?>
						<?php
							$name = $field['name'] ?? '';
							$label = $field['label'] ?? '';
							$type = $field['type'] ?? 'text';
							$required = ! empty( $field['required'] );
							$placeholder = $field['placeholder'] ?? '';
							$value = $editing_entry ? self::get_entry_value( $editing_entry, (array) ( $field['columns'] ?? array( $name ) ) ) : '';
							$select_options = $field['options'] ?? array();
						?>
						<div class="ufsc-field">
							<label for="ufsc-entry-<?php echo esc_attr( $name ); ?>">
								<?php echo esc_html( $label ); ?><?php if ( $required ) : ?> <span class="required">*</span><?php endif; ?>
							</label>
							<?php if ( 'select' === $type ) : ?>
								<select id="ufsc-entry-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
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
								/>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>

					<button type="submit" class="button">
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
			'created' => array( 'success', __( 'Inscription ajoutée avec succès.', 'ufsc-licence-competition' ) ),
			'updated' => array( 'success', __( 'Inscription mise à jour.', 'ufsc-licence-competition' ) ),
			'deleted' => array( 'success', __( 'Inscription supprimée.', 'ufsc-licence-competition' ) ),
			'error' => array( 'error', __( 'Une erreur est survenue. Merci de réessayer.', 'ufsc-licence-competition' ) ),
			'forbidden' => array( 'error', __( 'Action non autorisée.', 'ufsc-licence-competition' ) ),
			'missing_fields' => array( 'error', __( 'Merci de compléter les champs obligatoires.', 'ufsc-licence-competition' ) ),
			'not_open' => array( 'error', __( 'Les inscriptions sont fermées pour cette compétition.', 'ufsc-licence-competition' ) ),
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
