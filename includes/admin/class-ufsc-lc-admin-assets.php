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
		if ( empty( $this->pages[ $hook_suffix ] ) ) {
			return;
		}

		wp_enqueue_style(
			'ufsc-lc-admin-style',
			UFSC_LC_URL . 'assets/admin/css/ufsc-lc-admin.css',
			array(),
			UFSC_LC_Plugin::DB_VERSION
		);

		wp_enqueue_script(
			'ufsc-lc-admin-script',
			UFSC_LC_URL . 'assets/admin/js/ufsc-lc-admin.js',
			array(),
			UFSC_LC_Plugin::DB_VERSION,
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
					'saveAlias'  => wp_create_nonce( 'ufsc_lc_asptt_save_alias' ),
				),
				'strings' => array(
					'selectClub'   => __( 'Sélectionner un club', 'ufsc-licence-competition' ),
					'saving'       => __( 'Enregistrement...', 'ufsc-licence-competition' ),
					'selectFirst'  => __( 'Veuillez sélectionner un club.', 'ufsc-licence-competition' ),
					'confirmTrash' => __( 'Mettre en corbeille ?', 'ufsc-licence-competition' ),
					'confirmDelete' => __( 'Supprimer définitivement ?', 'ufsc-licence-competition' ),
					'errorDefault' => __( 'Erreur', 'ufsc-licence-competition' ),
				),
			)
		);
	}
}
