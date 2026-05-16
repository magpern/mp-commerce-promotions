<?php
/**
 * Manual promotion code row (plain code never stored).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

use InvalidArgumentException;

final class PromotionCode {

	public const STATUS_ACTIVE = 'active';

	public const STATUS_DISABLED = 'disabled';

	public const STATUS_EXPIRED = 'expired';

	/**
	 * @var list<string>
	 */
	private const ALLOWED_STATUSES = array(
		self::STATUS_ACTIVE,
		self::STATUS_DISABLED,
		self::STATUS_EXPIRED,
	);

	private ?int $id;

	private int $promotion_id;

	private string $code_hash;

	private string $code_last4;

	private string $status;

	private ?int $usage_limit;

	private int $usage_count;

	private ?string $expires_at;

	private ?string $created_at;

	private ?string $updated_at;

	public function __construct(
		?int $id,
		int $promotion_id,
		string $code_hash,
		string $code_last4,
		string $status,
		?int $usage_limit,
		int $usage_count,
		?string $expires_at,
		?string $created_at,
		?string $updated_at
	) {
		if ( $promotion_id <= 0 ) {
			throw new InvalidArgumentException( 'PromotionCode promotion_id must be > 0.' );
		}

		$code_hash = trim( $code_hash );
		if ( strlen( $code_hash ) !== 64 ) {
			throw new InvalidArgumentException( 'PromotionCode code_hash must be 64 characters.' );
		}

		$code_last4 = trim( $code_last4 );
		if ( $code_last4 === '' ) {
			throw new InvalidArgumentException( 'PromotionCode code_last4 must not be empty.' );
		}

		$status = trim( $status );
		if ( ! self::is_valid_status( $status ) ) {
			throw new InvalidArgumentException( 'Invalid promotion code status.' );
		}

		if ( $usage_count < 0 ) {
			throw new InvalidArgumentException( 'PromotionCode usage_count must be >= 0.' );
		}

		if ( $usage_limit !== null && $usage_limit < 0 ) {
			throw new InvalidArgumentException( 'PromotionCode usage_limit must be null or >= 0.' );
		}

		$this->id           = $id;
		$this->promotion_id = $promotion_id;
		$this->code_hash    = $code_hash;
		$this->code_last4   = $code_last4;
		$this->status       = $status;
		$this->usage_limit  = $usage_limit;
		$this->usage_count  = $usage_count;
		$this->expires_at   = $expires_at;
		$this->created_at   = $created_at;
		$this->updated_at   = $updated_at;
	}

	public static function is_valid_status( string $status ): bool {
		return in_array( $status, self::ALLOWED_STATUSES, true );
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

		return new self(
			$id,
			(int) ( $data['promotion_id'] ?? 0 ),
			(string) ( $data['code_hash'] ?? '' ),
			(string) ( $data['code_last4'] ?? '' ),
			(string) ( $data['status'] ?? '' ),
			$usage_limit,
			(int) ( $data['usage_count'] ?? 0 ),
			self::optional_string( $data['expires_at'] ?? null ),
			self::optional_string( $data['created_at'] ?? null ),
			self::optional_string( $data['updated_at'] ?? null )
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_promotion_id(): int {
		return $this->promotion_id;
	}

	public function get_code_hash(): string {
		return $this->code_hash;
	}

	public function get_code_last4(): string {
		return $this->code_last4;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_usage_limit(): ?int {
		return $this->usage_limit;
	}

	public function get_usage_count(): int {
		return $this->usage_count;
	}

	public function get_expires_at(): ?string {
		return $this->expires_at;
	}

	public function get_created_at(): ?string {
		return $this->created_at;
	}

	public function get_updated_at(): ?string {
		return $this->updated_at;
	}

	public function with_usage_count( int $usage_count ): self {
		return new self(
			$this->id,
			$this->promotion_id,
			$this->code_hash,
			$this->code_last4,
			$this->status,
			$this->usage_limit,
			$usage_count,
			$this->expires_at,
			$this->created_at,
			$this->updated_at
		);
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
