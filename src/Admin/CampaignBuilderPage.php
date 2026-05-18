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
use MP\CommercePromotions\Service\CampaignBuilderPreview;
use MP\CommercePromotions\Service\CampaignBuilderSummaryCounts;
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

	private PromotionRepository $promotions;

	private PromotionService $promotion_service;

	private CampaignBuilderDraftCreator $draft_creator;

	private CampaignBuilderPreview $preview;

	private CampaignBuilderSummaryCounts $summary;

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
			}
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$this->handle_post_duplicate();
		$this->handle_post_create();

		$goal = CampaignBuilderGoal::sanitize(
			isset( $_GET['campaign_goal'] ) ? wp_unslash( (string) $_GET['campaign_goal'] ) : null
		);

		$success_id = isset( $_GET['mp_cb_created'] ) ? (int) $_GET['mp_cb_created'] : 0;

		echo '<div class="wrap mp-cb-wrap">';
		$this->render_page_header();
		AdminNavigation::render_tabs( AdminNavigation::TAB_CAMPAIGN_BUILDER );

		if ( $success_id > 0 ) {
			$this->render_success_screen( $success_id );
		}

		$error_raw = isset( $_GET['mp_cb_error'] ) ? wp_unslash( (string) $_GET['mp_cb_error'] ) : '';
		if ( $error_raw !== '' ) {
			AdminNotice::error( $this->resolve_error_notice( sanitize_key( $error_raw ) ) );
		}

		$this->render_summary_cards();

		echo '<section class="mp-cb-section mp-cb-section--goals">';
		echo '<h2 class="mp-cb-section__title">' . esc_html__( 'Choose campaign type', 'mp-commerce-promotions' ) . '</h2>';
		$this->render_goal_cards( $goal );
		echo '</section>';

		if ( $goal !== null ) {
			$ui_state = $this->merged_ui_state( $goal );
			$parsed   = $this->draft_payload_from_ui_state( $goal, $ui_state );
			echo '<section class="mp-cb-section mp-cb-section--builder">';
			echo '<h2 class="mp-cb-section__title">' . esc_html__( 'Configure campaign', 'mp-commerce-promotions' ) . '</h2>';
			echo '<div class="mp-cb-layout">';
			echo '<div class="mp-cb-layout__primary">';
			$this->render_campaign_form( $goal, $ui_state );
			echo '</div>';
			echo '<aside class="mp-cb-layout__aside" aria-label="' . esc_attr__( 'Campaign preview', 'mp-commerce-promotions' ) . '">';
			$this->render_preview_sidebar( $goal, $parsed, $ui_state );
			echo '</aside>';
			echo '</div>';
			echo '</section>';
		} else {
			echo '<section class="mp-cb-section mp-cb-section--preview-empty">';
			echo '<h2 class="mp-cb-section__title">' . esc_html__( 'Campaign preview', 'mp-commerce-promotions' ) . '</h2>';
			$this->render_preview_empty_state();
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
		echo '<div class="mp-cb-header__titles">';
		echo '<h1 class="mp-cb-header__title">' . esc_html__( 'Campaign Builder', 'mp-commerce-promotions' ) . '</h1>';
		echo '<p class="mp-cb-header__subtitle">'
			. esc_html__( 'Create powerful promotions in a few simple steps.', 'mp-commerce-promotions' )
			. '</p>';
		echo '</div>';
		echo '<span class="mp-cb-badge">' . esc_html__( 'Merchant-friendly', 'mp-commerce-promotions' ) . '</span>';
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
		$base = $this->default_form_values( $goal );
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
		if ( null === $this->promotions->find( $id ) ) {
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
			. '</strong> ';
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
			$url    = add_query_arg( array( 'campaign_goal' => $key ), AdminUrl::tab( AdminNavigation::TAB_CAMPAIGN_BUILDER ) );
			$active = $active_goal === $key ? ' is-selected' : '';
			echo '<article class="mp-cb-goal-card' . esc_attr( $active ) . '">';
			echo '<span class="mp-cb-goal-card__icon dashicons dashicons-' . esc_attr( $def['icon'] ) . '" aria-hidden="true"></span>';
			echo '<h3 class="mp-cb-goal-card__title">' . esc_html( $def['title'] ) . '</h3>';
			echo '<p class="mp-cb-goal-card__desc">' . esc_html( $def['description'] ) . '</p>';
			echo '<p class="mp-cb-goal-card__best-for"><span>' . esc_html__( 'Best for', 'mp-commerce-promotions' ) . '</span> '
				. esc_html( $def['best_for'] ) . '</p>';
			printf(
				'<a class="button button-secondary mp-cb-goal-card__cta" href="%s">%s</a>',
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

		echo '<form class="mp-cb-form mp-cb-panel" method="post" action="' . esc_url( $post_url ) . '">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<input type="hidden" name="campaign_goal" value="' . esc_attr( $goal ) . '" />';

		echo '<div class="mp-cb-form-group">';
		echo '<h3 class="mp-cb-form-group__title">' . esc_html__( 'Campaign details', 'mp-commerce-promotions' ) . '</h3>';
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
		echo '</div>';

		echo '<div class="mp-cb-form-group">';
		echo '<h3 class="mp-cb-form-group__title">' . esc_html__( 'Schedule & limits', 'mp-commerce-promotions' ) . '</h3>';
		echo '<div class="mp-cb-fields-row">';
		$this->render_datetime_local(
			'starts_at',
			__( 'Starts', 'mp-commerce-promotions' ),
			(string) $values['starts_at']
		);
		$this->render_datetime_local(
			'ends_at',
			__( 'Ends', 'mp-commerce-promotions' ),
			(string) $values['ends_at']
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
		echo '<fieldset class="mp-cb-fieldset"><legend>' . esc_html__( 'Stacking', 'mp-commerce-promotions' ) . '</legend>';
		$this->radio_yes_no( 'stackable', (bool) $values['stackable'], __( 'Allow stacking with compatible promotions?', 'mp-commerce-promotions' ) );
		echo '</fieldset>';
		echo '<fieldset class="mp-cb-fieldset"><legend>'
			. esc_html__( 'Coupon requirement', 'mp-commerce-promotions' ) . '</legend>';
		if ( $goal === CampaignBuilderGoal::COUPON_CODE ) {
			echo '<p class="description">'
				. esc_html__( 'This campaign type requires a coupon code.', 'mp-commerce-promotions' ) . '</p>';
			echo '<input type="hidden" name="require_coupon_code" value="1" />';
		} else {
			$this->radio_yes_no(
				'require_coupon_code',
				(bool) $values['require_coupon_code'],
				__( 'Require shoppers to enter a promotion code?', 'mp-commerce-promotions' )
			);
		}
		echo '</fieldset>';
		echo '</div>';

		echo '<div class="mp-cb-form-group">';
		echo '<h3 class="mp-cb-form-group__title">' . esc_html__( 'Discount & offer', 'mp-commerce-promotions' ) . '</h3>';
		$this->render_goal_specific_fields( $goal, $values );
		echo '</div>';

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
				echo '<p class="description">'
					. esc_html__( 'Set Budget amount above — it caps total discount spend.', 'mp-commerce-promotions' )
					. '</p>';
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
				false
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
					false
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

		$this->render_coupon_optional_block( $values, $goal );
	}

	private function render_bogo_scope( string $selected ): void {
		echo '<p><label for="mp_cb_bogo_scope">' . esc_html__( 'Offer scope', 'mp-commerce-promotions' ) . '</label></p>';
		echo '<select id="mp_cb_bogo_scope" name="bogo_scope">';
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
	}

	/**
	 * @param array<string, mixed> $values
	 */
	private function render_coupon_optional_block( array $values, string $goal ): void {
		$needs = ! empty( $values['require_coupon_code'] ) || $goal === CampaignBuilderGoal::COUPON_CODE;
		if ( ! $needs ) {
			return;
		}

		echo '<hr />';
		$this->render_text_field(
			'coupon_code',
			__( 'Promotion code text', 'mp-commerce-promotions' ),
			(string) $values['coupon_code'],
			false
		);
		printf(
			'<p><label><input type="checkbox" name="generate_coupon_code" value="1" %s /> %s</label></p>',
			checked( ! empty( $values['generate_coupon_code'] ), true, false ),
			esc_html__( 'Auto-generate a unique code when empty', 'mp-commerce-promotions' )
		);
		$this->render_text_field(
			'code_usage_limit',
			__( 'Per-code redemption limit', 'mp-commerce-promotions' ),
			(string) $values['code_usage_limit'],
			false,
			array( 'type' => 'number', 'min' => '0', 'step' => '1' )
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
			echo '<p><span class="description">' . esc_html__( 'Discount type', 'mp-commerce-promotions' ) . '</span></p>';
			printf(
				'<label><input type="radio" name="discount_type" value="percentage" %s /> %s</label> ',
				checked( $discount_type !== 'fixed', true, false ),
				esc_html__( 'Percentage', 'mp-commerce-promotions' )
			);
			printf(
				'<label><input type="radio" name="discount_type" value="fixed" %s /> %s</label>',
				checked( $discount_type, 'fixed', false ),
				esc_html__( 'Fixed amount', 'mp-commerce-promotions' )
			);
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
			array( 'type' => 'number', 'step' => '0.01', 'min' => '0', 'max' => '100' )
		);
		$this->render_text_field(
			'amount',
			__( 'Fixed amount discount', 'mp-commerce-promotions' ),
			(string) $values['amount'],
			false,
			array( 'type' => 'number', 'step' => '0.01', 'min' => '0' )
		);
	}

	/**
	 * @param array<int|string> $selected
	 */
	private function render_role_checkboxes( array $selected ): void {
		$selected = array_map( 'sanitize_key', array_map( 'strval', $selected ) );

		global $wp_roles;

		echo '<fieldset class="mp-cb-role-boxes"><legend>' . esc_html__( 'Eligible roles', 'mp-commerce-promotions' ) . '</legend>';
		if ( ! $wp_roles instanceof WP_Roles ) {
			echo '<p>' . esc_html__( 'Roles are unavailable.', 'mp-commerce-promotions' ) . '</p>';
			echo '</fieldset>';

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
		echo '</fieldset>';
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

		echo '<fieldset class="mp-cb-cat-boxes"><legend>'
			. esc_html__( 'Categories', 'mp-commerce-promotions' ) . '</legend>';
		if ( $list === array() ) {
			echo '<p>' . esc_html__( 'No product categories found.', 'mp-commerce-promotions' ) . '</p>';
			echo '</fieldset>';

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

		echo '</fieldset>';
	}

	private function radio_yes_no( string $field, bool $yes, string $help ): void {
		echo '<p class="description">' . esc_html( $help ) . '</p>';
		printf(
			'<label><input type="radio" name="%1$s" value="1" %3$s /> %2$s</label> ',
			esc_attr( $field ),
			esc_html__( 'Yes', 'mp-commerce-promotions' ),
			checked( $yes, true, false )
		);
		printf(
			'<label><input type="radio" name="%1$s" value="0" %3$s /> %2$s</label>',
			esc_attr( $field ),
			esc_html__( 'No', 'mp-commerce-promotions' ),
			checked( $yes, false, false )
		);
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
		if ( $help !== null && $help !== '' ) {
			echo '<span class="mp-cb-field__help description">' . esc_html( $help ) . '</span>';
		}
		printf(
			'<input class="widefat mp-cb-field__input" type="%1$s" id="%2$s" name="%3$s" value="%4$s"%5$s%6$s />',
			esc_attr( $type ),
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( $value ),
			$required ? ' required' : '',
			$key_attrs
		);
		echo '</p>';
	}

	private function render_datetime_local( string $name, string $label, string $value ): void {
		$id = 'mp_cb_' . $name;

		echo '<p><label for="' . esc_attr( $id ) . '"><strong>' . esc_html( $label )
			. '</strong></label>';
		echo '<input class="widefat" type="datetime-local" id="' . esc_attr( $id )
			. '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" /></p>';
	}

	/**
	 * @param array<string, mixed> $form
	 * @param array<string, mixed> $ui_state
	 */
	private function render_preview_sidebar( string $goal, array $form, array $ui_state = array() ): void {
		$blocks = $this->preview->summarize_form( $goal, $form );

		echo '<div class="mp-cb-panel mp-cb-preview">';
		echo '<h3 class="mp-cb-preview__heading">' . esc_html__( 'Campaign preview', 'mp-commerce-promotions' ) . '</h3>';
		echo '<dl class="mp-cb-preview__list">';
		$this->render_preview_row( __( 'Applies when', 'mp-commerce-promotions' ), (string) ( $blocks['applies_when'] ?? '' ) );
		$this->render_preview_row( __( 'Customer gets', 'mp-commerce-promotions' ), (string) ( $blocks['customer_receives'] ?? '' ) );
		$this->render_preview_row( __( 'Dates', 'mp-commerce-promotions' ), $this->format_preview_dates( $ui_state ) );
		$this->render_preview_row( __( 'Budget / usage', 'mp-commerce-promotions' ), (string) ( $blocks['limits'] ?? '' ) );
		$this->render_preview_row( __( 'Stacking', 'mp-commerce-promotions' ), (string) ( $blocks['stacking'] ?? '' ) );
		$this->render_preview_row( __( 'Coupon', 'mp-commerce-promotions' ), (string) ( $blocks['coupon'] ?? '' ) );
		echo '</dl>';

		if ( ! empty( $blocks['warnings'] ) ) {
			echo '<div class="mp-cb-smart-advice">';
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
			echo '<div class="mp-cb-next-steps">';
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

	private function render_preview_row( string $label, string $value ): void {
		echo '<dt>' . esc_html( $label ) . '</dt>';
		echo '<dd>' . esc_html( $value !== '' ? $value : '—' ) . '</dd>';
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
		echo '<div class="mp-cb-sidebar-escape">';
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

		echo '<table class="widefat striped mp-cb-table"><thead><tr>';
		foreach (
			array(
				'name'       => __( 'Name', 'mp-commerce-promotions' ),
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
			echo '<td>' . esc_html( $this->goal_label_for_promotion( $promotion ) ) . '</td>';
			echo '<td>' . $this->render_status_badge( $promotion->get_status() ) . '</td>';
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
			echo '<tr><td colspan="9">';
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
		$out['product_ids']                 = $this->comma_ids_to_list( $out['product_ids_csv'] );
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
}
