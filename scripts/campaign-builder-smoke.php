<?php
/**
 * WP-CLI smoke: Campaign Builder goal mapping and draft creation.
 *
 * Usage (from WooCommerce project root):
 *   ./wp eval-file wp-content/plugins/mp-commerce-promotions/scripts/campaign-builder-smoke.php
 *
 * @package MP\CommercePromotions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use MP\CommercePromotions\Admin\CampaignBuilderAjax;
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Domain\AuditLogRepository;
use MP\CommercePromotions\Domain\PromotionFactory;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Service\CampaignBuilderDraftCreator;
use MP\CommercePromotions\Service\CampaignBuilderGoal;
use MP\CommercePromotions\Service\CampaignBuilderStep;
use MP\CommercePromotions\Service\CampaignSummaryFormatter;
use MP\CommercePromotions\Service\PromotionService;

$GLOBALS['smoke_failures'] = 0;

function cb_smoke_assert( bool $ok, string $label ): void {
	if ( $ok ) {
		WP_CLI::success( $label );
		return;
	}
	++$GLOBALS['smoke_failures'];
	WP_CLI::warning( 'FAIL: ' . $label );
}

global $wpdb;
if ( ! $wpdb instanceof wpdb ) {
	WP_CLI::error( 'wpdb unavailable' );
}

$repo     = new PromotionRepository( $wpdb );
$factory  = new PromotionFactory();
$audit    = new AuditLogger( new AuditLogRepository( $wpdb ) );
$service  = new PromotionService( $repo, $factory, $audit );

$codes         = new PromotionCodeRepository( $wpdb );
$code_factory  = new PromotionCodeFactory();
$creator       = new CampaignBuilderDraftCreator( $service, $code_factory, $codes );

$goals = CampaignBuilderGoal::all();
cb_smoke_assert( count( $goals ) === 10, 'ten campaign goals registered' );
cb_smoke_assert(
	CampaignBuilderStep::initial_after_goal( CampaignBuilderGoal::CATEGORY_DISCOUNT ) === CampaignBuilderStep::TARGETING,
	'wizard initial step for category discount'
);
cb_smoke_assert(
	! CampaignBuilderStep::goal_needs_targeting_step( CampaignBuilderGoal::FIRST_ORDER ),
	'first order skips targeting step'
);

cb_smoke_assert(
	has_action( 'wp_ajax_mp_cb_search_products' ) !== false
	&& has_action( 'wp_ajax_mp_cb_search_categories' ) !== false,
	'AJAX search handlers registered'
);
cb_smoke_assert(
	CampaignBuilderAjax::nonce_action() === 'mp_cb_search',
	'AJAX nonce action constant'
);

$user_id = (int) get_current_user_id();
if ( $user_id <= 0 ) {
	$user_id = 1;
	wp_set_current_user( $user_id );
}

$wizard_token = wp_generate_password( 12, false, false );
$wizard_key   = 'mp_cb_wizard_' . $user_id . '_' . sanitize_key( $wizard_token );
$wizard_ui    = array( 'campaign_name' => 'Wizard transient smoke', 'percentage' => '10' );
set_transient(
	$wizard_key,
	array(
		'goal' => CampaignBuilderGoal::CATEGORY_DISCOUNT,
		'ui'   => $wizard_ui,
	),
	HOUR_IN_SECONDS
);
$wizard_loaded = get_transient( $wizard_key );
cb_smoke_assert(
	is_array( $wizard_loaded )
	&& (string) ( $wizard_loaded['goal'] ?? '' ) === CampaignBuilderGoal::CATEGORY_DISCOUNT
	&& is_array( $wizard_loaded['ui'] ?? null ),
	'wizard transient round-trip'
);
delete_transient( $wizard_key );

foreach ( $goals as $goal ) {
	$form = cb_smoke_form_for_goal( $goal );
	$headline = CampaignSummaryFormatter::headline( $goal, $form );
	cb_smoke_assert( $headline !== '', 'summary headline: ' . $goal );

	$sections = CampaignSummaryFormatter::review_sections( $goal, $form );
	cb_smoke_assert( $sections['headline'] !== '' && $sections['benefit'] !== '', 'review sections: ' . $goal );

	if ( $goal === CampaignBuilderGoal::FIRST_ORDER ) {
		cb_smoke_assert(
			! str_contains( strtolower( $headline ), ' on first-time buyers' ),
			'first order headline without duplicate scope'
		);
	}
	if ( $goal === CampaignBuilderGoal::BUY_X_GET_Y ) {
		cb_smoke_assert( ! str_contains( $headline, ' on ' ), 'bogo headline without trailing scope clause' );
	}

	try {
		$rules = $creator->build_rules( $goal, $form );
		cb_smoke_assert( isset( $rules['actions'] ) && $rules['actions'] !== array(), 'build_rules: ' . $goal );
		cb_smoke_assert(
			isset( $rules['conditions'] ) && is_array( $rules['conditions'] ),
			'build_rules conditions array: ' . $goal
		);
	} catch ( Throwable $e ) {
		cb_smoke_assert( false, 'build_rules exception for ' . $goal . ': ' . $e->getMessage() );
	}
}

$headline = CampaignSummaryFormatter::headline(
	CampaignBuilderGoal::FREE_SHIPPING,
	array( 'minimum_subtotal' => '100' )
);
cb_smoke_assert( str_contains( $headline, 'Free shipping' ), 'summary formatter free shipping' );

$form = cb_smoke_form_for_goal( CampaignBuilderGoal::CATEGORY_DISCOUNT );
$form['campaign_name']  = 'Smoke CB ' . gmdate( 'Y-m-d H:i:s' );
$form['campaign_label'] = 'SMOKE_CB';
$form['actor_user_id']  = $user_id;
$form['stackable']      = '1';
$form['budget_amount']    = '500';
$form['usage_limit']      = '100';
$form['starts_at']        = gmdate( 'Y-m-d\TH:i', strtotime( '+1 day' ) );
$form['ends_at']          = gmdate( 'Y-m-d\TH:i', strtotime( '+30 days' ) );

try {
	$result = $creator->create_draft( $form );
} catch ( Throwable $e ) {
	cb_smoke_assert( false, 'create_draft: ' . $e->getMessage() );
	return;
}

$promotion = $result['promotion'];
$pid       = $promotion->get_id();
cb_smoke_assert( $pid !== null && $pid > 0, 'draft promotion inserted' );
cb_smoke_assert( $promotion->get_status() === PromotionStatus::DRAFT, 'status remains draft' );
cb_smoke_assert( $promotion->get_application_mode() === PromotionApplicationMode::STACKABLE, 'stackable applied' );
cb_smoke_assert( ! $promotion->should_stop_processing(), 'stop_processing false when stackable' );
cb_smoke_assert( $promotion->get_budget_amount() !== null && (float) $promotion->get_budget_amount() === 500.0, 'budget stored' );
cb_smoke_assert( $promotion->get_usage_limit() === 100, 'usage limit stored' );
cb_smoke_assert( $promotion->get_starts_at() !== null && $promotion->get_ends_at() !== null, 'schedule stored' );
cb_smoke_assert(
	$promotion->get_actions()[0]['type'] === RuleTypes::ACTION_PERCENTAGE_DISCOUNT,
	'category discount action type'
);

$edit_url = admin_url(
	add_query_arg(
		array(
			'page'      => 'mp-commerce-promotions',
			'tab'       => 'all',
			'promotion' => (string) $pid,
		),
		'admin.php'
	)
);
cb_smoke_assert( str_contains( $edit_url, 'promotion=' . (string) $pid ), 'advanced edit URL generated' );

$coupon_form = cb_smoke_form_for_goal( CampaignBuilderGoal::COUPON_CODE );
$coupon_form['campaign_name']        = 'Smoke Coupon ' . wp_generate_password( 4, false, false );
$coupon_form['generate_coupon_code'] = '1';
$coupon_form['require_coupon_code']  = '1';
$coupon_form['actor_user_id']        = $user_id;

try {
	$coupon_result = $creator->create_draft( $coupon_form );
} catch ( Throwable $e ) {
	cb_smoke_assert( false, 'coupon create_draft: ' . $e->getMessage() );
	return;
}

$code_plain = $coupon_result['generated_code'];
cb_smoke_assert( is_string( $code_plain ) && strlen( $code_plain ) >= 4, 'coupon campaign creates code' );
$coupon_id = $coupon_result['promotion']->get_id();
if ( $coupon_id !== null ) {
	$list = $codes->find_for_promotion( $coupon_id );
	cb_smoke_assert( count( $list ) >= 1, 'code row persisted for promotion' );

	$coupon_transient_key = 'mp_cb_code_' . $user_id . '_' . $coupon_id;
	set_transient( $coupon_transient_key, $code_plain, 5 * MINUTE_IN_SECONDS );
	cb_smoke_assert( get_transient( $coupon_transient_key ) === $code_plain, 'coupon one-time transient pattern' );
	delete_transient( $coupon_transient_key );
}

if ( $GLOBALS['smoke_failures'] > 0 ) {
	WP_CLI::error( sprintf( 'Campaign builder smoke finished with %d failure(s).', (int) $GLOBALS['smoke_failures'] ) );
}

WP_CLI::success( 'Campaign builder smoke passed.' );

/**
 * @return array<string, mixed>
 */
