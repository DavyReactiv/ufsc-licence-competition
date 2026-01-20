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

	public function register() {
		$capability = \UFSC_LC_Capabilities::get_manage_capability();

		// Instantiate pages defensively: if class missing, log and continue.
		$competitions_page = $this->safe_instance( Competitions_Page::class );
		$categories_page   = $this->safe_instance( Categories_Page::class );
		$entries_page      = $this->safe_instance( Entries_Page::class );
		$bouts_page        = $this->safe_instance( Bouts_Page::class );
		$settings_page     = $this->safe_instance( Settings_Page::class );
		$guide_page        = $this->safe_instance( Guide_Page::class );
		$quality_page      = $this->safe_instance( Quality_Page::class );
		$print_page        = $this->safe_instance( Print_Page::class );

		// If page objects expose register_actions(), call it now so admin_post / ajax hooks are set.
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

		// Helper to register a submenu only if page object exists.
		$register_submenu = function( $page_obj, $page_title, $menu_title, $page_slug ) use ( $capability ) {
			if ( ! $page_obj ) {
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

			if ( $hook_suffix ) {
				// Use central loader only to avoid duplicate enqueue
				\UFSC_LC_Admin_Assets::register_page( $hook_suffix );
			}
		};

		$register_submenu( $competitions_page, __( 'Compétitions', 'ufsc-licence-competition' ), __( 'Compétitions', 'ufsc-licence-competition' ), self::PAGE_COMPETITIONS );
		$register_submenu( $categories_page,   __( 'Catégories & formats', 'ufsc-licence-competition' ), __( 'Catégories & formats', 'ufsc-licence-competition' ), self::PAGE_CATEGORIES );
		$register_submenu( $entries_page,      __( 'Inscriptions', 'ufsc-licence-competition' ), __( 'Inscriptions', 'ufsc-licence-competition' ), self::PAGE_ENTRIES );
		$register_submenu( $quality_page,      __( 'Contrôles qualité', 'ufsc-licence-competition' ), __( 'Contrôles qualité', 'ufsc-licence-competition' ), self::PAGE_QUALITY );
		$register_submenu( $bouts_page,        __( 'Combats', 'ufsc-licence-competition' ), __( 'Combats', 'ufsc-licence-competition' ), self::PAGE_BOUTS );
		$register_submenu( $print_page,        __( 'Impression', 'ufsc-licence-competition' ), __( 'Impression', 'ufsc-licence-competition' ), self::PAGE_PRINT );
		$register_submenu( $settings_page,     __( 'Paramètres', 'ufsc-licence-competition' ), __( 'Paramètres', 'ufsc-licence-competition' ), self::PAGE_SETTINGS );
		$register_submenu( $guide_page,        __( 'Guide', 'ufsc-licence-competition' ), __( 'Guide', 'ufsc-licence-competition' ), self::PAGE_GUIDE );
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
			return null;
		}
	}
}
