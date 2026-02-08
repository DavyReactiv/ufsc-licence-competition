<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\CategoryPresetRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Guide_Page {
	private $competitions;
	private $categories;
	private $entries;
	private $fights;

	public function __construct() {
		$this->competitions = new CompetitionRepository();
		$this->categories = new CategoryRepository();
		$this->entries = new EntryRepository();
		$this->fights = new FightRepository();
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		if ( $competition_id && method_exists( $this->competitions, 'assert_competition_in_scope' ) ) {
			$this->competitions->assert_competition_in_scope( $competition_id );
		}
		$competition = $competition_id ? $this->competitions->get( $competition_id, true ) : null;
		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );

		$stats = $competition ? $this->get_competition_stats( $competition_id ) : array();
		$preset_label = $competition ? $this->get_preset_label( $competition->discipline ) : '';

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1><?php esc_html_e( 'Aide & Guide', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Suivez ces étapes pour créer, ouvrir, organiser et clôturer une compétition UFSC.', 'ufsc-licence-competition' ); ?></p></div>

			<form method="get" class="ufsc-competitions-filter">
				<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_GUIDE ); ?>" />
				<label for="ufsc_guide_competition" class="screen-reader-text"><?php esc_html_e( 'Compétition', 'ufsc-licence-competition' ); ?></label>
				<select name="competition_id" id="ufsc_guide_competition">
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $competitions as $item ) : ?>
						<option value="<?php echo esc_attr( $item->id ); ?>" <?php selected( $competition_id, $item->id ); ?>><?php echo esc_html( $item->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php submit_button( __( 'Charger', 'ufsc-licence-competition' ), 'secondary', '', false ); ?>
			</form>

			<div class="ufsc-steps">
				<?php echo $this->render_step( 1, __( 'Créer la compétition', 'ufsc-licence-competition' ), array(
					__( 'Renseigner type, discipline, dates, forclusion.', 'ufsc-licence-competition' ),
					__( 'Vérifier la saison et le statut.', 'ufsc-licence-competition' ),
				), array(
					'label' => __( 'Aller à Compétitions', 'ufsc-licence-competition' ),
					'url'   => $this->get_menu_url( Menu::PAGE_COMPETITIONS, array( 'ufsc_action' => 'add' ) ),
				), $competition ? 'ok' : 'warning', $competition ? '' : __( 'Sélectionnez ou créez une compétition.', 'ufsc-licence-competition' ) ); ?>

				<?php echo $this->render_step( 2, __( 'Appliquer le référentiel catégories', 'ufsc-licence-competition' ), array(
					__( 'Charger le référentiel UFSC dans Paramètres.', 'ufsc-licence-competition' ),
					__( 'Appliquer ou ajuster les catégories.', 'ufsc-licence-competition' ),
				), array(
					'label' => __( 'Aller à Catégories', 'ufsc-licence-competition' ),
					'url'   => $this->get_menu_url( Menu::PAGE_CATEGORIES, $competition ? array( 'ufsc_competition_id' => $competition_id ) : array() ),
					'disabled' => ! $competition,
				), $this->get_step_status( $competition, $stats['categories'] ?? 0 ), $competition ? $this->get_category_notice( $competition, $stats, $preset_label ) : __( 'Sélectionnez une compétition pour appliquer le référentiel.', 'ufsc-licence-competition' ) ); ?>

				<?php echo $this->render_step( 3, __( 'Ouvrir inscriptions + valider', 'ufsc-licence-competition' ), array(
					__( 'Passer le statut en ouvert.', 'ufsc-licence-competition' ),
					__( 'Contrôler les inscriptions en attente.', 'ufsc-licence-competition' ),
				), array(
					'label' => __( 'Aller à Inscriptions', 'ufsc-licence-competition' ),
					'url'   => $this->get_menu_url( Menu::PAGE_ENTRIES, $competition ? array( 'ufsc_competition_id' => $competition_id ) : array() ),
					'disabled' => ! $competition,
				), $this->get_step_status( $competition, $stats['entries'] ?? 0, $competition ? 'open' : '' ), $competition ? $this->get_entries_notice( $competition, $stats ) : __( 'Sélectionnez une compétition pour gérer les inscriptions.', 'ufsc-licence-competition' ) ); ?>

				<?php echo $this->render_step( 4, __( 'Générer combats (poules/bracket)', 'ufsc-licence-competition' ), array(
					__( 'Créer les combats à partir des inscriptions approuvées.', 'ufsc-licence-competition' ),
					__( 'Contrôler les affectations et rounds.', 'ufsc-licence-competition' ),
				), array(
					'label' => __( 'Aller à Combats', 'ufsc-licence-competition' ),
					'url'   => $this->get_menu_url( Menu::PAGE_BOUTS, $competition ? array( 'ufsc_competition_id' => $competition_id ) : array() ),
					'disabled' => ! $competition,
				), $this->get_step_status( $competition, $stats['fights'] ?? 0 ), $competition ? $this->get_fights_notice( $stats ) : __( 'Sélectionnez une compétition pour générer les combats.', 'ufsc-licence-competition' ) ); ?>

				<?php echo $this->render_step( 5, __( 'Planning/aires + Impression', 'ufsc-licence-competition' ), array(
					__( 'Organiser les aires et horaires.', 'ufsc-licence-competition' ),
					__( 'Imprimer les listings et tableaux.', 'ufsc-licence-competition' ),
				), array(
					'label' => __( 'Aller à Impression', 'ufsc-licence-competition' ),
					'url'   => $this->get_menu_url( Menu::PAGE_PRINT, $competition ? array( 'competition_id' => $competition_id ) : array() ),
					'disabled' => ! $competition,
				), $competition ? 'ok' : 'warning', $competition ? '' : __( 'Sélectionnez une compétition pour imprimer.', 'ufsc-licence-competition' ) ); ?>

				<?php echo $this->render_step( 6, __( 'Résultats + Clôture + Archivage', 'ufsc-licence-competition' ), array(
					__( 'Renseigner les résultats.', 'ufsc-licence-competition' ),
					__( 'Clôturer puis archiver.', 'ufsc-licence-competition' ),
				), array(
					'label' => __( 'Aller à Compétitions', 'ufsc-licence-competition' ),
					'url'   => $this->get_menu_url( Menu::PAGE_COMPETITIONS ),
					'disabled' => ! $competition,
				), $this->get_closure_status( $competition ), $competition ? $this->get_closure_notice( $competition ) : __( 'Sélectionnez une compétition pour clôturer.', 'ufsc-licence-competition' ) ); ?>
			</div>
		</div>
		<?php
	}

	private function render_step( $number, $title, array $checklist, array $button, $status, $notice ) {
		$status_class = 'ok' === $status ? 'ufsc-step--ok' : ( 'blocked' === $status ? 'ufsc-step--blocked' : 'ufsc-step--warning' );
		$button_disabled = ! empty( $button['disabled'] ) || 'blocked' === $status;
		$button_label = $button['label'] ?? '';
		$button_url = $button['url'] ?? '';

		ob_start();
		?>
		<section class="ufsc-step <?php echo esc_attr( $status_class ); ?>">
			<header class="ufsc-step__header">
				<span class="ufsc-step__badge"><?php echo esc_html( $number ); ?></span>
				<h3><?php echo esc_html( $title ); ?></h3>
			</header>
			<ul class="ufsc-step__list">
				<?php foreach ( $checklist as $item ) : ?>
					<li><?php echo esc_html( $item ); ?></li>
				<?php endforeach; ?>
			</ul>
			<div class="ufsc-step__actions">
				<?php if ( $button_label ) : ?>
					<a class="button <?php echo $button_disabled ? 'disabled' : 'button-primary'; ?>" href="<?php echo esc_url( $button_url ); ?>" <?php echo $button_disabled ? 'aria-disabled="true"' : ''; ?>><?php echo esc_html( $button_label ); ?></a>
				<?php endif; ?>
				<?php if ( $notice ) : ?>
					<p class="description"><?php echo esc_html( $notice ); ?></p>
				<?php endif; ?>
			</div>
		</section>
		<?php

		return ob_get_clean();
	}

	private function get_competition_stats( $competition_id ) {
		$entry_filters = array( 'view' => 'all', 'competition_id' => $competition_id );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}

		return array(
			'categories' => $this->categories->count( array( 'view' => 'all', 'competition_id' => $competition_id ) ),
			'entries'    => $this->entries->count( $entry_filters ),
			'fights'     => $this->fights->count( array( 'view' => 'all', 'competition_id' => $competition_id ) ),
		);
	}

	private function get_menu_url( $page, array $args = array() ) {
		$args = array_merge( array( 'page' => $page ), $args );

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private function get_step_status( $competition, $count, $required_status = '' ) {
		if ( ! $competition ) {
			return 'warning';
		}

		if ( $required_status && $competition->status !== $required_status ) {
			return 'warning';
		}

		return $count > 0 ? 'ok' : 'blocked';
	}

	private function get_category_notice( $competition, array $stats, $preset_label ) {
		if ( ! $competition ) {
			return '';
		}

		if ( ( $stats['categories'] ?? 0 ) > 0 ) {
			return '';
		}

		$type_label = $preset_label ? sprintf( __( 'Référentiel recommandé : %s.', 'ufsc-licence-competition' ), $preset_label ) : __( 'Aucun référentiel UFSC disponible pour cette discipline.', 'ufsc-licence-competition' );
		return $type_label;
	}

	private function get_entries_notice( $competition, array $stats ) {
		if ( ! $competition ) {
			return '';
		}

		if ( ( $stats['entries'] ?? 0 ) === 0 ) {
			return __( 'Aucune inscription pour le moment.', 'ufsc-licence-competition' );
		}

		if ( 'open' !== $competition->status ) {
			return __( 'Passez le statut en “Ouvert” pour accepter les inscriptions.', 'ufsc-licence-competition' );
		}

		return '';
	}

	private function get_fights_notice( array $stats ) {
		if ( ( $stats['fights'] ?? 0 ) === 0 ) {
			return __( 'Aucun combat généré.', 'ufsc-licence-competition' );
		}

		return '';
	}

	private function get_closure_status( $competition ) {
		if ( ! $competition ) {
			return 'warning';
		}

		return in_array( $competition->status, array( 'closed', 'archived' ), true ) ? 'ok' : 'warning';
	}

	private function get_closure_notice( $competition ) {
		if ( ! $competition ) {
			return '';
		}

		if ( 'archived' === $competition->status ) {
			return __( 'Compétition archivée.', 'ufsc-licence-competition' );
		}

		if ( 'closed' === $competition->status ) {
			return __( 'Compétition clôturée, prête à être archivée.', 'ufsc-licence-competition' );
		}

		return __( 'Clôturez la compétition avant l’archivage.', 'ufsc-licence-competition' );
	}

	private function get_preset_label( $discipline ) {
		$type = DisciplineRegistry::get_type( $discipline );
		$preset = CategoryPresetRegistry::get_preset( $type );

		return $preset['label'] ?? '';
	}
}