function cb_smoke_form_for_goal( string $goal ): array {
	$cat_id  = cb_smoke_first_category_id();
	$prod_id = cb_smoke_first_product_id();

	$base = array(
		'campaign_goal'  => $goal,
		'campaign_name'  => 'Smoke',
		'discount_type'  => 'percentage',
		'percentage'     => 10,
		'amount'         => 5,
	);

	switch ( $goal ) {
		case CampaignBuilderGoal::CATEGORY_DISCOUNT:
		case CampaignBuilderGoal::SCHEDULED:
			$base['category_ids'] = array( $cat_id );
			return $base;
		case CampaignBuilderGoal::PRODUCT_DISCOUNT:
			$base['product_ids'] = array( $prod_id );
			return $base;
		case CampaignBuilderGoal::BUY_X_GET_Y:
			return array_merge(
				$base,
				array(
					'bogo_scope'          => 'category',
					'category_ids'        => array( $cat_id ),
					'required_quantity'   => 2,
					'discounted_quantity' => 1,
					'discount_percentage' => 100,
				)
			);
		case CampaignBuilderGoal::FREE_SHIPPING:
			return array_merge( $base, array( 'minimum_subtotal' => 50 ) );
		case CampaignBuilderGoal::FREE_GIFT:
			return array_merge(
				$base,
				array(
					'minimum_subtotal' => 100,
					'gift_product_id'  => $prod_id,
					'gift_quantity'    => 1,
				)
			);
		case CampaignBuilderGoal::VIP_ROLE:
			return array_merge( $base, array( 'roles' => array( 'customer' ) ) );
		case CampaignBuilderGoal::COUPON_CODE:
		case CampaignBuilderGoal::BUDGETED:
			return $base;
		case CampaignBuilderGoal::FIRST_ORDER:
		default:
			return $base;
	}
}

function cb_smoke_first_category_id(): int {
	$terms = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'number'     => 1,
			'fields'     => 'ids',
		)
	);
	if ( is_array( $terms ) && isset( $terms[0] ) ) {
		return max( 1, (int) $terms[0] );
	}

	return 1;
}

function cb_smoke_first_product_id(): int {
	if ( function_exists( 'wc_get_products' ) ) {
		$products = wc_get_products(
			array(
				'limit'  => 1,
				'status' => 'publish',
				'return' => 'ids',
			)
		);
		if ( is_array( $products ) && isset( $products[0] ) ) {
			return max( 1, (int) $products[0] );
		}
	}

	$query = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
		)
	);
	if ( ! empty( $query->posts[0] ) ) {
		return max( 1, (int) $query->posts[0] );
	}

	return 1;
}
