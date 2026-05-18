<?php
/**
 * WP-CLI smoke: Cart/Checkout Blocks compatibility investigation setup.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-compatibility-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Service\BlockQaPromotionSetup;
use MP\CommercePromotions\Service\BlockTestPages;
use MP\CommercePromotions\Service\CompatibilityStatus;
use MP\CommercePromotions\Service\WooCommerceBlockPageContent;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\BlocksHookAudit;

$GLOBALS['mp_cp_blocks_smoke_ok']   = 0;
$GLOBALS['mp_cp_blocks_smoke_fail'] = 0;

function blocks_smoke_assert( bool $cond, string $label ): void {
	if ( $cond ) {
		++$GLOBALS['mp_cp_blocks_smoke_ok'];
		echo "OK  {$label}\n";
		return;
	}
	++$GLOBALS['mp_cp_blocks_smoke_fail'];
	echo "FAIL {$label}\n";
}

$pages  = new BlockTestPages();
$repair = $pages->repair_page_block_markup();
$ids    = $pages->ensure_pages();

blocks_smoke_assert( $ids['cart_page_id'] > 0, 'block cart page exists' );
blocks_smoke_assert( $ids['checkout_page_id'] > 0, 'block checkout page exists' );

$state = $pages->resolve_page_state();
blocks_smoke_assert( ! empty( $state['block_pages_present'] ), 'block pages contain full cart/checkout block structure' );

$cart_content = function_exists( 'get_post' ) ? (string) get_post_field( 'post_content', $ids['cart_page_id'] ) : '';
$cart_render    = WooCommerceBlockPageContent::render_cart_diagnostic( $cart_content );
blocks_smoke_assert( $cart_render['has_wrapper'], 'do_blocks cart output includes wc cart wrapper' );

$compat = ( new CompatibilityStatus() )->collect();
blocks_smoke_assert( $compat['cart_checkout_blocks_declared'] === false, 'cart_checkout_blocks not declared' );
blocks_smoke_assert( ! empty( $compat['hpos_declared_compatible'] ), 'HPOS FeaturesUtil available' );
blocks_smoke_assert(
	isset( $compat['block_cart_page_id'], $compat['block_checkout_page_id'], $compat['block_pages_present'] ),
	'compatibility block keys present'
);
blocks_smoke_assert(
	$compat['block_compatibility_status'] === BlockTestPages::STATUS_NOT_TESTED
	|| in_array( $compat['block_compatibility_status'], BlockTestPages::allowed_statuses(), true ),
	'block_compatibility_status is valid'
);

$hooks = BlocksHookAudit::audited_hooks();
blocks_smoke_assert( isset( $hooks['woocommerce_cart_calculate_fees'] ), 'hook audit includes cart_calculate_fees' );
blocks_smoke_assert( isset( $hooks['woocommerce_checkout_create_order'] ), 'hook audit includes checkout_create_order' );

global $wpdb;
if ( $wpdb instanceof wpdb ) {
	$repo    = new PromotionRepository( $wpdb );
	$audit   = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
	$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
	$factory = new \MP\CommercePromotions\Domain\PromotionFactory();
	$service = new PromotionService( $repo, $factory, $audit_log );
	$setup   = new BlockQaPromotionSetup( $repo, $service );

	$gift_id = 0;
	if ( function_exists( 'wc_get_products' ) ) {
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => 1 ) );
		if ( is_array( $products ) && isset( $products[0] ) && is_object( $products[0] ) && method_exists( $products[0], 'get_id' ) ) {
			$gift_id = (int) $products[0]->get_id();
		}
	}

	$result = $setup->refresh_qa_promotions( $gift_id );
	blocks_smoke_assert( $result['archived'] >= 0, 'archived prior block QA promotions' );
	blocks_smoke_assert( count( $result['created'] ) >= 4, 'created block QA promotions (paused)' );
}

$urls = $pages->preview_urls( (int) $ids['cart_page_id'], (int) $ids['checkout_page_id'] );
echo "\n--- Block QA URLs (draft preview) ---\n";
echo 'Cart page ID:     ' . (int) $ids['cart_page_id'] . "\n";
echo 'Checkout page ID: ' . (int) $ids['checkout_page_id'] . "\n";
if ( ! empty( $urls['cart_preview_url'] ) ) {
	echo 'Cart preview:     ' . $urls['cart_preview_url'] . "\n";
}
if ( ! empty( $urls['checkout_preview_url'] ) ) {
	echo 'Checkout preview: ' . $urls['checkout_preview_url'] . "\n";
}
echo "\nLive storefront cart/checkout page IDs are unchanged.\n";
echo "Enable hook debug: ./wp option update mp_cp_blocks_hook_debug yes (requires WP_DEBUG)\n";
echo "Set block status:  ./wp option update mp_cp_block_compatibility_status partial\n";
echo "Manual runbook:    docs/CART_CHECKOUT_BLOCKS_COMPATIBILITY.md\n";

$ok   = (int) $GLOBALS['mp_cp_blocks_smoke_ok'];
$fail = (int) $GLOBALS['mp_cp_blocks_smoke_fail'];
echo "\nBlocks compatibility smoke: {$ok} passed, {$fail} failed\n";
exit( $fail > 0 ? 1 : 0 );
