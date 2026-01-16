<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'ufsc_lc_get_settings_defaults' ) ) {
	function ufsc_lc_get_settings_defaults() {
		return array(
			UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR       => UFSC_LC_Settings_Page::DEFAULT_SEASON_END_YEAR,
			UFSC_LC_Settings_Page::SETTING_SEASON_RULE                  => UFSC_LC_Settings_Page::DEFAULT_SEASON_RULE,
			UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH           => UFSC_LC_Settings_Page::DEFAULT_SEASON_START_MONTH,
			UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE               => UFSC_LC_Settings_Page::DEFAULT_LICENCES_PER_PAGE,
			UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY            => UFSC_LC_Capabilities::MANAGE_CAPABILITY,
			UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY            => UFSC_LC_Capabilities::IMPORT_CAPABILITY,
			UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY            => UFSC_LC_Capabilities::EXPORT_CAPABILITY,
			UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD => UFSC_LC_Settings_Page::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD,
			UFSC_LC_Settings_Page::SETTING_ALLOW_IMPORT_ROLLBACK        => 1,
			UFSC_LC_Settings_Page::SETTING_ENABLE_LOGS                  => 0,
			UFSC_LC_Settings_Page::SETTING_CLUB_ACCESS_CAPABILITY       => '',
			UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_AUTH             => 1,
			UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_CLUB_MATCH       => 1,
			UFSC_LC_Settings_Page::SETTING_PDF_ALLOW_DOWNLOAD           => 1,
		);
	}
}

if ( ! function_exists( 'ufsc_lc_get_settings' ) ) {
	function ufsc_lc_get_settings() {
		$defaults = ufsc_lc_get_settings_defaults();
		$settings = get_option( UFSC_LC_Settings_Page::SETTINGS_OPTION, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$legacy = array();
		if ( empty( $settings ) ) {
			$legacy = ufsc_lc_get_legacy_settings();
		}

		$settings = wp_parse_args( $settings, wp_parse_args( $legacy, $defaults ) );

		return $settings;
	}
}

if ( ! function_exists( 'ufsc_lc_get_legacy_settings' ) ) {
	function ufsc_lc_get_legacy_settings() {
		$legacy = array();

		$season_end = UFSC_LC_Categories::sanitize_season_end_year(
			get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_DEFAULT_SEASON_END_YEAR,
				UFSC_LC_Settings_Page::DEFAULT_SEASON_END_YEAR
			)
		);
		if ( $season_end ) {
			$legacy[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ] = $season_end;
		}

		$season_rule = sanitize_key(
			(string) get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_SEASON_RULE,
				UFSC_LC_Settings_Page::DEFAULT_SEASON_RULE
			)
		);
		if ( in_array( $season_rule, array( 'split', 'calendar' ), true ) ) {
			$legacy[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ] = $season_rule;
		}

		$season_start_month = absint(
			get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_SEASON_START_MONTH,
				UFSC_LC_Settings_Page::DEFAULT_SEASON_START_MONTH
			)
		);
		if ( $season_start_month >= 1 && $season_start_month <= 12 ) {
			$legacy[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ] = $season_start_month;
		}

		$threshold = absint(
			get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD,
				UFSC_LC_Settings_Page::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD
			)
		);
		if ( $threshold >= 0 && $threshold <= 100 ) {
			$legacy[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ] = $threshold;
		}

		$legacy[ UFSC_LC_Settings_Page::SETTING_ALLOW_IMPORT_ROLLBACK ] = ! empty(
			get_option( UFSC_LC_Settings_Page::LEGACY_OPTION_ASPTT_ROLLBACK_ENABLED, true )
		) ? 1 : 0;

		$per_page = absint(
			get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_LICENCES_PER_PAGE,
				UFSC_LC_Settings_Page::DEFAULT_LICENCES_PER_PAGE
			)
		);
		if ( $per_page >= 5 && $per_page <= 500 ) {
			$legacy[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ] = $per_page;
		}

		$legacy[ UFSC_LC_Settings_Page::SETTING_CLUB_ACCESS_CAPABILITY ] = sanitize_key(
			(string) get_option( UFSC_LC_Settings_Page::LEGACY_OPTION_CLUB_ACCESS_CAPABILITY, '' )
		);

		$legacy[ UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY ] = sanitize_key(
			(string) get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_MANAGE_CAPABILITY,
				UFSC_LC_Capabilities::MANAGE_CAPABILITY
			)
		);
		$legacy[ UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY ] = sanitize_key(
			(string) get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_IMPORT_CAPABILITY,
				UFSC_LC_Capabilities::IMPORT_CAPABILITY
			)
		);
		$legacy[ UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY ] = sanitize_key(
			(string) get_option(
				UFSC_LC_Settings_Page::LEGACY_OPTION_EXPORT_CAPABILITY,
				UFSC_LC_Capabilities::EXPORT_CAPABILITY
			)
		);

		$legacy[ UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_AUTH ] = ! empty(
			get_option( UFSC_LC_Settings_Page::LEGACY_OPTION_PDF_REQUIRE_AUTH, true )
		) ? 1 : 0;
		$legacy[ UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_CLUB_MATCH ] = ! empty(
			get_option( UFSC_LC_Settings_Page::LEGACY_OPTION_PDF_REQUIRE_CLUB_MATCH, true )
		) ? 1 : 0;
		$legacy[ UFSC_LC_Settings_Page::SETTING_PDF_ALLOW_DOWNLOAD ] = ! empty(
			get_option( UFSC_LC_Settings_Page::LEGACY_OPTION_PDF_ALLOW_DOWNLOAD, true )
		) ? 1 : 0;
		$legacy[ UFSC_LC_Settings_Page::SETTING_ENABLE_LOGS ] = ! empty(
			get_option( UFSC_LC_Settings_Page::LEGACY_OPTION_ENABLE_LOGGING, false )
		) ? 1 : 0;

		return $legacy;
	}
}

