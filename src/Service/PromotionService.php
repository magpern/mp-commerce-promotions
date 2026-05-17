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
	public static function is_allowed_status_transition( string $from, string $to ): bool {
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

	/**
	 * Archive active promotions whose ends_at is in the past.
	 *
	 * @return array{changed: list<array{id: int, name: string}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	public function archive_expired_active_promotions( ?int $actor_user_id = null ): array {
		$result = array(
			'changed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$candidates = $this->promotions->find_expired_active( 500 );
		foreach ( $candidates as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'not_active',
				);
				continue;
			}

			if ( ! self::is_allowed_status_transition( PromotionStatus::ACTIVE, PromotionStatus::ARCHIVED ) ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'transition_not_allowed',
				);
				continue;
			}

			try {
				$this->change_status( $promotion, PromotionStatus::ARCHIVED, $actor_user_id );
				$result['changed'][] = array(
					'id'   => $id,
					'name' => $promotion->get_name(),
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = array(
					'id'      => $id,
					'message' => $e->getMessage(),
				);
			}
		}

		return $result;
	}

	/**
	 * Archive draft promotions older than N days (by created_at).
	 *
	 * @return array{changed: list<array{id: int, name: string}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	public function archive_old_drafts( int $days, ?int $actor_user_id = null ): array {
		$result = array(
			'changed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$candidates = $this->promotions->find_old_drafts( $days, 500 );
		foreach ( $candidates as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			if ( $promotion->get_status() !== PromotionStatus::DRAFT ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'not_draft',
				);
				continue;
			}

			try {
				$this->change_status( $promotion, PromotionStatus::ARCHIVED, $actor_user_id );
				$result['changed'][] = array(
					'id'   => $id,
					'name' => $promotion->get_name(),
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = array(
					'id'      => $id,
					'message' => $e->getMessage(),
				);
			}
		}

		return $result;
	}

	/**
	 * Pause active promotions whose promotion budget is exhausted.
	 *
	 * @return array{changed: list<array{id: int, name: string}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	public function pause_budget_exhausted_promotions( ?int $actor_user_id = null ): array {
		$result = array(
			'changed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		$candidates = $this->promotions->find_budget_exhausted_active( 500 );
		foreach ( $candidates as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			if ( ! $promotion->is_budget_exhausted() ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'not_exhausted',
				);
				continue;
			}

			if ( $promotion->get_status() !== PromotionStatus::ACTIVE ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'not_active',
				);
				continue;
			}

			try {
				$this->change_status( $promotion, PromotionStatus::PAUSED, $actor_user_id );
				$this->audit->log(
					'promotion.auto_paused_budget_exhausted',
					$id,
					array(
						'promotion_id'  => $id,
						'budget_amount' => $promotion->get_budget_amount(),
						'budget_spent'  => $promotion->get_budget_spent(),
					),
					$actor_user_id
				);
				$result['changed'][] = array(
					'id'   => $id,
					'name' => $promotion->get_name(),
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = array(
					'id'      => $id,
					'message' => $e->getMessage(),
				);
			}
		}

		return $result;
	}

	/**
	 * Activate draft promotions whose schedule has started.
	 *
	 * @return array{changed: list<array{id: int, name: string}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	public function activate_scheduled_promotions( ?int $actor_user_id = null ): array {
		return $this->run_status_batch(
			$this->promotions->find_scheduled_drafts_ready( 500 ),
			PromotionStatus::DRAFT,
			PromotionStatus::ACTIVE,
			'promotion.auto_activated',
			$actor_user_id
		);
	}

	/**
	 * Archive paused promotions past ends_at.
	 *
	 * @return array{changed: list<array{id: int, name: string}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	public function archive_expired_paused_promotions( ?int $actor_user_id = null ): array {
		return $this->run_status_batch(
			$this->promotions->find_expired_paused( 500 ),
			PromotionStatus::PAUSED,
			PromotionStatus::ARCHIVED,
			'promotion.auto_archived',
			$actor_user_id
		);
	}

	/**
	 * Normalize inconsistent promotion states (pause expired active; warn archived future).
	 *
	 * @return array{
	 *     changed: list<array{id: int, name: string, action: string}>,
	 *     warnings: list<array{id: int, name: string, reason: string}>,
	 *     skipped: list<array{id: int, reason: string}>,
	 *     errors: list<array{id: int, message: string}>
	 * }
	 */
	public function normalize_invalid_promotion_states( ?int $actor_user_id = null ): array {
		$result = array(
			'changed'  => array(),
			'warnings' => array(),
			'skipped'  => array(),
			'errors'   => array(),
		);

		$expired_active = $this->promotions->find_expired_active( 500 );
		foreach ( $expired_active as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			try {
				$this->change_status( $promotion, PromotionStatus::PAUSED, $actor_user_id );
				$this->audit->log(
					'promotion.normalized',
					$id,
					array(
						'action' => 'expired_active_to_paused',
					),
					$actor_user_id
				);
				$result['changed'][] = array(
					'id'     => $id,
					'name'   => $promotion->get_name(),
					'action' => 'expired_active_to_paused',
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = array(
					'id'      => $id,
					'message' => $e->getMessage(),
				);
			}
		}

		$all = $this->promotions->find_filtered( array( 'status' => PromotionStatus::ARCHIVED, 'limit' => 500 ) );
		$now = strtotime( current_time( 'mysql' ) );
		foreach ( $all as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$starts = $promotion->get_starts_at();
			if ( $starts === null || $starts === '' ) {
				continue;
			}
			$starts_ts = strtotime( $starts );
			if ( $starts_ts !== false && $starts_ts > $now ) {
				$result['warnings'][] = array(
					'id'     => $id,
					'name'   => $promotion->get_name(),
					'reason' => 'archived_future_starts_at',
				);
			}
		}

		return $result;
	}

	/**
	 * @param list<Promotion> $candidates
	 * @return array{changed: list<array{id: int, name: string}>, skipped: list<array{id: int, reason: string}>, errors: list<array{id: int, message: string}>}
	 */
	private function run_status_batch(
		array $candidates,
		string $expected_status,
		string $target_status,
		string $audit_action,
		?int $actor_user_id
	): array {
		$result = array(
			'changed' => array(),
			'skipped' => array(),
			'errors'  => array(),
		);

		foreach ( $candidates as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}

			if ( $promotion->get_status() !== $expected_status ) {
				$result['skipped'][] = array(
					'id'     => $id,
					'reason' => 'unexpected_status',
				);
				continue;
			}

			try {
				$this->change_status( $promotion, $target_status, $actor_user_id );
				$this->audit->log(
					$audit_action,
					$id,
					array(
						'from' => $expected_status,
						'to'   => $target_status,
					),
					$actor_user_id
				);
				$result['changed'][] = array(
					'id'   => $id,
					'name' => $promotion->get_name(),
				);
			} catch ( RuntimeException $e ) {
				$result['errors'][] = array(
					'id'      => $id,
					'message' => $e->getMessage(),
				);
			}
		}

		return $result;
	}
}
