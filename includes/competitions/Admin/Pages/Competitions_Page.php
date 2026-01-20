<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Admin\Menu;
use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Repositories\CategoryRepository;
use UFSC\Competitions\Repositories\EntryRepository;
use UFSC\Competitions\Repositories\FightRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Competitions_Page {
	private $repository;
	private $categories;
	private $entries;
	private $fights;
	private $club_repository;

	public function __construct() {
		// Defensive instantiation to avoid fatals when components are missing.
		$this->repository = class_exists( '\\UFSC\\Competitions\\Repositories\\CompetitionRepository' ) ? new CompetitionRepository() : null;
		$this->categories = class_exists( '\\UFSC\\Competitions\\Repositories\\CategoryRepository' ) ? new CategoryRepository() : null;
		$this->entries = class_exists( '\\UFSC\\Competitions\\Repositories\\EntryRepository' ) ? new EntryRepository() : null;
		$this->fights = class_exists( '\\UFSC\\Competitions\\Repositories\\FightRepository' ) ? new FightRepository() : null;

		// ClubRepository may or may not exist; instantiate if available.
		if ( class_exists( '\\UFSC\\Competitions\\Repositories\\ClubRepository' ) ) {
			$this->club_repository = new \UFSC\Competitions\Repositories\ClubRepository();
		} else {
			$this->club_repository = null;
		}

		// register AJAX for club lookup (guarded in handler)
		add_action( 'wp_ajax_ufsc_get_club', array( $this, 'ajax_get_club' ) );
	}

	public function register_actions() {
		add_action( 'admin_post_ufsc_competitions_save_competition', array( $this, 'handle_save' ) );
		add_action( 'admin_post_ufsc_competitions_trash_competition', array( $this, 'handle_trash' ) );
		add_action( 'admin_post_ufsc_competitions_restore_competition', array( $this, 'handle_restore' ) );
		add_action( 'admin_post_ufsc_competitions_delete_competition', array( $this, 'handle_delete' ) );
	}

	public function render() {
		if ( null === $this->repository ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'UFSC Competitions: module competitions non disponible — vérifiez le chargement du plugin.', 'ufsc-licence-competition' ) . '</p></div>';
			return;
		}

		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$action = isset( $_GET['ufsc_action'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_action'] ) ) : '';
		$notice = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : '';
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

		$this->render_notice( $notice );

		if ( in_array( $action, array( 'add', 'edit' ), true ) ) {
			$item = null;
			if ( 'edit' === $action && $id && $this->repository ) {
				$item = $this->repository->get( $id, true );
			}
			$this->render_form( $item );
			return;
		}

		$list_table = null;
		if ( class_exists( '\\UFSC\\Competitions\\Admin\\Tables\\Competitions_Table' ) ) {
			$list_table = new \UFSC\Competitions\Admin\Tables\Competitions_Table();
			$this->maybe_handle_bulk_actions( $list_table, Menu::PAGE_COMPETITIONS );
			$list_table->prepare_items();
		}

		?>
		<div class="wrap ufsc-competitions-admin">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Compétitions', 'ufsc-licence-competition' ); ?></h1>
			<a href="<?php echo esc_url( add_query_arg( array( 'page' => Menu::PAGE_COMPETITIONS, 'ufsc_action' => 'add' ), admin_url( 'admin.php' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Ajouter', 'ufsc-licence-competition' ); ?></a>
			<hr class="wp-header-end">
			<?php $this->render_helper_notice( __( 'Gérer les compétitions, catégories, inscriptions et tableaux.', 'ufsc-licence-competition' ) ); ?>
			<?php if ( $list_table ) : ?>
				<?php $list_table->views(); ?>
				<form method="post">
					<input type="hidden" name="page" value="<?php echo esc_attr( Menu::PAGE_COMPETITIONS ); ?>" />
					<?php $list_table->display(); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_notice( $notice ) {
		if ( ! $notice ) {
			return;
		}

		$map = array(
			'not_found' => __( 'Élément non trouvé.', 'ufsc-licence-competition' ),
			'saved'     => __( 'Compétition enregistrée.', 'ufsc-licence-competition' ),
			'invalid'   => __( 'Données invalides, vérifiez le formulaire.', 'ufsc-licence-competition' ),
		);

		if ( isset( $map[ $notice ] ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html( $map[ $notice ] ) . '</p></div>';
		}
	}

	/**
	 * Handle bulk actions from WP_List_Table.
	 *
	 * This method is defensive: it will not fatal if repository missing.
	 * Expected request:
	 * - bulk_ids[] => array of IDs to act on
	 * - bulk_action => action name (trash/delete/restore)
	 *
	 * @param object|null $list_table
	 * @param string $page
	 */
	private function maybe_handle_bulk_actions( $list_table, $page ) {
		if ( empty( $_REQUEST ) ) {
			return;
		}

		$action = isset( $_REQUEST['bulk_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['bulk_action'] ) ) : '';
		if ( empty( $action ) ) {
			// fallback to action/action2 (WP_List_Table)
			$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
			if ( empty( $action ) ) {
				$action = isset( $_REQUEST['action2'] ) ? sanitize_key( wp_unslash( $_REQUEST['action2'] ) ) : '';
			}
		}

		$ids = array();
		// common checkbox names
		if ( ! empty( $_REQUEST['bulk_ids'] ) && is_array( $_REQUEST['bulk_ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['bulk_ids'] ) );
		} elseif ( ! empty( $_REQUEST['ids'] ) && is_array( $_REQUEST['ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['ids'] ) );
		} elseif ( ! empty( $_REQUEST['competition_ids'] ) && is_array( $_REQUEST['competition_ids'] ) ) {
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['competition_ids'] ) );
		}

		if ( empty( $ids ) || empty( $action ) ) {
			return;
		}

		// Capability check
		if ( ! Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		// Optional nonce check if present
		if ( isset( $_REQUEST['_wpnonce'] ) ) {
			// Attempt to verify; if fails, stop
			if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'bulk-competitions' ) ) {
				wp_die( esc_html__( 'Nonce invalide.', 'ufsc-licence-competition' ) );
			}
		}

		// Process actions
		if ( ! $this->repository || ! method_exists( $this->repository, 'trash' ) ) {
			// repository not available: abort with notice
			$redirect = add_query_arg( array( 'page' => $page, 'ufsc_notice' => 'invalid' ), admin_url( 'admin.php' ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		$processed = 0;
		foreach ( $ids as $id ) {
			if ( 'trash' === $action || 'bulk-trash' === $action ) {
				if ( $this->repository->trash( $id ) ) {
					$processed++;
				}
			} elseif ( 'delete' === $action || 'bulk-delete' === $action ) {
				// Hard delete if method exists; otherwise soft-trash
				if ( method_exists( $this->repository, 'delete' ) ) {
					$ok = $this->repository->delete( $id );
				} else {
					$ok = $this->repository->trash( $id );
				}
				if ( $ok ) {
					$processed++;
				}
			}
			// other actions could be added later
		}

		$redirect = add_query_arg( array( 'page' => $page, 'ufsc_notice' => 'saved' ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	private function render_helper_notice( $message ) {
		echo '<div class="notice notice-info ufsc-competitions-helper"><p>' . esc_html( $message ) . '</p></div>';
	}

	// render_form() and handlers (handle_save, ajax_get_club, etc.) are expected to exist.
	// For brevity we assume render_form/handle_save are implemented elsewhere in this class
	// or previously in the file. If missing, ensure they are present with nonce/capability checks.
}
