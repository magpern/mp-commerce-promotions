<?php
/**
 * Compare current promotion state to a stored snapshot (admin preview).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionSnapshot;

final class PromotionSnapshotDiffService {

	/** @var list<string> */
	private const TRACKED_FIELDS = array(
		'name',
		'status',
		'discount_application_mode',
		'application_mode',
		'orchestration_group',
		'budget_amount',
		'budget_spent',
		'budget_currency',
		'dry_run',
		'priority',
		'coupon_behavior',
	);

	/**
	 * @return array{
	 *     changed_fields: list<array{field: string, before: mixed, after: mixed}>,
	 *     risk_indicators: list<array{code: string, severity: string, message: string}>,
	 *     summary: string
	 * }
	 */
	public function diff_against_snapshot( Promotion $current, PromotionSnapshot $snapshot ): array {
		$before = $snapshot->get_snapshot_data();
		$after  = $current->to_array();
		$changed = array();

		foreach ( self::TRACKED_FIELDS as $field ) {
			$old_val = $before[ $field ] ?? null;
			$new_val = $after[ $field ] ?? null;
			if ( $this->values_differ( $old_val, $new_val ) ) {
				$changed[] = array(
					'field'  => $field,
					'before' => $old_val,
					'after'  => $new_val,
				);
			}
		}

		$risks = $this->build_risk_indicators( $changed );

		return array(
			'changed_fields'   => $changed,
			'risk_indicators'  => $risks,
			'summary'          => $this->build_summary( $changed, $risks ),
		);
	}

	/**
	 * Diff latest snapshot vs current promotion.
	 *
	 * @return array<string, mixed>|null
	 */
	public function diff_latest( Promotion $current, ?PromotionSnapshot $latest ): ?array {
		if ( $latest === null ) {
			return null;
		}

		return $this->diff_against_snapshot( $current, $latest );
	}

	/**
	 * @param list<array{field: string, before: mixed, after: mixed}> $changed
	 * @return list<array{code: string, severity: string, message: string}>
	 */
	private function build_risk_indicators( array $changed ): array {
		$fields = array_column( $changed, 'field' );
		$risks  = array();

		if ( in_array( 'discount_application_mode', $fields, true ) ) {
			$risks[] = array(
				'code'     => 'pricing_mode_changed',
				'severity' => 'high',
				'message'  => __( 'Discount application mode changed since snapshot.', 'mp-commerce-promotions' ),
			);
		}
		if ( in_array( 'application_mode', $fields, true ) || in_array( 'stackable', $fields, true ) ) {
			$risks[] = array(
				'code'     => 'stackability_changed',
				'severity' => 'medium',
				'message'  => __( 'Stackability or application mode changed since snapshot.', 'mp-commerce-promotions' ),
			);
		}
		if ( in_array( 'orchestration_group', $fields, true ) ) {
			$risks[] = array(
				'code'     => 'orchestration_changed',
				'severity' => 'medium',
				'message'  => __( 'Orchestration group changed since snapshot.', 'mp-commerce-promotions' ),
			);
		}
		if ( in_array( 'budget_amount', $fields, true ) || in_array( 'budget_spent', $fields, true ) ) {
			$risks[] = array(
				'code'     => 'budget_changed',
				'severity' => 'medium',
				'message'  => __( 'Budget fields changed since snapshot.', 'mp-commerce-promotions' ),
			);
		}
		if ( in_array( 'dry_run', $fields, true ) ) {
			$risks[] = array(
				'code'     => 'dry_run_changed',
				'severity' => 'low',
				'message'  => __( 'Dry-run flag changed since snapshot.', 'mp-commerce-promotions' ),
			);
		}

		return $risks;
	}

	/**
	 * @param list<array{field: string, before: mixed, after: mixed}> $changed
	 * @param list<array{code: string, severity: string, message: string}> $risks
	 */
	private function build_summary( array $changed, array $risks ): string {
		$count = count( $changed );
		if ( $count === 0 ) {
			return __( 'No tracked field changes since snapshot.', 'mp-commerce-promotions' );
		}

		$high = count(
			array_filter(
				$risks,
				static fn ( array $r ): bool => ( $r['severity'] ?? '' ) === 'high'
			)
		);

		return sprintf(
			/* translators: 1: field change count, 2: high-risk indicator count */
			__( '%1$d tracked field change(s); %2$d high-risk indicator(s).', 'mp-commerce-promotions' ),
			$count,
			$high
		);
	}

	private function values_differ( mixed $a, mixed $b ): bool {
		if ( is_float( $a ) || is_float( $b ) ) {
			return abs( (float) $a - (float) $b ) > 0.0001;
		}

		return $a !== $b;
	}
}
