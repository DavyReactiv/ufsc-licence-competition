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

		$tab = isset( $_GET['tab'] ) ? sanitize_key( (string) wp_unslash( $_GET['tab'] ) ) : 'quick';
		if ( ! in_array( $tab, array( 'quick', 'complete' ), true ) ) {
			$tab = 'quick';
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
			<h1><?php esc_html_e( 'Aide & Documentation', 'ufsc-licence-competition' ); ?></h1>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Guide rapide pour le jour J, puis guide complet pour les règles métier et cas exceptionnels.', 'ufsc-licence-competition' ); ?></p></div>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Documentation', 'ufsc-licence-competition' ); ?>">
				<a class="nav-tab <?php echo 'quick' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_GUIDE, 'tab' => 'quick' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Guide rapide', 'ufsc-licence-competition' ); ?></a>
				<a class="nav-tab <?php echo 'complete' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_GUIDE, 'tab' => 'complete' ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Guide complet', 'ufsc-licence-competition' ); ?></a>
			</nav>

			<?php if ( 'quick' === $tab ) : ?>
				<?php $this->render_quick_guide(); ?>
			<?php else : ?>
				<?php $this->render_complete_guide( $competition_id, $competition, $competitions, $stats, $preset_label ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_quick_guide(): void {
		?>
		<section class="ufsc-doc-grid">
			<article class="ufsc-doc-card">
				<h2><?php esc_html_e( 'À quoi sert le plugin ?', 'ufsc-licence-competition' ); ?></h2>
				<p><?php esc_html_e( 'Piloter une compétition UFSC de bout en bout : inscriptions, pesées, combats, résultats, impressions.', 'ufsc-licence-competition' ); ?></p>
			</article>
			<article class="ufsc-doc-card">
				<h2><?php esc_html_e( 'Avant la compétition', 'ufsc-licence-competition' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Créer la compétition et vérifier les horaires.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Contrôler les catégories et imports CSV.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Valider les inscriptions prêtes.', 'ufsc-licence-competition' ); ?></li>
				</ol>
			</article>
			<article class="ufsc-doc-card">
				<h2><?php esc_html_e( 'Pesée (jour J)', 'ufsc-licence-competition' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'Ouvrir le menu Pesées et filtrer par compétition.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Saisir le poids réel puis valider le statut.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Attribuer le numéro combattant.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Si hors limite, proposer une catégorie supérieure.', 'ufsc-licence-competition' ); ?></li>
				</ol>
			</article>
			<article class="ufsc-doc-card">
				<h2><?php esc_html_e( 'Génération des combats', 'ufsc-licence-competition' ); ?></h2>
				<p><?php esc_html_e( 'Ne lancer la génération qu’avec des inscrits validés et pesés. Les statuts en attente bloquent la génération automatique.', 'ufsc-licence-competition' ); ?></p>
			</article>
			<article class="ufsc-doc-card">
				<h2><?php esc_html_e( 'Impression et résultats', 'ufsc-licence-competition' ); ?></h2>
				<p><?php esc_html_e( 'Imprimer les tableaux/surfaces, puis saisir les résultats au fil de l’événement. Une correction de résultat peut impacter la suite du bracket.', 'ufsc-licence-competition' ); ?></p>
			</article>
			<article class="ufsc-doc-card">
				<h2><?php esc_html_e( 'Points d’attention', 'ufsc-licence-competition' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Toujours vérifier la compétition active avant action.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Traiter les hors limite avant de générer.', 'ufsc-licence-competition' ); ?></li>
					<li><?php esc_html_e( 'Conserver une impression de secours.', 'ufsc-licence-competition' ); ?></li>
				</ul>
			</article>
		</section>
		<?php
	}

	private function render_complete_guide( int $competition_id, $competition, array $competitions, array $stats, string $preset_label ): void {
		?>
		<form method="get" class="ufsc-competitions-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_GUIDE ); ?>" />
			<input type="hidden" name="tab" value="complete" />
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
			<?php echo $this->render_step( 1, __( 'Vue d’ensemble du plugin', 'ufsc-licence-competition' ), array(
				__( 'Workflow : Compétitions → Inscriptions → Pesées → Combats → Résultats → Impression.', 'ufsc-licence-competition' ),
				__( 'Modules principaux : compétitions, catégories, inscriptions, combats, impression, timing profiles.', 'ufsc-licence-competition' ),
			), array(), 'ok', '' ); ?>
			<?php echo $this->render_step( 2, __( 'Compétitions', 'ufsc-licence-competition' ), array(
				__( 'Créer, planifier, ouvrir et clôturer.', 'ufsc-licence-competition' ),
				__( 'Renseigner les paramètres de pesée et tolérance.', 'ufsc-licence-competition' ),
			), array( 'label' => __( 'Gérer les compétitions', 'ufsc-licence-competition' ), 'url' => $this->get_menu_url( Menu::PAGE_COMPETITIONS ) ), $competition ? 'ok' : 'warning', $competition ? '' : __( 'Sélectionnez une compétition pour un suivi précis.', 'ufsc-licence-competition' ) ); ?>
			<?php echo $this->render_step( 3, __( 'Inscriptions', 'ufsc-licence-competition' ), array(
				__( 'Importer CSV puis valider les statuts.', 'ufsc-licence-competition' ),
				__( 'Les inscriptions non validées ne doivent pas partir en génération.', 'ufsc-licence-competition' ),
			), array( 'label' => __( 'Ouvrir Inscriptions', 'ufsc-licence-competition' ), 'url' => $this->get_menu_url( Menu::PAGE_ENTRIES, $competition ? array( 'ufsc_competition_id' => $competition_id ) : array() ) ), $this->get_step_status( $competition, $stats['entries'] ?? 0, $competition ? 'open' : '' ), $competition ? $this->get_entries_notice( $competition, $stats ) : '' ); ?>
			<?php echo $this->render_step( 4, __( 'Pesées', 'ufsc-licence-competition' ), array(
				__( 'Statuts : en attente, pesé, hors limite, reclassé, refusé.', 'ufsc-licence-competition' ),
				__( 'Attribuer un numéro combattant à la validation.', 'ufsc-licence-competition' ),
				__( 'Un reclassement non validé bloque la génération automatique.', 'ufsc-licence-competition' ),
			), array( 'label' => __( 'Ouvrir Pesées', 'ufsc-licence-competition' ), 'url' => $this->get_menu_url( Menu::PAGE_WEIGHINS, $competition ? array( 'competition_id' => $competition_id ) : array() ) ), $competition ? 'ok' : 'warning', '' ); ?>
			<?php echo $this->render_step( 5, __( 'Génération des combats', 'ufsc-licence-competition' ), array(
				__( 'Formats : poules, élimination directe, byes, phases.', 'ufsc-licence-competition' ),
				__( 'Affecter surfaces et timing profiles.', 'ufsc-licence-competition' ),
			), array( 'label' => __( 'Ouvrir Combats', 'ufsc-licence-competition' ), 'url' => $this->get_menu_url( Menu::PAGE_BOUTS, $competition ? array( 'ufsc_competition_id' => $competition_id ) : array() ) ), $this->get_step_status( $competition, $stats['fights'] ?? 0 ), $competition ? $this->get_fights_notice( $stats ) : '' ); ?>
			<?php echo $this->render_step( 6, __( 'Résultats', 'ufsc-licence-competition' ), array(
				__( 'Saisir et corriger les résultats avec prudence.', 'ufsc-licence-competition' ),
				__( 'Une correction peut impacter les combats suivants.', 'ufsc-licence-competition' ),
			), array( 'label' => __( 'Consulter Combats', 'ufsc-licence-competition' ), 'url' => $this->get_menu_url( Menu::PAGE_BOUTS, $competition ? array( 'ufsc_competition_id' => $competition_id ) : array() ) ), 'warning', '' ); ?>
			<?php echo $this->render_step( 7, __( 'Impression', 'ufsc-licence-competition' ), array(
				__( 'Imprimer tableaux et feuilles par surface.', 'ufsc-licence-competition' ),
				__( 'Les placeholders “Vainqueur combat X” sont remplacés automatiquement.', 'ufsc-licence-competition' ),
			), array( 'label' => __( 'Ouvrir Impression', 'ufsc-licence-competition' ), 'url' => $this->get_menu_url( Menu::PAGE_PRINT, $competition ? array( 'competition_id' => $competition_id ) : array() ) ), $competition ? 'ok' : 'warning', '' ); ?>
			<?php echo $this->render_step( 8, __( 'Officiels & surfaces', 'ufsc-licence-competition' ), array(
				__( 'Coordonner superviseurs, arbitres, juges et surfaces.', 'ufsc-licence-competition' ),
				__( 'Conserver des feuilles de secours imprimées.', 'ufsc-licence-competition' ),
			), array(), 'ok', '' ); ?>
			<?php echo $this->render_step( 9, __( 'Cas exceptionnels', 'ufsc-licence-competition' ), array(
				__( 'Réintégration d’athlète et régénération partielle si nécessaire.', 'ufsc-licence-competition' ),
				__( 'Documenter chaque correction de résultat.', 'ufsc-licence-competition' ),
			), array(), 'warning', '' ); ?>
			<?php echo $this->render_step( 10, __( 'Lexique', 'ufsc-licence-competition' ), array(
				__( 'BYE : passage automatique au tour suivant.', 'ufsc-licence-competition' ),
				__( 'Bracket : tableau à élimination.', 'ufsc-licence-competition' ),
				__( 'Poule : groupe de combats de qualification.', 'ufsc-licence-competition' ),
				__( 'Réintégration / régénération partielle : ajustements contrôlés après incident.', 'ufsc-licence-competition' ),
			), array(), 'ok', '' ); ?>
			<?php echo $this->render_step( 11, __( 'FAQ', 'ufsc-licence-competition' ), array(
				__( 'Pourquoi un inscrit n’apparaît pas dans les combats ? (statut/pesée non conforme).', 'ufsc-licence-competition' ),
				__( 'Que signifie “non pesé” ? (pas de validation exploitable).', 'ufsc-licence-competition' ),
				__( 'Pourquoi “Vainqueur combat X” ? (placeholder auto-remplacé).', 'ufsc-licence-competition' ),
			), array(), 'ok', '' ); ?>
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

	private function get_preset_label( $discipline ) {
		$type = DisciplineRegistry::get_type( $discipline );
		$preset = CategoryPresetRegistry::get_preset( $type );

		return $preset['label'] ?? '';
	}
}
