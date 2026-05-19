<?php
/**
 * WP-CLI diagnostic: Cart/Checkout block page markup and SSR output.
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/blocks-rendering-diagnostic.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/lib/qa-bootstrap.php';
mp_cp_qa_bootstrap_script( __FILE__ );

use MP\CommercePromotions\Service\BlockTestPages;
use MP\CommercePromotions\Service\WooCommerceBlockPageContent;

$pages   = new BlockTestPages();
$cart_id = (int) get_option( BlockTestPages::OPTION_CART_PAGE_ID, BlockTestPages::DEFAULT_CART_PAGE_ID );
if ( $cart_id <= 0 ) {
	$cart_id = BlockTestPages::DEFAULT_CART_PAGE_ID;
}
$checkout_id = (int) get_option( BlockTestPages::OPTION_CHECKOUT_PAGE_ID, BlockTestPages::DEFAULT_CHECKOUT_PAGE_ID );
if ( $checkout_id <= 0 ) {
	$checkout_id = BlockTestPages::DEFAULT_CHECKOUT_PAGE_ID;
}

$registry = WP_Block_Type_Registry::get_instance();
$registered = $registry->get_all_registered();
$has_cart_block     = isset( $registered['woocommerce/cart'] );
$has_checkout_block = isset( $registered['woocommerce/checkout'] );

$theme = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
$theme_name = $theme instanceof WP_Theme ? $theme->get( 'Name' ) : 'unknown';

/**
 * @param int $page_id
 * @return array<string, mixed>
 */
function mp_cp_block_page_diag( int $page_id, bool $is_cart ): array {
	$post = get_post( $page_id );
	if ( ! $post instanceof WP_Post ) {
		return array( 'page_id' => $page_id, 'exists' => false );
	}

	$content = (string) $post->post_content;
	$excerpt = substr( preg_replace( '/\s+/', ' ', $content ) ?? '', 0, 200 );

	$template = '';
	if ( function_exists( 'get_page_template_slug' ) ) {
		$template = (string) get_page_template_slug( $page_id );
	}
	if ( $template === '' ) {
		$template = 'default';
	}

	$has_comments = $is_cart
		? str_contains( $content, '<!-- wp:woocommerce/cart' )
		: str_contains( $content, '<!-- wp:woocommerce/checkout' );

	$has_structure = $is_cart
		? WooCommerceBlockPageContent::has_complete_cart_structure( $content )
		: WooCommerceBlockPageContent::has_complete_checkout_structure( $content );

	$html = function_exists( 'do_blocks' ) ? (string) do_blocks( $content ) : '';
	$wrapper_ok = $is_cart
		? ( str_contains( $html, 'wp-block-woocommerce-cart' ) || str_contains( $html, 'wc-block-cart' ) )
		: ( str_contains( $html, 'wp-block-woocommerce-checkout' ) || str_contains( $html, 'wc-block-checkout' ) );

	$permalink = function_exists( 'get_permalink' ) ? (string) get_permalink( $page_id ) : '';

	return array(
		'page_id'                      => $page_id,
		'exists'                       => true,
		'post_status'                  => (string) $post->post_status,
		'permalink'                    => $permalink,
		'page_template'                => $template,
		'post_content_excerpt'         => $excerpt,
		'has_block_comments'           => $has_comments,
		'has_complete_inner_structure' => $has_structure,
		'do_blocks_length'             => strlen( $html ),
		'do_blocks_has_wrapper'        => $wrapper_ok,
	);
}

$repair = $pages->repair_page_block_markup();

echo "MP Commerce Promotions — Blocks rendering diagnostic\n";
echo str_repeat( '-', 56 ) . "\n";
echo 'active_theme: ' . $theme_name . "\n";
echo 'woocommerce/cart registered: ' . ( $has_cart_block ? 'yes' : 'no' ) . "\n";
echo 'woocommerce/checkout registered: ' . ( $has_checkout_block ? 'yes' : 'no' ) . "\n";
echo 'repair_cart: ' . ( $repair['cart_repaired'] ? 'updated' : 'skipped_or_failed' ) . "\n";
echo 'repair_checkout: ' . ( $repair['checkout_repaired'] ? 'updated' : 'skipped_or_failed' ) . "\n";
echo str_repeat( '-', 56 ) . "\n";

foreach (
	array(
		'cart'     => mp_cp_block_page_diag( $cart_id, true ),
		'checkout' => mp_cp_block_page_diag( $checkout_id, false ),
	) as $label => $diag
) {
	echo strtoupper( $label ) . " PAGE\n";
	if ( empty( $diag['exists'] ) ) {
		echo "  missing page id {$diag['page_id']}\n\n";
		continue;
	}
	foreach ( $diag as $key => $value ) {
		if ( $key === 'exists' ) {
			continue;
		}
		$printed = is_bool( $value ) ? ( $value ? 'yes' : 'no' ) : (string) $value;
		echo "  {$key}: {$printed}\n";
	}
	echo "\n";
}

$state = $pages->resolve_page_state();
echo 'block_pages_present: ' . ( ! empty( $state['block_pages_present'] ) ? 'yes' : 'no' ) . "\n";
echo 'mp_cp_block_compatibility_status: ' . $pages->compatibility_status() . "\n";
$notes = $pages->compatibility_notes();
if ( $notes !== '' ) {
	echo 'mp_cp_block_compatibility_notes: ' . $notes . "\n";
}

$cart_diag = mp_cp_block_page_diag( $cart_id, true );
if ( ! empty( $cart_diag['exists'] ) && empty( $cart_diag['do_blocks_has_wrapper'] ) ) {
	exit( 1 );
}

exit( 0 );
