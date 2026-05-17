<?php
/**
 * Create and restore promotion rollback snapshots.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionSnapshot;
use MP\CommercePromotions\Domain\PromotionSnapshotRepository;
use RuntimeException;

final class PromotionSnapshotService {

	public const TYPE_BEFORE_TEMPLATE = PromotionSnapshot::TYPE_TEMPLATE_APPLY;

	public const TYPE_BEFORE_BUILDER = PromotionSnapshot::TYPE_BUILDER_APPLY;

	public const TYPE_BEFORE_DUPLICATE = PromotionSnapshot::TYPE_DUPLICATION;

	private PromotionRepository $promotions;

	private PromotionSnapshotRepository $snapshots;

	private AuditLogger $audit;

	public function __construct(
		PromotionRepository $promotions,
		PromotionSnapshotRepository $snapshots,
		AuditLogger $audit
	) {
		$this->promotions = $promotions;
		$this->snapshots  = $snapshots;
		$this->audit      = $audit;
	}

	public function capture( Promotion $promotion, string $snapshot_type, ?string $notes = null, ?int $actor_user_id = null ): int {
		$promotion_id = $promotion->get_id();
		if ( $promotion_id === null || $promotion_id <= 0 ) {
			return 0;
		}

		$snapshot = new PromotionSnapshot(
			null,
			$promotion_id,
			$snapshot_type,
			$promotion->to_array(),
			$notes,
			$actor_user_id,
			null
		);

		return $this->snapshots->insert( $snapshot );
	}

	public function restore( int $snapshot_id, ?int $actor_user_id = null ): Promotion {
		$snapshot = $this->snapshots->find( $snapshot_id );
		if ( $snapshot === null ) {
			throw new RuntimeException( 'Snapshot not found.' );
		}

		$promotion_id = $snapshot->get_promotion_id();
		$current      = $this->promotions->find( $promotion_id );
		if ( $current === null ) {
			throw new RuntimeException( 'Promotion not found for snapshot.' );
		}

		$data = $snapshot->get_snapshot_data();
		$data['id'] = $promotion_id;

		$restored = Promotion::from_array( $data );
		if ( ! $this->promotions->update( $restored ) ) {
			throw new RuntimeException( 'Failed to restore promotion from snapshot.' );
		}

		$saved = $this->promotions->find( $promotion_id );
		if ( $saved === null ) {
			throw new RuntimeException( 'Promotion missing after snapshot restore.' );
		}

		$this->audit->log(
			'promotion.snapshot_restored',
			$promotion_id,
			array(
				'snapshot_id'   => $snapshot_id,
				'snapshot_type' => $snapshot->get_snapshot_type(),
			),
			$actor_user_id
		);

		return $saved;
	}

	/**
	 * @return list<PromotionSnapshot>
	 */
	public function list_recent( int $promotion_id, int $limit = 10 ): array {
		return $this->snapshots->find_latest_for_promotion( $promotion_id, $limit );
	}
}
