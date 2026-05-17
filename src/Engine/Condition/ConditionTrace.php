<?php
/**
 * Structured trace for a single condition evaluation step.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;

final class ConditionTrace {

	public const REASON_PASSED              = 'condition_passed';
	public const REASON_FAILED              = 'condition_failed';
	public const REASON_INVALID             = 'condition_invalid';
	public const REASON_UNKNOWN             = 'condition_unknown';
	public const REASON_METADATA_MISSING    = 'metadata_missing';
	public const REASON_CART_VALUE_TOO_LOW  = 'cart_value_too_low';
	public const REASON_QUANTITY_NOT_MET    = 'quantity_not_met';
	public const REASON_NOT_LOGGED_IN       = 'customer_not_logged_in';
	public const REASON_PREVIOUS_ORDER      = 'previous_order_exists';
	public const REASON_ROLE_NOT_MATCHED    = 'role_not_matched';
	public const REASON_COUNTRY_NOT_MATCHED = 'country_not_matched';
	public const REASON_EMAIL_DOMAIN        = 'email_domain_not_matched';
	public const REASON_REDEMPTION_COUNT_NOT_MET = 'redemption_count_not_met';

	public const REASON_USAGE_LIMIT_REACHED = 'usage_limit_reached';

	public const REASON_CUSTOMER_USAGE_LIMIT_REACHED = 'customer_usage_limit_reached';

	public const REASON_CUSTOMER_REQUIRED_FOR_USAGE_TRACKING = 'customer_required_for_usage_tracking';

	public const REASON_PROMOTION_NOT_STARTED = 'promotion_not_started';

	public const REASON_PROMOTION_EXPIRED = 'promotion_expired';

	public const REASON_PROMOTION_BUDGET_EXHAUSTED = 'promotion_budget_exhausted';

	public const REASON_REQUIRED_PRODUCT_MISSING = 'required_product_missing';

	public const REASON_REQUIRED_CATEGORY_MISSING = 'required_category_missing';

	public const REASON_SALE_ITEMS_PRESENT = 'sale_items_present';

	public const REASON_ELIGIBLE_SUBTOTAL_TOO_LOW = 'eligible_subtotal_too_low';

	public const REASON_ELIGIBLE_SUBTOTAL_TOO_HIGH = 'eligible_subtotal_too_high';

	private string $type;

	private bool $passed;

	private ?string $message;

	private string $reason_code;

	/** @var array<string, mixed> */
	private array $config;

	/** @var array<string, mixed> */
	private array $observed;

	/**
	 * @param array<string, mixed> $config
	 * @param array<string, mixed> $observed
	 */
	public function __construct(
		string $type,
		bool $passed,
		?string $message,
		string $reason_code,
		array $config,
		array $observed
	) {
		$type = trim( $type );
		if ( $type === '' ) {
			throw new InvalidArgumentException( 'ConditionTrace type must be a non-empty string.' );
		}

		$reason_code = trim( $reason_code );
		if ( $reason_code === '' ) {
			throw new InvalidArgumentException( 'ConditionTrace reason_code must be a non-empty string.' );
		}

		$this->type        = $type;
		$this->passed      = $passed;
		$this->message     = $message;
		$this->reason_code = $reason_code;
		$this->config      = $config;
		$this->observed    = $observed;
	}

	public function get_type(): string {
		return $this->type;
	}

	public function passed(): bool {
		return $this->passed;
	}

	public function get_message(): ?string {
		return $this->message;
	}

	public function get_reason_code(): string {
		return $this->reason_code;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_observed(): array {
		return $this->observed;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'type'        => $this->type,
			'passed'      => $this->passed,
			'message'     => $this->message,
			'reason_code' => $this->reason_code,
			'config'      => $this->config,
			'observed'    => $this->observed,
		);
	}
}
