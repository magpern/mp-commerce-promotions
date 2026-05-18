<?php
/**
 * Browser/checkout certification record.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Domain;

final class CertificationRun {

	public const TYPE_CLASSIC_CHECKOUT = 'classic_checkout';

	public const TYPE_BLOCKS_CHECKOUT = 'blocks_checkout';

	public const TYPE_LINE_MODE = 'line_mode';

	public const TYPE_COUPON_COEXISTENCE = 'coupon_coexistence';

	public const STATUS_PASSED = 'passed';

	public const STATUS_FAILED = 'failed';

	public const STATUS_PARTIAL = 'partial';

	private ?int $id;

	private string $certification_type;

	private string $status;

	private ?string $environment;

	private ?string $payment_gateway;

	private ?string $operator_notes;

	/** @var array<string, mixed> */
	private array $metadata;

	private string $certified_at;

	private ?int $created_by;

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function __construct(
		?int $id,
		string $certification_type,
		string $status,
		?string $environment,
		?string $payment_gateway,
		?string $operator_notes,
		array $metadata,
		string $certified_at,
		?int $created_by = null
	) {
		$this->id                  = $id;
		$this->certification_type  = $certification_type;
		$this->status              = $status;
		$this->environment         = $environment;
		$this->payment_gateway     = $payment_gateway;
		$this->operator_notes      = $operator_notes;
		$this->metadata            = $metadata;
		$this->certified_at        = $certified_at;
		$this->created_by          = $created_by;
	}

	/**
	 * @return list<string>
	 */
	public static function allowed_types(): array {
		return array(
			self::TYPE_CLASSIC_CHECKOUT,
			self::TYPE_BLOCKS_CHECKOUT,
			self::TYPE_LINE_MODE,
			self::TYPE_COUPON_COEXISTENCE,
		);
	}

	public function get_id(): ?int {
		return $this->id;
	}

	public function get_certification_type(): string {
		return $this->certification_type;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_environment(): ?string {
		return $this->environment;
	}

	public function get_payment_gateway(): ?string {
		return $this->payment_gateway;
	}

	public function get_operator_notes(): ?string {
		return $this->operator_notes;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_metadata(): array {
		return $this->metadata;
	}

	public function get_certified_at(): string {
		return $this->certified_at;
	}

	public function get_created_by(): ?int {
		return $this->created_by;
	}
}
