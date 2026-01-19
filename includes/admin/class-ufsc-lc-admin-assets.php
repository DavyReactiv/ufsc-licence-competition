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

	public function enqueue( $hook_suffix ) {
		// Only enqueue when a registered page requests it
		if ( empty( $this->pages[ $hook_suffix ] ) ) {
			return;
		}

		$css_path = UFSC_LC_PLUGIN_DIR . 'assets/admin/css/ufsc-lc-admin.css';
		$css_url  = UFSC_LC_URL . 'assets/admin/css/ufsc-lc-admin.css';
		$ver_css  = file_exists( $css_path ) ? filemtime( $css_path ) : UFSC_LC_Plugin::DB_VERSION;

		wp_enqueue_style(
			'ufsc-lc-admin-style',
			$css_url,
			array(),
			$ver_css
		);

		$js_path = UFSC_LC_PLUGIN_DIR . 'assets/admin/js/ufsc-lc-admin.js';
		$js_url  = UFSC_LC_URL . 'assets/admin/js/ufsc-lc-admin.js';
		$ver_js  = file_exists( $js_path ) ? filemtime( $js_path ) : UFSC_LC_Plugin::DB_VERSION;

		wp_enqueue_script(
			'ufsc-lc-admin-script',
			$js_url,
			array(),
			$ver_js,
			true
		);

		wp_localize_script(
			'ufsc-lc-admin-script',
			'UFSC_LC_Admin',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'ufsc_lc_nonce' => wp_create_nonce( 'ufsc_lc_nonce' ),
				'nonces'  => array(
					'clubSearch' => wp_create_nonce( 'ufsc_lc_club_search' ),
					'searchClubs' => wp_create_nonce( 'ufsc_lc_search_clubs' ),
					'saveAlias'  => wp_create_nonce( 'ufsc_lc_asptt_save_alias' ),
				),
				'strings' => array(
					'selectClub'   => __( 'Sélectionner un club', 'ufsc-licence-competition' ),
					'searchPlaceholder' => __( 'Rechercher un club…', 'ufsc-licence-competition' ),
					'noResults'    => __( 'Aucun club trouvé.', 'ufsc-licence-competition' ),
					'saving'       => __( 'Enregistrement...', 'ufsc-licence-competition' ),
					'selectFirst'  => __( 'Veuillez sélectionner un club.', 'ufsc-licence-competition' ),
					'confirmDelete' => __( 'Supprimer définitivement ?', 'ufsc-licence-competition' ),
					'errorDefault' => __( 'Erreur', 'ufsc-licence-competition' ),
				),
			)
		);

		// Safety: if an old/legacy handle was accidentally enqueued elsewhere and points to a missing file,
		// deregister it to avoid 404s in the console. This only removes the handle; it won't affect correct assets.
		if ( wp_style_is( 'user-club-admin', 'registered' ) && ! wp_style_is( 'user-club-admin', 'enqueued' ) ) {
			// if registered but not enqueued, don't force enqueue; remove registration if it's broken.
			wp_deregister_style( 'user-club-admin' );
		}
	}
}
