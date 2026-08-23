<?php
/**
 * Plugin Name: UFSC Licence Competition
 * Description: Add-on UFSC pour associer des PDF nominatives aux licences officielles et préparer les évolutions compétitions.
 * Plugin URI: https://studioreactiv.fr/
 * Version: 1.5.1
 * Author: StudioReactiv
 * Author URI: https://studioreactiv.fr/
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: ufsc-licence-competition
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'UFSC_LC_LOADED' ) ) {
	return;
}
define( 'UFSC_LC_LOADED', true );

/**
 * Define core plugin constants early to avoid undefined constant errors.
 * These are used throughout the plugin. Keep them minimal and deterministic.
 */
if ( ! defined( 'UFSC_LC_FILE' ) ) {
	define( 'UFSC_LC_FILE', __FILE__ );
}
if ( ! defined( 'UFSC_LC_DIR' ) ) {
	define( 'UFSC_LC_DIR', plugin_dir_path( UFSC_LC_FILE ) );
}
if ( ! defined( 'UFSC_LC_URL' ) ) {
	define( 'UFSC_LC_URL', plugin_dir_url( UFSC_LC_FILE ) );
}
// Backwards-compatible alias: some files historically used UFSC_LC_PLUGIN_DIR
if ( ! defined( 'UFSC_LC_PLUGIN_DIR' ) ) {
	define( 'UFSC_LC_PLUGIN_DIR', UFSC_LC_DIR );
}
if ( ! defined( 'UFSC_LC_DEBUG_PERF' ) ) {
	define( 'UFSC_LC_DEBUG_PERF', false );
}

if ( ! defined( 'UFSC_COMPETITION_WEIGHT_CATEGORIES_PDF_URL' ) ) {
	define( 'UFSC_COMPETITION_WEIGHT_CATEGORIES_PDF_URL', 'https://ufsc-france.fr/wp-content/uploads/2026/04/AGES-CATEGORIES-DE-POIDS-TATAMI-SAISON-2025-2026-V2_2026.pdf' );
}

// Competition domain extensions that must be available before the module boots.
require_once UFSC_LC_DIR . 'includes/competitions/Db.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/EventProgramRegistry.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/EventFormatRegistry.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/RegistrationScopePresenter.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/RegistrationWindowService.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/CompetitionRequirementService.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/CompetitionDuplicationService.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/DatabaseTransaction.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/AtomicOperationLock.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/FightResultPersistence.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/CompetitionIntegrityService.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/DatabaseSchemaGuard.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/Pancrace/PancraceReference2025.php';
require_once UFSC_LC_DIR . 'includes/competitions/Services/Pancrace/PancraceDomainBootstrap.php';
require_once UFSC_LC_DIR . 'includes/competitions/Admin/Pages/EventProgram_Page.php';
require_once UFSC_LC_DIR . 'includes/competitions/Admin/PremiumExperience.php';
require_once UFSC_LC_DIR . 'includes/competitions/Admin/EventLifecycleAdmin.php';
require_once UFSC_LC_DIR . 'includes/competitions/Admin/CompetitionRequirementsAdmin.php';
require_once UFSC_LC_DIR . 'includes/competitions/Admin/IntegrityHealthNotice.php';
require_once UFSC_LC_DIR . 'includes/competitions/Admin/ResultConcurrencyAdmin.php';
require_once UFSC_LC_DIR . 'includes/competitions/Front/PremiumRegistrationExperience.php';
require_once UFSC_LC_DIR . 'includes/competitions/Front/ClubCompetitionPortal.php';

\UFSC\Competitions\Services\RegistrationWindowService::register();
\UFSC\Competitions\Services\CompetitionRequirementService::register();
\UFSC\Competitions\Services\DatabaseSchemaGuard::register();
\UFSC\Competitions\Services\Pancrace\PancraceDomainBootstrap::register();
\UFSC\Competitions\Admin\PremiumExperience::register();
\UFSC\Competitions\Admin\EventLifecycleAdmin::register();
\UFSC\Competitions\Admin\CompetitionRequirementsAdmin::register();
\UFSC\Competitions\Admin\IntegrityHealthNotice::register();
\UFSC\Competitions\Admin\ResultConcurrencyAdmin::register();
\UFSC\Competitions\Front\PremiumRegistrationExperience::register();
\UFSC\Competitions\Front\ClubCompetitionPortal::register();

require_once UFSC_LC_DIR . 'includes/ufsc-lc-helpers.php';
require_once UFSC_LC_DIR . 'includes/class-ufsc-lc-plugin.php';

UFSC_LC_Plugin::init( UFSC_LC_FILE );