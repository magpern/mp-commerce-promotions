<?php
/**
 * Creates/archives paused promotions for Cart/Checkout Blocks manual QA.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use Throwable;

final class BlockQaPromotionSetup {

	private PromotionRepository $promotions;

	private PromotionService $service;

	public function __construct( PromotionRepository $promotions, PromotionService $service ) {
		$this->promotions = $promotions;
		$this->service    = $service;
	}

	/**
	 * @return array{archived: int, created: list<array{id: int, name: string, status: string}>}
	 */
	public function refresh_qa_promotions( int $gift_product_id = 0 ): array {
		$archived = $this->archive_existing();
		$created  = array();

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Fee 10%',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ),
			),
			PromotionDiscountApplicationMode::FEE_BASED
		);

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Fixed 5',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 5 ),
			),
			PromotionDiscountApplicationMode::FEE_BASED
		);

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Free shipping',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
			),
			PromotionDiscountApplicationMode::FEE_BASED
		);

		if ( $gift_product_id > 0 ) {
			$created[] = $this->create_paused(
				BlockTestPages::QA_PROMOTION_PREFIX . ' — Free gift',
				array(
					array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
				),
				array(
					array(
						'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
						'product_id' => $gift_product_id,
						'quantity'   => 1,
					),
				),
				PromotionDiscountApplicationMode::FEE_BASED
			);
		}

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Line 10%',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ),
			),
			PromotionDiscountApplicationMode::LINE_ITEM
		);

		return array(
			'archived' => $archived,
			'created'  => $created,
		);
	}

	public function archive_existing(): int {
		$archived = 0;
		$rows     = $this->promotions->find_filtered(
			array(
				'search' => BlockTestPages::QA_PROMOTION_PREFIX,
				'limit'  => 100,
			)
		);

		foreach ( $rows as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$status = $promotion->get_status();
			if ( $status !== PromotionStatus::ACTIVE && $status !== PromotionStatus::PAUSED ) {
				continue;
			}
			try {
				$this->service->change_status( $promotion, PromotionStatus::ARCHIVED );
				++$archived;
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		return $archived;
	}

	/**
	 * @param list<array<string, mixed>> $conditions
	 * @param list<array<string, mixed>> $actions
	 * @return array{id: int, name: string, status: string}
	 */
	private function create_paused( string $name, array $conditions, array $actions, string $application_mode ): array {
		$draft = $this->service->create_draft( $name );
		$id    = (int) ( $draft->get_id() ?? 0 );

		$model = $draft
			->with_rules( $conditions, $actions, $draft->get_restrictions() )
			->with_priority( 5 )
			->with_pricing_fields( null, null, null, $application_mode );

		$this->service->update_promotion( $model );
		$this->service->change_status( $model, PromotionStatus::PAUSED );

		return array(
			'id'     => $id,
			'name'   => $name,
			'status' => PromotionStatus::PAUSED,
		);
	}
}
