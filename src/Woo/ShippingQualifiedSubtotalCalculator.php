<?php
/**
 * Paid shippable merchandise subtotal for free-shipping progress and promotions.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\EligibleCartScope;
use MP\CommercePromotions\Engine\EvaluationContext;
use MP\CommercePromotions\GiftCard\GiftCardPromotionExclusion;

final class ShippingQualifiedSubtotalCalculator {

	public const REASON_GIFT_CARD = 'gift_card_product';

	public const REASON_NON_SHIPPABLE = 'non_shippable';

	public const REASON_FREE_GIFT = 'free_gift';

	public const REASON_FREE_PROMOTIONAL_UNIT = 'free_promotional_unit';

	public const REASON_FULLY_DISCOUNTED_UNIT = 'fully_discounted_unit';

	public const REASON_DISCOUNT_ALLOCATION = 'discount_allocation';

	public const TRACE_QUALIFYING = 'qualifying_shipping_subtotal';

	public const TRACE_EXCLUDED = 'shipping_excluded_subtotal';

	public const TRACE_EXCLUDED_COUNT = 'shipping_excluded_items_count';

	public const TRACE_REASONS = 'shipping_exclusion_reasons';

	/** @var array<string, float> */
	private const EMPTY_REASONS = array(
		self::REASON_GIFT_CARD              => 0.0,
		self::REASON_NON_SHIPPABLE          => 0.0,
		self::REASON_FREE_GIFT              => 0.0,
		self::REASON_FREE_PROMOTIONAL_UNIT  => 0.0,
		self::REASON_FULLY_DISCOUNTED_UNIT  => 0.0,
		self::REASON_DISCOUNT_ALLOCATION    => 0.0,
	);

	private static ?PromotionRepository $promotions = null;

	/**
	 * @param list<array<string, mixed>> $items Normalized cart rows (see {@see self::row_from_wc_cart_item()}).
	 * @param EvaluationContext|null     $context Optional evaluation context for BOGO previews.
	 * @param list<Promotion>            $cheapest_item_promotions Optional promotions for BOGO exclusion (e.g. unit tests).
	 * @return array{
	 *   qualifying_shipping_subtotal: float,
	 *   shipping_excluded_subtotal: float,
	 *   shipping_excluded_items_count: int,
	 *   shipping_exclusion_reasons: array<string, float>,
	 *   has_qualifying_shipping_items: bool,
	 *   gift_card_products_excluded_from_shipping_count: int,
	 *   gift_card_products_excluded_from_shipping_subtotal: float
	 * }
	 */
	public static function calculate( array $items, ?EvaluationContext $context = null, array $cheapest_item_promotions = array() ): array {
		$reasons         = self::EMPTY_REASONS;
		$qualifying      = 0.0;
		$excluded_count  = 0;
		$gift_stats      = GiftCardPromotionExclusion::exclusion_stats( $items );

		if ( $context === null ) {
			$context = new EvaluationContext( null, null, null, $items, array( 'source' => 'shipping_qualified_subtotal' ) );
		}

		$line_discounts = self::line_discount_exclusions_by_item_key();
		$bogo_discounts = self::cheapest_item_exclusions_by_item_key( $items, $context, $cheapest_item_promotions );

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$line_subtotal = self::line_subtotal( $item );
			if ( $line_subtotal <= 0.0001 ) {
				if ( self::is_free_gift_line( $item ) ) {
					$reasons[ self::REASON_FREE_GIFT ] += $line_subtotal;
				} else {
					$reasons[ self::REASON_FULLY_DISCOUNTED_UNIT ] += $line_subtotal;
				}
				++$excluded_count;
				continue;
			}

			if ( GiftCardPromotionExclusion::line_is_gift_card_product( $item ) ) {
				$reasons[ self::REASON_GIFT_CARD ] += $line_subtotal;
				++$excluded_count;
				continue;
			}

			if ( ! self::line_needs_shipping( $item ) ) {
				$reasons[ self::REASON_NON_SHIPPABLE ] += $line_subtotal;
				++$excluded_count;
				continue;
			}

			if ( self::is_free_gift_line( $item ) ) {
				$reasons[ self::REASON_FREE_GIFT ] += $line_subtotal;
				++$excluded_count;
				continue;
			}

			$item_key           = isset( $item['item_key'] ) ? (string) $item['item_key'] : '';
			$promotional_exempt = 0.0;
			if ( $item_key !== '' ) {
				$promotional_exempt += (float) ( $bogo_discounts[ $item_key ] ?? 0.0 );
				$promotional_exempt += (float) ( $line_discounts[ $item_key ] ?? 0.0 );
			}

			$promotional_exempt = min( $line_subtotal, max( 0.0, $promotional_exempt ) );
			$paid               = max( 0.0, round( $line_subtotal - $promotional_exempt, 4 ) );

			if ( $paid <= 0.0001 ) {
				$reasons[ self::REASON_FREE_PROMOTIONAL_UNIT ] += $line_subtotal;
				++$excluded_count;
				continue;
			}

			if ( $promotional_exempt > 0.0 ) {
				$reasons[ self::REASON_DISCOUNT_ALLOCATION ] += $promotional_exempt;
				if ( abs( $promotional_exempt - $line_subtotal ) < 0.0001 ) {
					$reasons[ self::REASON_FREE_PROMOTIONAL_UNIT ] += $promotional_exempt;
				}
			}

			$qualifying += $paid;
		}

		foreach ( $reasons as $key => $amount ) {
			$reasons[ $key ] = round( $amount, 4 );
		}

		$excluded_subtotal = round( array_sum( $reasons ), 4 );
		$qualifying        = round( $qualifying, 4 );

		return array(
			self::TRACE_QUALIFYING          => $qualifying,
			self::TRACE_EXCLUDED            => $excluded_subtotal,
			self::TRACE_EXCLUDED_COUNT      => $excluded_count,
			self::TRACE_REASONS             => $reasons,
			'has_qualifying_shipping_items' => $qualifying > 0.0,
			CartShippingEligibilitySubtotal::TRACE_GIFT_COUNT_KEY    => $gift_stats['count'],
			CartShippingEligibilitySubtotal::TRACE_GIFT_SUBTOTAL_KEY => $gift_stats['subtotal'],
		);
	}

	/**
	 * @return array<string, float>
	 */
	public static function stats_from_cart(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! method_exists( WC()->cart, 'get_cart' ) ) {
			return self::calculate( array() );
		}

		$items = array();
		$raw   = WC()->cart->get_cart();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $cart_item_key => $cart_item ) {
				if ( ! is_array( $cart_item ) ) {
					continue;
				}
				$row = self::row_from_wc_cart_item( $cart_item, is_string( $cart_item_key ) ? $cart_item_key : null );
				if ( $row !== null ) {
					$items[] = $row;
				}
			}
		}

		return self::calculate( $items );
	}

	/**
	 * @param array<string, mixed> $cart_item
	 * @return array<string, mixed>|null
	 */
	public static function row_from_wc_cart_item( array $cart_item, ?string $cart_item_key = null ): ?array {
		$product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
		if ( $product_id <= 0 ) {
			return null;
		}

		$variation = isset( $cart_item['variation_id'] ) && (int) $cart_item['variation_id'] > 0
			? (int) $cart_item['variation_id']
			: null;

		$quantity = isset( $cart_item['quantity'] ) ? (float) $cart_item['quantity'] : 0.0;

		$line_subtotal = 0.0;
		if ( isset( $cart_item['line_subtotal'] ) && is_numeric( $cart_item['line_subtotal'] ) ) {
			$line_subtotal = (float) $cart_item['line_subtotal'];
		}

		$unit_price = 0.0;
		if ( $quantity > 0 && $line_subtotal >= 0 ) {
			$unit_price = $line_subtotal / $quantity;
		}

		$row = array(
			'product_id'           => $product_id,
			'variation_id'         => $variation,
			'quantity'             => $quantity,
			'line_subtotal'        => $line_subtotal,
			'unit_price'           => $unit_price,
			'needs_shipping'       => CartShippingEligibilitySubtotal::wc_cart_item_needs_shipping( $cart_item ),
			'is_gift_card_product' => GiftCardPromotionExclusion::wc_cart_item_is_gift_card( $cart_item ),
			'is_free_gift'         => self::wc_cart_item_is_free_gift( $cart_item ),
		);

		if ( $cart_item_key !== null && $cart_item_key !== '' ) {
			$row['item_key'] = $cart_item_key;
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	public static function is_free_gift_line( array $item ): bool {
		if ( ! empty( $item['is_free_gift'] ) ) {
			return true;
		}

		return isset( $item['mp_cp_free_gift'] ) && $item['mp_cp_free_gift'] === 'yes';
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function line_needs_shipping( array $item ): bool {
		if ( array_key_exists( 'needs_shipping', $item ) ) {
			return (bool) $item['needs_shipping'];
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $item
	 */
	private static function line_subtotal( array $item ): float {
		if ( isset( $item['line_subtotal'] ) && is_numeric( $item['line_subtotal'] ) ) {
			return max( 0.0, (float) $item['line_subtotal'] );
		}

		return 0.0;
	}

	/**
	 * @param array<string, mixed> $cart_item
	 */
	private static function wc_cart_item_is_free_gift( array $cart_item ): bool {
		return ! empty( $cart_item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] )
			&& $cart_item[ FreeGiftCartHandler::CART_ITEM_META_FREE_GIFT ] === 'yes';
	}

	/**
	 * @return array<string, float>
	 */
	private static function line_discount_exclusions_by_item_key(): array {
		$payload = CartSessionHelper::get_line_allocations();
		if ( ! is_array( $payload ) || ! isset( $payload['line_allocations'] ) || ! is_array( $payload['line_allocations'] ) ) {
			return array();
		}

		$map = array();
		foreach ( $payload['line_allocations'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$key = isset( $row['line_key'] ) ? (string) $row['line_key'] : '';
			if ( $key === '' || ! isset( $row['amount'] ) || ! is_numeric( $row['amount'] ) ) {
				continue;
			}
			$map[ $key ] = ( $map[ $key ] ?? 0.0 ) + max( 0.0, (float) $row['amount'] );
		}

		return $map;
	}

	/**
	 * @param list<array<string, mixed>> $items
	 * @return array<string, float>
	 */
	/**
	 * @param list<Promotion> $cheapest_item_promotions
	 * @return array<string, float>
	 */
	private static function cheapest_item_exclusions_by_item_key( array $items, EvaluationContext $context, array $cheapest_item_promotions = array() ): array {
		$map = array();

		$promotions = $cheapest_item_promotions !== array()
			? $cheapest_item_promotions
			: self::cheapest_item_promotions_for_context( $context );

		foreach ( $promotions as $promotion ) {
			$action = self::first_cheapest_item_action( $promotion );
			if ( $action === null ) {
				continue;
			}

			try {
				$cheapest_action = CheapestItemDiscountAction::from_config( $action );
			} catch ( \InvalidArgumentException $e ) {
				continue;
			}

			$preview = $cheapest_action->preview( $context )->get_payload();
			if ( ! empty( $preview['not_applicable'] ) ) {
				continue;
			}

			$product_ids   = $cheapest_action->get_scope() === CheapestItemDiscountAction::SCOPE_PRODUCTS
				? ( $action['product_ids'] ?? array() )
				: array();
			$variation_ids = $cheapest_action->get_scope() === CheapestItemDiscountAction::SCOPE_PRODUCTS
				? ( $action['variation_ids'] ?? array() )
				: array();
			$category_ids  = $cheapest_action->get_scope() === CheapestItemDiscountAction::SCOPE_CATEGORY
				? ( $action['category_ids'] ?? array() )
				: array();

			$eligible_items = EligibleCartScope::filter_items(
				GiftCardPromotionExclusion::without_gift_card_products( $context->get_items() ),
				is_array( $product_ids ) ? $product_ids : array(),
				is_array( $variation_ids ) ? $variation_ids : array(),
				is_array( $category_ids ) ? $category_ids : array(),
				array(),
				array(),
				! empty( $action['exclude_sale_items'] )
			);

			$cheapest_units = EligibleCartScope::cheapest_units(
				$eligible_items,
				(int) ( $action['discounted_quantity'] ?? 1 )
			);

			$discount_pct = isset( $action['discount_percentage'] ) ? (float) $action['discount_percentage'] : 0.0;
			foreach ( $cheapest_units as $unit ) {
				$unit_price = isset( $unit['unit_price'] ) ? (float) $unit['unit_price'] : 0.0;
				if ( $unit_price <= 0 ) {
					continue;
				}

				$excluded_value = $unit_price * ( $discount_pct / 100.0 );
				$key            = self::item_key_for_unit( $unit, $items );
				if ( $key === '' ) {
					continue;
				}

				$map[ $key ] = ( $map[ $key ] ?? 0.0 ) + $excluded_value;
			}
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $unit
	 * @param list<array<string, mixed>> $items
	 */
	private static function item_key_for_unit( array $unit, array $items ): string {
		if ( isset( $unit['item_key'] ) && is_string( $unit['item_key'] ) && $unit['item_key'] !== '' ) {
			return $unit['item_key'];
		}

		if ( isset( $unit['source_item'] ) && is_array( $unit['source_item'] ) && isset( $unit['source_item']['item_key'] ) ) {
			return (string) $unit['source_item']['item_key'];
		}

		$product_id = isset( $unit['product_id'] ) ? (int) $unit['product_id'] : 0;
		$variation  = isset( $unit['variation_id'] ) && is_numeric( $unit['variation_id'] )
			? (int) $unit['variation_id']
			: 0;

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['item_key'] ) ) {
				continue;
			}
			$item_product = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			$item_var     = isset( $item['variation_id'] ) && is_numeric( $item['variation_id'] )
				? (int) $item['variation_id']
				: 0;
			if ( $item_product === $product_id && $item_var === $variation ) {
				return (string) $item['item_key'];
			}
		}

		return '';
	}

	/**
	 * @return list<Promotion>
	 */
	private static function cheapest_item_promotions_for_context( EvaluationContext $context ): array {
		$promotions = array();
		$seen       = array();

		foreach ( AppliedPromotionSession::entries_from_session( CartSessionHelper::get_applied_promotion() ) as $entry ) {
			$action_type = isset( $entry['action_type'] ) ? (string) $entry['action_type'] : '';
			if ( $action_type !== CartPromotionApplier::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
				continue;
			}
			$promotion_id = isset( $entry['promotion_id'] ) ? (int) $entry['promotion_id'] : 0;
			if ( $promotion_id <= 0 || isset( $seen[ $promotion_id ] ) ) {
				continue;
			}
			$promotion = self::promotion_repository()->find( $promotion_id );
			if ( $promotion === null ) {
				continue;
			}
			$seen[ $promotion_id ] = true;
			$promotions[]          = $promotion;
		}

		return $promotions;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function first_cheapest_item_action( Promotion $promotion ): ?array {
		$actions = $promotion->get_actions();
		if ( ! is_array( $actions ) ) {
			return null;
		}

		foreach ( $actions as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = isset( $action['type'] ) ? (string) $action['type'] : '';
			if ( $type === CartPromotionApplier::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
				return $action;
			}
		}

		return null;
	}

	private static function promotion_repository(): PromotionRepository {
		if ( self::$promotions === null ) {
			global $wpdb;
			self::$promotions = new PromotionRepository( $wpdb );
		}

		return self::$promotions;
	}

	private function __construct() {
	}
}
