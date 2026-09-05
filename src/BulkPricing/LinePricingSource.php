<?php
/**
 * Line pricing decision constants.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\BulkPricing;

final class LinePricingSource {

	public const BULK_TIER = 'bulk_tier';

	public const PROMOTION = 'promotion';

	public const STANDARD = 'standard';

	public const CART_META_SOURCE = 'mp_cp_pricing_source';

	public const CART_META_TIER_MIN = 'mp_cp_bulk_tier_min_qty';

	public const CART_META_TIER_PCT = 'mp_cp_bulk_tier_pct';

	public const CART_META_BASE_SNAPSHOT = 'mp_cp_base_unit_snapshot';

	public const ORDER_META_SOURCE = '_mp_cp_pricing_source';

	public const ORDER_META_TIER_MIN = '_mp_cp_bulk_tier_min_qty';

	public const ORDER_META_TIER_PCT = '_mp_cp_bulk_tier_pct';

	public const ORDER_META_BASE_SNAPSHOT = '_mp_cp_base_unit_snapshot_minor';

	public const ORDER_META_FINAL_UNIT = '_mp_cp_final_unit_price';
}
