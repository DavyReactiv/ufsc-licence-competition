<?php

namespace UFSC\Competitions\Front;

use UFSC\Competitions\Front\Shortcodes\CompetitionDetailsShortcode;
use UFSC\Competitions\Front\Shortcodes\CompetitionsListShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Front {
	public static function init(): void {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		self::load_dependencies();

		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );

		self::register_shortcodes();
		self::register_rewrite_rules();

		if ( class_exists( '\\UFSC\\Competitions\\Front\\Entries\\EntriesModule' ) ) {
			\UFSC\Competitions\Front\Entries\EntriesModule::register();
		}
	}

	public static function register_shortcodes(): void {
		if ( class_exists( CompetitionDetailsShortcode::class ) ) {
			( new CompetitionDetailsShortcode() )->register();
		}

		if ( class_exists( CompetitionsListShortcode::class ) ) {
			( new CompetitionsListShortcode() )->register();
		}
	}

	public static function register_query_vars( array $vars ): array {
		// Modern
		$vars[] = 'competition_id';

		// Legacy / backward compatibility
		$vars[] = 'ufsc_competition_id';

		return $vars;
	}

	/**
	 * Optional pretty URL rewrite.
	 * Disabled by default and only active if a valid details page id is provided.
	 *
	 * URL: /competitions/competition/{id}/
	 * Routes to: page_id={details_page_id}&competition_id={id}
	 */
	public static function register_rewrite_rules(): void {
		$enabled         = (bool) apply_filters( 'ufsc_competitions_front_enable_rewrite', false );
		$details_page_id = self::get_details_page_id();

		// Anti-conflict: never add rewrite rules unless explicitly enabled AND a valid details page exists.
		if ( ! $enabled || ! $details_page_id ) {
			return;
		}

		add_rewrite_rule(
			'^competitions/competition/([0-9]+)/?$',
			'index.php?page_id=' . (int) $details_page_id . '&competition_id=$matches[1]',
			'top'
		);
	}

	/**
	 * Flush rewrites safely.
	 * Must only be called on activation/deactivation or permalinks save.
	 */
	public static function flush_rewrite_rules(): void {
		$enabled         = (bool) apply_filters( 'ufsc_competitions_front_enable_rewrite', false );
		$details_page_id = self::get_details_page_id();

		// Anti-conflict: do not flush if rewrite is not configured.
		if ( ! $enabled || ! $details_page_id ) {
			return;
		}

		self::register_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Details page id used by rewrite routing.
	 * Keep it filter-based so it never impacts admin and stays configurable.
	 */
	public static function get_details_page_id(): int {
		$page_id = (int) apply_filters( 'ufsc_competitions_front_details_page_id', 0 );
		return $page_id > 0 ? $page_id : 0;
	}

	/**
	 * Resolve competition id from request.
	 * Priority:
	 * 1) ?competition_id=123 (GET)
	 * 2) query_var competition_id (rewrite to page_id)
	 * 3) legacy query_var ufsc_competition_id (older rewrite variant)
	 */
	public static function get_competition_id_from_request(): ?int {
		$raw = null;

		if ( isset( $_GET['competition_id'] ) ) {
			$raw = wp_unslash( $_GET['competition_id'] );
		} elseif ( get_query_var( 'competition_id' ) ) {
			$raw = get_query_var( 'competition_id' );
		} elseif ( get_query_var( 'ufsc_competition_id' ) ) {
			$raw = get_query_var( 'ufsc_competition_id' );
		}

		$id = absint( $raw );
		return $id > 0 ? $id : null;
	}

	public static function get_competition_details_url( int $competition_id, string $base_url = '' ): string {
		$competition_id = absint( $competition_id );
		if ( ! $competition_id ) {
			return '';
		}

		$base_url = $base_url ? $base_url : (string) get_permalink();
		if ( ! $base_url ) {
			$base_url = home_url( '/' );
		}

		return add_query_arg( 'competition_id', $competition_id, $base_url );
	}

	private static function load_dependencies(): void {
		$base = __DIR__;

		$files = array(
			$base . '/Access/ClubAccess.php',
			$base . '/Entries/EntryActions.php',
			$base . '/Entries/EntryFormRenderer.php',
			$base . '/Entries/EntriesModule.php',
			$base . '/Repositories/EntryFrontRepository.php',
			$base . '/Repositories/CompetitionReadRepository.php',
			$base . '/Shortcodes/CompetitionsListShortcode.php',
			$base . '/Shortcodes/CompetitionDetailsShortcode.php',
		);

		foreach ( $files as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}
}
