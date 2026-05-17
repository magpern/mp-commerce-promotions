<?php
/**
 * Fixed amount discount preview (whole-cart fee or scoped eligible subtotal cap).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class FixedAmountDiscountAction implements ActionInterface {

	public const REASON_NO_ELIGIBLE_SUBTOTAL = 'no_eligible_subtotal';

	private float $amount;

	/** @var list<int> */
	private array $product_ids;

	/** @var list<int> */
	private array $variation_ids;

	/** @var list<int> */
	private array $category_ids;

	public function __construct( float $amount ) {
		if ( $amount <= 0 ) {
			throw new InvalidArgumentException( 'fixed_amount_discount amount must be > 0.' );
		}
		$this->amount        = $amount;
		$this->product_ids   = array();
		$this->variation_ids = array();
		$this->category_ids  = array();
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public static function from_config( array $config ): self {
		if ( ! isset( $config['amount'] ) || ! is_numeric( $config['amount'] ) ) {
			throw new InvalidArgumentException( 'fixed_amount_discount amount is required.' );
		}

		$scope = EligibleCartScope::parse_scope_lists( $config );
		$self  = new self( (float) $config['amount'] );
		$self->product_ids   = $scope['product_ids'];
		$self->variation_ids = $scope['variation_ids'];
		$self->category_ids  = $scope['category_ids'];

		return $self;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		$payload = array(
			'amount' => $this->amount,
		);

		if ( ! $this->has_scope() ) {
			return new ActionResult( $this->get_type(), $payload );
		}

		$eligible_items    = EligibleCartScope::filter_items(
			$context->get_items(),
			$this->product_ids,
			$this->variation_ids,
			$this->category_ids
		);
		$eligible_subtotal = EligibleCartScope::subtotal( $eligible_items );

		$payload['eligible_subtotal']   = $eligible_subtotal;
		$payload['matched_items_count'] = count( $eligible_items );
		$payload['scoped']              = true;

		if ( $eligible_subtotal <= 0 ) {
			$payload['not_applicable']    = true;
			$payload['reason']            = self::REASON_NO_ELIGIBLE_SUBTOTAL;
			$payload['applied_discount']  = 0.0;

			return new ActionResult( $this->get_type(), $payload );
		}

		$payload['applied_discount'] = round( min( $this->amount, $eligible_subtotal ), 4 );

		return new ActionResult( $this->get_type(), $payload );
	}

	private function has_scope(): bool {
		return $this->product_ids !== array()
			|| $this->variation_ids !== array()
			|| $this->category_ids !== array();
	}
}
