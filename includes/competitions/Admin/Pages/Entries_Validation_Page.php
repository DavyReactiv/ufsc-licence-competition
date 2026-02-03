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
		$table_output = $this->capture_list_table_output( $table );
		$items_count = is_countable( $table->items ) ? count( $table->items ) : 0;
		$output_len = strlen( $table_output );
		$current_view = isset( $_GET['ufsc_view'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_view'] ) ) : '';
		$legacy_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$competition_id = isset( $_GET['ufsc_competition_id'] ) ? absint( $_GET['ufsc_competition_id'] ) : 0;
		$legacy_competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$status = isset( $_GET['ufsc_status'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_status'] ) ) : '';
		$legacy_status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$discipline = isset( $_GET['ufsc_discipline'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_discipline'] ) ) : '';
		$legacy_discipline = isset( $_GET['discipline'] ) ? sanitize_key( wp_unslash( $_GET['discipline'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 0;

		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) {
			error_log(
				sprintf(
					'UFSC entries validation page=%s s=%s paged=%d ufsc_view=%s competition_id=%d status=%s discipline=%s items=%d output_len=%d',
					Entries_Validation_Menu::PAGE_SLUG,
					$search,
					(int) $paged,
					$current_view,
					(int) ( $competition_id ?: $legacy_competition_id ),
					$status ?: $legacy_status,
					$discipline ?: $legacy_discipline,
					(int) $items_count,
					(int) $output_len
				)
			);
		}
		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php echo esc_html__( 'Inscriptions (Validation)', 'ufsc-licence-competition' ); ?></h1>

			<?php if ( $notice ) : ?>
				<?php echo $this->render_notice( $notice ); ?>
			<?php endif; ?>

			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Entries_Validation_Menu::PAGE_SLUG ); ?>" />
				<?php if ( $current_view ) : ?>
					<input type="hidden" name="ufsc_view" value="<?php echo esc_attr( $current_view ); ?>" />
				<?php endif; ?>
				<?php if ( $legacy_view ) : ?>
					<input type="hidden" name="view" value="<?php echo esc_attr( $legacy_view ); ?>" />
				<?php endif; ?>
				<?php if ( $competition_id ) : ?>
					<input type="hidden" name="ufsc_competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
				<?php endif; ?>
				<?php if ( $legacy_competition_id ) : ?>
					<input type="hidden" name="competition_id" value="<?php echo esc_attr( $legacy_competition_id ); ?>" />
				<?php endif; ?>
				<?php if ( $status ) : ?>
					<input type="hidden" name="ufsc_status" value="<?php echo esc_attr( $status ); ?>" />
				<?php endif; ?>
				<?php if ( $legacy_status ) : ?>
					<input type="hidden" name="status" value="<?php echo esc_attr( $legacy_status ); ?>" />
				<?php endif; ?>
				<?php if ( $discipline ) : ?>
					<input type="hidden" name="ufsc_discipline" value="<?php echo esc_attr( $discipline ); ?>" />
				<?php endif; ?>
				<?php if ( $legacy_discipline ) : ?>
					<input type="hidden" name="discipline" value="<?php echo esc_attr( $legacy_discipline ); ?>" />
				<?php endif; ?>
				<?php if ( $search ) : ?>
					<input type="hidden" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<?php endif; ?>
				<?php if ( $paged ) : ?>
					<input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>" />
				<?php endif; ?>
				<?php $table->search_box( __( 'Rechercher', 'ufsc-licence-competition' ), 'ufsc-entry-validation' ); ?>
				<?php
				if ( '' !== trim( $table_output ) ) {
					echo $table_output;
				} else {
					$table->display();
				}
				?>
			</form>
			<?php if ( defined( 'WP_DEBUG' ) && WP_DEBUG && current_user_can( 'manage_options' ) ) : ?>
				<!-- UFSC entries debug: display_called=1 items=<?php echo esc_attr( (string) $items_count ); ?> -->
			<?php endif; ?>
		</div>
		<?php
	}

	private function capture_list_table_output( Entries_Validation_Table $table ): string {
		ob_start();
		$table->display();
		$output = (string) ob_get_clean();

		return $output;
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
