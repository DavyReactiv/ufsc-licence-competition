<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Admin\Pages\EventProgram_Page;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Services\CompetitionFilters;
use UFSC\Competitions\Services\EventProgramRegistry;
use UFSC\Competitions\Services\RegistrationScopePresenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared premium UX layer for every competition admin screen.
 *
 * This class deliberately owns navigation, global UI assets and contextual
 * summaries so individual pages do not copy the same presentation code.
 */
class PremiumExperience {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_menu', array( EventProgram_Page::class, 'register_menu' ), 80 );
		EventProgram_Page::register_actions();
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 50 );
		add_action( 'admin_notices', array( __CLASS__, 'render_workflow_navigation' ), 1 );
		add_action( 'admin_notices', array( __CLASS__, 'render_context_panel' ), 2 );
		add_filter( 'admin_body_class', array( __CLASS__, 'admin_body_class' ) );
	}

	public static function enqueue_assets(): void {
		if ( ! self::is_competition_admin_page() ) {
			return;
		}

		$base_css_file = UFSC_LC_DIR . 'includes/competitions/assets/admin.css';
		$css_file      = UFSC_LC_DIR . 'includes/competitions/assets/premium-admin.css';
		$js_file       = UFSC_LC_DIR . 'includes/competitions/assets/premium-admin.js';

		if ( file_exists( $base_css_file ) && ! wp_style_is( 'ufsc-competitions-admin', 'enqueued' ) ) {
			$base_css_mtime   = filemtime( $base_css_file );
			$base_css_version = false !== $base_css_mtime ? (string) $base_css_mtime : '1.0.0';
			wp_enqueue_style(
				'ufsc-competitions-admin',
				UFSC_LC_URL . 'includes/competitions/assets/admin.css',
				array(),
				$base_css_version
			);
		}

		if ( file_exists( $css_file ) ) {
			$css_mtime   = filemtime( $css_file );
			$css_version = false !== $css_mtime ? (string) $css_mtime : '1.0.0';
			wp_enqueue_style(
				'ufsc-competitions-premium-admin',
				UFSC_LC_URL . 'includes/competitions/assets/premium-admin.css',
				array( 'ufsc-competitions-admin' ),
				$css_version
			);
		}

		if ( file_exists( $js_file ) ) {
			$js_mtime   = filemtime( $js_file );
			$js_version = false !== $js_mtime ? (string) $js_mtime : '1.0.0';
			wp_enqueue_script(
				'ufsc-competitions-premium-admin',
				UFSC_LC_URL . 'includes/competitions/assets/premium-admin.js',
				array(),
				$js_version,
				true
			);
			wp_localize_script(
				'ufsc-competitions-premium-admin',
				'ufscPremiumAdmin',
				array(
					'regionModeLabel'              => __( 'Les inscriptions sont limitées par région.', 'ufsc-licence-competition' ),
					'regionAndDisciplineModeLabel' => __( 'Le club doit respecter la région ET la discipline.', 'ufsc-licence-competition' ),
					'clubModeLabel'                => __( 'Seuls les clubs sélectionnés peuvent inscrire.', 'ufsc-licence-competition' ),
					'affiliatedModeLabel'          => __( 'Tous les clubs affiliés qui remplissent les conditions peuvent inscrire.', 'ufsc-licence-competition' ),
					'emptyProgramLabel'            => __( 'Ajoutez au moins un bloc au programme.', 'ufsc-licence-competition' ),
				)
			);
		}
	}

	public static function admin_body_class( string $classes ): string {
		if ( self::is_competition_admin_page() ) {
			$classes .= ' ufsc-competitions-premium-ui';
		}
		return $classes;
	}

	public static function render_workflow_navigation(): void {
		if ( ! self::is_competition_admin_page() ) {
			return;
		}

		$page           = self::current_page();
		$competition_id = self::current_competition_id();
		$links          = self::workflow_links( $competition_id );

		?>
		<nav class="ufsc-premium-workflow" aria-label="<?php esc_attr_e( 'Parcours de gestion compétition', 'ufsc-licence-competition' ); ?>">
			<div class="ufsc-premium-workflow__brand">
				<span class="dashicons dashicons-awards" aria-hidden="true"></span>
				<div>
					<strong><?php esc_html_e( 'UFSC Compétitions', 'ufsc-licence-competition' ); ?></strong>
					<small><?php esc_html_e( 'Parcours administrateur', 'ufsc-licence-competition' ); ?></small>
				</div>
			</div>
			<div class="ufsc-premium-workflow__steps">
				<?php foreach ( $links as $link ) : ?>
					<?php $active = in_array( $page, (array) $link['pages'], true ); ?>
					<a class="ufsc-premium-workflow__step<?php echo $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( (string) $link['url'] ); ?>"<?php echo $active ? ' aria-current="page"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<span class="ufsc-premium-workflow__index"><?php echo esc_html( (string) $link['index'] ); ?></span>
						<span>
							<strong><?php echo esc_html( (string) $link['label'] ); ?></strong>
							<small><?php echo esc_html( (string) $link['hint'] ); ?></small>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		</nav>
		<?php
	}

	public static function render_context_panel(): void {
		if ( ! self::is_competition_admin_page() ) {
			return;
		}

		$page           = self::current_page();
		$competition_id = self::current_competition_id();
		if ( ! $competition_id ) {
			return;
		}

		if ( in_array( $page, array( 'ufsc-competitions-entries', 'ufsc-competitions-entries-import' ), true ) ) {
			self::render_registration_scope( $competition_id );
		}

		if ( in_array( $page, array( 'ufsc-competitions-bouts', 'ufsc-competitions-plateau' ), true ) ) {
			self::render_program_scope( $competition_id );
		}
	}

	private static function render_registration_scope( int $competition_id ): void {
		$scope = RegistrationScopePresenter::describe_for_competition( $competition_id );
		$tone  = sanitize_key( (string) ( $scope['tone'] ?? 'info' ) );
		if ( ! in_array( $tone, array( 'info', 'warning', 'success' ), true ) ) {
			$tone = 'info';
		}
		?>
		<section class="ufsc-premium-context-card is-<?php echo esc_attr( $tone ); ?>" aria-label="<?php esc_attr_e( 'Règles des inscriptions', 'ufsc-licence-competition' ); ?>">
			<div class="ufsc-premium-context-card__icon"><span class="dashicons dashicons-location-alt" aria-hidden="true"></span></div>
			<div class="ufsc-premium-context-card__body">
				<div class="ufsc-premium-context-card__titleline">
					<strong><?php echo esc_html( (string) ( $scope['title'] ?? '' ) ); ?></strong>
					<?php if ( ! empty( $scope['is_region_scoped'] ) ) : ?>
						<span class="ufsc-premium-tag"><?php esc_html_e( 'Filtrage régional actif', 'ufsc-licence-competition' ); ?></span>
					<?php endif; ?>
				</div>
				<p><?php echo esc_html( (string) ( $scope['summary'] ?? '' ) ); ?></p>
				<?php if ( ! empty( $scope['requirements'] ) ) : ?>
					<div class="ufsc-premium-context-card__meta">
						<span><?php esc_html_e( 'Conditions :', 'ufsc-licence-competition' ); ?></span>
						<strong><?php echo esc_html( implode( ' · ', (array) $scope['requirements'] ) ); ?></strong>
					</div>
				<?php endif; ?>
			</div>
			<a class="button" href="<?php echo esc_url( self::competition_edit_url( $competition_id ) ); ?>"><?php esc_html_e( 'Modifier les règles', 'ufsc-licence-competition' ); ?></a>
		</section>
		<?php
	}

	private static function render_program_scope( int $competition_id ): void {
		$repository  = new CompetitionRepository();
		$competition = $repository->get( $competition_id, true );
		if ( ! $competition ) {
			return;
		}
		$event_type = CompetitionFilters::normalize_type_key( (string) ( $competition->type ?? '' ) );
		$program    = EventProgramRegistry::get( $competition_id, $event_type );
		$summary    = EventProgramRegistry::get_summary( $program );
		/* translators: 1: active program blocks, 2: tournament blocks, 3: direct-fight blocks. */
		$summary_format = __( '%1$d bloc(s) actif(s) · %2$d mini-tournoi(s) · %3$d bloc(s) de combats directs.', 'ufsc-licence-competition' );
		$summary_text   = sprintf(
			$summary_format,
			(int) ( $summary['active_blocks'] ?? 0 ),
			(int) ( $summary['tournament_blocks'] ?? 0 ),
			(int) ( $summary['direct_blocks'] ?? 0 )
		);
		?>
		<section class="ufsc-premium-context-card is-program" aria-label="<?php esc_attr_e( 'Programme de l’événement', 'ufsc-licence-competition' ); ?>">
			<div class="ufsc-premium-context-card__icon"><span class="dashicons dashicons-editor-ol" aria-hidden="true"></span></div>
			<div class="ufsc-premium-context-card__body">
				<div class="ufsc-premium-context-card__titleline">
					<strong><?php esc_html_e( 'Programme événement', 'ufsc-licence-competition' ); ?></strong>
					<?php if ( ! empty( $summary['is_hybrid'] ) ) : ?>
						<span class="ufsc-premium-tag is-accent"><?php esc_html_e( 'Hybride', 'ufsc-licence-competition' ); ?></span>
					<?php endif; ?>
				</div>
				<p><?php echo esc_html( $summary_text ); ?></p>
			</div>
			<a class="button button-primary" href="<?php echo esc_url( self::program_url( $competition_id ) ); ?>"><?php esc_html_e( 'Ouvrir le programme', 'ufsc-licence-competition' ); ?></a>
		</section>
		<?php
	}

	private static function workflow_links( int $competition_id ): array {
		$query = $competition_id ? array( 'competition_id' => $competition_id ) : array();

		return array(
			array(
				'index' => 1,
				'label' => __( 'Pilotage', 'ufsc-licence-competition' ),
				'hint'  => __( 'Événement', 'ufsc-licence-competition' ),
				'pages' => array( 'ufsc-competitions' ),
				'url'   => add_query_arg( $query, admin_url( 'admin.php?page=ufsc-competitions' ) ),
			),
			array(
				'index' => 2,
				'label' => __( 'Inscriptions', 'ufsc-licence-competition' ),
				'hint'  => __( 'Clubs & athlètes', 'ufsc-licence-competition' ),
				'pages' => array( 'ufsc-competitions-entries', 'ufsc-competitions-entries-import' ),
				'url'   => add_query_arg( $query, admin_url( 'admin.php?page=ufsc-competitions-entries' ) ),
			),
			array(
				'index' => 3,
				'label' => __( 'Catégories', 'ufsc-licence-competition' ),
				'hint'  => __( 'Poids & pesées', 'ufsc-licence-competition' ),
				'pages' => array( 'ufsc-competitions-categories', 'ufsc-competitions-weighins' ),
				'url'   => add_query_arg( $query, admin_url( 'admin.php?page=ufsc-competitions-categories' ) ),
			),
			array(
				'index' => 4,
				'label' => __( 'Programme', 'ufsc-licence-competition' ),
				'hint'  => __( 'Carte & tournois', 'ufsc-licence-competition' ),
				'pages' => array( EventProgram_Page::PAGE_SLUG ),
				'url'   => self::program_url( $competition_id ),
			),
			array(
				'index' => 5,
				'label' => __( 'Combats', 'ufsc-licence-competition' ),
				'hint'  => __( 'Génération', 'ufsc-licence-competition' ),
				'pages' => array( 'ufsc-competitions-bouts' ),
				'url'   => add_query_arg( $query, admin_url( 'admin.php?page=ufsc-competitions-bouts' ) ),
			),
			array(
				'index' => 6,
				'label' => __( 'Jour J', 'ufsc-licence-competition' ),
				'hint'  => __( 'Plateau & résultats', 'ufsc-licence-competition' ),
				'pages' => array( 'ufsc-competitions-plateau', 'ufsc-competitions-results' ),
				'url'   => add_query_arg( $query, admin_url( 'admin.php?page=ufsc-competitions-plateau' ) ),
			),
		);
	}

	private static function current_page(): string {
		return isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	private static function current_competition_id(): int {
		foreach ( array( 'competition_id', 'id' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$id = absint( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( $id > 0 ) {
					return $id;
				}
			}
		}
		return 0;
	}

	private static function is_competition_admin_page(): bool {
		$page = self::current_page();
		return '' !== $page && 0 === strpos( $page, 'ufsc-competitions' );
	}

	private static function program_url( int $competition_id ): string {
		$args = array( 'page' => EventProgram_Page::PAGE_SLUG );
		if ( $competition_id ) {
			$args['competition_id'] = $competition_id;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function competition_edit_url( int $competition_id ): string {
		return add_query_arg(
			array(
				'page'        => 'ufsc-competitions',
				'ufsc_action' => 'edit',
				'id'          => absint( $competition_id ),
			),
			admin_url( 'admin.php' )
		);
	}
}
