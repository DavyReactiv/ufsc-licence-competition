<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

	private function get_base_dir() {
		if ( defined( 'UFSC_LC_DIR' ) ) {
			return rtrim( UFSC_LC_DIR, '/\\' ) . '/';
		}
		if ( defined( 'UFSC_LC_FILE' ) ) {
			return plugin_dir_path( UFSC_LC_FILE );
		}
		return plugin_dir_path( __DIR__ . '/..' );
	}

	private function get_base_url() {
		if ( defined( 'UFSC_LC_URL' ) ) {
			return rtrim( UFSC_LC_URL, '/\\' ) . '/';
		}
		if ( defined( 'UFSC_LC_FILE' ) ) {
			return plugin_dir_url( UFSC_LC_FILE );
		}
		return plugins_url( '/', $this->get_base_dir() );
	}

	public function enqueue( $hook_suffix ) {
		// Only load on pages we registered
		if ( empty( $this->pages[ $hook_suffix ] ) ) {
			return;
		}

		$base_dir = $this->get_base_dir();
		$base_url = $this->get_base_url();

		$css_candidates = array(
			'assets/admin/css/ufsc-lc-admin.css',
			'assets/admin/ufsc-lc-admin.css',
			'includes/competitions/assets/admin.css',
			'includes/competitions/assets/css/admin.css',
			'includes/competitions/assets/css/ufsc-lc-admin.css',
		);

		$js_candidates = array(
			'assets/admin/js/ufsc-lc-admin.js',
			'assets/admin/ufsc-lc-admin.js',
			'includes/competitions/assets/admin.js',
			'includes/competitions/assets/js/admin.js',
			'includes/competitions/assets/js/ufsc-lc-admin.js',
		);

		// Resolve CSS
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
		if ( $css_found ) {
			$ver = filemtime( $css_path ) ?: '1.0.0';
			wp_enqueue_style( 'ufsc-lc-admin-style', $css_url, array(), $ver );
		}

		// Resolve JS
		$js_found = false;
		$js_requires_jquery = false;
		foreach ( $js_candidates as $rel ) {
			$path = $base_dir . $rel;
			if ( file_exists( $path ) ) {
				$js_path = $path;
				$js_url  = $base_url . $rel;
				$js_found = true;
				if ( strpos( $rel, 'assets/admin/js/ufsc-lc-admin.js' ) !== false || strpos( $rel, 'assets/admin/ufsc-lc-admin.js' ) !== false ) {
					$js_requires_jquery = true;
				}
				break;
			}
		}

		if ( $js_found ) {
			$ver_js = filemtime( $js_path ) ?: '1.0.0';
			$deps = $js_requires_jquery ? array( 'jquery' ) : array();
			wp_enqueue_script( 'ufsc-lc-admin-script', $js_url, $deps, $ver_js, true );

			wp_localize_script(
				'ufsc-lc-admin-script',
				'UFSC_LC_Admin',
				array(
					'ajaxurl'      => admin_url( 'admin-ajax.php' ),
					'ufsc_lc_nonce' => wp_create_nonce( 'ufsc_lc_nonce' ),
				)
			);
		}

		// Defensive: deregister legacy handle to avoid 404 in console.
		// Some installs left an old 'user-club-admin' handle registered pointing to a missing file.
		if ( wp_style_is( 'user-club-admin', 'registered' ) ) {
			wp_deregister_style( 'user-club-admin' );
		}
	}
}
