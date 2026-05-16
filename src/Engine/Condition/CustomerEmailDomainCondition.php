<?php
/**
 * Condition: customer email domain must match configured domains.
 *
 * Domains are compared case-insensitively (normalized to lowercase).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class CustomerEmailDomainCondition implements ConditionInterface {

	/**
	 * @var list<string>
	 */
	private array $domains;

	/**
	 * @param array<mixed> $domains Email domains from promotion JSON (no @).
	 */
	public function __construct( array $domains ) {
		$normalized = self::normalize_domain_list( $domains );
		if ( $normalized === array() ) {
			throw new InvalidArgumentException( 'customer_email_domain domains must contain at least one valid domain string.' );
		}

		$this->domains = $normalized;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CUSTOMER_EMAIL_DOMAIN;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$metadata = $context->get_metadata();

		if ( ! isset( $metadata['customer_email'] ) || ! is_string( $metadata['customer_email'] ) ) {
			return ConditionResult::fail( 'Customer email is not available (customer_email metadata missing).' );
		}

		$domain = self::extract_domain( $metadata['customer_email'] );
		if ( $domain === '' ) {
			return ConditionResult::fail( 'Customer email is invalid or has no domain.' );
		}

		if ( in_array( $domain, $this->domains, true ) ) {
			return ConditionResult::pass();
		}

		return ConditionResult::fail( 'Customer email domain does not match allowed domains.' );
	}

	/**
	 * @param array<mixed> $domains
	 * @return list<string>
	 */
	private static function normalize_domain_list( array $domains ): array {
		$out = array();
		foreach ( $domains as $domain ) {
			if ( ! is_string( $domain ) ) {
				continue;
			}
			$domain = strtolower( trim( $domain ) );
			if ( $domain === '' || strpos( $domain, '@' ) !== false ) {
				continue;
			}
			$out[] = $domain;
		}

		return array_values( array_unique( $out ) );
	}

	private static function extract_domain( string $email ): string {
		$email = trim( $email );
		if ( $email === '' || strpos( $email, '@' ) === false ) {
			return '';
		}

		$parts = explode( '@', $email );
		$domain = strtolower( trim( (string) end( $parts ) ) );
		if ( $domain === '' || strpos( $domain, '@' ) !== false ) {
			return '';
		}

		return $domain;
	}
}
