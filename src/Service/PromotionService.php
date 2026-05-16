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
use MP\CommercePromotions\Domain\PromotionStatus;
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

	public function change_status( Promotion $promotion, string $new_status, ?int $actor_user_id = null ): Promotion {
		if ( ! PromotionStatus::is_valid( $new_status ) ) {
			throw new RuntimeException( 'Invalid promotion status.' );
		}

		$old_status = $promotion->get_status();
		if ( ! self::is_allowed_status_transition( $old_status, $new_status ) ) {
			throw new RuntimeException( 'Promotion status transition is not allowed.' );
		}

		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			throw new RuntimeException( 'Promotion id is required for status change.' );
		}

		$updated_model = $promotion->with_status( $new_status );
		$ok            = $this->promotions->update( $updated_model );
		if ( ! $ok ) {
			throw new RuntimeException( 'Failed to update promotion status.' );
		}

		$reloaded = $this->promotions->find( $id );
		if ( $reloaded === null ) {
			throw new RuntimeException( 'Promotion was not found after status change.' );
		}

		$this->audit->log(
			'promotion.status_changed',
			$reloaded->get_id(),
			array(
				'id'         => $reloaded->get_id(),
				'uuid'       => $reloaded->get_uuid(),
				'old_status' => $old_status,
				'new_status' => $new_status,
				'name'       => $reloaded->get_name(),
			),
			$actor_user_id
		);

		return $reloaded;
	}

	/**
	 * Allowed transitions only (archived is terminal).
	 */
	private static function is_allowed_status_transition( string $from, string $to ): bool {
		if ( $from === $to ) {
			return false;
		}

		$key = $from . ':' . $to;

		static $allowed = array(
			PromotionStatus::DRAFT . ':' . PromotionStatus::ACTIVE    => true,
			PromotionStatus::DRAFT . ':' . PromotionStatus::ARCHIVED => true,
			PromotionStatus::ACTIVE . ':' . PromotionStatus::PAUSED  => true,
			PromotionStatus::ACTIVE . ':' . PromotionStatus::ARCHIVED => true,
			PromotionStatus::PAUSED . ':' . PromotionStatus::ACTIVE    => true,
			PromotionStatus::PAUSED . ':' . PromotionStatus::ARCHIVED => true,
		);

		return isset( $allowed[ $key ] );
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

	public function duplicate_as_draft( Promotion $source, ?int $actor_user_id = null ): Promotion {
		$source_id = $source->get_id();
		if ( $source_id === null || $source_id <= 0 ) {
			throw new RuntimeException( 'Source promotion id is required for duplication.' );
		}

		$name = sprintf(
			/* translators: %s: original promotion name */
			__( 'Copy of %s', 'mp-commerce-promotions' ),
			$source->get_name()
		);
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $name ) > 191 ) {
			$name = mb_substr( $name, 0, 191 );
		} elseif ( strlen( $name ) > 191 ) {
			$name = substr( $name, 0, 191 );
		}

		$draft = $this->factory->create_draft_from_source( $source, $name, $actor_user_id );

		$new_id = $this->promotions->insert( $draft );
		if ( $new_id <= 0 ) {
			throw new RuntimeException( 'Failed to insert duplicated promotion.' );
		}

		$saved = $this->promotions->find( $new_id );
		if ( $saved === null ) {
			throw new RuntimeException( 'Duplicated promotion was not found after insert.' );
		}

		$this->audit->log(
			'promotion.duplicated',
			$saved->get_id(),
			array(
				'source_promotion_id' => $source_id,
				'new_promotion_id'    => $saved->get_id(),
				'name'                => $saved->get_name(),
				'uuid'                => $saved->get_uuid(),
			),
			$actor_user_id
		);

		return $saved;
	}
}
