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
use MP\CommercePromotions\Service\PromotionLifecycle;
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

	public function capture(
		Promotion $promotion,
		string $snapshot_type,
		?string $notes = null,
		?int $actor_user_id = null,
		?string $snapshot_label = null,
		?string $snapshot_source = null
	): int {
		$promotion_id = $promotion->get_id();
		if ( $promotion_id === null || $promotion_id <= 0 ) {
			return 0;
		}

		$snapshot = new PromotionSnapshot(
			null,
			$promotion_id,
			$snapshot_type,
			$promotion->to_array(),
			self::merge_intelligence_notes( $notes, $promotion, $snapshot_label ),
			$actor_user_id,
			null,
			$snapshot_label ?? PromotionLifecycle::badge_label( PromotionLifecycle::primary_phase( $promotion ) ),
			$snapshot_source
		);

		return $this->snapshots->insert( $snapshot );
	}

	/**
	 * @return true|string
	 */
	public function validate_snapshot_payload( PromotionSnapshot $snapshot ) {
		$data = $snapshot->get_snapshot_data();
		if ( $data === array() ) {
			return 'empty_payload';
		}

		try {
			Promotion::from_array( $data );
		} catch ( \InvalidArgumentException $e ) {
			return 'invalid_promotion_payload';
		}

		foreach ( array( 'conditions', 'actions', 'restrictions' ) as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				continue;
			}
			if ( ! is_array( $data[ $key ] ) ) {
				return 'invalid_json_' . $key;
			}
		}

		return true;
	}

	public function restore( int $snapshot_id, ?int $actor_user_id = null ): Promotion {
		$snapshot = $this->snapshots->find( $snapshot_id );
		if ( $snapshot === null ) {
			throw new RuntimeException( 'Snapshot not found.' );
		}

		$validation = $this->validate_snapshot_payload( $snapshot );
		if ( $validation !== true ) {
			throw new RuntimeException( 'Snapshot payload is invalid or corrupted.' );
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

	/**
	 * @return array<string, mixed>
	 */
	public static function parse_intelligence_metadata( ?string $notes ): array {
		if ( $notes === null || trim( $notes ) === '' ) {
			return array();
		}

		$decoded = json_decode( $notes, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['mp_cp_intel'] ) || ! is_array( $decoded['mp_cp_intel'] ) ) {
			return array();
		}

		return $decoded['mp_cp_intel'];
	}

	public static function merge_intelligence_notes(
		?string $notes,
		Promotion $promotion,
		?string $simulation_label = null
	): ?string {
		$meta = array(
			'lifecycle_phase' => PromotionLifecycle::primary_phase( $promotion ),
			'campaign_label'  => $promotion->get_campaign_label(),
			'orchestration'   => $promotion->get_orchestration_group(),
			'simulation_label' => $simulation_label,
		);

		$decoded = array();
		if ( $notes !== null && trim( $notes ) !== '' ) {
			$parsed = json_decode( $notes, true );
			if ( is_array( $parsed ) ) {
				$decoded = $parsed;
			} else {
				$decoded['merchant_note'] = $notes;
			}
		}

		$decoded['mp_cp_intel'] = array_merge( (array) ( $decoded['mp_cp_intel'] ?? array() ), $meta );

		return wp_json_encode( $decoded ) ?: null;
	}
}
