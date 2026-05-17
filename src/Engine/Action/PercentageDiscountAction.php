<?php
/**
 * Percentage discount preview (whole-cart or scoped eligible subtotal).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\CartItemSelector;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class PercentageDiscountAction implements ActionInterface {

	private float $percentage;

	/** @var list<int> */
	private array $product_ids;

	/** @var list<int> */
	private array $variation_ids;

	/** @var list<int> */
	private array $category_ids;

	private bool $exclude_sale_items;

	public function __construct( float $percentage ) {
		if ( $percentage <= 0 || $percentage > 100 ) {
			throw new InvalidArgumentException( 'percentage_discount percentage must be > 0 and <= 100.' );
		}
		$this->percentage          = $percentage;
		$this->product_ids         = array();
		$this->variation_ids       = array();
		$this->category_ids        = array();
		$this->exclude_sale_items  = false;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function from_config( array $config ): self {
		if ( ! isset( $config['percentage'] ) || ! is_numeric( $config['percentage'] ) ) {
			throw new InvalidArgumentException( 'percentage_discount percentage is required.' );
		}

		$scope = EligibleCartScope::parse_scope_lists( $config );
		$self  = new self( (float) $config['percentage'] );
		$self->product_ids        = $scope['product_ids'];
		$self->variation_ids      = $scope['variation_ids'];
		$self->category_ids       = $scope['category_ids'];
		$self->exclude_sale_items = $scope['exclude_sale_items'];

		return $self;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_PERCENTAGE_DISCOUNT;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		$payload = array(
			'percentage' => $this->percentage,
		);

		if ( ! $this->has_scope() ) {
			return new ActionResult( $this->get_type(), $payload );
		}

		$items_before_sale = EligibleCartScope::filter_items(
			$context->get_items(),
			$this->product_ids,
			$this->variation_ids,
			$this->category_ids
		);
		$sale_excluded_count = 0;
		if ( $this->exclude_sale_items ) {
			$sale_excluded_count = CartItemSelector::count_sale_items( $items_before_sale );
		}

		$eligible_items    = EligibleCartScope::filter_items(
			$context->get_items(),
			$this->product_ids,
			$this->variation_ids,
			$this->category_ids,
			array(),
			array(),
			$this->exclude_sale_items
		);
		$eligible_subtotal   = EligibleCartScope::subtotal( $eligible_items );
		$calculated_discount = round( $eligible_subtotal * $this->percentage / 100.0, 4 );

		$payload['eligible_subtotal']    = $eligible_subtotal;
		$payload['calculated_discount']  = $calculated_discount;
		$payload['matched_items_count']  = count( $eligible_items );
		$payload['scoped']               = true;

		if ( $this->exclude_sale_items && $sale_excluded_count > 0 ) {
			$payload['sale_items_excluded_count'] = $sale_excluded_count;
		}

		return new ActionResult( $this->get_type(), $payload );
	}

	private function has_scope(): bool {
		return EligibleCartScope::has_include_or_sale_scope(
			array(
				'product_ids'        => $this->product_ids,
				'variation_ids'      => $this->variation_ids,
				'category_ids'       => $this->category_ids,
				'exclude_sale_items' => $this->exclude_sale_items,
			)
		);
	}
}
