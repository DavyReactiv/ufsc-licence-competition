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

		// Candidate lists (relative to plugin root / base url)
		$css_candidates = array(
			'assets/admin/css/ufsc-lc-admin.css',            // historical location (prefer)
			'assets/admin/ufsc-lc-admin.css',
			'includes/competitions/assets/admin.css',        // repo location fallback
			'includes/competitions/assets/css/admin.css',
			'includes/competitions/assets/css/ufsc-lc-admin.css',
		);

		$js_candidates = array(
			'assets/admin/js/ufsc-lc-admin.js',              // historical location (prefer)
			'assets/admin/ufsc-lc-admin.js',
			'includes/competitions/assets/admin.js',         // repo location fallback
			'includes/competitions/assets/js/admin.js',
			'includes/competitions/assets/js/ufsc-lc-admin.js',
		);

		// Resolve CSS: find first existing file
		$css_found = false;
		foreach ( $css_candidates as $rel ) {
			$path = $base_dir . $rel;
			if ( file_exists( $path ) ) {
				$css_path = $path;
				$css_url  = $base_url . $rel;
				$css_found = true;
				break;
			}
		}
		// If none found, still build URL to the preferred location (non-fatal) but do not enqueue
		if ( $css_found ) {
			$ver = filemtime( $css_path ) ?: '1.0.0';
			wp_enqueue_style(
				'ufsc-lc-admin-style',
				$css_url,
				array(),
				$ver
			);
		}

		// Resolve JS: find first existing file
		$js_found = false;
		$js_requires_jquery = false;
		foreach ( $js_candidates as $rel ) {
			$path = $base_dir . $rel;
			if ( file_exists( $path ) ) {
				$js_path = $path;
				$js_url  = $base_url . $rel;
				$js_found = true;
				// keep backwards compatibility: if the chosen file matches historical assets/admin/js/ufsc-lc-admin.js, preserve jQuery dependency
				if ( strpos( $rel, 'assets/admin/js/ufsc-lc-admin.js' ) !== false || strpos( $rel, 'assets/admin/ufsc-lc-admin.js' ) !== false ) {
					$js_requires_jquery = true;
				}
				break;
			}
		}

		if ( $js_found ) {
			$ver_js = filemtime( $js_path ) ?: '1.0.0';
			$deps = $js_requires_jquery ? array( 'jquery' ) : array();
			wp_enqueue_script(
				'ufsc-lc-admin-script',
				$js_url,
				$deps,
				$ver_js,
				true
			);

			// Localize only when we enqueued a script
			wp_localize_script(
				'ufsc-lc-admin-script',
				'UFSC_LC_Admin',
				array(
					'ajaxurl'      => admin_url( 'admin-ajax.php' ),
					'ufsc_lc_nonce' => wp_create_nonce( 'ufsc_lc_nonce' ),
				)
			);
		}

		// Defensive: if old handle 'user-club-admin' registered and points to missing file, deregister to avoid 404
		if ( wp_style_is( 'user-club-admin', 'registered' ) && ! wp_style_is( 'user-club-admin', 'enqueued' ) ) {
			wp_deregister_style( 'user-club-admin' );
		}
	}
}