if ( ! function_exists( 'ufsc_lc_sanitize_settings' ) ) {
	function ufsc_lc_sanitize_settings( $input ) {
		// Manual test plan (not executed):
		// - Sauver onglet Général avec année=2027 -> persiste et reste sur Général.
		// - Sauver onglet Import ASPTT -> persiste et reste sur Import ASPTT.
		// - Laisser un champ number vide -> aucune erreur, garde la valeur précédente.
		// - Saisir un mois hors 1..12 -> 1 erreur “Mois … invalide” et conservation de l’ancienne valeur.
		$defaults = ufsc_lc_get_settings_defaults();
		$old      = ufsc_lc_get_settings();
		$output   = $old;

		if ( ! is_array( $input ) ) {
			return $output;
		}

		$input = wp_unslash( $input );

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR, $input ) ) {
			$year_input = $input[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ];
			if ( '' === $year_input || null === $year_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ] = $old[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ];
			} else {
				$year = UFSC_LC_Categories::sanitize_season_end_year( $year_input );
				if ( null === $year ) {
					add_settings_error(
						UFSC_LC_Settings_Page::SETTINGS_OPTION,
						'invalid_season_end_year',
						__( 'Année de fin de saison invalide.', 'ufsc-licence-competition' ),
						'error'
					);
					$output[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ] = $old[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ];
				} else {
					$output[ UFSC_LC_Settings_Page::SETTING_DEFAULT_SEASON_END_YEAR ] = $year;
				}
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_SEASON_RULE, $input ) ) {
			$rule_input = $input[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ];
			if ( '' === $rule_input || null === $rule_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ] = $old[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ];
			} else {
				$rule = sanitize_key( $rule_input );
				if ( ! in_array( $rule, array( 'split', 'calendar' ), true ) ) {
					add_settings_error(
						UFSC_LC_Settings_Page::SETTINGS_OPTION,
						'invalid_season_rule',
						__( 'Règle de saison invalide.', 'ufsc-licence-competition' ),
						'error'
					);
					$output[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ] = $old[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ];
				} else {
					$output[ UFSC_LC_Settings_Page::SETTING_SEASON_RULE ] = $rule;
				}
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH, $input ) ) {
			$month_input = $input[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ];
			if ( '' === $month_input || null === $month_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ] = $old[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ];
			} else {
				$month = absint( $month_input );
				if ( $month < 1 || $month > 12 ) {
					add_settings_error(
						UFSC_LC_Settings_Page::SETTINGS_OPTION,
						'invalid_season_month',
						__( 'Mois de début de saison invalide.', 'ufsc-licence-competition' ),
						'error'
					);
					$output[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ] = $old[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ];
				} else {
					$output[ UFSC_LC_Settings_Page::SETTING_SEASON_START_MONTH ] = $month;
				}
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE, $input ) ) {
			$per_page_input = $input[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ];
			if ( '' === $per_page_input || null === $per_page_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ] = $old[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ];
			} else {
				$per_page = absint( $per_page_input );
				if ( $per_page < 5 || $per_page > 500 ) {
					add_settings_error(
						UFSC_LC_Settings_Page::SETTINGS_OPTION,
						'invalid_per_page',
						__( 'Pagination invalide.', 'ufsc-licence-competition' ),
						'error'
					);
					$old_per_page = absint( $old[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ] );
					if ( $old_per_page >= 5 && $old_per_page <= 500 ) {
						$output[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ] = $old_per_page;
					} else {
						$output[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ] = $defaults[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ];
					}
				} else {
					$output[ UFSC_LC_Settings_Page::SETTING_ADMIN_PER_PAGE ] = $per_page;
				}
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD, $input ) ) {
			$threshold_input = $input[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ];
			if ( '' === $threshold_input || null === $threshold_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ] = $old[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ];
			} else {
				$threshold = absint( $threshold_input );
				if ( $threshold < 0 || $threshold > 100 ) {
					add_settings_error(
						UFSC_LC_Settings_Page::SETTINGS_OPTION,
						'invalid_threshold',
						__( 'Seuil d’auto-validation invalide.', 'ufsc-licence-competition' ),
						'error'
					);
					$output[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ] = $old[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ];
				} else {
					$output[ UFSC_LC_Settings_Page::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ] = $threshold;
				}
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_ALLOW_IMPORT_ROLLBACK, $input ) ) {
			$output[ UFSC_LC_Settings_Page::SETTING_ALLOW_IMPORT_ROLLBACK ] = ! empty(
				$input[ UFSC_LC_Settings_Page::SETTING_ALLOW_IMPORT_ROLLBACK ]
			) ? 1 : 0;
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_ENABLE_LOGS, $input ) ) {
			$output[ UFSC_LC_Settings_Page::SETTING_ENABLE_LOGS ] = ! empty(
				$input[ UFSC_LC_Settings_Page::SETTING_ENABLE_LOGS ]
			) ? 1 : 0;
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_CLUB_ACCESS_CAPABILITY, $input ) ) {
			$output[ UFSC_LC_Settings_Page::SETTING_CLUB_ACCESS_CAPABILITY ] = sanitize_key(
				$input[ UFSC_LC_Settings_Page::SETTING_CLUB_ACCESS_CAPABILITY ]
			);
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY, $input ) ) {
			$capability_input = $input[ UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY ];
			if ( '' === $capability_input || null === $capability_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY ] = $old[ UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY ];
			} else {
				$capability = sanitize_key( $capability_input );
				$output[ UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY ] = '' !== $capability
					? $capability
					: $old[ UFSC_LC_Settings_Page::SETTING_MANAGE_CAPABILITY ];
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY, $input ) ) {
			$capability_input = $input[ UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY ];
			if ( '' === $capability_input || null === $capability_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY ] = $old[ UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY ];
			} else {
				$capability = sanitize_key( $capability_input );
				$output[ UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY ] = '' !== $capability
					? $capability
					: $old[ UFSC_LC_Settings_Page::SETTING_IMPORT_CAPABILITY ];
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY, $input ) ) {
			$capability_input = $input[ UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY ];
			if ( '' === $capability_input || null === $capability_input ) {
				$output[ UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY ] = $old[ UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY ];
			} else {
				$capability = sanitize_key( $capability_input );
				$output[ UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY ] = '' !== $capability
					? $capability
					: $old[ UFSC_LC_Settings_Page::SETTING_EXPORT_CAPABILITY ];
			}
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_AUTH, $input ) ) {
			$output[ UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_AUTH ] = ! empty(
				$input[ UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_AUTH ]
			) ? 1 : 0;
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_CLUB_MATCH, $input ) ) {
			$output[ UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_CLUB_MATCH ] = ! empty(
				$input[ UFSC_LC_Settings_Page::SETTING_PDF_REQUIRE_CLUB_MATCH ]
			) ? 1 : 0;
		}

		if ( array_key_exists( UFSC_LC_Settings_Page::SETTING_PDF_ALLOW_DOWNLOAD, $input ) ) {
			$output[ UFSC_LC_Settings_Page::SETTING_PDF_ALLOW_DOWNLOAD ] = ! empty(
				$input[ UFSC_LC_Settings_Page::SETTING_PDF_ALLOW_DOWNLOAD ]
			) ? 1 : 0;
		}

		return $output;
	}
}

