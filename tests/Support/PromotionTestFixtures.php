<?php
/**
 * Test helpers for building Promotion and EvaluationContext instances.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Support;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\EvaluationContext;

final class PromotionTestFixtures {

	/**
	 * @param array<mixed> $conditions
	 * @param array<mixed> $actions
	 */
	public static function promotion( string $status, array $conditions, array $actions ): Promotion {
		$data = array(
			'uuid'         => '11111111-1111-4111-8111-111111111111',
			'name'         => 'Test Promotion',
			'status'       => $status,
			'priority'     => 10,
			'conditions'   => $conditions,
			'actions'      => $actions,
			'restrictions' => array(),
			'usage_count'  => 0,
		);

		if ( $status === PromotionStatus::ACTIVE ) {
			$data['ends_at'] = '2099-12-31 23:59:59';
		}

		return Promotion::from_array( $data );
	}

	public static function active_promotion( array $conditions, array $actions ): Promotion {
		return self::promotion( PromotionStatus::ACTIVE, $conditions, $actions );
	}

	public static function active_promotion_with_id( int $id, array $conditions, array $actions ): Promotion {
		$data            = self::active_promotion( $conditions, $actions )->to_array();
		$data['id']      = $id;
		$data['uuid']    = sprintf( '00000000-0000-4000-8000-%012d', $id );
		$data['name']    = 'Promotion ' . $id;

		return Promotion::from_array( $data );
	}

	public static function cart_context(
		?int $customer_id,
		?float $subtotal,
		array $items = array(),
		array $metadata = array()
	): EvaluationContext {
		return new EvaluationContext( $customer_id, $subtotal, 'USD', $items, $metadata );
	}
}
