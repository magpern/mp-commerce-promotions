<?php
/**
 * Emergency storefront operations with dry-run preview.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\AllocationContextCache;
use MP\CommercePromotions\Engine\PlannerContextCache;
use MP\CommercePromotions\Service\AuditLogger;

final class EmergencyOperationsService {

	private Settings $settings;

	private PromotionRepository $promotions;

	private ?PromotionPerformanceProfiler $profiler;

	private ?PromotionIntelligenceRecovery $intelligence_recovery;

	private ?AuditLogger $audit;

	public function __construct(
		Settings $settings,
		PromotionRepository $promotions,
		?PromotionPerformanceProfiler $profiler = null,
		?PromotionIntelligenceRecovery $intelligence_recovery = null,
		?AuditLogger $audit = null
	) {
		$this->settings              = $settings;
		$this->promotions            = $promotions;
		$this->profiler              = $profiler;
		$this->intelligence_recovery = $intelligence_recovery;
		$this->audit                 = $audit;
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function disable_automatic_promotions( bool $dry_run = true ): array {
		$summary = array(
			'safe_mode_before' => $this->settings->safe_mode_enabled(),
			'safe_mode_after'  => true,
		);
		if ( ! $dry_run ) {
			$this->settings->set_safe_mode_enabled( true );
			$this->log( 'emergency.disable_automatic_promotions', $summary );
		}

		return array(
			'dry_run' => $dry_run,
			'action'  => 'disable_automatic_promotions',
			'summary' => $summary,
		);
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function disable_line_item_mode_globally( bool $dry_run = true ): array {
		$summary = array(
			'line_mode_disabled_before' => $this->settings->line_item_mode_disabled(),
			'line_mode_disabled_after'  => true,
		);
		if ( ! $dry_run ) {
			$this->settings->set_line_item_mode_disabled( true );
			$this->log( 'emergency.disable_line_item_mode', $summary );
		}

		return array(
			'dry_run' => $dry_run,
			'action'  => 'disable_line_item_mode_globally',
			'summary' => $summary,
		);
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function pause_stackable_promotions( bool $dry_run = true ): array {
		$active = $this->promotions->find_filtered(
			array(
				'status' => PromotionStatus::ACTIVE,
				'limit'  => 500,
			)
		);
		$paused_ids = array();
		foreach ( $active as $promotion ) {
			if ( $promotion->get_application_mode() !== PromotionApplicationMode::STACKABLE ) {
				continue;
			}
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$paused_ids[] = $id;
			if ( ! $dry_run ) {
				$this->promotions->update( $promotion->with_status( PromotionStatus::PAUSED ) );
			}
		}

		$summary = array(
			'paused_count' => count( $paused_ids ),
			'promotion_ids' => $paused_ids,
		);
		if ( ! $dry_run ) {
			$this->log( 'emergency.pause_stackable', $summary );
		}

		return array(
			'dry_run' => $dry_run,
			'action'  => 'pause_stackable_promotions',
			'summary' => $summary,
		);
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function rebuild_promotion_caches( bool $dry_run = true ): array {
		$summary = array(
			'planner_cache_cleared'    => true,
			'allocation_cache_cleared' => true,
			'repository_cache_cleared' => true,
		);
		if ( ! $dry_run ) {
			PromotionRepository::clear_request_cache();
			PlannerContextCache::reset_request_cache();
			AllocationContextCache::reset_request_cache();
			$this->log( 'emergency.rebuild_caches', $summary );
		}

		return array(
			'dry_run' => $dry_run,
			'action'  => 'rebuild_promotion_caches',
			'summary' => $summary,
		);
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function clear_planner_telemetry( bool $dry_run = true ): array {
		$deleted = 0;
		if ( $this->intelligence_recovery !== null ) {
			$result  = $this->intelligence_recovery->reset_telemetry( $dry_run );
			$deleted = (int) ( $result['deleted_rows'] ?? 0 );
		}
		$summary = array( 'rows_deleted' => $deleted );
		if ( ! $dry_run ) {
			$this->log( 'emergency.clear_planner_telemetry', $summary );
		}

		return array(
			'dry_run' => $dry_run,
			'action'  => 'clear_planner_telemetry',
			'summary' => $summary,
		);
	}

	/**
	 * @return array{dry_run: bool, action: string, summary: array<string, mixed>}
	 */
	public function reset_degraded_mode( bool $dry_run = true ): array {
		$was = $this->profiler !== null ? $this->profiler->is_storefront_degraded() : false;
		$summary = array( 'was_degraded' => $was );
		if ( ! $dry_run && $this->profiler !== null ) {
			$this->profiler->clear_degraded_state();
			$this->log( 'emergency.reset_degraded_mode', $summary );
		}

		return array(
			'dry_run' => $dry_run,
			'action'  => 'reset_degraded_mode',
			'summary' => $summary,
		);
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	private function log( string $event, array $summary ): void {
		if ( $this->audit === null ) {
			return;
		}

		$this->audit->log( $event, null, $summary, get_current_user_id() );
	}
}
