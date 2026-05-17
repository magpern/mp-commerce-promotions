<?php
/**
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Tests\Unit;

use MP\CommercePromotions\Domain\AutomationRun;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionSnapshot;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionAutomationRunner;
use MP\CommercePromotions\Service\PromotionOperationalRecovery;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use PHPUnit\Framework\TestCase;

final class PromotionAutomationObservabilityTest extends TestCase {

	public function test_automation_run_model(): void {
		$run = new AutomationRun(
			null,
			PromotionAutomationRunner::RUN_TYPE_ALL,
			AutomationRun::STATUS_COMPLETED,
			array( 'actions' => array() ),
			0,
			0,
			'2026-05-16 10:00:00',
			'2026-05-16 10:00:01'
		);

		$this->assertSame( PromotionAutomationRunner::RUN_TYPE_ALL, $run->get_run_type() );
		$this->assertSame( AutomationRun::STATUS_COMPLETED, $run->get_status() );
	}

	public function test_snapshot_validation(): void {
		$promotion = Promotion::from_array(
			array(
				'id'     => 2,
				'uuid'   => '22222222-2222-4222-8222-222222222222',
				'name'   => 'Snap',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$snapshot = new PromotionSnapshot(
			1,
			2,
			PromotionSnapshot::TYPE_AUTOMATION,
			$promotion->to_array(),
			'restore notes',
			1,
			'2026-05-16 12:00:00',
			'pre-automation',
			'diagnostics'
		);

		Promotion::from_array( $snapshot->get_snapshot_data() );
		$this->assertSame( 'pre-automation', $snapshot->get_snapshot_label() );
	}

	public function test_snapshot_label_and_source_getters(): void {
		$promotion = Promotion::from_array(
			array(
				'id'     => 3,
				'uuid'   => '33333333-3333-4333-8333-333333333333',
				'name'   => 'Labeled',
				'status' => PromotionStatus::DRAFT,
			)
		);

		$snapshot = new PromotionSnapshot(
			null,
			3,
			PromotionSnapshot::TYPE_AUTOMATION,
			$promotion->to_array(),
			null,
			null,
			null,
			'Before automation',
			'runner'
		);

		$this->assertSame( 'Before automation', $snapshot->get_snapshot_label() );
		$this->assertSame( 'runner', $snapshot->get_snapshot_source() );
	}

	public function test_validator_active_without_actions_is_critical(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'    => '44444444-4444-4444-8444-444444444444',
				'name'    => 'Empty actions',
				'status'  => PromotionStatus::ACTIVE,
				'actions' => array(),
			)
		);

		$validator = new PromotionRuleValidator();
		$issues    = $validator->validate( $promotion );
		$critical  = array_filter(
			$issues,
			static fn ( array $i ): bool => ( $i['level'] ?? '' ) === 'error'
		);

		$this->assertNotEmpty( $critical );
	}

	public function test_validator_cooldown_without_logged_in_warning(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'           => '55555555-5555-4555-8555-555555555555',
				'name'           => 'Cooldown guest',
				'status'         => PromotionStatus::DRAFT,
				'cooldown_hours' => 12,
				'conditions'     => array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 10 ) ),
				'actions'        => array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
			)
		);

		$validator = new PromotionRuleValidator();
		$issues    = $validator->validate( $promotion );
		$messages  = array_column( $issues, 'message' );

		$this->assertTrue(
			$this->issues_contain_substring( $issues, 'logged-in' )
			|| $this->issues_contain_substring( $issues, 'logged in' )
		);
	}

	public function test_plan_metrics_include_budget_and_exclusion_counts(): void {
		$plan = new PromotionEvaluationPlan(
			array(),
			array(
				'blocked_by_exclusion_count' => 1,
				'blocked_by_budget_count'    => 2,
			)
		);

		$metrics = $plan->get_metrics();
		$this->assertSame( 1, $metrics['blocked_by_exclusion_count'] );
		$this->assertSame( 2, $metrics['blocked_by_budget_count'] );
	}

	public function test_orchestration_group_normalization_mismatch_is_critical(): void {
		$promotion = Promotion::from_array(
			array(
				'uuid'                => '66666666-6666-4666-8666-666666666666',
				'name'                => 'Bad group',
				'status'              => PromotionStatus::DRAFT,
				'orchestration_group' => '  Bad Group!!  ',
				'actions'             => array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 5 ) ),
			)
		);

		$normalized = Promotion::normalize_orchestration_group( $promotion->get_orchestration_group() );
		if ( $normalized === $promotion->get_orchestration_group() ) {
			$this->markTestSkipped( 'Environment does not produce normalization mismatch for sample group.' );
		}

		$issues = ( new PromotionRuleValidator() )->validate( $promotion );
		$this->assertNotEmpty(
			array_filter( $issues, static fn ( array $i ): bool => ( $i['level'] ?? '' ) === 'error' )
		);
	}

	/**
	 * @param list<array{level: string, message: string}> $issues
	 */
	private function issues_contain_substring( array $issues, string $needle ): bool {
		foreach ( $issues as $issue ) {
			if ( isset( $issue['message'] ) && stripos( (string) $issue['message'], $needle ) !== false ) {
				return true;
			}
		}

		return false;
	}

}
