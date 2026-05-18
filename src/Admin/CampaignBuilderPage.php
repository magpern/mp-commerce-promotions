<?php
/**
 * Merchant-friendly Campaign Builder admin tab.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Engine\Action\CheapestItemDiscountAction;
use MP\CommercePromotions\Service\CampaignBuilderDraftCreator;
use MP\CommercePromotions\Service\CampaignBuilderGoal;
use MP\CommercePromotions\Service\CampaignBuilderLifecyclePresenter;
use MP\CommercePromotions\Service\CampaignBuilderMerchantInsights;
use MP\CommercePromotions\Service\CampaignBuilderPreview;
use MP\CommercePromotions\Service\CampaignBuilderStep;
use MP\CommercePromotions\Service\CampaignBuilderSummaryCounts;
use MP\CommercePromotions\Service\CampaignSummaryFormatter;
use MP\CommercePromotions\Service\PromotionHealthMonitor;
use MP\CommercePromotions\Service\PromotionLifecycle;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\PromotionService;
use Throwable;
use WP_Roles;

final class CampaignBuilderPage {

	private const NONCE_ACTION = 'mp_cb_create_campaign';

	private const NONCE_FIELD = 'mp_cb_create_campaign_nonce';

	private const DUPLICATE_NONCE_ACTION = 'mp_cb_duplicate_campaign';

	private const DUPLICATE_NONCE_FIELD = 'mp_cb_duplicate_campaign_nonce';

	private const WIZARD_NONCE_ACTION = 'mp_cb_wizard_nav';

	private const WIZARD_NONCE_FIELD = 'mp_cb_wizard_nonce';

	private const WIZARD_TRANSIENT_PREFIX = 'mp_cb_wizard_';

	private PromotionRepository $promotions;

	private PromotionService $promotion_service;

	private CampaignBuilderDraftCreator $draft_creator;

	private CampaignBuilderPreview $preview;

	private CampaignBuilderSummaryCounts $summary;

	private CampaignBuilderMerchantInsights $insights;

	private PromotionRuleValidator $validator;

	private ?PromotionHealthMonitor $health_monitor;

	/** @var list<array<string, mixed>>|null */
	private ?array $health_issue_cache = null;

	public function __construct(
		PromotionRepository $promotions,
		PromotionService $promotion_service,
		CampaignBuilderDraftCreator $draft_creator,
		CampaignBuilderPreview $preview,
		CampaignBuilderSummaryCounts $summary,
		?PromotionRuleValidator $validator = null,
		?PromotionHealthMonitor $health_monitor = null
	) {
		$this->promotions        = $promotions;
		$this->promotion_service = $promotion_service;
		$this->draft_creator     = $draft_creator;
		$this->preview           = $preview;
		$this->summary           = $summary;
		$this->validator         = $validator ?? new PromotionRuleValidator();
		$this->health_monitor    = $health_monitor;
		$this->insights          = new CampaignBuilderMerchantInsights(
			$promotions,
			null,
			null,
			null,
			$draft_creator
		);
	}

	public function register_assets(): void {
		add_action(
			'admin_enqueue_scripts',
			static function ( string $hook ): void {
				if ( $hook !== 'woocommerce_page_' . AdminNavigation::PAGE_SLUG ) {
					return;
				}
				$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
				if ( $tab !== AdminNavigation::TAB_CAMPAIGN_BUILDER ) {
					return;
				}
				wp_enqueue_style(
					'mp-cp-campaign-builder',
					MP_COMMERCE_PROMOTIONS_URL . 'assets/css/admin-campaign-builder.css',
					array(),
					MP_COMMERCE_PROMOTIONS_VERSION
				);
				wp_enqueue_script(
					'mp-cp-campaign-builder',
					MP_COMMERCE_PROMOTIONS_URL . 'assets/js/admin-campaign-builder.js',
					array(),
					MP_COMMERCE_PROMOTIONS_VERSION,
					true
				);
				wp_localize_script(
					'mp-cp-campaign-builder',
					'mpCbAdmin',
					array(
						'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
						'searchNonce' => wp_create_nonce( CampaignBuilderAjax::nonce_action() ),
					)
				);
			}
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_duplicate();
		$this->handle_post_create();
		$this->handle_wizard_navigation();

		$goal = CampaignBuilderGoal::sanitize(
			isset( $_GET['campaign_goal'] ) ? wp_unslash( (string) $_GET['campaign_goal'] ) : null
		);

		$success_id = isset( $_GET['mp_cb_created'] ) ? (int) $_GET['mp_cb_created'] : 0;

		echo '<div class="wrap mp-cb-wrap">';
		$this->render_page_header();
		$this->render_mode_switch();
		AdminNavigation::render_tabs( AdminNavigation::TAB_CAMPAIGN_BUILDER );

		if ( $success_id > 0 ) {
			$this->render_success_screen( $success_id );
		}

		$error_raw = isset( $_GET['mp_cb_error'] ) ? wp_unslash( (string) $_GET['mp_cb_error'] ) : '';
		if ( $error_raw !== '' ) {
			AdminNotice::error( $this->resolve_error_notice( sanitize_key( $error_raw ) ) );
		}

		$this->render_summary_cards();

		if ( $goal === null ) {
			echo '<section class="mp-cb-section mp-cb-section--goals">';
			echo '<h2 class="mp-cb-section__title">' . esc_html__( 'Step 1 — Choose campaign goal', 'mp-commerce-promotions' ) . '</h2>';
			$this->render_wizard_progress( null, CampaignBuilderStep::GOAL );
			$this->render_goal_cards( null );
			echo '</section>';
			echo '<section class="mp-cb-section mp-cb-section--preview-empty">';
			echo '<h2 class="mp-cb-section__title">' . esc_html__( 'Campaign preview', 'mp-commerce-promotions' ) . '</h2>';
			$this->render_preview_empty_state();
			echo '</section>';
		} else {
			$ui_state = $this->merged_ui_state( $goal );
			$step     = $this->resolve_wizard_step( $goal );
			$parsed   = $this->draft_payload_from_ui_state( $goal, $ui_state );
			echo '<section class="mp-cb-section mp-cb-section--builder">';
			$this->render_wizard_progress( $goal, $step );
			echo '<div class="mp-cb-layout">';
			echo '<div class="mp-cb-layout__primary">';
			$this->render_wizard( $goal, $step, $ui_state );
			echo '</div>';
			echo '<aside class="mp-cb-layout__aside" aria-label="' . esc_attr__( 'Campaign preview', 'mp-commerce-promotions' ) . '">';
			$this->render_preview_sidebar( $goal, $parsed, $ui_state );
			echo '</aside>';
			echo '</div>';
			echo '</section>';
		}

		echo '<section class="mp-cb-section mp-cb-section--recent">';
		$this->render_campaign_list();
		echo '</section>';

		$this->render_escape_hatch();
		echo '</div>';
	}

	private function render_page_header(): void {
		echo '<header class="mp-cb-header">';
		echo '<div class="mp-cb-header__title-row">';
		echo '<h1 class="mp-cb-header__title">' . esc_html__( 'Campaign Builder', 'mp-commerce-promotions' ) . '</h1>';
		echo '<span class="mp-cb-badge">' . esc_html__( 'Merchant-friendly', 'mp-commerce-promotions' ) . '</span>';
		echo '</div>';
		echo '<p class="mp-cb-header__subtitle">'
			. esc_html__( 'Guided campaign setup for your store — no JSON required.', 'mp-commerce-promotions' )
			. '</p>';
		echo '</header>';
	}

	private function render_preview_empty_state(): void {
		echo '<div class="mp-cb-panel mp-cb-preview mp-cb-preview--empty">';
		echo '<span class="dashicons dashicons-visibility mp-cb-preview__empty-icon" aria-hidden="true"></span>';
		echo '<p class="mp-cb-preview__empty-text">'
			. esc_html__( 'Choose a campaign type to see a preview.', 'mp-commerce-promotions' )
			. '</p>';
		echo '</div>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function merged_ui_state( string $goal ): array {
		$base  = $this->default_form_values( $goal );
		$token = isset( $_GET['cb_token'] ) ? sanitize_key( wp_unslash( (string) $_GET['cb_token'] ) ) : '';
		$stored = $this->load_wizard_state( $token, $goal );
		if ( $stored !== null ) {
			$base = array_merge( $base, $stored );
		}
		if ( isset( $_GET['preview'] ) && (string) $_GET['preview'] === '1' ) {
			return array_merge( $base, $this->parse_ui_state_from_get( $goal ) );
		}
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] === 'POST'
			&& isset( $_POST['campaign_goal'] )
			&& CampaignBuilderGoal::sanitize( sanitize_key( wp_unslash( (string) $_POST['campaign_goal'] ) ) ) === $goal ) {
			return array_merge( $base, $this->parse_ui_state_from_post( $goal ) );
		}

		return $base;
	}

	/**
	 * Shapes sanitized payload for previews and draft creation (database-ready dates).
	 *
	 * @param array<string, mixed> $ui
	 * @return array<string, mixed>
	 */
	private function draft_payload_from_ui_state( string $goal, array $ui ): array {
		$form                  = $this->build_draft_payload( $goal, $ui );
		$form['campaign_goal'] = $goal;
		$form['actor_user_id'] = (int) get_current_user_id();

		return $form;
	}

	private function redirect_to_builder( array $args ): never {
		$url = add_query_arg(
			array_merge(
				array(
					'page' => AdminNavigation::PAGE_SLUG,
					'tab'  => AdminNavigation::TAB_CAMPAIGN_BUILDER,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	private function handle_post_duplicate(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}
		if ( ! isset( $_POST['mp_cb_duplicate_submit'] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::DUPLICATE_NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::DUPLICATE_NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::DUPLICATE_NONCE_ACTION ) ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'invalid_nonce' ) );
		}

		$id = isset( $_POST['mp_cb_duplicate_promotion_id'] ) ? (int) $_POST['mp_cb_duplicate_promotion_id'] : 0;
		if ( $id <= 0 ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'duplicate_invalid' ) );
		}

		$existing = $this->promotions->find( $id );
		if ( $existing === null ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'duplicate_missing' ) );
		}

		try {
			$copy = $this->promotion_service->duplicate_as_draft(
				$existing,
				(int) get_current_user_id(),
				array()
			);
		} catch ( Throwable $e ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'duplicate_failed' ) );
		}

		$new_id = $copy->get_id();
		if ( $new_id === null || $new_id <= 0 ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'duplicate_failed' ) );
		}

		wp_safe_redirect( AdminUrl::edit_promotion( $new_id ) );
		exit;
	}

	private function handle_post_create(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}
		if ( ! isset( $_POST['mp_cb_submit_create'] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'invalid_nonce' ) );
		}

		$goal_raw = isset( $_POST['campaign_goal'] )
			? sanitize_key( wp_unslash( (string) $_POST['campaign_goal'] ) ) : '';
		$goal     = CampaignBuilderGoal::sanitize( $goal_raw );
		if ( $goal === null ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'invalid_goal' ) );
		}

		try {
			$form = $this->parse_form_from_post( $goal );

			$result = $this->draft_creator->create_draft( $form );
			/** @var Promotion $promotion */
			$promotion = $result['promotion'];
			$new_id    = $promotion->get_id();

			if ( $new_id === null || $new_id <= 0 ) {
				$this->redirect_to_builder(
					array(
						'campaign_goal' => $goal,
						'mp_cb_error'   => 'create_failed',
					)
				);
			}

			$user_id        = get_current_user_id();
			$generated_code = $result['generated_code'] ?? null;
			if ( is_string( $generated_code ) && $generated_code !== '' ) {
				set_transient(
					$this->generated_code_transient_key( $user_id, $new_id ),
					$generated_code,
					10 * MINUTE_IN_SECONDS
				);
			}

			$this->redirect_to_builder(
				array(
					'mp_cb_created' => (string) $new_id,
					'campaign_goal' => $goal,
				)
			);
		} catch ( Throwable $e ) {
			$this->redirect_to_builder(
				array(
					'campaign_goal' => $goal_raw,
					'mp_cb_error'   => 'create_validation',
				)
			);
		}
	}

	private function generated_code_transient_key( int $user_id, int $promotion_id ): string {
		return 'mp_cb_code_' . $user_id . '_' . $promotion_id;
	}

	private function resolve_error_notice( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
				return __( 'Security check failed. Please reload the page and try again.', 'mp-commerce-promotions' );
			case 'invalid_goal':
				return __( 'Please choose a valid campaign goal.', 'mp-commerce-promotions' );
			case 'duplicate_invalid':
			case 'duplicate_missing':
				return __( 'Duplicate failed: promotion not found.', 'mp-commerce-promotions' );
			case 'duplicate_failed':
				return __( 'Duplicate failed. Please try again.', 'mp-commerce-promotions' );
			case 'create_failed':
				return __( 'Could not finalize the promotion. Please verify required fields.', 'mp-commerce-promotions' );
			case 'create_validation':
				return __( 'Some fields are invalid for this campaign type. Correct the highlighted requirements and retry.', 'mp-commerce-promotions' );
			default:
				return __( 'Something went wrong. Please review your input.', 'mp-commerce-promotions' );
		}
	}

	private function render_success_screen( int $id ): void {
		$promotion = $this->promotions->find( $id );
		if ( null === $promotion ) {
			echo '<div class="notice notice-warning"><p>'
				. esc_html__( 'We could not find that draft — it may have been removed.', 'mp-commerce-promotions' )
				. '</p></div>';

			return;
		}

		$plain = '';
		$key   = $this->generated_code_transient_key( (int) get_current_user_id(), $id );
		$stored = get_transient( $key );
		delete_transient( $key );

		if ( is_string( $stored ) && $stored !== '' ) {
			$plain = $stored;
		}

		echo '<div class="notice notice-success">';
		echo '<p><strong>'
			. esc_html__( 'Draft campaign created.', 'mp-commerce-promotions' )
			. '</strong></p>';
		echo '<p class="mp-cb-success-summary">' . esc_html( CampaignSummaryFormatter::from_promotion( $promotion ) ) . '</p>';
		echo '<p>';
			printf(
				/* translators: %s: promotions URL anchor */
				esc_html__(
					'Open it here: %s',
					'mp-commerce-promotions'
				),
				'<a href="' . esc_url( AdminUrl::edit_promotion( $id ) ) . '">' . esc_html__( 'Advanced edit', 'mp-commerce-promotions' ) . '</a>'
			);
		echo '</p>';
		if ( $plain !== '' ) {
			printf(
				'<p>%s <code>%s</code></p>',
				esc_html__( 'Generated coupon code:', 'mp-commerce-promotions' ),
				esc_html( $plain )
			);
		}
		echo '</div>';
	}

	private function render_summary_cards(): void {
		$c    = $this->summary->counts();
		$defs = array(
			array(
				'label' => __( 'Live', 'mp-commerce-promotions' ),
				'value' => (string) $c['active'],
				'icon'  => 'yes-alt',
				'url'   => $this->promotions_list_url( array( 'promotion_status' => PromotionStatus::ACTIVE ) ),
				'warn'  => false,
			),
			array(
				'label' => __( 'Scheduled', 'mp-commerce-promotions' ),
				'value' => (string) $c['scheduled'],
				'icon'  => 'calendar-alt',
				'url'   => $this->promotions_list_url( array( 'lifecycle_phase' => PromotionLifecycle::PHASE_UPCOMING ) ),
				'warn'  => false,
			),
			array(
				'label' => __( 'Drafts', 'mp-commerce-promotions' ),
				'value' => (string) $c['drafts'],
				'icon'  => 'edit',
				'url'   => $this->promotions_list_url( array( 'promotion_status' => PromotionStatus::DRAFT ) ),
				'warn'  => false,
			),
			array(
				'label' => __( 'Needs attention', 'mp-commerce-promotions' ),
				'value' => (string) $c['needs_attention'],
				'icon'  => 'warning',
				'url'   => $this->promotions_list_url( array( 'quick_filter' => 'health_issues' ) ),
				'warn'  => (int) $c['needs_attention'] > 0,
			),
			array(
				'label' => __( 'Budget exhausted', 'mp-commerce-promotions' ),
				'value' => (string) $c['budget_exhausted'],
				'icon'  => 'money-alt',
				'url'   => $this->promotions_list_url( array( 'quick_filter' => 'budget_exhausted' ) ),
				'warn'  => (int) $c['budget_exhausted'] > 0,
			),
		);

		echo '<div class="mp-cb-summary">';
		foreach ( $defs as $item ) {
			$class = 'mp-cb-summary-card';
			if ( ! empty( $item['warn'] ) ) {
				$class .= ' is-warning';
			}
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $item['url'] ) . '">';
			echo '<span class="mp-cb-summary-card__icon dashicons dashicons-' . esc_attr( $item['icon'] ) . '" aria-hidden="true"></span>';
			echo '<span class="mp-cb-summary-card__value">' . esc_html( $item['value'] ) . '</span>';
			echo '<span class="mp-cb-summary-card__label">' . esc_html( $item['label'] ) . '</span>';
			echo '<span class="mp-cb-summary-card__view">' . esc_html__( 'View', 'mp-commerce-promotions' ) . '</span>';
			echo '</a>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, string> $query
	 */
	private function promotions_list_url( array $query = array() ): string {
		return AdminUrl::list_promotions( $query );
	}

	private function render_goal_cards( ?string $active_goal ): void {
		echo '<div class="mp-cb-goals">';
		foreach ( CampaignBuilderGoal::definitions() as $key => $def ) {
			$url    = add_query_arg(
				array(
					'campaign_goal' => $key,
					'cb_step'       => CampaignBuilderStep::initial_after_goal( $key ),
				),
				AdminUrl::tab( AdminNavigation::TAB_CAMPAIGN_BUILDER )
			);
			$theme  = CampaignBuilderGoal::visual_theme( $key );
			$active = $active_goal === $key ? ' is-selected' : '';
			echo '<article class="mp-cb-goal-card mp-cb-goal-card--theme-' . esc_attr( $theme ) . esc_attr( $active ) . '">';
			echo '<span class="mp-cb-goal-card__icon dashicons dashicons-' . esc_attr( $def['icon'] ) . '" aria-hidden="true"></span>';
			echo '<h3 class="mp-cb-goal-card__title">' . esc_html( $def['title'] ) . '</h3>';
			echo '<p class="mp-cb-goal-card__teaser">' . esc_html( CampaignSummaryFormatter::goal_teaser( $key ) ) . '</p>';
			echo '<p class="mp-cb-goal-card__desc">' . esc_html( $def['description'] ) . '</p>';
			echo '<p class="mp-cb-goal-card__best-for"><span>' . esc_html__( 'Best for', 'mp-commerce-promotions' ) . '</span> '
				. esc_html( $def['best_for'] ) . '</p>';
			$btn_class = $active_goal === $key ? 'button button-primary' : 'button button-secondary';
			printf(
				'<a class="%s mp-cb-goal-card__cta" href="%s">%s</a>',
				esc_attr( $btn_class ),
				esc_url( $url ),
				esc_html__( 'Create campaign', 'mp-commerce-promotions' )
			);
			echo '</article>';
		}
		echo '</div>';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_campaign_form( string $goal, array $values ): void {
		$post_url = AdminUrl::tab( AdminNavigation::TAB_CAMPAIGN_BUILDER );
		$post_url = add_query_arg( array( 'campaign_goal' => $goal ), $post_url );

		echo '<form class="mp-cb-form" method="post" action="' . esc_url( $post_url ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="campaign_goal" value="' . esc_attr( $goal ) . '" />';

		$this->open_form_card( __( 'Campaign details', 'mp-commerce-promotions' ) );
		$this->render_text_field(
			'campaign_name',
			__( 'Campaign name', 'mp-commerce-promotions' ),
			(string) $values['campaign_name'],
			true,
			array(),
			__( 'Shown in admin and reports.', 'mp-commerce-promotions' )
		);
		$this->render_text_field(
			'campaign_label',
			__( 'Campaign label', 'mp-commerce-promotions' ),
			(string) $values['campaign_label'],
			false,
			array(),
			__( 'Optional internal tag for filtering.', 'mp-commerce-promotions' )
		);
		$this->close_form_card();

		$this->open_form_card( __( 'Schedule & limits', 'mp-commerce-promotions' ) );
		echo '<div class="mp-cb-fields-row">';
		$this->render_datetime_local(
			'starts_at',
			__( 'Starts', 'mp-commerce-promotions' ),
			(string) $values['starts_at'],
			__( 'Leave empty to start immediately after activation.', 'mp-commerce-promotions' )
		);
		$this->render_datetime_local(
			'ends_at',
			__( 'Ends', 'mp-commerce-promotions' ),
			(string) $values['ends_at'],
			__( 'Leave empty for no end date.', 'mp-commerce-promotions' )
		);
		echo '</div>';
		echo '<div class="mp-cb-fields-row">';
		$this->render_text_field(
			'budget_amount',
			__( 'Budget amount', 'mp-commerce-promotions' ),
			(string) $values['budget_amount'],
			false,
			array( 'type' => 'number', 'step' => '0.01', 'min' => '0' ),
			__( 'Optional cap on total discount spend.', 'mp-commerce-promotions' )
		);
		$this->render_text_field(
			'usage_limit',
			__( 'Usage limit', 'mp-commerce-promotions' ),
			(string) $values['usage_limit'],
			false,
			array( 'type' => 'number', 'step' => '1', 'min' => '0' ),
			__( 'Optional maximum redemptions for this campaign.', 'mp-commerce-promotions' )
		);
		echo '</div>';
		$this->close_form_card();

		$this->open_form_card( __( 'Discount & offer', 'mp-commerce-promotions' ) );
		$this->render_goal_specific_fields( $goal, $values );
		$this->close_form_card();

		$this->open_form_card( __( 'Coupon & stacking', 'mp-commerce-promotions' ) );
		$this->radio_yes_no(
			'stackable',
			(bool) $values['stackable'],
			__( 'Stacking', 'mp-commerce-promotions' ),
			__( 'Allow stacking with compatible promotions?', 'mp-commerce-promotions' )
		);
		if ( $goal === CampaignBuilderGoal::COUPON_CODE ) {
			$this->render_field_notice(
				__( 'Coupon requirement', 'mp-commerce-promotions' ),
				__( 'This campaign type requires a coupon code.', 'mp-commerce-promotions' )
			);
			echo '<input type="hidden" name="require_coupon_code" value="1" />';
		} else {
			$this->radio_yes_no(
				'require_coupon_code',
				(bool) $values['require_coupon_code'],
				__( 'Coupon requirement', 'mp-commerce-promotions' ),
				__( 'Require shoppers to enter a promotion code?', 'mp-commerce-promotions' )
			);
		}
		$this->render_coupon_optional_block( $values, $goal );
		$this->close_form_card();

		echo '<p class="mp-cb-form-actions submit">';
		submit_button( __( 'Create Draft Campaign', 'mp-commerce-promotions' ), 'primary', 'mp_cb_submit_create', false );
		$url_preview = esc_url(
			add_query_arg(
				array(
					'page'           => AdminNavigation::PAGE_SLUG,
					'tab'            => AdminNavigation::TAB_CAMPAIGN_BUILDER,
					'campaign_goal'  => $goal,
					'preview'        => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		printf(
			' <button type="submit" class="button" formaction="%1$s" formmethod="get" formnovalidate>%2$s</button>',
			$url_preview,
			esc_html__( 'Preview in sidebar', 'mp-commerce-promotions' )
		);
		echo '</p>';

		echo '<p class="description">' . esc_html__(
			'Preview opens in a GET request with whatever you already typed in compatible fields.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_goal_specific_fields( string $goal, array $values ): void {
		if ( $goal === CampaignBuilderGoal::FIRST_ORDER ) {
			$this->render_discount_fields( $values, true );
		} elseif ( $goal === CampaignBuilderGoal::VIP_ROLE ) {
			$this->render_discount_fields( $values, true );
			$this->render_role_checkboxes( (array) ( $values['roles'] ?? array() ) );
		} elseif ( in_array(
			$goal,
			array( CampaignBuilderGoal::COUPON_CODE, CampaignBuilderGoal::BUDGETED ),
			true
		) ) {
			$this->render_discount_fields( $values, true );
			if ( $goal === CampaignBuilderGoal::BUDGETED ) {
				$this->render_field_notice(
					__( 'Budget', 'mp-commerce-promotions' ),
					__( 'Set Budget amount in Schedule & limits — it caps total discount spend.', 'mp-commerce-promotions' )
				);
			}
		} elseif ( $goal === CampaignBuilderGoal::SCHEDULED ) {
			$this->render_category_checkboxes(
				array_map( 'intval', (array) ( $values['category_ids'] ?? array() ) )
			);
			$this->render_discount_fields( $values, true, false, true );
		} elseif ( $goal === CampaignBuilderGoal::CATEGORY_DISCOUNT ) {
			$this->render_category_checkboxes(
				array_map( 'intval', (array) ( $values['category_ids'] ?? array() ) )
			);
			$this->render_discount_fields( $values, false, true );
		} elseif ( $goal === CampaignBuilderGoal::PRODUCT_DISCOUNT ) {
			$this->render_text_field(
				'product_ids_csv',
				__( 'Product IDs (comma-separated)', 'mp-commerce-promotions' ),
				(string) $values['product_ids_csv'],
				false,
				array(),
				__( 'Enter WooCommerce product IDs separated by commas.', 'mp-commerce-promotions' )
			);
			$this->render_discount_fields( $values, false, false );
		} elseif ( $goal === CampaignBuilderGoal::BUY_X_GET_Y ) {
			$this->render_bogo_scope( (string) $values['bogo_scope'] );

			if ( (string) $values['bogo_scope'] !== CheapestItemDiscountAction::SCOPE_PRODUCTS ) {
				$this->render_category_checkboxes(
					array_map( 'intval', (array) ( $values['category_ids'] ?? array() ) )
				);
			} else {
				$this->render_text_field(
					'product_ids_csv',
					__( 'Product IDs (comma-separated)', 'mp-commerce-promotions' ),
					(string) $values['product_ids_csv'],
					false,
					array(),
					__( 'Enter WooCommerce product IDs separated by commas.', 'mp-commerce-promotions' )
				);
			}

			$this->render_text_field(
				'required_quantity',
				__( 'Required quantity (buy X)', 'mp-commerce-promotions' ),
				(string) $values['required_quantity'],
				false,
				array( 'type' => 'number', 'min' => '1', 'step' => '1' )
			);
			$this->render_text_field(
				'discounted_quantity',
				__( 'Discounted quantity (get Y cheapest)', 'mp-commerce-promotions' ),
				(string) $values['discounted_quantity'],
				false,
				array( 'type' => 'number', 'min' => '1', 'step' => '1' )
			);
			$this->render_text_field(
				'discount_percentage',
				__( 'Discount percentage on discounted units', 'mp-commerce-promotions' ),
				(string) $values['discount_percentage'],
				false,
				array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100' )
			);
			$this->render_text_field(
				'minimum_eligible_subtotal',
				__( 'Minimum eligible subtotal', 'mp-commerce-promotions' ),
				(string) $values['minimum_eligible_subtotal'],
				false,
				array( 'type' => 'number', 'step' => '0.01', 'min' => '0' )
			);
		} elseif ( $goal === CampaignBuilderGoal::FREE_SHIPPING || $goal === CampaignBuilderGoal::FREE_GIFT ) {
			$sub_label = CampaignBuilderGoal::FREE_SHIPPING === $goal
				? __( 'Minimum cart subtotal for free shipping', 'mp-commerce-promotions' )
				: __( 'Minimum cart subtotal for gift', 'mp-commerce-promotions' );

			$this->render_text_field(
				'minimum_subtotal',
				(string) $sub_label,
				(string) $values['minimum_subtotal'],
				true,
				array( 'type' => 'number', 'step' => '0.01', 'min' => '0' )
			);
			if ( $goal === CampaignBuilderGoal::FREE_GIFT ) {
				$this->render_text_field(
					'gift_product_id',
					__( 'Gift product ID', 'mp-commerce-promotions' ),
					(string) $values['gift_product_id'],
					true,
					array( 'type' => 'number', 'min' => '1', 'step' => '1' )
				);
				$this->render_text_field(
					'gift_quantity',
					__( 'Gift quantity', 'mp-commerce-promotions' ),
					(string) $values['gift_quantity'],
					false,
					array( 'type' => 'number', 'min' => '1', 'step' => '1' )
				);
			}
		}

	}

	private function render_bogo_scope( string $selected ): void {
		echo '<p class="mp-cb-field">';
		echo '<label class="mp-cb-field__label" for="mp_cb_bogo_scope">'
			. esc_html__( 'Offer scope', 'mp-commerce-promotions' ) . '</label>';
		echo '<select class="mp-cb-field__input" id="mp_cb_bogo_scope" name="bogo_scope">';
		printf(
			'<option value="%1$s"%3$s>%2$s</option>',
			esc_attr( CheapestItemDiscountAction::SCOPE_CATEGORY ),
			esc_html__( 'Selected categories', 'mp-commerce-promotions' ),
			selected( $selected, CheapestItemDiscountAction::SCOPE_CATEGORY, false )
		);
		printf(
			'<option value="%1$s"%3$s>%2$s</option>',
			esc_attr( CheapestItemDiscountAction::SCOPE_PRODUCTS ),
			esc_html__( 'Specific products', 'mp-commerce-promotions' ),
			selected( $selected, CheapestItemDiscountAction::SCOPE_PRODUCTS, false )
		);
		echo '</select>';
		echo '<span class="mp-cb-field__help description">'
			. esc_html__( 'Choose whether the offer applies to categories or specific products.', 'mp-commerce-promotions' )
			. '</span>';
		echo '</p>';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_coupon_optional_block( array $values, string $goal ): void {
		$needs = ! empty( $values['require_coupon_code'] ) || $goal === CampaignBuilderGoal::COUPON_CODE;
		if ( ! $needs ) {
			return;
		}

		$this->render_text_field(
			'coupon_code',
			__( 'Promotion code text', 'mp-commerce-promotions' ),
			(string) $values['coupon_code'],
			false,
			array(),
			__( 'Leave empty to rely on auto-generation below.', 'mp-commerce-promotions' )
		);
		echo '<div class="mp-cb-field mp-cb-field--checkbox">';
		echo '<span class="mp-cb-field__label">' . esc_html__( 'Auto-generate code', 'mp-commerce-promotions' ) . '</span>';
		echo '<span class="mp-cb-field__help description">'
			. esc_html__( 'Create a unique code when the text field above is empty.', 'mp-commerce-promotions' )
			. '</span>';
		printf(
			'<label class="mp-cb-field__control"><input type="checkbox" name="generate_coupon_code" value="1" %s /> %s</label>',
			checked( ! empty( $values['generate_coupon_code'] ), true, false ),
			esc_html__( 'Yes, auto-generate', 'mp-commerce-promotions' )
		);
		echo '</div>';
		$this->render_text_field(
			'code_usage_limit',
			__( 'Per-code redemption limit', 'mp-commerce-promotions' ),
			(string) $values['code_usage_limit'],
			false,
			array( 'type' => 'number', 'min' => '0', 'step' => '1' ),
			__( 'Optional cap on how many times this code can be used.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_discount_fields(
		array $values,
		bool $hide_type_switch,
		bool $category_context = false,
		bool $force_percentage = false
	): void {
		$discount_type = (string) ( $values['discount_type'] ?? 'percentage' );

		if ( ! $hide_type_switch && ! $force_percentage ) {
			echo '<div class="mp-cb-field mp-cb-field--radio">';
			echo '<span class="mp-cb-field__label">' . esc_html__( 'Discount type', 'mp-commerce-promotions' ) . '</span>';
			echo '<span class="mp-cb-field__help description">'
				. esc_html__( 'Choose percentage off or a fixed cart discount.', 'mp-commerce-promotions' )
				. '</span>';
			echo '<span class="mp-cb-field__control">';
			printf(
				'<label class="mp-cb-radio"><input type="radio" name="discount_type" value="percentage" %s /> %s</label>',
				checked( $discount_type !== 'fixed', true, false ),
				esc_html__( 'Percentage', 'mp-commerce-promotions' )
			);
			printf(
				'<label class="mp-cb-radio"><input type="radio" name="discount_type" value="fixed" %s /> %s</label>',
				checked( $discount_type, 'fixed', false ),
				esc_html__( 'Fixed amount', 'mp-commerce-promotions' )
			);
			echo '</span></div>';
		} elseif ( $force_percentage ) {
			echo '<input type="hidden" name="discount_type" value="percentage" />';
		}

		if ( $hide_type_switch && ! $force_percentage ) {
			echo '<input type="hidden" name="discount_type" value="percentage" />';
		}

		$this->render_text_field(
			'percentage',
			__( 'Percentage discount', 'mp-commerce-promotions' ),
			(string) $values['percentage'],
			false,
			array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100' ),
			__( 'Percent off eligible items or cart subtotal.', 'mp-commerce-promotions' )
		);
		$this->render_text_field(
			'amount',
			__( 'Fixed amount discount', 'mp-commerce-promotions' ),
			(string) $values['amount'],
			false,
			array( 'type' => 'number', 'step' => '0.01', 'min' => '0' ),
			__( 'Flat currency amount off when using fixed discount type.', 'mp-commerce-promotions' )
		);
	}

	/**
	 * @param array<int|string> $selected
	 */
	private function render_role_checkboxes( array $selected ): void {
		$selected = array_map( 'sanitize_key', array_map( 'strval', $selected ) );

		global $wp_roles;

		echo '<div class="mp-cb-field">';
		echo '<span class="mp-cb-field__label">' . esc_html__( 'Eligible roles', 'mp-commerce-promotions' ) . '</span>';
		echo '<span class="mp-cb-field__help description">'
			. esc_html__( 'Only customers with these WordPress roles receive the discount.', 'mp-commerce-promotions' )
			. '</span>';
		echo '<fieldset class="mp-cb-role-boxes">';
		if ( ! $wp_roles instanceof WP_Roles ) {
			echo '<p>' . esc_html__( 'Roles are unavailable.', 'mp-commerce-promotions' ) . '</p>';
			echo '</fieldset></div>';

			return;
		}

		$roles = array_keys( $wp_roles->roles );
		sort( $roles );
		foreach ( $roles as $role ) {
			printf(
				'<label style="display:block;"><input type="checkbox" name="roles[]" value="%1$s" %3$s /> %2$s</label>',
				esc_attr( $role ),
				esc_html( $role ),
				checked( in_array( sanitize_key( (string) $role ), $selected, true ), true, false )
			);
		}
		echo '</fieldset></div>';
	}

	/**
	 * @param array<int> $selected
	 */
	private function render_category_checkboxes( array $selected ): void {
		$list = array();
		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 40,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( isset( $term->term_id, $term->name ) ) {
						$list[] = $term;
					}
				}
			}
		}

		echo '<div class="mp-cb-field">';
		echo '<span class="mp-cb-field__label">' . esc_html__( 'Categories', 'mp-commerce-promotions' ) . '</span>';
		echo '<span class="mp-cb-field__help description">'
			. esc_html__( 'Select one or more product categories for this offer.', 'mp-commerce-promotions' )
			. '</span>';
		echo '<fieldset class="mp-cb-cat-boxes">';
		if ( $list === array() ) {
			echo '<p>' . esc_html__( 'No product categories found.', 'mp-commerce-promotions' ) . '</p>';
			echo '</fieldset></div>';

			return;
		}

		foreach ( $list as $term ) {
			/** @var \WP_Term $term */
			$id = (int) $term->term_id;

			printf(
				'<label style="display:block;"><input type="checkbox" name="category_ids[]" value="%d" %s /> %s</label>',
				$id,
				checked( in_array( $id, $selected, true ), true, false ),
				esc_html( (string) $term->name )
			);
		}

		echo '</fieldset></div>';
	}

	private function radio_yes_no( string $field, bool $yes, string $label, string $help ): void {
		echo '<div class="mp-cb-field mp-cb-field--radio">';
		echo '<span class="mp-cb-field__label" id="mp_cb_label_' . esc_attr( $field ) . '">'
			. esc_html( $label ) . '</span>';
		echo '<span class="mp-cb-field__help description">' . esc_html( $help ) . '</span>';
		echo '<span class="mp-cb-field__control" role="radiogroup" aria-labelledby="mp_cb_label_'
			. esc_attr( $field ) . '">';
		printf(
			'<label class="mp-cb-radio"><input type="radio" name="%1$s" value="1" %3$s /> %2$s</label>',
			esc_attr( $field ),
			esc_html__( 'Yes', 'mp-commerce-promotions' ),
			checked( $yes, true, false )
		);
		printf(
			'<label class="mp-cb-radio"><input type="radio" name="%1$s" value="0" %3$s /> %2$s</label>',
			esc_attr( $field ),
			esc_html__( 'No', 'mp-commerce-promotions' ),
			checked( $yes, false, false )
		);
		echo '</span></div>';
	}

	private function open_form_card( string $title ): void {
		echo '<div class="mp-cb-form-card">';
		echo '<h3 class="mp-cb-form-card__title">' . esc_html( $title ) . '</h3>';
	}

	private function close_form_card(): void {
		echo '</div>';
	}

	private function render_field_notice( string $label, string $help ): void {
		echo '<div class="mp-cb-field mp-cb-field--notice">';
		echo '<span class="mp-cb-field__label">' . esc_html( $label ) . '</span>';
		echo '<p class="mp-cb-field__help description">' . esc_html( $help ) . '</p>';
		echo '</div>';
	}

	/**
	 * @param array<string, string|null> $more
	 */
	private function render_text_field(
		string $name,
		string $label,
		string $value,
		bool $required,
		array $more = array(),
		?string $help = null
	): void {
		$type      = isset( $more['type'] ) ? (string) $more['type'] : 'text';
		$id        = 'mp_cb_' . $name;
		$key_attrs = '';

		unset( $more['type'] );
		foreach ( $more as $k => $val ) {
			if ( ! is_scalar( $val ) ) {
				continue;
			}
			$key_attrs .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( (string) $val ) );
		}

		echo '<p class="mp-cb-field">';
		printf(
			'<label class="mp-cb-field__label" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html( $label )
		);
		printf(
			'<input class="widefat mp-cb-field__input" type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s />',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			$required ? ' required' : '',
			$key_attrs
		);
		if ( $help !== null && $help !== '' ) {
			echo '<span class="mp-cb-field__help description">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}

	private function render_datetime_local( string $name, string $label, string $value, ?string $help = null ): void {
		$id = 'mp_cb_' . $name;

		echo '<p class="mp-cb-field">';
		printf(
			'<label class="mp-cb-field__label" for="%1$s">%2$s</label>',
			esc_attr( $id ),
			esc_html( $label )
		);
		printf(
			'<input class="widefat mp-cb-field__input" type="datetime-local" id="%1$s" name="%2$s" value="%3$s" />',
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value )
		);
		if ( $help !== null && $help !== '' ) {
			echo '<span class="mp-cb-field__help description">' . esc_html( $help ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * @param array<string, mixed> $form
	 * @param array<string, mixed> $ui_state
	 */
	private function render_preview_sidebar( string $goal, array $form, array $ui_state = array() ): void {
		$blocks    = $this->preview->summarize_form( $goal, $form );
		$headline  = CampaignSummaryFormatter::headline( $goal, $ui_state );
		$insights  = $this->insights->analyze_form( $goal, $ui_state );
		$sections  = CampaignSummaryFormatter::review_sections( $goal, $ui_state );

		echo '<div class="mp-cb-panel mp-cb-preview mp-cb-preview-card">';
		echo '<div class="mp-cb-preview__hero">';
		echo '<h3 class="mp-cb-preview__heading">' . esc_html__( 'Campaign preview', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="mp-cb-preview__headline">' . esc_html( $headline ) . '</p>';
		echo '</div>';
		$this->render_confidence_panel( $insights );
		echo '<div class="mp-cb-preview-section"><h4 class="mp-cb-preview-section__title">'
			. esc_html__( 'Timeline', 'mp-commerce-promotions' ) . '</h4>';
		echo '<p>' . esc_html( $sections['schedule'] ) . '</p></div>';
		echo '<div class="mp-cb-preview-section"><h4 class="mp-cb-preview-section__title">'
			. esc_html__( 'Customer benefit', 'mp-commerce-promotions' ) . '</h4>';
		echo '<p>' . esc_html( $sections['benefit'] ) . '</p></div>';
		echo '<div class="mp-cb-preview-section"><h4 class="mp-cb-preview-section__title">'
			. esc_html__( 'Targeting', 'mp-commerce-promotions' ) . '</h4>';
		echo '<p>' . esc_html( $sections['targeting'] ) . '</p>';
		$this->render_preview_scope_badges( $ui_state );
		echo '</div>';
		echo '<div class="mp-cb-preview-section"><h4 class="mp-cb-preview-section__title">'
			. esc_html__( 'Limits', 'mp-commerce-promotions' ) . '</h4>';
		echo '<p>' . esc_html( $sections['limits'] ) . '</p></div>';
		echo '<ul class="mp-cb-preview__bullets">';
		$this->render_preview_item( __( 'Applies when', 'mp-commerce-promotions' ), (string) ( $blocks['applies_when'] ?? '' ) );
		$this->render_preview_item( __( 'Stacking', 'mp-commerce-promotions' ), (string) ( $blocks['stacking'] ?? '' ) );
		$this->render_preview_item( __( 'Coupon', 'mp-commerce-promotions' ), (string) ( $blocks['coupon'] ?? '' ) );
		echo '</ul>';

		if ( ! empty( $blocks['warnings'] ) ) {
			echo '<div class="mp-cb-preview-box mp-cb-preview-box--advice mp-cb-smart-advice">';
			echo '<h4 class="mp-cb-smart-advice__title">';
			echo '<span class="dashicons dashicons-lightbulb" aria-hidden="true"></span> ';
			echo esc_html__( 'Smart advice', 'mp-commerce-promotions' );
			echo '</h4><ul>';
			foreach ( $blocks['warnings'] as $w ) {
				echo '<li>' . esc_html( (string) $w ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( ! empty( $blocks['recommendations'] ) ) {
			echo '<div class="mp-cb-preview-box mp-cb-preview-box--next mp-cb-next-steps">';
			echo '<h4 class="mp-cb-next-steps__title">' . esc_html__( 'What happens next', 'mp-commerce-promotions' ) . '</h4>';
			echo '<ol class="mp-cb-next-steps__list">';
			foreach ( $blocks['recommendations'] as $rec ) {
				echo '<li>' . esc_html( (string) $rec ) . '</li>';
			}
			echo '</ol></div>';
		}

		$this->render_sidebar_escape_hatch();
		echo '</div>';
	}

	private function render_preview_item( string $label, string $value ): void {
		echo '<li class="mp-cb-preview__item">';
		echo '<strong class="mp-cb-preview__item-label">' . esc_html( $label ) . '</strong>';
		echo '<span class="mp-cb-preview__item-value">' . esc_html( $value !== '' ? $value : '—' ) . '</span>';
		echo '</li>';
	}

	/**
	 * @param array<string, mixed> $ui_state
	 */
	private function render_preview_scope_badges( array $ui_state ): void {
		$badges = array();

		$category_ids = array_map( 'intval', (array) ( $ui_state['category_ids'] ?? array() ) );
		$category_ids = array_values( array_filter( $category_ids, static fn( int $id ): bool => $id > 0 ) );
		if ( $category_ids !== array() && function_exists( 'get_term' ) ) {
			foreach ( $category_ids as $cat_id ) {
				$term = get_term( $cat_id, 'product_cat' );
				if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
					$badges[] = (string) $term->name;
				}
			}
		}

		$product_ids = array();
		if ( isset( $ui_state['product_ids'] ) && is_array( $ui_state['product_ids'] ) ) {
			$product_ids = array_map( 'intval', $ui_state['product_ids'] );
		} elseif ( isset( $ui_state['product_ids_csv'] ) ) {
			$parts = preg_split( '/[\s,]+/', (string) $ui_state['product_ids_csv'] ) ?: array();
			foreach ( $parts as $part ) {
				$id = (int) $part;
				if ( $id > 0 ) {
					$product_ids[] = $id;
				}
			}
		}
		$product_ids = array_values( array_unique( array_filter( $product_ids, static fn( int $id ): bool => $id > 0 ) ) );
		foreach ( $product_ids as $product_id ) {
			$badges[] = sprintf(
				/* translators: %d: WooCommerce product ID */
				__( 'Product #%d', 'mp-commerce-promotions' ),
				$product_id
			);
		}

		if ( $badges === array() ) {
			return;
		}

		echo '<div class="mp-cb-preview__scope">';
		echo '<span class="mp-cb-preview__scope-label">' . esc_html__( 'Scope', 'mp-commerce-promotions' ) . '</span>';
		echo '<span class="mp-cb-preview__scope-badges">';
		foreach ( $badges as $badge ) {
			echo '<span class="mp-cb-scope-badge">' . esc_html( $badge ) . '</span>';
		}
		echo '</span></div>';
	}

	/**
	 * @param array<string, mixed> $ui_state
	 */
	private function format_preview_dates( array $ui_state ): string {
		$starts = isset( $ui_state['starts_at'] ) ? trim( (string) $ui_state['starts_at'] ) : '';
		$ends   = isset( $ui_state['ends_at'] ) ? trim( (string) $ui_state['ends_at'] ) : '';
		if ( $starts === '' && $ends === '' ) {
			return __( 'No schedule set — runs until paused or archived.', 'mp-commerce-promotions' );
		}

		return trim( ( $starts !== '' ? $starts : '—' ) . ' → ' . ( $ends !== '' ? $ends : '—' ) );
	}

	private function render_sidebar_escape_hatch(): void {
		echo '<div class="mp-cb-preview-box mp-cb-preview-box--power mp-cb-sidebar-escape">';
		echo '<h4>' . esc_html__( 'Need more power?', 'mp-commerce-promotions' ) . '</h4>';
		echo '<p class="description">' . esc_html__(
			'Use the Advanced Editor for JSON rules, cart simulation, codes, and orchestration.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url( AdminUrl::tab( AdminNavigation::TAB_ALL ) ) . '">'
			. esc_html__( 'Open Advanced Editor', 'mp-commerce-promotions' ) . '</a></p>';
		echo '</div>';
	}

	private function render_campaign_list(): void {
		$rows = $this->promotions->find_filtered( array( 'limit' => 10 ) );

		echo '<h2 class="mp-cb-section__title mp-cb-section__title--table">' . esc_html__( 'Recent campaigns', 'mp-commerce-promotions' ) . '</h2>';

		echo '<table class="widefat striped mp-cb-table"><thead class="mp-cb-table__head"><tr>';
		foreach (
			array(
				'name'       => __( 'Name', 'mp-commerce-promotions' ),
				'summary'    => __( 'Summary', 'mp-commerce-promotions' ),
				'type'       => __( 'Type / goal', 'mp-commerce-promotions' ),
				'status'     => __( 'Status', 'mp-commerce-promotions' ),
				'starts_at'  => __( 'Starts', 'mp-commerce-promotions' ),
				'ends_at'    => __( 'Ends', 'mp-commerce-promotions' ),
				'usage'      => __( 'Usage', 'mp-commerce-promotions' ),
				'budget'     => __( 'Budget', 'mp-commerce-promotions' ),
				'health'     => __( 'Health', 'mp-commerce-promotions' ),
				'actions_col'=> __( 'Actions', 'mp-commerce-promotions' ),
			) as $th
		) {
			echo '<th>' . esc_html( $th ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $promotion ) {
			$id         = $promotion->get_id();
			$edit_key   = $id !== null && $id > 0 ? $id : $promotion->get_uuid();
			$edit_url   = AdminUrl::edit_promotion( $edit_key );

			echo '<tr>';
			echo '<td>' . esc_html( $promotion->get_name() ) . '</td>';
			echo '<td class="mp-cb-table__summary">' . esc_html( CampaignSummaryFormatter::from_promotion( $promotion ) ) . '</td>';
			echo '<td>' . esc_html( $this->goal_label_for_promotion( $promotion ) ) . '</td>';
			echo '<td>' . $this->render_status_badge( $promotion->get_status() ) . ' ';
			$this->render_lifecycle_bar( $promotion );
			echo '</td>';
			echo '<td>' . esc_html( $this->format_datetime_cell( $promotion->get_starts_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime_cell( $promotion->get_ends_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_usage_cell( $promotion ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_budget_cell( $promotion ) ) . '</td>';
			echo '<td>' . $this->render_health_badge( $promotion ) . '</td>';
			echo '<td class="mp-cb-table__actions">';
			printf(
				'<a class="button button-small" href="%1$s">%2$s</a> ',
				esc_url( $edit_url ),
				esc_html__( 'View', 'mp-commerce-promotions' )
			);
			printf(
				'<a class="button button-small" href="%1$s">%2$s</a> ',
				esc_url( $edit_url ),
				esc_html__( 'Advanced edit', 'mp-commerce-promotions' )
			);
			if ( $id !== null && $id > 0 ) {
				$this->render_duplicate_form( $id );
			}
			echo '</td>';
			echo '</tr>';
		}

		if ( $rows === array() ) {
			echo '<tr><td colspan="10">';
			echo esc_html__( 'No promotions found yet.', 'mp-commerce-promotions' );
			echo '</td></tr>';
		}

		echo '</tbody></table>';
	}

	private function format_datetime_cell( ?string $value ): string {
		if ( $value === null || trim( $value ) === '' ) {
			return '—';
		}

		return $value;
	}

	private function format_usage_cell( Promotion $promotion ): string {
		$limit = $promotion->get_usage_limit();
		$cnt   = $promotion->get_usage_count();

		return $limit === null ? (string) $cnt . ' / ∞' : (string) $cnt . ' / ' . (string) $limit;
	}

	private function format_budget_cell( Promotion $promotion ): string {
		if ( ! $promotion->has_budget_cap() ) {
			return '—';
		}

		return sprintf(
			'%s / %s',
			number_format_i18n( (float) $promotion->get_budget_spent(), 2 ),
			number_format_i18n( (float) ( $promotion->get_budget_amount() ?? 0.0 ), 2 )
		);
	}

	private function format_health_cell( Promotion $promotion ): string {
		$id = $promotion->get_id();

		if ( $id !== null && $this->health_monitor instanceof PromotionHealthMonitor ) {
			$bad = 0;
			foreach ( $this->resolve_health_issues() as $issue ) {
				if ( isset( $issue['promotion_ids'] ) && is_array( $issue['promotion_ids'] )
					&& in_array( $id, array_map( 'intval', $issue['promotion_ids'] ), true ) ) {
					++$bad;
				}
			}
			return $bad > 0
				? sprintf(
				/* translators: %d: issue count */
					__( '%d health notes', 'mp-commerce-promotions' ),
					$bad
				)
				: __( 'OK', 'mp-commerce-promotions' );
		}

		$error_count = 0;
		foreach ( $this->validator->validate( $promotion ) as $row ) {
			if ( ( $row['level'] ?? '' ) === 'error' ) {
				++$error_count;
			}
		}

		return $error_count > 0
			? sprintf(
				/* translators: %d: validation error count */
				__( '%d validation issues', 'mp-commerce-promotions' ),
				$error_count
			)
			: __( 'OK', 'mp-commerce-promotions' );
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function resolve_health_issues(): array {
		if ( $this->health_issue_cache !== null ) {
			return $this->health_issue_cache;
		}
		if ( $this->health_monitor === null ) {
			$this->health_issue_cache = array();

			return $this->health_issue_cache;
		}

		$this->health_issue_cache = $this->health_monitor->analyze( 500 );

		return $this->health_issue_cache;
	}

	private function goal_label_for_promotion( Promotion $promotion ): string {
		$g = CampaignBuilderGoal::parse_goal_from_notes( $promotion->get_internal_notes() );

		return $g !== null ? CampaignBuilderGoal::label( $g ) : __( 'General', 'mp-commerce-promotions' );
	}

	private function status_label( string $status ): string {
		$labels = array(
			PromotionStatus::DRAFT    => __( 'Draft', 'mp-commerce-promotions' ),
			PromotionStatus::ACTIVE   => __( 'Active', 'mp-commerce-promotions' ),
			PromotionStatus::PAUSED   => __( 'Paused', 'mp-commerce-promotions' ),
			PromotionStatus::ARCHIVED => __( 'Archived', 'mp-commerce-promotions' ),
		);

		return $labels[ $status ] ?? $status;
	}

	private function render_status_badge( string $status ): string {
		$class = 'mp-cb-badge-status mp-cb-badge-status--' . sanitize_html_class( $status );

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $this->status_label( $status ) ) . '</span>';
	}

	private function render_health_badge( Promotion $promotion ): string {
		$text = $this->format_health_cell( $promotion );
		$class = 'mp-cb-badge-health mp-cb-badge-health--ok';
		if ( $text !== __( 'OK', 'mp-commerce-promotions' ) ) {
			$class = 'mp-cb-badge-health mp-cb-badge-health--warn';
		}

		return '<span class="' . esc_attr( $class ) . '">' . esc_html( $text ) . '</span>';
	}

	private function render_duplicate_form( int $promotion_id ): void {
		echo '<form method="post" class="mp-cb-dup-form">';
		wp_nonce_field( self::DUPLICATE_NONCE_ACTION, self::DUPLICATE_NONCE_FIELD );
		printf( '<input type="hidden" name="mp_cb_duplicate_promotion_id" value="%d" />', $promotion_id );
		submit_button( __( 'Duplicate', 'mp-commerce-promotions' ), 'small', 'mp_cb_duplicate_submit', false );
		echo '</form>';
	}

	private function render_escape_hatch(): void {
		echo '<div class="mp-cb-escape mp-cb-panel">';
		echo '<h3>' . esc_html__( 'Need more power?', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Campaign Builder creates standard promotions. For JSON rules, diagnostics, and cart simulation, use the Advanced Editor.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<p><a class="button button-secondary" href="' . esc_url( AdminUrl::tab( AdminNavigation::TAB_ALL ) ) . '">'
			. esc_html__( 'Go to Advanced Editor', 'mp-commerce-promotions' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function default_form_values( string $goal ): array {
		return array(
			'campaign_name'             => '',
			'campaign_label'            => '',
			'starts_at'                 => '',
			'ends_at'                   => '',
			'budget_amount'             => '',
			'usage_limit'               => '',
			'stackable'                 => false,
			'require_coupon_code'       => $goal === CampaignBuilderGoal::COUPON_CODE,
			'category_ids'              => array(),
			'product_ids_csv'           => '',
			'product_ids'               => array(),
			'discount_type'             => 'percentage',
			'percentage'                => '10',
			'amount'                    => '10',
			'bogo_scope'                => CheapestItemDiscountAction::SCOPE_CATEGORY,
			'required_quantity'         => '1',
			'discounted_quantity'       => '1',
			'discount_percentage'       => '100',
			'minimum_eligible_subtotal' => '',
			'minimum_subtotal'          => '',
			'gift_product_id'           => '',
			'gift_quantity'             => '1',
			'roles'                     => array(),
			'coupon_code'               => '',
			'generate_coupon_code'      => false,
			'code_usage_limit'          => '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_ui_state_from_get( string $goal ): array {
		return $this->parse_ui_bag( $goal, wp_unslash( $_GET ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_form_from_post( string $goal ): array {
		return $this->draft_payload_from_ui_state( $goal, $this->parse_ui_state_from_post( $goal ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function parse_ui_state_from_post( string $goal ): array {
		return $this->parse_ui_bag( $goal, wp_unslash( $_POST ) );
	}

	/**
	 * @param array<string, mixed> $bag Unslashed `$_GET` / `$_POST` fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function parse_ui_bag( string $goal, array $bag ): array {
		if ( isset( $bag['mp_cb_state_json'] ) && is_string( $bag['mp_cb_state_json'] ) ) {
			$raw     = wp_unslash( $bag['mp_cb_state_json'] );
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$bag = array_merge( $decoded, $bag );
			}
		}

		$out = $this->default_form_values( $goal );
		$t   = static function ( array $bag, string $key ): string {
			return isset( $bag[ $key ] ) ? sanitize_text_field( (string) $bag[ $key ] ) : '';
		};

		$out['campaign_name']               = $t( $bag, 'campaign_name' );
		$out['campaign_label']              = $t( $bag, 'campaign_label' );
		$out['starts_at']                   = isset( $bag['starts_at'] )
			? $this->sanitize_datetime_local( (string) $bag['starts_at'] ) : '';
		$out['ends_at']                     = isset( $bag['ends_at'] )
			? $this->sanitize_datetime_local( (string) $bag['ends_at'] ) : '';
		$out['budget_amount']               = $t( $bag, 'budget_amount' );
		$out['usage_limit']                 = $t( $bag, 'usage_limit' );
		$out['stackable']                   = isset( $bag['stackable'] ) && (string) $bag['stackable'] === '1';
		$out['require_coupon_code']         = isset( $bag['require_coupon_code'] ) && (string) $bag['require_coupon_code'] === '1';
		$out['discount_type']               = isset( $bag['discount_type'] )
			? sanitize_key( (string) $bag['discount_type'] ) : 'percentage';
		$out['percentage']                  = $t( $bag, 'percentage' );
		$out['amount']                      = $t( $bag, 'amount' );
		$out['bogo_scope']                  = isset( $bag['bogo_scope'] )
			? sanitize_key( (string) $bag['bogo_scope'] ) : CheapestItemDiscountAction::SCOPE_CATEGORY;
		$out['required_quantity']           = $t( $bag, 'required_quantity' );
		$out['discounted_quantity']         = $t( $bag, 'discounted_quantity' );
		$out['discount_percentage']         = $t( $bag, 'discount_percentage' );
		$out['minimum_eligible_subtotal']   = $t( $bag, 'minimum_eligible_subtotal' );
		$out['minimum_subtotal']            = $t( $bag, 'minimum_subtotal' );
		$out['gift_product_id']             = $t( $bag, 'gift_product_id' );
		$out['gift_quantity']               = $t( $bag, 'gift_quantity' );
		$out['product_ids_csv']             = $t( $bag, 'product_ids_csv' );
		$out['coupon_code']                 = $t( $bag, 'coupon_code' );
		$out['generate_coupon_code']        = isset( $bag['generate_coupon_code'] );
		$out['code_usage_limit']            = $t( $bag, 'code_usage_limit' );
		$out['category_ids']                = $this->array_int_from_request( $bag, 'category_ids' );
		$product_ids                        = $this->array_int_from_request( $bag, 'product_ids' );
		$out['product_ids']                 = $product_ids !== array()
			? $product_ids
			: $this->comma_ids_to_list( $out['product_ids_csv'] );
		$out['roles']                       = isset( $bag['roles'] ) && is_array( $bag['roles'] )
			? array_map( 'sanitize_key', array_map( 'strval', $bag['roles'] ) ) : array();

		return $out;
	}

	/**
	 * Shapes array for {@see CampaignBuilderDraftCreator::create_draft()}.
	 *
	 * @param array<string, mixed> $src
	 * @return array<string, mixed>
	 */
	private function build_draft_payload( string $goal, array $src ): array {
		$require_code = ! empty( $src['require_coupon_code'] ) || $goal === CampaignBuilderGoal::COUPON_CODE;

		return array(
			'campaign_goal'             => $goal,
			'campaign_name'             => (string) $src['campaign_name'],
			'campaign_label'            => (string) $src['campaign_label'],
			'starts_at'                   => $this->datetime_local_to_sql( (string) $src['starts_at'] ),
			'ends_at'                     => $this->datetime_local_to_sql( (string) $src['ends_at'] ),
			'budget_amount'             => $src['budget_amount'],
			'usage_limit'               => $src['usage_limit'],
			'stackable'                 => ! empty( $src['stackable'] ),
			'require_coupon_code'       => $require_code,
			'category_ids'              => array_values( array_filter( array_map( 'intval', (array) $src['category_ids'] ) ) ),
			'product_ids'               => array_values(
				array_filter(
					array_map( 'intval', (array) ( $src['product_ids'] ?? array() ) ),
					static fn ( int $i ): bool => $i > 0
				)
			),
			'discount_type'             => sanitize_key( (string) ( $src['discount_type'] ?? 'percentage' ) ),
			'percentage'                => $src['percentage'],
			'amount'                    => $src['amount'],
			'bogo_scope'                => in_array( (string) $src['bogo_scope'], array( CheapestItemDiscountAction::SCOPE_CATEGORY, CheapestItemDiscountAction::SCOPE_PRODUCTS ), true )
				? (string) $src['bogo_scope'] : CheapestItemDiscountAction::SCOPE_CATEGORY,
			'required_quantity'         => $src['required_quantity'],
			'discounted_quantity'       => $src['discounted_quantity'],
			'discount_percentage'       => $src['discount_percentage'],
			'minimum_eligible_subtotal' => $src['minimum_eligible_subtotal'],
			'minimum_subtotal'          => $src['minimum_subtotal'],
			'gift_product_id'           => $src['gift_product_id'],
			'gift_quantity'             => $src['gift_quantity'],
			'roles'                     => array_values( array_filter( array_map( 'sanitize_key', (array) $src['roles'] ) ) ),
			'coupon_code'               => (string) $src['coupon_code'],
			'generate_coupon_code'      => ! empty( $src['generate_coupon_code'] ),
			'code_usage_limit'          => $src['code_usage_limit'],
		);
	}

	private function sanitize_datetime_local( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}

		return preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $raw ) ? $raw : '';
	}

	private function datetime_local_to_sql( string $local ): string {
		$local = trim( $local );
		if ( $local === '' ) {
			return '';
		}

		$norm = str_replace( 'T', ' ', $local );

		return $norm;
	}

	/**
	 * @param array<string, mixed> $src
	 * @return list<int>
	 */
	private function array_int_from_request( array $src, string $key ): array {
		if ( ! isset( $src[ $key ] ) || ! is_array( $src[ $key ] ) ) {
			return array();
		}

		$out = array();
		foreach ( $src[ $key ] as $v ) {
			$i = (int) $v;
			if ( $i > 0 ) {
				$out[] = $i;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * @return list<int>
	 */
	private function comma_ids_to_list( string $csv ): array {
		$parts = preg_split( '/[\s,]+/', $csv, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $parts ) ) {
			return array();
		}

		$out = array();
		foreach ( $parts as $p ) {
			$i = (int) $p;
			if ( $i > 0 ) {
				$out[] = $i;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private function handle_wizard_navigation(): void {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'POST' ) {
			return;
		}
		if ( ! isset( $_POST['mp_cb_wizard_nav'] ) ) {
			return;
		}

		$nonce = isset( $_POST[ self::WIZARD_NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::WIZARD_NONCE_FIELD ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::WIZARD_NONCE_ACTION ) ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'invalid_nonce' ) );
		}

		$goal = CampaignBuilderGoal::sanitize(
			isset( $_POST['campaign_goal'] ) ? wp_unslash( (string) $_POST['campaign_goal'] ) : null
		);
		if ( $goal === null ) {
			$this->redirect_to_builder( array( 'mp_cb_error' => 'invalid_goal' ) );
		}

		$step = CampaignBuilderStep::sanitize(
			isset( $_POST['cb_step'] ) ? wp_unslash( (string) $_POST['cb_step'] ) : null
		);
		if ( $step === null ) {
			$step = CampaignBuilderStep::initial_after_goal( $goal );
		}

		$ui  = $this->parse_ui_state_from_post( $goal );
		$dir = sanitize_key( wp_unslash( (string) $_POST['mp_cb_wizard_nav'] ) );
		$next = $dir === 'back'
			? CampaignBuilderStep::navigate( $goal, $step, 'back' )
			: CampaignBuilderStep::navigate( $goal, $step, 'next' );

		$token = $this->persist_wizard_state( $goal, $ui );
		$this->redirect_to_builder(
			array(
				'campaign_goal' => $goal,
				'cb_step'       => $next,
				'cb_token'      => $token,
			)
		);
	}

	private function resolve_wizard_step( string $goal ): string {
		$step = CampaignBuilderStep::sanitize(
			isset( $_GET['cb_step'] ) ? wp_unslash( (string) $_GET['cb_step'] ) : null
		);
		$flow = CampaignBuilderStep::flow_for_goal( $goal );
		if ( $step !== null && in_array( $step, $flow, true ) ) {
			return $step;
		}

		return CampaignBuilderStep::initial_after_goal( $goal );
	}

	private function wizard_transient_key( string $token ): string {
		return self::WIZARD_TRANSIENT_PREFIX . (int) get_current_user_id() . '_' . sanitize_key( $token );
	}

	/**
	 * @param array<string, mixed> $ui
	 */
	private function persist_wizard_state( string $goal, array $ui ): string {
		$token = isset( $_POST['cb_token'] ) ? sanitize_key( wp_unslash( (string) $_POST['cb_token'] ) ) : '';
		if ( $token === '' ) {
			$token = wp_generate_password( 16, false, false );
		}
		set_transient(
			$this->wizard_transient_key( $token ),
			array(
				'goal' => $goal,
				'ui'   => $ui,
			),
			HOUR_IN_SECONDS
		);

		return $token;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load_wizard_state( string $token, string $goal ): ?array {
		if ( $token === '' ) {
			return null;
		}
		$stored = get_transient( $this->wizard_transient_key( $token ) );
		if ( ! is_array( $stored ) || (string) ( $stored['goal'] ?? '' ) !== $goal ) {
			return null;
		}

		return isset( $stored['ui'] ) && is_array( $stored['ui'] ) ? $stored['ui'] : null;
	}

	private function render_mode_switch(): void {
		echo '<nav class="mp-cb-mode-switch" aria-label="' . esc_attr__( 'Editor mode', 'mp-commerce-promotions' ) . '">';
		echo '<span class="mp-cb-mode-switch__item is-active">' . esc_html__( 'Simple Campaign Builder', 'mp-commerce-promotions' ) . '</span>';
		printf(
			'<a class="mp-cb-mode-switch__item mp-cb-mode-switch__item--link" href="%s">%s</a>',
			esc_url( AdminUrl::tab( AdminNavigation::TAB_ALL ) ),
			esc_html__( 'Advanced Editor', 'mp-commerce-promotions' )
		);
		echo '<p class="mp-cb-mode-switch__hint description">'
			. esc_html__(
				'Need advanced targeting, orchestration, or JSON rules? Use Advanced Editor.',
				'mp-commerce-promotions'
			)
			. '</p></nav>';
	}

	private function render_wizard_progress( ?string $goal, string $current ): void {
		$steps = $goal === null
			? array( CampaignBuilderStep::GOAL )
			: array_merge( array( CampaignBuilderStep::GOAL ), CampaignBuilderStep::flow_for_goal( $goal ) );

		$current_idx = array_search( $current, $steps, true );
		echo '<ol class="mp-cb-progress" aria-label="' . esc_attr__( 'Campaign setup progress', 'mp-commerce-promotions' ) . '">';
		foreach ( $steps as $idx => $step ) {
			$classes = 'mp-cb-progress__step';
			if ( $step === $current ) {
				$classes .= ' is-current';
			} elseif ( $current_idx !== false && $idx < $current_idx ) {
				$classes .= ' is-complete';
			}
			echo '<li class="' . esc_attr( $classes ) . '">';
			echo '<span class="mp-cb-progress__label">' . esc_html( CampaignBuilderStep::label( $step ) ) . '</span>';
			echo '</li>';
		}
		echo '</ol>';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_wizard( string $goal, string $step, array $values ): void {
		$post_url = add_query_arg(
			array( 'campaign_goal' => $goal ),
			AdminUrl::tab( AdminNavigation::TAB_CAMPAIGN_BUILDER )
		);
		$token = isset( $_GET['cb_token'] ) ? sanitize_key( wp_unslash( (string) $_GET['cb_token'] ) ) : '';

		echo '<form class="mp-cb-wizard-form" method="post" action="' . esc_url( $post_url ) . '">';
		wp_nonce_field( self::WIZARD_NONCE_ACTION, self::WIZARD_NONCE_FIELD );
		echo '<input type="hidden" name="campaign_goal" value="' . esc_attr( $goal ) . '" />';
		echo '<input type="hidden" name="cb_step" value="' . esc_attr( $step ) . '" />';
		if ( $token !== '' ) {
			echo '<input type="hidden" name="cb_token" value="' . esc_attr( $token ) . '" />';
		}
		$this->render_wizard_state_json( $values );

		echo '<div class="mp-cb-wizard-panel">';
		switch ( $step ) {
			case CampaignBuilderStep::TARGETING:
				$this->render_step_targeting( $goal, $values );
				break;
			case CampaignBuilderStep::OFFER:
				$this->render_step_offer( $goal, $values );
				break;
			case CampaignBuilderStep::SCHEDULE:
				$this->render_step_schedule( $goal, $values );
				break;
			case CampaignBuilderStep::REVIEW:
				$this->render_step_review( $goal, $values );
				break;
			default:
				$this->render_step_offer( $goal, $values );
		}
		echo '</div>';

		$this->render_wizard_nav( $goal, $step );
		echo '</form>';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_wizard_state_json( array $values ): void {
		$encoded = wp_json_encode( $values );
		if ( ! is_string( $encoded ) ) {
			return;
		}
		echo '<input type="hidden" name="mp_cb_state_json" value="' . esc_attr( $encoded ) . '" />';
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_step_targeting( string $goal, array $values ): void {
		$this->open_form_card( __( 'Who and what does this campaign apply to?', 'mp-commerce-promotions' ) );
		$this->render_text_field(
			'campaign_name',
			__( 'Campaign name', 'mp-commerce-promotions' ),
			(string) $values['campaign_name'],
			true,
			array(),
			__( 'Shown in admin and reports.', 'mp-commerce-promotions' )
		);
		$this->render_text_field(
			'campaign_label',
			__( 'Campaign label', 'mp-commerce-promotions' ),
			(string) $values['campaign_label'],
			false,
			array(),
			__( 'Optional internal tag for filtering.', 'mp-commerce-promotions' )
		);

		if ( in_array( $goal, array( CampaignBuilderGoal::CATEGORY_DISCOUNT, CampaignBuilderGoal::SCHEDULED ), true ) ) {
			$this->render_category_picker( array_map( 'intval', (array) ( $values['category_ids'] ?? array() ) ) );
		} elseif ( $goal === CampaignBuilderGoal::PRODUCT_DISCOUNT ) {
			$this->render_product_picker( $this->product_ids_from_values( $values ) );
		} elseif ( $goal === CampaignBuilderGoal::BUY_X_GET_Y ) {
			$this->render_bogo_scope( (string) $values['bogo_scope'] );
			if ( (string) $values['bogo_scope'] === CheapestItemDiscountAction::SCOPE_PRODUCTS ) {
				$this->render_product_picker( $this->product_ids_from_values( $values ) );
			} else {
				$this->render_category_picker( array_map( 'intval', (array) ( $values['category_ids'] ?? array() ) ) );
			}
		} elseif ( $goal === CampaignBuilderGoal::VIP_ROLE ) {
			$this->render_role_checkboxes( (array) ( $values['roles'] ?? array() ) );
		} else {
			echo '<p class="description">' . esc_html__(
				'This campaign type applies to all eligible shoppers — continue to configure the offer.',
				'mp-commerce-promotions'
			) . '</p>';
		}
		$this->close_form_card();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_step_offer( string $goal, array $values ): void {
		if ( trim( (string) ( $values['campaign_name'] ?? '' ) ) === '' ) {
			$this->open_form_card( __( 'Campaign name', 'mp-commerce-promotions' ) );
			$this->render_text_field(
				'campaign_name',
				__( 'Campaign name', 'mp-commerce-promotions' ),
				'',
				true
			);
			$this->close_form_card();
		}
		$this->open_form_card( __( 'What do customers get?', 'mp-commerce-promotions' ) );
		echo '<p class="mp-cb-step-lead">' . esc_html( CampaignSummaryFormatter::headline( $goal, $values ) ) . '</p>';
		$this->render_goal_specific_fields( $goal, $values );
		$this->close_form_card();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_step_schedule( string $goal, array $values ): void {
		$this->open_form_card( __( 'Schedule & limits', 'mp-commerce-promotions' ) );
		echo '<div class="mp-cb-fields-row">';
		$this->render_datetime_local(
			'starts_at',
			__( 'Starts', 'mp-commerce-promotions' ),
			(string) $values['starts_at'],
			__( 'Leave empty to start immediately after activation.', 'mp-commerce-promotions' )
		);
		$this->render_datetime_local(
			'ends_at',
			__( 'Ends', 'mp-commerce-promotions' ),
			(string) $values['ends_at'],
			__( 'Leave empty for no end date.', 'mp-commerce-promotions' )
		);
		echo '</div>';
		echo '<div class="mp-cb-fields-row">';
		$this->render_text_field(
			'budget_amount',
			__( 'Budget amount', 'mp-commerce-promotions' ),
			(string) $values['budget_amount'],
			false,
			array( 'type' => 'number', 'step' => '0.01', 'min' => '0' ),
			__( 'Optional cap on total discount spend.', 'mp-commerce-promotions' )
		);
		$this->render_text_field(
			'usage_limit',
			__( 'Usage limit', 'mp-commerce-promotions' ),
			(string) $values['usage_limit'],
			false,
			array( 'type' => 'number', 'step' => '1', 'min' => '0' ),
			__( 'Optional maximum redemptions for this campaign.', 'mp-commerce-promotions' )
		);
		echo '</div>';
		$this->close_form_card();

		$this->open_form_card( __( 'Coupon & stacking', 'mp-commerce-promotions' ) );
		$this->radio_yes_no(
			'stackable',
			(bool) $values['stackable'],
			__( 'Stacking', 'mp-commerce-promotions' ),
			__( 'Allow stacking with compatible promotions?', 'mp-commerce-promotions' )
		);
		if ( $goal === CampaignBuilderGoal::COUPON_CODE ) {
			$this->render_field_notice(
				__( 'Coupon requirement', 'mp-commerce-promotions' ),
				__( 'This campaign type requires a coupon code.', 'mp-commerce-promotions' )
			);
			echo '<input type="hidden" name="require_coupon_code" value="1" />';
		} else {
			$this->radio_yes_no(
				'require_coupon_code',
				(bool) $values['require_coupon_code'],
				__( 'Coupon requirement', 'mp-commerce-promotions' ),
				__( 'Require shoppers to enter a promotion code?', 'mp-commerce-promotions' )
			);
		}
		$this->render_coupon_optional_block( $values, $goal );
		$this->close_form_card();
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_step_review( string $goal, array $values ): void {
		$sections = CampaignSummaryFormatter::review_sections( $goal, $values );
		$insights = $this->insights->analyze_form( $goal, $values );

		$this->open_form_card( __( 'Review your campaign', 'mp-commerce-promotions' ) );
		echo '<p class="mp-cb-review-headline">' . esc_html( $sections['headline'] ) . '</p>';
		echo '<ul class="mp-cb-review-list">';
		foreach (
			array(
				__( 'Customer benefit', 'mp-commerce-promotions' ) => $sections['benefit'],
				__( 'Targeting', 'mp-commerce-promotions' )          => $sections['targeting'],
				__( 'Schedule', 'mp-commerce-promotions' )             => $sections['schedule'],
				__( 'Limits', 'mp-commerce-promotions' )             => $sections['limits'],
			) as $label => $text
		) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $text ) . '</li>';
		}
		echo '</ul>';
		$this->render_confidence_panel( $insights );
		$this->close_form_card();
	}

	private function render_wizard_nav( string $goal, string $step ): void {
		$flow = CampaignBuilderStep::flow_for_goal( $goal );
		$idx  = array_search( $step, $flow, true );
		$idx  = $idx === false ? 0 : (int) $idx;

		echo '<div class="mp-cb-wizard-nav">';
		if ( $idx > 0 ) {
			printf(
				'<button type="submit" class="button" name="mp_cb_wizard_nav" value="back">%s</button>',
				esc_html__( 'Back', 'mp-commerce-promotions' )
			);
		}
		if ( $step !== CampaignBuilderStep::REVIEW ) {
			printf(
				'<button type="submit" class="button button-primary" name="mp_cb_wizard_nav" value="next">%s</button>',
				esc_html__( 'Next', 'mp-commerce-promotions' )
			);
		} else {
			wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
			submit_button( __( 'Create draft campaign', 'mp-commerce-promotions' ), 'primary', 'mp_cb_submit_create', false );
		}
		echo '</div>';
	}

	/**
	 * @param array<int> $selected
	 */
	private function render_category_picker( array $selected ): void {
		echo '<div class="mp-cb-field">';
		echo '<span class="mp-cb-field__label">' . esc_html__( 'Categories', 'mp-commerce-promotions' ) . '</span>';
		echo '<span class="mp-cb-field__help description">'
			. esc_html__( 'Search and select product categories.', 'mp-commerce-promotions' ) . '</span>';
		echo '<div class="mp-cb-picker" data-mp-cb-picker="categories">';
		echo '<input type="search" class="mp-cb-picker__search widefat" placeholder="'
			. esc_attr__( 'Search categories…', 'mp-commerce-promotions' ) . '" autocomplete="off" />';
		echo '<div class="mp-cb-picker__results" role="listbox"></div>';
		echo '<div class="mp-cb-picker__selected">';
		foreach ( $selected as $id ) {
			$label = '#' . $id;
			if ( function_exists( 'get_term' ) ) {
				$term = get_term( $id, 'product_cat' );
				if ( $term instanceof \WP_Term && ! is_wp_error( $term ) ) {
					$label = (string) $term->name;
				}
			}
			echo '<span class="mp-cb-picker__pill" data-id="' . esc_attr( (string) $id ) . '" data-label="'
				. esc_attr( $label ) . '">';
			echo '<span class="mp-cb-picker__pill-label">' . esc_html( $label ) . '</span>';
			echo '<button type="button" class="mp-cb-picker__pill-remove" aria-label="Remove">&times;</button>';
			echo '<input type="hidden" name="category_ids[]" value="' . esc_attr( (string) $id ) . '" />';
			echo '</span>';
		}
		echo '</div></div>';
		echo '<details class="mp-cb-advanced-ids"><summary>'
			. esc_html__( 'Browse all categories', 'mp-commerce-promotions' ) . '</summary>';
		$this->render_category_checkboxes( $selected );
		echo '</details></div>';
	}

	/**
	 * @param list<int> $selected
	 */
	private function render_product_picker( array $selected ): void {
		echo '<div class="mp-cb-field">';
		echo '<span class="mp-cb-field__label">' . esc_html__( 'Products', 'mp-commerce-promotions' ) . '</span>';
		echo '<span class="mp-cb-field__help description">'
			. esc_html__( 'Search products by name or SKU.', 'mp-commerce-promotions' ) . '</span>';
		echo '<div class="mp-cb-picker" data-mp-cb-picker="products">';
		echo '<input type="search" class="mp-cb-picker__search widefat" placeholder="'
			. esc_attr__( 'Search products…', 'mp-commerce-promotions' ) . '" autocomplete="off" />';
		echo '<div class="mp-cb-picker__results" role="listbox"></div>';
		echo '<div class="mp-cb-picker__selected">';
		foreach ( $selected as $id ) {
			$label = '#' . $id;
			if ( function_exists( 'wc_get_product' ) ) {
				$product = wc_get_product( $id );
				if ( $product ) {
					$label = $product->get_name();
				}
			}
			echo '<span class="mp-cb-picker__pill" data-id="' . esc_attr( (string) $id ) . '" data-label="'
				. esc_attr( $label ) . '">';
			echo '<span class="mp-cb-picker__pill-label">' . esc_html( $label ) . '</span>';
			echo '<button type="button" class="mp-cb-picker__pill-remove" aria-label="Remove">&times;</button>';
			echo '<input type="hidden" name="product_ids[]" value="' . esc_attr( (string) $id ) . '" />';
			echo '</span>';
		}
		echo '</div></div>';
		echo '<details class="mp-cb-advanced-ids"><summary>'
			. esc_html__( 'Enter product IDs manually', 'mp-commerce-promotions' ) . '</summary>';
		$this->render_text_field(
			'product_ids_csv',
			__( 'Product IDs (comma-separated)', 'mp-commerce-promotions' ),
			implode( ', ', $selected ),
			false,
			array(),
			__( 'Fallback for advanced users.', 'mp-commerce-promotions' )
		);
		echo '</details></div>';
	}

	/**
	 * @param array<string, mixed> $values
	 * @return list<int>
	 */
	private function product_ids_from_values( array $values ): array {
		$ids = array_map( 'intval', (array) ( $values['product_ids'] ?? array() ) );
		$ids = array_values( array_filter( $ids, static fn( int $i ): bool => $i > 0 ) );
		if ( $ids !== array() ) {
			return $ids;
		}

		return $this->comma_ids_to_list( (string) ( $values['product_ids_csv'] ?? '' ) );
	}

	/**
	 * @param array<string, mixed> $insights
	 */
	private function render_confidence_panel( array $insights ): void {
		$confidence = (string) ( $insights['confidence'] ?? CampaignBuilderMerchantInsights::CONFIDENCE_CAUTION );
		echo '<div class="mp-cb-confidence mp-cb-confidence--' . esc_attr( $confidence ) . '">';
		echo '<span class="mp-cb-confidence__badge">' . esc_html( (string) ( $insights['confidence_label'] ?? '' ) ) . '</span>';
		if ( ! empty( $insights['impact'] ) ) {
			echo '<p class="mp-cb-confidence__impact">' . esc_html( (string) $insights['impact'] ) . '</p>';
		}
		if ( ! empty( $insights['badges'] ) && is_array( $insights['badges'] ) ) {
			echo '<div class="mp-cb-confidence__badges">';
			foreach ( $insights['badges'] as $badge ) {
				if ( ! is_array( $badge ) ) {
					continue;
				}
				$level = (string) ( $badge['level'] ?? 'info' );
				echo '<span class="mp-cb-risk-badge mp-cb-risk-badge--' . esc_attr( $level ) . '">'
					. esc_html( (string) ( $badge['text'] ?? '' ) ) . '</span>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	private function render_lifecycle_bar( Promotion $promotion ): void {
		$snap = CampaignBuilderLifecyclePresenter::snapshot( $promotion );
		echo '<div class="mp-cb-lifecycle">';
		echo '<span class="mp-cb-lifecycle__chip mp-cb-lifecycle__chip--' . esc_attr( $snap['phase'] ) . '">'
			. esc_html( $snap['chip'] ) . '</span>';
		echo '<span class="mp-cb-lifecycle__relative">' . esc_html( $snap['relative'] ) . '</span>';
		if ( $snap['percent'] !== null ) {
			echo '<div class="mp-cb-budget-bar" role="progressbar" aria-valuenow="' . esc_attr( (string) $snap['percent'] )
				. '" aria-valuemin="0" aria-valuemax="100">';
			echo '<span class="mp-cb-budget-bar__fill" style="width:' . esc_attr( (string) $snap['percent'] ) . '%"></span>';
			echo '</div>';
		}
		echo '</div>';
	}
}
