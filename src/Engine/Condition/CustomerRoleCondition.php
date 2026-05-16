<?php
/**
 * Condition: customer has at least one configured WordPress role slug.
 *
 * Role slugs are compared case-insensitively (normalized to lowercase).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Engine\Condition;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\Engine\RuleTypes;

final class CustomerRoleCondition implements ConditionInterface {

	/**
	 * @var list<string>
	 */
	private array $required_roles;

	/**
	 * @param array<mixed> $roles WordPress role slugs from promotion JSON.
	 */
	public function __construct( array $roles ) {
		$normalized = self::normalize_role_list( $roles );
		if ( $normalized === array() ) {
			throw new InvalidArgumentException( 'customer_role roles must contain at least one non-empty string.' );
		}

		$this->required_roles = $normalized;
	}

	public function get_type(): string {
		return RuleTypes::CONDITION_CUSTOMER_ROLE;
	}

	public function evaluate( EvaluationContext $context ): ConditionResult {
		$metadata = $context->get_metadata();

		if ( ! isset( $metadata['customer_roles'] ) || ! is_array( $metadata['customer_roles'] ) ) {
			return ConditionResult::fail(
				'Customer roles are not available (customer_roles metadata missing).',
				ConditionTrace::REASON_METADATA_MISSING,
				array( 'customer_roles' => null )
			);
		}

		$actual = self::normalize_role_list( $metadata['customer_roles'] );
		if ( $actual === array() ) {
			return ConditionResult::fail(
				'Customer has no roles in evaluation context.',
				ConditionTrace::REASON_ROLE_NOT_MATCHED,
				array(
					'customer_roles' => array(),
					'required_roles' => $this->required_roles,
				)
			);
		}

		$observed = array(
			'customer_roles' => $actual,
			'required_roles' => $this->required_roles,
		);

		foreach ( $this->required_roles as $required ) {
			if ( in_array( $required, $actual, true ) ) {
				return ConditionResult::pass( null, ConditionTrace::REASON_PASSED, $observed );
			}
		}

		return ConditionResult::fail(
			'Customer does not have a required role.',
			ConditionTrace::REASON_ROLE_NOT_MATCHED,
			$observed
		);
	}

	/**
	 * @param array<mixed> $roles
	 * @return list<string>
	 */
	private static function normalize_role_list( array $roles ): array {
		$out = array();
		foreach ( $roles as $role ) {
			if ( ! is_string( $role ) ) {
				continue;
			}
			$role = strtolower( trim( $role ) );
			if ( $role === '' ) {
				continue;
			}
			$out[] = $role;
		}

		return array_values( array_unique( $out ) );
	}
}
