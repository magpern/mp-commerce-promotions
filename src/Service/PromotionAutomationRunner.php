<?php
/**
 * Manual/admin-triggered promotion lifecycle automation (cron-ready structure).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\AutomationRun;
use MP\CommercePromotions\Domain\AutomationRunRepository;
use Throwable;

final class PromotionAutomationRunner {

	public const RUN_TYPE_ALL = 'run_all';

	public const RUN_TYPE_ACTIVATE_SCHEDULED = 'activate_scheduled';

	public const RUN_TYPE_ARCHIVE_EXPIRED = 'archive_expired';

	public const RUN_TYPE_PAUSE_BUDGET = 'pause_budget_exhausted';

	public const RUN_TYPE_NORMALIZE = 'normalize_states';

	private PromotionService $promotions;

	private ?AutomationRunRepository $runs;

	public function __construct(
		PromotionService $promotions,
		?AutomationRunRepository $runs = null
	) {
		$this->promotions = $promotions;
		$this->runs       = $runs;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function run_all( ?int $actor_user_id = null ): array {
		return $this->execute( self::RUN_TYPE_ALL, function () use ( $actor_user_id ): array {
			$summary = array(
				'actions' => array(),
			);

			$summary['actions']['activate_scheduled']       = $this->activate_scheduled( $actor_user_id );
			$summary['actions']['archive_expired']          = $this->archive_expired( $actor_user_id );
			$summary['actions']['pause_budget_exhausted']   = $this->pause_budget_exhausted( $actor_user_id );
			$summary['actions']['normalize_states']         = $this->normalize_states( $actor_user_id );

			return $summary;
		} );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function activate_scheduled( ?int $actor_user_id = null ): array {
		return $this->execute( self::RUN_TYPE_ACTIVATE_SCHEDULED, function () use ( $actor_user_id ): array {
			return array(
				'actions' => array(
					'activate_scheduled' => $this->promotions->activate_scheduled_promotions( $actor_user_id ),
				),
			);
		} );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function archive_expired( ?int $actor_user_id = null ): array {
		return $this->execute( self::RUN_TYPE_ARCHIVE_EXPIRED, function () use ( $actor_user_id ): array {
			return array(
				'actions' => array(
					'archive_expired_active' => $this->promotions->archive_expired_active_promotions( $actor_user_id ),
					'archive_expired_paused' => $this->promotions->archive_expired_paused_promotions( $actor_user_id ),
				),
			);
		} );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function pause_budget_exhausted( ?int $actor_user_id = null ): array {
		return $this->execute( self::RUN_TYPE_PAUSE_BUDGET, function () use ( $actor_user_id ): array {
			return array(
				'actions' => array(
					'pause_budget_exhausted' => $this->promotions->pause_budget_exhausted_promotions( $actor_user_id ),
				),
			);
		} );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function normalize_states( ?int $actor_user_id = null ): array {
		return $this->execute( self::RUN_TYPE_NORMALIZE, function () use ( $actor_user_id ): array {
			return array(
				'actions' => array(
					'normalize_states' => $this->promotions->normalize_invalid_promotion_states( $actor_user_id ),
				),
			);
		} );
	}

	/**
	 * @param callable(): array<string, mixed> $runner
	 * @return array<string, mixed>
	 */
	private function execute( string $run_type, callable $runner ): array {
		$started = current_time( 'mysql' );
		$summary = array(
			'started_at'  => $started,
			'finished_at' => null,
			'actions'     => array(),
			'warnings'    => array(),
			'errors'      => array(),
		);

		$status = AutomationRun::STATUS_COMPLETED;

		try {
			$result            = $runner();
			$summary['actions'] = $result['actions'] ?? array();
			$summary           = array_merge( $summary, $this->collect_warnings_and_errors( $summary['actions'] ) );
		} catch ( Throwable $e ) {
			$status             = AutomationRun::STATUS_FAILED;
			$summary['errors'][] = array(
				'message' => $e->getMessage(),
			);
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] Automation runner failed: ' . $e->getMessage() );
			}
		}

		$summary['finished_at'] = current_time( 'mysql' );

		$this->persist_run(
			$run_type,
			$status,
			$summary,
			count( $summary['warnings'] ),
			count( $summary['errors'] )
		);

		return $summary;
	}

	/**
	 * @param array<string, mixed> $actions
	 * @return array{warnings: list<array<string, mixed>>, errors: list<array<string, mixed>>}
	 */
	private function collect_warnings_and_errors( array $actions ): array {
		$warnings = array();
		$errors   = array();

		foreach ( $actions as $action_key => $batch ) {
			if ( ! is_array( $batch ) ) {
				continue;
			}

			if ( isset( $batch['warnings'] ) && is_array( $batch['warnings'] ) ) {
				foreach ( $batch['warnings'] as $warning ) {
					$warnings[] = array(
						'action'  => $action_key,
						'details' => $warning,
					);
				}
			}

			if ( isset( $batch['errors'] ) && is_array( $batch['errors'] ) ) {
				foreach ( $batch['errors'] as $error ) {
					$errors[] = array(
						'action'  => $action_key,
						'details' => $error,
					);
				}
			}
		}

		return array(
			'warnings' => $warnings,
			'errors'   => $errors,
		);
	}

	/**
	 * @param array<string, mixed> $summary
	 */
	private function persist_run(
		string $run_type,
		string $status,
		array $summary,
		int $warnings_count,
		int $errors_count
	): void {
		if ( $this->runs === null ) {
			return;
		}

		$run = new AutomationRun(
			null,
			$run_type,
			$status,
			$summary,
			$warnings_count,
			$errors_count,
			(string) ( $summary['started_at'] ?? current_time( 'mysql' ) ),
			isset( $summary['finished_at'] ) ? (string) $summary['finished_at'] : null
		);

		$this->runs->insert( $run );
	}
}
