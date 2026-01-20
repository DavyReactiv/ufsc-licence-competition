<?php

namespace UFSC\Competitions\Admin;

use UFSC_LC_Logger;
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
 *
 * - Instantiates page classes and calls register_actions() immediately so admin_post/wp_ajax hooks
 *   are registered early (AJAX endpoints must be available on admin-ajax.php).
 * - Defers add_submenu_page() calls to the 'admin_menu' hook to ensure WP core builds proper admin URLs
 *   (prevents incorrect pretty rewrite like /wp-admin/ufsc-competitions).
 * - Uses UFSC_LC_Admin_Assets::register_page() as the single assets loader to avoid double-enqueue.
 * - Logs and surfaces admin notices for missing pages/hooks (no fatal).
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

	/** @var array list of admin_menu failures to show as notices */
	private $admin_menu_failures = array();

	/**
	 * Register pages: instantiate pages & register actions now, build WP menus on admin_menu.
	 */
	public function register() {
		$capability = \UFSC_LC_Capabilities::get_manage_capability();

		// Instantiate pages defensively (constructors may register ajax/admin_post hooks).
		$competitions_page = $this->safe_instance( Competitions_Page::class );
		$categories_page   = $this->safe_instance( Categories_Page::class );
		$entries_page      = $this->safe_instance( Entries_Page::class );
		$bouts_page        = $this->safe_instance( Bouts_Page::class );
		$settings_page     = $this->safe_instance( Settings_Page::class );
		$guide_page        = $this->safe_instance( Guide_Page::class );
		$quality_page      = $this->safe_instance( Quality_Page::class );
		$print_page        = $this->safe_instance( Print_Page::class );

		// Call register_actions() on instantiated pages so admin_post / ajax hooks are registered early.
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
					$message = sprintf( 'UFSC Competitions: register_actions failed for %s: %s', get_class( $page ), $e->getMessage() );
					if ( class_exists( '\\UFSC_LC_Logger' ) ) {
						\UFSC_LC_Logger::log( $message );
					} else {
						error_log( $message );
					}
				}
			}
		}

		// Defer actual menu creation to admin_menu hook (correct timing for WP to generate admin.php?page=... links).
		add_action(
			'admin_menu',
			function() use ( $capability, $competitions_page, $categories_page, $entries_page, $bouts_page, $settings_page, $guide_page, $quality_page, $print_page ) {
				$this->build_submenus( $capability, $competitions_page, $categories_page, $entries_page, $bouts_page, $settings_page, $guide_page, $quality_page, $print_page );
			},
			30
		);

		// If any menu failed to register, display admin_notice for administrators.
		if ( ! empty( $this->admin_menu_failures ) ) {
			add_action( 'admin_notices', array( $this, 'render_admin_menu_failures_notice' ) );
		}
	}

	/**
	 * Build and register submenu pages (called on admin_menu).
	 *
	 * @param string $capability
	 * @param object|null ...$pages
	 * @return void
	 */
	private function build_submenus( $capability ) {
		$args = func_get_args();
		array_shift( $args ); // remove capability
		$pages = $args;

		// Use single central assets loader: UFSC_LC_Admin_Assets
		foreach ( array(
			array( 'page' => $pages[0] ?? null, 'title' => __( 'Compétitions', 'ufsc-licence-competition' ), 'menu' => __( 'Compétitions', 'ufsc-licence-competition' ), 'slug' => self::PAGE_COMPETITIONS ),
			array( 'page' => $pages[1] ?? null, 'title' => __( 'Catégories & formats', 'ufsc-licence-competition' ), 'menu' => __( 'Catégories & formats', 'ufsc-licence-competition' ), 'slug' => self::PAGE_CATEGORIES ),
			array( 'page' => $pages[2] ?? null, 'title' => __( 'Inscriptions', 'ufsc-licence-competition' ), 'menu' => __( 'Inscriptions', 'ufsc-licence-competition' ), 'slug' => self::PAGE_ENTRIES ),
			array( 'page' => $pages[6] ?? null, 'title' => __( 'Contrôles qualité', 'ufsc-licence-competition' ), 'menu' => __( 'Contrôles qualité', 'ufsc-licence-competition' ), 'slug' => self::PAGE_QUALITY ),
			array( 'page' => $pages[3] ?? null, 'title' => __( 'Combats', 'ufsc-licence-competition' ), 'menu' => __( 'Combats', 'ufsc-licence-competition' ), 'slug' => self::PAGE_BOUTS ),
			array( 'page' => $pages[7] ?? null, 'title' => __( 'Impression', 'ufsc-licence-competition' ), 'menu' => __( 'Impression', 'ufsc-licence-competition' ), 'slug' => self::PAGE_PRINT ),
			array( 'page' => $pages[4] ?? null, 'title' => __( 'Paramètres', 'ufsc-licence-competition' ), 'menu' => __( 'Paramètres', 'ufsc-licence-competition' ), 'slug' => self::PAGE_SETTINGS ),
			array( 'page' => $pages[5] ?? null, 'title' => __( 'Guide', 'ufsc-licence-competition' ), 'menu' => __( 'Guide', 'ufsc-licence-competition' ), 'slug' => self::PAGE_GUIDE ),
		) as $cfg ) {
			$this->maybe_register_submenu( $cfg['page'], $cfg['title'], $cfg['menu'], $cfg['slug'], $capability );
		}
	}

	/**
	 * Register a single submenu if page object exists.
	 *
	 * @param object|null $page_obj
	 * @param string      $page_title
	 * @param string      $menu_title
	 * @param string      $page_slug
	 * @param string      $capability
	 * @return void
	 */
	private function maybe_register_submenu( $page_obj, $page_title, $menu_title, $page_slug, $capability ) {
		if ( ! $page_obj ) {
			$this->admin_menu_failures[] = sprintf( 'Page class for slug "%s" not instantiated.', $page_slug );
			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( sprintf( 'UFSC Competitions: Page class for slug "%s" not instantiated.', $page_slug ) );
			} else {
				error_log( sprintf( '[UFSC LC] Page class for slug "%s" not instantiated.', $page_slug ) );
			}
			return;
		}

		$hook_suffix = add_submenu_page(
			self::PARENT_SLUG,
			$page_title,
			$menu_title,
			$capability,
			$page_slug,
			array( $page_obj, 'render' )
		);

		if ( ! $hook_suffix ) {
			// Record failure for admin notice
			$this->admin_menu_failures[] = sprintf( 'Failed to register submenu for slug "%s".', $page_slug );
			$message = sprintf( 'UFSC Competitions: Failed to register submenu for slug "%s".', $page_slug );
			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( $message );
			} else {
				error_log( $message );
			}
			return;
		}

		// Use singleton UFSC_LC_Admin_Assets to enqueue admin assets only for registered pages.
		\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
	}

	/**
	 * Show admin notice when menu registration issues occurred.
	 *
	 * Runs only for users with manage capability.
	 */
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

	/**
	 * Try to instantiate a class name if available.
	 *
	 * @param string $fqcn Fully qualified class name string.
	 * @return object|null Instance or null if class missing or instantiation failed.
	 */
	private function safe_instance( $fqcn ) {
		if ( ! class_exists( $fqcn ) ) {
			$message = sprintf( 'UFSC Competitions: Admin\\Menu registration failed: Class %s not found.', $fqcn );
			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( $message );
			} else {
				error_log( $message );
			}
			return null;
		}

		try {
			return new $fqcn();
		} catch ( \Throwable $e ) {
			// Defensive: do not fatal the admin menu — log and continue.
			$message = sprintf( 'UFSC Competitions: Admin\\Menu instantiation failed for %s: %s', $fqcn, $e->getMessage() );
			if ( class_exists( '\\UFSC_LC_Logger' ) ) {
				\UFSC_LC_Logger::log( $message, array( 'exception' => $e->getTraceAsString() ) );
			} else {
				error_log( $message );
			}
			$this->admin_menu_failures[] = $message;
			return null;
		}
	}
}
