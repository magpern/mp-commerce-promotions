<?php
/**
 * Global and per-promotion dry-run application guards.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Service;

use MP\CommercePromotions\Domain\Promotion;

final class PromotionDryRunGuard {

	public const REASON_DRY_RUN_MODE = 'dry_run_mode';

	private Settings $settings;

	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings ?? new Settings();
	}

	public function is_global_dry_run(): bool {
		return $this->settings->promotion_dry_run_enabled();
	}

	public function is_promotion_dry_run( Promotion $promotion ): bool {
		if ( $this->is_global_dry_run() ) {
			return true;
		}

		return $promotion->is_dry_run();
	}

	public function should_apply_storefront( Promotion $promotion ): bool {
		return ! $this->is_promotion_dry_run( $promotion );
	}
}
