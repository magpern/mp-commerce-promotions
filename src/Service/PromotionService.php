<?php
/**
 * Orchestrates promotion creation with persistence and audit.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Domain\PromotionRepository;
use RuntimeException;

final class PromotionService {

	private PromotionRepository $promotions;

	private PromotionFactory $factory;

	private AuditLogger $audit;

	public function __construct(
		PromotionRepository $promotions,
		PromotionFactory $factory,
		AuditLogger $audit
	) {
		$this->promotions = $promotions;
		$this->factory     = $factory;
		$this->audit       = $audit;
	}

	public function create_draft( string $name, ?int $created_by = null ): Promotion {
		$draft = $this->factory->create_draft( $name, $created_by );

		$new_id = $this->promotions->insert( $draft );
		if ( $new_id <= 0 ) {
			throw new RuntimeException( 'Failed to insert promotion.' );
		}

		$saved = $this->promotions->find( $new_id );
		if ( $saved === null ) {
			throw new RuntimeException( 'Promotion was not found after insert.' );
		}

		$this->audit->log(
			'promotion.created',
			$saved->get_id(),
			array(
				'name'   => $saved->get_name(),
				'status' => $saved->get_status(),
				'uuid'   => $saved->get_uuid(),
			),
			$created_by
		);

		return $saved;
	}

	public function update_promotion( Promotion $promotion, ?int $actor_user_id = null ): Promotion {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			throw new RuntimeException( 'Promotion id is required for update.' );
		}

		$ok = $this->promotions->update( $promotion );
		if ( ! $ok ) {
			throw new RuntimeException( 'Failed to update promotion.' );
		}

		$reloaded = $this->promotions->find( $id );
		if ( $reloaded === null ) {
			throw new RuntimeException( 'Promotion was not found after update.' );
		}

		$this->audit->log(
			'promotion.updated',
			$reloaded->get_id(),
			array(
				'id'     => $reloaded->get_id(),
				'uuid'   => $reloaded->get_uuid(),
				'name'   => $reloaded->get_name(),
				'status' => $reloaded->get_status(),
			),
			$actor_user_id
		);

		return $reloaded;
	}
}
