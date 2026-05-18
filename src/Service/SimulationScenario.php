<?php
/**
 * Cart/customer scenario definition for promotion simulation.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use InvalidArgumentException;

final class SimulationScenario {

	public const PRESET_WHOLE_CART = 'whole_cart';

	public const PRESET_SCOPED_PRODUCTS = 'scoped_products';

	public const PRESET_CATEGORY_CART = 'category_cart';

	public const PRESET_HIGH_QUANTITY = 'high_quantity';

	public const PRESET_VIP_CUSTOMER = 'vip_customer';

	public const PRESET_GUEST_CUSTOMER = 'guest_customer';

	public const PRESET_COOLDOWN_ACTIVE = 'cooldown_active_customer';

	/** @var array<string, mixed> */
	private array $config;

	/**
	 * @param array<string, mixed> $config
	 */
	public function __construct( array $config = array() ) {
		$this->config = $config;
	}

	public static function from_preset( string $preset ): self {
		$preset = sanitize_key( $preset );

		if ( $preset === self::PRESET_SCOPED_PRODUCTS ) {
			return new self(
				array(
					'preset'   => $preset,
					'items'    => array(
						array(
							'product_id'    => 101,
							'quantity'      => 1,
							'line_subtotal' => 49.99,
							'unit_price'    => 49.99,
						),
						array(
							'product_id'    => 202,
							'quantity'      => 2,
							'line_subtotal' => 30.00,
							'unit_price'    => 15.00,
						),
					),
					'metadata' => array( 'customer_lifetime_spend' => 250.0 ),
				)
			);
		}

		if ( $preset === self::PRESET_CATEGORY_CART ) {
			return new self(
				array(
					'preset'   => $preset,
					'items'    => array(
						array(
							'product_id'    => 301,
							'quantity'      => 3,
							'line_subtotal' => 90.0,
							'category_ids'  => array( 15 ),
						),
					),
					'metadata' => array( 'customer_order_count' => 5 ),
				)
			);
		}

		if ( $preset === self::PRESET_HIGH_QUANTITY ) {
			return new self(
				array(
					'preset' => $preset,
					'items'  => array(
						array(
							'product_id'    => 401,
							'quantity'      => 12,
							'line_subtotal' => 240.0,
							'unit_price'    => 20.0,
						),
					),
				)
			);
		}

		if ( $preset === self::PRESET_VIP_CUSTOMER ) {
			return new self(
				array(
					'preset'      => $preset,
					'customer_id' => 42,
					'items'       => array(
						array(
							'product_id'    => 501,
							'quantity'      => 1,
							'line_subtotal' => 199.0,
						),
					),
					'metadata'    => array(
						'customer_lifetime_spend'      => 5000.0,
						'customer_order_count'         => 25,
						'customer_average_order_value' => 200.0,
					),
				)
			);
		}

		if ( $preset === self::PRESET_GUEST_CUSTOMER ) {
			return new self(
				array(
					'preset'      => $preset,
					'customer_id' => null,
					'items'       => array(
						array(
							'product_id'    => 601,
							'quantity'      => 1,
							'line_subtotal' => 75.0,
						),
					),
				)
			);
		}

		if ( $preset === self::PRESET_COOLDOWN_ACTIVE ) {
			return new self(
				array(
					'preset'      => $preset,
					'customer_id' => 99,
					'items'       => array(
						array(
							'product_id'    => 701,
							'quantity'      => 1,
							'line_subtotal' => 55.0,
						),
					),
					'metadata'    => array( 'simulate_cooldown_active' => true ),
				)
			);
		}

		return new self(
			array(
				'preset' => self::PRESET_WHOLE_CART,
				'items'  => array(
					array(
						'product_id'    => 1,
						'quantity'      => 2,
						'line_subtotal' => 100.0,
						'unit_price'    => 50.0,
					),
					array(
						'product_id'    => 2,
						'quantity'      => 1,
						'line_subtotal' => 25.0,
						'unit_price'    => 25.0,
					),
				),
			)
		);
	}

	/**
	 * @param array<string, mixed> $json
	 */
	public static function from_array( array $json ): self {
		if ( isset( $json['preset'] ) && is_string( $json['preset'] ) && $json['preset'] !== '' ) {
			$base = self::from_preset( $json['preset'] )->to_array();
			$json = array_merge( $base, $json );
		}

		return new self( $json );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->config;
	}

	public function get_preset(): ?string {
		$preset = isset( $this->config['preset'] ) ? (string) $this->config['preset'] : '';
		return $preset !== '' ? $preset : null;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function get_items(): array {
		$items = $this->config['items'] ?? array();
		return is_array( $items ) ? $items : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_metadata(): array {
		$meta = $this->config['metadata'] ?? array();
		return is_array( $meta ) ? $meta : array();
	}

	public function get_customer_id(): ?int {
		if ( ! array_key_exists( 'customer_id', $this->config ) ) {
			return null;
		}
		$id = (int) $this->config['customer_id'];
		return $id > 0 ? $id : null;
	}

	public function get_coupon_code(): ?string {
		$code = isset( $this->config['coupon_code'] ) ? trim( (string) $this->config['coupon_code'] ) : '';
		return $code !== '' ? $code : null;
	}

	/**
	 * @return list<int>
	 */
	public function get_promotion_ids(): array {
		$raw = $this->config['promotion_ids'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $id ) {
			$id = (int) $id;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @return true|string
	 */
	public function validate() {
		$items = $this->get_items();
		if ( $items === array() ) {
			return 'scenario_missing_items';
		}

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				return 'invalid_item_shape';
			}
		}

		return true;
	}
}
