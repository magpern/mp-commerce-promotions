<?php
/**
 * Ecosystem compatibility matrix for Diagnostics / Reports.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Service\EcosystemCompatibilityRegistry;
use MP\CommercePromotions\Service\MerchantSafetyAdvisor;
use MP\CommercePromotions\Service\PromotionComplexityScorer;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\ScheduleConflictPreviewService;
use MP\CommercePromotions\Service\SystemHealthService;

final class EcosystemCompatibilityPanel {

	public static function render_ecosystem_matrix( ?EcosystemCompatibilityRegistry $registry = null ): void {
		$registry = $registry ?? new EcosystemCompatibilityRegistry();
		$summary  = $registry->summarize( true );

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Ecosystem compatibility', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Detection-only audit for common WooCommerce extensions. No deep integration is performed in this phase.',
			'mp-commerce-promotions'
		) . '</p>';

		printf(
			'<p><strong>%1$s:</strong> %2$d &nbsp; <strong>%3$s:</strong> %4$s &nbsp; <strong>%5$s:</strong> %6$d</p>',
			esc_html__( 'Ecosystem score', 'mp-commerce-promotions' ),
			(int) ( $summary['score'] ?? 0 ),
			esc_html__( 'Confidence', 'mp-commerce-promotions' ),
			esc_html( (string) ( $summary['confidence'] ?? '' ) ),
			esc_html__( 'Detected integrations', 'mp-commerce-promotions' ),
			(int) ( $summary['detected_count'] ?? 0 )
		);

		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Integration', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Detected', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Confidence', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Notes', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $summary['matrix'] as $row ) {
			$limitation = is_array( $row['limitation'] ?? null ) ? $row['limitation'] : array();
			$note       = (string) ( $row['message'] ?? '' );
			if ( ! empty( $limitation['summary'] ) ) {
				$note .= ' — ' . (string) $limitation['summary'];
			}
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) ( $row['label'] ?? '' ) ),
				! empty( $row['detected'] ) ? esc_html__( 'Yes', 'mp-commerce-promotions' ) : esc_html__( 'No', 'mp-commerce-promotions' ),
				esc_html( (string) ( $row['status'] ?? '' ) ),
				esc_html( (string) ( $row['confidence'] ?? '' ) ),
				esc_html( $note )
			);
		}

		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'See docs/COMPATIBILITY_MATRIX.md and docs/KNOWN_LIMITATIONS.md for full registry.', 'mp-commerce-promotions' ) . '</p>';
	}

	public static function render_system_health(
		Settings $settings,
		PromotionPerformanceProfiler $profiler,
		PromotionConcurrencyGuard $concurrency,
		?PromotionRepository $promotions = null,
		?PromotionHealthMonitor $health_monitor = null
	): void {
		$health = new SystemHealthService(
			$settings,
			$profiler,
			$concurrency,
			null,
			$health_monitor,
			$promotions
		);
		$data = $health->collect( true );

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'System health', 'mp-commerce-promotions' ) . '</h2>';
		printf(
			'<p><strong>%1$s:</strong> %2$d (%3$s)</p>',
			esc_html__( 'Health score', 'mp-commerce-promotions' ),
			(int) ( $data['score'] ?? 0 ),
			esc_html( (string) ( $data['label'] ?? '' ) )
		);

		if ( ! empty( $data['recommendations'] ) && is_array( $data['recommendations'] ) ) {
			echo '<h3>' . esc_html__( 'Recovery recommendations', 'mp-commerce-promotions' ) . '</h3><ul style="list-style:disc;margin-left:1.5em;">';
			foreach ( $data['recommendations'] as $rec ) {
				echo '<li>' . esc_html( (string) $rec ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public static function render_merchant_safety( PromotionRepository $promotions ): void {
		$advisor = new MerchantSafetyAdvisor( $promotions );
		$issues  = $advisor->analyze_catalog( 150 );

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Merchant safety', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Heuristic warnings for over-discounting, budgets, and stackable risk. Enable Promotion dry-run in Performance & hardening to preview without applying cart fees.',
			'mp-commerce-promotions'
		) . '</p>';

		if ( $issues === array() ) {
			echo '<p>' . esc_html__( 'No high-risk promotion patterns detected in the current catalog sample.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Severity', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Promotion', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $issues, 0, 25 ) as $issue ) {
			printf(
				'<tr><td>%1$s</td><td>%2$d</td><td>%3$s</td></tr>',
				esc_html( (string) ( $issue['severity'] ?? '' ) ),
				(int) ( $issue['promotion_id'] ?? 0 ),
				esc_html( (string) ( $issue['message'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	public static function render_complexity( PromotionRepository $promotions ): void {
		$scorer = new PromotionComplexityScorer( $promotions );
		$rows   = $scorer->slow_promotion_candidates( 40, 10 );

		echo '<h3 style="margin-top:1em;">' . esc_html__( 'High-complexity active promotions', 'mp-commerce-promotions' ) . '</h3>';
		if ( $rows === array() ) {
			echo '<p>' . esc_html__( 'No active promotions exceed the complexity threshold.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:720px;"><thead><tr>';
		echo '<th>ID</th><th>' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Score', 'mp-commerce-promotions' ) . '</th><th>' . esc_html__( 'Tier', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$d</td><td>%4$s</td></tr>',
				(int) ( $row['promotion_id'] ?? 0 ),
				esc_html( (string) ( $row['name'] ?? '' ) ),
				(int) ( $row['score'] ?? 0 ),
				esc_html( (string) ( $row['tier'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	public static function render_schedule_conflict_preview( PromotionRepository $repo, int $limit = 15 ): void {
		$catalog = $repo->find_filtered( array( 'limit' => 200 ) );
		$rows    = ( new ScheduleConflictPreviewService() )->preview_site_summary( $catalog, $limit );

		echo '<h2 style="margin-top:1.5em;">' . esc_html__( 'Schedule conflict preview', 'mp-commerce-promotions' ) . '</h2>';
		if ( $rows === array() ) {
			echo '<p>' . esc_html__( 'No schedule or orchestration conflicts detected among draft, paused, or active promotions.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:960px;"><thead><tr>';
		echo '<th>' . esc_html__( 'Source', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Severity', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Type', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Promotions', 'mp-commerce-promotions' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$ids = isset( $row['promotion_ids'] ) && is_array( $row['promotion_ids'] )
				? implode( ', ', array_map( 'strval', $row['promotion_ids'] ) )
				: '';
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td><td>%5$s</td></tr>',
				esc_html( (string) ( $row['source'] ?? '' ) ),
				esc_html( (string) ( $row['severity'] ?? '' ) ),
				esc_html( (string) ( $row['type'] ?? '' ) ),
				esc_html( $ids ),
				esc_html( (string) ( $row['message'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}
}
