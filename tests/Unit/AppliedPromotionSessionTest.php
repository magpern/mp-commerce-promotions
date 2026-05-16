<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Woo\AppliedPromotionSession;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use PHPUnit\Framework\TestCase;

final class AppliedPromotionSessionTest extends TestCase {

	public function test_build_and_read_multi_entry_payload(): void {
		$entries = array(
			array(
				'promotion_id'    => 1,
				'promotion_uuid'  => '11111111-1111-4111-8111-111111111111',
				'promotion_name'  => 'A',
				'discount_amount' => 10.0,
				'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
				'fixed_amount'    => 10.0,
			),
			array(
				'promotion_id'    => 2,
				'promotion_uuid'  => '22222222-2222-4222-8222-222222222222',
				'promotion_name'  => 'B',
				'discount_amount' => 15.0,
				'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
				'fixed_amount'    => 15.0,
			),
		);

		$payload = AppliedPromotionSession::build_session_payload( $entries );
		$this->assertSame( 1, $payload['promotion_id'] );
		$this->assertCount( 2, $payload['applied_promotions'] );
		$this->assertSame( 25.0, $payload['total_discount_amount'] );

		$roundtrip = AppliedPromotionSession::entries_from_session( $payload );
		$this->assertCount( 2, $roundtrip );
	}

	public function test_legacy_single_payload_still_readable(): void {
		$legacy = array(
			'promotion_id'    => 5,
			'promotion_uuid'  => '55555555-5555-4555-8555-555555555555',
			'promotion_name'  => 'Legacy',
			'discount_amount' => 4.0,
			'action_type'     => CartPromotionApplier::ACTION_FIXED_AMOUNT_DISCOUNT,
			'fixed_amount'    => 4.0,
		);

		$this->assertCount( 1, AppliedPromotionSession::entries_from_session( $legacy ) );
	}
}
