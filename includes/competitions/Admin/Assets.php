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

			wp_enqueue_style(
				self::HANDLE,
				UFSC_LC_URL . 'includes/competitions/assets/admin.css',
				array(),
				'1.0.0'
			);

			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				UFSC_LC_URL . 'includes/competitions/assets/admin.js',
				array(),
				'1.0.0',
				true
			);
		} );
	}
}
