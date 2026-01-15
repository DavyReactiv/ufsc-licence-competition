<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UFSC_LC_Admin_Assets {
	private static $instance;
	private $pages = array();

	public static function register_page( $hook_suffix ) {
		if ( ! $hook_suffix ) {
			return;
		}

		$instance = self::instance();
		$instance->pages[ $hook_suffix ] = true;
		add_action( 'load-' . $hook_suffix, array( $instance, 'on_load' ) );
	}

	private static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function on_load() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook_suffix ) {
		if ( empty( $this->pages[ $hook_suffix ] ) ) {
			return;
		}

		$css_path = UFSC_LC_DIR . 'assets/admin/css/ufsc-lc-admin.css';
		$js_path  = UFSC_LC_DIR . 'assets/admin/js/ufsc-lc-admin.js';

		wp_enqueue_style(
			'ufsc-lc-admin',
			UFSC_LC_URL . 'assets/admin/css/ufsc-lc-admin.css',
			array(),
			file_exists( $css_path ) ? (string) filemtime( $css_path ) : UFSC_LC_Plugin::DB_VERSION
		);

		wp_enqueue_script(
			'ufsc-lc-admin',
			UFSC_LC_URL . 'assets/admin/js/ufsc-lc-admin.js',
			array(),
			file_exists( $js_path ) ? (string) filemtime( $js_path ) : UFSC_LC_Plugin::DB_VERSION,
			true
		);

		wp_localize_script(
			'ufsc-lc-admin',
			'UFSC_LC_Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'clubSearch' => wp_create_nonce( 'ufsc_lc_club_search' ),
					'saveAlias'  => wp_create_nonce( 'ufsc_lc_asptt_save_alias' ),
				),
				'strings' => array(
					'selectClub'   => __( 'Sélectionner un club', 'ufsc-licence-competition' ),
					'saving'       => __( 'Enregistrement...', 'ufsc-licence-competition' ),
					'selectFirst'  => __( 'Veuillez sélectionner un club.', 'ufsc-licence-competition' ),
					'errorDefault' => __( 'Erreur', 'ufsc-licence-competition' ),
				),
			)
		);
	}
}
