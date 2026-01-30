<?php

namespace UFSC\Competitions\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Assets {
	const HANDLE = 'ufsc-competitions-admin';
	const SCRIPT_HANDLE = 'ufsc-competitions-admin-js';

	public function register( $hook_suffix, $load_pointer = false, $page_slug = '' ) {
		add_action( 'admin_enqueue_scripts', function( $hook ) use ( $hook_suffix, $load_pointer, $page_slug ) {
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

			if ( 'ufsc-competitions-entries' === $page_slug ) {
				$entry_css_file = plugin_dir_path( __DIR__ ) . 'assets/admin-entries.css';
				$entry_css_url  = UFSC_LC_URL . 'includes/competitions/assets/admin-entries.css';
				if ( file_exists( $entry_css_file ) ) {
					$entry_css_ver = filemtime( $entry_css_file ) ?: '1.0.0';
					wp_enqueue_style( 'ufsc-competitions-entries-admin', $entry_css_url, array( self::HANDLE ), $entry_css_ver );
				}

				$entry_js_file = plugin_dir_path( __DIR__ ) . 'assets/admin-entries.js';
				$entry_js_url  = UFSC_LC_URL . 'includes/competitions/assets/admin-entries.js';
				if ( file_exists( $entry_js_file ) ) {
					$entry_js_ver = filemtime( $entry_js_file ) ?: '1.0.0';
					wp_enqueue_script( 'ufsc-competitions-entries-admin', $entry_js_url, array(), $entry_js_ver, true );
					wp_localize_script(
						'ufsc-competitions-entries-admin',
						'ufscEntriesSearch',
						array(
							'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
							'nonce'                    => wp_create_nonce( 'ufsc_lc_entries' ),
							'searchEmptyMessage'       => __( 'Aucun résultat trouvé.', 'ufsc-licence-competition' ),
							'searchErrorMessage'       => __( 'Erreur de recherche, réessayez.', 'ufsc-licence-competition' ),
							'selectionRequiredMessage' => __( 'Veuillez sélectionner un licencié dans la liste.', 'ufsc-licence-competition' ),
							'autoCategoryLabel'        => __( 'Catégorie auto détectée :', 'ufsc-licence-competition' ),
							'autoCategoryEmptyLabel'   => __( 'Non déterminée', 'ufsc-licence-competition' ),
						)
					);
				}
			}

			// Defensive: deregister legacy handle to avoid 404 in console.
			if ( wp_style_is( 'user-club-admin', 'enqueued' ) ) {
				wp_dequeue_style( 'user-club-admin' );
			}
			if ( wp_style_is( 'user-club-admin', 'registered' ) ) {
				wp_deregister_style( 'user-club-admin' );
			}
		} );
	}
}
