<?php
/**
 * Plain-language campaign preview and merchant safety warnings for Campaign Builder.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Service\LineDiscountModeHelper;
use MP\CommercePromotions\Engine\RuleTypes;

final class CampaignBuilderPreview {

	private PromotionRuleValidator $validator;

	private PromotionConflictAnalyzer $conflicts;

	private ?MerchantSafetyAdvisor $safety;

	private ?PromotionRepository $promotions;

	private CampaignBuilderDraftCreator $draft_creator;

	public function __construct(
		CampaignBuilderDraftCreator $draft_creator,
		?PromotionRepository $promotions = null,
		?PromotionRuleValidator $validator = null,
		?PromotionConflictAnalyzer $conflicts = null,
		?MerchantSafetyAdvisor $safety = null
	) {
		$this->draft_creator = $draft_creator;
		$this->promotions    = $promotions;
		$this->validator     = $validator ?? new PromotionRuleValidator();
		$this->conflicts     = $conflicts ?? new PromotionConflictAnalyzer();
		$this->safety        = $safety ?? ( $promotions !== null ? new MerchantSafetyAdvisor( $promotions ) : null );
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array{
	 *     applies_when: string,
	 *     customer_receives: string,
	 *     limits: string,
	 *     stacking: string,
	 *     coupon: string,
	 *     warnings: list<string>,
	 *     recommendations: list<string>
	 * }
	 */
	public function summarize_form( string $goal, array $form, ?Promotion $draft = null ): array {
		$promotion = $draft;
		if ( $promotion === null ) {
			try {
				$rules     = $this->draft_creator->build_rules( $goal, $form );
				$promotion = Promotion::from_array(
					array(
						'uuid'             => '00000000-0000-4000-8000-000000000001',
						'name'             => isset( $form['campaign_name'] ) ? (string) $form['campaign_name'] : 'Preview',
						'status'           => PromotionStatus::DRAFT,
						'conditions'       => $rules['conditions'],
						'actions'          => $rules['actions'],
						'restrictions'     => $rules['restrictions'],
						'application_mode' => ! empty( $form['stackable'] ) ? PromotionApplicationMode::STACKABLE : PromotionApplicationMode::EXCLUSIVE,
						'stop_processing'  => empty( $form['stackable'] ),
						'budget_amount'    => $form['budget_amount'] ?? null,
						'usage_limit'      => $form['usage_limit'] ?? null,
						'starts_at'        => $form['starts_at'] ?? null,
						'ends_at'          => $form['ends_at'] ?? null,
					)
				);
			} catch ( \Throwable $e ) {
				return array(
					'applies_when'      => __( 'Complete the form to see when this campaign applies.', 'mp-commerce-promotions' ),
					'customer_receives' => __( '—', 'mp-commerce-promotions' ),
					'limits'            => __( '—', 'mp-commerce-promotions' ),
					'stacking'          => __( '—', 'mp-commerce-promotions' ),
					'coupon'            => __( '—', 'mp-commerce-promotions' ),
					'warnings'          => array( __( 'Some required fields are missing or invalid.', 'mp-commerce-promotions' ) ),
					'recommendations'   => array(),
				);
			}
		}

		return $this->summarize_promotion( $promotion, $goal, $form );
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array{
	 *     applies_when: string,
	 *     customer_receives: string,
	 *     limits: string,
	 *     stacking: string,
	 *     coupon: string,
	 *     warnings: list<string>,
	 *     recommendations: list<string>
	 * }
	 */
	public function summarize_promotion( Promotion $promotion, ?string $goal = null, array $form = array() ): array {
		$warnings        = array();
		$recommendations = array();

		if ( $promotion->get_ends_at() === null ) {
			$warnings[] = __( 'No end date — the campaign can run indefinitely until paused or archived.', 'mp-commerce-promotions' );
		}

		if ( LineDiscountModeHelper::uses_experimental_line_mode( $promotion ) ) {
			$warnings[] = __( 'Line discount mode is experimental and not editable in the simple Campaign Builder.', 'mp-commerce-promotions' );
		}

		if ( CampaignBuilderGoal::parse_goal_from_notes( $promotion->get_internal_notes() ) === null
			&& $goal === null
			&& count( $promotion->get_actions() ) > 0
		) {
			$warnings[] = __( 'This promotion was not created in Campaign Builder — use the Advanced Editor for full control.', 'mp-commerce-promotions' );
		} elseif ( CampaignBuilderGoal::has_advanced_builder_rules( $promotion ) ) {
			$warnings[] = __( 'This campaign has advanced rules that are not editable in the simple builder.', 'mp-commerce-promotions' );
		}

		foreach ( $this->validator->validate( $promotion ) as $issue ) {
			if ( ( $issue['level'] ?? '' ) === 'error' ) {
				$warnings[] = (string) ( $issue['message'] ?? '' );
			}
		}

		if ( $this->safety !== null ) {
			foreach ( $this->safety->analyze_promotion( $promotion ) as $issue ) {
				$warnings[] = (string) ( $issue['message'] ?? '' );
			}
		}

		if ( $promotion->has_budget_cap() && $promotion->is_budget_exhausted() ) {
			$warnings[] = __( 'Budget is exhausted — customers will not receive this discount until the budget is increased.', 'mp-commerce-promotions' );
		} elseif ( $promotion->has_budget_cap() ) {
			$cap       = (float) $promotion->get_budget_amount();
			$remaining = max( 0.0, $cap - (float) $promotion->get_budget_spent() );
			if ( $remaining < ( $cap * 0.1 ) ) {
				$warnings[] = __( 'Budget may exhaust soon based on current spend.', 'mp-commerce-promotions' );
			}
		}

		if ( ! empty( $form['require_coupon_code'] ) || $goal === CampaignBuilderGoal::COUPON_CODE ) {
			$has_code = ! empty( $form['coupon_code'] ) || ! empty( $form['generate_coupon_code'] );
			if ( ! $has_code && $goal === CampaignBuilderGoal::COUPON_CODE ) {
				$warnings[] = __( 'Coupon required but no code provided — enter a code or choose auto-generate.', 'mp-commerce-promotions' );
			}
		}

		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) || ( $action['type'] ?? '' ) !== RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
				continue;
			}
			$product_id = (int) ( $action['product_id'] ?? 0 );
			if ( $product_id > 0 && function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $product_id );
				if ( $product === false || $product === null || ! $product->is_purchasable() ) {
					$warnings[] = __( 'Free gift product may be unavailable or not purchasable.', 'mp-commerce-promotions' );
				}
			}
		}

		if ( $this->promotions !== null ) {
			$active = $this->promotions->find_filtered(
				array(
					'status' => PromotionStatus::ACTIVE,
					'limit'  => 100,
				)
			);
			$peer   = array_merge( $active, array( $promotion ) );
			foreach ( $this->conflicts->analyze( $peer ) as $conflict ) {
				$ids = $conflict['promotion_ids'] ?? array();
				$pid = $promotion->get_id();
				if ( $pid !== null && in_array( $pid, $ids, true ) && count( $ids ) > 1 ) {
					$warnings[] = (string) ( $conflict['message'] ?? __( 'Possible overlap with another active campaign.', 'mp-commerce-promotions' ) );
					break;
				}
			}

			$schedule = new PromotionScheduleAnalyzer();
			foreach ( $schedule->analyze( $active, $promotion ) as $note ) {
				$warnings[] = (string) ( $note['message'] ?? '' );
			}
		}

		$recommendations[] = __( 'Review cart preview on the Advanced Editor before activating.', 'mp-commerce-promotions' );
		$recommendations[] = __( 'Confirm stacking behavior matches your other live campaigns.', 'mp-commerce-promotions' );

		if ( $promotion->get_status() === PromotionStatus::DRAFT ) {
			$recommendations[] = __( 'Activate only after validating dates, budget, and coupon codes.', 'mp-commerce-promotions' );
		}

		return array(
			'applies_when'      => $this->describe_applies_when( $promotion, $goal ),
			'customer_receives' => $this->describe_customer_receives( $promotion ),
			'limits'            => $this->describe_limits( $promotion ),
			'stacking'          => $this->describe_stacking( $promotion ),
			'coupon'            => $this->describe_coupon( $promotion, $form, $goal ),
			'warnings'          => array_values( array_unique( array_filter( $warnings ) ) ),
			'recommendations'   => $recommendations,
		);
	}

	private function describe_applies_when( Promotion $promotion, ?string $goal ): string {
		$parts = array();
		foreach ( $promotion->get_conditions() as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}
			$type = (string) ( $condition['type'] ?? '' );
			if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
				$parts[] = sprintf(
					/* translators: %s: amount */
					__( 'Cart subtotal is at least %s', 'mp-commerce-promotions' ),
					(string) ( $condition['amount'] ?? '' )
				);
			} elseif ( $type === RuleTypes::CONDITION_FIRST_ORDER ) {
				$parts[] = __( 'Customer’s first order', 'mp-commerce-promotions' );
			} elseif ( $type === RuleTypes::CONDITION_CUSTOMER_ROLE ) {
				$roles = $condition['roles'] ?? array();
				$parts[] = sprintf(
					/* translators: %s: comma-separated roles */
					__( 'Customer has role: %s', 'mp-commerce-promotions' ),
					is_array( $roles ) ? implode( ', ', $roles ) : ''
				);
			} elseif ( $type === RuleTypes::CONDITION_MINIMUM_ELIGIBLE_SUBTOTAL ) {
				$parts[] = __( 'Eligible category/product lines meet minimum subtotal', 'mp-commerce-promotions' );
			}
		}

		if ( $parts === array() ) {
			if ( $goal === CampaignBuilderGoal::COUPON_CODE || $goal === CampaignBuilderGoal::BUDGETED ) {
				return __( 'When a valid promotion code is entered at checkout', 'mp-commerce-promotions' );
			}

			return __( 'When cart rules are satisfied (no extra conditions)', 'mp-commerce-promotions' );
		}

		return implode( '; ', $parts );
	}

	private function describe_customer_receives( Promotion $promotion ): string {
		$parts = array();
		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$parts[] = sprintf(
					/* translators: %s: percentage */
					__( '%s%% discount', 'mp-commerce-promotions' ),
					(string) ( $action['percentage'] ?? '' )
				);
			} elseif ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$parts[] = sprintf(
					/* translators: %s: amount */
					__( '%s off', 'mp-commerce-promotions' ),
					(string) ( $action['amount'] ?? '' )
				);
			} elseif ( $type === RuleTypes::ACTION_FREE_SHIPPING ) {
				$parts[] = __( 'Free shipping', 'mp-commerce-promotions' );
			} elseif ( $type === RuleTypes::ACTION_FREE_GIFT_PRODUCT ) {
				$parts[] = sprintf(
					/* translators: %d: product id */
					__( 'Free gift (product #%d)', 'mp-commerce-promotions' ),
					(int) ( $action['product_id'] ?? 0 )
				);
			} elseif ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
				$parts[] = __( 'Discount on cheapest qualifying units (buy X get Y)', 'mp-commerce-promotions' );
			}
		}

		return $parts === array() ? __( '—', 'mp-commerce-promotions' ) : implode( '; ', $parts );
	}

	private function describe_limits( Promotion $promotion ): string {
		$parts = array();
		if ( $promotion->get_usage_limit() !== null ) {
			$parts[] = sprintf(
				/* translators: 1: used count, 2: limit */
				__( 'Usage %1$d / %2$d', 'mp-commerce-promotions' ),
				$promotion->get_usage_count(),
				$promotion->get_usage_limit()
			);
		}
		if ( $promotion->has_budget_cap() ) {
			$parts[] = sprintf(
				/* translators: 1: spent, 2: cap */
				__( 'Budget %1$s / %2$s', 'mp-commerce-promotions' ),
				(string) $promotion->get_budget_spent(),
				(string) $promotion->get_budget_amount()
			);
		}
		if ( $promotion->get_starts_at() !== null || $promotion->get_ends_at() !== null ) {
			$parts[] = sprintf(
				'%s → %s',
				$promotion->get_starts_at() ?? '—',
				$promotion->get_ends_at() ?? '—'
			);
		}

		return $parts === array() ? __( 'No usage or budget limits set', 'mp-commerce-promotions' ) : implode( '; ', $parts );
	}

	private function describe_stacking( Promotion $promotion ): string {
		if ( $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE ) {
			return __( 'Stackable — may combine with other stackable promotions', 'mp-commerce-promotions' );
		}

		return __( 'Exclusive — stops other promotions when applied', 'mp-commerce-promotions' );
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function describe_coupon( Promotion $promotion, array $form, ?string $goal ): string {
		if ( ! empty( $form['require_coupon_code'] ) || $goal === CampaignBuilderGoal::COUPON_CODE ) {
			if ( ! empty( $form['coupon_code'] ) ) {
				return sprintf(
					/* translators: %s: code */
					__( 'Requires code: %s', 'mp-commerce-promotions' ),
					(string) $form['coupon_code']
				);
			}
			if ( ! empty( $form['generate_coupon_code'] ) ) {
				return __( 'A unique code will be generated on save', 'mp-commerce-promotions' );
			}

			return __( 'Coupon code required at checkout', 'mp-commerce-promotions' );
		}

		return __( 'No coupon code required — applies automatically when eligible', 'mp-commerce-promotions' );
	}
}
