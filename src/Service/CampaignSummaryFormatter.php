<?php
/**
 * Merchant-readable campaign summaries (no engine jargon).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Engine\RuleTypes;

final class CampaignSummaryFormatter {

	/**
	 * Primary one-line headline for cards, tables, and review.
	 */
	public static function headline( string $goal, array $ui ): string {
		$benefit = self::customer_benefit( $goal, $ui );
		$scope   = self::targeting_phrase( $goal, $ui );

		if ( $scope !== '' && $benefit !== '' ) {
			return sprintf(
				/* translators: 1: customer benefit, 2: targeting scope */
				__( '%1$s on %2$s.', 'mp-commerce-promotions' ),
				$benefit,
				$scope
			);
		}

		if ( $benefit !== '' ) {
			return $benefit . '.';
		}

		return CampaignBuilderGoal::label( $goal );
	}

	public static function from_promotion( Promotion $promotion ): string {
		$goal = CampaignBuilderGoal::parse_goal_from_notes( $promotion->get_internal_notes() );
		if ( $goal === null ) {
			return $promotion->get_name();
		}

		$ui = self::ui_stub_from_promotion( $promotion, $goal );

		return self::headline( $goal, $ui );
	}

	/**
	 * Short teaser for goal cards (before configuration).
	 */
	public static function goal_teaser( string $goal ): string {
		$teasers = array(
			CampaignBuilderGoal::CATEGORY_DISCOUNT => __( 'Customers get a discount on selected categories.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::PRODUCT_DISCOUNT  => __( 'Customers get a discount on specific products.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::BUY_X_GET_Y       => __( 'Buy X items and get the cheapest units discounted.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::FREE_SHIPPING     => __( 'Free shipping when the cart reaches your threshold.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::FREE_GIFT         => __( 'Customers receive a free gift above a subtotal.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::FIRST_ORDER       => __( 'Welcome discount for first-time buyers.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::VIP_ROLE          => __( 'Exclusive discount for selected customer groups.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::COUPON_CODE       => __( 'Shoppers enter a code to unlock the offer.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::BUDGETED          => __( 'Cart discount with a controlled spending cap.', 'mp-commerce-promotions' ),
			CampaignBuilderGoal::SCHEDULED         => __( 'Timed category sale with a clear start and end.', 'mp-commerce-promotions' ),
		);

		return $teasers[ $goal ] ?? CampaignBuilderGoal::label( $goal );
	}

	public static function customer_benefit( string $goal, array $ui ): string {
		if ( $goal === CampaignBuilderGoal::FREE_SHIPPING ) {
			$min = self::money( (string) ( $ui['minimum_subtotal'] ?? '' ) );
			if ( $min !== '' ) {
				return sprintf(
					/* translators: %s: formatted money threshold */
					__( 'Free shipping on orders above %s', 'mp-commerce-promotions' ),
					$min
				);
			}

			return __( 'Free shipping on qualifying orders', 'mp-commerce-promotions' );
		}

		if ( $goal === CampaignBuilderGoal::FREE_GIFT ) {
			$min = self::money( (string) ( $ui['minimum_subtotal'] ?? '' ) );
			$qty = max( 1, (int) ( $ui['gift_quantity'] ?? 1 ) );
			if ( $min !== '' ) {
				return sprintf(
					/* translators: 1: gift quantity, 2: money threshold */
					_n(
						'Customers get %1$d free gift on orders above %2$s',
						'Customers get %1$d free gifts on orders above %2$s',
						$qty,
						'mp-commerce-promotions'
					),
					$qty,
					$min
				);
			}

			return __( 'Customers receive a free gift', 'mp-commerce-promotions' );
		}

		if ( $goal === CampaignBuilderGoal::BUY_X_GET_Y ) {
			$buy  = max( 1, (int) ( $ui['required_quantity'] ?? 1 ) );
			$get  = max( 1, (int) ( $ui['discounted_quantity'] ?? 1 ) );
			$pct  = (float) ( $ui['discount_percentage'] ?? 100 );
			$scope = self::targeting_phrase( $goal, $ui );
			$off   = $pct >= 100
				? __( 'the cheapest free', 'mp-commerce-promotions' )
				: sprintf(
					/* translators: %s: percentage */
					__( '%s%% off the cheapest', 'mp-commerce-promotions' ),
					self::format_number( $pct )
				);

			if ( $scope !== '' ) {
				return sprintf(
					/* translators: 1: buy qty, 2: scope, 3: get qty, 4: discount phrase */
					__( 'Buy %1$d from %2$s and get %3$d with %4$s', 'mp-commerce-promotions' ),
					$buy,
					$scope,
					$get,
					$off
				);
			}

			return sprintf(
				/* translators: 1: buy qty, 2: get qty, 3: discount phrase */
				__( 'Buy %1$d and get %2$d with %3$s', 'mp-commerce-promotions' ),
				$buy,
				$get,
				$off
			);
		}

		$discount = self::discount_phrase( $ui );
		if ( $discount === '' ) {
			return __( 'Customers receive a promotion', 'mp-commerce-promotions' );
		}

		if ( $goal === CampaignBuilderGoal::FIRST_ORDER ) {
			return sprintf(
				/* translators: %s: discount phrase e.g. 20% off */
				__( 'First-time customers get %s', 'mp-commerce-promotions' ),
				$discount
			);
		}

		if ( $goal === CampaignBuilderGoal::COUPON_CODE ) {
			$code = trim( (string) ( $ui['coupon_code'] ?? '' ) );
			if ( $code !== '' ) {
				return sprintf(
					/* translators: 1: discount, 2: coupon code */
					__( 'Customers get %1$s with code %2$s', 'mp-commerce-promotions' ),
					$discount,
					$code
				);
			}

			return sprintf(
				/* translators: %s: discount phrase */
				__( 'Customers get %s with a coupon code', 'mp-commerce-promotions' ),
				$discount
			);
		}

		return sprintf(
			/* translators: %s: discount phrase */
			__( 'Customers get %s', 'mp-commerce-promotions' ),
			$discount
		);
	}

	public static function targeting_phrase( string $goal, array $ui ): string {
		$names = self::category_names( (array) ( $ui['category_ids'] ?? array() ) );
		if ( $names !== array() ) {
			return self::join_names( $names );
		}

		$product_ids = self::product_ids_from_ui( $ui );
		if ( $product_ids !== array() ) {
			$labels = self::product_labels( $product_ids );
			if ( $labels !== array() ) {
				return self::join_names( $labels );
			}

			return sprintf(
				/* translators: %d: number of products */
				_n( '%d selected product', '%d selected products', count( $product_ids ), 'mp-commerce-promotions' ),
				count( $product_ids )
			);
		}

		$roles = array_filter( array_map( 'strval', (array) ( $ui['roles'] ?? array() ) ) );
		if ( $roles !== array() && $goal === CampaignBuilderGoal::VIP_ROLE ) {
			return self::join_names( $roles );
		}

		if ( $goal === CampaignBuilderGoal::FIRST_ORDER ) {
			return __( 'first-time buyers', 'mp-commerce-promotions' );
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $ui
	 * @return array{headline: string, benefit: string, targeting: string, schedule: string, limits: string}
	 */
	public static function review_sections( string $goal, array $ui ): array {
		return array(
			'headline'   => self::headline( $goal, $ui ),
			'benefit'    => self::customer_benefit( $goal, $ui ),
			'targeting'  => self::targeting_phrase( $goal, $ui ) ?: __( 'All eligible shoppers', 'mp-commerce-promotions' ),
			'schedule'   => self::schedule_phrase( $ui ),
			'limits'     => self::limits_phrase( $ui ),
		);
	}

	/**
	 * @param array<string, mixed> $ui
	 */
	private static function schedule_phrase( array $ui ): string {
		$starts = trim( (string) ( $ui['starts_at'] ?? '' ) );
		$ends   = trim( (string) ( $ui['ends_at'] ?? '' ) );
		if ( $starts === '' && $ends === '' ) {
			return __( 'Runs when you activate it (no fixed end date)', 'mp-commerce-promotions' );
		}
		if ( $starts !== '' && $ends !== '' ) {
			return sprintf(
				/* translators: 1: start, 2: end */
				__( '%1$s → %2$s', 'mp-commerce-promotions' ),
				$starts,
				$ends
			);
		}

		return $starts !== '' ? $starts : $ends;
	}

	/**
	 * @param array<string, mixed> $ui
	 */
	private static function limits_phrase( array $ui ): string {
		$parts = array();
		$budget = trim( (string) ( $ui['budget_amount'] ?? '' ) );
		if ( $budget !== '' && is_numeric( $budget ) ) {
			$parts[] = sprintf(
				/* translators: %s: money amount */
				__( 'Budget cap %s', 'mp-commerce-promotions' ),
				self::money( $budget )
			);
		}
		$usage = trim( (string) ( $ui['usage_limit'] ?? '' ) );
		if ( $usage !== '' && (int) $usage > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: max redemptions */
				__( 'Up to %d redemptions', 'mp-commerce-promotions' ),
				(int) $usage
			);
		}
		if ( ! empty( $ui['stackable'] ) ) {
			$parts[] = __( 'Can stack with compatible promotions', 'mp-commerce-promotions' );
		} else {
			$parts[] = __( 'Does not stack with other promotions', 'mp-commerce-promotions' );
		}

		return $parts !== array() ? implode( ' · ', $parts ) : __( 'No budget or usage cap set', 'mp-commerce-promotions' );
	}

	/**
	 * @param array<string, mixed> $ui
	 */
	private static function discount_phrase( array $ui ): string {
		$type = (string) ( $ui['discount_type'] ?? 'percentage' );
		if ( $type === 'fixed' ) {
			$amt = trim( (string) ( $ui['amount'] ?? '' ) );
			if ( $amt !== '' && is_numeric( $amt ) ) {
				return sprintf(
					/* translators: %s: money amount off */
					__( '%s off', 'mp-commerce-promotions' ),
					self::money( $amt )
				);
			}
		}

		$pct = trim( (string) ( $ui['percentage'] ?? '' ) );
		if ( $pct !== '' && is_numeric( $pct ) ) {
			return sprintf(
				/* translators: %s: percentage */
				__( '%s%% off', 'mp-commerce-promotions' ),
				self::format_number( (float) $pct )
			);
		}

		return '';
	}

	/**
	 * @param array<int> $ids
	 * @return list<string>
	 */
	private static function category_names( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( int $i ): bool => $i > 0 ) );
		if ( $ids === array() || ! function_exists( 'get_term' ) ) {
			return array();
		}

		$names = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, 'product_cat' );
			if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
				$names[] = (string) $term->name;
			}
		}

		return $names;
	}

	/**
	 * @param array<string, mixed> $ui
	 * @return list<int>
	 */
	private static function product_ids_from_ui( array $ui ): array {
		$ids = array_map( 'intval', (array) ( $ui['product_ids'] ?? array() ) );
		$ids = array_values( array_filter( $ids, static fn( int $i ): bool => $i > 0 ) );
		if ( $ids !== array() ) {
			return $ids;
		}

		$csv = trim( (string) ( $ui['product_ids_csv'] ?? '' ) );
		if ( $csv === '' ) {
			return array();
		}

		$parts = preg_split( '/[\s,]+/', $csv, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$out = array();
		foreach ( $parts as $p ) {
			$i = (int) $p;
			if ( $i > 0 ) {
				$out[] = $i;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @param list<int> $ids
	 * @return list<string>
	 */
	private static function product_labels( array $ids ): array {
		$labels = array();
		foreach ( $ids as $id ) {
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					$labels[] = $product->get_name();
					continue;
				}
			}
			$labels[] = '#' . $id;
		}

		return $labels;
	}

	/**
	 * @param list<string> $names
	 */
	private static function join_names( array $names ): string {
		$names = array_values( array_filter( array_map( 'trim', $names ) ) );
		$count = count( $names );
		if ( $count === 0 ) {
			return '';
		}
		if ( $count === 1 ) {
			return $names[0];
		}
		if ( $count === 2 ) {
			return $names[0] . ' ' . __( 'and', 'mp-commerce-promotions' ) . ' ' . $names[1];
		}

		$last = array_pop( $names );

		return implode( ', ', $names ) . ' ' . __( 'and', 'mp-commerce-promotions' ) . ' ' . $last;
	}

	private static function money( string $amount ): string {
		if ( $amount === '' || ! is_numeric( $amount ) ) {
			return '';
		}
		$value = (float) $amount;
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $value ) );
		}

		return self::format_number( $value );
	}

	private static function format_number( float $n ): string {
		return rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function ui_stub_from_promotion( Promotion $promotion, string $goal ): array {
		$ui = array(
			'category_ids'    => array(),
			'product_ids'     => array(),
			'roles'           => array(),
			'discount_type'   => 'percentage',
			'percentage'      => '',
			'amount'          => '',
			'minimum_subtotal'=> '',
		);

		foreach ( $promotion->get_actions() as $action ) {
			if ( ! is_array( $action ) ) {
				continue;
			}
			$type = (string) ( $action['type'] ?? '' );
			if ( $type === RuleTypes::ACTION_PERCENTAGE_DISCOUNT ) {
				$ui['percentage'] = (string) ( $action['percentage'] ?? '' );
			} elseif ( $type === RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT ) {
				$ui['discount_type'] = 'fixed';
				$ui['amount']        = (string) ( $action['amount'] ?? '' );
			}
		}

		return $ui;
	}

	private function __construct() {
	}
}
