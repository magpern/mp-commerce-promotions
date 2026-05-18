<?php
/**
 * Redacted support/debug JSON export (no PII, no raw codes).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\AutomationRunRepository;
use MP\CommercePromotions\Domain\PlannerTelemetryRepository;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Infrastructure\Database\MigrationRunner;
use MP\CommercePromotions\Infrastructure\Database\Schema;
use MP\CommercePromotions\GiftCard\GiftCardMailDiagnostics;
use MP\CommercePromotions\Service\MultiCurrencyCompatibility;
use MP\CommercePromotions\Service\PromotionPerformanceProfiler;
use wpdb;

final class SupportBundleExporter {

	private Settings $settings;

	private CompatibilityStatus $compatibility;

	private ?PromotionRepository $promotions;

	private ?RedemptionRepository $redemptions;

	private ?PromotionCodeRepository $codes;

	private ?PromotionCodeBatchRepository $batches;

	private ?AutomationRunRepository $automation_runs;

	private ?PromotionHealthMonitor $health_monitor;

	public function __construct(
		Settings $settings,
		?PromotionRepository $promotions = null,
		?RedemptionRepository $redemptions = null,
		?PromotionCodeRepository $codes = null,
		?PromotionCodeBatchRepository $batches = null,
		?AutomationRunRepository $automation_runs = null,
		?PromotionHealthMonitor $health_monitor = null
	) {
		$this->settings        = $settings;
		$this->compatibility   = new CompatibilityStatus();
		$this->promotions      = $promotions;
		$this->redemptions     = $redemptions;
		$this->codes           = $codes;
		$this->batches         = $batches;
		$this->automation_runs = $automation_runs;
		$this->health_monitor  = $health_monitor;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function build(): array {
		$bundle = array(
			'generated_at'     => gmdate( 'c' ),
			'plugin_version'   => defined( 'MP_COMMERCE_PROMOTIONS_VERSION' ) ? MP_COMMERCE_PROMOTIONS_VERSION : '',
			'schema_version'   => get_option( MigrationRunner::OPTION_SCHEMA_VERSION, '' ),
			'environment'      => $this->compatibility->collect(),
			'settings'         => $this->settings->to_feature_flags(),
			'counts'           => $this->counts(),
			'automation_runs'  => $this->recent_automation_runs(),
			'health_issues'           => $this->health_issues(),
			'currency_compatibility'  => ( new MultiCurrencyCompatibility() )->snapshot(),
			'coupon_coexistence_telemetry' => ( new PromotionPerformanceProfiler() )->get_report_summary(),
			'gift_card_mail'                => $this->gift_card_mail_summary(),
			'redaction_notice'        => 'No customer PII or raw promotion codes are included.',
		);

		return $this->redact_sensitive( $bundle );
	}

	public function to_json(): string {
		$encoded = wp_json_encode( $this->build(), JSON_PRETTY_PRINT );

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * @return array<string, int>
	 */
	private function counts(): array {
		$counts = array(
			'promotions'        => 0,
			'active_promotions' => 0,
			'redemptions'       => 0,
			'promotion_codes'   => 0,
			'code_batches'      => 0,
		);

		if ( $this->promotions !== null ) {
			$counts['promotions']        = $this->promotions->count_all();
			$counts['active_promotions'] = $this->promotions->count_filtered(
				array(
					'status' => PromotionStatus::ACTIVE,
					'limit'  => 1,
				)
			);
		}

		if ( $this->redemptions !== null ) {
			$counts['redemptions'] = $this->redemptions->count_recorded();
		}

		if ( $this->codes !== null && $this->promotions !== null ) {
			$total_codes = 0;
			foreach ( $this->promotions->find_filtered( array( 'limit' => 500 ) ) as $promotion ) {
				$id = $promotion->get_id();
				if ( $id !== null && $id > 0 ) {
					$total_codes += $this->codes->count_for_promotion( $id );
				}
			}
			$counts['promotion_codes'] = $total_codes;
		}

		if ( $this->batches !== null && $this->promotions !== null ) {
			$total_batches = 0;
			foreach ( $this->promotions->find_filtered( array( 'limit' => 500 ) ) as $promotion ) {
				$id = $promotion->get_id();
				if ( $id !== null && $id > 0 ) {
					$total_batches += $this->batches->count_for_promotion( $id );
				}
			}
			$counts['code_batches'] = $total_batches;
		}

		return $counts;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function recent_automation_runs(): array {
		if ( $this->automation_runs === null ) {
			return array();
		}

		$rows = $this->automation_runs->find_latest( 10 );
		$safe = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$safe[] = array(
				'id'         => (int) ( $row['id'] ?? 0 ),
				'run_type'   => isset( $row['run_type'] ) ? (string) $row['run_type'] : '',
				'status'     => isset( $row['status'] ) ? (string) $row['status'] : '',
				'started_at' => isset( $row['started_at'] ) ? (string) $row['started_at'] : '',
				'summary'    => isset( $row['summary_json'] ) ? '[redacted]' : '',
			);
		}

		return $safe;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function gift_card_mail_summary(): array {
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) {
			return array();
		}

		$diag   = new GiftCardMailDiagnostics( $wpdb, $this->settings );
		$report = $diag->analyze();

		return array(
			'delivery_email_enabled' => $report['delivery_email_enabled'] ?? false,
			'recent_delivery_failed' => $report['recent_delivery_failed'] ?? 0,
			'wp_mail_likely_failing' => $report['wp_mail_likely_failing'] ?? false,
			'settings'               => $diag->settings_summary(),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function health_issues(): array {
		if ( $this->health_monitor === null ) {
			return array();
		}

		$raw    = $this->health_monitor->analyze( 100 );
		$issues = array();
		foreach ( $raw as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}
			$issues[] = array(
				'severity'      => isset( $issue['severity'] ) ? (string) $issue['severity'] : '',
				'code'          => isset( $issue['code'] ) ? (string) $issue['code'] : '',
				'message'       => isset( $issue['message'] ) ? (string) $issue['message'] : '',
				'promotion_ids' => isset( $issue['promotion_ids'] ) && is_array( $issue['promotion_ids'] )
					? array_map( 'intval', $issue['promotion_ids'] )
					: array(),
			);
		}

		return $issues;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function redact_sensitive( array $data ): array {
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) ) {
			return $data;
		}

		$json = preg_replace( '/"code_hash"\s*:\s*"[^"]*"/', '"code_hash":"[redacted]"', $json ) ?? $json;
		$json = preg_replace( '/"plain_code"\s*:\s*"[^"]*"/', '"plain_code":"[redacted]"', $json ) ?? $json;
		$json = preg_replace( '/"email"\s*:\s*"[^"]*"/', '"email":"[redacted]"', $json ) ?? $json;

		$decoded = json_decode( $json, true );

		return is_array( $decoded ) ? $decoded : $data;
	}
}
