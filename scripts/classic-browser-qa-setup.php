<?php
/**
 * Seed promotions for classic browser QA (local Docker).
 *
 * Usage:
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/classic-browser-qa-setup.php
 *   ./wp eval-file .../classic-browser-qa-setup.php activate stacked
 *   ./wp eval-file .../classic-browser-qa-setup.php archive-all
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\PromotionService;

$action = isset( $args[0] ) ? (string) $args[0] : 'seed';

global $wpdb;

$plugin = new \MP\CommercePromotions\Plugin();
$plugin->init();

$repo      = new PromotionRepository( $wpdb );
$code_repo = new PromotionCodeRepository( $wpdb );
$audit     = new \MP\CommercePromotions\Domain\AuditLogRepository( $wpdb );
$audit_log = new \MP\CommercePromotions\Service\AuditLogger( $audit );
$factory   = new \MP\CommercePromotions\Domain\PromotionFactory();
$service   = new PromotionService( $repo, $factory, $audit_log );

const MP_CP_QA_OPTION = 'mp_cp_browser_qa_promotions';

function mp_cp_qa_gift_product_id(): int {
	$stored = (int) get_option( 'mp_cp_browser_qa_gift_product_id', 0 );
	if ( $stored > 0 ) {
		$p = wc_get_product( $stored );
		if ( $p && $p->is_purchasable() ) {
			return $stored;
		}
	}

	$product = new WC_Product_Simple();
	$product->set_name( 'Browser QA Gift SKU' );
	$product->set_status( 'publish' );
	$product->set_catalog_visibility( 'hidden' );
	$product->set_regular_price( '1' );
	$product->set_manage_stock( false );
	$product->set_sold_individually( true );
	$id = $product->save();
	if ( $id > 0 ) {
		update_option( 'mp_cp_browser_qa_gift_product_id', $id, false );
	}

	return (int) $id;
}

function mp_cp_qa_archive_all( PromotionService $service, PromotionRepository $repo ): void {
	$map = get_option( MP_CP_QA_OPTION, array() );
	if ( is_array( $map ) ) {
		foreach ( $map as $pid ) {
			$p = $repo->find( (int) $pid );
			if ( $p && in_array( $p->get_status(), array( PromotionStatus::ACTIVE, PromotionStatus::PAUSED ), true ) ) {
				$service->change_status( $p, PromotionStatus::ARCHIVED );
			}
		}
	}
	$p79 = $repo->find( 79 );
	if ( $p79 && $p79->get_status() === PromotionStatus::ARCHIVED ) {
		$service->change_status( $p79, PromotionStatus::ACTIVE );
	}
	WP_CLI::success( 'Archived Browser QA promotions; restored #79 if was archived.' );
}

function mp_cp_qa_pause_except( PromotionRepository $repo, PromotionService $service, array $keep_ids ): void {
	$active = $repo->find_active( 100 );
	foreach ( $active as $p ) {
		$id = (int) $p->get_id();
		if ( in_array( $id, $keep_ids, true ) ) {
			continue;
		}
		if ( $id === 79 ) {
			$service->change_status( $p, PromotionStatus::PAUSED );
			continue;
		}
		$name = (string) $p->get_name();
		if ( strpos( $name, 'Browser QA' ) === 0 || strpos( $name, 'Smoke ' ) === 0 ) {
			$service->change_status( $p, PromotionStatus::PAUSED );
			continue;
		}
		$service->change_status( $p, PromotionStatus::PAUSED );
	}
}

function mp_cp_qa_make(
	PromotionService $service,
	PromotionRepository $repo,
	string $name,
	array $conditions,
	array $actions,
	array $restrictions,
	string $mode = PromotionApplicationMode::STACKABLE,
	bool $stop = false,
	?int $priority = 10,
	?float $budget = null,
	?int $cooldown = null,
	?string $orch_group = null,
	array $excluded_promotion_ids = array()
): int {
	$draft = $service->create_draft( $name );
	$id    = (int) $draft->get_id();
	$updated = $draft
		->with_rules( $conditions, $actions, $restrictions )
		->with_application_rules( $mode, $stop, null )
		->with_priority( $priority ?? 10 );
	if ( $budget !== null ) {
		$updated = $updated->with_budget( $budget, 0.0, null );
	}
	if ( $cooldown !== null || $orch_group !== null ) {
		$updated = $updated->with_orchestration( $cooldown, $orch_group );
	}
	if ( $excluded_promotion_ids !== array() ) {
		$updated = $updated->with_excluded_promotion_ids( $excluded_promotion_ids );
	}
	$service->update_promotion( $updated );
	$reload = $repo->find( $id );
	return $reload ? $id : 0;
}

if ( $action === 'archive-all' ) {
	mp_cp_qa_archive_all( $service, $repo );
	exit( 0 );
}

if ( $action === 'activate' && isset( $args[1] ) ) {
	$map   = get_option( MP_CP_QA_OPTION, array() );
	$key   = (string) $args[1];
	$ids   = is_array( $map ) && isset( $map[ $key ] ) ? array_map( 'intval', (array) $map[ $key ] ) : array();
	$keep  = array();
	foreach ( $ids as $id ) {
		if ( $id > 0 ) {
			$keep[] = $id;
		}
	}
	mp_cp_qa_pause_except( $repo, $service, $keep );
	foreach ( $keep as $id ) {
		$p = $repo->find( $id );
		if ( $p && $p->get_status() !== PromotionStatus::ACTIVE ) {
			$service->change_status( $p, PromotionStatus::ACTIVE );
		}
	}
	WP_CLI::success( 'Activated scenario: ' . $key . ' → ' . implode( ',', $keep ) );
	exit( 0 );
}

$gift_id = mp_cp_qa_gift_product_id();
$qual_id = 3703;

$map = array(
	'stacked'        => array(),
	'scoped'         => array(),
	'cheapest'       => array(),
	'free_shipping'  => array(),
	'free_gift'      => array(),
	'code'           => array(),
	'budget'         => array(),
	'cooldown'       => array(),
	'orchestration'  => array(),
	'exclusion'      => array(),
);

$map['stacked'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Stack A',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 10 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	40
);
$map['stacked'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Stack B',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 5 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	41
);

$map['scoped'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Scoped 10pct',
	array(
		array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ),
		array( 'type' => RuleTypes::CONDITION_PRODUCT_IN_CART, 'product_id' => $qual_id ),
	),
	array( array( 'type' => RuleTypes::ACTION_PERCENTAGE_DISCOUNT, 'percentage' => 10 ) ),
	array()
);

$map['cheapest'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Cheapest BOGO',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 50 ) ),
	array( array( 'type' => RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT, 'percentage' => 100 ) ),
	array()
);

$map['free_shipping'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Free Shipping',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FREE_SHIPPING ) ),
	array()
);

$map['free_gift'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Free Gift',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 40 ) ),
	array(
		array(
			'type'       => RuleTypes::ACTION_FREE_GIFT_PRODUCT,
			'product_id' => $gift_id,
			'quantity'   => 1,
		),
	),
	array()
);

$code_promo = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Code 15off',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 15 ) ),
	array(),
	PromotionApplicationMode::EXCLUSIVE,
	false,
	5
);
$map['code'][] = $code_promo;
$code_factory = new \MP\CommercePromotions\Domain\PromotionCodeFactory();
$code_entity  = $code_factory->create_manual_code( $code_promo, 'BROWSERQA15', 100, null );
$code_repo->insert( $code_entity );

$map['budget'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Budget 5',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 3 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	10,
	5.0
);

$map['cooldown'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Cooldown',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 2 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	10,
	null,
	168
);

$map['orchestration'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Orch A',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 4 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	50,
	null,
	null,
	'browser-qa-lane'
);
$map['orchestration'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Orch B',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 3 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	51,
	null,
	null,
	'browser-qa-lane'
);

$ex_a = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Excl A',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 7 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	60
);
$map['exclusion'][] = $ex_a;
$map['exclusion'][] = mp_cp_qa_make(
	$service,
	$repo,
	'Browser QA Excl B',
	array( array( 'type' => RuleTypes::CONDITION_MINIMUM_SUBTOTAL, 'amount' => 1 ) ),
	array( array( 'type' => RuleTypes::ACTION_FIXED_AMOUNT_DISCOUNT, 'amount' => 6 ) ),
	array(),
	PromotionApplicationMode::STACKABLE,
	false,
	61,
	null,
	null,
	null,
	array( $ex_a )
);

update_option( MP_CP_QA_OPTION, $map, false );

$p79 = $repo->find( 79 );
if ( $p79 && $p79->get_status() === PromotionStatus::ACTIVE ) {
	$service->change_status( $p79, PromotionStatus::PAUSED );
}

WP_CLI::log( wp_json_encode(
	array(
		'gift_product_id'      => $gift_id,
		'qualifying_product_id' => $qual_id,
		'promotions'           => $map,
		'code'                 => 'BROWSERQA15',
	),
	JSON_PRETTY_PRINT
) );
