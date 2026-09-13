<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compatibility bridge for UFSC Gestion write endpoints.
 *
 * Some current UFSC Gestion status/save handlers redirect or terminate the
 * request before emitting a dedicated post-validation hook. We arm a PHP
 * shutdown callback before those handlers run, then re-read the final database
 * state. The generator itself remains the single source of truth and refuses
 * non-validated licences or licences without an official UFSC number.
 */
final class UFSC_LC_Licence_Pdf_Hooks {
	/** @var array<int,int> */
	private static $licence_ids = array();

	/** @var bool */
	private static $shutdown_registered = false;

	/**
	 * Register only write-path bridges; no front-end/page-load polling is added.
	 */
	public static function register() {
		$admin_actions = array(
			'admin_post_ufsc_sql_save_licence',
			'admin_post_ufsc_update_licence_status',
			'admin_post_ufsc_save_licence',
			'admin_post_ufsc_update_licence',
			'admin_post_ufsc_generate_identifier',
		);

		foreach ( $admin_actions as $action ) {
			add_action( $action, array( __CLASS__, 'arm_from_request' ), 1 );
		}

		add_action( 'wp_ajax_ufsc_update_licence_status', array( __CLASS__, 'arm_from_request' ), 1 );
		add_action( 'admin_head', array( __CLASS__, 'protect_admin_preview_styles' ), 99 );
	}

	/**
	 * The PDF markup contains document-level body defaults for Dompdf. On the
	 * browser preview page, restore the WordPress admin shell with a more
	 * specific selector so the card CSS cannot restyle the surrounding admin UI.
	 */
	public static function protect_admin_preview_styles() {
		$page = isset( $_GET['page'] ) && ! is_array( $_GET['page'] )
			? sanitize_key( wp_unslash( $_GET['page'] ) )
			: '';
		if ( UFSC_LC_Licence_Pdf_Generator::ADMIN_PAGE_SLUG !== $page ) {
			return;
		}
		?>
		<style id="ufsc-lc-license-preview-admin-guard">
			body.wp-admin {
				margin: 0;
				padding: 0;
				background: #f0f0f1;
				color: #3c434a;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			}
		</style>
		<?php
	}

	/**
	 * Capture the licence id before the parent plugin's handler runs.
	 */
	public static function arm_from_request() {
		$licence_id = self::request_licence_id();
		if ( $licence_id <= 0 ) {
			return;
		}

		self::$licence_ids[ $licence_id ] = $licence_id;

		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			register_shutdown_function( array( __CLASS__, 'run_after_parent_write' ) );
		}
	}

	/**
	 * Re-check final DB state after the parent write/redirect has completed.
	 */
	public static function run_after_parent_write() {
		if ( ! class_exists( 'UFSC_LC_Licence_Pdf_Generator' ) ) {
			return;
		}

		foreach ( self::$licence_ids as $licence_id ) {
			UFSC_LC_Licence_Pdf_Generator::maybe_generate_for_licence( $licence_id, false );
		}
	}

	/**
	 * Resolve the id names used by the existing UFSC Gestion endpoints.
	 */
	private static function request_licence_id() {
		foreach ( array( 'licence_id', 'id' ) as $key ) {
			if ( isset( $_REQUEST[ $key ] ) && ! is_array( $_REQUEST[ $key ] ) ) {
				$id = absint( wp_unslash( $_REQUEST[ $key ] ) );
				if ( $id > 0 ) {
					return $id;
				}
			}
		}

		return 0;
	}
}
