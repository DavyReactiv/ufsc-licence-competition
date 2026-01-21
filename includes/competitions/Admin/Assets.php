<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {
	const HANDLE = 'ufsc-competitions-admin';
	const SCRIPT_HANDLE = 'ufsc-competitions-admin-js';

	public function register( $hook_suffix, $load_pointer = false ) {
		add_action( 'admin_enqueue_scripts', function( $hook ) use ( $hook_suffix, $load_pointer ) {
			if ( $hook !== $hook_suffix ) {
				return;
			}

			$css_file = plugin_dir_path( __DIR__ ) . 'assets/admin.css';
			$css_url  = UFSC_LC_URL . 'includes/competitions/assets/admin.css';

			if ( file_exists( $css_file ) ) {
				// Use filemtime when file exists for cache busting.
				$ver = filemtime( $css_file ) ?: '1.0.0';

				wp_enqueue_style(
					self::HANDLE,
					$css_url,
					array(),
					$ver
				);
			}

			$js_file = plugin_dir_path( __DIR__ ) . 'assets/admin.js';
			$js_url  = UFSC_LC_URL . 'includes/competitions/assets/admin.js';
			if ( $load_pointer ) {
				wp_enqueue_script( 'jquery' );
				wp_enqueue_script( 'jquery-ui-core' );
				wp_enqueue_script( 'wp-pointer' );
				wp_enqueue_style( 'wp-pointer' );
			}

			if ( file_exists( $js_file ) ) {
				$ver_js = filemtime( $js_file ) ?: '1.0.0';
				$deps   = $load_pointer ? array( 'jquery', 'jquery-ui-core', 'wp-pointer' ) : array();

				wp_enqueue_script(
					self::SCRIPT_HANDLE,
					$js_url,
					$deps,
					$ver_js,
					true
				);
			}

			// Defensive: deregister legacy handle to avoid 404 in console.
			if ( wp_style_is( 'user-club-admin', 'registered' ) ) {
				wp_deregister_style( 'user-club-admin' );
			}
		} );
	}
}
