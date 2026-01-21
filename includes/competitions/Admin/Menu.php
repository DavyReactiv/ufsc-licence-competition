<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Admin\Pages\Competitions_Page;
use UFSC\Competitions\Admin\Pages\Categories_Page;
use UFSC\Competitions\Admin\Pages\Entries_Page;
use UFSC\Competitions\Admin\Pages\Bouts_Page;
use UFSC\Competitions\Admin\Pages\Settings_Page;
use UFSC\Competitions\Admin\Pages\Guide_Page;
use UFSC\Competitions\Admin\Pages\Quality_Page;
use UFSC\Competitions\Admin\Pages\Print_Page;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin menu registration for Competitions module.
 */
class Menu {
	const PARENT_SLUG        = 'ufsc-licence-documents';

	const PAGE_COMPETITIONS  = 'ufsc-competitions';
	const PAGE_CATEGORIES    = 'ufsc-competitions-categories';
	const PAGE_ENTRIES       = 'ufsc-competitions-entries';
	const PAGE_BOUTS         = 'ufsc-competitions-bouts';
	const PAGE_SETTINGS      = 'ufsc-competitions-settings';
	const PAGE_PRINT         = 'ufsc-competitions-print';
	const PAGE_GUIDE         = 'ufsc-competitions-guide';
	const PAGE_QUALITY       = 'ufsc-competitions-quality';

	/** @var string[] */
	private $admin_menu_failures = array();

	public function register() {
		$capability = \UFSC_LC_Capabilities::get_manage_capability();

		// Instantiate pages defensively
		$competitions_page = $this->safe_instance( Competitions_Page::class );
		$categories_page   = $this->safe_instance( Categories_Page::class );
		$entries_page      = $this->safe_instance( Entries_Page::class );
		$bouts_page        = $this->safe_instance( Bouts_Page::class );
		$settings_page     = $this->safe_instance( Settings_Page::class );
		$guide_page        = $this->safe_instance( Guide_Page::class );
		$quality_page      = $this->safe_instance( Quality_Page::class );
		$print_page        = $this->safe_instance( Print_Page::class );

		// Register actions early (admin_post / ajax etc.)
		foreach ( array(
			$competitions_page,
			$categories_page,
			$entries_page,
			$bouts_page,
			$settings_page,
			$guide_page,
			$quality_page,
			$print_page,
		) as $page ) {
			if ( $page && method_exists( $page, 'register_actions' ) ) {
				try {
					$page->register_actions();
				} catch ( \Throwable $e ) {
					$this->log( sprintf(
						'UFSC Competitions: register_actions failed for %s: %s',
						is_object( $page ) ? get_class( $page ) : '(null)',
						$e->getMessage()
					) );
				}
			}
		}

		// Defer submenu creation to admin_menu hook (correct WP timing)
		add_action(
			'admin_menu',
			function() use (
				$capability,
				$competitions_page,
				$categories_page,
				$entries_page,
				$bouts_page,
				$settings_page,
				$guide_page,
				$quality_page,
				$print_page
			) {
				$this->build_submenus(
					$capability,
					$competitions_page,
					$categories_page,
					$entries_page,
					$bouts_page,
					$settings_page,
					$guide_page,
					$quality_page,
					$print_page
				);
			},
			30
		);

		if ( ! empty( $this->admin_menu_failures ) ) {
			add_action( 'admin_notices', array( $this, 'render_admin_menu_failures_notice' ) );
		}
	}

