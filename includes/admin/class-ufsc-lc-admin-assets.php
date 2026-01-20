<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin assets loader with robust fallbacks to avoid undefined constants.
 *
 * Enqueue only on registered admin pages via UFSC_LC_Admin_Assets::register_page()
 */

class UFSC_LC_Admin_Assets {
	private static $instance;
	private $pages = array();
	private $hook_registered = false;

	public static function register_page( $hook_suffix ) {
		if ( ! $hook_suffix ) {
			return;
		}

		$instance = self::instance();
		$instance->pages[ $hook_suffix ] = true;
		if ( ! $instance->hook_registered ) {
			$instance->hook_registered = true;
			add_action( 'admin_enqueue_scripts', array( $instance, 'enqueue' ) );
		}
	}

	private static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Resolve a reliable base dir for plugin files.
	 *
	 * @return string
	 */
	private function get_base_dir() {
		if ( defined( 'UFSC_LC_DIR' ) ) {
			return rtrim( UFSC_LC_DIR, '/\\' ) . '/';
		}
		if ( defined( 'UFSC_LC_FILE' ) ) {
			return plugin_dir_path( UFSC_LC_FILE );
		}
		// fallback to this file's directory (best effort)
		return plugin_dir_path( __DIR__ . '/..' );
	}

	/**
	 * Resolve a reliable base URL for plugin assets.
	 *
	 * @return string
	 */
	private function get_base_url() {
		if ( defined( 'UFSC_LC_URL' ) ) {
			return rtrim( UFSC_LC_URL, '/\\' ) . '/';
		}
		if ( defined( 'UFSC_LC_FILE' ) ) {
			return plugin_dir_url( UFSC_LC_FILE );
		}
		// fallback: derive from base dir (best effort)
		return plugins_url( '/', $this->get_base_dir() );
	}

	/**
	 * Enqueue styles/scripts only for registered pages.
	 *
	 * @param string $hook_suffix
	 */
	public function enqueue( $hook_suffix ) {
		// Only load on pages we registered
		if ( empty( $this->pages[ $hook_suffix ] ) ) {
			return;
		}

		$base_dir = $this->get_base_dir();
		$base_url = $this->get_base_url();

		// Admin CSS
		$css_path = $base_dir . 'assets/admin/css/ufsc-lc-admin.css';
		$css_url  = $base_url . 'assets/admin/css/ufsc-lc-admin.css';
		$ver = '1.0.0';
		if ( file_exists( $css_path ) ) {
			$ver = filemtime( $css_path );
		}

		wp_enqueue_style(
			'ufsc-lc-admin-style',
			$css_url,
			array(),
			$ver
		);

		// Admin JS
		$js_path = $base_dir . 'assets/admin/js/ufsc-lc-admin.js';
		$js_url  = $base_url . 'assets/admin/js/ufsc-lc-admin.js';
		$ver_js  = '1.0.0';
		if ( file_exists( $js_path ) ) {
			$ver_js = filemtime( $js_path );
		}

		wp_enqueue_script(
			'ufsc-lc-admin-script',
			$js_url,
			array( 'jquery' ),
			$ver_js,
			true
		);

		wp_localize_script(
			'ufsc-lc-admin-script',
			'UFSC_LC_Admin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'ufsc_lc_nonce' => wp_create_nonce( 'ufsc_lc_nonce' ),
			)
		);

		// Defensive: if old handle 'user-club-admin' registered and points to missing file, deregister to avoid 404
		if ( wp_style_is( 'user-club-admin', 'registered' ) && ! wp_style_is( 'user-club-admin', 'enqueued' ) ) {
			wp_deregister_style( 'user-club-admin' );
		}
	}
}
