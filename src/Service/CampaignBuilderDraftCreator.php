<?php
/**
 * Creates draft promotions from Campaign Builder merchant forms.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\RuleTypes;
use RuntimeException;

final class CampaignBuilderDraftCreator {

	private ?PromotionService $promotion_service;

	private ?PromotionCodeFactory $code_factory;

	private ?PromotionCodeRepository $codes;

	public function __construct(
		?PromotionService $promotion_service = null,
		?PromotionCodeFactory $code_factory = null,
		?PromotionCodeRepository $codes = null
	) {
		$this->promotion_service = $promotion_service;
		$this->code_factory      = $code_factory;
		$this->codes             = $codes;
	}

	/**
	 * @param array<string, mixed> $form Sanitized form payload from CampaignBuilderPage.
	 * @return array{promotion: Promotion, generated_code: string|null, goal: string}
	 */
	public function create_draft( array $form ): array {
		if ( $this->promotion_service === null ) {
			throw new RuntimeException( 'PromotionService is required to create drafts.' );
		}

		$goal = CampaignBuilderGoal::sanitize( isset( $form['campaign_goal'] ) ? (string) $form['campaign_goal'] : null );
		if ( $goal === null ) {
			throw new InvalidArgumentException( 'invalid_campaign_goal' );
		}

		$name = isset( $form['campaign_name'] ) ? trim( (string) $form['campaign_name'] ) : '';
		if ( $name === '' ) {
			throw new InvalidArgumentException( 'empty_campaign_name' );
		}

		$rules = $this->build_rules( $goal, $form );

		$draft = $this->promotion_service->create_draft( $name, isset( $form['actor_user_id'] ) ? (int) $form['actor_user_id'] : null );

		$draft = $draft->with_rules(
			$rules['conditions'],
			$rules['actions'],
			$rules['restrictions']
		);

		$label = isset( $form['campaign_label'] ) ? trim( (string) $form['campaign_label'] ) : '';
		if ( $label === '' ) {
			$label = null;
		}

		$draft = $draft->with_campaign_metadata(
			$label,
			CampaignBuilderGoal::encode_internal_notes( $goal ),
			null
		);

		$draft = $draft->with_date_window(
			$this->nullable_datetime( $form['starts_at'] ?? null ),
			$this->nullable_datetime( $form['ends_at'] ?? null )
		);

		$budget = $this->optional_positive_float( $form['budget_amount'] ?? null );
		if ( $budget !== null && $budget > 0 ) {
			$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null;
			$draft    = $draft->with_budget( $budget, 0.0, $currency );
		}

		$usage_limit = $this->optional_positive_int( $form['usage_limit'] ?? null );
		if ( $usage_limit !== null ) {
			$draft = $draft->with_usage_limits( $usage_limit, $draft->get_customer_usage_limit() );
		}

		$stackable = ! empty( $form['stackable'] );
		$draft     = $draft->with_application_rules(
			$stackable ? PromotionApplicationMode::STACKABLE : PromotionApplicationMode::EXCLUSIVE,
			! $stackable,
			$draft->get_max_applications()
		);

		$draft = $this->promotion_service->update_promotion( $draft, isset( $form['actor_user_id'] ) ? (int) $form['actor_user_id'] : null );

		$generated_code = null;
		$require_code   = ! empty( $form['require_coupon_code'] ) || $goal === CampaignBuilderGoal::COUPON_CODE;

		if ( $require_code && $this->code_factory !== null && $this->codes !== null ) {
			$generated_code = $this->create_promotion_code( $draft, $form );
		}

		return array(
			'promotion'      => $draft,
			'generated_code' => $generated_code,
			'goal'           => $goal,
		);
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	public function build_rules( string $goal, array $form ): array {
		if ( $goal === CampaignBuilderGoal::COUPON_CODE || $goal === CampaignBuilderGoal::BUDGETED ) {
			return $this->build_whole_cart_rules( $form );
		}

		$template_input = $this->build_template_input( $goal, $form );
		$template_key   = $this->resolve_template_key( $goal, $form );

		if ( $template_key !== null && $this->uses_template_builder( $goal, $form ) ) {
			return PromotionTemplate::build( $template_key, $template_input );
		}

		return $this->build_custom_rules( $goal, $form );
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private function build_whole_cart_rules( array $form ): array {
		$discount_type = isset( $form['discount_type'] ) ? sanitize_key( (string) $form['discount_type'] ) : 'percentage';
		if ( $discount_type === 'fixed' ) {
			$action = array(
				'type'   => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
				'amount' => $this->require_positive_float( $form, 'amount' ),
			);
		} else {
			$action = array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => $this->require_percentage( $form, 'percentage' ),
			);
		}

		return array(
			'conditions'   => array(),
			'actions'      => array( $action ),
			'restrictions' => array(),
		);
	}

	public static function apply_stackable_rules( Promotion $promotion, bool $stackable ): Promotion {
		return $promotion->with_application_rules(
			$stackable ? PromotionApplicationMode::STACKABLE : PromotionApplicationMode::EXCLUSIVE,
			! $stackable,
			$promotion->get_max_applications()
		);
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function resolve_template_key( string $goal, array $form ): ?string {
		$defs = CampaignBuilderGoal::definitions();
		if ( ! isset( $defs[ $goal ] ) ) {
			return null;
		}

		if ( $goal === CampaignBuilderGoal::PRODUCT_DISCOUNT
			&& isset( $form['discount_type'] )
			&& (string) $form['discount_type'] === 'percentage'
		) {
			return null;
		}

		if ( $goal === CampaignBuilderGoal::CATEGORY_DISCOUNT
			&& isset( $form['discount_type'] )
			&& (string) $form['discount_type'] === 'fixed'
		) {
			return null;
		}

		return $defs[ $goal ]['template_key'];
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function uses_template_builder( string $goal, array $form ): bool {
		return $this->resolve_template_key( $goal, $form ) !== null;
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array<string, mixed>
	 */
	public function build_template_input( string $goal, array $form ): array {
		$discount_type = isset( $form['discount_type'] ) ? sanitize_key( (string) $form['discount_type'] ) : 'percentage';
		if ( $discount_type !== 'fixed' ) {
			$discount_type = 'percentage';
		}

		$input = array(
			'category_ids'              => $this->int_list( $form['category_ids'] ?? array() ),
			'product_ids'               => $this->int_list( $form['product_ids'] ?? array() ),
			'discount_type'             => $discount_type,
			'percentage'                => $form['percentage'] ?? '',
			'amount'                    => $form['amount'] ?? '',
			'minimum_eligible_subtotal' => $form['minimum_eligible_subtotal'] ?? '',
			'scope'                     => $form['bogo_scope'] ?? CheapestItemDiscountAction::SCOPE_CATEGORY,
			'required_quantity'         => $form['required_quantity'] ?? '',
			'discounted_quantity'       => $form['discounted_quantity'] ?? '',
			'discount_percentage'       => $form['discount_percentage'] ?? '',
			'gift_product_id'           => $form['gift_product_id'] ?? '',
			'gift_quantity'             => $form['gift_quantity'] ?? '1',
			'roles'                     => $this->string_list( $form['roles'] ?? array() ),
		);

		if ( $goal === CampaignBuilderGoal::FREE_SHIPPING || $goal === CampaignBuilderGoal::FREE_GIFT ) {
			$input['amount'] = $form['minimum_subtotal'] ?? $form['amount'] ?? '';
		}

		if ( in_array( $goal, array( CampaignBuilderGoal::COUPON_CODE, CampaignBuilderGoal::BUDGETED, CampaignBuilderGoal::SCHEDULED ), true ) ) {
			if ( $goal === CampaignBuilderGoal::SCHEDULED ) {
				$input['category_ids'] = $this->int_list( $form['category_ids'] ?? array() );
				$input['percentage']   = $form['percentage'] ?? '10';
			} else {
				$input['discount_type'] = $discount_type;
				$input['percentage']    = $form['percentage'] ?? '10';
				$input['amount']        = $form['amount'] ?? '10';
			}
		}

		return $input;
	}

	/**
	 * @param array<string, mixed> $form
	 * @return array{conditions: list<array<string, mixed>>, actions: list<array<string, mixed>>, restrictions: list<array<string, mixed>>}
	 */
	private function build_custom_rules( string $goal, array $form ): array {
		if ( $goal === CampaignBuilderGoal::PRODUCT_DISCOUNT
			&& isset( $form['discount_type'] )
			&& (string) $form['discount_type'] === 'percentage'
		) {
			$product_ids = $this->int_list( $form['product_ids'] ?? array() );
			if ( $product_ids === array() ) {
				throw new InvalidArgumentException( 'missing_product_ids' );
			}

			$percentage = $this->require_percentage( $form, 'percentage' );

			return array(
				'conditions'   => array(),
				'actions'      => array(
					array(
						'type'        => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
						'percentage'  => $percentage,
						'product_ids' => $product_ids,
					),
				),
				'restrictions' => array(),
			);
		}

		if ( $goal === CampaignBuilderGoal::CATEGORY_DISCOUNT
			&& isset( $form['discount_type'] )
			&& (string) $form['discount_type'] === 'fixed'
		) {
			$category_ids = $this->int_list( $form['category_ids'] ?? array() );
			if ( $category_ids === array() ) {
				throw new InvalidArgumentException( 'missing_category_ids' );
			}

			$amount = $this->require_positive_float( $form, 'amount' );

			return array(
				'conditions'   => array(),
				'actions'      => array(
					array(
						'type'         => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
						'amount'       => $amount,
						'category_ids' => $category_ids,
					),
				),
				'restrictions' => array(),
			);
		}

		throw new InvalidArgumentException( 'unsupported_custom_rules' );
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function create_promotion_code( Promotion $promotion, array $form ): string {
		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			throw new RuntimeException( 'promotion_id_required' );
		}

		$plain = isset( $form['coupon_code'] ) ? trim( (string) $form['coupon_code'] ) : '';
		if ( $plain === '' && ! empty( $form['generate_coupon_code'] ) ) {
			$plain = $this->generate_plain_code();
		}

		if ( $plain === '' ) {
			throw new InvalidArgumentException( 'missing_coupon_code' );
		}

		$code_usage_limit = $this->optional_positive_int( $form['code_usage_limit'] ?? null );

		if ( $this->codes !== null && $this->codes->find_by_plain_code( $plain ) !== null ) {
			throw new InvalidArgumentException( 'duplicate_coupon_code' );
		}

		$code = $this->code_factory->create_manual_code(
			$pid,
			$plain,
			$code_usage_limit,
			$this->nullable_datetime( $form['ends_at'] ?? null )
		);

		$new_id = $this->codes->insert( $code );
		if ( $new_id <= 0 ) {
			throw new RuntimeException( 'code_insert_failed' );
		}

		return PromotionCodeFactory::normalize_plain_code( $plain );
	}

	private function generate_plain_code(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$max      = strlen( $alphabet ) - 1;
		$out      = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$out .= $alphabet[ random_int( 0, $max ) ];
		}

		return $out;
	}

	/**
	 * @param mixed $value
	 * @return list<int>
	 */
	private function int_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();
		foreach ( $value as $item ) {
			if ( is_numeric( $item ) && (int) $item > 0 ) {
				$ids[] = (int) $item;
			}
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private function string_list( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$out = array();
		foreach ( $value as $item ) {
			if ( is_string( $item ) ) {
				$key = sanitize_key( trim( $item ) );
				if ( $key !== '' ) {
					$out[] = $key;
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function require_percentage( array $form, string $key ): float {
		if ( ! isset( $form[ $key ] ) || ! is_numeric( $form[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$value = (float) $form[ $key ];
		if ( $value <= 0 || $value > 100 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param array<string, mixed> $form
	 */
	private function require_positive_float( array $form, string $key ): float {
		if ( ! isset( $form[ $key ] ) || ! is_numeric( $form[ $key ] ) ) {
			throw new InvalidArgumentException( 'missing_' . $key );
		}

		$value = (float) $form[ $key ];
		if ( $value <= 0 ) {
			throw new InvalidArgumentException( 'invalid_' . $key );
		}

		return $value;
	}

	/**
	 * @param mixed $value
	 */
	private function optional_positive_float( $value ): ?float {
		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$f = (float) $value;

		return $f > 0 ? $f : null;
	}

	/**
	 * @param mixed $value
	 */
	private function optional_positive_int( $value ): ?int {
		if ( $value === null || $value === '' ) {
			return null;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$i = (int) $value;

		return $i >= 1 ? $i : null;
	}

	/**
	 * @param mixed $value
	 */
	private function nullable_datetime( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}

		$raw = trim( (string) $value );
		if ( $raw === '' ) {
			return null;
		}

		$ts = strtotime( $raw );
		if ( $ts === false ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
