<?php
/**
 * Isolated recovery for malformed telemetry/scenarios and analyzer failures.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\SimulationScenarioRepository;
use MP\CommercePromotions\Woo\PricingCompatibilityAnalyzer;
use Throwable;

final class PromotionSubsystemRecovery {

	private PromotionIntelligenceRecovery $intelligence;

	public function __construct(
		PlannerTelemetryRepository $telemetry,
		SimulationScenarioRepository $scenarios
	) {
		$this->intelligence = new PromotionIntelligenceRecovery( $telemetry, $scenarios );
	}

	/**
	 * @return array{dry_run: bool, deleted_rows: int}
	 */
	public function recover_malformed_telemetry( bool $dry_run = true ): array {
		return $this->intelligence->reset_telemetry( $dry_run );
	}

	/**
	 * @return array{dry_run: bool, repaired: int, archived: int}
	 */
	public function recover_malformed_scenarios( bool $dry_run = true ): array {
		return $this->intelligence->repair_malformed_simulation_rows( $dry_run );
	}

	/**
	 * @return array{confidence: string, issues: list<array<string, string>>}
	 */
	public function safe_compatibility_audit(): array {
		try {
			$analyzer = new PricingCompatibilityAnalyzer();
			$audit    = $analyzer->audit_with_confidence( false );

			return array(
				'confidence' => (string) ( $audit['confidence'] ?? PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN ),
				'issues'     => array(),
			);
		} catch ( Throwable $e ) {
			return array(
				'confidence' => PricingCompatibilityAnalyzer::CONFIDENCE_UNKNOWN,
				'issues'     => array(
					array(
						'code'    => 'analyzer_failed',
						'message' => $e->getMessage(),
					),
				),
			);
		}
	}
}
