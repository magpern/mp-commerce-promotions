<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use PHPUnit\Framework\TestCase;

final class PromotionTest extends TestCase {

	public function test_invalid_status_throws(): void {
		$this->expectException( InvalidArgumentException::class );

		Promotion::from_array(
			array(
				'uuid'   => '11111111-1111-4111-8111-111111111111',
				'name'   => 'Bad status',
				'status' => 'not_a_status',
			)
		);
	}

	public function test_with_rules_returns_new_instance(): void {
		$original = Promotion::from_array(
			array(
				'uuid'         => '11111111-1111-4111-8111-111111111111',
				'name'         => 'Original',
				'status'       => PromotionStatus::DRAFT,
				'conditions'   => array(),
				'actions'      => array(),
				'restrictions' => array(),
			)
		);

		$updated = $original->with_rules(
			array( array( 'type' => RuleTypes::CONDITION_LOGGED_IN ) ),
			array(
				array(
					'type'       => RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
					'percentage' => 5.0,
				),
			),
			array( 'max_uses' => 1 )
		);

		$this->assertNotSame( $original, $updated );
		$this->assertSame( array(), $original->get_conditions() );
		$this->assertCount( 1, $updated->get_conditions() );
		$this->assertCount( 1, $updated->get_actions() );
		$this->assertSame( array( 'max_uses' => 1 ), $updated->get_restrictions() );
	}

	public function test_with_status_validates(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'   => '11111111-1111-4111-8111-111111111111',
				'name'   => 'Promo',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$active = $promotion->with_status( PromotionStatus::ACTIVE );
		$this->assertSame( PromotionStatus::ACTIVE, $active->get_status() );

		$this->expectException( InvalidArgumentException::class );
		$promotion->with_status( 'invalid' );
	}

	public function test_from_array_includes_application_rules_defaults(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'   => '11111111-1111-4111-8111-111111111111',
				'name'   => 'Promo',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$this->assertSame( PromotionApplicationMode::EXCLUSIVE, $promotion->get_application_mode() );
		$this->assertTrue( $promotion->should_stop_processing() );
		$this->assertNull( $promotion->get_max_applications() );

		$array = $promotion->to_array();
		$this->assertSame( PromotionApplicationMode::EXCLUSIVE, $array['application_mode'] );
		$this->assertTrue( $array['stop_processing'] );
	}

	public function test_with_application_rules(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'   => '11111111-1111-4111-8111-111111111111',
				'name'   => 'Promo',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$updated = $promotion->with_application_rules( PromotionApplicationMode::STACKABLE, false, 3 );
		$this->assertSame( PromotionApplicationMode::STACKABLE, $updated->get_application_mode() );
		$this->assertFalse( $updated->should_stop_processing() );
		$this->assertSame( 3, $updated->get_max_applications() );
	}

	public function test_with_usage_count_rejects_negative(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'        => '11111111-1111-4111-8111-111111111111',
				'name'        => 'Promo',
				'status'      => PromotionStatus::ACTIVE,
				'usage_count' => 0,
			)
		);

		$this->expectException( InvalidArgumentException::class );
		$promotion->with_usage_count( -1 );
	}
}
