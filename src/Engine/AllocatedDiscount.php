<?php
/**
 * Per-target discount allocation slice.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine;

final class AllocatedDiscount {

	public const TARGET_LINE = 'line';

	public const TARGET_SHIPPING = 'shipping';

	private int $promotion_id;

	private string $target_type;

	private ?string $line_key;

	private ?int $product_id;

	private float $amount;

	private float $share_percent;

	/** @var array<string, mixed> */
	private array $metadata;

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function __construct(
		int $promotion_id,
		string $target_type,
		?string $line_key,
		?int $product_id,
		float $amount,
		float $share_percent,
		array $metadata = array()
	) {
		$this->promotion_id  = $promotion_id;
		$this->target_type   = $target_type;
		$this->line_key      = $line_key;
		$this->product_id    = $product_id;
		$this->amount        = max( 0.0, $amount );
		$this->share_percent = max( 0.0, min( 100.0, $share_percent ) );
		$this->metadata      = $metadata;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'promotion_id'  => $this->promotion_id,
			'target_type'   => $this->target_type,
			'line_key'      => $this->line_key,
			'product_id'    => $this->product_id,
			'amount'        => round( $this->amount, 4 ),
			'share_percent' => round( $this->share_percent, 4 ),
			'metadata'      => $this->metadata,
		);
	}

	public function get_amount(): float {
		return $this->amount;
	}
}
