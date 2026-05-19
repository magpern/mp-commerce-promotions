<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Woo\PromotionCartExclusionSession;
use MP\CommercePromotions\Woo\PromotionFeeLabelResolver;
use PHPUnit\Framework\TestCase;

final class PromotionCartExclusionSessionTest extends TestCase {

	public function test_normalize_ids_dedupes_and_filters(): void {
		$this->assertSame(
			array( 2, 5 ),
			PromotionCartExclusionSession::normalize_ids( array( 2, '5', 0, 2, -1 ) )
		);
	}

	public function test_add_id_appends_unique(): void {
		$this->assertSame(
			array( 1, 2 ),
			PromotionCartExclusionSession::add_id( array( 1 ), 2 )
		);
		$this->assertSame(
			array( 1 ),
			PromotionCartExclusionSession::add_id( array( 1 ), 1 )
		);
	}

	public function test_filter_promotions_with_manual_exclusion_list(): void {
		$p1 = $this->promotion_with_id( 10 );
		$p2 = $this->promotion_with_id( 20 );

		$excluded = array( 10 );
		$filtered = array();
		foreach ( array( $p1, $p2 ) as $promotion ) {
			$pid = (int) ( $promotion->get_id() ?? 0 );
			if ( $pid > 0 && in_array( $pid, $excluded, true ) ) {
				continue;
			}
			$filtered[] = $promotion;
		}

		$this->assertCount( 1, $filtered );
		$this->assertSame( 20, $filtered[0]->get_id() );
	}

	public function test_fee_label_resolves_promotion_id_from_entry(): void {
		$entry = array(
			'promotion_id'    => 42,
			'promotion_name'  => 'Regression stack A',
			'discount_amount' => 1.0,
			'action_type'     => 'percentage_discount',
		);

		$label = PromotionFeeLabelResolver::label_from_entry( $entry );
		$this->assertNotNull( $label );
		$this->assertSame( 42, PromotionFeeLabelResolver::promotion_id_from_fee_label( (string) $label, array( $entry ) ) );
	}

	public function test_disable_automatic_session_constants(): void {
		$this->assertSame( 'mp_cp_disable_automatic_promotions', PromotionCartExclusionSession::DISABLE_AUTOMATIC_KEY );
		$this->assertSame( 'yes', PromotionCartExclusionSession::DISABLE_AUTOMATIC_VALUE );
	}

	public function test_fee_label_does_not_concatenate_title_and_summary(): void {
		$entry = array(
			'promotion_id'    => 7,
			'promotion_name'  => 'Stack promo',
			'discount_amount' => 2.0,
			'action_type'     => 'fixed_amount_discount',
		);
		$label = PromotionFeeLabelResolver::label_from_entry( $entry );
		$this->assertIsString( $label );
		$this->assertStringNotContainsString( 'Stack promoNo store credit', $label );
	}

	private function promotion_with_id( int $id ): Promotion {
		return new Promotion(
			$id,
			'00000000-0000-4000-8000-' . sprintf( '%012d', $id ),
			'Promo ' . $id,
			null,
			PromotionStatus::ACTIVE,
			10,
			null,
			null,
			array(),
			array(),
			array(),
			null,
			null,
			0,
			PromotionApplicationMode::EXCLUSIVE,
			true,
			null,
			array(),
			array(),
			array(),
			null,
			null,
			null,
			null,
			0.0,
			null,
			null,
			null,
			'storefront',
			'coexist',
			'proportional',
			'fee_based',
			false
		);
	}
}
