<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Tests\Support\PromotionTestFixtures;
use MP\CommercePromotions\Service\EcosystemCompatibilityRegistry;
use MP\CommercePromotions\Service\KnownLimitationsRegistry;
use MP\CommercePromotions\Service\MerchantSafetyAdvisor;
use MP\CommercePromotions\Service\PromotionComplexityScorer;
use MP\CommercePromotions\Service\PromotionConcurrencyGuard;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use MP\CommercePromotions\Service\Settings;
use MP\CommercePromotions\Service\SystemHealthService;
use PHPUnit\Framework\TestCase;

final class GaStabilizationTest extends TestCase {

	public function test_ecosystem_matrix_has_core_slugs(): void {
		$matrix = ( new EcosystemCompatibilityRegistry() )->build_matrix( false );
		$slugs  = array_column( $matrix, 'slug' );

		$this->assertContains( 'woocommerce_subscriptions', $slugs );
		$this->assertContains( 'hpos', $slugs );
		$this->assertContains( 'cart_checkout_blocks', $slugs );
	}

	public function test_known_limitations_lookup(): void {
		$row = KnownLimitationsRegistry::lookup( 'multi_currency_plugin' );
		$this->assertNotSame( '', $row['summary'] );
	}

	public function test_complexity_scorer_tiers(): void {
		$row   = PromotionTestFixtures::active_promotion_with_id(
			1,
			array(),
			array( array( 'type' => 'percentage_discount', 'percentage' => 10 ) )
		)->to_array();
		$promo  = Promotion::from_array( $row );
		global $wpdb;
		if ( ! isset( $wpdb ) || ! ( $wpdb instanceof \wpdb ) ) {
			$wpdb = new \wpdb();
		}
		$scored = ( new PromotionComplexityScorer( new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ) ) )
			->score_promotion( $promo );
		$this->assertArrayHasKey( 'score', $scored );
		$this->assertContains( $scored['tier'], array( 'low', 'medium', 'high' ) );
	}

	public function test_merchant_safety_high_percentage(): void {
		$row   = PromotionTestFixtures::active_promotion_with_id(
			9,
			array(),
			array( array( 'type' => 'percentage_discount', 'percentage' => 60 ) )
		)->to_array();
		$promo = Promotion::from_array( $row );
		global $wpdb;
		if ( ! isset( $wpdb ) || ! ( $wpdb instanceof \wpdb ) ) {
			$wpdb = new \wpdb();
		}
		$issues = ( new MerchantSafetyAdvisor( new \MP\CommercePromotions\Domain\PromotionRepository( $wpdb ) ) )
			->analyze_promotion( $promo );

		$this->assertNotEmpty( $issues );
		$this->assertSame( 'high_percentage_discount', $issues[0]['code'] ?? '' );
	}

	public function test_profiler_timing_buckets(): void {
		$profiler = new PromotionPerformanceProfiler();
		$profiler->record_planner_run(
			array(
				'duration_ms'           => 120,
				'evaluator_calls'       => 1,
				'condition_checks'      => 1,
				'action_count'          => 1,
				'promotions_considered' => 1,
				'selected_count'        => 1,
			)
		);
		$summary = $profiler->get_report_summary();
		$this->assertArrayHasKey( 'timing_buckets', $summary );
		$this->assertNotEmpty( $summary['timing_buckets'] );
	}

	public function test_concurrency_purge_stale_locks_dry_run(): void {
		$guard = new PromotionConcurrencyGuard();
		$result = $guard->purge_stale_locks( true );
		$this->assertTrue( $result['dry_run'] );
		$this->assertIsArray( $result['purged'] );
	}

	public function test_system_health_collect(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) && defined( 'MP_COMMERCE_PROMOTIONS_FILE' ) ) {
			\MP\CommercePromotions\Woo\WooCompatibility::declare_feature_compatibility();
		}
		$health = new SystemHealthService(
			new Settings(),
			new PromotionPerformanceProfiler(),
			new PromotionConcurrencyGuard()
		);
		$data = $health->collect( false );
		$this->assertArrayHasKey( 'score', $data );
		$this->assertGreaterThanOrEqual( 0, (int) $data['score'] );
	}

	public function test_settings_promotion_dry_run_flag(): void {
		$settings = new Settings();
		$this->assertFalse( $settings->promotion_dry_run_enabled() );
		$settings->set_promotion_dry_run_enabled( true );
		$this->assertTrue( $settings->promotion_dry_run_enabled() );
	}
}
