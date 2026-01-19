<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {
	const HANDLE = 'ufsc-competitions-admin';
	const SCRIPT_HANDLE = 'ufsc-competitions-admin-js';

	public function register( $hook_suffix ) {
		add_action( 'admin_enqueue_scripts', function( $hook ) use ( $hook_suffix ) {
			if ( $hook !== $hook_suffix ) {
				return;
			}

			$css_file = plugin_dir_path( __DIR__ ) . 'assets/admin.css';
			$css_url  = UFSC_LC_URL . 'includes/competitions/assets/admin.css';

			// Use filemtime when file exists for cache busting
			$ver = file_exists( $css_file ) ? filemtime( $css_file ) : '1.0.0';

			wp_enqueue_style(
				self::HANDLE,
				$css_url,
				array(),
				$ver
			);

			$js_file = plugin_dir_path( __DIR__ ) . 'assets/admin.js';
			$js_url  = UFSC_LC_URL . 'includes/competitions/assets/admin.js';
			$ver_js  = file_exists( $js_file ) ? filemtime( $js_file ) : '1.0.0';

			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				$js_url,
				array(),
				$ver_js,
				true
			);
		} );
	}
}
