<?php
/**
 * Creates/archives paused promotions for Cart/Checkout Blocks manual QA.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionDiscountApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use Throwable;

final class BlockQaPromotionSetup {

	public const OPTION_PAID_PRODUCT_ID = 'mp_cp_block_qa_paid_product_id';

	public const OPTION_GIFT_PRODUCT_ID = 'mp_cp_block_qa_gift_product_id';

	public const DEFAULT_PAID_PRODUCT_ID = 4338;

	public const DEFAULT_BROWSER_PAID_PRODUCT_ID = 3702;

	public const QA_PAID_PRODUCT_SKU = 'mp-cp-block-qa-paid';

	public const QA_GIFT_PRODUCT_SKU = 'mp-cp-block-qa-gift';

	private PromotionRepository $promotions;

	private PromotionService $service;

	public function __construct( PromotionRepository $promotions, PromotionService $service ) {
		$this->promotions = $promotions;
		$this->service    = $service;
	}

	/**
	 * @return array{paid_product_id: int, gift_product_id: int, note: string}
	 */
	public static function resolve_default_product_pair(): array {
		$pair = self::ensure_distinct_addable_product_pair();
		$paid = $pair['paid_product_id'];
		$gift = $pair['gift_product_id'];
		$note = $pair['note'];

		if ( getenv( 'MP_CP_BLOCK_QA_PAID_PRODUCT_ID' ) !== false ) {
			$forced = (int) getenv( 'MP_CP_BLOCK_QA_PAID_PRODUCT_ID' );
			if ( $forced > 0 && self::is_product_cart_addable( $forced ) && $forced !== $gift ) {
				$paid = $forced;
				$note = 'paid product forced via MP_CP_BLOCK_QA_PAID_PRODUCT_ID';
			}
		}

		if ( $gift <= 0 || $gift === $paid ) {
			$note = 'browser paid SKU: MOTS-C variable 3702 + gift product ' . ( $gift > 0 ? (string) $gift : '4338' );
		}

		return array(
			'paid_product_id' => $paid,
			'gift_product_id' => $gift,
			'note'            => $note,
		);
	}

	/**
	 * Ensures two distinct, cart-addable simple products for CLI block QA (paid + gift).
	 *
	 * @return array{paid_product_id: int, gift_product_id: int, note: string}
	 */
	public static function ensure_distinct_addable_product_pair(): array {
		$paid_id = self::find_product_id_by_sku( self::QA_PAID_PRODUCT_SKU );
		if ( $paid_id <= 0 || ! self::is_product_cart_addable( $paid_id ) ) {
			$paid_id = self::create_simple_qa_product(
				'MP CP Blocks QA — Paid',
				self::QA_PAID_PRODUCT_SKU,
				'5.00'
			);
		}

		$gift_id = self::find_product_id_by_sku( self::QA_GIFT_PRODUCT_SKU );
		if ( $gift_id <= 0 ) {
			$gift_id = self::DEFAULT_PAID_PRODUCT_ID;
			if ( $gift_id > 0 && function_exists( 'wc_get_product' ) ) {
				$gift_product = wc_get_product( $gift_id );
				if ( $gift_product && $gift_product->get_sku() === '' && method_exists( $gift_product, 'set_sku' ) ) {
					$gift_product->set_sku( self::QA_GIFT_PRODUCT_SKU );
					$gift_product->save();
				}
			}
		}
		if ( $gift_id <= 0 || ! self::is_product_cart_addable( $gift_id ) || $gift_id === $paid_id ) {
			$gift_id = self::create_simple_qa_product(
				'MP CP Blocks QA — Gift',
				self::QA_GIFT_PRODUCT_SKU,
				'1.00'
			);
		}

		$note = '';
		if ( $paid_id <= 0 || $gift_id <= 0 || $paid_id === $gift_id ) {
			$note = 'could not provision distinct addable paid/gift products';
		}

		return array(
			'paid_product_id' => $paid_id,
			'gift_product_id' => $gift_id,
			'note'            => $note,
		);
	}

	public static function find_product_id_by_sku( string $sku ): int {
		if ( $sku === '' || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return 0;
		}

		$id = (int) wc_get_product_id_by_sku( $sku );

		return $id > 0 ? $id : 0;
	}

	public static function create_simple_qa_product( string $name, string $sku, string $price ): int {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}

		$existing = self::find_product_id_by_sku( $sku );
		if ( $existing > 0 ) {
			return $existing;
		}

		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_sku( $sku );
		$product->set_regular_price( $price );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->set_sold_individually( false );

		$id = (int) $product->save();

		return $id > 0 && self::is_product_cart_addable( $id ) ? $id : 0;
	}

	/**
	 * @return list<int>
	 */
	public static function find_cart_addable_product_ids(): array {
		if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
			return array();
		}

		$ids      = array();
		$products = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => 50,
				'orderby' => 'id',
				'order'   => 'ASC',
			)
		);
		if ( ! is_array( $products ) ) {
			return $ids;
		}

		foreach ( $products as $product ) {
			if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
				continue;
			}
			$id = (int) $product->get_id();
			if ( $id <= 0 || ! self::is_product_cart_addable( $id ) ) {
				continue;
			}
			$ids[] = $id;
		}

		return $ids;
	}

	public static function is_product_cart_addable( int $product_id ): bool {
		if ( $product_id <= 0 || ! function_exists( 'wc_load_cart' ) || ! function_exists( 'WC' ) ) {
			return false;
		}

		wc_load_cart();
		$cart = WC()->cart;
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'empty_cart' ) || ! method_exists( $cart, 'add_to_cart' ) ) {
			return false;
		}

		$cart->empty_cart( true );
		$key = $cart->add_to_cart( $product_id, 1 );
		$cart->empty_cart( true );

		return $key !== false && $key !== '';
	}

	/**
	 * @return array{archived: int, created: list<array{id: int, name: string, status: string}>, product_pair: array{paid_product_id: int, gift_product_id: int, note: string}}
	 */
	public function refresh_qa_promotions( int $gift_product_id = 0, int $paid_product_id = 0 ): array {
		$pair = self::ensure_distinct_addable_product_pair();
		if ( $paid_product_id <= 0 ) {
			$paid_product_id = $pair['paid_product_id'];
		}
		if ( $gift_product_id <= 0 ) {
			$gift_product_id = $pair['gift_product_id'];
		}
		if ( $gift_product_id === $paid_product_id ) {
			$pair['note'] = 'gift_product_id equals paid_product_id; use browser for distinct MOTS-C + gift SKU';
		}
		if ( $gift_product_id > 0 && ! self::is_product_cart_addable( $gift_product_id ) ) {
			$gift_product_id = 0;
			$pair['note']    = 'gift product is not cart-addable in CLI';
		}
		if ( $paid_product_id > 0 && ! self::is_product_cart_addable( $paid_product_id ) ) {
			$paid_product_id = $pair['paid_product_id'];
		}

		update_option( self::OPTION_PAID_PRODUCT_ID, $paid_product_id, false );
		update_option( self::OPTION_GIFT_PRODUCT_ID, $gift_product_id, false );

		$archived = $this->archive_existing();
		$created  = array();

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Fee 10%',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ),
			),
			PromotionDiscountApplicationMode::FEE_BASED,
			PromotionApplicationMode::EXCLUSIVE,
			true
		);

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Fixed 5',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 5 ),
			),
			PromotionDiscountApplicationMode::FEE_BASED,
			PromotionApplicationMode::EXCLUSIVE,
			true
		);

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Stack 3%',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 3 ),
			),
			PromotionDiscountApplicationMode::FEE_BASED,
			PromotionApplicationMode::STACKABLE,
			false
		);

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Stack 2 fixed',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 2 ),
			),
			PromotionDiscountApplicationMode::FEE_BASED,
			PromotionApplicationMode::STACKABLE,
			false
		);

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Free shipping',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ),
			),
			PromotionDiscountApplicationMode::FEE_BASED,
			PromotionApplicationMode::EXCLUSIVE,
			true
		);

		if ( $gift_product_id > 0 && $gift_product_id !== $paid_product_id ) {
			$created[] = $this->create_paused(
				BlockTestPages::QA_PROMOTION_PREFIX . ' — Free gift',
				array(
					array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
				),
				array(
					array(
						'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
						'product_id' => $gift_product_id,
						'quantity'   => 1,
					),
				),
				PromotionDiscountApplicationMode::FEE_BASED,
				PromotionApplicationMode::EXCLUSIVE,
				true
			);
		}

		$created[] = $this->create_paused(
			BlockTestPages::QA_PROMOTION_PREFIX . ' — Line 10%',
			array(
				array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 0.01 ),
			),
			array(
				array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ),
			),
			PromotionDiscountApplicationMode::LINE_ITEM,
			PromotionApplicationMode::EXCLUSIVE,
			true
		);

		return array(
			'archived'      => $archived,
			'created'       => $created,
			'product_pair'  => array(
				'paid_product_id' => $paid_product_id,
				'gift_product_id' => $gift_product_id,
				'note'            => $pair['note'],
			),
		);
	}

	public function archive_existing(): int {
		$archived = 0;
		$rows     = $this->promotions->find_filtered(
			array(
				'search' => BlockTestPages::QA_PROMOTION_PREFIX,
				'limit'  => 100,
			)
		);

		foreach ( $rows as $promotion ) {
			$id = $promotion->get_id();
			if ( $id === null || $id <= 0 ) {
				continue;
			}
			$status = $promotion->get_status();
			if ( $status !== PromotionStatus::ACTIVE && $status !== PromotionStatus::PAUSED ) {
				continue;
			}
			try {
				$this->service->change_status( $promotion, PromotionStatus::ARCHIVED );
				++$archived;
			} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
		}

		return $archived;
	}

	/**
	 * @param list<array<string, mixed>> $conditions
	 * @param list<array<string, mixed>> $actions
	 * @return array{id: int, name: string, status: string}
	 */
	private function create_paused(
		string $name,
		array $conditions,
		array $actions,
		string $discount_application_mode,
		string $application_mode = PromotionApplicationMode::EXCLUSIVE,
		bool $stop_processing = true
	): array {
		$draft = $this->service->create_draft( $name );
		$id    = (int) ( $draft->get_id() ?? 0 );

		$model = $draft
			->with_rules( $conditions, $actions, $draft->get_restrictions() )
			->with_priority( 5 )
			->with_pricing_fields( null, null, null, $discount_application_mode )
			->with_application_rules( $application_mode, $stop_processing, null );

		$this->service->update_promotion( $model );
		$reloaded = $this->promotions->find( $id );
		if ( $reloaded === null ) {
			return array(
				'id'     => $id,
				'name'   => $name,
				'status' => PromotionStatus::DRAFT,
			);
		}

		$this->service->change_status( $reloaded, PromotionStatus::ACTIVE );
		$active = $this->promotions->find( $id );
		if ( $active !== null ) {
			$this->service->change_status( $active, PromotionStatus::PAUSED );
		}

		return array(
			'id'     => $id,
			'name'   => $name,
			'status' => PromotionStatus::PAUSED,
		);
	}
}
