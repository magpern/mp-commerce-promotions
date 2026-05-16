<?php
/**
 * Condition: billing country must be in configured ISO country codes.
 *
 * Country codes are normalized to uppercase for comparison.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class BillingCountryCondition implements ConditionInterface {

	/**
	 * @var list<string>
	 */
	private array $countries;

	/**
	 * @param array<mixed> $countries ISO 3166-1 alpha-2 codes from promotion JSON.
	 */
	public function __construct( array $countries ) {
		$normalized = self::normalize_country_list( $countries );
		if ( $normalized === array() ) {
			throw new InvalidArgumentException( 'billing_country countries must contain at least one non-empty string.' );
		}

		$this->countries = $normalized;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_BILLING_COUNTRY;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$metadata = $context->get_metadata();

		if ( ! isset( $metadata['billing_country'] ) || ! is_string( $metadata['billing_country'] ) ) {
			return ConditionResult::fail( 'Billing country is not available (billing_country metadata missing).' );
		}

		$actual = self::normalize_country_code( $metadata['billing_country'] );
		if ( $actual === '' ) {
			return ConditionResult::fail( 'Billing country is empty in evaluation context.' );
		}

		if ( in_array( $actual, $this->countries, true ) ) {
			return ConditionResult::pass();
		}

		return ConditionResult::fail( 'Billing country does not match allowed countries.' );
	}

	/**
	 * @param array<mixed> $countries
	 * @return list<string>
	 */
	private static function normalize_country_list( array $countries ): array {
		$out = array();
		foreach ( $countries as $country ) {
			if ( ! is_string( $country ) ) {
				continue;
			}
			$code = self::normalize_country_code( $country );
			if ( $code === '' ) {
				continue;
			}
			$out[] = $code;
		}

		return array_values( array_unique( $out ) );
	}

	private static function normalize_country_code( string $country ): string {
		return strtoupper( trim( $country ) );
	}
}
