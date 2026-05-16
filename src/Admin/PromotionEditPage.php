<?php
/**
 * Single promotion admin detail and JSON rule editing.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\AuditLogEntry;
use MP\CommercePromotions\Domain\AuditLogRepository;
use InvalidArgumentException;
use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionCode;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\EvaluationResult;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Woo\CartContextBuilder;
use RuntimeException;
use Throwable;

final class PromotionEditPage {

	private PromotionRepository $promotions;

	private PromotionService $promotion_service;

	private ?CartContextBuilder $cart_context_builder;

	private PromotionEvaluator $promotion_evaluator;

	private ?EvaluationResult $cart_preview_result = null;

	private ?string $cart_preview_error = null;

	private ?RedemptionRepository $redemptions;

	private ?AuditLogRepository $audit_logs;

	private PromotionRuleValidator $rule_validator;

	private ?PromotionCodeRepository $promotion_codes;

	private ?PromotionCodeFactory $promotion_code_factory;

	private const ADMIN_USAGE_AUDIT_LIMIT = 25;

	public function __construct(
		PromotionRepository $promotions,
		PromotionService $promotion_service,
		?CartContextBuilder $cart_context_builder = null,
		?PromotionEvaluator $promotion_evaluator = null,
		?RedemptionRepository $redemptions = null,
		?AuditLogRepository $audit_logs = null,
		?PromotionRuleValidator $rule_validator = null,
		?PromotionCodeRepository $promotion_codes = null,
		?PromotionCodeFactory $promotion_code_factory = null
	) {
		$this->promotions               = $promotions;
		$this->promotion_service        = $promotion_service;
		$this->cart_context_builder     = $cart_context_builder;
		$this->promotion_evaluator      = $promotion_evaluator ?? new PromotionEvaluator();
		$this->redemptions              = $redemptions;
		$this->audit_logs               = $audit_logs;
		$this->rule_validator           = $rule_validator ?? new PromotionRuleValidator();
		$this->promotion_codes          = $promotion_codes;
		$this->promotion_code_factory   = $promotion_code_factory;
	}

	public function render( string $identifier ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		$promotion = $this->promotions->find_by_id_or_uuid( $identifier );
		if ( $promotion === null ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'promotion_not_found', $this->list_url() ) );
			exit;
		}

		$this->cart_preview_result = null;
		$this->cart_preview_error  = null;

		$this->handle_post_change_status( $promotion );
		$this->handle_post_update( $promotion );
		$this->handle_post_create_promotion_code( $promotion );
		$this->handle_post_preview( $promotion );

		$promotion = $this->promotions->find_by_id_or_uuid( $identifier );
		if ( $promotion === null ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'promotion_not_found', $this->list_url() ) );
			exit;
		}

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Edit promotion', 'mp-commerce-promotions' ) . '</h1>';
		echo '<p>';
		echo '<a href="' . esc_url( $this->list_url() ) . '">' . esc_html__( '← Back to promotions', 'mp-commerce-promotions' ) . '</a>';
		echo '</p>';

		$this->render_status_section( $promotion );
		$this->render_rule_validation_section( $promotion );
		$this->render_cart_preview_section( $promotion );
		$this->render_form( $promotion );
		$this->render_promotion_codes_section( $promotion );
		$this->render_usage_redemptions_section( $promotion );
		$this->render_audit_log_section( $promotion );
		echo '</div>';
	}

	private function handle_post_create_promotion_code( Promotion $promotion ): void {
		if ( $this->promotion_codes === null || $this->promotion_code_factory === null ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_create_promotion_code_submit'] ) ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_create_promotion_code_' . $pid;
		if ( ! isset( $_POST['mp_cp_create_promotion_code_nonce'] ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'missing_nonce' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_create_promotion_code_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'invalid_nonce' ) );
		}

		$plain = isset( $_POST['mp_cp_promotion_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_promotion_code'] ) )
			: '';
		if ( $plain === '' ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'empty_code' ) );
		}

		$usage_limit = null;
		if ( isset( $_POST['mp_cp_code_usage_limit'] ) && $_POST['mp_cp_code_usage_limit'] !== '' ) {
			$usage_limit = (int) $_POST['mp_cp_code_usage_limit'];
			if ( $usage_limit < 0 ) {
				$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'invalid_usage_limit' ) );
			}
		}

		$expires_at = isset( $_POST['mp_cp_code_expires_at'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_code_expires_at'] ) )
			: '';
		if ( $expires_at === '' ) {
			$expires_at = null;
		}

		if ( $this->promotion_codes->find_by_plain_code( $plain ) !== null ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'duplicate_code' ) );
		}

		try {
			$code = $this->promotion_code_factory->create_manual_code( $pid, $plain, $usage_limit, $expires_at );
		} catch ( InvalidArgumentException $e ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'invalid_code' ) );
		}

		$new_id = $this->promotion_codes->insert( $code );
		if ( $new_id <= 0 ) {
			if ( $this->promotion_codes->find_by_plain_code( $plain ) !== null ) {
				$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'duplicate_code' ) );
			}
			$this->redirect_to_edit( $pid, array( 'mp_cp_code_error' => 'insert_failed' ) );
		}

		$this->redirect_to_edit( $pid, array( 'mp_cp_code_created' => '1' ) );
	}

	private function handle_post_preview( Promotion $promotion ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_preview_cart_submit'] ) ) {
			return;
		}

		$action = isset( $_POST['mp_cp_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_action'] ) ) : '';
		if ( $action !== 'preview_cart' ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		if ( $this->cart_context_builder === null ) {
			$this->cart_preview_error = __( 'Cart preview is not available.', 'mp-commerce-promotions' );
			return;
		}

		if ( ! isset( $_POST['mp_cp_preview_cart_nonce'] ) ) {
			$this->cart_preview_error = __( 'Security check failed (preview).', 'mp-commerce-promotions' );
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_preview_cart_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'mp_cp_preview_cart_' . $pid ) ) {
			$this->cart_preview_error = __( 'Security check failed (preview).', 'mp-commerce-promotions' );
			return;
		}

		$post_id = isset( $_POST['promotion_id'] ) ? (int) $_POST['promotion_id'] : 0;
		if ( $post_id !== $pid ) {
			$this->cart_preview_error = __( 'Invalid preview form submission.', 'mp-commerce-promotions' );
			return;
		}

		try {
			$context = $this->cart_context_builder->build_from_cart();
			$this->cart_preview_result = $this->promotion_evaluator->evaluate( $promotion, $context );
		} catch ( Throwable $e ) {
			$this->cart_preview_error = __( 'Preview failed.', 'mp-commerce-promotions' );
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$this->cart_preview_error .= ' ' . $e->getMessage();
			}
		}
	}

	private function handle_post_change_status( Promotion $promotion ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$action = isset( $_POST['mp_cp_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_action'] ) ) : '';
		if ( $action !== 'change_status' ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_change_status_' . $pid;
		if ( ! isset( $_POST['mp_cp_change_status_nonce'] ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_status_error' => 'missing_nonce' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_change_status_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_status_error' => 'invalid_nonce' ) );
		}

		$post_promo_id = isset( $_POST['promotion_id'] ) ? (int) $_POST['promotion_id'] : 0;
		if ( $post_promo_id !== $pid ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_status_error' => 'id_mismatch' ) );
		}

		$new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_status'] ) ) : '';
		if ( $new_status === '' ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_status_error' => 'invalid_status' ) );
		}

		try {
			$this->promotion_service->change_status( $promotion, $new_status, (int) get_current_user_id() );
		} catch ( RuntimeException $e ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_status_error' => 'status_change_failed' ) );
		}

		$this->redirect_to_edit( $pid, array( 'mp_cp_status_saved' => '1' ) );
	}

	private function handle_post_update( Promotion $promotion ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_update_promotion_submit'] ) ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_update_promotion_' . $pid;
		if ( ! isset( $_POST['mp_cp_update_nonce'] ) ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'missing_nonce' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_update_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'invalid_nonce' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		$post_id = isset( $_POST['mp_cp_promotion_id'] ) ? (int) $_POST['mp_cp_promotion_id'] : 0;
		if ( $post_id !== $pid ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'id_mismatch' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		$name = isset( $_POST['promotion_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['promotion_name'] ) ) : '';
		if ( $name === '' ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'empty_name' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		$description = isset( $_POST['promotion_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['promotion_description'] ) ) : '';
		if ( $description === '' ) {
			$description = null;
		}

		$priority = isset( $_POST['promotion_priority'] ) ? (int) $_POST['promotion_priority'] : 0;
		if ( $priority < 0 ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'invalid_priority' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		$starts_raw = isset( $_POST['promotion_starts_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['promotion_starts_at'] ) ) : '';
		$ends_raw   = isset( $_POST['promotion_ends_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['promotion_ends_at'] ) ) : '';
		$starts_at  = $starts_raw === '' ? null : $starts_raw;
		$ends_at    = $ends_raw === '' ? null : $ends_raw;

		$conditions_raw    = isset( $_POST['promotion_conditions_json'] ) ? wp_unslash( (string) $_POST['promotion_conditions_json'] ) : '';
		$actions_raw       = isset( $_POST['promotion_actions_json'] ) ? wp_unslash( (string) $_POST['promotion_actions_json'] ) : '';
		$restrictions_raw  = isset( $_POST['promotion_restrictions_json'] ) ? wp_unslash( (string) $_POST['promotion_restrictions_json'] ) : '';

		$conditions = $this->decode_json_array_field( $conditions_raw );
		$actions      = $this->decode_json_array_field( $actions_raw );
		$restrictions = $this->decode_json_array_field( $restrictions_raw );

		if ( $conditions === null || $actions === null || $restrictions === null ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'invalid_json' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		try {
			$updated = $promotion
				->with_name( $name )
				->with_description( $description )
				->with_status( $promotion->get_status() )
				->with_priority( $priority )
				->with_date_window( $starts_at, $ends_at )
				->with_rules( $conditions, $actions, $restrictions );

			$this->promotion_service->update_promotion( $updated, (int) get_current_user_id() );
		} catch ( RuntimeException $e ) {
			wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_error' => 'update_failed' ), $this->edit_url( (string) $pid ) ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( array( 'promotion' => (string) $pid, 'mp_cp_saved' => '1' ), $this->edit_url( (string) $pid ) ) );
		exit;
	}

	/**
	 * @return array<mixed>|null Null when invalid JSON or not an array (empty input → empty array).
	 */
	private function decode_json_array_field( string $raw ): ?array {
		$trimmed = trim( $raw );
		if ( $trimmed === '' ) {
			return array();
		}

		$decoded = json_decode( $trimmed, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return null;
		}
		if ( ! is_array( $decoded ) ) {
			return null;
		}

		return $decoded;
	}

	private function render_notices(): void {
		if ( isset( $_GET['mp_cp_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_saved'] ) ) === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Promotion saved.', 'mp-commerce-promotions' ) . '</p></div>';
		}

		if ( isset( $_GET['mp_cp_status_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_status_saved'] ) ) === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Promotion status updated.', 'mp-commerce-promotions' ) . '</p></div>';
		}

		if ( isset( $_GET['mp_cp_status_error'] ) ) {
			$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_status_error'] ) );
			$msg  = $this->status_error_message_for_code( $code );
			if ( $msg !== '' ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}

		if ( isset( $_GET['mp_cp_error'] ) ) {
			$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_error'] ) );
			$msg  = $this->error_message_for_code( $code );
			if ( $msg !== '' ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}

		if ( isset( $_GET['mp_cp_code_created'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_code_created'] ) ) === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Promotion code created.', 'mp-commerce-promotions' ) . '</p></div>';
		}

		if ( isset( $_GET['mp_cp_code_error'] ) ) {
			$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_code_error'] ) );
			$msg  = $this->code_error_message_for_code( $code );
			if ( $msg !== '' ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}
	}

	private function code_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return __( 'Security check failed while creating a promotion code. Please try again.', 'mp-commerce-promotions' );
			case 'empty_code':
				return __( 'Please enter a promotion code.', 'mp-commerce-promotions' );
			case 'invalid_code':
				return __( 'Promotion code must be at least 4 characters and use only A-Z, 0-9, and hyphens.', 'mp-commerce-promotions' );
			case 'invalid_usage_limit':
				return __( 'Usage limit must be zero or greater.', 'mp-commerce-promotions' );
			case 'duplicate_code':
				return __( 'That promotion code already exists.', 'mp-commerce-promotions' );
			case 'insert_failed':
				return __( 'Could not create the promotion code. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			case 'id_mismatch':
				return __( 'Invalid form submission.', 'mp-commerce-promotions' );
			case 'empty_name':
				return __( 'Please enter a promotion name.', 'mp-commerce-promotions' );
			case 'invalid_priority':
				return __( 'Priority must be zero or greater.', 'mp-commerce-promotions' );
			case 'invalid_json':
				return __( 'Conditions, actions, and restrictions must be valid JSON arrays.', 'mp-commerce-promotions' );
			case 'update_failed':
				return __( 'Could not save the promotion. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function status_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return __( 'Security check failed while changing status. Please try again.', 'mp-commerce-promotions' );
			case 'id_mismatch':
				return __( 'Invalid status form submission.', 'mp-commerce-promotions' );
			case 'invalid_status':
				return __( 'Invalid target status.', 'mp-commerce-promotions' );
			case 'status_change_failed':
				return __( 'That status change is not allowed or could not be saved.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function render_status_section( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		$status = $promotion->get_status();
		echo '<div class="card" style="max-width:720px;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Current:', 'mp-commerce-promotions' ) . '</strong> ' . esc_html( $status ) . '</p>';

		if ( $status === PromotionStatus::ARCHIVED ) {
			echo '<p class="description">' . esc_html__( 'Archived promotions cannot be reactivated.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'Change status using the actions below. Status cannot be edited from the main form.', 'mp-commerce-promotions' ) . '</p>';

		$url = $this->edit_url( (string) $id );

		if ( $status === PromotionStatus::DRAFT ) {
			$this->render_status_action_form( $url, $id, PromotionStatus::ACTIVE, __( 'Activate', 'mp-commerce-promotions' ) );
			$this->render_status_action_form( $url, $id, PromotionStatus::ARCHIVED, __( 'Archive', 'mp-commerce-promotions' ) );
		} elseif ( $status === PromotionStatus::ACTIVE ) {
			$this->render_status_action_form( $url, $id, PromotionStatus::PAUSED, __( 'Pause', 'mp-commerce-promotions' ) );
			$this->render_status_action_form( $url, $id, PromotionStatus::ARCHIVED, __( 'Archive', 'mp-commerce-promotions' ) );
		} elseif ( $status === PromotionStatus::PAUSED ) {
			$this->render_status_action_form( $url, $id, PromotionStatus::ACTIVE, __( 'Activate', 'mp-commerce-promotions' ) );
			$this->render_status_action_form( $url, $id, PromotionStatus::ARCHIVED, __( 'Archive', 'mp-commerce-promotions' ) );
		}

		echo '</div>';
	}

	private function render_status_action_form( string $action_url, int $promotion_id, string $new_status, string $label ): void {
		$nonce_action = 'mp_cp_change_status_' . $promotion_id;
		echo '<form method="post" action="' . esc_url( $action_url ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
		wp_nonce_field( $nonce_action, 'mp_cp_change_status_nonce' );
		echo '<input type="hidden" name="mp_cp_action" value="change_status" />';
		echo '<input type="hidden" name="promotion_id" value="' . esc_attr( (string) $promotion_id ) . '" />';
		echo '<input type="hidden" name="new_status" value="' . esc_attr( $new_status ) . '" />';
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button>';
		echo '</form>';
	}

	private function render_rule_validation_section( Promotion $promotion ): void {
		$issues = $this->rule_validator->validate( $promotion );

		echo '<div class="card" style="max-width:720px;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Rule Validation', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Read-only checks against supported condition and action types. Passing validation does not guarantee the promotion will apply to a specific cart.', 'mp-commerce-promotions' ) . '</p>';

		if ( count( $issues ) === 0 ) {
			echo '<p>' . esc_html__( 'No validation issues found.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $issues as $issue ) {
			$level   = isset( $issue['level'] ) ? (string) $issue['level'] : 'info';
			$message = isset( $issue['message'] ) ? (string) $issue['message'] : '';

			$label = match ( $level ) {
				'error' => __( 'Error', 'mp-commerce-promotions' ),
				'warning' => __( 'Warning', 'mp-commerce-promotions' ),
				default => __( 'Info', 'mp-commerce-promotions' ),
			};

			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $message ) . '</li>';
		}
		echo '</ul>';
		echo '</div>';
	}

	private function render_cart_preview_section( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		echo '<div class="card" style="max-width:720px;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Cart preview', 'mp-commerce-promotions' ) . '</h2>';

		if ( $this->cart_context_builder === null ) {
			echo '<p class="description">' . esc_html__( 'WooCommerce is unavailable or the cart context builder is not loaded, so preview against the current cart is disabled.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		$url = $this->edit_url( (string) $id );
		echo '<form method="post" action="' . esc_url( $url ) . '">';
		wp_nonce_field( 'mp_cp_preview_cart_' . $id, 'mp_cp_preview_cart_nonce' );
		echo '<input type="hidden" name="mp_cp_action" value="preview_cart" />';
		echo '<input type="hidden" name="promotion_id" value="' . esc_attr( (string) $id ) . '" />';
		echo '<p class="submit" style="margin:0;">';
		echo '<button type="submit" name="mp_cp_preview_cart_submit" value="1" class="button">' . esc_html__( 'Preview against current cart', 'mp-commerce-promotions' ) . '</button>';
		echo '</p>';
		echo '<p class="description">' . esc_html__( 'Evaluates this promotion against the cart for the current session. Nothing is saved to the database; discounts are not applied.', 'mp-commerce-promotions' ) . '</p>';
		echo '</form>';

		if ( $this->cart_preview_error !== null && $this->cart_preview_error !== '' ) {
			echo '<div class="notice notice-error inline" style="margin-top:12px;"><p>' . esc_html( $this->cart_preview_error ) . '</p></div>';
		}

		if ( $this->cart_preview_result instanceof EvaluationResult ) {
			$result = $this->cart_preview_result;
			echo '<hr style="margin:16px 0;" />';
			echo '<p><strong>' . esc_html__( 'Eligible', 'mp-commerce-promotions' ) . ':</strong> ';
			echo $result->is_eligible()
				? esc_html__( 'Yes', 'mp-commerce-promotions' )
				: esc_html__( 'No', 'mp-commerce-promotions' );
			echo '</p>';

			$messages = $result->get_messages();
			if ( count( $messages ) > 0 ) {
				echo '<p><strong>' . esc_html__( 'Messages', 'mp-commerce-promotions' ) . '</strong></p>';
				echo '<ul style="list-style:disc;margin-left:1.5em;">';
				foreach ( $messages as $message ) {
					echo '<li>' . esc_html( (string) $message ) . '</li>';
				}
				echo '</ul>';
			}

			$action_results = $result->get_action_results();
			echo '<p><strong>' . esc_html__( 'Action previews (JSON)', 'mp-commerce-promotions' ) . '</strong></p>';
			$json = wp_json_encode( $action_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $json ) ) {
				$json = '[]';
			}
			echo '<pre class="code" style="max-height:240px;overflow:auto;background:#f6f7f7;padding:12px;">' . esc_html( $json ) . '</pre>';
		}

		echo '</div>';
	}

	private function render_form( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_update_promotion_' . $id;
		$form_action  = $this->edit_url( (string) $id );

		echo '<form method="post" action="' . esc_url( $form_action ) . '">';
		wp_nonce_field( $nonce_action, 'mp_cp_update_nonce' );
		echo '<input type="hidden" name="mp_cp_promotion_id" value="' . esc_attr( (string) $id ) . '" />';

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="mp_cp_name">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_name" name="promotion_name" maxlength="191" required value="' . esc_attr( $promotion->get_name() ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_desc">' . esc_html__( 'Description', 'mp-commerce-promotions' ) . '</label></th><td>';
		$desc = $promotion->get_description() ?? '';
		echo '<textarea class="large-text" rows="3" id="mp_cp_desc" name="promotion_description">' . esc_textarea( $desc ) . '</textarea></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th><td>';
		echo '<p class="description" style="margin:0;">' . esc_html( $promotion->get_status() ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Use the status actions above to change draft, active, paused, or archived.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_priority">' . esc_html__( 'Priority', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_priority" name="promotion_priority" min="0" step="1" value="' . esc_attr( (string) $promotion->get_priority() ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_starts">' . esc_html__( 'Starts at', 'mp-commerce-promotions' ) . '</label></th><td>';
		$starts = $promotion->get_starts_at() ?? '';
		echo '<input type="text" class="regular-text" id="mp_cp_starts" name="promotion_starts_at" value="' . esc_attr( $starts ) . '" placeholder="' . esc_attr__( 'YYYY-MM-DD HH:MM:SS or leave empty', 'mp-commerce-promotions' ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_ends">' . esc_html__( 'Ends at', 'mp-commerce-promotions' ) . '</label></th><td>';
		$ends = $promotion->get_ends_at() ?? '';
		echo '<input type="text" class="regular-text" id="mp_cp_ends" name="promotion_ends_at" value="' . esc_attr( $ends ) . '" placeholder="' . esc_attr__( 'YYYY-MM-DD HH:MM:SS or leave empty', 'mp-commerce-promotions' ) . '" /></td></tr>';

		$cond_json = wp_json_encode( $promotion->get_conditions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $cond_json ) ) {
			$cond_json = '[]';
		}
		echo '<tr><th scope="row"><label for="mp_cp_cond">' . esc_html__( 'Conditions (JSON)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="8" id="mp_cp_cond" name="promotion_conditions_json">' . esc_textarea( $cond_json ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Supported condition types (all must pass):', 'mp-commerce-promotions' ) . '</p>';
		echo '<pre class="code" style="max-height:200px;overflow:auto;background:#f6f7f7;padding:8px;">'
			. esc_html(
				"[\n  {\"type\":\"minimum_subtotal\",\"amount\":1}\n]\n\n[\n  {\"type\":\"product_quantity\",\"product_id\":3702,\"operator\":\">=\",\"quantity\":2}\n]\n\n[\n  {\"type\":\"category_quantity\",\"category_id\":123,\"operator\":\">=\",\"quantity\":2}\n]"
			)
			. '</pre></td></tr>';

		$act_json = wp_json_encode( $promotion->get_actions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $act_json ) ) {
			$act_json = '[]';
		}
		echo '<tr><th scope="row"><label for="mp_cp_act">' . esc_html__( 'Actions (JSON)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="8" id="mp_cp_act" name="promotion_actions_json">' . esc_textarea( $act_json ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Supported action types (first eligible action on the first eligible promotion applies):', 'mp-commerce-promotions' ) . '</p>';
		echo '<pre class="code" style="max-height:160px;overflow:auto;background:#f6f7f7;padding:8px;">'
			. esc_html(
				"[\n  {\"type\":\"percentage_discount\",\"percentage\":10}\n]\n\n[\n  {\"type\":\"fixed_amount_discount\",\"amount\":25}\n]"
			)
			. '</pre></td></tr>';

		$res_json = wp_json_encode( $promotion->get_restrictions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $res_json ) ) {
			$res_json = '[]';
		}
		echo '<tr><th scope="row"><label for="mp_cp_res">' . esc_html__( 'Restrictions (JSON)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="8" id="mp_cp_res" name="promotion_restrictions_json">' . esc_textarea( $res_json ) . '</textarea></td></tr>';

		echo '</tbody></table>';

		echo '<p class="submit"><button type="submit" name="mp_cp_update_promotion_submit" value="1" class="button button-primary">' . esc_html__( 'Save promotion', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function render_promotion_codes_section( Promotion $promotion ): void {
		if ( $this->promotion_codes === null || $this->promotion_code_factory === null ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$codes = $this->promotion_codes->find_for_promotion( $pid, 50 );

		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Promotion Codes', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Manual codes are stored hashed; only the last four characters are shown after creation. Storefront redemption is not implemented yet.', 'mp-commerce-promotions' ) . '</p>';

		if ( count( $codes ) === 0 ) {
			echo '<p>' . esc_html__( 'No promotion codes for this promotion yet.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Last 4', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Usage', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Created', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $codes as $code ) {
				if ( ! $code instanceof PromotionCode ) {
					continue;
				}
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $code->get_id() ?? '' ) ) . '</td>';
				echo '<td>****' . esc_html( $code->get_code_last4() ) . '</td>';
				echo '<td>' . esc_html( $code->get_status() ) . '</td>';
				echo '<td>' . esc_html( $this->format_code_usage( $code ) ) . '</td>';
				echo '<td>' . esc_html( $code->get_expires_at() ?? '—' ) . '</td>';
				echo '<td>' . esc_html( $code->get_created_at() ?? '—' ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		}

		$nonce_action = 'mp_cp_create_promotion_code_' . $pid;
		$form_action  = $this->edit_url( (string) $pid );

		echo '<h3>' . esc_html__( 'Add manual code', 'mp-commerce-promotions' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( $form_action ) . '">';
		wp_nonce_field( $nonce_action, 'mp_cp_create_promotion_code_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="mp_cp_promotion_code">' . esc_html__( 'Code', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_promotion_code" name="mp_cp_promotion_code" maxlength="64" autocomplete="off" required />';
		echo '<p class="description">' . esc_html__( 'A-Z, 0-9, and hyphens only; minimum 4 characters.', 'mp-commerce-promotions' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_code_usage_limit">' . esc_html__( 'Usage limit', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_code_usage_limit" name="mp_cp_code_usage_limit" min="0" step="1" />';
		echo '<p class="description">' . esc_html__( 'Optional. Leave empty for unlimited.', 'mp-commerce-promotions' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_code_expires_at">' . esc_html__( 'Expires at', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_code_expires_at" name="mp_cp_code_expires_at" placeholder="' . esc_attr__( 'YYYY-MM-DD HH:MM:SS or leave empty', 'mp-commerce-promotions' ) . '" />';
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" name="mp_cp_create_promotion_code_submit" value="1" class="button">' . esc_html__( 'Create promotion code', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
		echo '</div>';
	}

	private function format_code_usage( PromotionCode $code ): string {
		$limit = $code->get_usage_limit();
		if ( $limit === null ) {
			return sprintf(
				/* translators: %d: usage count */
				__( '%d / —', 'mp-commerce-promotions' ),
				$code->get_usage_count()
			);
		}

		return sprintf(
			/* translators: 1: usage count, 2: usage limit */
			__( '%1$d / %2$d', 'mp-commerce-promotions' ),
			$code->get_usage_count(),
			$limit
		);
	}

	private function render_usage_redemptions_section( Promotion $promotion ): void {
		if ( $this->redemptions === null ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$rows = $this->redemptions->find_for_promotion( $pid, self::ADMIN_USAGE_AUDIT_LIMIT );

		echo '<div class="card" style="max-width:960px;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Usage / Redemptions', 'mp-commerce-promotions' ) . '</h2>';

		if ( $rows === array() ) {
			echo '<p class="description">' . esc_html__( 'No redemptions recorded.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Order ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Customer ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Discount Amount', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Currency', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Redeemed At', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created At', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			if ( ! $r instanceof Redemption ) {
				continue;
			}
			$rid   = $r->get_id();
			$oid   = $r->get_order_id();
			$cid   = $r->get_customer_id();
			$disc  = $r->get_discount_amount();
			$cur   = $r->get_currency();
			$stat  = $r->get_status();
			$redAt = $r->get_redeemed_at();
			$creAt = $r->get_created_at();

			echo '<tr>';
			echo '<td>' . esc_html( $rid !== null ? (string) $rid : '—' ) . '</td>';
			echo '<td>' . esc_html( $oid !== null ? (string) $oid : '—' ) . '</td>';
			echo '<td>' . esc_html( $cid !== null ? (string) $cid : '—' ) . '</td>';
			echo '<td>' . esc_html( function_exists( 'wc_format_decimal' ) ? wc_format_decimal( (string) $disc ) : (string) $disc ) . '</td>';
			echo '<td>' . esc_html( $cur !== null && $cur !== '' ? $cur : '—' ) . '</td>';
			echo '<td>' . esc_html( $stat ) . '</td>';
			echo '<td>' . esc_html( $redAt !== null && $redAt !== '' ? $redAt : '—' ) . '</td>';
			echo '<td>' . esc_html( $creAt !== null && $creAt !== '' ? $creAt : '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function render_audit_log_section( Promotion $promotion ): void {
		if ( $this->audit_logs === null ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$rows = $this->audit_logs->find_for_promotion( $pid, self::ADMIN_USAGE_AUDIT_LIMIT );

		echo '<div class="card" style="max-width:960px;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Audit Log', 'mp-commerce-promotions' ) . '</h2>';

		if ( $rows === array() ) {
			echo '<p class="description">' . esc_html__( 'No audit entries recorded.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actor User ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'IP Hash', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created At', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Context', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $entry ) {
			if ( ! $entry instanceof AuditLogEntry ) {
				continue;
			}
			$eid    = $entry->get_id();
			$action = $entry->get_action();
			$actor  = $entry->get_actor_user_id();
			$ip     = $entry->get_ip_hash();
			$cat    = $entry->get_created_at();
			$ctx    = $entry->get_context();

			$ctx_json = wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! is_string( $ctx_json ) ) {
				$ctx_json = '{}';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $eid !== null ? (string) $eid : '—' ) . '</td>';
			echo '<td>' . esc_html( $action ) . '</td>';
			echo '<td>' . esc_html( $actor !== null ? (string) $actor : '—' ) . '</td>';
			echo '<td>' . esc_html( $ip !== null && $ip !== '' ? $ip : '—' ) . '</td>';
			echo '<td>' . esc_html( $cat !== null && $cat !== '' ? $cat : '—' ) . '</td>';
			echo '<td><pre class="code" style="max-height:140px;overflow:auto;font-size:11px;margin:0;white-space:pre-wrap;">'
				. esc_html( $ctx_json )
				. '</pre></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function list_url(): string {
		return AdminNavigation::tab_url( AdminNavigation::TAB_ALL );
	}

	private function edit_url( string $promotion_identifier ): string {
		return add_query_arg(
			array( 'promotion' => $promotion_identifier ),
			AdminNavigation::tab_url( AdminNavigation::TAB_ALL )
		);
	}

	/**
	 * @param array<string, string> $extra_query
	 */
	private function redirect_to_edit( int $promotion_id, array $extra_query = array() ): void {
		wp_safe_redirect(
			add_query_arg( $extra_query, $this->edit_url( (string) $promotion_id ) )
		);
		exit;
	}
}
