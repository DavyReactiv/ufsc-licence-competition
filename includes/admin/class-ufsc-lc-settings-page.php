<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Settings_Page {
	const SETTINGS_GROUP = 'ufsc_lc_settings';
	const OPTION_DEFAULT_SEASON_END_YEAR = 'ufsc_lc_default_season_end_year';
	const OPTION_SEASON_RULE = 'ufsc_lc_season_rule';
	const OPTION_SEASON_START_MONTH = 'ufsc_lc_season_start_month';
	const OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD = 'ufsc_lc_asptt_auto_validate_threshold';
	const OPTION_ASPTT_ROLLBACK_ENABLED = 'ufsc_lc_asptt_enable_rollback';
	const OPTION_LICENCES_PER_PAGE = 'ufsc_lc_licences_per_page';
	const OPTION_CLUB_ACCESS_CAPABILITY = 'ufsc_lc_club_access_capability';
	const OPTION_MANAGE_CAPABILITY = 'ufsc_lc_manage_capability';
	const OPTION_IMPORT_CAPABILITY = 'ufsc_lc_import_capability';
	const OPTION_EXPORT_CAPABILITY = 'ufsc_lc_export_capability';
	const OPTION_PDF_REQUIRE_AUTH = 'ufsc_lc_pdf_require_auth';
	const OPTION_PDF_REQUIRE_CLUB_MATCH = 'ufsc_lc_pdf_require_club_match';
	const OPTION_PDF_ALLOW_DOWNLOAD = 'ufsc_lc_pdf_allow_download';
	const OPTION_ENABLE_LOGGING = 'ufsc_lc_enable_logging';
	const PAGE_SLUG = 'ufsc-lc-settings';
	const DEFAULT_SEASON_END_YEAR = 2026;
	const DEFAULT_SEASON_RULE = 'split';
	const DEFAULT_SEASON_START_MONTH = 9;
	const DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD = 0;
	const DEFAULT_LICENCES_PER_PAGE = 25;
	private $menu_missing = false;

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ), 30 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_admin_menu() {
		$parent_slug = UFSC_LC_Plugin::PARENT_SLUG;
		if ( empty( $parent_slug ) ) {
			$parent_slug = 'ufsc-licence-documents';
		}

		$hook_suffix = add_submenu_page(
			$parent_slug,
			__( 'Paramètres', 'ufsc-licence-competition' ),
			__( 'Paramètres', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::get_manage_capability(),
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $hook_suffix ) {
			UFSC_LC_Admin_Assets::register_page( $hook_suffix );
		} else {
			$this->menu_missing = true;
			add_action( 'admin_notices', array( $this, 'render_missing_menu_notice' ) );
		}
	}

	public function register_settings() {
		foreach ( $this->get_settings_registry() as $option => $args ) {
			register_setting( self::SETTINGS_GROUP, $option, $args );
		}

		$this->register_general_settings();
		$this->register_season_settings();
		$this->register_asptt_settings();
		$this->register_licence_settings();
		$this->register_club_settings();
		$this->register_security_settings();
		$this->register_pdf_settings();
		$this->register_log_settings();

		do_action( 'ufsc_lc_register_settings', $this );
	}

	private function register_general_settings() {
		add_settings_section(
			'ufsc_lc_settings_general',
			__( 'Configuration générale', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'general' )
		);

		add_settings_field(
			self::OPTION_DEFAULT_SEASON_END_YEAR,
			$this->get_label_with_tooltip(
				__( 'Année de fin de saison par défaut', 'ufsc-licence-competition' ),
				__( 'Utilisée pour les imports qui ne précisent pas de saison.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_default_season_field' ),
			$this->get_tab_page_slug( 'general' ),
			'ufsc_lc_settings_general'
		);
	}

	private function register_season_settings() {
		add_settings_section(
			'ufsc_lc_settings_seasons',
			__( 'Règles de saison', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'seasons' )
		);

		add_settings_field(
			self::OPTION_SEASON_RULE,
			$this->get_label_with_tooltip(
				__( 'Type de saison', 'ufsc-licence-competition' ),
				__( 'Définit la règle de calcul de la saison sportive.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_season_rule_field' ),
			$this->get_tab_page_slug( 'seasons' ),
			'ufsc_lc_settings_seasons'
		);

		add_settings_field(
			self::OPTION_SEASON_START_MONTH,
			$this->get_label_with_tooltip(
				__( 'Mois de début de saison', 'ufsc-licence-competition' ),
				__( 'Utilisé pour les calculs de catégories et de saison.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_season_start_month_field' ),
			$this->get_tab_page_slug( 'seasons' ),
			'ufsc_lc_settings_seasons'
		);
	}

	private function register_asptt_settings() {
		add_settings_section(
			'ufsc_lc_settings_asptt',
			__( 'Import ASPTT', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'asptt' )
		);

		add_settings_field(
			self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD,
			$this->get_label_with_tooltip(
				__( 'Seuil auto-validation', 'ufsc-licence-competition' ),
				__( 'Pourcentage de lignes correctement associées pour proposer l’auto-validation.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_asptt_threshold_field' ),
			$this->get_tab_page_slug( 'asptt' ),
			'ufsc_lc_settings_asptt'
		);

		add_settings_field(
			self::OPTION_ASPTT_ROLLBACK_ENABLED,
			$this->get_label_with_tooltip(
				__( 'Annulation (rollback)', 'ufsc-licence-competition' ),
				__( 'Autorise l’annulation du dernier import ASPTT.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_asptt_rollback_field' ),
			$this->get_tab_page_slug( 'asptt' ),
			'ufsc_lc_settings_asptt'
		);
	}

	private function register_licence_settings() {
		add_settings_section(
			'ufsc_lc_settings_licences',
			__( 'Licences', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'licences' )
		);

		add_settings_field(
			self::OPTION_LICENCES_PER_PAGE,
			$this->get_label_with_tooltip(
				__( 'Pagination par défaut', 'ufsc-licence-competition' ),
				__( 'Nombre de licences affichées par page (admin + espace club).', 'ufsc-licence-competition' )
			),
			array( $this, 'render_licences_per_page_field' ),
			$this->get_tab_page_slug( 'licences' ),
			'ufsc_lc_settings_licences'
		);
	}

	private function register_club_settings() {
		add_settings_section(
			'ufsc_lc_settings_clubs',
			__( 'Accès clubs', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'clubs' )
		);

		add_settings_field(
			self::OPTION_CLUB_ACCESS_CAPABILITY,
			$this->get_label_with_tooltip(
				__( 'Capacité requise', 'ufsc-licence-competition' ),
				__( 'Capacité WordPress requise pour consulter les licences côté club.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_club_access_capability_field' ),
			$this->get_tab_page_slug( 'clubs' ),
			'ufsc_lc_settings_clubs'
		);
	}

	private function register_security_settings() {
		add_settings_section(
			'ufsc_lc_settings_security',
			__( 'Sécurité & droits', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'security' )
		);

		add_settings_field(
			self::OPTION_MANAGE_CAPABILITY,
			$this->get_label_with_tooltip(
				__( 'Capacité de gestion', 'ufsc-licence-competition' ),
				__( 'Capacité minimale pour accéder aux outils d’administration UFSC.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_manage_capability_field' ),
			$this->get_tab_page_slug( 'security' ),
			'ufsc_lc_settings_security'
		);

		add_settings_field(
			self::OPTION_IMPORT_CAPABILITY,
			$this->get_label_with_tooltip(
				__( 'Capacité import', 'ufsc-licence-competition' ),
				__( 'Capacité minimale pour exécuter un import ASPTT.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_import_capability_field' ),
			$this->get_tab_page_slug( 'security' ),
			'ufsc_lc_settings_security'
		);

		add_settings_field(
			self::OPTION_EXPORT_CAPABILITY,
			$this->get_label_with_tooltip(
				__( 'Capacité export', 'ufsc-licence-competition' ),
				__( 'Capacité minimale pour exporter des licences.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_export_capability_field' ),
			$this->get_tab_page_slug( 'security' ),
			'ufsc_lc_settings_security'
		);
	}

	private function register_pdf_settings() {
		add_settings_section(
			'ufsc_lc_settings_pdf',
			__( 'PDF & documents', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'pdf' )
		);

		add_settings_field(
			self::OPTION_PDF_REQUIRE_AUTH,
			$this->get_label_with_tooltip(
				__( 'Authentification obligatoire', 'ufsc-licence-competition' ),
				__( 'Impose une session connectée pour accéder aux PDF.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_pdf_require_auth_field' ),
			$this->get_tab_page_slug( 'pdf' ),
			'ufsc_lc_settings_pdf'
		);

		add_settings_field(
			self::OPTION_PDF_REQUIRE_CLUB_MATCH,
			$this->get_label_with_tooltip(
				__( 'Limiter aux clubs propriétaires', 'ufsc-licence-competition' ),
				__( 'Restreint l’accès aux PDF au club rattaché à la licence.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_pdf_require_club_match_field' ),
			$this->get_tab_page_slug( 'pdf' ),
			'ufsc_lc_settings_pdf'
		);

		add_settings_field(
			self::OPTION_PDF_ALLOW_DOWNLOAD,
			$this->get_label_with_tooltip(
				__( 'Téléchargement PDF', 'ufsc-licence-competition' ),
				__( 'Autorise le téléchargement direct depuis l’espace club.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_pdf_allow_download_field' ),
			$this->get_tab_page_slug( 'pdf' ),
			'ufsc_lc_settings_pdf'
		);
	}

	private function register_log_settings() {
		add_settings_section(
			'ufsc_lc_settings_logs',
			__( 'Journal & maintenance', 'ufsc-licence-competition' ),
			'__return_false',
			$this->get_tab_page_slug( 'logs' )
		);

		add_settings_field(
			self::OPTION_ENABLE_LOGGING,
			$this->get_label_with_tooltip(
				__( 'Activer le journal', 'ufsc-licence-competition' ),
				__( 'Force la journalisation UFSC LC même si WP_DEBUG est désactivé.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_logging_field' ),
			$this->get_tab_page_slug( 'logs' ),
			'ufsc_lc_settings_logs'
		);
	}

	private function get_settings_registry() {
		return array(
			self::OPTION_DEFAULT_SEASON_END_YEAR      => array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_SEASON_END_YEAR,
				'sanitize_callback' => array( $this, 'sanitize_default_season_end_year' ),
			),
			self::OPTION_SEASON_RULE                 => array(
				'type'              => 'string',
				'default'           => self::DEFAULT_SEASON_RULE,
				'sanitize_callback' => array( $this, 'sanitize_season_rule' ),
			),
			self::OPTION_SEASON_START_MONTH          => array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_SEASON_START_MONTH,
				'sanitize_callback' => array( $this, 'sanitize_season_start_month' ),
			),
			self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD => array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD,
				'sanitize_callback' => array( $this, 'sanitize_asptt_threshold' ),
			),
			self::OPTION_ASPTT_ROLLBACK_ENABLED      => array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			),
			self::OPTION_LICENCES_PER_PAGE           => array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_LICENCES_PER_PAGE,
				'sanitize_callback' => array( $this, 'sanitize_licences_per_page' ),
			),
			self::OPTION_CLUB_ACCESS_CAPABILITY      => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( $this, 'sanitize_club_access_capability' ),
			),
			self::OPTION_MANAGE_CAPABILITY           => array(
				'type'              => 'string',
				'default'           => UFSC_LC_Capabilities::MANAGE_CAPABILITY,
				'sanitize_callback' => array( $this, 'sanitize_manage_capability' ),
			),
			self::OPTION_IMPORT_CAPABILITY           => array(
				'type'              => 'string',
				'default'           => UFSC_LC_Capabilities::IMPORT_CAPABILITY,
				'sanitize_callback' => array( $this, 'sanitize_import_capability' ),
			),
			self::OPTION_EXPORT_CAPABILITY           => array(
				'type'              => 'string',
				'default'           => UFSC_LC_Capabilities::EXPORT_CAPABILITY,
				'sanitize_callback' => array( $this, 'sanitize_export_capability' ),
			),
			self::OPTION_PDF_REQUIRE_AUTH            => array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			),
			self::OPTION_PDF_REQUIRE_CLUB_MATCH      => array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			),
			self::OPTION_PDF_ALLOW_DOWNLOAD          => array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			),
			self::OPTION_ENABLE_LOGGING              => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'sanitize_boolean' ),
			),
		);
	}

	public function sanitize_default_season_end_year( $value ) {
		$year = UFSC_LC_Categories::sanitize_season_end_year( $value );
		if ( null === $year ) {
			add_settings_error(
				self::OPTION_DEFAULT_SEASON_END_YEAR,
				'invalid_season_end_year',
				__( 'Année de fin de saison invalide.', 'ufsc-licence-competition' )
			);
			return $this->get_default_season_end_year();
		}

		return $year;
	}

	public function sanitize_season_rule( $value ) {
		$value = sanitize_key( $value );
		$allowed = array( 'split', 'calendar' );
		if ( ! in_array( $value, $allowed, true ) ) {
			add_settings_error(
				self::OPTION_SEASON_RULE,
				'invalid_season_rule',
				__( 'Règle de saison invalide.', 'ufsc-licence-competition' )
			);
			return self::DEFAULT_SEASON_RULE;
		}

		return $value;
	}

	public function sanitize_season_start_month( $value ) {
		$month = absint( $value );
		if ( $month < 1 || $month > 12 ) {
			add_settings_error(
				self::OPTION_SEASON_START_MONTH,
				'invalid_season_month',
				__( 'Mois de début de saison invalide.', 'ufsc-licence-competition' )
			);
			return self::DEFAULT_SEASON_START_MONTH;
		}

		return $month;
	}

	public function sanitize_asptt_threshold( $value ) {
		$threshold = absint( $value );
		if ( $threshold < 0 || $threshold > 100 ) {
			add_settings_error(
				self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD,
				'invalid_threshold',
				__( 'Seuil d’auto-validation invalide.', 'ufsc-licence-competition' )
			);
			return self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD;
		}

		return $threshold;
	}

	public function sanitize_licences_per_page( $value ) {
		$per_page = absint( $value );
		$allowed  = array( 25, 50, 100 );
		if ( ! in_array( $per_page, $allowed, true ) ) {
			add_settings_error(
				self::OPTION_LICENCES_PER_PAGE,
				'invalid_per_page',
				__( 'Pagination invalide.', 'ufsc-licence-competition' )
			);
			return self::DEFAULT_LICENCES_PER_PAGE;
		}

		return $per_page;
	}

	public function sanitize_club_access_capability( $value ) {
		$capability = sanitize_key( $value );
		return $capability;
	}

	public function sanitize_manage_capability( $value ) {
		return $this->sanitize_capability_from_allowed(
			$value,
			array( UFSC_LC_Capabilities::MANAGE_CAPABILITY, UFSC_LC_Capabilities::LEGACY_CAPABILITY, 'manage_options' ),
			self::OPTION_MANAGE_CAPABILITY,
			__( 'Capacité de gestion invalide.', 'ufsc-licence-competition' )
		);
	}

	public function sanitize_import_capability( $value ) {
		return $this->sanitize_capability_from_allowed(
			$value,
			array( UFSC_LC_Capabilities::IMPORT_CAPABILITY, UFSC_LC_Capabilities::MANAGE_CAPABILITY, 'manage_options' ),
			self::OPTION_IMPORT_CAPABILITY,
			__( 'Capacité d’import invalide.', 'ufsc-licence-competition' )
		);
	}

	public function sanitize_export_capability( $value ) {
		return $this->sanitize_capability_from_allowed(
			$value,
			array( UFSC_LC_Capabilities::EXPORT_CAPABILITY, UFSC_LC_Capabilities::MANAGE_CAPABILITY, 'manage_options' ),
			self::OPTION_EXPORT_CAPABILITY,
			__( 'Capacité d’export invalide.', 'ufsc-licence-competition' )
		);
	}

	private function sanitize_capability_from_allowed( $value, $allowed, $option_name, $error_message ) {
		$capability = sanitize_key( $value );
		if ( ! in_array( $capability, $allowed, true ) ) {
			add_settings_error( $option_name, 'invalid_capability', $error_message );
			return $allowed[0];
		}

		return $capability;
	}

	public function sanitize_boolean( $value ) {
		return ! empty( $value ) ? 1 : 0;
	}

	public function render_settings_page() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$tabs       = $this->get_tabs();
		$active_tab = $this->get_active_tab( $tabs );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Paramètres', 'ufsc-licence-competition' ); ?></h1>
			<?php $this->render_tabs( $tabs, $active_tab ); ?>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( $this->get_tab_page_slug( $active_tab ) );
				submit_button( __( 'Enregistrer', 'ufsc-licence-competition' ) );
				?>
			</form>
			<?php $this->render_tab_footer( $active_tab ); ?>
		</div>
		<?php
	}

	private function render_tabs( $tabs, $active_tab ) {
		?>
		<h2 class="nav-tab-wrapper">
			<?php foreach ( $tabs as $tab_id => $label ) : ?>
				<?php
				$url = add_query_arg(
					array(
						'page' => self::PAGE_SLUG,
						'tab'  => $tab_id,
					),
					admin_url( 'admin.php' )
				);
				?>
				<a class="nav-tab <?php echo $tab_id === $active_tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $url ); ?>">
					<?php echo esc_html( $label ); ?>
				</a>
			<?php endforeach; ?>
		</h2>
		<?php
	}

	private function render_tab_footer( $active_tab ) {
		if ( 'logs' !== $active_tab ) {
			return;
		}

		printf(
			'<p class="description">%s</p>',
			wp_kses_post(
				sprintf(
					__( 'Consultez la page <a href="%s">Statut UFSC LC</a> pour les actions de maintenance avancées.', 'ufsc-licence-competition' ),
					esc_url( admin_url( 'admin.php?page=ufsc-lc-status' ) )
				)
			)
		);
	}

	private function get_tabs() {
		$tabs = array(
			'general'  => __( 'Général', 'ufsc-licence-competition' ),
			'seasons'  => __( 'Saisons & Catégories', 'ufsc-licence-competition' ),
			'asptt'    => __( 'Import ASPTT', 'ufsc-licence-competition' ),
			'licences' => __( 'Licences', 'ufsc-licence-competition' ),
			'clubs'    => __( 'Clubs', 'ufsc-licence-competition' ),
			'security' => __( 'Sécurité & droits', 'ufsc-licence-competition' ),
			'pdf'      => __( 'PDF & documents', 'ufsc-licence-competition' ),
			'logs'     => __( 'Journal & maintenance', 'ufsc-licence-competition' ),
		);

		return apply_filters( 'ufsc_lc_settings_tabs', $tabs );
	}

	private function get_active_tab( $tabs ) {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		return array_key_exists( $tab, $tabs ) ? $tab : 'general';
	}

	private function get_tab_page_slug( $tab ) {
		return self::PAGE_SLUG . '_' . $tab;
	}

	private function get_label_with_tooltip( $label, $tooltip ) {
		return sprintf(
			'%s %s',
			esc_html( $label ),
			$this->get_tooltip_html( $tooltip )
		);
	}

	private function get_tooltip_html( $text ) {
		return sprintf(
			'<span class="dashicons dashicons-editor-help ufsc-lc-tooltip" title="%1$s" aria-hidden="true"></span><span class="screen-reader-text">%2$s</span>',
			esc_attr( $text ),
			esc_html( $text )
		);
	}

	public function render_default_season_field() {
		$value = $this->get_default_season_end_year();
		?>
		<input
			type="number"
			min="2000"
			max="2100"
			name="<?php echo esc_attr( self::OPTION_DEFAULT_SEASON_END_YEAR ); ?>"
			id="<?php echo esc_attr( self::OPTION_DEFAULT_SEASON_END_YEAR ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Année de fin de saison utilisée quand le CSV ne fournit pas la saison.', 'ufsc-licence-competition' ); ?>
		</p>
		<?php
	}

	public function render_season_rule_field() {
		$value = self::get_option_value( self::OPTION_SEASON_RULE, self::DEFAULT_SEASON_RULE );
		?>
		<select name="<?php echo esc_attr( self::OPTION_SEASON_RULE ); ?>" id="<?php echo esc_attr( self::OPTION_SEASON_RULE ); ?>">
			<option value="split" <?php selected( $value, 'split' ); ?>>
				<?php esc_html_e( 'Saison sportive (année N/N+1)', 'ufsc-licence-competition' ); ?>
			</option>
			<option value="calendar" <?php selected( $value, 'calendar' ); ?>>
				<?php esc_html_e( 'Année civile (janvier-décembre)', 'ufsc-licence-competition' ); ?>
			</option>
		</select>
		<p class="description">
			<?php esc_html_e( 'Utilisé pour préparer la logique de calcul des saisons.', 'ufsc-licence-competition' ); ?>
		</p>
		<?php
	}

	public function render_season_start_month_field() {
		$value = (int) self::get_option_value( self::OPTION_SEASON_START_MONTH, self::DEFAULT_SEASON_START_MONTH );
		global $wp_locale;
		?>
		<select name="<?php echo esc_attr( self::OPTION_SEASON_START_MONTH ); ?>" id="<?php echo esc_attr( self::OPTION_SEASON_START_MONTH ); ?>">
			<?php for ( $month = 1; $month <= 12; $month++ ) : ?>
				<option value="<?php echo esc_attr( $month ); ?>" <?php selected( $value, $month ); ?>>
					<?php echo esc_html( $wp_locale->get_month( $month ) ); ?>
				</option>
			<?php endfor; ?>
		</select>
		<p class="description">
			<?php esc_html_e( 'Mois de référence pour déclencher une nouvelle saison.', 'ufsc-licence-competition' ); ?>
		</p>
		<?php
	}

	public function render_asptt_threshold_field() {
		$value = (int) self::get_option_value( self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD, self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD );
		?>
		<input
			type="number"
			min="0"
			max="100"
			step="1"
			name="<?php echo esc_attr( self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD ); ?>"
			id="<?php echo esc_attr( self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		>
		<span class="description">%</span>
		<p class="description">
			<?php esc_html_e( '0 = toujours proposer l’auto-validation.', 'ufsc-licence-competition' ); ?>
		</p>
		<?php
	}

	public function render_asptt_rollback_field() {
		$value = self::is_asptt_rollback_enabled();
		?>
		<label for="<?php echo esc_attr( self::OPTION_ASPTT_ROLLBACK_ENABLED ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ASPTT_ROLLBACK_ENABLED ); ?>" id="<?php echo esc_attr( self::OPTION_ASPTT_ROLLBACK_ENABLED ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Autoriser l’annulation du dernier import.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_licences_per_page_field() {
		$value   = (int) self::get_option_value( self::OPTION_LICENCES_PER_PAGE, self::DEFAULT_LICENCES_PER_PAGE );
		$options = array( 25, 50, 100 );
		?>
		<select name="<?php echo esc_attr( self::OPTION_LICENCES_PER_PAGE ); ?>" id="<?php echo esc_attr( self::OPTION_LICENCES_PER_PAGE ); ?>">
			<?php foreach ( $options as $option ) : ?>
				<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $value, $option ); ?>>
					<?php echo esc_html( $option ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_club_access_capability_field() {
		$value = self::get_club_access_capability();
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_CLUB_ACCESS_CAPABILITY ); ?>"
			id="<?php echo esc_attr( self::OPTION_CLUB_ACCESS_CAPABILITY ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="<?php esc_attr_e( 'ex: read', 'ufsc-licence-competition' ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Laisser vide pour autoriser tous les utilisateurs connectés du club.', 'ufsc-licence-competition' ); ?>
		</p>
		<?php
	}

	public function render_manage_capability_field() {
		$value   = self::get_manage_capability();
		$options = array(
			UFSC_LC_Capabilities::MANAGE_CAPABILITY => __( 'UFSC — Gestion (ufsc_lc_manage)', 'ufsc-licence-competition' ),
			'manage_options'                        => __( 'Administrateur (manage_options)', 'ufsc-licence-competition' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_MANAGE_CAPABILITY ); ?>" id="<?php echo esc_attr( self::OPTION_MANAGE_CAPABILITY ); ?>">
			<?php foreach ( $options as $option_value => $label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_import_capability_field() {
		$value   = self::get_import_capability();
		$options = array(
			UFSC_LC_Capabilities::IMPORT_CAPABILITY => __( 'UFSC — Import (ufsc_lc_import)', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::MANAGE_CAPABILITY => __( 'UFSC — Gestion (ufsc_lc_manage)', 'ufsc-licence-competition' ),
			'manage_options'                        => __( 'Administrateur (manage_options)', 'ufsc-licence-competition' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_IMPORT_CAPABILITY ); ?>" id="<?php echo esc_attr( self::OPTION_IMPORT_CAPABILITY ); ?>">
			<?php foreach ( $options as $option_value => $label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_export_capability_field() {
		$value   = self::get_export_capability();
		$options = array(
			UFSC_LC_Capabilities::EXPORT_CAPABILITY => __( 'UFSC — Export (ufsc_lc_export)', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::MANAGE_CAPABILITY => __( 'UFSC — Gestion (ufsc_lc_manage)', 'ufsc-licence-competition' ),
			'manage_options'                        => __( 'Administrateur (manage_options)', 'ufsc-licence-competition' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_EXPORT_CAPABILITY ); ?>" id="<?php echo esc_attr( self::OPTION_EXPORT_CAPABILITY ); ?>">
			<?php foreach ( $options as $option_value => $label ) : ?>
				<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public function render_pdf_require_auth_field() {
		$value = self::is_pdf_auth_required();
		?>
		<label for="<?php echo esc_attr( self::OPTION_PDF_REQUIRE_AUTH ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_PDF_REQUIRE_AUTH ); ?>" id="<?php echo esc_attr( self::OPTION_PDF_REQUIRE_AUTH ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Restreindre l’accès aux utilisateurs connectés.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_pdf_require_club_match_field() {
		$value = self::is_pdf_club_match_required();
		?>
		<label for="<?php echo esc_attr( self::OPTION_PDF_REQUIRE_CLUB_MATCH ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_PDF_REQUIRE_CLUB_MATCH ); ?>" id="<?php echo esc_attr( self::OPTION_PDF_REQUIRE_CLUB_MATCH ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Valider l’appartenance au club.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_pdf_allow_download_field() {
		$value = self::is_pdf_download_allowed();
		?>
		<label for="<?php echo esc_attr( self::OPTION_PDF_ALLOW_DOWNLOAD ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_PDF_ALLOW_DOWNLOAD ); ?>" id="<?php echo esc_attr( self::OPTION_PDF_ALLOW_DOWNLOAD ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Afficher le bouton de téléchargement dans l’espace club.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_logging_field() {
		$value = self::is_logging_enabled();
		?>
		<label for="<?php echo esc_attr( self::OPTION_ENABLE_LOGGING ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( self::OPTION_ENABLE_LOGGING ); ?>" id="<?php echo esc_attr( self::OPTION_ENABLE_LOGGING ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Activer la journalisation UFSC LC.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	private function get_default_season_end_year() {
		$option = get_option( self::OPTION_DEFAULT_SEASON_END_YEAR, self::DEFAULT_SEASON_END_YEAR );
		$year   = UFSC_LC_Categories::sanitize_season_end_year( $option );

		return $year ? $year : self::DEFAULT_SEASON_END_YEAR;
	}

	public function render_missing_menu_notice() {
		if ( ! $this->menu_missing ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'La page Paramètres UFSC LC n’a pas pu être enregistrée. Vérifiez le slug du menu parent.', 'ufsc-licence-competition' )
		);
	}

	public static function get_option_value( $option, $default = null ) {
		$value = get_option( $option, $default );
		return apply_filters( 'ufsc_lc_setting_value', $value, $option, $default );
	}

	public static function get_asptt_auto_validate_threshold() {
		$threshold = absint( self::get_option_value( self::OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD, self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD ) );
		if ( $threshold < 0 || $threshold > 100 ) {
			return self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD;
		}

		return $threshold;
	}

	public static function is_asptt_rollback_enabled() {
		return (bool) self::get_option_value( self::OPTION_ASPTT_ROLLBACK_ENABLED, true );
	}

	public static function get_licences_per_page() {
		$per_page = absint( self::get_option_value( self::OPTION_LICENCES_PER_PAGE, self::DEFAULT_LICENCES_PER_PAGE ) );
		if ( ! in_array( $per_page, array( 25, 50, 100 ), true ) ) {
			return self::DEFAULT_LICENCES_PER_PAGE;
		}

		return $per_page;
	}

	public static function get_club_access_capability() {
		return sanitize_key( (string) self::get_option_value( self::OPTION_CLUB_ACCESS_CAPABILITY, '' ) );
	}

	public static function get_manage_capability() {
		$capability = sanitize_key( (string) self::get_option_value( self::OPTION_MANAGE_CAPABILITY, UFSC_LC_Capabilities::MANAGE_CAPABILITY ) );
		$allowed    = array( UFSC_LC_Capabilities::MANAGE_CAPABILITY, UFSC_LC_Capabilities::LEGACY_CAPABILITY, 'manage_options' );

		return in_array( $capability, $allowed, true ) ? $capability : UFSC_LC_Capabilities::MANAGE_CAPABILITY;
	}

	public static function get_import_capability() {
		$capability = sanitize_key( (string) self::get_option_value( self::OPTION_IMPORT_CAPABILITY, UFSC_LC_Capabilities::IMPORT_CAPABILITY ) );
		$allowed    = array( UFSC_LC_Capabilities::IMPORT_CAPABILITY, UFSC_LC_Capabilities::MANAGE_CAPABILITY, 'manage_options' );

		return in_array( $capability, $allowed, true ) ? $capability : UFSC_LC_Capabilities::IMPORT_CAPABILITY;
	}

	public static function get_export_capability() {
		$capability = sanitize_key( (string) self::get_option_value( self::OPTION_EXPORT_CAPABILITY, UFSC_LC_Capabilities::EXPORT_CAPABILITY ) );
		$allowed    = array( UFSC_LC_Capabilities::EXPORT_CAPABILITY, UFSC_LC_Capabilities::MANAGE_CAPABILITY, 'manage_options' );

		return in_array( $capability, $allowed, true ) ? $capability : UFSC_LC_Capabilities::EXPORT_CAPABILITY;
	}

	public static function is_pdf_auth_required() {
		return (bool) self::get_option_value( self::OPTION_PDF_REQUIRE_AUTH, true );
	}

	public static function is_pdf_club_match_required() {
		return (bool) self::get_option_value( self::OPTION_PDF_REQUIRE_CLUB_MATCH, true );
	}

	public static function is_pdf_download_allowed() {
		return (bool) self::get_option_value( self::OPTION_PDF_ALLOW_DOWNLOAD, true );
	}

	public static function is_logging_enabled() {
		return (bool) self::get_option_value( self::OPTION_ENABLE_LOGGING, false );
	}
}
