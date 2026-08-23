<?php

namespace UFSC\Competitions\Admin\Pages;

use UFSC\Competitions\Repositories\CompetitionRepository;
use UFSC\Competitions\Services\CompetitionFilters;
use UFSC\Competitions\Services\DisciplineRegistry;
use UFSC\Competitions\Services\EventFormatRegistry;
use UFSC\Competitions\Services\EventProgramRegistry;
use UFSC\Competitions\Services\LogService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Premium editor for fight-card/tournament blocks inside one event. */
class EventProgram_Page {
	public const PAGE_SLUG = 'ufsc-competitions-program';

	public static function register_menu(): void {
		$capability = class_exists( '\\UFSC\\Competitions\\Capabilities' )
			? \UFSC\Competitions\Capabilities::get_edit_capability()
			: 'manage_options';

		add_submenu_page(
			'ufsc-competitions',
			__( 'Programme événement', 'ufsc-licence-competition' ),
			__( 'Programme', 'ufsc-licence-competition' ),
			$capability,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_actions(): void {
		add_action( 'admin_post_ufsc_competitions_save_event_program', array( __CLASS__, 'handle_save' ) );
	}

	public static function render(): void {
		if ( ! class_exists( '\\UFSC\\Competitions\\Capabilities' ) || ! \UFSC\Competitions\Capabilities::user_can_read() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ) );
		}

		$repository = new CompetitionRepository();
		// Read-only navigation state; no mutation is performed from these GET values.
		$competition_id = isset( $_GET['competition_id'] ) ? absint( wp_unslash( $_GET['competition_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$competitions   = $repository->list( array( 'view' => 'all' ), 300, 0 );
		$competition    = $competition_id ? $repository->get( $competition_id, true ) : null;
		$notice         = isset( $_GET['ufsc_notice'] ) ? sanitize_key( wp_unslash( $_GET['ufsc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $notice ) {
			self::render_notice( $notice );
		}

		?>
		<div class="wrap ufsc-competitions-admin ufsc-program-page">
			<header class="ufsc-admin-page-header">
				<div>
					<p class="ufsc-admin-page-kicker"><?php esc_html_e( 'Construction du programme', 'ufsc-licence-competition' ); ?></p>
					<h1><?php esc_html_e( 'Programme de l’événement', 'ufsc-licence-competition' ); ?></h1>
					<p class="ufsc-admin-page-description"><?php esc_html_e( 'Composez un gala, un open ou un championnat en blocs indépendants : combats directs, mini-tournoi, poule ou séquence manuelle. Un même gala peut donc contenir plusieurs formats sans dupliquer la compétition.', 'ufsc-licence-competition' ); ?></p>
				</div>
			</header>

			<section class="ufsc-admin-surface ufsc-program-selector-card">
				<form method="get" class="ufsc-admin-filters ufsc-program-selector">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
					<label for="ufsc-program-competition"><strong><?php esc_html_e( 'Événement à organiser', 'ufsc-licence-competition' ); ?></strong></label>
					<select id="ufsc-program-competition" name="competition_id" required>
						<option value="0"><?php esc_html_e( 'Sélectionner une compétition', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $competitions as $item ) : ?>
							<option value="<?php echo esc_attr( (int) ( $item->id ?? 0 ) ); ?>" <?php selected( $competition_id, (int) ( $item->id ?? 0 ) ); ?>>
								<?php echo esc_html( (string) ( $item->name ?? '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Ouvrir le programme', 'ufsc-licence-competition' ); ?></button>
				</form>
			</section>

			<?php if ( ! $competition ) : ?>
				<div class="ufsc-empty-state">
					<strong><?php esc_html_e( 'Choisissez un événement.', 'ufsc-licence-competition' ); ?></strong>
					<p><?php esc_html_e( 'Le programme sera ensuite construit sans modifier les inscriptions, pesées ou résultats existants.', 'ufsc-licence-competition' ); ?></p>
				</div>
			<?php else : ?>
				<?php self::render_program_form( $competition ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_program_form( $competition ): void {
		$competition_id = absint( $competition->id ?? 0 );
		$event_type     = CompetitionFilters::normalize_type_key( (string) ( $competition->type ?? '' ) );
		$program        = EventProgramRegistry::get( $competition_id, $event_type );
		$blocks         = (array) ( $program['blocks'] ?? array() );
		$summary        = EventProgramRegistry::get_summary( $program );
		$mode_choices   = EventProgramRegistry::get_mode_choices();
		$allowed_modes  = EventFormatRegistry::get_supported_program_modes( $event_type );
		$disciplines    = DisciplineRegistry::get_disciplines();
		$event_label    = CompetitionFilters::get_type_label( $event_type );
		$event_label    = '' !== $event_label ? $event_label : __( 'Événement', 'ufsc-licence-competition' );
		$can_edit       = class_exists( '\\UFSC\\Competitions\\Capabilities' ) && \UFSC\Competitions\Capabilities::user_can_edit();

		?>
		<section class="ufsc-program-hero ufsc-admin-surface">
			<div>
				<span class="ufsc-premium-eyebrow"><?php echo esc_html( $event_label ); ?></span>
				<h2><?php echo esc_html( (string) ( $competition->name ?? '' ) ); ?></h2>
				<p><?php esc_html_e( 'Chaque bloc possède sa propre logique. Les combattants et catégories restent rattachés à une seule compétition.', 'ufsc-licence-competition' ); ?></p>
			</div>
			<div class="ufsc-program-summary-pills">
				<span class="ufsc-program-pill"><strong><?php echo esc_html( (string) ( $summary['active_blocks'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'bloc(s)', 'ufsc-licence-competition' ); ?></span>
				<span class="ufsc-program-pill"><strong><?php echo esc_html( (string) ( $summary['direct_blocks'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'carte', 'ufsc-licence-competition' ); ?></span>
				<span class="ufsc-program-pill"><strong><?php echo esc_html( (string) ( $summary['tournament_blocks'] ?? 0 ) ); ?></strong> <?php esc_html_e( 'tournoi(s)', 'ufsc-licence-competition' ); ?></span>
				<?php if ( ! empty( $summary['is_hybrid'] ) ) : ?>
					<span class="ufsc-program-pill is-accent"><?php esc_html_e( 'Programme hybride', 'ufsc-licence-competition' ); ?></span>
				<?php endif; ?>
			</div>
		</section>

		<?php if ( 'gala' === $event_type ) : ?>
			<div class="ufsc-premium-callout is-info">
				<strong><?php esc_html_e( 'Gala hybride autorisé', 'ufsc-licence-competition' ); ?></strong>
				<p><?php esc_html_e( 'Vous pouvez conserver une carte de combats directs et ajouter, par exemple, un mini-tournoi de 6 combattants en -75 kg pour une ceinture. Le tournoi devient seulement un bloc du gala.', 'ufsc-licence-competition' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="ufsc-program-form" data-ufsc-program-editor>
			<?php wp_nonce_field( 'ufsc_competitions_save_event_program_' . $competition_id ); ?>
			<input type="hidden" name="action" value="ufsc_competitions_save_event_program" />
			<input type="hidden" name="competition_id" value="<?php echo esc_attr( $competition_id ); ?>" />
			<input type="hidden" name="event_type" value="<?php echo esc_attr( $event_type ); ?>" />

			<div class="ufsc-program-toolbar ufsc-admin-surface">
				<div>
					<h2><?php esc_html_e( 'Blocs du programme', 'ufsc-licence-competition' ); ?></h2>
					<p><?php esc_html_e( 'Organisez les séquences dans l’ordre réel de l’événement. Les blocs peuvent être réordonnés sans toucher aux données sportives existantes.', 'ufsc-licence-competition' ); ?></p>
				</div>
				<?php if ( $can_edit ) : ?>
					<div class="ufsc-program-toolbar__actions">
						<?php if ( in_array( EventProgramRegistry::MODE_DIRECT_FIGHTS, $allowed_modes, true ) ) : ?>
							<button type="button" class="button" data-ufsc-add-program-block="direct_fights"><?php esc_html_e( '+ Combats directs', 'ufsc-licence-competition' ); ?></button>
						<?php endif; ?>
						<?php if ( in_array( EventProgramRegistry::MODE_TOURNAMENT, $allowed_modes, true ) ) : ?>
							<button type="button" class="button button-primary" data-ufsc-add-program-block="tournament"><?php esc_html_e( '+ Mini-tournoi', 'ufsc-licence-competition' ); ?></button>
						<?php endif; ?>
						<?php if ( in_array( EventProgramRegistry::MODE_POOL, $allowed_modes, true ) ) : ?>
							<button type="button" class="button" data-ufsc-add-program-block="pool"><?php esc_html_e( '+ Poule', 'ufsc-licence-competition' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>

			<div class="ufsc-program-blocks" data-ufsc-program-blocks>
				<?php foreach ( $blocks as $index => $block ) : ?>
					<?php self::render_block( (int) $index, $block, $mode_choices, $allowed_modes, $disciplines, $can_edit ); ?>
				<?php endforeach; ?>
			</div>

			<?php if ( $can_edit ) : ?>
				<div class="ufsc-program-savebar">
					<div>
						<strong><?php esc_html_e( 'Sauvegarde non destructive', 'ufsc-licence-competition' ); ?></strong>
						<span><?php esc_html_e( 'Seule la structure du programme est enregistrée.', 'ufsc-licence-competition' ); ?></span>
					</div>
					<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Enregistrer le programme', 'ufsc-licence-competition' ); ?></button>
				</div>
			<?php endif; ?>
		</form>

		<template id="ufsc-program-block-template">
			<?php self::render_block( 999, array(), $mode_choices, $allowed_modes, $disciplines, true, true ); ?>
		</template>
		<?php
	}

	private static function render_block( int $index, array $block, array $mode_choices, array $allowed_modes, array $disciplines, bool $can_edit, bool $is_template = false ): void {
		$mode               = sanitize_key( (string) ( $block['mode'] ?? EventProgramRegistry::MODE_MANUAL ) );
		$label              = (string) ( $block['label'] ?? '' );
		$discipline         = sanitize_key( (string) ( $block['discipline'] ?? '' ) );
		$category           = (string) ( $block['category'] ?? '' );
		$weight_class       = (string) ( $block['weight_class'] ?? '' );
		$participant_target = absint( $block['participant_target'] ?? 0 );
		$trophy             = (string) ( $block['trophy'] ?? '' );
		$notes              = (string) ( $block['notes'] ?? '' );
		$active             = ! isset( $block['active'] ) || ! empty( $block['active'] );
		$id                 = sanitize_key( (string) ( $block['id'] ?? '' ) );
		$disabled           = $can_edit ? '' : ' disabled';
		$row_index          = $is_template ? '__INDEX__' : (string) $index;
		$field_prefix       = 'program_blocks[' . $row_index . ']';
		$display_label      = '' !== $label ? $label : __( 'Nouveau bloc', 'ufsc-licence-competition' );
		$participant_value  = $participant_target > 0 ? (string) $participant_target : '';

		?>
		<article class="ufsc-program-block<?php echo $is_template ? ' is-template' : ''; ?>" data-ufsc-program-block>
			<header class="ufsc-program-block__header">
				<div class="ufsc-program-block__drag" aria-hidden="true">⋮⋮</div>
				<div class="ufsc-program-block__heading">
					<span class="ufsc-program-block__number" data-ufsc-program-number><?php echo esc_html( (string) ( $index + 1 ) ); ?></span>
					<div>
						<strong data-ufsc-program-title><?php echo esc_html( $display_label ); ?></strong>
						<span><?php echo esc_html( $mode_choices[ $mode ] ?? __( 'Bloc', 'ufsc-licence-competition' ) ); ?></span>
					</div>
				</div>
				<?php if ( $can_edit ) : ?>
					<div class="ufsc-program-block__actions">
						<button type="button" class="button-link" data-ufsc-move-program="up" aria-label="<?php esc_attr_e( 'Monter', 'ufsc-licence-competition' ); ?>">↑</button>
						<button type="button" class="button-link" data-ufsc-move-program="down" aria-label="<?php esc_attr_e( 'Descendre', 'ufsc-licence-competition' ); ?>">↓</button>
						<button type="button" class="button-link-delete" data-ufsc-remove-program><?php esc_html_e( 'Retirer', 'ufsc-licence-competition' ); ?></button>
					</div>
				<?php endif; ?>
			</header>

			<input type="hidden" name="<?php echo esc_attr( $field_prefix . '[id]' ); ?>" value="<?php echo esc_attr( $id ); ?>" data-ufsc-field="id" />
			<div class="ufsc-program-block__grid">
				<label class="ufsc-premium-field span-2">
					<span><?php esc_html_e( 'Nom du bloc', 'ufsc-licence-competition' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $field_prefix . '[label]' ); ?>" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Ex. Ceinture -75 kg', 'ufsc-licence-competition' ); ?>" data-ufsc-field="label"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</label>
				<label class="ufsc-premium-field">
					<span><?php esc_html_e( 'Format', 'ufsc-licence-competition' ); ?></span>
					<select name="<?php echo esc_attr( $field_prefix . '[mode]' ); ?>" data-ufsc-field="mode"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<?php foreach ( $mode_choices as $mode_key => $mode_label ) : ?>
							<?php if ( ! empty( $allowed_modes ) && ! in_array( $mode_key, $allowed_modes, true ) ) : ?>
								<?php continue; ?>
							<?php endif; ?>
							<option value="<?php echo esc_attr( $mode_key ); ?>" <?php selected( $mode, $mode_key ); ?>><?php echo esc_html( $mode_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="ufsc-premium-field">
					<span><?php esc_html_e( 'Discipline', 'ufsc-licence-competition' ); ?></span>
					<select name="<?php echo esc_attr( $field_prefix . '[discipline]' ); ?>" data-ufsc-field="discipline"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
						<option value=""><?php esc_html_e( 'Discipline de la compétition', 'ufsc-licence-competition' ); ?></option>
						<?php foreach ( $disciplines as $discipline_key => $discipline_label ) : ?>
							<option value="<?php echo esc_attr( $discipline_key ); ?>" <?php selected( $discipline, $discipline_key ); ?>><?php echo esc_html( $discipline_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label class="ufsc-premium-field">
					<span><?php esc_html_e( 'Catégorie', 'ufsc-licence-competition' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $field_prefix . '[category]' ); ?>" value="<?php echo esc_attr( $category ); ?>" placeholder="<?php esc_attr_e( 'Ex. Senior', 'ufsc-licence-competition' ); ?>" data-ufsc-field="category"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</label>
				<label class="ufsc-premium-field">
					<span><?php esc_html_e( 'Catégorie de poids', 'ufsc-licence-competition' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $field_prefix . '[weight_class]' ); ?>" value="<?php echo esc_attr( $weight_class ); ?>" placeholder="<?php esc_attr_e( 'Ex. -75 kg', 'ufsc-licence-competition' ); ?>" data-ufsc-field="weight_class"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</label>
				<label class="ufsc-premium-field">
					<span><?php esc_html_e( 'Combattants prévus', 'ufsc-licence-competition' ); ?></span>
					<input type="number" min="2" max="256" name="<?php echo esc_attr( $field_prefix . '[participant_target]' ); ?>" value="<?php echo esc_attr( $participant_value ); ?>" placeholder="6" data-ufsc-field="participant_target"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</label>
				<label class="ufsc-premium-field span-2">
					<span><?php esc_html_e( 'Titre / enjeu', 'ufsc-licence-competition' ); ?></span>
					<input type="text" name="<?php echo esc_attr( $field_prefix . '[trophy]' ); ?>" value="<?php echo esc_attr( $trophy ); ?>" placeholder="<?php esc_attr_e( 'Ex. Ceinture du gala', 'ufsc-licence-competition' ); ?>" data-ufsc-field="trophy"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				</label>
				<label class="ufsc-premium-field span-2">
					<span><?php esc_html_e( 'Notes organisation', 'ufsc-licence-competition' ); ?></span>
					<textarea name="<?php echo esc_attr( $field_prefix . '[notes]' ); ?>" rows="2" data-ufsc-field="notes"<?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php echo esc_textarea( $notes ); ?></textarea>
				</label>
			</div>
			<label class="ufsc-program-active-toggle">
				<input type="checkbox" name="<?php echo esc_attr( $field_prefix . '[active]' ); ?>" value="1" <?php checked( $active ); ?><?php echo $disabled; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
				<span><?php esc_html_e( 'Bloc actif dans le programme', 'ufsc-licence-competition' ); ?></span>
			</label>
		</article>
		<?php
	}

	public static function handle_save(): void {
		if ( ! class_exists( '\\UFSC\\Competitions\\Capabilities' ) || ! \UFSC\Competitions\Capabilities::user_can_edit() ) {
			wp_die( esc_html__( 'Accès refusé.', 'ufsc-licence-competition' ), '', array( 'response' => 403 ) );
		}

		$competition_id = isset( $_POST['competition_id'] ) ? absint( wp_unslash( $_POST['competition_id'] ) ) : 0;
		check_admin_referer( 'ufsc_competitions_save_event_program_' . $competition_id );

		$repository  = new CompetitionRepository();
		$competition = $competition_id ? $repository->get( $competition_id, true ) : null;
		if ( ! $competition ) {
			self::redirect( $competition_id, 'program_invalid' );
		}

		if ( function_exists( 'ufsc_lc_enforce_competition_access' ) ) {
			ufsc_lc_enforce_competition_access( $competition_id );
		}

		$event_type = CompetitionFilters::normalize_type_key( (string) ( $competition->type ?? '' ) );
		$blocks     = isset( $_POST['program_blocks'] ) && is_array( $_POST['program_blocks'] )
			? wp_unslash( $_POST['program_blocks'] )
			: array();
		$saved      = EventProgramRegistry::save( $competition_id, $event_type, $blocks );

		if ( $saved && class_exists( LogService::class ) ) {
			( new LogService() )->audit(
				'event_program_saved',
				$competition_id,
				'competition',
				$competition_id,
				array( 'blocks' => count( EventProgramRegistry::sanitize_blocks( $blocks, $event_type ) ) ),
				'Programme événement mis à jour.'
			);
		}

		self::redirect( $competition_id, $saved ? 'program_saved' : 'program_error' );
	}

	private static function render_notice( string $notice ): void {
		$messages = array(
			'program_saved'   => array( 'success', __( 'Programme enregistré.', 'ufsc-licence-competition' ) ),
			'program_error'   => array( 'error', __( 'Le programme n’a pas pu être enregistré.', 'ufsc-licence-competition' ) ),
			'program_invalid' => array( 'error', __( 'Compétition introuvable.', 'ufsc-licence-competition' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	private static function redirect( int $competition_id, string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => self::PAGE_SLUG,
					'competition_id' => absint( $competition_id ),
					'ufsc_notice'    => sanitize_key( $notice ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
