<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Entries_Validation_Menu;
use UFSC\Competitions\Admin\Tables\Entries_Validation_Table;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Entries_Validation_Page {
	public function render(): void {
		if ( ! Capabilities::user_can_validate_entries() ) {
			wp_die( esc_html__( 'Action non autorisée.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		if ( 'reject' === $action ) {
			$this->render_reject_form();
			return;
		}

		$table = new Entries_Validation_Table();
		$table->prepare_items();

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html__( 'Inscriptions (Validation)', 'ufsc-licence-competition' ); ?></h1>

			<?php if ( $notice ) : ?>
				<?php echo $this->render_notice( $notice ); ?>
			<?php endif; ?>

			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Entries_Validation_Menu::PAGE_SLUG ); ?>" />
				<?php $table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-entry-validation' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private function render_reject_form(): void {
		$entry_id = isset( $_GET['entry_id'] ) ? absint( $_GET['entry_id'] ) : 0;
		$repo = new EntryRepository();
		$entry = $entry_id ? $repo->get( $entry_id ) : null;

		if ( ! $entry ) {
			wp_die( esc_html__( 'Inscription introuvable.', 'ufsc-licence-competition' ) );
		}

		$competition_name = '';
		$competition_id = absint( $entry->competition_id ?? 0 );
		if ( $competition_id ) {
			$competition_repo = new CompetitionRepository();
			$competition = $competition_repo->get( $competition_id );
			$competition_name = $competition ? (string) ( $competition->name ?? '' ) : '';
		}

		$action_url = admin_url( 'admin-post.php' );
		$back_url = admin_url( 'admin.php?page=' . Entries_Validation_Menu::PAGE_SLUG );
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html__( 'Rejeter une inscription', 'ufsc-licence-competition' ); ?></h1>
			<p>
				<?php echo esc_html__( 'Compétition :', 'ufsc-licence-competition' ); ?>
				<strong><?php echo esc_html( $competition_name ?: '#' . $competition_id ); ?></strong>
			</p>
			<p>
				<?php echo esc_html__( 'Inscription ID :', 'ufsc-licence-competition' ); ?>
				<strong><?php echo esc_html( (string) $entry_id ); ?></strong>
			</p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="ufsc_entry_admin_reject" />
				<input type="hidden" name="entry_id" value="<?php echo esc_attr( $entry_id ); ?>" />
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $back_url ); ?>" />
				<?php wp_nonce_field( 'ufsc_entry_admin_reject_' . $entry_id ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="ufsc-reject-reason"><?php echo esc_html__( 'Motif de rejet', 'ufsc-licence-competition' ); ?></label>
						</th>
						<td>
							<textarea id="ufsc-reject-reason" name="reason" rows="4" class="large-text" required></textarea>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php echo esc_html__( 'Rejeter', 'ufsc-licence-competition' ); ?></button>
					<a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html__( 'Annuler', 'ufsc-licence-competition' ); ?></a>
				</p>
			</form>
		</div>
		<?php
	}

	private function render_notice( string $notice ): string {
		$messages = array(
			'entry_validated' => array( 'success', __( 'Inscription approuvée.', 'ufsc-licence-competition' ) ),
			'entry_rejected' => array( 'success', __( 'Inscription rejetée.', 'ufsc-licence-competition' ) ),
			'entry_reopened' => array( 'success', __( 'Inscription ré-ouverte.', 'ufsc-licence-competition' ) ),
			'error_forbidden' => array( 'error', __( 'Action non autorisée.', 'ufsc-licence-competition' ) ),
			'error_not_found' => array( 'error', __( 'Inscription introuvable.', 'ufsc-licence-competition' ) ),
			'error_invalid_status' => array( 'error', __( 'Statut invalide.', 'ufsc-licence-competition' ) ),
			'error_invalid_fields' => array( 'error', __( 'Champs invalides.', 'ufsc-licence-competition' ) ),
			'error_weight_required' => array( 'error', __( 'Veuillez renseigner le poids avant validation.', 'ufsc-licence-competition' ) ),
			'error' => array( 'error', __( 'Une erreur est survenue.', 'ufsc-licence-competition' ) ),
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
}
