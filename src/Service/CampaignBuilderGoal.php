<?php
/**
 * Merchant Campaign Builder goal identifiers and template mapping metadata.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;

final class CampaignBuilderGoal {

	public const CATEGORY_DISCOUNT = 'category_discount';

	public const PRODUCT_DISCOUNT = 'product_discount';

	public const BUY_X_GET_Y = 'buy_x_get_y';

	public const FREE_SHIPPING = 'free_shipping';

	public const FREE_GIFT = 'free_gift';

	public const FIRST_ORDER = 'first_order_discount';

	public const VIP_ROLE = 'vip_role_discount';

	public const COUPON_CODE = 'coupon_code_campaign';

	public const BUDGETED = 'budgeted_campaign';

	public const SCHEDULED = 'scheduled_campaign';

	public const NOTES_PREFIX = 'campaign_builder_goal:';

	/**
	 * @return list<string>
	 */
	public static function all(): array {
		return array(
			self::CATEGORY_DISCOUNT,
			self::PRODUCT_DISCOUNT,
			self::BUY_X_GET_Y,
			self::FREE_SHIPPING,
			self::FREE_GIFT,
			self::FIRST_ORDER,
			self::VIP_ROLE,
			self::COUPON_CODE,
			self::BUDGETED,
			self::SCHEDULED,
		);
	}

	public static function sanitize( ?string $goal ): ?string {
		if ( $goal === null || $goal === '' ) {
			return null;
		}

		$goal = sanitize_key( $goal );

		return in_array( $goal, self::all(), true ) ? $goal : null;
	}

	/**
	 * @return array<string, array{
	 *     title: string,
	 *     description: string,
	 *     best_for: string,
	 *     icon: string,
	 *     template_key: string|null
	 * }>
	 */
	public static function definitions(): array {
		return array(
			self::CATEGORY_DISCOUNT => array(
				'title'        => __( 'Category discount', 'mp-commerce-promotions' ),
				'description'  => __( 'Percentage or fixed amount off products in selected categories.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Seasonal category sales (e.g. clothing, supplements).', 'mp-commerce-promotions' ),
				'icon'         => 'tag',
				'template_key' => PromotionTemplate::TEMPLATE_PERCENT_OFF_CATEGORY,
			),
			self::PRODUCT_DISCOUNT  => array(
				'title'        => __( 'Product discount', 'mp-commerce-promotions' ),
				'description'  => __( 'Percentage or fixed amount off specific products.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Clearance SKUs or hero products.', 'mp-commerce-promotions' ),
				'icon'         => 'products',
				'template_key' => PromotionTemplate::TEMPLATE_FIXED_OFF_PRODUCTS,
			),
			self::BUY_X_GET_Y       => array(
				'title'        => __( 'Buy X get Y', 'mp-commerce-promotions' ),
				'description'  => __( 'Buy a quantity in scope and discount the cheapest units.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'BOGO-style offers on a category or product set.', 'mp-commerce-promotions' ),
				'icon'         => 'cart',
				'template_key' => PromotionTemplate::TEMPLATE_BUY_X_GET_Y_CHEAPEST_FREE,
			),
			self::FREE_SHIPPING     => array(
				'title'        => __( 'Free shipping', 'mp-commerce-promotions' ),
				'description'  => __( 'Waive shipping when the cart reaches a minimum subtotal.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Threshold-based shipping promotions.', 'mp-commerce-promotions' ),
				'icon'         => 'car',
				'template_key' => PromotionTemplate::TEMPLATE_FREE_SHIPPING_OVER_SUBTOTAL,
			),
			self::FREE_GIFT         => array(
				'title'        => __( 'Free gift', 'mp-commerce-promotions' ),
				'description'  => __( 'Add a gift product when the order subtotal is high enough.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Gift-with-purchase campaigns.', 'mp-commerce-promotions' ),
				'icon'         => 'gift',
				'template_key' => PromotionTemplate::TEMPLATE_FREE_GIFT_OVER_SUBTOTAL,
			),
			self::FIRST_ORDER       => array(
				'title'        => __( 'First order discount', 'mp-commerce-promotions' ),
				'description'  => __( 'Welcome discount for customers with no previous orders.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'New customer acquisition.', 'mp-commerce-promotions' ),
				'icon'         => 'welcome',
				'template_key' => PromotionTemplate::TEMPLATE_FIRST_ORDER_DISCOUNT,
			),
			self::VIP_ROLE          => array(
				'title'        => __( 'VIP / role discount', 'mp-commerce-promotions' ),
				'description'  => __( 'Whole-cart discount for selected customer roles.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Wholesale, VIP, or member pricing.', 'mp-commerce-promotions' ),
				'icon'         => 'groups',
				'template_key' => PromotionTemplate::TEMPLATE_CUSTOMER_ROLE_DISCOUNT,
			),
			self::COUPON_CODE       => array(
				'title'        => __( 'Coupon code campaign', 'mp-commerce-promotions' ),
				'description'  => __( 'Customers enter a code to unlock a cart discount.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Newsletter codes and partner promotions.', 'mp-commerce-promotions' ),
				'icon'         => 'tickets-alt',
				'template_key' => PromotionTemplate::TEMPLATE_FIRST_ORDER_DISCOUNT,
			),
			self::BUDGETED          => array(
				'title'        => __( 'Budgeted campaign', 'mp-commerce-promotions' ),
				'description'  => __( 'Cart discount with a spending cap — stops when budget is used.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Controlled spend on broad promotions.', 'mp-commerce-promotions' ),
				'icon'         => 'money-alt',
				'template_key' => PromotionTemplate::TEMPLATE_FIRST_ORDER_DISCOUNT,
			),
			self::SCHEDULED         => array(
				'title'        => __( 'Scheduled campaign', 'mp-commerce-promotions' ),
				'description'  => __( 'Category discount with clear start and end dates.', 'mp-commerce-promotions' ),
				'best_for'     => __( 'Planned launches and seasonal windows.', 'mp-commerce-promotions' ),
				'icon'         => 'calendar-alt',
				'template_key' => PromotionTemplate::TEMPLATE_PERCENT_OFF_CATEGORY,
			),
		);
	}

	public static function label( string $goal ): string {
		$defs = self::definitions();

		return $defs[ $goal ]['title'] ?? $goal;
	}

	/**
	 * Visual theme slug for goal cards (discount, shipping, gift, coupon, vip, budget).
	 */
	public static function visual_theme( string $goal ): string {
		$map = array(
			self::CATEGORY_DISCOUNT => 'discount',
			self::PRODUCT_DISCOUNT  => 'discount',
			self::BUY_X_GET_Y       => 'discount',
			self::SCHEDULED         => 'discount',
			self::FIRST_ORDER       => 'discount',
			self::FREE_SHIPPING     => 'shipping',
			self::FREE_GIFT         => 'gift',
			self::COUPON_CODE       => 'coupon',
			self::VIP_ROLE          => 'vip',
			self::BUDGETED          => 'budget',
		);

		return $map[ $goal ] ?? 'discount';
	}

	public static function encode_internal_notes( string $goal, ?string $merchant_notes = null ): string {
		$line = self::NOTES_PREFIX . $goal;
		if ( $merchant_notes !== null && trim( $merchant_notes ) !== '' ) {
			$line .= "\n" . trim( $merchant_notes );
		}

		return $line;
	}

	public static function parse_goal_from_notes( ?string $notes ): ?string {
		if ( $notes === null || $notes === '' ) {
			return null;
		}

		foreach ( preg_split( '/\r\n|\r|\n/', $notes ) as $line ) {
			$line = trim( (string) $line );
			if ( str_starts_with( $line, self::NOTES_PREFIX ) ) {
				$goal = substr( $line, strlen( self::NOTES_PREFIX ) );

				return self::sanitize( $goal );
			}
		}

		return null;
	}

	public static function has_advanced_builder_rules( Promotion $promotion ): bool {
		$goal = self::parse_goal_from_notes( $promotion->get_internal_notes() );
		if ( $goal === null ) {
			return false;
		}

		$conditions = count( $promotion->get_conditions() );
		$actions    = count( $promotion->get_actions() );

		return $conditions > 3 || $actions > 2;
	}

	private function __construct() {
	}
}
