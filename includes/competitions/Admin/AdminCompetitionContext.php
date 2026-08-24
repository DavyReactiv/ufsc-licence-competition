<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Repositories\CompetitionRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps competition admin screens scoped to one event and uses the full
 * WordPress admin content width.
 *
 * The active competition is stored per user. Direct links containing a
 * competition id always win and refresh the stored context. The main
 * competition list remains unscoped so administrators can still choose or
 * create an event.
 */
class AdminCompetitionContext {
	private const USER_META_KEY = 'ufsc_competitions_active_competition_id';
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_action( 'admin_init', array( __CLASS__, 'synchronize' ), 0 );
		add_action( 'admin_head', array( __CLASS__, 'render_full_width_css' ), 99 );
	}

	public static function synchronize(): void {
		if ( ! is_admin() ) {
			return;
		}

		$page = self::current_page();
		if ( ! self::is_competition_page( $page ) ) {
			return;
		}

		$explicit_id = self::explicit_competition_id( $page );
		if ( $explicit_id > 0 && self::is_accessible_competition( $explicit_id ) ) {
			self::remember( $explicit_id );
			self::inject( $explicit_id );
			return;
		}

		// The pilotage/list screen must stay global so an administrator can pick
		// another event. Editing an event is handled above through its `id`.
		if ( 'ufsc-competitions' === $page ) {
			return;
		}

		$competition_id = self::remembered_competition_id();
		if ( $competition_id <= 0 ) {
			$competition_id = self::first_accessible_competition_id();
			if ( $competition_id > 0 ) {
				self::remember( $competition_id );
			}
		}

		if ( $competition_id > 0 ) {
			self::inject( $competition_id );
		}
	}

	public static function render_full_width_css(): void {
		$page = self::current_page();
		if ( ! self::is_competition_page( $page ) ) {
			return;
		}
		?>
		<style id="ufsc-competitions-full-width-admin">
			body.ufsc-competitions-premium-ui #wpbody-content > .wrap,
			body.ufsc-competitions-premium-ui .wrap.ufsc-competitions-admin,
			body.ufsc-competitions-premium-ui .ufsc-competitions-admin {
				box-sizing: border-box;
				width: auto !important;
				max-width: none !important;
				margin-right: 20px !important;
			}

			body.ufsc-competitions-premium-ui .ufsc-admin-page-header,
			body.ufsc-competitions-premium-ui .ufsc-admin-surface,
			body.ufsc-competitions-premium-ui .ufsc-admin-section-card,
			body.ufsc-competitions-premium-ui .ufsc-competitions-table-wrap,
			body.ufsc-competitions-premium-ui .ufsc-competitions-table-wrapper,
			body.ufsc-competitions-premium-ui .ufsc-competitions-entries-table-wrap,
			body.ufsc-competitions-premium-ui form,
			body.ufsc-competitions-premium-ui .form-table {
				max-width: none !important;
			}

			body.ufsc-competitions-premium-ui .ufsc-competitions-table-wrap,
			body.ufsc-competitions-premium-ui .ufsc-competitions-table-wrapper,
			body.ufsc-competitions-premium-ui .ufsc-competitions-entries-table-wrap {
				width: 100% !important;
				overflow-x: auto;
			}

			body.ufsc-competitions-premium-ui .wp-list-table {
				width: 100%;
			}
		</style>
		<?php
	}

	private static function current_page(): string {
		return isset( $_REQUEST['page'] ) && is_scalar( $_REQUEST['page'] )
			? sanitize_key( wp_unslash( (string) $_REQUEST['page'] ) )
			: '';
	}

	private static function is_competition_page( string $page ): bool {
		return '' !== $page && 0 === strpos( $page, 'ufsc-competitions' );
	}

	private static function explicit_competition_id( string $page ): int {
		foreach ( array( 'competition_id', 'ufsc_competition_id' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ) {
				$id = absint( wp_unslash( (string) $_REQUEST[ $key ] ) );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}

		// On the pilotage edit screen `id` is the competition id. On other
		// screens it may be an entry/fight id and must never become the context.
		$action = isset( $_REQUEST['ufsc_action'] ) && is_scalar( $_REQUEST['ufsc_action'] )
			? sanitize_key( wp_unslash( (string) $_REQUEST['ufsc_action'] ) )
			: '';
		if ( 'ufsc-competitions' === $page && 'edit' === $action && isset( $_REQUEST['id'] ) ) {
			return absint( wp_unslash( (string) $_REQUEST['id'] ) );
		}

		return 0;
	}

	private static function inject( int $competition_id ): void {
		$competition_id = absint( $competition_id );
		if ( $competition_id <= 0 ) {
			return;
		}

		$_GET['competition_id']     = $competition_id;
		$_REQUEST['competition_id'] = $competition_id;

		// Existing list tables also understand this legacy/filter key. Keeping it
		// synchronized makes their dropdown immediately reflect the event context.
		if ( ! isset( $_REQUEST['ufsc_competition_id'] ) || 0 === absint( $_REQUEST['ufsc_competition_id'] ) ) {
			$_REQUEST['ufsc_competition_id'] = $competition_id;
		}
	}

	private static function remember( int $competition_id ): void {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		update_user_meta( $user_id, self::USER_META_KEY, absint( $competition_id ) );
	}

	private static function remembered_competition_id(): int {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0;
		}

		$competition_id = absint( get_user_meta( $user_id, self::USER_META_KEY, true ) );
		if ( $competition_id > 0 && self::is_accessible_competition( $competition_id ) ) {
			return $competition_id;
		}

		return 0;
	}

	private static function is_accessible_competition( int $competition_id ): bool {
		$competition_id = absint( $competition_id );
		if ( $competition_id <= 0 || ! class_exists( CompetitionRepository::class ) ) {
			return false;
		}

		foreach ( self::accessible_competitions() as $competition ) {
			if ( $competition_id === absint( $competition->id ?? 0 ) ) {
				return true;
			}
		}

		return false;
	}

	private static function first_accessible_competition_id(): int {
		foreach ( self::accessible_competitions() as $competition ) {
			$id = absint( $competition->id ?? 0 );
			if ( $id > 0 ) {
				return $id;
			}
		}

		return 0;
	}

	private static function accessible_competitions(): array {
		if ( ! class_exists( CompetitionRepository::class ) ) {
			return array();
		}

		$filters = array( 'view' => 'all' );
		if ( function_exists( 'ufsc_lc_competitions_apply_scope_to_query_args' ) ) {
			$filters = ufsc_lc_competitions_apply_scope_to_query_args( $filters );
		}

		$repository = new CompetitionRepository();
		$items      = $repository->list( $filters, 500, 0 );
		return is_array( $items ) ? $items : array();
	}
}
