<?php

namespace UFSC\Competitions\Admin;

use UFSC\Competitions\Capabilities;
use UFSC\Competitions\Services\CompetitionIntegrityService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Adds a read-only data-integrity summary to the existing Quality page. */
class IntegrityHealthNotice {
	private static $registered = false;

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;
		add_action( 'admin_notices', array( __CLASS__, 'render' ), 25 );
	}

	public static function render(): void {
		if ( ! is_admin() || ! Capabilities::user_can_manage() ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( Menu::PAGE_QUALITY !== $page ) {
			return;
		}

		$competition_id = isset( $_GET['competition_id'] ) ? absint( $_GET['competition_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report = ( new CompetitionIntegrityService() )->audit( $competition_id );
		$total  = absint( $report['total_issues'] ?? 0 );
		$class  = 0 === $total ? 'notice-success' : 'notice-warning';
		?>
		<div class="notice <?php echo esc_attr( $class ); ?>">
			<p>
				<strong><?php esc_html_e( 'Intégrité des données compétition', 'ufsc-licence-competition' ); ?> :</strong>
				<?php if ( 0 === $total ) : ?>
					<?php esc_html_e( 'aucune incohérence relationnelle détectée.', 'ufsc-licence-competition' ); ?>
				<?php else : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of integrity issues */
							_n( '%d anomalie détectée.', '%d anomalies détectées.', $total, 'ufsc-licence-competition' ),
							$total
						)
					);
					?>
				<?php endif; ?>
			</p>
			<?php if ( $total > 0 && ! empty( $report['issues'] ) ) : ?>
				<ul style="margin-left:18px;list-style:disc;">
					<?php foreach ( (array) $report['issues'] as $key => $count ) : ?>
						<?php
						if ( (int) $count <= 0 ) {
							continue;
						}
						?>
						<li><?php echo esc_html( self::label( (string) $key ) . ' : ' . (int) $count ); ?></li>
					<?php endforeach; ?>
				</ul>
				<p class="description"><?php esc_html_e( 'Ce contrôle est en lecture seule : aucune donnée n’est corrigée ou supprimée automatiquement.', 'ufsc-licence-competition' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function label( string $key ): string {
		$labels = array(
			'entries_without_competition'    => __( 'Inscriptions sans événement', 'ufsc-licence-competition' ),
			'categories_without_competition' => __( 'Catégories sans événement', 'ufsc-licence-competition' ),
			'weighins_without_entry'          => __( 'Pesées sans inscription', 'ufsc-licence-competition' ),
			'fights_without_competition'      => __( 'Combats sans événement', 'ufsc-licence-competition' ),
			'fights_with_missing_red_entry'   => __( 'Coins rouges liés à une inscription absente', 'ufsc-licence-competition' ),
			'fights_with_missing_blue_entry'  => __( 'Coins bleus liés à une inscription absente', 'ufsc-licence-competition' ),
			'invalid_fight_winner'            => __( 'Vainqueurs incohérents', 'ufsc-licence-competition' ),
			'duplicate_active_entries'        => __( 'Licenciés inscrits plusieurs fois au même événement', 'ufsc-licence-competition' ),
		);
		return $labels[ $key ] ?? $key;
	}
}
