<?php
/**
 * Action: add a configured product to the cart as a free gift when the promotion applies.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Action;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class FreeGiftProductAction implements ActionInterface {

	private int $product_id;

	private ?int $variation_id;

	private int $quantity;

	/**
	 * @param array<string, mixed> $config Promotion action JSON object.
	 */
	public static function from_config( array $config ): self {
		if ( ! isset( $config['product_id'] ) || ! is_numeric( $config['product_id'] ) ) {
			throw new InvalidArgumentException( 'free_gift_product product_id is required.' );
		}

		$product_id = (int) $config['product_id'];
		if ( $product_id <= 0 ) {
			throw new InvalidArgumentException( 'free_gift_product product_id must be a positive integer.' );
		}

		$variation_id = null;
		if ( isset( $config['variation_id'] ) && $config['variation_id'] !== null && $config['variation_id'] !== '' ) {
			if ( ! is_numeric( $config['variation_id'] ) ) {
				throw new InvalidArgumentException( 'free_gift_product variation_id must be a positive integer when set.' );
			}
			$variation_id = (int) $config['variation_id'];
			if ( $variation_id <= 0 ) {
				throw new InvalidArgumentException( 'free_gift_product variation_id must be a positive integer when set.' );
			}
		}

		if ( ! isset( $config['quantity'] ) || ! is_numeric( $config['quantity'] ) ) {
			throw new InvalidArgumentException( 'free_gift_product quantity is required.' );
		}
		$quantity = (int) $config['quantity'];
		if ( $quantity < 1 ) {
			throw new InvalidArgumentException( 'free_gift_product quantity must be >= 1.' );
		}

		return new self( $product_id, $variation_id, $quantity );
	}

	public function __construct( int $product_id, ?int $variation_id, int $quantity ) {
		if ( $product_id <= 0 ) {
			throw new InvalidArgumentException( 'free_gift_product product_id must be a positive integer.' );
		}
		if ( $variation_id !== null && $variation_id <= 0 ) {
			throw new InvalidArgumentException( 'free_gift_product variation_id must be a positive integer when set.' );
		}
		if ( $quantity < 1 ) {
			throw new InvalidArgumentException( 'free_gift_product quantity must be >= 1.' );
		}

		$this->product_id   = $product_id;
		$this->variation_id = $variation_id;
		$this->quantity     = $quantity;
	}

	public function get_type(): string {
		return RuleTypes::ACTION_FREE_GIFT_PRODUCT;
	}

	public function get_product_id(): int {
		return $this->product_id;
	}

	public function get_variation_id(): ?int {
		return $this->variation_id;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}

	public function preview( EvaluationContext $context ): ActionResult {
		$payload = array(
			'product_id' => $this->product_id,
		);

		if ( $this->variation_id !== null ) {
			$payload['variation_id'] = $this->variation_id;
		}

		$payload['quantity'] = $this->quantity;

		return new ActionResult( $this->get_type(), $payload );
	}
}
