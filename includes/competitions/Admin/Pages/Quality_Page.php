<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Admin\Tables\Quality_Table;
use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;
use UFSC\Competitions\Repositories\WeighInRepository;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\CompetitionSafetyService;
use UFSC\Competitions\Services\CompetitionStatsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quality_Page {
	private $entries;
	private $competitions;
	private $categories;
	private $fights;
	private $weighins;

	public function __construct() {
		$this->entries      = new EntryRepository();
		$this->competitions = new CompetitionRepository();
		$this->categories   = new CategoryRepository();
		$this->fights       = new FightRepository();
		$this->weighins     = new WeighInRepository();
	}

	public function render() {
		if ( ! Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$issues       = $this->collect_issues( $competition_id );
		$table        = new Quality_Table( $issues );
		$table->prepare_items();
		$issues_count = count( $issues );

		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Contrôle métier', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Contrôles qualité', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Détectez en un coup d’œil les anomalies qui bloquent validations, génération de combats ou conformité.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>

			<section class="ufsc-kpis ufsc-kpis--premium">
				<article class="ufsc-kpi">
					<span class="ufsc-kpi__label"><?php esc_html_e( 'Anomalies détectées', 'ufsc-licence-competition' ); ?></span>
					<strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $issues_count ) ); ?></strong>
				</article>
				<article class="ufsc-kpi">
					<span class="ufsc-kpi__label"><?php esc_html_e( 'Priorité', 'ufsc-licence-competition' ); ?></span>
					<strong class="ufsc-kpi__value"><?php echo esc_html( $issues_count > 0 ? __( 'Action requise', 'ufsc-licence-competition' ) : __( 'OK', 'ufsc-licence-competition' ) ); ?></strong>
				</article>
			</section>

			<div class="notice notice-info ufsc-competitions-helper">
				<p><?php esc_html_e( 'Contrôle avant compétition officielle : configuration, catégories, plateaux, inscrits, pesées, doublons et données manquantes.', 'ufsc-licence-competition' ); ?></p>
			</div>

			<form method="get" class="ufsc-admin-filters">
				<input type="hidden" name="page" value="ufsc-competitions-quality" />
				<label for="ufsc-quality-competition"><strong><?php esc_html_e( 'Limiter à une compétition', 'ufsc-licence-competition' ); ?></strong></label>
				<select id="ufsc-quality-competition" name="competition_id">
					<option value="0"><?php esc_html_e( 'Toutes les compétitions visibles', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $this->get_visible_competitions() as $competition ) : ?>
						<option value="<?php echo esc_attr( (int) $competition->id ); ?>" <?php selected( $competition_id, (int) $competition->id ); ?>><?php echo esc_html( (string) $competition->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Lancer le contrôle', 'ufsc-licence-competition' ); ?></button>
			</form>

			<?php $this->render_data_protection_section( $competition_id ); ?>
			<?php $this->render_counter_diagnostic_section( $competition_id ); ?>

			<?php if ( 0 === $issues_count ) : ?>
				<div class="ufsc-empty-state">
					<h2><?php esc_html_e( 'Aucune anomalie détectée', 'ufsc-licence-competition' ); ?></h2>
					<p><?php esc_html_e( 'Tous les contrôles actuels sont conformes. Vous pouvez poursuivre la préparation des tableaux.', 'ufsc-licence-competition' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=ufsc-competitions-entries' ) ); ?>">
							<?php esc_html_e( 'Consulter les inscriptions', 'ufsc-licence-competition' ); ?>
						</a>
					</p>
				</div>
			<?php else : ?>
				<div class="ufsc-competitions-table-wrap">
					<?php $table->display(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_data_protection_section( int $competition_id ): void {
		if ( $competition_id <= 0 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Protection des données réelles : sélectionnez une compétition pour afficher les blocages actifs.', 'ufsc-licence-competition' ) . '</p></div>';
			return;
		}

		$summary = ( new CompetitionSafetyService() )->get_protection_summary( $competition_id );
		?>
		<section class="ufsc-admin-surface ufsc-data-protection-summary">
			<h2><?php esc_html_e( 'Protection des données réelles', 'ufsc-licence-competition' ); ?></h2>
			<p><?php esc_html_e( 'Cette section indique les protections anti-perte activées pour la compétition sélectionnée. En cas de résultats ou verrous, les actions destructrices et régénérations sont bloquées.', 'ufsc-licence-competition' ); ?></p>
			<section class="ufsc-kpis ufsc-kpis--premium">
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Inscriptions réelles', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['entries_count'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Combats existants', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['fights_count'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Résultats saisis', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['results_entered'] ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Combats verrouillés', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( (int) $summary['locked_fights'] ) ); ?></strong></article>
			</section>
			<p><strong><?php esc_html_e( 'Statut de protection :', 'ufsc-licence-competition' ); ?></strong> <?php echo esc_html( (string) $summary['protection_status'] ); ?></p>
			<?php if ( ! empty( $summary['blocked_actions'] ) ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Actions sensibles actuellement bloquées ou renforcées :', 'ufsc-licence-competition' ); ?></strong></p><ul>
					<?php foreach ( $summary['blocked_actions'] as $blocked_action ) : ?>
						<li><?php echo esc_html( (string) $blocked_action ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php else : ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Aucune donnée réelle critique détectée. Les protections restent actives dès qu’une inscription, un combat ou un résultat apparaît.', 'ufsc-licence-competition' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Recommandation jour J : exporter les inscrits, imprimer les pesées et vérifier les logs avant toute action sensible.', 'ufsc-licence-competition' ); ?></p>
		</section>
		<?php
	}

	private function render_counter_diagnostic_section( int $competition_id ): void {
		if ( $competition_id <= 0 ) {
			return;
		}

		$stats = ( new CompetitionStatsService() )->get_competition_stats( $competition_id );
		$entries = $stats['entries'] ?? array();
		$weighins = $stats['weighins'] ?? array();
		$categories = $stats['categories'] ?? array();
		$by_status = $entries['by_status'] ?? array();
		?>
		<section class="ufsc-admin-surface ufsc-counter-diagnostic">
			<h2><?php esc_html_e( 'Diagnostic des compteurs', 'ufsc-licence-competition' ); ?></h2>
			<p><?php echo esc_html( sprintf( __( 'Compétition analysée : competition_id=%d. Cette section explique pourquoi les compteurs peuvent différer entre Inscriptions, Pesées et Tableau de bord sans modifier les données.', 'ufsc-licence-competition' ), $competition_id ) ); ?></p>
			<table class="widefat striped">
				<tbody>
				<tr><th><?php esc_html_e( 'Inscriptions totales', 'ufsc-licence-competition' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) ( $entries['total'] ?? 0 ) ) ); ?></td><td><?php echo esc_html( (string) ( $stats['sources']['entries'] ?? '' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Statuts comptés', 'ufsc-licence-competition' ); ?></th><td colspan="2"><?php foreach ( $by_status as $status => $count ) : ?><span class="ufsc-badge"><?php echo esc_html( $status . ': ' . (int) $count ); ?></span> <?php endforeach; ?></td></tr>
				<tr><th><?php esc_html_e( 'Périmètre Pesées', 'ufsc-licence-competition' ); ?></th><td><?php echo esc_html( sprintf( __( '%1$d visible(s), %2$d exclue(s)', 'ufsc-licence-competition' ), (int) ( $weighins['visible_entries'] ?? 0 ), max( 0, (int) ( $entries['total'] ?? 0 ) - (int) ( $weighins['visible_entries'] ?? 0 ) ) ) ); ?></td><td><?php echo esc_html( (string) ( $stats['sources']['weighins'] ?? '' ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Catégories référentiel', 'ufsc-licence-competition' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) ( $categories['configured_count'] ?? 0 ) ) ); ?></td><td><?php esc_html_e( 'Catégories configurées utilisées par la génération automatique.', 'ufsc-licence-competition' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Catégories déclarées dans les inscriptions', 'ufsc-licence-competition' ); ?></th><td><?php echo esc_html( number_format_i18n( (int) ( $categories['text_distinct_count'] ?? 0 ) ) ); ?></td><td><?php esc_html_e( 'Libellés textuels importés/saisis sur les inscriptions, sans import automatique dans le référentiel.', 'ufsc-licence-competition' ); ?></td></tr>
				</tbody>
			</table>
			<?php if ( ! empty( $categories['unmatched_text_labels'] ) ) : ?>
				<p><strong><?php esc_html_e( 'Libellés déclarés non retrouvés dans le référentiel configuré :', 'ufsc-licence-competition' ); ?></strong></p>
				<ul>
					<?php foreach ( array_slice( $categories['unmatched_text_labels'], 0, 20 ) as $label_data ) : ?>
						<li><?php echo esc_html( sprintf( '%s (%d inscription(s))', (string) ( $label_data['label'] ?? '' ), (int) ( $label_data['count'] ?? 0 ) ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</section>
		<?php
	}



	private function collect_issues( int $competition_id = 0 ) {
		$competition_filters = array( 'view' => 'all' );
		if ( $competition_id > 0 ) {
			$competition_filters['id'] = $competition_id;
		}
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );
		if ( $competition_id > 0 ) {
			$competitions = array_values(
				array_filter(
					$competitions,
					static function( $competition ) use ( $competition_id ) {
						return (int) ( $competition->id ?? 0 ) === $competition_id;
					}
				)
			);
		}

		$issues = array();
		if ( empty( $competitions ) ) {
			$issues[] = $this->issue( __( 'Configuration bloquante', 'ufsc-licence-competition' ), '', '', __( 'Aucune compétition visible. Vérifiez la création de la compétition et vos droits d’accès.', 'ufsc-licence-competition' ) );
			return $issues;
		}

		foreach ( $competitions as $competition ) {
			$cid  = (int) ( $competition->id ?? 0 );
			$name = (string) ( $competition->name ?? '' );
			if ( ! $cid ) {
				continue;
			}

			$categories = $this->categories->list( array( 'view' => 'all', 'competition_id' => $cid ), 1000, 0 );
			$category_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $categories, 'id' ) ) ) );

			$entry_filters = array( 'view' => 'all', 'competition_id' => $cid );
			if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
				$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
			}
			$entries = $this->entries->list_with_details( $entry_filters, 5000, 0 );
			if ( empty( $categories ) ) {
				$text_labels = array();
				foreach ( $entries as $entry_for_label ) {
					$label = trim( (string) ( $entry_for_label->category_name ?? $entry_for_label->category ?? '' ) );
					if ( '' !== $label ) {
						$text_labels[ CompetitionStatsService::normalize_category_label( $label ) ] = $label;
					}
				}
				if ( $text_labels ) {
					$issues[] = $this->issue( __( 'Bloquant : référentiel catégories non configuré', 'ufsc-licence-competition' ), $name, '', sprintf( __( 'Des catégories sont visibles dans les inscriptions (%d libellé(s)), mais le référentiel utilisé pour la génération automatique n’est pas encore configuré. Prévisualisez/importez le référentiel catégories sans modifier les inscriptions existantes.', 'ufsc-licence-competition' ), count( $text_labels ) ) );
				} else {
					$issues[] = $this->issue( __( 'Bloquant : aucune catégorie', 'ufsc-licence-competition' ), $name, '', __( 'Ajoutez ou importez le référentiel catégories avant d’ouvrir ou générer officiellement les combats.', 'ufsc-licence-competition' ) );
				}
			}
			if ( empty( $entries ) ) {
				$issues[] = $this->issue( __( 'Bloquant : aucun inscrit', 'ufsc-licence-competition' ), $name, '', __( 'Importez ou saisissez les inscriptions avant les pesées et la génération.', 'ufsc-licence-competition' ) );
			}

			$fights = $this->fights->list( array( 'view' => 'all', 'competition_id' => $cid ), 5000, 0 );
			$surfaces = array();
			foreach ( $fights as $fight ) {
				$ring = trim( (string) ( $fight->ring ?? '' ) );
				if ( '' !== $ring ) {
					$surfaces[ $ring ] = true;
				}
			}
			if ( ! empty( $fights ) && empty( $surfaces ) ) {
				$issues[] = $this->issue( __( 'Avertissement : plateaux non renseignés', 'ufsc-licence-competition' ), $name, '', __( 'Des combats existent mais aucune surface/plateau n’est renseigné. L’impression par plateau sera difficile.', 'ufsc-licence-competition' ) );
			}

			$entry_ids = array_values( array_filter( array_map( 'absint', wp_list_pluck( $entries, 'id' ) ) ) );
			$weighins  = $this->weighins->get_for_entries( $cid, $entry_ids );
			$category_usage = array_fill_keys( $category_ids, 0 );
			$seen_fighters = array();
			foreach ( $entries as $entry ) {
				if ( ! empty( $entry->deleted_at ) ) {
					continue;
				}
				$entry_id = (int) ( $entry->id ?? 0 );
				$licensee = trim( (string) ( ( $entry->last_name ?? '' ) . ' ' . ( $entry->first_name ?? '' ) ) );
				if ( '' === $licensee ) {
					$licensee = sprintf( '#%d', $entry_id ?: (int) ( $entry->licensee_id ?? 0 ) );
				}

				$cat_id = (int) ( $entry->category_id ?? 0 );
				if ( $cat_id && isset( $category_usage[ $cat_id ] ) ) {
					$category_usage[ $cat_id ]++;
				}
				if ( empty( $entry->category_id ) ) {
					$issues[] = $this->issue( __( 'Bloquant : catégorie manquante', 'ufsc-licence-competition' ), $name, $licensee, __( 'Affectez une catégorie avant la génération des combats.', 'ufsc-licence-competition' ) );
				}

				$entry_status = EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) );
				if ( in_array( $entry_status, EntriesWorkflow::get_review_queue_statuses(), true ) ) {
					$issues[] = $this->issue( __( 'Inscription à valider', 'ufsc-licence-competition' ), $name, $licensee, __( 'Validez, refusez ou corrigez cette inscription avant la génération officielle.', 'ufsc-licence-competition' ) );
				}

				if ( 'approved' === $entry_status ) {
					$row = $weighins[ $entry_id ] ?? null;
					if ( ! $this->weighins->is_valid_weighin_row( $row, (float) ( $competition->weight_tolerance ?? 1 ), isset( $entry->weight_kg ) ? (float) $entry->weight_kg : null ) ) {
						$issues[] = $this->issue( __( 'Bloquant : pesée manquante ou non validée', 'ufsc-licence-competition' ), $name, $licensee, __( 'Seuls les compétiteurs approuvés avec pesée validée doivent être inclus dans les combats officiels.', 'ufsc-licence-competition' ) );
					}
				}

				$club = trim( (string) ( $entry->club_name ?? $entry->club ?? '' ) );
				if ( '' === $club ) {
					$issues[] = $this->issue( __( 'Avertissement : club manquant', 'ufsc-licence-competition' ), $name, $licensee, __( 'Renseignez le club pour faciliter l’accueil, les impressions et les contrôles doublons.', 'ufsc-licence-competition' ) );
				}
				$weight = trim( (string) ( $entry->weight_kg ?? $entry->weight ?? '' ) );
				if ( '' === $weight || (float) str_replace( ',', '.', $weight ) <= 0 ) {
					$issues[] = $this->issue( __( 'Avertissement : poids déclaré manquant', 'ufsc-licence-competition' ), $name, $licensee, __( 'Complétez le poids déclaré ou saisissez une pesée validée.', 'ufsc-licence-competition' ) );
				}

				$duplicate_key = strtolower( trim( (string) ( $entry->license_number ?? $entry->licence_number ?? '' ) ) );
				if ( '' === $duplicate_key ) {
					$duplicate_key = strtolower( trim( (string) ( $entry->last_name ?? '' ) . '|' . ( $entry->first_name ?? '' ) . '|' . ( $entry->birth_date ?? $entry->date_naissance ?? '' ) ) );
				}
				if ( '' !== $duplicate_key && '||' !== $duplicate_key ) {
					if ( isset( $seen_fighters[ $duplicate_key ] ) ) {
						$issues[] = $this->issue( __( 'Avertissement : doublon possible', 'ufsc-licence-competition' ), $name, $licensee, sprintf( __( 'Cette inscription ressemble à %s. Vérifiez qu’il ne s’agit pas d’un doublon.', 'ufsc-licence-competition' ), $seen_fighters[ $duplicate_key ] ) );
					} else {
						$seen_fighters[ $duplicate_key ] = $licensee;
					}
				}
			}

			foreach ( $categories as $category ) {
				$cat_id = (int) ( $category->id ?? 0 );
				if ( $cat_id && isset( $category_usage[ $cat_id ] ) && 0 === (int) $category_usage[ $cat_id ] ) {
					$issues[] = $this->issue( __( 'Avertissement : catégorie vide', 'ufsc-licence-competition' ), $name, '', sprintf( __( 'La catégorie “%s” ne contient aucun inscrit.', 'ufsc-licence-competition' ), (string) ( $category->name ?? '#' . $cat_id ) ) );
				}
			}
		}

		return $issues;
	}

	private function issue( string $issue, string $competition, string $licensee, string $details ): array {
		return array(
			'issue'       => $issue,
			'competition' => $competition,
			'licensee'    => $licensee,
			'details'     => $details,
		);
	}

	private function get_visible_competitions(): array {
		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		return $this->competitions->list( $competition_filters, 200, 0 );
	}

}
