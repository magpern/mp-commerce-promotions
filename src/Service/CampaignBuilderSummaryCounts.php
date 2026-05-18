<?php
/**
 * Lightweight dashboard counts for Campaign Builder.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;

final class CampaignBuilderSummaryCounts {

	private PromotionRepository $promotions;

	private ?PromotionHealthMonitor $health;

	public function __construct(
		PromotionRepository $promotions,
		?PromotionHealthMonitor $health = null
	) {
		$this->promotions = $promotions;
		$this->health     = $health;
	}

	/**
	 * @return array{
	 *     active: int,
	 *     scheduled: int,
	 *     drafts: int,
	 *     needs_attention: int,
	 *     budget_exhausted: int
	 * }
	 */
	public function counts(): array {
		$active = $this->promotions->count_filtered(
			array( 'status' => PromotionStatus::ACTIVE )
		);

		$scheduled = 0;
		try {
			$scheduled = $this->promotions->count_filtered(
				array( 'lifecycle_phase' => PromotionLifecycle::PHASE_UPCOMING )
			);
		} catch ( \InvalidArgumentException $e ) {
			$scheduled = 0;
		}

		$drafts = $this->promotions->count_filtered(
			array( 'status' => PromotionStatus::DRAFT )
		);

		$budget_exhausted = $this->promotions->count_budget_exhausted_active();

		$needs_attention = 0;
		if ( $this->health !== null ) {
			$ids = array();
			foreach ( $this->health->analyze( 100 ) as $issue ) {
				if ( ! isset( $issue['promotion_ids'] ) || ! is_array( $issue['promotion_ids'] ) ) {
					continue;
				}
				foreach ( $issue['promotion_ids'] as $id ) {
					$id = (int) $id;
					if ( $id > 0 ) {
						$ids[ $id ] = true;
					}
				}
			}
			$needs_attention = count( $ids );
		}

		return array(
			'active'            => $active,
			'scheduled'         => $scheduled,
			'drafts'            => $drafts,
			'needs_attention'   => $needs_attention,
			'budget_exhausted'  => $budget_exhausted,
		);
	}
}
