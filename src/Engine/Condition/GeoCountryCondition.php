<?php
/**
 * Condition: request-time visitor country (resolved by Universal Geo Context)
 * must be in configured ISO country codes.
 *
 * Country codes are normalized to uppercase for comparison. This is distinct
 * from billing_country: geo_country reflects where the visitor is detected to
 * be right now, not the address the customer typed in. There is no fallback
 * between the two.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class GeoCountryCondition implements ConditionInterface {

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
			throw new InvalidArgumentException( 'geo_country countries must contain at least one non-empty string.' );
		}

		$this->countries = $normalized;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_GEO_COUNTRY;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$metadata = $context->get_metadata();

		if ( ! isset( $metadata['geo_country'] ) || ! is_string( $metadata['geo_country'] ) ) {
			return ConditionResult::fail(
				'Visitor geo country is not available (geo_country metadata missing).',
				ConditionTrace::REASON_METADATA_MISSING,
				array( 'geo_country' => null )
			);
		}

		$actual = self::normalize_country_code( $metadata['geo_country'] );
		if ( $actual === '' ) {
			return ConditionResult::fail(
				'Visitor geo country is empty in evaluation context.',
				ConditionTrace::REASON_METADATA_MISSING,
				array( 'geo_country' => '' )
			);
		}

		$observed = array(
			'geo_country' => $actual,
			'countries'   => $this->countries,
		);

		if ( in_array( $actual, $this->countries, true ) ) {
			return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
		}

		return ConditionResult::fail(
			'Visitor geo country does not match allowed countries.',
			ConditionTrace::REASON_COUNTRY_NOT_MATCHED,
			$observed
		);
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
