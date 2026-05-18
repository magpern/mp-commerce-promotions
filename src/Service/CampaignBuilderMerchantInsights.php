<?php
/**
 * Merchant confidence, risk badges, and impact copy for Campaign Builder (delegates to analyzers).
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;

final class CampaignBuilderMerchantInsights {

	public const CONFIDENCE_SAFE = 'safe';

	public const CONFIDENCE_CAUTION = 'caution';

	public const CONFIDENCE_RISK = 'risk';

	private PromotionRepository $promotions;

	private MerchantSafetyAdvisor $safety;

	private ScheduleConflictPreviewService $schedule_preview;

	private PromotionConflictAnalyzer $conflicts;

	private ?CampaignBuilderDraftCreator $draft_creator;

	public function __construct(
		PromotionRepository $promotions,
		?MerchantSafetyAdvisor $safety = null,
		?ScheduleConflictPreviewService $schedule_preview = null,
		?PromotionConflictAnalyzer $conflicts = null,
		?CampaignBuilderDraftCreator $draft_creator = null
	) {
		$this->promotions       = $promotions;
		$this->safety           = $safety ?? new MerchantSafetyAdvisor( $promotions );
		$this->schedule_preview = $schedule_preview ?? new ScheduleConflictPreviewService();
		$this->conflicts        = $conflicts ?? new PromotionConflictAnalyzer();
		$this->draft_creator    = $draft_creator;
	}

	/**
	 * @param array<string, mixed> $ui
	 * @return array{
	 *     confidence: string,
	 *     confidence_label: string,
	 *     impact: string,
	 *     badges: list<array{level: string, text: string}>,
	 *     warnings: list<string>,
	 *     recommendations: list<string>
	 * }
	 */
	public function analyze_form( string $goal, array $ui, ?Promotion $draft = null ): array {
		$promotion = $draft;
		if ( $promotion === null && $this->draft_creator !== null ) {
			try {
				$form  = $this->form_from_ui( $goal, $ui );
				$rules = $this->draft_creator->build_rules( $goal, $form );
				$promotion = Promotion::from_array(
					array(
						'id'               => 0,
						'name'             => (string) ( $ui['campaign_name'] ?? 'Preview' ),
						'status'           => PromotionStatus::DRAFT,
						'conditions'       => $rules['conditions'],
						'actions'          => $rules['actions'],
						'restrictions'     => $rules['restrictions'],
						'application_mode' => ! empty( $ui['stackable'] )
							? PromotionApplicationMode::STACKABLE
							: PromotionApplicationMode::EXCLUSIVE,
						'stop_processing'  => empty( $ui['stackable'] ),
						'budget_amount'    => $ui['budget_amount'] ?? null,
						'usage_limit'      => $ui['usage_limit'] ?? null,
						'starts_at'        => $ui['starts_at'] ?? null,
						'ends_at'          => $ui['ends_at'] ?? null,
					)
				);
			} catch ( \Throwable $e ) {
				return $this->incomplete_form_result();
			}
		}

		if ( $promotion === null ) {
			return $this->incomplete_form_result();
		}

		return $this->analyze_promotion( $promotion, $goal, $ui );
	}

	/**
	 * @param array<string, mixed> $ui
	 * @return array{
	 *     confidence: string,
	 *     confidence_label: string,
	 *     impact: string,
	 *     badges: list<array{level: string, text: string}>,
	 *     warnings: list<string>,
	 *     recommendations: list<string>
	 * }
	 */
	public function analyze_promotion( Promotion $promotion, ?string $goal = null, array $ui = array() ): array {
		$badges     = array();
		$warnings   = array();
		$confidence = self::CONFIDENCE_SAFE;

		foreach ( $this->safety->analyze_promotion( $promotion ) as $issue ) {
			$msg = (string) ( $issue['message'] ?? '' );
			if ( $msg === '' ) {
				continue;
			}
			$sev = (string) ( $issue['severity'] ?? MerchantSafetyAdvisor::SEVERITY_INFO );
			$warnings[] = $msg;
			$badges[]   = array(
				'level' => $sev === MerchantSafetyAdvisor::SEVERITY_CRITICAL ? 'risk' : 'warn',
				'text'  => $msg,
			);
			if ( $sev === MerchantSafetyAdvisor::SEVERITY_CRITICAL ) {
				$confidence = self::CONFIDENCE_RISK;
			} elseif ( $sev === MerchantSafetyAdvisor::SEVERITY_WARNING && $confidence !== self::CONFIDENCE_RISK ) {
				$confidence = self::CONFIDENCE_CAUTION;
			}
		}

		if ( LineDiscountModeHelper::uses_experimental_line_mode( $promotion ) ) {
			$warnings[] = __( 'Line discounts experimental — review in Advanced Editor before going live.', 'mp-commerce-promotions' );
			$badges[]   = array(
				'level' => 'warn',
				'text'  => __( 'Line discounts experimental', 'mp-commerce-promotions' ),
			);
			if ( $confidence === self::CONFIDENCE_SAFE ) {
				$confidence = self::CONFIDENCE_CAUTION;
			}
		}

		$catalog = $this->promotions->find_filtered( array( 'limit' => 100 ) );
		foreach ( $this->schedule_preview->preview_for_promotion( $promotion, $catalog ) as $row ) {
			$msg = (string) ( $row['message'] ?? '' );
			if ( $msg === '' ) {
				continue;
			}
			$warnings[] = $msg;
			$badges[]   = array(
				'level' => (string) ( $row['severity'] ?? 'info' ) === 'critical' ? 'risk' : 'warn',
				'text'  => $msg,
			);
			if ( $confidence === self::CONFIDENCE_SAFE ) {
				$confidence = self::CONFIDENCE_CAUTION;
			}
		}

		$active = array_values(
			array_filter(
				$catalog,
				static fn ( Promotion $p ): bool => $p->get_status() === PromotionStatus::ACTIVE
			)
		);
		if ( $promotion->get_id() !== null && $promotion->get_id() > 0 ) {
			foreach ( $this->conflicts->analyze( $active ) as $conflict ) {
				$ids = isset( $conflict['promotion_ids'] ) && is_array( $conflict['promotion_ids'] )
					? array_map( 'intval', $conflict['promotion_ids'] ) : array();
				if ( ! in_array( (int) $promotion->get_id(), $ids, true ) ) {
					continue;
				}
				$msg = (string) ( $conflict['message'] ?? '' );
				if ( $msg !== '' ) {
					$warnings[] = $msg;
					$badges[]   = array(
						'level' => 'warn',
						'text'  => __( 'May conflict with other active campaigns', 'mp-commerce-promotions' ),
					);
					if ( $confidence === self::CONFIDENCE_SAFE ) {
						$confidence = self::CONFIDENCE_CAUTION;
					}
				}
			}
		} elseif ( ! empty( $ui['stackable'] ) ) {
			$badges[] = array(
				'level' => 'info',
				'text'  => __( 'Stackable — check overlap with live campaigns after activation', 'mp-commerce-promotions' ),
			);
		}

		$impact = $this->impact_text( $promotion, $ui );

		return array(
			'confidence'       => $confidence,
			'confidence_label' => self::confidence_label( $confidence ),
			'impact'           => $impact,
			'badges'           => $this->dedupe_badges( $badges ),
			'warnings'         => array_values( array_unique( $warnings ) ),
			'recommendations'  => $this->recommendations( $confidence, $goal ),
		);
	}

	public static function confidence_label( string $confidence ): string {
		$labels = array(
			self::CONFIDENCE_SAFE    => __( 'Safe to activate', 'mp-commerce-promotions' ),
			self::CONFIDENCE_CAUTION => __( 'Review before activating', 'mp-commerce-promotions' ),
			self::CONFIDENCE_RISK    => __( 'High discount risk', 'mp-commerce-promotions' ),
		);

		return $labels[ $confidence ] ?? $confidence;
	}

	/**
	 * @param array<string, mixed> $ui
	 */
	private function impact_text( Promotion $promotion, array $ui ): string {
		$exposure = $this->safety->estimate_max_cart_exposure( $promotion );
		if ( $exposure > 0 ) {
			$formatted = function_exists( 'wc_price' )
				? wp_strip_all_tags( wc_price( $exposure ) )
				: (string) $exposure;

			return sprintf(
				/* translators: %s: estimated discount per cart */
				__( 'Estimated impact: up to %s discount per qualifying cart (heuristic).', 'mp-commerce-promotions' ),
				$formatted
			);
		}

		$budget = trim( (string) ( $ui['budget_amount'] ?? '' ) );
		if ( $budget !== '' && is_numeric( $budget ) && (float) $budget > 0 ) {
			return __( 'Budget cap limits total campaign spend.', 'mp-commerce-promotions' );
		}

		return __( 'Impact depends on cart size and how many customers qualify.', 'mp-commerce-promotions' );
	}

	/**
	 * @param array<string, mixed> $ui
	 * @return array<string, mixed>
	 */
	private function form_from_ui( string $goal, array $ui ): array {
		return array_merge(
			array( 'campaign_goal' => $goal ),
			$ui
		);
	}

	/**
	 * @return array{
	 *     confidence: string,
	 *     confidence_label: string,
	 *     impact: string,
	 *     badges: list<array{level: string, text: string}>,
	 *     warnings: list<string>,
	 *     recommendations: list<string>
	 * }
	 */
	private function incomplete_form_result(): array {
		return array(
			'confidence'       => self::CONFIDENCE_CAUTION,
			'confidence_label' => __( 'Complete the form to assess risk', 'mp-commerce-promotions' ),
			'impact'           => '',
			'badges'           => array(),
			'warnings'         => array(),
			'recommendations'  => array(),
		);
	}

	/**
	 * @param list<array{level: string, text: string}> $badges
	 * @return list<array{level: string, text: string}>
	 */
	private function dedupe_badges( array $badges ): array {
		$seen = array();
		$out  = array();
		foreach ( $badges as $badge ) {
			$key = (string) ( $badge['text'] ?? '' );
			if ( $key === '' || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $badge;
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function recommendations( string $confidence, ?string $goal ): array {
		$recs = array(
			__( 'We create a draft — nothing goes live until you activate it.', 'mp-commerce-promotions' ),
			__( 'Test with a small cart in your store before a big launch.', 'mp-commerce-promotions' ),
		);
		if ( $confidence === self::CONFIDENCE_RISK ) {
			$recs[] = __( 'Consider lowering the discount or adding a budget cap.', 'mp-commerce-promotions' );
		}
		if ( $goal === CampaignBuilderGoal::COUPON_CODE ) {
			$recs[] = __( 'Share the coupon code only after you activate the campaign.', 'mp-commerce-promotions' );
		}

		return $recs;
	}
}
