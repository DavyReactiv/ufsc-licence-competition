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
			if ( empty( $categories ) ) {
				$issues[] = $this->issue( __( 'Bloquant : aucune catégorie', 'ufsc-licence-competition' ), $name, '', __( 'Ajoutez les catégories avant d’ouvrir ou générer officiellement les combats.', 'ufsc-licence-competition' ) );
			}

			$entry_filters = array( 'view' => 'all', 'competition_id' => $cid );
			if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
				$entry_filters = ufsc_lc_competitions_apply_scope_to_query_args( $entry_filters );
			}
			$entries = $this->entries->list_with_details( $entry_filters, 5000, 0 );
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
