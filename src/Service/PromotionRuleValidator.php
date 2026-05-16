<?php
/**
 * Read-only validation of promotion conditions/actions against supported engine types.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;

final class PromotionRuleValidator {

	/**
	 * @var list<string>
	 */
	private const SUPPORTED_CONDITION_TYPES = array(
		'minimum_subtotal',
		'product_quantity',
		'category_quantity',
	);

	/**
	 * @var list<string>
	 */
	private const SUPPORTED_ACTION_TYPES = array(
		'percentage_discount',
		'fixed_amount_discount',
	);

	/**
	 * @return list<array{level: string, message: string}>
	 */
	public function validate( Promotion $promotion ): array {
		$issues = array();

		$this->append_status_issues( $promotion, $issues );
		$this->append_condition_issues( $promotion->get_conditions(), $issues );
		$this->append_action_issues( $promotion->get_actions(), $issues );

		return $issues;
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_status_issues( Promotion $promotion, array &$issues ): void {
		$status = $promotion->get_status();

		if ( $status === PromotionStatus::ARCHIVED ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Archived promotions do not run.', 'mp-commerce-promotions' ),
			);
			return;
		}

		if ( $status === PromotionStatus::DRAFT || $status === PromotionStatus::PAUSED ) {
			$issues[] = array(
				'level'   => 'info',
				'message' => __( 'Promotion is not active and will not run.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<mixed>                                  $conditions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_condition_issues( array $conditions, array &$issues ): void {
		if ( count( $conditions ) === 0 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Promotion has no conditions and may apply broadly.', 'mp-commerce-promotions' ),
			);
			return;
		}

		foreach ( $conditions as $index => $raw ) {
			$this->validate_condition_entry( (int) $index, $raw, $issues );
		}
	}

	/**
	 * @param mixed                                         $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_condition_entry( int $index, $raw, array &$issues ): void {
		if ( ! is_array( $raw ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'Condition at index %s must be an object.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
		if ( $type === '' ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'Condition at index %s has no type.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		if ( ! in_array( $type, self::SUPPORTED_CONDITION_TYPES, true ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: condition type string */
					__( 'Unknown condition type: %s', 'mp-commerce-promotions' ),
					$type
				)
			);
			return;
		}

		if ( $type === 'minimum_subtotal' ) {
			$this->validate_minimum_subtotal( $index, $raw, $issues );
			return;
		}

		if ( $type === 'product_quantity' ) {
			$this->validate_quantity_condition( $index, $raw, 'product_quantity', 'product_id', $issues );
			return;
		}

		$this->validate_quantity_condition( $index, $raw, 'category_quantity', 'category_id', $issues );
	}

	/**
	 * @param array<string, mixed>                          $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_minimum_subtotal( int $index, array $raw, array &$issues ): void {
		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'minimum_subtotal at index %s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new MinimumSubtotalCondition( (float) $raw['amount'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based condition index */
					__( 'minimum_subtotal at index %s has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<string, mixed>                          $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_quantity_condition(
		int $index,
		array $raw,
		string $type_label,
		string $id_key,
		array &$issues
	): void {
		if ( ! isset( $raw[ $id_key ] ) || ! is_numeric( $raw[ $id_key ] ) || (int) $raw[ $id_key ] <= 0 ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid %3$s.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index,
					$id_key
				)
			);
			return;
		}

		if ( ! isset( $raw['operator'] ) || ! is_string( $raw['operator'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid operator.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		$operator = trim( $raw['operator'] );
		if ( ! QuantityComparator::supports( $operator ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has an unsupported operator.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		if ( ! isset( $raw['quantity'] ) || ! is_numeric( $raw['quantity'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s is missing or has an invalid quantity.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
			return;
		}

		try {
			if ( $type_label === 'product_quantity' ) {
				new ProductQuantityCondition( (int) $raw[ $id_key ], $operator, (float) $raw['quantity'] );
			} else {
				new CategoryQuantityCondition( (int) $raw[ $id_key ], $operator, (float) $raw['quantity'] );
			}
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: 1: condition type, 2: index */
					__( '%1$s at index %2$s has invalid field values.', 'mp-commerce-promotions' ),
					$type_label,
					(string) $index
				)
			);
		}
	}

	/**
	 * @param array<mixed>                                  $actions
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function append_action_issues( array $actions, array &$issues ): void {
		if ( count( $actions ) === 0 ) {
			$issues[] = $this->error(
				__( 'Promotion has no actions.', 'mp-commerce-promotions' )
			);
			return;
		}

		$supported_count = 0;

		foreach ( $actions as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'Action at index %s must be an object.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				continue;
			}

			$type = isset( $raw['type'] ) ? trim( (string) $raw['type'] ) : '';
			if ( $type === '' ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'Action at index %s has no type.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				continue;
			}

			if ( ! in_array( $type, self::SUPPORTED_ACTION_TYPES, true ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: action type string */
						__( 'Unknown action type: %s', 'mp-commerce-promotions' ),
						$type
					)
				);
				continue;
			}

			++$supported_count;
			$this->validate_supported_action( (int) $index, $type, $raw, $issues );
		}

		if ( $supported_count > 1 ) {
			$issues[] = array(
				'level'   => 'warning',
				'message' => __( 'Only the first supported action is applied in v1.', 'mp-commerce-promotions' ),
			);
		}
	}

	/**
	 * @param array<string, mixed>                          $raw
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function validate_supported_action( int $index, string $type, array $raw, array &$issues ): void {
		if ( $type === 'percentage_discount' ) {
			if ( ! isset( $raw['percentage'] ) || ! is_numeric( $raw['percentage'] ) ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'percentage_discount at index %s is missing or has an invalid percentage.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
				return;
			}

			try {
				new PercentageDiscountAction( (float) $raw['percentage'] );
			} catch ( InvalidArgumentException $e ) {
				$issues[] = $this->error(
					sprintf(
						/* translators: %s: zero-based action index */
						__( 'percentage_discount at index %s has an invalid percentage.', 'mp-commerce-promotions' ),
						(string) $index
					)
				);
			}
			return;
		}

		if ( ! isset( $raw['amount'] ) || ! is_numeric( $raw['amount'] ) ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'fixed_amount_discount at index %s is missing or has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
			return;
		}

		try {
			new FixedAmountDiscountAction( (float) $raw['amount'] );
		} catch ( InvalidArgumentException $e ) {
			$issues[] = $this->error(
				sprintf(
					/* translators: %s: zero-based action index */
					__( 'fixed_amount_discount at index %s has an invalid amount.', 'mp-commerce-promotions' ),
					(string) $index
				)
			);
		}
	}

	/**
	 * @return array{level: string, message: string}
	 */
	private function error( string $message ): array {
		return array(
			'level'   => 'error',
			'message' => $message,
		);
	}
}