class UFSC_LC_Settings_Page {
	const SETTINGS_GROUP = 'ufsc_lc_settings_group';
	const SETTINGS_OPTION = 'ufsc_lc_settings';
	const SETTING_DEFAULT_SEASON_END_YEAR = 'default_season_end_year';
	const SETTING_SEASON_RULE = 'season_rule';
	const SETTING_SEASON_START_MONTH = 'season_start_month';
	const SETTING_ASPTT_AUTO_APPROVE_THRESHOLD = 'asptt_auto_approve_threshold';
	const SETTING_ALLOW_IMPORT_ROLLBACK = 'allow_import_rollback';
	const SETTING_ADMIN_PER_PAGE = 'admin_per_page';
	const SETTING_CLUB_ACCESS_CAPABILITY = 'club_access_capability';
	const SETTING_MANAGE_CAPABILITY = 'manage_capability';
	const SETTING_IMPORT_CAPABILITY = 'import_capability';
	const SETTING_EXPORT_CAPABILITY = 'export_capability';
	const SETTING_PDF_REQUIRE_AUTH = 'pdf_require_auth';
	const SETTING_PDF_REQUIRE_CLUB_MATCH = 'pdf_require_club_match';
	const SETTING_PDF_ALLOW_DOWNLOAD = 'pdf_allow_download';
	const SETTING_ENABLE_LOGS = 'enable_logs';
	const LEGACY_OPTION_DEFAULT_SEASON_END_YEAR = 'ufsc_lc_default_season_end_year';
	const LEGACY_OPTION_SEASON_RULE = 'ufsc_lc_season_rule';
	const LEGACY_OPTION_SEASON_START_MONTH = 'ufsc_lc_season_start_month';
	const LEGACY_OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD = 'ufsc_lc_asptt_auto_validate_threshold';
	const LEGACY_OPTION_ASPTT_ROLLBACK_ENABLED = 'ufsc_lc_asptt_enable_rollback';
	const LEGACY_OPTION_LICENCES_PER_PAGE = 'ufsc_lc_licences_per_page';
	const LEGACY_OPTION_CLUB_ACCESS_CAPABILITY = 'ufsc_lc_club_access_capability';
	const LEGACY_OPTION_MANAGE_CAPABILITY = 'ufsc_lc_manage_capability';
	const LEGACY_OPTION_IMPORT_CAPABILITY = 'ufsc_lc_import_capability';
	const LEGACY_OPTION_EXPORT_CAPABILITY = 'ufsc_lc_export_capability';
	const LEGACY_OPTION_PDF_REQUIRE_AUTH = 'ufsc_lc_pdf_require_auth';
	const LEGACY_OPTION_PDF_REQUIRE_CLUB_MATCH = 'ufsc_lc_pdf_require_club_match';
	const LEGACY_OPTION_PDF_ALLOW_DOWNLOAD = 'ufsc_lc_pdf_allow_download';
	const LEGACY_OPTION_ENABLE_LOGGING = 'ufsc_lc_enable_logging';
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
		add_action( 'update_option_' . self::SETTINGS_OPTION, array( $this, 'sync_legacy_options' ), 10, 2 );
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
		register_setting(
			self::SETTINGS_GROUP,
			self::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => 'ufsc_lc_sanitize_settings',
				'default'           => ufsc_lc_get_settings_defaults(),
			)
		);

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
			self::SETTING_DEFAULT_SEASON_END_YEAR,
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
			self::SETTING_SEASON_RULE,
			$this->get_label_with_tooltip(
				__( 'Type de saison', 'ufsc-licence-competition' ),
				__( 'Définit la règle de calcul de la saison sportive.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_season_rule_field' ),
			$this->get_tab_page_slug( 'seasons' ),
			'ufsc_lc_settings_seasons'
		);

		add_settings_field(
			self::SETTING_SEASON_START_MONTH,
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
			self::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD,
			$this->get_label_with_tooltip(
				__( 'Seuil auto-validation', 'ufsc-licence-competition' ),
				__( 'Pourcentage de lignes correctement associées pour proposer l’auto-validation.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_asptt_threshold_field' ),
			$this->get_tab_page_slug( 'asptt' ),
			'ufsc_lc_settings_asptt'
		);

		add_settings_field(
			self::SETTING_ALLOW_IMPORT_ROLLBACK,
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
			self::SETTING_ADMIN_PER_PAGE,
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
			self::SETTING_CLUB_ACCESS_CAPABILITY,
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
			self::SETTING_MANAGE_CAPABILITY,
			$this->get_label_with_tooltip(
				__( 'Capacité de gestion', 'ufsc-licence-competition' ),
				__( 'Capacité minimale pour accéder aux outils d’administration UFSC.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_manage_capability_field' ),
			$this->get_tab_page_slug( 'security' ),
			'ufsc_lc_settings_security'
		);

		add_settings_field(
			self::SETTING_IMPORT_CAPABILITY,
			$this->get_label_with_tooltip(
				__( 'Capacité import', 'ufsc-licence-competition' ),
				__( 'Capacité minimale pour exécuter un import ASPTT.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_import_capability_field' ),
			$this->get_tab_page_slug( 'security' ),
			'ufsc_lc_settings_security'
		);

		add_settings_field(
			self::SETTING_EXPORT_CAPABILITY,
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
			self::SETTING_PDF_REQUIRE_AUTH,
			$this->get_label_with_tooltip(
				__( 'Authentification obligatoire', 'ufsc-licence-competition' ),
				__( 'Impose une session connectée pour accéder aux PDF.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_pdf_require_auth_field' ),
			$this->get_tab_page_slug( 'pdf' ),
			'ufsc_lc_settings_pdf'
		);

		add_settings_field(
			self::SETTING_PDF_REQUIRE_CLUB_MATCH,
			$this->get_label_with_tooltip(
				__( 'Limiter aux clubs propriétaires', 'ufsc-licence-competition' ),
				__( 'Restreint l’accès aux PDF au club rattaché à la licence.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_pdf_require_club_match_field' ),
			$this->get_tab_page_slug( 'pdf' ),
			'ufsc_lc_settings_pdf'
		);

		add_settings_field(
			self::SETTING_PDF_ALLOW_DOWNLOAD,
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
			self::SETTING_ENABLE_LOGS,
			$this->get_label_with_tooltip(
				__( 'Activer le journal', 'ufsc-licence-competition' ),
				__( 'Force la journalisation UFSC LC même si WP_DEBUG est désactivé.', 'ufsc-licence-competition' )
			),
			array( $this, 'render_logging_field' ),
			$this->get_tab_page_slug( 'logs' ),
			'ufsc_lc_settings_logs'
		);
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
			<?php settings_errors( self::SETTINGS_OPTION ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				do_settings_sections( $this->get_tab_page_slug( $active_tab ) );
				submit_button( __( 'Enregistrer', 'ufsc-licence-competition' ) );
				?>
				<?php
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
				if ( '' === $request_uri || false === strpos( $request_uri, 'tab=' ) ) {
					$referer_url = add_query_arg(
						array(
							'page' => self::PAGE_SLUG,
							'tab'  => $active_tab,
						),
						admin_url( 'admin.php' )
					);
					?>
					<input type="hidden" name="_wp_http_referer" value="<?php echo esc_attr( $referer_url ); ?>">
					<?php
				}
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

	private function get_setting_field_name( $key ) {
		return self::SETTINGS_OPTION . '[' . $key . ']';
	}

	public function render_default_season_field() {
		$value = self::get_default_season_end_year();
		?>
		<input
			type="number"
			min="2000"
			max="2100"
			name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_DEFAULT_SEASON_END_YEAR ) ); ?>"
			id="<?php echo esc_attr( self::SETTING_DEFAULT_SEASON_END_YEAR ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		>
		<p class="description">
			<?php esc_html_e( 'Année de fin de saison utilisée quand le CSV ne fournit pas la saison.', 'ufsc-licence-competition' ); ?>
		</p>
		<?php
	}

	public function render_season_rule_field() {
		$value = self::get_setting( self::SETTING_SEASON_RULE, self::DEFAULT_SEASON_RULE );
		?>
		<select name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_SEASON_RULE ) ); ?>" id="<?php echo esc_attr( self::SETTING_SEASON_RULE ); ?>">
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
		$value = (int) self::get_setting( self::SETTING_SEASON_START_MONTH, self::DEFAULT_SEASON_START_MONTH );
		global $wp_locale;
		?>
		<select name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_SEASON_START_MONTH ) ); ?>" id="<?php echo esc_attr( self::SETTING_SEASON_START_MONTH ); ?>">
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
		$value = (int) self::get_setting( self::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD, self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD );
		?>
		<input
			type="number"
			min="0"
			max="100"
			step="1"
			name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ) ); ?>"
			id="<?php echo esc_attr( self::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ); ?>"
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
		<input type="hidden" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_ALLOW_IMPORT_ROLLBACK ) ); ?>" value="0">
		<label for="<?php echo esc_attr( self::SETTING_ALLOW_IMPORT_ROLLBACK ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_ALLOW_IMPORT_ROLLBACK ) ); ?>" id="<?php echo esc_attr( self::SETTING_ALLOW_IMPORT_ROLLBACK ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Autoriser l’annulation du dernier import.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_licences_per_page_field() {
		$value = (int) self::get_setting( self::SETTING_ADMIN_PER_PAGE, self::DEFAULT_LICENCES_PER_PAGE );
		?>
		<input
			type="number"
			min="5"
			max="500"
			step="1"
			name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_ADMIN_PER_PAGE ) ); ?>"
			id="<?php echo esc_attr( self::SETTING_ADMIN_PER_PAGE ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
		>
		<?php
	}

	public function render_club_access_capability_field() {
		$value = self::get_club_access_capability();
		?>
		<input
			type="text"
			name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_CLUB_ACCESS_CAPABILITY ) ); ?>"
			id="<?php echo esc_attr( self::SETTING_CLUB_ACCESS_CAPABILITY ); ?>"
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
		<select name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_MANAGE_CAPABILITY ) ); ?>" id="<?php echo esc_attr( self::SETTING_MANAGE_CAPABILITY ); ?>">
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
		<select name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_IMPORT_CAPABILITY ) ); ?>" id="<?php echo esc_attr( self::SETTING_IMPORT_CAPABILITY ); ?>">
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
		<select name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_EXPORT_CAPABILITY ) ); ?>" id="<?php echo esc_attr( self::SETTING_EXPORT_CAPABILITY ); ?>">
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
		<input type="hidden" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_PDF_REQUIRE_AUTH ) ); ?>" value="0">
		<label for="<?php echo esc_attr( self::SETTING_PDF_REQUIRE_AUTH ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_PDF_REQUIRE_AUTH ) ); ?>" id="<?php echo esc_attr( self::SETTING_PDF_REQUIRE_AUTH ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Restreindre l’accès aux utilisateurs connectés.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_pdf_require_club_match_field() {
		$value = self::is_pdf_club_match_required();
		?>
		<input type="hidden" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_PDF_REQUIRE_CLUB_MATCH ) ); ?>" value="0">
		<label for="<?php echo esc_attr( self::SETTING_PDF_REQUIRE_CLUB_MATCH ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_PDF_REQUIRE_CLUB_MATCH ) ); ?>" id="<?php echo esc_attr( self::SETTING_PDF_REQUIRE_CLUB_MATCH ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Valider l’appartenance au club.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_pdf_allow_download_field() {
		$value = self::is_pdf_download_allowed();
		?>
		<input type="hidden" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_PDF_ALLOW_DOWNLOAD ) ); ?>" value="0">
		<label for="<?php echo esc_attr( self::SETTING_PDF_ALLOW_DOWNLOAD ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_PDF_ALLOW_DOWNLOAD ) ); ?>" id="<?php echo esc_attr( self::SETTING_PDF_ALLOW_DOWNLOAD ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Afficher le bouton de téléchargement dans l’espace club.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public function render_logging_field() {
		$value = self::is_logging_enabled();
		?>
		<input type="hidden" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_ENABLE_LOGS ) ); ?>" value="0">
		<label for="<?php echo esc_attr( self::SETTING_ENABLE_LOGS ); ?>">
			<input type="checkbox" name="<?php echo esc_attr( $this->get_setting_field_name( self::SETTING_ENABLE_LOGS ) ); ?>" id="<?php echo esc_attr( self::SETTING_ENABLE_LOGS ); ?>" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Activer la journalisation UFSC LC.', 'ufsc-licence-competition' ); ?>
		</label>
		<?php
	}

	public static function get_default_season_end_year() {
		$settings = ufsc_lc_get_settings();
		$year     = UFSC_LC_Categories::sanitize_season_end_year( $settings[ self::SETTING_DEFAULT_SEASON_END_YEAR ] );

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

	public function sync_legacy_options( $old_value, $new_value ) {
		if ( ! is_array( $new_value ) ) {
			return;
		}

		$settings = wp_parse_args( $new_value, ufsc_lc_get_settings_defaults() );

		update_option( self::LEGACY_OPTION_DEFAULT_SEASON_END_YEAR, $settings[ self::SETTING_DEFAULT_SEASON_END_YEAR ] );
		update_option( self::LEGACY_OPTION_SEASON_RULE, $settings[ self::SETTING_SEASON_RULE ] );
		update_option( self::LEGACY_OPTION_SEASON_START_MONTH, $settings[ self::SETTING_SEASON_START_MONTH ] );
		update_option( self::LEGACY_OPTION_ASPTT_AUTO_VALIDATE_THRESHOLD, $settings[ self::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD ] );
		update_option( self::LEGACY_OPTION_ASPTT_ROLLBACK_ENABLED, $settings[ self::SETTING_ALLOW_IMPORT_ROLLBACK ] );
		update_option( self::LEGACY_OPTION_LICENCES_PER_PAGE, $settings[ self::SETTING_ADMIN_PER_PAGE ] );
		update_option( self::LEGACY_OPTION_CLUB_ACCESS_CAPABILITY, $settings[ self::SETTING_CLUB_ACCESS_CAPABILITY ] );
		update_option( self::LEGACY_OPTION_MANAGE_CAPABILITY, $settings[ self::SETTING_MANAGE_CAPABILITY ] );
		update_option( self::LEGACY_OPTION_IMPORT_CAPABILITY, $settings[ self::SETTING_IMPORT_CAPABILITY ] );
		update_option( self::LEGACY_OPTION_EXPORT_CAPABILITY, $settings[ self::SETTING_EXPORT_CAPABILITY ] );
		update_option( self::LEGACY_OPTION_PDF_REQUIRE_AUTH, $settings[ self::SETTING_PDF_REQUIRE_AUTH ] );
		update_option( self::LEGACY_OPTION_PDF_REQUIRE_CLUB_MATCH, $settings[ self::SETTING_PDF_REQUIRE_CLUB_MATCH ] );
		update_option( self::LEGACY_OPTION_PDF_ALLOW_DOWNLOAD, $settings[ self::SETTING_PDF_ALLOW_DOWNLOAD ] );
		update_option( self::LEGACY_OPTION_ENABLE_LOGGING, $settings[ self::SETTING_ENABLE_LOGS ] );
	}

	private static function get_setting( $key, $default = null ) {
		$settings = ufsc_lc_get_settings();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	public static function get_option_value( $option, $default = null ) {
		$settings = ufsc_lc_get_settings();
		$value    = array_key_exists( $option, $settings ) ? $settings[ $option ] : $default;
		return apply_filters( 'ufsc_lc_setting_value', $value, $option, $default );
	}

	public static function get_asptt_auto_validate_threshold() {
		$threshold = absint( self::get_option_value( self::SETTING_ASPTT_AUTO_APPROVE_THRESHOLD, self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD ) );
		if ( $threshold < 0 || $threshold > 100 ) {
			return self::DEFAULT_ASPTT_AUTO_VALIDATE_THRESHOLD;
		}

		return $threshold;
	}

	public static function is_asptt_rollback_enabled() {
		return (bool) self::get_option_value( self::SETTING_ALLOW_IMPORT_ROLLBACK, true );
	}

	public static function get_licences_per_page() {
		$per_page = absint( self::get_option_value( self::SETTING_ADMIN_PER_PAGE, self::DEFAULT_LICENCES_PER_PAGE ) );

		if ( $per_page < 5 || $per_page > 500 ) {
			return self::DEFAULT_LICENCES_PER_PAGE;
		}

		return $per_page;
	}

	public static function get_club_access_capability() {
		return sanitize_key( (string) self::get_option_value( self::SETTING_CLUB_ACCESS_CAPABILITY, '' ) );
	}

	public static function get_manage_capability() {
		$capability = sanitize_key( (string) self::get_option_value( self::SETTING_MANAGE_CAPABILITY, UFSC_LC_Capabilities::MANAGE_CAPABILITY ) );
		return '' !== $capability ? $capability : UFSC_LC_Capabilities::MANAGE_CAPABILITY;
	}

	public static function get_import_capability() {
		$capability = sanitize_key( (string) self::get_option_value( self::SETTING_IMPORT_CAPABILITY, UFSC_LC_Capabilities::IMPORT_CAPABILITY ) );
		return '' !== $capability ? $capability : UFSC_LC_Capabilities::IMPORT_CAPABILITY;
	}

	public static function get_export_capability() {
		$capability = sanitize_key( (string) self::get_option_value( self::SETTING_EXPORT_CAPABILITY, UFSC_LC_Capabilities::EXPORT_CAPABILITY ) );
		return '' !== $capability ? $capability : UFSC_LC_Capabilities::EXPORT_CAPABILITY;
	}

	public static function is_pdf_auth_required() {
		return (bool) self::get_option_value( self::SETTING_PDF_REQUIRE_AUTH, true );
	}

	public static function is_pdf_club_match_required() {
		return (bool) self::get_option_value( self::SETTING_PDF_REQUIRE_CLUB_MATCH, true );
	}

	public static function is_pdf_download_allowed() {
		return (bool) self::get_option_value( self::SETTING_PDF_ALLOW_DOWNLOAD, true );
	}

	public static function is_logging_enabled() {
		return (bool) self::get_option_value( self::SETTING_ENABLE_LOGS, false );
	}
}
