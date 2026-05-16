<?php
/**
 * Generated promotion code batch metadata (plain codes are not stored).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class PromotionCodeBatch {

	public const MAX_QUANTITY = 1000;

	private ?int $id;

	private int $promotion_id;

	private string $batch_uuid;

	private string $name;

	private int $quantity;

	private ?string $code_prefix;

	private ?int $usage_limit;

	private ?string $expires_at;

	private ?int $created_by;

	private ?string $created_at;

	public function __construct(
		?int $id,
		int $promotion_id,
		string $batch_uuid,
		string $name,
		int $quantity,
		?string $code_prefix,
		?int $usage_limit,
		?string $expires_at,
		?int $created_by,
		?string $created_at
	) {
		if ( $promotion_id <= 0 ) {
			throw new InvalidArgumentException( 'PromotionCodeBatch promotion_id must be > 0.' );
		}

		$batch_uuid = trim( $batch_uuid );
		if ( $batch_uuid === '' ) {
			throw new InvalidArgumentException( 'PromotionCodeBatch batch_uuid must not be empty.' );
		}

		$name = trim( $name );
		if ( $name === '' ) {
			throw new InvalidArgumentException( 'PromotionCodeBatch name must not be empty.' );
		}

		if ( $quantity <= 0 || $quantity > self::MAX_QUANTITY ) {
			throw new InvalidArgumentException(
				sprintf( 'PromotionCodeBatch quantity must be between 1 and %d.', self::MAX_QUANTITY )
			);
		}

		if ( $code_prefix !== null ) {
			$code_prefix = trim( $code_prefix );
			if ( $code_prefix === '' ) {
				$code_prefix = null;
			}
		}

		if ( $usage_limit !== null && $usage_limit < 0 ) {
			throw new InvalidArgumentException( 'PromotionCodeBatch usage_limit must be null or >= 0.' );
		}

		$this->id           = $id;
		$this->promotion_id = $promotion_id;
		$this->batch_uuid   = $batch_uuid;
		$this->name         = $name;
		$this->quantity     = $quantity;
		$this->code_prefix  = $code_prefix;
		$this->usage_limit  = $usage_limit;
		$this->expires_at   = $expires_at;
		$this->created_by   = $created_by;
		$this->created_at   = $created_at;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function from_array( array $data ): self {
		$raw_id = isset( $data['id'] ) ? (int) $data['id'] : 0;
		$id     = $raw_id > 0 ? $raw_id : null;

		$usage_limit = isset( $data['usage_limit'] ) && $data['usage_limit'] !== null && $data['usage_limit'] !== ''
			? (int) $data['usage_limit']
			: null;

		$created_by = isset( $data['created_by'] ) && $data['created_by'] !== null && $data['created_by'] !== ''
			? (int) $data['created_by']
			: null;
		if ( $created_by !== null && $created_by <= 0 ) {
			$created_by = null;
		}

		$code_prefix = isset( $data['code_prefix'] ) && is_string( $data['code_prefix'] ) && $data['code_prefix'] !== ''
			? $data['code_prefix']
			: null;

		return new self(
			$id,
			(int) ( $data['promotion_id'] ?? 0 ),
			(string) ( $data['batch_uuid'] ?? '' ),
			(string) ( $data['name'] ?? '' ),
			(int) ( $data['quantity'] ?? 0 ),
			$code_prefix,
			$usage_limit,
			self::optional_string( $data['expires_at'] ?? null ),
			$created_by,
			self::optional_string( $data['created_at'] ?? null )
		);
	}

	public function with_id( int $id ): self {
		if ( $id <= 0 ) {
			throw new InvalidArgumentException( 'PromotionCodeBatch id must be > 0.' );
		}

		return new self(
			$id,
			$this->promotion_id,
			$this->batch_uuid,
			$this->name,
			$this->quantity,
			$this->code_prefix,
			$this->usage_limit,
			$this->expires_at,
			$this->created_by,
			$this->created_at
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_promotion_id(): int {
		return $this->promotion_id;
	}

	public function get_batch_uuid(): string {
		return $this->batch_uuid;
	}

	public function get_name(): string {
		return $this->name;
	}

	public function get_quantity(): int {
		return $this->quantity;
	}

	public function get_code_prefix(): ?string {
		return $this->code_prefix;
	}

	public function get_usage_limit(): ?int {
		return $this->usage_limit;
	}

	public function get_expires_at(): ?string {
		return $this->expires_at;
	}

	public function get_created_by(): ?int {
		return $this->created_by;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	/**
	 * @param mixed $value
	 */
	private static function optional_string( $value ): ?string {
		if ( $value === null || $value === '' ) {
			return null;
		}

		return (string) $value;
	}
}
