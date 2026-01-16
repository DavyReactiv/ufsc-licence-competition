<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Settings_Page {
	const SETTINGS_GROUP = 'ufsc_lc_settings';
	const OPTION_DEFAULT_SEASON_END_YEAR = 'ufsc_lc_default_season_end_year';
	const PAGE_SLUG = 'ufsc-lc-settings';
	const DEFAULT_SEASON_END_YEAR = 2026;

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_admin_menu() {
		add_submenu_page(
			UFSC_LC_Plugin::PARENT_SLUG,
			__( 'Paramètres', 'ufsc-licence-competition' ),
			__( 'Paramètres', 'ufsc-licence-competition' ),
			UFSC_LC_Capabilities::MANAGE_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DEFAULT_SEASON_END_YEAR,
			array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_SEASON_END_YEAR,
				'sanitize_callback' => array( $this, 'sanitize_default_season_end_year' ),
			)
		);

		add_settings_section(
			'ufsc_lc_settings_main',
			__( 'Paramètres', 'ufsc-licence-competition' ),
			'__return_false',
			self::PAGE_SLUG
		);

		add_settings_field(
			self::OPTION_DEFAULT_SEASON_END_YEAR,
			__( 'Année de fin de saison par défaut', 'ufsc-licence-competition' ),
			array( $this, 'render_default_season_field' ),
			self::PAGE_SLUG,
			'ufsc_lc_settings_main'
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

	public function render_settings_page() {
		if ( ! UFSC_LC_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Paramètres', 'ufsc-licence-competition' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Enregistrer', 'ufsc-licence-competition' ) );
				?>
			</form>
		</div>
		<?php
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

	private function get_default_season_end_year() {
		$option = get_option( self::OPTION_DEFAULT_SEASON_END_YEAR, self::DEFAULT_SEASON_END_YEAR );
		$year   = UFSC_LC_Categories::sanitize_season_end_year( $option );

		return $year ? $year : self::DEFAULT_SEASON_END_YEAR;
	}
}