	private function build_submenus( $capability, $competitions_page, $categories_page, $entries_page, $bouts_page, $settings_page, $guide_page, $quality_page, $print_page ) {
		$configs = array(
			array(
				'page'  => $competitions_page,
				'title' => __( 'Compétitions', 'ufsc-licence-competition' ),
				'menu'  => __( 'Compétitions', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_COMPETITIONS,
			),
			array(
				'page'  => $categories_page,
				'title' => __( 'Catégories & formats', 'ufsc-licence-competition' ),
				'menu'  => __( 'Catégories & formats', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_CATEGORIES,
			),
			array(
				'page'  => $entries_page,
				'title' => __( 'Inscriptions', 'ufsc-licence-competition' ),
				'menu'  => __( 'Inscriptions', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_ENTRIES,
			),
			array(
				'page'  => $quality_page,
				'title' => __( 'Contrôles qualité', 'ufsc-licence-competition' ),
				'menu'  => __( 'Contrôles qualité', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_QUALITY,
			),
			array(
				'page'  => $bouts_page,
				'title' => __( 'Combats', 'ufsc-licence-competition' ),
				'menu'  => __( 'Combats', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_BOUTS,
			),
			array(
				'page'  => $print_page,
				'title' => __( 'Impression', 'ufsc-licence-competition' ),
				'menu'  => __( 'Impression', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_PRINT,
			),
			array(
				'page'  => $settings_page,
				'title' => __( 'Paramètres', 'ufsc-licence-competition' ),
				'menu'  => __( 'Paramètres', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_SETTINGS,
			),
			array(
				'page'  => $guide_page,
				'title' => __( 'Guide', 'ufsc-licence-competition' ),
				'menu'  => __( 'Guide', 'ufsc-licence-competition' ),
				'slug'  => self::PAGE_GUIDE,
			),
		);

		foreach ( $configs as $cfg ) {
			$this->maybe_register_submenu( $cfg['page'], $cfg['title'], $cfg['menu'], $cfg['slug'], $capability );
		}
	}

	private function maybe_register_submenu( $page_obj, $page_title, $menu_title, $page_slug, $capability ) {
		if ( ! $page_obj ) {
			$this->admin_menu_failures[] = sprintf( 'Page class for slug "%s" not instantiated.', $page_slug );
			$this->log( sprintf( 'UFSC Competitions: Page class for slug "%s" not instantiated.', $page_slug ) );
			return;
		}

		/**
		 * Robust callback:
		 * - use is_callable() (method_exists() is not enough: private/protected methods exist but are not callable)
		 * - supports multiple method names
		 */
		$callback = function() use ( $page_obj, $page_slug ) {
			$candidates = array( 'render', 'render_page', 'display', 'render_list', 'render_admin_page' );

			foreach ( $candidates as $method ) {
				if ( is_object( $page_obj ) && is_callable( array( $page_obj, $method ) ) ) {
					return call_user_func( array( $page_obj, $method ) );
				}
			}

			$msg = sprintf(
				'UFSC Competitions: Page object for slug "%s" missing callable render method (tried: %s).',
				$page_slug,
				implode( ',', $candidates )
			);
			$this->log( $msg );

			echo '<div class="wrap"><h1>' . esc_html__( 'Compétitions', 'ufsc-licence-competition' ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'La page est indisponible : méthode de rendu introuvable ou non publique. Vérifiez la classe de la page.', 'ufsc-licence-competition' ) . '</p></div></div>';
			return null;
		};

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			$page_title,
			$menu_title,
			$capability,
			$page_slug,
			$callback
		);

		if ( ! $hook_suffix ) {
			$this->admin_menu_failures[] = sprintf( 'Failed to register submenu for slug "%s".', $page_slug );
			$this->log( sprintf( 'UFSC Competitions: Failed to register submenu for slug "%s".', $page_slug ) );
			return;
		}

		// Register assets loader ONLY via UFSC_LC_Admin_Assets to avoid double enqueue
		if ( class_exists( '\\UFSC_LC_Admin_Assets' ) ) {
			\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
		}
	}

	public function render_admin_menu_failures_notice() {
		if ( ! current_user_can( \UFSC_LC_Capabilities::get_manage_capability() ) ) {
			return;
		}
		if ( empty( $this->admin_menu_failures ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'UFSC Competitions — problème d\'enregistrement des sous-menus :', 'ufsc-licence-competition' );
		echo '<ul>';
		foreach ( $this->admin_menu_failures as $msg ) {
			echo '<li>' . esc_html( $msg ) . '</li>';
		}
		echo '</ul>';
		echo '</p></div>';
	}

	private function safe_instance( $fqcn ) {
		if ( ! class_exists( $fqcn ) ) {
			$this->log( sprintf( 'UFSC Competitions: Admin\\Menu registration failed: Class %s not found.', $fqcn ) );
			return null;
		}

		try {
			return new $fqcn();
		} catch ( \Throwable $e ) {
			$message = sprintf( 'UFSC Competitions: Admin\\Menu instantiation failed for %s: %s', $fqcn, $e->getMessage() );
			$this->admin_menu_failures[] = $message;
			$this->log( $message . ' | ' . $e->getTraceAsString() );
			return null;
		}
	}

	private function log( $message ) {
		if ( class_exists( '\\UFSC_LC_Logger' ) ) {
			\UFSC_LC_Logger::log( $message );
			return;
		}
		error_log( $message );
	}
}
