<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Services\CompetitionStatsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Menu {

	/**
	 * Slug racine du menu Compétitions.
	 */
	public const MENU_SLUG = 'ufsc-competitions';

	/**
	 * Constantes attendues par certaines pages (ex: Competitions_Page.php)
	 * pour éviter les "Undefined constant Menu::PAGE_*".
	 */
	public const PAGE_COMPETITIONS = 'ufsc-competitions';
	public const PAGE_CATEGORIES   = 'ufsc-competitions-categories';
	public const PAGE_ENTRIES      = 'ufsc-competitions-entries';
	public const PAGE_ENTRIES_IMPORT = 'ufsc-competitions-entries-import';
	public const PAGE_WEIGHINS = 'ufsc-competitions-weighins';
	public const PAGE_BOUTS        = 'ufsc-competitions-bouts';
	public const PAGE_PLATEAU      = 'ufsc-competitions-plateau';
	public const PAGE_TIMING      = 'ufsc-competitions-timing-profiles';
	public const PAGE_QUALITY      = 'ufsc-competitions-quality';
	public const PAGE_PRINT        = 'ufsc-competitions-print';
	public const PAGE_OFFICIALS    = 'ufsc-competitions-officials';
	public const PAGE_SENSITIVE_OPS = 'ufsc-competitions-sensitive-ops';
	public const PAGE_SETTINGS     = 'ufsc-competitions-settings';
	public const PAGE_LOGS         = 'ufsc-competitions-logs';
	public const PAGE_GUIDE        = 'ufsc-competitions-guide';
	public const PAGE_ACCESS_DIAGNOSTIC = 'ufsc-competitions-access-diagnostic';
	public const PAGE_ESTIMATION = 'ufsc-competitions-estimation';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_page_actions' ) );
	}

	public function add_menu(): void {
		$cap = \UFSC\Competitions\Capabilities::get_read_capability();
		$manage_cap = \UFSC\Competitions\Capabilities::get_edit_capability();
		$admin_cap = 'manage_options';

		add_menu_page(
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			$cap,
			self::MENU_SLUG,
			array( $this, 'render_root' ),
			'dashicons-awards',
			56
		);

		// Sous-menus (chargés seulement si les classes existent)
		$this->add_submenu_safe(
			$cap,
			self::PAGE_COMPETITIONS,
			__( 'Compétitions', 'ufsc-licence-competition' ),
			__( 'Compétitions', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Competitions_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_CATEGORIES,
			__( 'Catégories', 'ufsc-licence-competition' ),
			__( 'Catégories', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Categories_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_ENTRIES,
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			__( 'Inscriptions', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Entries_Page'
		);

		$this->add_submenu_safe(
			$manage_cap,
			self::PAGE_ENTRIES_IMPORT,
			__( 'Import CSV', 'ufsc-licence-competition' ),
			__( 'Import CSV', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Entries_Import_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_WEIGHINS,
			__( 'Pesées', 'ufsc-licence-competition' ),
			__( 'Pesées', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\WeighIns_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_BOUTS,
			__( 'Combats', 'ufsc-licence-competition' ),
			__( 'Combats', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Bouts_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_PLATEAU,
			__( 'Plateau jour J', 'ufsc-licence-competition' ),
			__( 'Plateau jour J', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Plateau_Page'
		);

		$this->add_submenu_safe(
			$manage_cap,
			self::PAGE_TIMING,
			__( 'Timing Profiles', 'ufsc-licence-competition' ),
			__( 'Timing Profiles', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Timing_Profiles_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_QUALITY,
			__( 'Qualité', 'ufsc-licence-competition' ),
			__( 'Qualité', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Quality_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_PRINT,
			__( 'Impression', 'ufsc-licence-competition' ),
			__( 'Impression', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Print_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_OFFICIALS,
			__( 'Officiels', 'ufsc-licence-competition' ),
			__( 'Officiels', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Officials_Page'
		);

		$this->add_submenu_safe(
			$manage_cap,
			self::PAGE_SENSITIVE_OPS,
			__( 'Actions sensibles', 'ufsc-licence-competition' ),
			__( 'Actions sensibles', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Sensitive_Operations_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_ESTIMATION,
			__( 'Estimation', 'ufsc-licence-competition' ),
			__( 'Estimation', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Estimation_Page'
		);

		$this->add_submenu_safe(
			$admin_cap,
			self::PAGE_SETTINGS,
			__( 'Paramètres', 'ufsc-licence-competition' ),
			__( 'Paramètres', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Settings_Page'
		);

		$this->add_submenu_safe(
			$manage_cap,
			self::PAGE_LOGS,
			__( 'Logs', 'ufsc-licence-competition' ),
			__( 'Logs', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\CompetitionLogs_Page'
		);

		$this->add_submenu_safe(
			$cap,
			self::PAGE_GUIDE,
			__( 'Aide & Documentation', 'ufsc-licence-competition' ),
			__( 'Aide', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Guide_Page'
		);

		$diagnostic_hook = $this->add_submenu_safe(
			$admin_cap,
			self::PAGE_ACCESS_DIAGNOSTIC,
			__( 'Diagnostic Accès', 'ufsc-licence-competition' ),
			__( 'Diagnostic Accès', 'ufsc-licence-competition' ),
			'UFSC\\Competitions\\Admin\\Pages\\Access_Diagnostic_Page'
		);
		if ( $diagnostic_hook && class_exists( 'UFSC_LC_Admin_Assets' ) ) {
			\UFSC_LC_Admin_Assets::register_page( $diagnostic_hook );
		}
	}

	private function add_submenu_safe( string $cap, string $slug, string $page_title, string $menu_title, string $page_class ): string {
		$callback = function() use ( $page_class ) {
			if ( class_exists( $page_class ) ) {
				$obj = new $page_class();

				if ( method_exists( $obj, 'render' ) ) {
					$obj->render();
					return;
				}
				if ( method_exists( $obj, 'output' ) ) {
					$obj->output();
					return;
				}
			}

			echo '<div class="wrap ufsc-competitions-admin">';
			echo '<h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<p><strong>' . esc_html__( 'Sous-menu non chargé :', 'ufsc-licence-competition' ) . '</strong> ' . esc_html( $page_class ) . '</p>';
			echo '<p>' . esc_html__( 'Vérifie que la classe existe et expose une méthode render() ou output().', 'ufsc-licence-competition' ) . '</p>';
			echo '</div>';
		};

		$hook_suffix = add_submenu_page(
			self::MENU_SLUG,
			$page_title,
			$menu_title,
			$cap,
			$slug,
			$callback
		);
		if ( $hook_suffix && class_exists( '\UFSC\Competitions\Admin\Assets' ) ) {
			$assets = new Assets();
			$assets->register( $hook_suffix, self::PAGE_COMPETITIONS === $slug, $slug );
		}

		return $hook_suffix ? (string) $hook_suffix : '';
	}

	public function register_page_actions(): void {
		$page_classes = array(
			'UFSC\\Competitions\\Admin\\Pages\\Competitions_Page',
			'UFSC\\Competitions\\Admin\\Pages\\Entries_Page',
			'UFSC\\Competitions\\Admin\\Pages\\Entries_Import_Page',
			'UFSC\Competitions\Admin\Pages\Bouts_Page',
			'UFSC\Competitions\Admin\Pages\Plateau_Page',
			'UFSC\\Competitions\\Admin\\Pages\\Settings_Page',
			'UFSC\\Competitions\\Admin\\Pages\\CompetitionLogs_Page',
			'UFSC\\Competitions\\Admin\\Pages\\Timing_Profiles_Page',
			'UFSC\\Competitions\\Admin\\Pages\\Access_Diagnostic_Page',
		);

		foreach ( $page_classes as $page_class ) {
			if ( ! class_exists( $page_class ) ) {
				continue;
			}
			$page = new $page_class();
			if ( method_exists( $page, 'register_actions' ) ) {
				$page->register_actions();
			}
		}
	}

	public function render_root(): void {
		if ( ! \UFSC\Competitions\Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0;
		$dashboard      = $this->build_dashboard_context( $competition_id );
		$competition_id = (int) ( $dashboard['competition_id'] ?? 0 );
		$competition    = $dashboard['competition'] ?? null;

		?>
		<div class="wrap ufsc-competitions-admin">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Centre de pilotage jour J', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Tableau de bord compétition', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Suivez l’état global d’une compétition officielle depuis un seul écran : inscriptions, pesées, combats, résultats et actions prioritaires.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>

			<form method="get" class="ufsc-admin-filters ufsc-dashboard-selector">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
				<label for="ufsc-dashboard-competition"><strong><?php esc_html_e( 'Compétition pilotée', 'ufsc-licence-competition' ); ?></strong></label>
				<select id="ufsc-dashboard-competition" name="competition_id">
					<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
					<?php foreach ( $dashboard['competitions'] as $item ) : ?>
						<option value="<?php echo esc_attr( (int) $item->id ); ?>" <?php selected( $competition_id, (int) $item->id ); ?>><?php echo esc_html( (string) $item->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Afficher le tableau de bord', 'ufsc-licence-competition' ); ?></button>
			</form>

			<?php if ( ! $competition ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Choisissez une compétition pour afficher les indicateurs opérationnels et les accès rapides.', 'ufsc-licence-competition' ); ?></p></div>
			<?php else : ?>
				<section class="ufsc-kpis ufsc-kpis--premium">
					<?php foreach ( $dashboard['kpis'] as $kpi ) : ?>
						<article class="ufsc-kpi">
							<span class="ufsc-kpi__label"><?php echo esc_html( $kpi['label'] ); ?></span>
							<strong class="ufsc-kpi__value"><?php echo esc_html( $kpi['value'] ); ?></strong>
							<?php if ( ! empty( $kpi['hint'] ) ) : ?><small><?php echo esc_html( $kpi['hint'] ); ?></small><?php endif; ?>
						</article>
					<?php endforeach; ?>
				</section>

				<div class="ufsc-admin-surface">
					<h2><?php echo esc_html( sprintf( __( 'État général — %s', 'ufsc-licence-competition' ), (string) $competition->name ) ); ?></h2>
					<?php if ( empty( $dashboard['alerts'] ) ) : ?>
						<div class="notice notice-success inline"><p><?php esc_html_e( 'Aucune alerte bloquante détectée sur les indicateurs principaux. Lancez tout de même le contrôle qualité complet avant génération officielle.', 'ufsc-licence-competition' ); ?></p></div>
					<?php else : ?>
						<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Points à vérifier avant le jour J :', 'ufsc-licence-competition' ); ?></strong></p><ul>
							<?php foreach ( $dashboard['alerts'] as $alert ) : ?><li><?php echo esc_html( $alert ); ?></li><?php endforeach; ?>
						</ul></div>
					<?php endif; ?>
				</div>

				<div class="ufsc-admin-surface">
					<h2><?php esc_html_e( 'Actions rapides', 'ufsc-licence-competition' ); ?></h2>
					<p><?php esc_html_e( 'Ces raccourcis conservent le contexte de compétition pour éviter les erreurs de sélection le jour de l’évènement.', 'ufsc-licence-competition' ); ?></p>
					<p class="ufsc-admin-page-actions">
						<?php foreach ( $this->get_dashboard_links( $competition_id ) as $link ) : ?>
							<a class="button<?php echo ! empty( $link['primary'] ) ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
						<?php endforeach; ?>
					</p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function build_dashboard_context( int $competition_id ): array {
		$competition_repo = new CompetitionRepository();
		$stats_service    = new CompetitionStatsService();

		$competition_filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$competition_filters = ufsc_lc_competitions_apply_scope_to_query_args( $competition_filters );
		}
		$competitions = $competition_repo->list( $competition_filters, 200, 0 );

		$competition = $competition_id ? $competition_repo->get( $competition_id, true ) : null;
		if ( $competition && method_exists( $competition_repo, 'assert_competition_in_scope' ) ) {
			$competition_repo->assert_competition_in_scope( $competition_id );
		}

		$stats = $competition ? $stats_service->get_competition_stats( $competition_id ) : $stats_service->get_competition_stats( 0 );
		$entries = $stats['entries'];
		$weighins = $stats['weighins'];
		$fights = $stats['fights'];
		$categories = $stats['categories'];

		$alerts = array();
		if ( $competition ) {
			if ( 0 === (int) $entries['total'] ) {
				$alerts[] = __( 'Aucune inscription enregistrée pour cette compétition.', 'ufsc-licence-competition' );
			}
			if ( 0 === (int) $categories['configured_count'] ) {
				if ( (int) $categories['text_distinct_count'] > 0 ) {
					$alerts[] = sprintf(
						__( 'Aucun référentiel catégories n’est configuré pour cette compétition. %d libellé(s) de catégorie existent dans les inscriptions : ce sont des données déclarées/importées, pas encore le référentiel utilisé pour la génération automatique.', 'ufsc-licence-competition' ),
						(int) $categories['text_distinct_count']
					);
				} else {
					$alerts[] = __( 'Aucun référentiel catégories n’est configuré : la génération officielle sera bloquée ou incomplète.', 'ufsc-licence-competition' );
				}
			}
			if ( (int) $entries['approved'] > 0 && (int) $weighins['remaining'] > 0 ) {
				$alerts[] = sprintf( __( '%d pesée(s) restent à valider dans le périmètre affiché par la page Pesées.', 'ufsc-licence-competition' ), (int) $weighins['remaining'] );
			}
			if ( (int) $fights['generated'] > 0 && 0 === (int) $fights['surfaces'] ) {
				$alerts[] = __( 'Des combats existent mais aucune surface/plateau n’est renseigné.', 'ufsc-licence-competition' );
			}
		}

		return array(
			'competition_id' => $competition_id,
			'competition'    => $competition,
			'competitions'   => $competitions,
			'alerts'         => $alerts,
			'stats'          => $stats,
			'kpis'           => array(
				array( 'label' => __( 'Inscriptions', 'ufsc-licence-competition' ), 'value' => number_format_i18n( (int) $entries['total'] ), 'hint' => sprintf( __( '%1$d approuvée(s), %2$d à valider', 'ufsc-licence-competition' ), (int) $entries['approved'], (int) $entries['to_validate'] ) ),
				array( 'label' => __( 'Pesées validées', 'ufsc-licence-competition' ), 'value' => number_format_i18n( (int) $weighins['validated'] ), 'hint' => sprintf( __( '%1$d visible(s), %2$d restante(s)', 'ufsc-licence-competition' ), (int) $weighins['visible_entries'], (int) $weighins['remaining'] ) ),
				array( 'label' => __( 'Combats générés', 'ufsc-licence-competition' ), 'value' => number_format_i18n( (int) $fights['generated'] ), 'hint' => sprintf( __( '%d plateau(x)', 'ufsc-licence-competition' ), (int) $fights['surfaces'] ) ),
				array( 'label' => __( 'Résultats saisis', 'ufsc-licence-competition' ), 'value' => number_format_i18n( (int) $fights['results_entered'] ), 'hint' => (int) $fights['generated'] > 0 ? sprintf( __( '%d à traiter', 'ufsc-licence-competition' ), max( 0, (int) $fights['generated'] - (int) $fights['results_entered'] ) ) : __( 'Aucun combat', 'ufsc-licence-competition' ) ),
			),
		);
	}

	private function get_dashboard_links( int $competition_id ): array {
		$base = array( 'competition_id' => $competition_id );
		return array(
			array( 'label' => __( 'Inscriptions', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_ENTRIES ) ), admin_url( 'admin.php' ) ) ),
			array( 'label' => __( 'Pesées', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_WEIGHINS ) ), admin_url( 'admin.php' ) ), 'primary' => true ),
			array( 'label' => __( 'Générer / gérer les combats', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_BOUTS ) ), admin_url( 'admin.php' ) ), 'primary' => true ),
			array( 'label' => __( 'Plateau jour J', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_PLATEAU ) ), admin_url( 'admin.php' ) ) ),
			array( 'label' => __( 'Impressions', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_PRINT ) ), admin_url( 'admin.php' ) ) ),
			array( 'label' => __( 'Contrôle qualité', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_QUALITY ) ), admin_url( 'admin.php' ) ) ),
			array( 'label' => __( 'Logs', 'ufsc-licence-competition' ), 'url' => add_query_arg( array_merge( $base, array( 'page' => self::PAGE_LOGS ) ), admin_url( 'admin.php' ) ) ),
		);
	}
}
