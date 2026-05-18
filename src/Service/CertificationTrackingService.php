<?php
/**
 * Checkout certification run recording and staleness checks.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\CertificationRun;
use MP\CommercePromotions\Domain\CertificationRunRepository;
use MP\CommercePromotions\Service\BlockTestPages;

final class CertificationTrackingService {

	private CertificationRunRepository $repository;

	public function __construct( CertificationRunRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @param array<string, mixed> $metadata
	 */
	public function record(
		string $type,
		string $status,
		?string $environment = null,
		?string $payment_gateway = null,
		?string $operator_notes = null,
		array $metadata = array(),
		?int $created_by = null
	): int {
		if ( ! in_array( $type, CertificationRun::allowed_types(), true ) ) {
			return 0;
		}

		$run = new CertificationRun(
			null,
			$type,
			$status,
			$environment,
			$payment_gateway,
			$operator_notes,
			$metadata,
			current_time( 'mysql' ),
			$created_by
		);

		return $this->repository->insert( $run );
	}

	/**
	 * @return list<CertificationRun>
	 */
	public function latest_per_type(): array {
		return $this->repository->find_latest_per_type();
	}

	/**
	 * @return list<array{type: string, label: string, status: string, certified_at: string, stale: bool}>
	 */
	public function dashboard_rows( int $stale_days = 30 ): array {
		$labels = array(
			CertificationRun::TYPE_CLASSIC_CHECKOUT    => __( 'Classic checkout', 'mp-commerce-promotions' ),
			CertificationRun::TYPE_BLOCKS_CHECKOUT     => __( 'Cart/Checkout Blocks', 'mp-commerce-promotions' ),
			CertificationRun::TYPE_LINE_MODE           => __( 'Line discount mode', 'mp-commerce-promotions' ),
			CertificationRun::TYPE_COUPON_COEXISTENCE  => __( 'Coupon coexistence', 'mp-commerce-promotions' ),
		);

		$latest = array();
		foreach ( $this->repository->find_latest_per_type() as $run ) {
			$latest[ $run->get_certification_type() ] = $run;
		}

		$cutoff = strtotime( '-' . $stale_days . ' days' );
		$rows   = array();

		foreach ( CertificationRun::allowed_types() as $type ) {
			$run = $latest[ $type ] ?? null;
			if ( $run === null ) {
				$rows[] = array(
					'type'         => $type,
					'label'        => $labels[ $type ] ?? $type,
					'status'       => 'missing',
					'certified_at' => '',
					'stale'        => true,
				);
				continue;
			}

			$at    = strtotime( $run->get_certified_at() );
			$stale = $at === false || $at < $cutoff;

			$rows[] = array(
				'type'         => $type,
				'label'        => $labels[ $type ] ?? $type,
				'status'       => $run->get_status(),
				'certified_at' => $run->get_certified_at(),
				'stale'        => $stale,
			);
		}

		return $rows;
	}

	public function import_blocks_status_from_option(): int {
		$status = get_option( 'mp_cp_block_compatibility_status', '' );
		if ( ! is_string( $status ) || $status === '' ) {
			return 0;
		}

		$mapped = $status === BlockTestPages::STATUS_PASSED
			? CertificationRun::STATUS_PASSED
			: ( $status === BlockTestPages::STATUS_PARTIAL ? CertificationRun::STATUS_PARTIAL : CertificationRun::STATUS_FAILED );

		return $this->record(
			CertificationRun::TYPE_BLOCKS_CHECKOUT,
			$mapped,
			'docker-local',
			'cod',
			(string) get_option( 'mp_cp_block_compatibility_notes', '' ),
			array( 'imported_from_option' => true )
		);
	}
}
