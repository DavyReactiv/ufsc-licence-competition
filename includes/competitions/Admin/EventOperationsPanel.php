<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Services\CompetitionStatsService;
use UFSC\Competitions\Services\FighterNumberAuditService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds a compact operational-readiness panel to the competition dashboard. */
class EventOperationsPanel {
	private const OFFICIALS_OPTION = 'ufsc_competitions_officials';
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 30 );
	}

	public static function render(): void {
		if ( ! is_admin() || ! \UFSC\Competitions\Capabilities::user_can_read() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only dashboard context.
		if ( Menu::MENU_SLUG !== $page ) {
			return;
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only dashboard context.
		if ( $competition_id <= 0 ) {
			return;
		}

		$number_audit = ( new FighterNumberAuditService() )->audit( $competition_id );
		$stats        = ( new CompetitionStatsService() )->get_competition_stats( $competition_id );
		$officials    = self::get_official_counts( $competition_id );
		$fights       = is_array( $stats['fights'] ?? null ) ? $stats['fights'] : array();
		$weighins     = is_array( $stats['weighins'] ?? null ) ? $stats['weighins'] : array();

		$issues = array();
		if ( (int) $number_audit['eligible_missing_count'] > 0 ) {
			$issues[] = sprintf(
				/* translators: %d: fighters ready after weigh-in but missing a number. */
				__( '%d combattant(s) prêt(s) après pesée n’ont pas de numéro.', 'ufsc-licence-competition' ),
				(int) $number_audit['eligible_missing_count']
			);
		}
		if ( (int) $number_audit['duplicate_count'] > 0 ) {
			$issues[] = sprintf(
				/* translators: %d: duplicate fighter numbers. */
				__( '%d conflit(s) de numéro combattant détecté(s).', 'ufsc-licence-competition' ),
				(int) $number_audit['duplicate_count']
			);
		}
		if ( (int) $number_audit['premature_count'] > 0 ) {
			$issues[] = sprintf(
				/* translators: %d: fighter numbers assigned before final readiness. */
				__( '%d numéro(s) ont été attribué(s) avant que le dossier/pesée soit prêt.', 'ufsc-licence-competition' ),
				(int) $number_audit['premature_count']
			);
		}
		if ( (int) ( $fights['generated'] ?? 0 ) > 0 && 0 === (int) ( $fights['surfaces'] ?? 0 ) ) {
			$issues[] = __( 'Des combats sont générés mais aucune surface active n’est détectée.', 'ufsc-licence-competition' );
		}
		if ( (int) ( $fights['generated'] ?? 0 ) > 0 && 0 === (int) $officials['active'] ) {
			$issues[] = __( 'Aucun officiel actif n’est encore affecté à cet événement.', 'ufsc-licence-competition' );
		}

		$weighin_url = add_query_arg(
			array(
				'page'           => Menu::PAGE_WEIGHINS,
				'competition_id' => $competition_id,
			),
			admin_url( 'admin.php' )
		);
		$officials_url = add_query_arg(
			array(
				'page'           => Menu::PAGE_OFFICIALS,
				'competition_id' => $competition_id,
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="notice notice-info ufsc-event-operations-panel">
			<p><strong><?php esc_html_e( 'Préparation opérationnelle', 'ufsc-licence-competition' ); ?></strong></p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: assigned fighter numbers, 2: validated weigh-ins, 3: active officials, 4: surfaces. */
						__( 'Numéros combattants : %1$d attribué(s) · Pesées validées : %2$d · Officiels actifs : %3$d · Surfaces : %4$d', 'ufsc-licence-competition' ),
						(int) $number_audit['assigned'],
						(int) ( $weighins['validated'] ?? 0 ),
						(int) $officials['active'],
						(int) ( $fights['surfaces'] ?? 0 )
					)
				);
				?>
			</p>
			<?php if ( $issues ) : ?>
				<ul class="ul-disc">
					<?php foreach ( $issues as $issue ) : ?>
						<li><?php echo esc_html( $issue ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p><?php esc_html_e( 'Aucune anomalie opérationnelle détectée sur les numéros combattants, surfaces et officiels.', 'ufsc-licence-competition' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( $weighin_url ); ?>"><?php esc_html_e( 'Contrôler les pesées et numéros', 'ufsc-licence-competition' ); ?></a>
				<a class="button" href="<?php echo esc_url( $officials_url ); ?>"><?php esc_html_e( 'Gérer les officiels', 'ufsc-licence-competition' ); ?></a>
			</p>
		</div>
		<?php
	}

	private static function get_official_counts( int $competition_id ): array {
		$all   = get_option( self::OFFICIALS_OPTION, array() );
		$items = is_array( $all ) && isset( $all[ $competition_id ] ) && is_array( $all[ $competition_id ] ) ? $all[ $competition_id ] : array();
		$active = 0;
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item['active'] ) ) {
				$active++;
			}
		}

		return array(
			'total'  => count( $items ),
			'active' => $active,
		);
	}
}
