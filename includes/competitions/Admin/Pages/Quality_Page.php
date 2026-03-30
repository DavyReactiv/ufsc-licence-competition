<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Entries\EntriesWorkflow;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Admin\Tables\Quality_Table;
use UFSC\Competitions\Services\DisciplineRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Quality_Page {
	private $entries;
	private $competitions;

	public function __construct() {
		$this->entries = new EntryRepository();
		$this->competitions = new CompetitionRepository();
	}

	public function render() {
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$issues = $this->collect_issues();
		$table = new Quality_Table( $issues );
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
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Anomalies détectées', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( number_format_i18n( $issues_count ) ); ?></strong></article>
				<article class="ufsc-kpi"><span class="ufsc-kpi__label"><?php esc_html_e( 'Priorité', 'ufsc-licence-competition' ); ?></span><strong class="ufsc-kpi__value"><?php echo esc_html( $issues_count > 0 ? __( 'Action requise', 'ufsc-licence-competition' ) : __( 'OK', 'ufsc-licence-competition' ) ); ?></strong></article>
			</section>
			<div class="notice notice-info ufsc-competitions-helper"><p><?php esc_html_e( 'Détecter anomalies licences, catégories incohérentes, poids manquants.', 'ufsc-licence-competition' ); ?></p></div>
			<?php if ( 0 === $issues_count ) : ?>
				<div class="ufsc-empty-state">
					<h2><?php esc_html_e( 'Aucune anomalie détectée', 'ufsc-licence-competition' ); ?></h2>
					<p><?php esc_html_e( 'Tous les contrôles actuels sont conformes. Vous pouvez poursuivre la préparation des tableaux.', 'ufsc-licence-competition' ); ?></p>
				</div>
			<?php else : ?>
				<div class="ufsc-competitions-table-wrap">
					<?php $table->display(); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function collect_issues() {
		$entry_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
		}
		$entries = $this->entries->list( $entry_filters, 500, 0 );

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $this->competitions->list( $competition_filters, 200, 0 );
		$index = array();

		foreach ( $competitions as $competition ) {
			$index[ $competition->id ] = $competition;
		}

		$issues = array();
		foreach ( $entries as $entry ) {
			if ( ! empty( $entry->deleted_at ) ) {
				continue;
			}
			if ( empty( $entry->category_id ) ) {
				$competition = $index[ $entry->competition_id ] ?? null;
				$issues[] = array(
					'issue'       => __( 'Catégorie manquante', 'ufsc-licence-competition' ),
					'competition' => $competition ? $competition->name : '',
					'licensee'    => sprintf( '#%d', (int) $entry->licensee_id ),
					'details'     => $competition ? DisciplineRegistry::get_label( $competition->discipline ) : '',
				);
			}
			$entry_status = EntriesWorkflow::normalize_status( (string) ( $entry->status ?? '' ) );
			if ( in_array( $entry_status, EntriesWorkflow::get_review_queue_statuses(), true ) ) {
				$competition = $index[ $entry->competition_id ] ?? null;
				$issues[] = array(
					'issue'       => __( 'Inscription en attente de validation', 'ufsc-licence-competition' ),
					'competition' => $competition ? $competition->name : '',
					'licensee'    => sprintf( '#%d', (int) $entry->licensee_id ),
					'details'     => $competition ? DisciplineRegistry::get_label( $competition->discipline ) : '',
				);
			}
		}

		return $issues;
	}
}
