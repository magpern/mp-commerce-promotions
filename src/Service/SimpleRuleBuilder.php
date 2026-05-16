<?php
/**
 * Builds a single-condition / single-action rules payload for the admin rule builder.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;
use MP\CommercePromotions\Engine\Action\FixedAmountDiscountAction;
use MP\CommercePromotions\Engine\Action\PercentageDiscountAction;
use MP\CommercePromotions\Engine\Condition\CategoryQuantityCondition;
use MP\CommercePromotions\Engine\Condition\MinimumSubtotalCondition;
use MP\CommercePromotions\Engine\Condition\ProductQuantityCondition;
use MP\CommercePromotions\Engine\Condition\QuantityComparator;
use MP\CommercePromotions\Engine\RuleRegistry;
use MP\CommercePromotions\Engine\RuleTypes;

final class SimpleRuleBuilder {

	/**
	 * @param array<string, mixed> $post Unslashed POST values (builder fields only).
	 * @return array{conditions: array<int, array<string, mixed>>, actions: array<int, array<string, mixed>>}
	 */
	public static function build_from_post( array $post ): array {
		$condition_type = isset( $post['mp_cp_builder_condition_type'] )
			? sanitize_text_field( (string) $post['mp_cp_builder_condition_type'] )
			: '';

		if ( ! RuleRegistry::is_supported_condition( $condition_type ) ) {
			throw new InvalidArgumentException( 'invalid_condition_type' );
		}

		$action_type = isset( $post['mp_cp_builder_action_type'] )
			? sanitize_text_field( (string) $post['mp_cp_builder_action_type'] )
			: '';

		if ( ! RuleRegistry::is_supported_action( $action_type ) ) {
			throw new InvalidArgumentException( 'invalid_action_type' );
		}

		$conditions = array( self::build_condition( $condition_type, $post ) );
		$actions    = array( self::build_action( $action_type, $post ) );

		return array(
			'conditions' => $conditions,
			'actions'    => $actions,
		);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private static function build_condition( string $type, array $post ): array {
		if ( $type === RuleTypes::CONDITION_MINIMUM_SUBTOTAL ) {
			$amount = self::parse_required_float( $post, 'mp_cp_builder_amount', 'invalid_amount' );
			new MinimumSubtotalCondition( $amount );

			return array(
				'type'   => RuleTypes::CONDITION_MINIMUM_SUBTOTAL,
				'amount' => $amount,
			);
		}

		$operator = self::parse_operator( $post );
		$quantity = self::parse_required_float( $post, 'mp_cp_builder_quantity', 'invalid_quantity' );

		if ( $type === RuleTypes::CONDITION_PRODUCT_QUANTITY ) {
			$product_id = self::parse_required_positive_int( $post, 'mp_cp_builder_product_id', 'invalid_product_id' );
			new ProductQuantityCondition( $product_id, $operator, $quantity );

			return array(
				'type'       => RuleTypes::CONDITION_PRODUCT_QUANTITY,
				'product_id' => $product_id,
				'operator'   => $operator,
				'quantity'   => $quantity,
			);
		}

		$category_id = self::parse_required_positive_int( $post, 'mp_cp_builder_category_id', 'invalid_category_id' );
		new CategoryQuantityCondition( $category_id, $operator, $quantity );

		return array(
			'type'        => RuleTypes::CONDITION_CATEGORY_QUANTITY,
			'category_id' => $category_id,
			'operator'    => $operator,
			'quantity'    => $quantity,
		);
	}

	/**
	 * @param array<string, mixed> $post
	 * @return array<string, mixed>
	 */
	private static function build_action( string $type, array $post ): array {
		if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
			$percentage = self::parse_required_float( $post, 'mp_cp_builder_percentage', 'invalid_percentage' );
			new PercentageDiscountAction( $percentage );

			return array(
				'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
				'percentage' => $percentage,
			);
		}

		$amount = self::parse_required_float( $post, 'mp_cp_builder_fixed_amount', 'invalid_fixed_amount' );
		new FixedAmountDiscountAction( $amount );

		return array(
			'type'   => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT,
			'amount' => $amount,
		);
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_operator( array $post ): string {
		$operator = isset( $post['mp_cp_builder_operator'] )
			? sanitize_text_field( (string) $post['mp_cp_builder_operator'] )
			: '';

		if ( ! QuantityComparator::supports( $operator ) ) {
			throw new InvalidArgumentException( 'invalid_operator' );
		}

		return $operator;
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_required_float( array $post, string $key, string $error_code ): float {
		if ( ! isset( $post[ $key ] ) || $post[ $key ] === '' || ! is_numeric( $post[ $key ] ) ) {
			throw new InvalidArgumentException( $error_code );
		}

		return (float) $post[ $key ];
	}

	/**
	 * @param array<string, mixed> $post
	 */
	private static function parse_required_positive_int( array $post, string $key, string $error_code ): int {
		if ( ! isset( $post[ $key ] ) || $post[ $key ] === '' || ! is_numeric( $post[ $key ] ) ) {
			throw new InvalidArgumentException( $error_code );
		}

		$value = (int) $post[ $key ];
		if ( $value <= 0 ) {
			throw new InvalidArgumentException( $error_code );
		}

		return $value;
	}
}
