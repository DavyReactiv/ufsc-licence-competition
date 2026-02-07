<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Services\PrintRenderer;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Print_Page {
	private $competitions;
	private $categories;
	private $entries;
	private $renderer;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->categories = new CategoryRepository();
		$this->entries = new EntryRepository();
		$this->renderer = new PrintRenderer();
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$type = isset( $_GET['print_type'] ) ? sanitize_key( wp_unslash( $_GET['print_type'] ) ) : 'entries';

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Impression', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Imprimer fiches combats, tableaux, poules, brackets.', 'ufsc-licence-competition' ); ?></p></div>
			<form method="get" class="ufsc-competitions-print-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_PRINT ); ?>" />
				<label for="ufsc_print_competition" class="screen-reader-text"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label>
				<select name="competition_id" id="ufsc_print_competition">
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $competition ) : ?>
						<option value="<?php echo esc_attr( $competition->id ); ?>" <?php selected( $competition_id, $competition->id ); ?>><?php echo esc_html( $competition->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label for="ufsc_print_type" class="screen-reader-text"><?php esc_html_e( 'Type d\'impression', 'ufsc-licence-competition' ); ?></label>
				<select name="print_type" id="ufsc_print_type">
					<option value="entries" <?php selected( $type, 'entries' ); ?>><?php esc_html_e( 'Liste des inscriptions', 'ufsc-licence-competition' ); ?></option>
					<option value="categories" <?php selected( $type, 'categories' ); ?>><?php esc_html_e( 'Catégories', 'ufsc-licence-competition' ); ?></option>
				</select>
				<?php submit_button( __( 'Afficher', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
				<?php submit_button( __( 'Imprimer', 'ufsc-licence-competition' ), 'primary', 'ufsc_print_now', false, array( 'onclick' => 'window.print();return false;' ) ); ?>
			</form>

			<?php if ( $competition_id ) : ?>
				<?php
				if ( method_exists( $this->competitions, 'assert_competition_in_scope' ) ) {
					$this->competitions->assert_competition_in_scope( $competition_id );
				}
				$competition = $this->competitions->get( $competition_id, true );
				?>
				<?php if ( $competition ) : ?>
					<div class="ufsc-print-area">
						<?php
						echo $this->renderer->render_header(
							$competition->name,
							array(
								__( 'Discipline', 'ufsc-licence-competition' ) => DisciplineRegistry::get_label( $competition->discipline ),
								__( 'Saison', 'ufsc-licence-competition' ) => $competition->season,
							)
						);
						if ( 'categories' === $type ) {
							$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
							echo '<h2>' . esc_html__( 'Catégories', 'ufsc-licence-competition' ) . '</h2>';
							echo '<table class="widefat striped">';
							echo '<thead><tr><th>' . esc_html__( 'Nom', 'ufsc-licence-competition' ) . '</th><th>' . esc_html__( 'Âges', 'ufsc-licence-competition' ) . '</th><th>' . esc_html__( 'Poids', 'ufsc-licence-competition' ) . '</th></tr></thead><tbody>';
							foreach ( $categories as $category ) {
								echo '<tr><td>' . esc_html( $category->name ) . '</td><td>' . esc_html( trim( $category->age_min . '-' . $category->age_max ) ) . '</td><td>' . esc_html( trim( $category->weight_min . '-' . $category->weight_max ) ) . '</td></tr>';
							}
							if ( ! $categories ) {
								echo '<tr><td colspan="3">' . esc_html__( 'Aucune catégorie.', 'ufsc-licence-competition' ) . '</td></tr>';
							}
							echo '</tbody></table>';
						} else {
							$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
							if ( function_exists( 'ufsc_competitions_apply_scope_to_query_args' ) ) {
								$entry_filters = ufsc_competitions_apply_scope_to_query_args( $entry_filters );
							}
							$entries = $this->entries->list( $entry_filters, 500, 0 );
							$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $competition_id ), 500, 0 );
							$category_map = array();
							foreach ( $categories as $category ) {
								$category_map[ $category->id ] = $category->name;
							}
							echo '<h2>' . esc_html__( 'Inscriptions', 'ufsc-licence-competition' ) . '</h2>';
							echo '<table class="widefat striped">';
							echo '<thead><tr><th>' . esc_html__( 'Licencié', 'ufsc-licence-competition' ) . '</th><th>' . esc_html__( 'Catégorie', 'ufsc-licence-competition' ) . '</th><th>' . esc_html__( 'Statut', 'ufsc-licence-competition' ) . '</th></tr></thead><tbody>';
							foreach ( $entries as $entry ) {
								$category_label = $entry->category_id ? ( $category_map[ $entry->category_id ] ?? '#' . $entry->category_id ) : '-';
								echo '<tr><td>' . esc_html( '#' . $entry->licensee_id ) . '</td><td>' . esc_html( $category_label ) . '</td><td>' . esc_html( $entry->status ) . '</td></tr>';
							}
							if ( ! $entries ) {
								echo '<tr><td colspan="3">' . esc_html__( 'Aucune inscription.', 'ufsc-licence-competition' ) . '</td></tr>';
							}
							echo '</tbody></table>';
						}
						?>
					</div>
				<?php endif; ?>
			<?php else : ?>
				<p><?php esc_html_e( 'Sélectionnez une compétition pour générer la vue imprimable.', 'ufsc-licence-competition' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
