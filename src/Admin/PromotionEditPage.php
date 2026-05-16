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
use MP\CommercePromotions\Domain\PromotionApplicationMode;
use MP\CommercePromotions\Domain\PromotionCode;
use MP\CommercePromotions\Domain\PromotionCodeBatch;
use MP\CommercePromotions\Domain\PromotionCodeBatchRepository;
use MP\CommercePromotions\Domain\PromotionCodeFactory;
use MP\CommercePromotions\Domain\PromotionCodeRepository;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Domain\PromotionStatus;
use MP\CommercePromotions\Domain\Redemption;
use MP\CommercePromotions\Domain\RedemptionRepository;
use MP\CommercePromotions\Engine\EvaluationResult;
use MP\CommercePromotions\Engine\Condition\ConditionTrace;
use MP\CommercePromotions\Engine\PromotionEvaluationDecision;
use MP\CommercePromotions\Engine\PromotionEvaluationPlan;
use MP\CommercePromotions\Engine\PromotionEvaluator;
use MP\CommercePromotions\Engine\PromotionPlanner;
use MP\CommercePromotions\Engine\RuleRegistry;
use MP\CommercePromotions\Engine\RuleTypes;
use MP\CommercePromotions\Service\AuditLogger;
use MP\CommercePromotions\Woo\CartPromotionApplier;
use MP\CommercePromotions\Service\PromotionCodeBatchGenerationOutcome;
use MP\CommercePromotions\Service\PromotionCodeBatchGenerator;
use MP\CommercePromotions\Service\PromotionRuleValidator;
use MP\CommercePromotions\Service\PromotionService;
use MP\CommercePromotions\Service\SimpleRuleBuilder;
use MP\CommercePromotions\Woo\CartContextBuilder;
use RuntimeException;
use Throwable;

final class PromotionEditPage {

	private PromotionRepository $promotions;

	private PromotionService $promotion_service;

	private ?CartContextBuilder $cart_context_builder;

	private PromotionEvaluator $promotion_evaluator;

	private ?EvaluationResult $cart_preview_result = null;

	private ?PromotionEvaluationPlan $cart_preview_plan = null;

	private ?string $cart_preview_error = null;

	private PromotionPlanner $promotion_planner;

	private ?RedemptionRepository $redemptions;

	private ?AuditLogRepository $audit_logs;

	private PromotionRuleValidator $rule_validator;

	private ?PromotionCodeRepository $promotion_codes;

	private ?PromotionCodeFactory $promotion_code_factory;

	private ?PromotionCodeBatchRepository $code_batches;

	private ?PromotionCodeBatchGenerator $batch_generator;

	private ?AuditLogger $audit_logger;

	private ?PromotionCodeBatchGenerationOutcome $batch_generation_outcome = null;

	private ?string $batch_generation_error = null;

	private ?PromotionCodeBatch $batch_detail = null;

	private ?string $batch_detail_error = null;

	private const ADMIN_USAGE_AUDIT_LIMIT = 25;

	private const BATCH_DETAIL_CODES_LIMIT = 100;

	public function __construct(
		PromotionRepository $promotions,
		PromotionService $promotion_service,
		?CartContextBuilder $cart_context_builder = null,
		?PromotionEvaluator $promotion_evaluator = null,
		?RedemptionRepository $redemptions = null,
		?AuditLogRepository $audit_logs = null,
		?PromotionRuleValidator $rule_validator = null,
		?PromotionCodeRepository $promotion_codes = null,
		?PromotionCodeFactory $promotion_code_factory = null,
		?PromotionCodeBatchRepository $code_batches = null,
		?PromotionCodeBatchGenerator $batch_generator = null,
		?AuditLogger $audit_logger = null
	) {
		$this->promotions             = $promotions;
		$this->promotion_service      = $promotion_service;
		$this->cart_context_builder   = $cart_context_builder;
		$this->promotion_evaluator    = $promotion_evaluator ?? new PromotionEvaluator();
		$this->promotion_planner      = new PromotionPlanner( $this->promotion_evaluator );
		$this->redemptions            = $redemptions;
		$this->audit_logs             = $audit_logs;
		$this->rule_validator         = $rule_validator ?? new PromotionRuleValidator();
		$this->promotion_codes        = $promotion_codes;
		$this->promotion_code_factory = $promotion_code_factory;
		$this->code_batches           = $code_batches;
		$this->batch_generator        = $batch_generator;
		$this->audit_logger           = $audit_logger;
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

		$this->cart_preview_result      = null;
		$this->cart_preview_plan        = null;
		$this->cart_preview_error       = null;
		$this->batch_generation_outcome = null;
		$this->batch_generation_error   = null;
		$this->batch_detail             = null;
		$this->batch_detail_error       = null;

		$this->resolve_batch_detail_view( $promotion );

		$this->handle_post_download_generated_codes_csv( $promotion );

		$this->handle_post_change_status( $promotion );
		$this->handle_post_duplicate_promotion( $promotion );
		$this->handle_post_apply_rule_builder( $promotion );
		$this->handle_post_update( $promotion );
		$this->handle_post_create_promotion_code( $promotion );
		$this->handle_post_change_promotion_code_status( $promotion );
		$this->handle_post_change_batch_code_status( $promotion );
		$this->handle_post_generate_code_batch( $promotion );
		$this->handle_post_preview( $promotion );

		$promotion = $this->promotions->find_by_id_or_uuid( $identifier );
		if ( $promotion === null ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'promotion_not_found', $this->list_url() ) );
			exit;
		}

		$pid = $promotion->get_id();

		echo '<div class="wrap">';
		$this->render_edit_page_notices();
		$this->render_code_batch_generation_outcome();
		echo '<p class="mp-cp-edit-back-links">';
		echo '<a href="' . esc_url( $this->list_url() ) . '">' . esc_html__( '← Back to promotions', 'mp-commerce-promotions' ) . '</a>';
		if ( $this->batch_detail instanceof PromotionCodeBatch && $pid !== null && $pid > 0 ) {
			echo ' | <a href="' . esc_url( $this->edit_url( (string) $pid ) ) . '">' . esc_html__( '← Back to promotion', 'mp-commerce-promotions' ) . '</a>';
		}
		echo '</p>';

		if ( $this->batch_detail instanceof PromotionCodeBatch ) {
			$this->render_batch_detail_section( $this->batch_detail );
		}

		$this->render_edit_header_summary( $promotion );
		$this->render_status_section( $promotion );
		$this->render_form( $promotion );
		$this->render_rule_validation_section( $promotion );
		$this->render_cart_preview_section( $promotion );
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

	private function handle_post_change_promotion_code_status( Promotion $promotion ): void {
		if ( $this->promotion_codes === null ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$action = isset( $_POST['mp_cp_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_action'] ) ) : '';
		if ( $action !== 'change_promotion_code_status' ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$post_promotion_id = isset( $_POST['promotion_id'] ) ? (int) $_POST['promotion_id'] : 0;
		if ( $post_promotion_id !== $pid ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'id_mismatch' ) );
		}

		$code_id = isset( $_POST['promotion_code_id'] ) ? (int) $_POST['promotion_code_id'] : 0;
		if ( $code_id <= 0 ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'code_not_found' ) );
		}

		$nonce_action = 'mp_cp_change_promotion_code_status_' . $pid . '_' . $code_id;
		if ( ! isset( $_POST['mp_cp_change_promotion_code_status_nonce'] ) ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'missing_nonce' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_change_promotion_code_status_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'invalid_nonce' ) );
		}

		$new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['new_status'] ) ) : '';
		if ( ! PromotionCode::is_valid_status( $new_status ) ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'invalid_transition' ) );
		}

		$code = $this->promotion_codes->find( $code_id );
		if ( $code === null || $code->get_promotion_id() !== $pid ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'code_not_found' ) );
		}

		$old_status = $code->get_status();
		if ( ! $this->is_allowed_promotion_code_status_transition( $old_status, $new_status ) ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'invalid_transition' ) );
		}

		try {
			$updated_code = $code->with_status( $new_status );
		} catch ( InvalidArgumentException $e ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'invalid_transition' ) );
		}

		if ( ! $this->promotion_codes->update( $updated_code ) ) {
			$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_error' => 'update_failed' ) );
		}

		if ( $this->audit_logger !== null ) {
			$this->audit_logger->log(
				'promotion_code.status_changed',
				$pid,
				array(
					'promotion_code_id' => $code_id,
					'promotion_id'      => $pid,
					'old_status'        => $old_status,
					'new_status'        => $new_status,
					'last4'             => $code->get_code_last4(),
				),
				(int) get_current_user_id()
			);
		}

		$this->redirect_after_code_status_change( $pid, array( 'mp_cp_code_status_saved' => '1' ) );
	}

	private function is_allowed_promotion_code_status_transition( string $from_status, string $to_status ): bool {
		if ( $from_status === $to_status ) {
			return false;
		}

		if ( $from_status === PromotionCode::STATUS_ACTIVE && $to_status === PromotionCode::STATUS_DISABLED ) {
			return true;
		}

		if ( $from_status === PromotionCode::STATUS_DISABLED && $to_status === PromotionCode::STATUS_ACTIVE ) {
			return true;
		}

		if ( $from_status === PromotionCode::STATUS_DISABLED && $to_status === PromotionCode::STATUS_EXPIRED ) {
			return true;
		}

		if ( $from_status === PromotionCode::STATUS_EXPIRED && $to_status === PromotionCode::STATUS_DISABLED ) {
			return true;
		}

		return false;
	}

	private function handle_post_change_batch_code_status( Promotion $promotion ): void {
		if ( $this->promotion_codes === null || $this->code_batches === null ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$action = isset( $_POST['mp_cp_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_action'] ) ) : '';
		if ( $action !== 'change_batch_code_status' ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$post_promotion_id = isset( $_POST['promotion_id'] ) ? (int) $_POST['promotion_id'] : 0;
		if ( $post_promotion_id !== $pid ) {
			$this->redirect_after_batch_code_status_change( $pid, 0, array( 'mp_cp_batch_code_status_error' => 'id_mismatch' ) );
		}

		$batch_id = isset( $_POST['batch_id'] ) ? (int) $_POST['batch_id'] : 0;
		if ( $batch_id <= 0 ) {
			$this->redirect_after_batch_code_status_change( $pid, 0, array( 'mp_cp_batch_code_status_error' => 'batch_not_found' ) );
		}

		$nonce_action = 'mp_cp_change_batch_code_status_' . $pid . '_' . $batch_id;
		if ( ! isset( $_POST['mp_cp_change_batch_code_status_nonce'] ) ) {
			$this->redirect_after_batch_code_status_change( $pid, $batch_id, array( 'mp_cp_batch_code_status_error' => 'missing_nonce' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_change_batch_code_status_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_after_batch_code_status_change( $pid, $batch_id, array( 'mp_cp_batch_code_status_error' => 'invalid_nonce' ) );
		}

		$from_status = isset( $_POST['from_status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['from_status'] ) ) : '';
		$to_status   = isset( $_POST['to_status'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['to_status'] ) ) : '';
		if ( ! PromotionCode::is_valid_status( $from_status ) || ! PromotionCode::is_valid_status( $to_status ) ) {
			$this->redirect_after_batch_code_status_change( $pid, $batch_id, array( 'mp_cp_batch_code_status_error' => 'invalid_transition' ) );
		}

		if ( ! $this->is_allowed_promotion_code_status_transition( $from_status, $to_status ) ) {
			$this->redirect_after_batch_code_status_change( $pid, $batch_id, array( 'mp_cp_batch_code_status_error' => 'invalid_transition' ) );
		}

		$batch = $this->code_batches->find( $batch_id );
		if ( $batch === null || $batch->get_promotion_id() !== $pid ) {
			$this->redirect_after_batch_code_status_change( $pid, $batch_id, array( 'mp_cp_batch_code_status_error' => 'batch_not_found' ) );
		}

		$affected = $this->promotion_codes->bulk_update_status_for_batch( $batch_id, $from_status, $to_status );

		if ( $this->audit_logger !== null && $affected > 0 ) {
			$this->audit_logger->log(
				'promotion_code.batch_status_changed',
				$pid,
				array(
					'promotion_id'   => $pid,
					'batch_id'       => $batch_id,
					'from_status'    => $from_status,
					'to_status'      => $to_status,
					'affected_count' => $affected,
				),
				(int) get_current_user_id()
			);
		}

		$this->redirect_after_batch_code_status_change(
			$pid,
			$batch_id,
			array(
				'mp_cp_batch_code_status_saved' => '1',
				'mp_cp_batch_affected'          => (string) $affected,
			)
		);
	}

	private function handle_post_download_generated_codes_csv( Promotion $promotion ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_download_generated_codes_submit'] ) ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			wp_die( esc_html__( 'Invalid promotion.', 'mp-commerce-promotions' ) );
		}

		$nonce_action = 'mp_cp_download_generated_codes_' . $pid;
		if ( ! isset( $_POST['mp_cp_download_generated_codes_nonce'] ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'mp-commerce-promotions' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_download_generated_codes_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Security check failed. Please try again.', 'mp-commerce-promotions' ) );
		}

		if ( ! isset( $_POST['mp_cp_generated_codes_payload'] ) ) {
			wp_die( esc_html__( 'No generated codes were submitted for download.', 'mp-commerce-promotions' ) );
		}

		$encoded = wp_unslash( (string) $_POST['mp_cp_generated_codes_payload'] );
		$encoded = trim( $encoded );
		if ( $encoded !== '' && ! preg_match( '/^[A-Za-z0-9+\/=]+$/', $encoded ) ) {
			wp_die( esc_html__( 'Generated codes payload is invalid or expired.', 'mp-commerce-promotions' ) );
		}

		$decoded = PromotionCodeBatchGenerationOutcome::decode_download_payload( $encoded );
		if ( $decoded === null ) {
			wp_die( esc_html__( 'Generated codes payload is invalid or expired.', 'mp-commerce-promotions' ) );
		}

		if ( (int) $decoded['promotion_id'] !== $pid ) {
			wp_die( esc_html__( 'Generated codes do not match this promotion.', 'mp-commerce-promotions' ) );
		}

		$batch_id = (int) $decoded['batch_id'];
		$csv      = PromotionCodeBatchGenerationOutcome::build_csv_string(
			$decoded['codes'],
			$pid,
			$batch_id,
			$decoded['generated_at']
		);

		$filename = sprintf( 'promotion-codes-%d-%d.csv', $pid, $batch_id );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV file body.
		echo "\xEF\xBB\xBF" . $csv;
		exit;
	}

	private function handle_post_generate_code_batch( Promotion $promotion ): void {
		if ( $this->batch_generator === null ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_generate_code_batch_submit'] ) ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_generate_code_batch_' . $pid;
		if ( ! isset( $_POST['mp_cp_generate_code_batch_nonce'] ) ) {
			$this->batch_generation_error = __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_generate_code_batch_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->batch_generation_error = __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			return;
		}

		$batch_name = isset( $_POST['mp_cp_batch_name'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_batch_name'] ) )
			: '';
		if ( $batch_name === '' ) {
			$this->batch_generation_error = __( 'Please enter a batch name.', 'mp-commerce-promotions' );
			return;
		}

		$quantity = isset( $_POST['mp_cp_batch_quantity'] ) ? (int) $_POST['mp_cp_batch_quantity'] : 0;
		if ( $quantity <= 0 || $quantity > PromotionCodeBatch::MAX_QUANTITY ) {
			$this->batch_generation_error = sprintf(
				/* translators: %d: maximum batch size */
				__( 'Quantity must be between 1 and %d.', 'mp-commerce-promotions' ),
				PromotionCodeBatch::MAX_QUANTITY
			);
			return;
		}

		$prefix = isset( $_POST['mp_cp_batch_prefix'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_batch_prefix'] ) )
			: '';
		if ( $prefix === '' ) {
			$prefix = null;
		}

		$usage_limit = null;
		if ( isset( $_POST['mp_cp_batch_usage_limit'] ) && $_POST['mp_cp_batch_usage_limit'] !== '' ) {
			$usage_limit = (int) $_POST['mp_cp_batch_usage_limit'];
			if ( $usage_limit < 0 ) {
				$this->batch_generation_error = __( 'Usage limit must be zero or greater.', 'mp-commerce-promotions' );
				return;
			}
		}

		$expires_at = isset( $_POST['mp_cp_batch_expires_at'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_batch_expires_at'] ) )
			: '';
		if ( $expires_at === '' ) {
			$expires_at = null;
		}

		$actor = (int) get_current_user_id();
		if ( $actor <= 0 ) {
			$actor = null;
		}

		try {
			$this->batch_generation_outcome = $this->batch_generator->generate(
				$pid,
				$batch_name,
				$quantity,
				$prefix,
				$usage_limit,
				$expires_at,
				$actor
			);
		} catch ( InvalidArgumentException $e ) {
			$this->batch_generation_error = $e->getMessage();
		} catch ( RuntimeException $e ) {
			$this->batch_generation_error = $e->getMessage();
		}
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
			$context                   = $this->cart_context_builder->build_from_cart();
			$this->cart_preview_result = $this->promotion_evaluator->evaluate( $promotion, $context );

			$active = $this->promotions->find_active( 50 );
			$this->cart_preview_plan   = $this->promotion_planner->plan( $active, $context );
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

	private function handle_post_duplicate_promotion( Promotion $promotion ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$action = isset( $_POST['mp_cp_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_action'] ) ) : '';
		if ( $action !== 'duplicate_promotion' ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mp-commerce-promotions' ) );
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_duplicate_promotion_' . $pid;
		if ( ! isset( $_POST['mp_cp_duplicate_promotion_nonce'] ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_duplicate_error' => 'missing_nonce' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_duplicate_promotion_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_duplicate_error' => 'invalid_nonce' ) );
		}

		$post_promo_id = isset( $_POST['promotion_id'] ) ? (int) $_POST['promotion_id'] : 0;
		if ( $post_promo_id !== $pid ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_duplicate_error' => 'id_mismatch' ) );
		}

		try {
			$copy = $this->promotion_service->duplicate_as_draft( $promotion, (int) get_current_user_id() );
		} catch ( RuntimeException $e ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_duplicate_error' => 'duplicate_failed' ) );
		}

		$new_id = $copy->get_id();
		if ( $new_id === null || $new_id <= 0 ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_duplicate_error' => 'duplicate_failed' ) );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'promotion'        => (string) $new_id,
					'mp_cp_duplicated' => '1',
				),
				$this->edit_url( (string) $new_id )
			)
		);
		exit;
	}

	private function handle_post_apply_rule_builder( Promotion $promotion ): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		$action = isset( $_POST['mp_cp_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_action'] ) ) : '';
		if ( $action !== 'apply_rule_builder' ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mp-commerce-promotions' ) );
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_apply_rule_builder_' . $pid;
		if ( ! isset( $_POST['mp_cp_apply_rule_builder_nonce'] ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_rule_builder_error' => 'missing_nonce' ) );
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_apply_rule_builder_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_rule_builder_error' => 'invalid_nonce' ) );
		}

		$post_id = isset( $_POST['mp_cp_promotion_id'] ) ? (int) $_POST['mp_cp_promotion_id'] : 0;
		if ( $post_id !== $pid ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_rule_builder_error' => 'id_mismatch' ) );
		}

		$post = array(
			'mp_cp_builder_condition_type' => isset( $_POST['mp_cp_builder_condition_type'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_condition_type'] )
				: '',
			'mp_cp_builder_action_type'    => isset( $_POST['mp_cp_builder_action_type'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_action_type'] )
				: '',
			'mp_cp_builder_amount'         => isset( $_POST['mp_cp_builder_amount'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_amount'] )
				: '',
			'mp_cp_builder_product_id'     => isset( $_POST['mp_cp_builder_product_id'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_product_id'] )
				: '',
			'mp_cp_builder_category_id'    => isset( $_POST['mp_cp_builder_category_id'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_category_id'] )
				: '',
			'mp_cp_builder_operator'       => isset( $_POST['mp_cp_builder_operator'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_operator'] )
				: '',
			'mp_cp_builder_quantity'       => isset( $_POST['mp_cp_builder_quantity'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_quantity'] )
				: '',
			'mp_cp_builder_percentage'     => isset( $_POST['mp_cp_builder_percentage'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_percentage'] )
				: '',
			'mp_cp_builder_fixed_amount'   => isset( $_POST['mp_cp_builder_fixed_amount'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_fixed_amount'] )
				: '',
			'mp_cp_builder_roles'          => isset( $_POST['mp_cp_builder_roles'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_roles'] )
				: '',
			'mp_cp_builder_countries'      => isset( $_POST['mp_cp_builder_countries'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_countries'] )
				: '',
			'mp_cp_builder_domains'        => isset( $_POST['mp_cp_builder_domains'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_domains'] )
				: '',
			'mp_cp_builder_redemption_count' => isset( $_POST['mp_cp_builder_redemption_count'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_redemption_count'] )
				: '',
			'mp_cp_builder_cheapest_scope'   => isset( $_POST['mp_cp_builder_cheapest_scope'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_cheapest_scope'] )
				: '',
			'mp_cp_builder_cheapest_category_ids' => isset( $_POST['mp_cp_builder_cheapest_category_ids'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_cheapest_category_ids'] )
				: '',
			'mp_cp_builder_cheapest_product_ids' => isset( $_POST['mp_cp_builder_cheapest_product_ids'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_cheapest_product_ids'] )
				: '',
			'mp_cp_builder_cheapest_required_quantity' => isset( $_POST['mp_cp_builder_cheapest_required_quantity'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_cheapest_required_quantity'] )
				: '',
			'mp_cp_builder_cheapest_discounted_quantity' => isset( $_POST['mp_cp_builder_cheapest_discounted_quantity'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_cheapest_discounted_quantity'] )
				: '',
			'mp_cp_builder_cheapest_discount_percentage' => isset( $_POST['mp_cp_builder_cheapest_discount_percentage'] )
				? wp_unslash( (string) $_POST['mp_cp_builder_cheapest_discount_percentage'] )
				: '',
		);

		try {
			$built = SimpleRuleBuilder::build_from_post( $post );
		} catch ( InvalidArgumentException $e ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_rule_builder_error' => $e->getMessage() ) );
		}

		try {
			$updated = $promotion->with_rules(
				$built['conditions'],
				$built['actions'],
				$promotion->get_restrictions()
			);
			$this->promotion_service->update_promotion( $updated, (int) get_current_user_id() );
		} catch ( RuntimeException $e ) {
			$this->redirect_to_edit( $pid, array( 'mp_cp_rule_builder_error' => 'update_failed' ) );
		}

		$this->redirect_to_edit( $pid, array( 'mp_cp_rule_builder_saved' => '1' ) );
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
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'missing_nonce',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_update_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_nonce',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$post_id = isset( $_POST['mp_cp_promotion_id'] ) ? (int) $_POST['mp_cp_promotion_id'] : 0;
		if ( $post_id !== $pid ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'id_mismatch',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$name = isset( $_POST['promotion_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['promotion_name'] ) ) : '';
		if ( $name === '' ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'empty_name',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$description = isset( $_POST['promotion_description'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['promotion_description'] ) ) : '';
		if ( $description === '' ) {
			$description = null;
		}

		$priority = isset( $_POST['promotion_priority'] ) ? (int) $_POST['promotion_priority'] : 0;
		if ( $priority < 0 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_priority',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$starts_raw = isset( $_POST['promotion_starts_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['promotion_starts_at'] ) ) : '';
		$ends_raw   = isset( $_POST['promotion_ends_at'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['promotion_ends_at'] ) ) : '';
		$starts_at  = $starts_raw === '' ? null : $starts_raw;
		$ends_at    = $ends_raw === '' ? null : $ends_raw;

		$conditions_raw   = isset( $_POST['promotion_conditions_json'] ) ? wp_unslash( (string) $_POST['promotion_conditions_json'] ) : '';
		$actions_raw      = isset( $_POST['promotion_actions_json'] ) ? wp_unslash( (string) $_POST['promotion_actions_json'] ) : '';
		$restrictions_raw = isset( $_POST['promotion_restrictions_json'] ) ? wp_unslash( (string) $_POST['promotion_restrictions_json'] ) : '';

		$conditions   = $this->decode_json_array_field( $conditions_raw );
		$actions      = $this->decode_json_array_field( $actions_raw );
		$restrictions = $this->decode_json_array_field( $restrictions_raw );

		if ( $conditions === null || $actions === null || $restrictions === null ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_json',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$application_mode = isset( $_POST['promotion_application_mode'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['promotion_application_mode'] ) )
			: PromotionApplicationMode::EXCLUSIVE;
		if ( ! PromotionApplicationMode::is_valid( $application_mode ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_application_mode',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$stop_processing = isset( $_POST['promotion_stop_processing'] ) && (string) $_POST['promotion_stop_processing'] === '1';

		$max_apps_raw = isset( $_POST['promotion_max_applications'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['promotion_max_applications'] ) )
			: '';
		$max_apps     = $max_apps_raw === '' ? null : (int) $max_apps_raw;
		if ( $max_apps !== null && $max_apps < 1 ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_max_applications',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		$usage_limit = null;
		if ( isset( $_POST['promotion_usage_limit'] ) && $_POST['promotion_usage_limit'] !== '' ) {
			$usage_limit = (int) $_POST['promotion_usage_limit'];
			if ( $usage_limit < 1 ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'promotion'   => (string) $pid,
							'mp_cp_error' => 'invalid_usage_limit',
						),
						$this->edit_url( (string) $pid )
					)
				);
				exit;
			}
		}

		$customer_usage_limit = null;
		if ( isset( $_POST['promotion_customer_usage_limit'] ) && $_POST['promotion_customer_usage_limit'] !== '' ) {
			$customer_usage_limit = (int) $_POST['promotion_customer_usage_limit'];
			if ( $customer_usage_limit < 1 ) {
				wp_safe_redirect(
					add_query_arg(
						array(
							'promotion'   => (string) $pid,
							'mp_cp_error' => 'invalid_customer_usage_limit',
						),
						$this->edit_url( (string) $pid )
					)
				);
				exit;
			}
		}

		$excluded_ids = $this->parse_excluded_promotion_ids_from_post( $pid );
		if ( $excluded_ids === null ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_excluded_promotion_ids',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		try {
			$updated = $promotion
				->with_name( $name )
				->with_description( $description )
				->with_status( $promotion->get_status() )
				->with_priority( $priority )
				->with_date_window( $starts_at, $ends_at )
				->with_usage_limits( $usage_limit, $customer_usage_limit )
				->with_application_rules( $application_mode, $stop_processing, $max_apps )
				->with_excluded_promotion_ids( $excluded_ids )
				->with_rules( $conditions, $actions, $restrictions );

			$this->promotion_service->update_promotion( $updated, (int) get_current_user_id() );
		} catch ( InvalidArgumentException $e ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'invalid_excluded_promotion_ids',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		} catch ( RuntimeException $e ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'promotion'   => (string) $pid,
						'mp_cp_error' => 'update_failed',
					),
					$this->edit_url( (string) $pid )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'promotion'   => (string) $pid,
					'mp_cp_saved' => '1',
				),
				$this->edit_url( (string) $pid )
			)
		);
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

	private function render_edit_page_notices(): void {
		foreach ( $this->collect_edit_page_notices() as $notice ) {
			AdminNotice::render( $notice['type'], $notice['message'] );
		}

		if ( $this->batch_detail_error !== null && $this->batch_detail_error !== '' ) {
			AdminNotice::error( $this->batch_detail_error );
		}
	}

	/**
	 * @return list<array{type: string, message: string}>
	 */
	private function collect_edit_page_notices(): array {
		$notices = array();
		$seen    = array();

		$add = static function ( string $type, string $message ) use ( &$notices, &$seen ): void {
			if ( $message === '' || isset( $seen[ $message ] ) ) {
				return;
			}
			$seen[ $message ] = true;
			$notices[]        = array(
				'type'    => $type,
				'message' => $message,
			);
		};

		if ( isset( $_GET['mp_cp_duplicated'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_duplicated'] ) ) === '1' ) {
			$add(
				'success',
				__( 'Promotion duplicated successfully. You are editing the new draft copy.', 'mp-commerce-promotions' )
			);
		}

		if ( isset( $_GET['mp_cp_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_saved'] ) ) === '1' ) {
			$add( 'success', __( 'Promotion saved successfully.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['mp_cp_status_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_status_saved'] ) ) === '1' ) {
			$add( 'success', __( 'Promotion status updated successfully.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['mp_cp_rule_builder_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_rule_builder_saved'] ) ) === '1' ) {
			$add( 'success', __( 'Rules updated from the simple rule builder.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['mp_cp_code_created'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_code_created'] ) ) === '1' ) {
			$add( 'success', __( 'Promotion code created successfully.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['mp_cp_code_status_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_code_status_saved'] ) ) === '1' ) {
			$add( 'success', __( 'Promotion code status updated successfully.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['mp_cp_batch_code_status_saved'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_batch_code_status_saved'] ) ) === '1' ) {
			$affected = isset( $_GET['mp_cp_batch_affected'] ) ? (int) $_GET['mp_cp_batch_affected'] : 0;
			$add(
				'success',
				sprintf(
					/* translators: %d: number of promotion codes updated */
					_n(
						'%d promotion code in this batch was updated successfully.',
						'%d promotion codes in this batch were updated successfully.',
						$affected,
						'mp-commerce-promotions'
					),
					$affected
				)
			);
		}

		$error_params = array(
			'mp_cp_duplicate_error'         => array( $this, 'duplicate_error_message_for_code' ),
			'mp_cp_error'                   => array( $this, 'error_message_for_code' ),
			'mp_cp_status_error'            => array( $this, 'status_error_message_for_code' ),
			'mp_cp_code_error'              => array( $this, 'code_error_message_for_code' ),
			'mp_cp_code_status_error'       => array( $this, 'code_status_error_message_for_code' ),
			'mp_cp_batch_code_status_error' => array( $this, 'batch_code_status_error_message_for_code' ),
			'mp_cp_rule_builder_error'      => array( $this, 'rule_builder_error_message_for_code' ),
		);

		foreach ( $error_params as $param => $resolver ) {
			if ( ! isset( $_GET[ $param ] ) ) {
				continue;
			}

			$code = sanitize_text_field( wp_unslash( (string) $_GET[ $param ] ) );
			$msg  = $resolver( $code );
			if ( $msg !== '' ) {
				$add( 'error', $msg );
			}
		}

		return $notices;
	}

	private function security_check_failed_message(): string {
		return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
	}

	private function duplicate_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return $this->security_check_failed_message();
			case 'id_mismatch':
				return __( 'Invalid duplicate promotion form submission.', 'mp-commerce-promotions' );
			case 'duplicate_failed':
				return __( 'Could not duplicate the promotion. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function rule_builder_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return $this->security_check_failed_message();
			case 'id_mismatch':
				return __( 'Invalid rule builder form submission.', 'mp-commerce-promotions' );
			case 'invalid_condition_type':
				return __( 'Please choose a supported condition type.', 'mp-commerce-promotions' );
			case 'invalid_action_type':
				return __( 'Please choose a supported action type.', 'mp-commerce-promotions' );
			case 'invalid_operator':
				return __( 'Please choose a supported operator.', 'mp-commerce-promotions' );
			case 'invalid_amount':
				return __( 'Enter a valid minimum subtotal amount.', 'mp-commerce-promotions' );
			case 'invalid_product_id':
				return __( 'Enter a valid numeric product ID greater than zero.', 'mp-commerce-promotions' );
			case 'invalid_category_id':
				return __( 'Enter a valid numeric category ID greater than zero.', 'mp-commerce-promotions' );
			case 'invalid_quantity':
				return __( 'Enter a valid quantity.', 'mp-commerce-promotions' );
			case 'invalid_percentage':
				return __( 'Enter a valid percentage greater than zero and up to 100.', 'mp-commerce-promotions' );
			case 'invalid_fixed_amount':
				return __( 'Enter a valid fixed discount amount greater than zero.', 'mp-commerce-promotions' );
			case 'update_failed':
				return __( 'Could not save rules from the builder. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function batch_code_status_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return $this->security_check_failed_message();
			case 'id_mismatch':
				return __( 'Invalid batch code status form submission.', 'mp-commerce-promotions' );
			case 'batch_not_found':
				return __( 'Code batch not found for this promotion.', 'mp-commerce-promotions' );
			case 'invalid_transition':
				return __( 'That batch code status change is not allowed.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function code_status_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return $this->security_check_failed_message();
			case 'id_mismatch':
				return __( 'Invalid promotion code status form submission.', 'mp-commerce-promotions' );
			case 'code_not_found':
				return __( 'Promotion code not found for this promotion.', 'mp-commerce-promotions' );
			case 'invalid_transition':
				return __( 'That promotion code status change is not allowed.', 'mp-commerce-promotions' );
			case 'update_failed':
				return __( 'Could not update the promotion code status. Please try again.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function code_error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return $this->security_check_failed_message();
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
				return $this->security_check_failed_message();
			case 'id_mismatch':
				return __( 'Invalid form submission.', 'mp-commerce-promotions' );
			case 'empty_name':
				return __( 'Please enter a promotion name.', 'mp-commerce-promotions' );
			case 'invalid_priority':
				return __( 'Priority must be zero or greater.', 'mp-commerce-promotions' );
			case 'invalid_application_mode':
				return __( 'Application mode must be exclusive or stackable.', 'mp-commerce-promotions' );
			case 'invalid_max_applications':
				return __( 'Max applications must be empty or at least 1.', 'mp-commerce-promotions' );
			case 'invalid_excluded_promotion_ids':
				return __( 'Excluded promotion IDs must be a comma-separated list of positive integers and cannot include this promotion.', 'mp-commerce-promotions' );
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
				return $this->security_check_failed_message();
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

	private function render_edit_header_summary( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		echo '<h1 style="margin-bottom:0.25em;">' . esc_html( $promotion->get_name() ) . '</h1>';
		$usage_label = (string) $promotion->get_usage_count();
		$usage_limit = $promotion->get_usage_limit();
		if ( $usage_limit !== null ) {
			$usage_label .= ' / ' . (string) $usage_limit;
		}

		echo '<p class="description" style="margin-top:0;">';
		echo esc_html(
			sprintf(
				/* translators: 1: promotion ID, 2: status, 3: priority, 4: usage count (optional limit) */
				__( 'Promotion #%1$d · Status: %2$s · Priority: %3$d · Usage: %4$s', 'mp-commerce-promotions' ),
				$id,
				$promotion->get_status(),
				$promotion->get_priority(),
				$usage_label
			)
		);
		echo '</p>';
	}

	private function render_duplicate_promotion_form( string $action_url, int $promotion_id ): void {
		echo '<form method="post" action="' . esc_url( $action_url ) . '" style="margin-top:12px;">';
		wp_nonce_field( 'mp_cp_duplicate_promotion_' . $promotion_id, 'mp_cp_duplicate_promotion_nonce' );
		echo '<input type="hidden" name="mp_cp_action" value="duplicate_promotion" />';
		echo '<input type="hidden" name="promotion_id" value="' . esc_attr( (string) $promotion_id ) . '" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Duplicate promotion', 'mp-commerce-promotions' ) . '</button>';
		echo '<p class="description" style="margin:8px 0 0;">' . esc_html__(
			'Creates a new draft copy with the same rules. Codes, batches, redemptions, and usage counts are not copied.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '</form>';
	}

	private function render_status_section( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		$status = $promotion->get_status();
		$url    = $this->edit_url( (string) $id );

		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Status actions', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Current:', 'mp-commerce-promotions' ) . '</strong> ' . esc_html( $status ) . '</p>';

		if ( $status === PromotionStatus::ARCHIVED ) {
			echo '<p class="description">' . esc_html__( 'Archived promotions cannot be reactivated.', 'mp-commerce-promotions' ) . '</p>';
			$this->render_duplicate_promotion_form( $url, $id );
			echo '</div>';
			return;
		}

		echo '<p>' . esc_html__( 'Change status using the actions below.', 'mp-commerce-promotions' ) . '</p>';

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

		$this->render_duplicate_promotion_form( $url, $id );

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

		AdminSection::render(
			__( 'Rule Validation', 'mp-commerce-promotions' ),
			function () use ( $issues ): void {
				if ( count( $issues ) === 0 ) {
					echo '<p>' . esc_html__( 'No validation issues found.', 'mp-commerce-promotions' ) . '</p>';
					return;
				}

				echo '<ul style="list-style:disc;margin-left:1.5em;">';
				foreach ( $issues as $issue ) {
					$level   = isset( $issue['level'] ) ? (string) $issue['level'] : 'info';
					$message = isset( $issue['message'] ) ? (string) $issue['message'] : '';

					switch ( $level ) {
						case 'error':
							$label = __( 'Error', 'mp-commerce-promotions' );
							break;
						case 'warning':
							$label = __( 'Warning', 'mp-commerce-promotions' );
							break;
						default:
							$label = __( 'Info', 'mp-commerce-promotions' );
							break;
					}

					echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $message ) . '</li>';
				}
				echo '</ul>';
			},
			__( 'Read-only checks against supported condition and action types. Passing validation does not guarantee the promotion will apply to a specific cart.', 'mp-commerce-promotions' ),
			array(
				'heading' => 'h2',
				'width'   => 'narrow',
			)
		);
	}

	/**
	 * @param list<array<string, mixed>> $action_results
	 */
	private function render_free_gift_cart_preview_notes( array $action_results, bool $eligible ): void {
		foreach ( $action_results as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = isset( $row['type'] ) ? (string) $row['type'] : '';
			if ( $type !== CartPromotionApplier::ACTION_FREE_GIFT_PRODUCT ) {
				continue;
			}

			$payload    = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();
			$product_id = isset( $payload['product_id'] ) ? (int) $payload['product_id'] : 0;
			$quantity   = isset( $payload['quantity'] ) ? (int) $payload['quantity'] : 0;

			echo '<p><strong>' . esc_html__( 'Free gift (storefront)', 'mp-commerce-promotions' ) . '</strong></p>';
			echo '<ul style="list-style:disc;margin-left:1.5em;">';
			echo '<li>' . esc_html__( 'Product ID', 'mp-commerce-promotions' ) . ': ' . esc_html( (string) $product_id ) . '</li>';
			echo '<li>' . esc_html__( 'Quantity', 'mp-commerce-promotions' ) . ': ' . esc_html( (string) $quantity ) . '</li>';
			echo '<li>' . esc_html__( 'Eligible on current cart', 'mp-commerce-promotions' ) . ': ';
			echo $eligible
				? esc_html__( 'Yes — gift would be synchronized on checkout totals', 'mp-commerce-promotions' )
				: esc_html__( 'No', 'mp-commerce-promotions' );
			echo '</li>';
			echo '</ul>';
		}
	}

	private function render_evaluation_trace_tables( EvaluationResult $result ): void {
		$condition_traces = $result->get_condition_traces();
		if ( count( $condition_traces ) > 0 ) {
			echo '<p><strong>' . esc_html__( 'Condition trace', 'mp-commerce-promotions' ) . '</strong></p>';
			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Type', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Result', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Reason', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Observed', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $condition_traces as $trace ) {
				if ( ! is_array( $trace ) ) {
					continue;
				}
				$type = isset( $trace['type'] ) ? (string) $trace['type'] : '';
				echo '<tr>';
				echo '<td>' . esc_html( RuleRegistry::condition_label( $type ) ) . '</td>';
				$passed = ! empty( $trace['passed'] );
				echo '<td>' . esc_html( $passed ? __( 'Pass', 'mp-commerce-promotions' ) : __( 'Fail', 'mp-commerce-promotions' ) ) . '</td>';
				echo '<td><code>' . esc_html( isset( $trace['reason_code'] ) ? (string) $trace['reason_code'] : '' ) . '</code></td>';
				$message = isset( $trace['message'] ) && is_string( $trace['message'] ) ? $trace['message'] : '';
				echo '<td>' . esc_html( $message ) . '</td>';
				echo '<td>';
				$this->render_trace_json_pre( isset( $trace['observed'] ) && is_array( $trace['observed'] ) ? $trace['observed'] : array() );
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		$action_traces = $result->get_action_traces();
		if ( count( $action_traces ) > 0 ) {
			echo '<p style="margin-top:16px;"><strong>' . esc_html__( 'Action trace', 'mp-commerce-promotions' ) . '</strong></p>';
			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'Type', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Selected', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Reason', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Message', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Preview', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $action_traces as $trace ) {
				if ( ! is_array( $trace ) ) {
					continue;
				}
				$type = isset( $trace['type'] ) ? (string) $trace['type'] : '';
				echo '<tr>';
				echo '<td>' . esc_html( RuleRegistry::action_label( $type ) ) . '</td>';
				$selected = ! empty( $trace['selected'] );
				echo '<td>' . esc_html( $selected ? __( 'Yes', 'mp-commerce-promotions' ) : __( 'No', 'mp-commerce-promotions' ) ) . '</td>';
				echo '<td><code>' . esc_html( isset( $trace['reason_code'] ) ? (string) $trace['reason_code'] : '' ) . '</code></td>';
				$message = isset( $trace['message'] ) && is_string( $trace['message'] ) ? $trace['message'] : '';
				if ( $message === '' && $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
					$message = $this->format_cheapest_item_preview_summary(
						isset( $trace['preview'] ) && is_array( $trace['preview'] ) ? $trace['preview'] : array()
					);
				}
				echo '<td>' . esc_html( $message ) . '</td>';
				echo '<td>';
				if ( $type === RuleTypes::ACTION_CHEAPEST_ITEM_DISCOUNT ) {
					$this->render_cheapest_item_preview_details(
						isset( $trace['preview'] ) && is_array( $trace['preview'] ) ? $trace['preview'] : array()
					);
				}
				$this->render_trace_json_pre( isset( $trace['preview'] ) && is_array( $trace['preview'] ) ? $trace['preview'] : array() );
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description" style="margin-top:8px;">' . esc_html__(
				'Cheapest item discount is applied as a cart fee in the MVP; product line prices are not changed.',
				'mp-commerce-promotions'
			) . '</p>';
		}
	}

	/**
	 * @param array<string, mixed> $preview Action trace preview (ActionResult::to_array shape).
	 */
	private function format_cheapest_item_preview_summary( array $preview ): string {
		$payload = isset( $preview['payload'] ) && is_array( $preview['payload'] )
			? $preview['payload']
			: $preview;

		if ( ! empty( $payload['not_applicable'] ) ) {
			$reason = isset( $payload['reason'] ) ? (string) $payload['reason'] : 'not_applicable';
			$eligible = isset( $payload['eligible_units'] ) ? (string) $payload['eligible_units'] : '?';
			$required = isset( $payload['required_quantity'] ) ? (string) $payload['required_quantity'] : '?';

			return sprintf(
				/* translators: 1: reason code, 2: eligible unit count, 3: required quantity */
				__( 'Not applicable (%1$s): %2$s eligible units, need %3$s.', 'mp-commerce-promotions' ),
				$reason,
				$eligible,
				$required
			);
		}

		$amount = isset( $payload['discount_amount'] ) ? (float) $payload['discount_amount'] : 0.0;
		$units  = isset( $payload['discounted_units'] ) ? (int) $payload['discounted_units'] : 0;

		return sprintf(
			/* translators: 1: discount amount, 2: discounted unit count */
			__( 'Calculated discount: %1$s (%2$d unit(s)).', 'mp-commerce-promotions' ),
			(string) $amount,
			$units
		);
	}

	/**
	 * @param array<string, mixed> $preview
	 */
	private function render_cheapest_item_preview_details( array $preview ): void {
		$payload = isset( $preview['payload'] ) && is_array( $preview['payload'] )
			? $preview['payload']
			: $preview;

		if ( $payload === array() ) {
			return;
		}

		echo '<p class="description" style="margin:0 0 8px;">';
		echo esc_html( $this->format_cheapest_item_preview_summary( $preview ) );
		if ( isset( $payload['scope'] ) ) {
			echo ' ';
			echo esc_html(
				sprintf(
					/* translators: %s: scope label */
					__( 'Scope: %s.', 'mp-commerce-promotions' ),
					(string) $payload['scope']
				)
			);
		}
		echo '</p>';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function render_trace_json_pre( array $data ): void {
		if ( $data === array() ) {
			echo '<span class="description">—</span>';
			return;
		}

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}
		echo '<pre class="code" style="max-height:120px;overflow:auto;background:#f6f7f7;padding:8px;margin:0;font-size:11px;">' . esc_html( $json ) . '</pre>';
	}

	private function render_promotion_plan_table( PromotionEvaluationPlan $plan ): void {
		$decisions = $plan->get_decisions();
		if ( $decisions === array() ) {
			return;
		}

		echo '<hr style="margin:16px 0;" />';
		echo '<p><strong>' . esc_html__( 'Promotion plan (active promotions)', 'mp-commerce-promotions' ) . '</strong></p>';
		echo '<p class="description">' . esc_html__(
			'How active promotions would be selected for the current cart. Cart fees follow selected rows only.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<table class="widefat striped" style="max-width:100%;"><thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Promotion', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Eligible', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Selected', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Skipped reason', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Details', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $decisions as $decision ) {
			if ( ! $decision instanceof PromotionEvaluationDecision ) {
				continue;
			}

			$name = $decision->get_promotion_name();
			$pid  = $decision->get_promotion_id();
			if ( $pid !== null && $pid > 0 ) {
				$name .= ' (#' . $pid . ')';
			}

			$eligible_label = $decision->get_result()->is_eligible()
				? __( 'Yes', 'mp-commerce-promotions' )
				: __( 'No', 'mp-commerce-promotions' );
			$selected_label = $decision->is_selected()
				? __( 'Yes', 'mp-commerce-promotions' )
				: __( 'No', 'mp-commerce-promotions' );

			$reason = $decision->get_skipped_reason();
			$reason_label = $reason !== null && $reason !== ''
				? $this->format_plan_skipped_reason_label( $reason )
				: '—';

			if ( ! $decision->is_selected() && $reason === PromotionEvaluationDecision::REASON_NOT_ELIGIBLE ) {
				$trace_reason = $this->resolve_primary_ineligibility_reason_code( $decision->get_result() );
				if ( $trace_reason !== null && $trace_reason !== '' ) {
					$reason_label = $this->format_plan_skipped_reason_label( $trace_reason );
				}
			}

			$details = '—';
			$meta    = $decision->get_metadata();
			if ( $meta !== array() ) {
				$encoded = wp_json_encode( $meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				$details = is_string( $encoded ) ? $encoded : '—';
			} elseif ( ! $decision->get_result()->is_eligible() ) {
				$messages = $decision->get_result()->get_messages();
				if ( $messages !== array() ) {
					$details = (string) $messages[0];
				}
			}

			echo '<tr>';
			echo '<td>' . esc_html( $name ) . '</td>';
			echo '<td>' . esc_html( $eligible_label ) . '</td>';
			echo '<td>' . esc_html( $selected_label ) . '</td>';
			echo '<td><code>' . esc_html( $reason_label ) . '</code></td>';
			echo '<td><code style="font-size:11px;">' . esc_html( $details ) . '</code></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function format_plan_skipped_reason_label( string $reason ): string {
		$labels = array(
			PromotionEvaluationDecision::REASON_NOT_ELIGIBLE => 'not_eligible',
			PromotionEvaluationDecision::REASON_BLOCKED_EXCLUSIVE => 'blocked_by_exclusive_promotion',
			PromotionEvaluationDecision::REASON_STOPPED_PROCESSING => 'stopped_processing',
			PromotionEvaluationDecision::REASON_EXCLUDED_BY_SELECTED => 'excluded_by_selected_promotion',
			PromotionEvaluationDecision::REASON_MAX_APPLICATIONS_REACHED => 'max_applications_reached',
			ConditionTrace::REASON_USAGE_LIMIT_REACHED => 'usage_limit_reached',
			ConditionTrace::REASON_CUSTOMER_USAGE_LIMIT_REACHED => 'customer_usage_limit_reached',
			ConditionTrace::REASON_CUSTOMER_REQUIRED_FOR_USAGE_TRACKING => 'customer_required_for_usage_tracking',
			ConditionTrace::REASON_PROMOTION_NOT_STARTED => 'promotion_not_started',
			ConditionTrace::REASON_PROMOTION_EXPIRED => 'promotion_expired',
		);

		return $labels[ $reason ] ?? $reason;
	}

	private function resolve_primary_ineligibility_reason_code( EvaluationResult $result ): ?string {
		foreach ( $result->get_condition_traces() as $trace ) {
			if ( ! is_array( $trace ) || ! empty( $trace['passed'] ) ) {
				continue;
			}
			$reason_code = isset( $trace['reason_code'] ) ? trim( (string) $trace['reason_code'] ) : '';
			if ( $reason_code !== '' ) {
				return $reason_code;
			}
		}

		return null;
	}

	private function render_cart_preview_section( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		AdminSection::render(
			__( 'Cart preview', 'mp-commerce-promotions' ),
			function () use ( $id ): void {
				if ( $this->cart_context_builder === null ) {
					echo '<p class="description">' . esc_html__( 'WooCommerce is unavailable or the cart context builder is not loaded, so preview against the current cart is disabled.', 'mp-commerce-promotions' ) . '</p>';
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
					AdminNotice::error(
						$this->cart_preview_error,
						array(
							'inline'      => true,
							'dismissible' => false,
						)
					);
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

					$this->render_evaluation_trace_tables( $result );

					if ( $this->cart_preview_plan instanceof PromotionEvaluationPlan ) {
						$this->render_promotion_plan_table( $this->cart_preview_plan );
					}

					$action_results = $result->get_action_results();
					$this->render_free_gift_cart_preview_notes( $action_results, $result->is_eligible() );

					echo '<p><strong>' . esc_html__( 'Action previews (JSON)', 'mp-commerce-promotions' ) . '</strong></p>';
					$json = wp_json_encode( $action_results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					if ( ! is_string( $json ) ) {
						$json = '[]';
					}
					echo '<pre class="code" style="max-height:240px;overflow:auto;background:#f6f7f7;padding:12px;">' . esc_html( $json ) . '</pre>';
				}
			},
			null,
			array(
				'heading' => 'h2',
				'width'   => 'narrow',
			)
		);
	}

	private function render_simple_rule_builder_section( Promotion $promotion ): void {
		$id = $promotion->get_id();
		if ( $id === null || $id <= 0 ) {
			return;
		}

		$nonce_action = 'mp_cp_apply_rule_builder_' . $id;

		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:8px 0 16px;">';
		echo '<h3 style="margin-top:0;">' . esc_html__( 'Simple Rule Builder', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Build one condition and one action, then apply them to the JSON fields below. Name, status, priority, dates, and restrictions are not changed. Use the raw JSON fields for advanced edits.',
			'mp-commerce-promotions'
		) . '</p>';

		wp_nonce_field( $nonce_action, 'mp_cp_apply_rule_builder_nonce' );

		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_condition_type">' . esc_html__( 'Condition type', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_builder_condition_type" name="mp_cp_builder_condition_type">';
		echo '<option value="minimum_subtotal">' . esc_html__( 'Minimum subtotal', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="product_quantity">' . esc_html__( 'Product quantity', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="category_quantity">' . esc_html__( 'Category quantity', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="logged_in">' . esc_html__( 'Logged in', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="first_order">' . esc_html__( 'First order', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="customer_role">' . esc_html__( 'Customer role', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="billing_country">' . esc_html__( 'Billing country', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="customer_email_domain">' . esc_html__( 'Customer email domain', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="customer_redemption_count">' . esc_html__( 'Customer redemption count', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="minimum_cart_quantity">' . esc_html__( 'Minimum cart quantity', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="maximum_cart_quantity">' . esc_html__( 'Maximum cart quantity', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cart_quantity">' . esc_html__( 'Cart quantity', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_cart_quantity" name="mp_cp_builder_cart_quantity" min="1" step="1" />';
		echo '<p class="description">' . esc_html__( 'Total units across all cart lines (minimum_cart_quantity / maximum_cart_quantity).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_amount">' . esc_html__( 'Minimum subtotal amount', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_amount" name="mp_cp_builder_amount" min="0" step="0.01" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_product_id">' . esc_html__( 'Product ID', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_product_id" name="mp_cp_builder_product_id" min="1" step="1" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_category_id">' . esc_html__( 'Category ID', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_category_id" name="mp_cp_builder_category_id" min="1" step="1" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_operator">' . esc_html__( 'Operator', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_builder_operator" name="mp_cp_builder_operator">';
		foreach ( array( '>=', '>', '=', '<=', '<' ) as $op ) {
			echo '<option value="' . esc_attr( $op ) . '">' . esc_html( $op ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used for product/category quantity and customer redemption count conditions.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_quantity">' . esc_html__( 'Quantity', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_quantity" name="mp_cp_builder_quantity" min="0" step="1" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_roles">' . esc_html__( 'Customer roles', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_builder_roles" name="mp_cp_builder_roles" placeholder="customer, vip" />';
		echo '<p class="description">' . esc_html__( 'Comma-separated WordPress role slugs (customer_role condition).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_countries">' . esc_html__( 'Billing countries', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_builder_countries" name="mp_cp_builder_countries" placeholder="SE, NO, DK" />';
		echo '<p class="description">' . esc_html__( 'Comma-separated ISO country codes (billing_country condition).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_domains">' . esc_html__( 'Email domains', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_builder_domains" name="mp_cp_builder_domains" placeholder="example.com, company.com" />';
		echo '<p class="description">' . esc_html__( 'Comma-separated domains without @ (customer_email_domain condition).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_redemption_count">' . esc_html__( 'Redemption count', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_redemption_count" name="mp_cp_builder_redemption_count" min="0" step="1" />';
		echo '<p class="description">' . esc_html__( 'Compared with operator for customer_redemption_count (logged-in customers only).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_action_type">' . esc_html__( 'Action type', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_builder_action_type" name="mp_cp_builder_action_type">';
		echo '<option value="percentage_discount">' . esc_html__( 'Percentage discount', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="fixed_amount_discount">' . esc_html__( 'Fixed amount discount', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="free_shipping">' . esc_html__( 'Free shipping', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="cheapest_item_discount">' . esc_html__( 'Cheapest item discount', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="free_gift_product">' . esc_html__( 'Free gift product', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_gift_product_id">' . esc_html__( 'Gift product ID', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_gift_product_id" name="mp_cp_builder_gift_product_id" min="1" step="1" />';
		echo '<p class="description">' . esc_html__( 'WooCommerce product post ID to add as a free gift (free_gift_product action).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_gift_variation_id">' . esc_html__( 'Gift variation ID', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_gift_variation_id" name="mp_cp_builder_gift_variation_id" min="1" step="1" />';
		echo '<p class="description">' . esc_html__( 'Optional variation post ID when the gift is a variable product.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_gift_quantity">' . esc_html__( 'Gift quantity', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_gift_quantity" name="mp_cp_builder_gift_quantity" min="1" step="1" value="1" />';
		echo '<p class="description">' . esc_html__( 'How many gift units to add when the promotion applies (default 1).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cheapest_scope">' . esc_html__( 'Cheapest item scope', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_builder_cheapest_scope" name="mp_cp_builder_cheapest_scope">';
		echo '<option value="category">' . esc_html__( 'Category', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="products">' . esc_html__( 'Products', 'mp-commerce-promotions' ) . '</option>';
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Category scope matches product category term IDs; products scope matches product post IDs. Variation-specific targeting is not supported yet.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cheapest_category_ids">' . esc_html__( 'Cheapest item category IDs', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_builder_cheapest_category_ids" name="mp_cp_builder_cheapest_category_ids" placeholder="10, 15" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cheapest_product_ids">' . esc_html__( 'Cheapest item product IDs', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_builder_cheapest_product_ids" name="mp_cp_builder_cheapest_product_ids" placeholder="100, 101" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cheapest_required_quantity">' . esc_html__( 'Required quantity', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_cheapest_required_quantity" name="mp_cp_builder_cheapest_required_quantity" min="1" step="1" />';
		echo '<p class="description">' . esc_html__( 'Minimum eligible units in cart before discount applies (e.g. 3 for buy 2 get 1 free).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cheapest_discounted_quantity">' . esc_html__( 'Discounted quantity', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_cheapest_discounted_quantity" name="mp_cp_builder_cheapest_discounted_quantity" min="1" step="1" />';
		echo '<p class="description">' . esc_html__( 'How many cheapest units receive the discount (must be <= required quantity).', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_cheapest_discount_percentage">' . esc_html__( 'Discount percentage', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_cheapest_discount_percentage" name="mp_cp_builder_cheapest_discount_percentage" min="0.01" max="100" step="0.01" />';
		echo '<p class="description">' . esc_html__( '100 = free unit; 50 = half off cheapest unit(s). Applied as a cart fee on the storefront.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_percentage">' . esc_html__( 'Percentage', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_percentage" name="mp_cp_builder_percentage" min="0.01" max="100" step="0.01" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_builder_fixed_amount">' . esc_html__( 'Fixed discount amount', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_builder_fixed_amount" name="mp_cp_builder_fixed_amount" min="0.01" step="0.01" /></td></tr>';

		echo '</tbody></table>';

		echo '<p><button type="submit" class="button" name="mp_cp_action" value="apply_rule_builder">' . esc_html__( 'Apply builder to JSON', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</div>';
	}

	private function render_product_category_id_helper_section(): void {
		AdminSection::render(
			__( 'Product and category IDs', 'mp-commerce-promotions' ),
			function (): void {
				echo '<p>';
				echo '<a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=product' ) ) . '">' . esc_html__( 'Products list', 'mp-commerce-promotions' ) . '</a> ';
				echo '<a class="button" href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=product_cat&post_type=product' ) ) . '">' . esc_html__( 'Product categories list', 'mp-commerce-promotions' ) . '</a>';
				echo '</p>';

				$this->render_recent_products_id_helper_table();
				$this->render_recent_categories_id_helper_table();
			},
			__(
				'The product_quantity condition uses WooCommerce product post IDs. The category_quantity condition uses product category term IDs (taxonomy product_cat). For cheapest_item_discount: category scope uses product category term IDs; products scope uses product post IDs. Variation-specific targeting is not implemented yet.',
				'mp-commerce-promotions'
			),
			array(
				'heading' => 'h3',
				'width'   => 'wide',
			)
		);
	}

	private function render_recent_products_id_helper_table(): void {
		if ( ! post_type_exists( 'product' ) ) {
			return;
		}

		$rows = $this->fetch_recent_products_for_id_helper();
		if ( count( $rows ) === 0 ) {
			return;
		}

		echo '<h4 style="margin:1.5em 0 8px;">' . esc_html__( 'Recent published products', 'mp-commerce-promotions' ) . '</h4>';
		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Type / SKU', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Edit', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['id'] ) . '</td>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( $row['type_sku'] ) . '</td>';
			echo '<td>';
			if ( $row['edit_url'] !== '' ) {
				echo '<a href="' . esc_url( $row['edit_url'] ) . '">' . esc_html__( 'Edit', 'mp-commerce-promotions' ) . '</a>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_recent_categories_id_helper_table(): void {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}

		$rows = $this->fetch_recent_categories_for_id_helper();
		if ( count( $rows ) === 0 ) {
			return;
		}

		echo '<h4 style="margin:1.5em 0 8px;">' . esc_html__( 'Product categories', 'mp-commerce-promotions' ) . '</h4>';
		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Count', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Edit', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) $row['id'] ) . '</td>';
			echo '<td>' . esc_html( $row['name'] ) . '</td>';
			echo '<td>' . esc_html( (string) $row['count'] ) . '</td>';
			echo '<td>';
			if ( $row['edit_url'] !== '' ) {
				echo '<a href="' . esc_url( $row['edit_url'] ) . '">' . esc_html__( 'Edit', 'mp-commerce-promotions' ) . '</a>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * @return list<array{id: int, name: string, type_sku: string, edit_url: string}>
	 */
	private function fetch_recent_products_for_id_helper(): array {
		$rows = array();

		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'status'  => 'publish',
					'limit'   => 10,
					'orderby' => 'date',
					'order'   => 'DESC',
				)
			);

			if ( is_array( $products ) ) {
				foreach ( $products as $product ) {
					if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
						continue;
					}

					$product_id = (int) $product->get_id();
					if ( $product_id <= 0 ) {
						continue;
					}

					$rows[] = $this->format_product_id_helper_row( $product_id, $product );
				}
			}

			return $rows;
		}

		$posts = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		if ( ! is_array( $posts ) ) {
			return array();
		}

		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$product_id = (int) $post->ID;
			if ( $product_id <= 0 ) {
				continue;
			}

			$wc_product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
			$rows[]     = $this->format_product_id_helper_row( $product_id, $wc_product, $post );
		}

		return $rows;
	}

	/**
	 * @param object|null   $product WC_Product or null.
	 * @param \WP_Post|null $post    Fallback post when product object is unavailable.
	 * @return array{id: int, name: string, type_sku: string, edit_url: string}
	 */
	private function format_product_id_helper_row( int $product_id, $product, ?\WP_Post $post = null ): array {
		$name = '';
		if ( is_object( $product ) && method_exists( $product, 'get_name' ) ) {
			$name = (string) $product->get_name();
		} elseif ( $post instanceof \WP_Post ) {
			$name = $post->post_title;
		}

		$type_sku = '—';
		if ( is_object( $product ) && method_exists( $product, 'get_type' ) ) {
			$type = (string) $product->get_type();
			$sku  = method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '';
			if ( $sku !== '' ) {
				$type_sku = $type . ' / ' . $sku;
			} else {
				$type_sku = $type;
			}
		}

		$edit_url = get_edit_post_link( $product_id, 'raw' );

		return array(
			'id'       => $product_id,
			'name'     => $name,
			'type_sku' => $type_sku,
			'edit_url' => is_string( $edit_url ) ? $edit_url : '',
		);
	}

	/**
	 * @return list<array{id: int, name: string, count: int, edit_url: string}>
	 */
	private function fetch_recent_categories_for_id_helper(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 10,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$rows = array();

		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$term_id = (int) $term->term_id;
			if ( $term_id <= 0 ) {
				continue;
			}

			$edit_url = get_edit_term_link( $term, 'product_cat', 'product' );

			$rows[] = array(
				'id'       => $term_id,
				'name'     => $term->name,
				'count'    => (int) $term->count,
				'edit_url' => ( is_string( $edit_url ) && ! is_wp_error( $edit_url ) ) ? $edit_url : '',
			);
		}

		return $rows;
	}

	private function render_rule_templates_section(): void {
		AdminSection::render(
			__( 'Rule templates', 'mp-commerce-promotions' ),
			function (): void {
				echo '<h4>' . esc_html__( 'Conditions examples', 'mp-commerce-promotions' ) . '</h4>';

				$this->render_rule_template_readonly(
					__( 'Minimum subtotal', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"minimum_subtotal\",\"amount\":100}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Product quantity', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"product_quantity\",\"product_id\":123,\"operator\":\">=\",\"quantity\":2}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Category quantity', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"category_quantity\",\"category_id\":123,\"operator\":\">=\",\"quantity\":2}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Logged in', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"logged_in\"}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'First order', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"first_order\"}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Customer role', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"customer_role\",\"roles\":[\"customer\",\"vip\"]}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Billing country', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"billing_country\",\"countries\":[\"SE\",\"NO\",\"DK\"]}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Customer email domain', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"customer_email_domain\",\"domains\":[\"example.com\",\"company.com\"]}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Customer redemption count', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"customer_redemption_count\",\"operator\":\"<\",\"count\":1}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Minimum cart quantity', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"minimum_cart_quantity\",\"quantity\":3}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Maximum cart quantity', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"maximum_cart_quantity\",\"quantity\":10}\n]"
				);

				echo '<h4 style="margin-top:1.5em;">' . esc_html__( 'Actions examples', 'mp-commerce-promotions' ) . '</h4>';

				$this->render_rule_template_readonly(
					__( 'Percentage discount', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"percentage_discount\",\"percentage\":10}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Fixed amount discount', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"fixed_amount_discount\",\"amount\":25}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Free shipping', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"free_shipping\"}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Cheapest item discount (category)', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"cheapest_item_discount\",\"scope\":\"category\",\"category_ids\":[123],\"discount_percentage\":100,\"required_quantity\":3,\"discounted_quantity\":1}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Cheapest item discount (products)', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"cheapest_item_discount\",\"scope\":\"products\",\"product_ids\":[100,101],\"discount_percentage\":50,\"required_quantity\":2,\"discounted_quantity\":1}\n]"
				);
				$this->render_rule_template_readonly(
					__( 'Free gift product', 'mp-commerce-promotions' ),
					"[\n  {\"type\":\"free_gift_product\",\"product_id\":123,\"variation_id\":456,\"quantity\":1}\n]"
				);
			},
			__(
				'Copy these JSON examples into the fields below. JSON must be valid. Conditions are all required to pass. Only the first supported action per promotion is applied on the storefront. Product and category IDs must be numeric WordPress IDs. Woo cart context enriches line items with unit_price, item_key, and product_name when available. cheapest_item_discount is BOGO groundwork: it discounts the cheapest eligible units as a negative cart fee (does not add free products or change line prices). free_gift_product adds a configured product to the cart with mp_cp_free_gift metadata and zero line price (no negative fee). See docs/manual-free-gift-test.md. free_shipping is an MVP fee offset equal to the current shipping total. Simple Rule Builder supports cheapest_item_discount and free_gift_product.',
				'mp-commerce-promotions'
			),
			array(
				'heading' => 'h3',
				'width'   => 'wide',
			)
		);
	}

	private function render_rule_template_readonly( string $label, string $json ): void {
		echo '<p style="margin:12px 0 4px;"><strong>' . esc_html( $label ) . '</strong></p>';
		echo '<textarea class="large-text code" rows="4" readonly style="font-family:monospace;background:#f6f7f7;">';
		echo esc_textarea( $json );
		echo '</textarea>';
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

		echo '<h2 class="mp-cp-edit-section-title" style="margin:1.5em 0 0.5em;">' . esc_html__( 'Promotion details', 'mp-commerce-promotions' ) . '</h2>';
		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:0 0 16px;">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="mp_cp_name">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_name" name="promotion_name" maxlength="191" required value="' . esc_attr( $promotion->get_name() ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_desc">' . esc_html__( 'Description', 'mp-commerce-promotions' ) . '</label></th><td>';
		$desc = $promotion->get_description() ?? '';
		echo '<textarea class="large-text" rows="3" id="mp_cp_desc" name="promotion_description">' . esc_textarea( $desc ) . '</textarea></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_priority">' . esc_html__( 'Priority', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_priority" name="promotion_priority" min="0" step="1" value="' . esc_attr( (string) $promotion->get_priority() ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_starts">' . esc_html__( 'Starts at', 'mp-commerce-promotions' ) . '</label></th><td>';
		$starts = $promotion->get_starts_at() ?? '';
		echo '<input type="text" class="regular-text" id="mp_cp_starts" name="promotion_starts_at" value="' . esc_attr( $starts ) . '" placeholder="' . esc_attr__( 'YYYY-MM-DD HH:MM:SS or leave empty', 'mp-commerce-promotions' ) . '" /></td></tr>';

		echo '<tr><th scope="row"><label for="mp_cp_ends">' . esc_html__( 'Ends at', 'mp-commerce-promotions' ) . '</label></th><td>';
		$ends = $promotion->get_ends_at() ?? '';
		echo '<input type="text" class="regular-text" id="mp_cp_ends" name="promotion_ends_at" value="' . esc_attr( $ends ) . '" placeholder="' . esc_attr__( 'YYYY-MM-DD HH:MM:SS or leave empty', 'mp-commerce-promotions' ) . '" /></td></tr>';

		$usage_limit = $promotion->get_usage_limit();
		echo '<tr><th scope="row"><label for="mp_cp_usage_limit">' . esc_html__( 'Global usage limit', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_usage_limit" name="promotion_usage_limit" min="1" step="1" value="' . esc_attr( $usage_limit !== null ? (string) $usage_limit : '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Maximum total successful redemptions for this promotion (all customers). Leave empty for unlimited.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		$customer_usage_limit = $promotion->get_customer_usage_limit();
		echo '<tr><th scope="row"><label for="mp_cp_customer_usage_limit">' . esc_html__( 'Per-customer usage limit', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_customer_usage_limit" name="promotion_customer_usage_limit" min="1" step="1" value="' . esc_attr( $customer_usage_limit !== null ? (string) $customer_usage_limit : '' ) . '" />';
		echo '<p class="description">' . esc_html__( 'Maximum successful redemptions per customer account. Guests cannot satisfy per-customer limits. Leave empty for unlimited.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		echo '</tbody></table></div>';

		echo '<h2 class="mp-cp-edit-section-title" style="margin:1.5em 0 0.5em;">' . esc_html__( 'Application rules', 'mp-commerce-promotions' ) . '</h2>';
		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:0 0 16px;">';
		echo '<p class="description">' . esc_html__(
			'Controls how this promotion interacts with other eligible promotions in the evaluation plan. Stackable promotions can apply multiple cart fees; exclusions skip specific promotion IDs evaluated later.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$mode = $promotion->get_application_mode();
		echo '<tr><th scope="row"><label for="mp_cp_application_mode">' . esc_html__( 'Application mode', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<select id="mp_cp_application_mode" name="promotion_application_mode">';
		echo '<option value="' . esc_attr( PromotionApplicationMode::EXCLUSIVE ) . '"' . selected( $mode, PromotionApplicationMode::EXCLUSIVE, false ) . '>' . esc_html__( 'Exclusive', 'mp-commerce-promotions' ) . '</option>';
		echo '<option value="' . esc_attr( PromotionApplicationMode::STACKABLE ) . '"' . selected( $mode, PromotionApplicationMode::STACKABLE, false ) . '>' . esc_html__( 'Stackable', 'mp-commerce-promotions' ) . '</option>';
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Stop processing', 'mp-commerce-promotions' ) . '</th><td>';
		echo '<label><input type="checkbox" name="promotion_stop_processing" value="1" ' . checked( $promotion->should_stop_processing(), true, false ) . ' /> ';
		echo esc_html__( 'Stop evaluating further promotions after this one is selected in the plan', 'mp-commerce-promotions' ) . '</label></td></tr>';

		$max_apps = $promotion->get_max_applications();
		echo '<tr><th scope="row"><label for="mp_cp_max_applications">' . esc_html__( 'Max applications', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_max_applications" name="promotion_max_applications" min="1" step="1" value="' . esc_attr( $max_apps !== null ? (string) $max_apps : '' ) . '" />';
		echo '<p class="description">' . esc_html__(
			'Optional. Limits how many promotions may be selected in one cart evaluation plan (not per-customer usage). Leave empty for unlimited. When set, the plan cap is the minimum max_applications among selected promotions.',
			'mp-commerce-promotions'
		) . '</p></td></tr>';

		$excluded       = $promotion->get_excluded_promotion_ids();
		$excluded_value = $excluded !== array() ? implode( ',', array_map( 'strval', $excluded ) ) : '';
		$current_pid    = (int) ( $promotion->get_id() ?? 0 );
		echo '<tr><th scope="row">' . esc_html__( 'Excluded promotions', 'mp-commerce-promotions' ) . '</th><td>';
		echo '<p class="description">' . esc_html__(
			'When this promotion is selected, checked promotions and any manual IDs below will be skipped even if eligible. Your own promotion ID cannot be excluded.',
			'mp-commerce-promotions'
		) . '</p>';
		$picker = new PromotionPicker( $this->promotions );
		$picker->render_exclusion_checklist( $excluded, $current_pid );
		echo '<p style="margin-top:12px;"><label for="mp_cp_excluded_promotion_ids">' . esc_html__( 'Additional IDs (comma-separated)', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="mp_cp_excluded_promotion_ids" name="promotion_excluded_promotion_ids" value="' . esc_attr( $excluded_value ) . '" placeholder="' . esc_attr__( '12,15,20', 'mp-commerce-promotions' ) . '" /></p>';
		echo '</td></tr>';

		echo '</tbody></table></div>';

		echo '<h2 class="mp-cp-edit-section-title" style="margin:1.5em 0 0.5em;">' . esc_html__( 'Rules', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Configure conditions and actions with the simple builder, ID helper, templates, or raw JSON below.',
			'mp-commerce-promotions'
		) . '</p>';

		$this->render_simple_rule_builder_section( $promotion );
		$this->render_product_category_id_helper_section();
		$this->render_rule_templates_section();

		echo '<h3 style="margin:1.25em 0 0.5em;">' . esc_html__( 'Raw JSON editor', 'mp-commerce-promotions' ) . '</h3>';
		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:0 0 16px;">';
		echo '<table class="form-table" role="presentation"><tbody>';

		$cond_json = wp_json_encode( $promotion->get_conditions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $cond_json ) ) {
			$cond_json = '[]';
		}
		echo '<tr><th scope="row"><label for="mp_cp_cond">' . esc_html__( 'Conditions (JSON)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="8" id="mp_cp_cond" name="promotion_conditions_json">' . esc_textarea( $cond_json ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Copy a template above or enter a valid JSON array. All conditions must pass.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		$act_json = wp_json_encode( $promotion->get_actions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $act_json ) ) {
			$act_json = '[]';
		}
		echo '<tr><th scope="row"><label for="mp_cp_act">' . esc_html__( 'Actions (JSON)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="8" id="mp_cp_act" name="promotion_actions_json">' . esc_textarea( $act_json ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'Copy a template above or enter a valid JSON array. Only the first supported action per promotion is applied on the storefront.', 'mp-commerce-promotions' ) . '</p></td></tr>';

		$res_json = wp_json_encode( $promotion->get_restrictions(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $res_json ) ) {
			$res_json = '[]';
		}
		echo '<tr><th scope="row"><label for="mp_cp_res">' . esc_html__( 'Restrictions (JSON)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="8" id="mp_cp_res" name="promotion_restrictions_json">' . esc_textarea( $res_json ) . '</textarea></td></tr>';

		echo '</tbody></table></div>';

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
		echo '<p class="description">' . esc_html__( 'Manual codes are stored hashed; only the last four characters are shown after creation. Customers enter codes in the WooCommerce coupon field at checkout.', 'mp-commerce-promotions' ) . '</p>';

		if ( count( $codes ) === 0 ) {
			echo '<p>' . esc_html__( 'No promotion codes for this promotion yet.', 'mp-commerce-promotions' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:100%;">';
			echo '<thead><tr>';
			echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Batch ID', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Last 4', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Usage', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Created', 'mp-commerce-promotions' ) . '</th>';
			echo '<th scope="col">' . esc_html__( 'Actions', 'mp-commerce-promotions' ) . '</th>';
			echo '</tr></thead><tbody>';

			$codes_form_action = $this->edit_url( (string) $pid );

			foreach ( $codes as $code ) {
				if ( ! $code instanceof PromotionCode ) {
					continue;
				}
				echo '<tr>';
				echo '<td>' . esc_html( (string) ( $code->get_id() ?? '' ) ) . '</td>';
				$batch_id = $code->get_batch_id();
				echo '<td>' . esc_html( $batch_id !== null && $batch_id > 0 ? (string) $batch_id : '—' ) . '</td>';
				echo '<td>****' . esc_html( $code->get_code_last4() ) . '</td>';
				echo '<td>' . esc_html( $code->get_status() ) . '</td>';
				echo '<td>' . esc_html( $this->format_code_usage( $code ) ) . '</td>';
				echo '<td>' . esc_html( $code->get_expires_at() ?? '—' ) . '</td>';
				echo '<td>' . esc_html( $code->get_created_at() ?? '—' ) . '</td>';
				echo '<td>';
				$this->render_promotion_code_status_actions( $code, $pid, $codes_form_action );
				echo '</td>';
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

		$this->render_generate_code_batch_section( $pid, $form_action );

		echo '</div>';
	}

	private function render_generate_code_batch_section( int $pid, string $form_action ): void {
		if ( $this->batch_generator === null || $this->code_batches === null ) {
			return;
		}

		if ( $this->batch_generation_error !== null && $this->batch_generation_error !== '' ) {
			AdminNotice::error( $this->batch_generation_error, array( 'dismissible' => false ) );
		}

		$batches = $this->code_batches->find_for_promotion( $pid, 50 );

		echo '<h3>' . esc_html__( 'Generate Code Batch', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Generate up to 1,000 unique codes per batch. Full codes are shown once after generation; only hashes are stored.',
			'mp-commerce-promotions'
		) . '</p>';

		$batch_nonce_action = 'mp_cp_generate_code_batch_' . $pid;
		echo '<form method="post" action="' . esc_url( $form_action ) . '">';
		wp_nonce_field( $batch_nonce_action, 'mp_cp_generate_code_batch_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="mp_cp_batch_name">' . esc_html__( 'Batch name', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_batch_name" name="mp_cp_batch_name" maxlength="191" required /></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_batch_quantity">' . esc_html__( 'Quantity', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_batch_quantity" name="mp_cp_batch_quantity" min="1" max="' . esc_attr( (string) PromotionCodeBatch::MAX_QUANTITY ) . '" step="1" value="10" required />';
		echo '<p class="description">' . esc_html__( 'Maximum 1,000 codes per batch.', 'mp-commerce-promotions' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_batch_prefix">' . esc_html__( 'Prefix (optional)', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_batch_prefix" name="mp_cp_batch_prefix" maxlength="32" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Codes will be PREFIX-RANDOM when set; otherwise RANDOM only.', 'mp-commerce-promotions' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_batch_usage_limit">' . esc_html__( 'Usage limit', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="mp_cp_batch_usage_limit" name="mp_cp_batch_usage_limit" min="0" step="1" />';
		echo '<p class="description">' . esc_html__( 'Optional. Leave empty for unlimited.', 'mp-commerce-promotions' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="mp_cp_batch_expires_at">' . esc_html__( 'Expires at', 'mp-commerce-promotions' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="mp_cp_batch_expires_at" name="mp_cp_batch_expires_at" placeholder="' . esc_attr__( 'YYYY-MM-DD HH:MM:SS or leave empty', 'mp-commerce-promotions' ) . '" />';
		echo '</td></tr>';
		echo '</tbody></table>';
		echo '<p class="submit"><button type="submit" name="mp_cp_generate_code_batch_submit" value="1" class="button button-primary">' . esc_html__( 'Generate code batch', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';

		echo '<h3>' . esc_html__( 'Previous code batches', 'mp-commerce-promotions' ) . '</h3>';
		if ( count( $batches ) === 0 ) {
			echo '<p>' . esc_html__( 'No code batches for this promotion yet.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Name', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Quantity', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Code count', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Prefix', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Usage limit', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $batches as $batch ) {
			if ( ! $batch instanceof PromotionCodeBatch ) {
				continue;
			}
			$prefix       = $batch->get_code_prefix();
			$limit        = $batch->get_usage_limit();
			$batch_row_id = $batch->get_id();
			$code_count   = '—';
			if ( $batch_row_id !== null && $batch_row_id > 0 && $this->promotion_codes !== null ) {
				$code_count = (string) $this->promotion_codes->count_for_batch( $batch_row_id );
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $batch_row_id ?? '' ) ) . '</td>';
			echo '<td>';
			if ( $batch_row_id !== null && $batch_row_id > 0 ) {
				echo '<a href="' . esc_url( $this->batch_detail_url( $pid, $batch_row_id ) ) . '">' . esc_html( $batch->get_name() ) . '</a>';
			} else {
				echo esc_html( $batch->get_name() );
			}
			echo '</td>';
			echo '<td>' . esc_html( (string) $batch->get_quantity() ) . '</td>';
			echo '<td>' . esc_html( $code_count ) . '</td>';
			echo '<td>' . esc_html( $prefix !== null && $prefix !== '' ? $prefix : '—' ) . '</td>';
			echo '<td>' . esc_html( $limit !== null ? (string) $limit : '—' ) . '</td>';
			echo '<td>' . esc_html( $batch->get_expires_at() ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $batch->get_created_at() ?? '—' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function render_code_batch_generation_outcome(): void {
		if ( $this->batch_generation_outcome === null ) {
			return;
		}

		$outcome = $this->batch_generation_outcome;
		$batch   = $outcome->get_batch();
		$codes   = $outcome->get_plain_codes();
		$lines   = implode( "\n", $codes );

		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:16px 0;border-left:4px solid #00a32a;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Generated code batch', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p><strong>' . esc_html__( 'Batch ID', 'mp-commerce-promotions' ) . ':</strong> ';
		echo esc_html( (string) ( $batch->get_id() ?? '' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Batch name', 'mp-commerce-promotions' ) . ':</strong> ';
		echo esc_html( $batch->get_name() ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Codes generated', 'mp-commerce-promotions' ) . ':</strong> ';
		echo esc_html( (string) $outcome->get_inserted_count() );
		echo ' / ' . esc_html( (string) $outcome->get_requested_quantity() ) . '</p>';

		$warning = $outcome->get_warning();
		if ( $warning !== null && $warning !== '' ) {
			AdminNotice::warning(
				$warning,
				array(
					'inline'      => true,
					'dismissible' => false,
				)
			);
		}

		echo '<p class="description" style="font-weight:600;">' . esc_html__(
			'Copy these codes now. They will not be shown again.',
			'mp-commerce-promotions'
		) . '</p>';
		echo '<textarea class="large-text code" rows="' . esc_attr( (string) min( 20, max( 4, count( $codes ) + 1 ) ) ) . '" readonly style="font-family:monospace;">';
		echo esc_textarea( $lines );
		echo '</textarea>';

		$batch_id       = $batch->get_id();
		$promotion_id   = $batch->get_promotion_id();
		$generated_at   = $outcome->get_generated_at();
		$download_nonce = 'mp_cp_download_generated_codes_' . $promotion_id;
		$form_action    = $this->edit_url( (string) $promotion_id );
		$payload        = PromotionCodeBatchGenerationOutcome::encode_download_payload(
			$codes,
			$promotion_id,
			$batch_id !== null && $batch_id > 0 ? $batch_id : 0,
			$generated_at
		);

		if ( $payload !== '' && $batch_id !== null && $batch_id > 0 ) {
			echo '<form method="post" action="' . esc_url( $form_action ) . '" style="margin-top:12px;">';
			wp_nonce_field( $download_nonce, 'mp_cp_download_generated_codes_nonce' );
			echo '<input type="hidden" name="mp_cp_generated_codes_payload" value="' . esc_attr( $payload ) . '" />';
			echo '<p class="submit" style="margin:0;padding:0;">';
			echo '<button type="submit" name="mp_cp_download_generated_codes_submit" value="1" class="button">';
			echo esc_html__( 'Download CSV', 'mp-commerce-promotions' );
			echo '</button>';
			echo '</p>';
			echo '<p class="description">' . esc_html__(
				'Download the CSV before leaving or refreshing this page. Plain codes are not stored and cannot be downloaded later.',
				'mp-commerce-promotions'
			) . '</p>';
			echo '</form>';
		}

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

	private function resolve_batch_detail_view( Promotion $promotion ): void {
		if ( $this->code_batches === null ) {
			return;
		}

		if ( ! isset( $_GET['batch'] ) ) {
			return;
		}

		$batch_id_arg = (int) $_GET['batch'];
		if ( $batch_id_arg <= 0 ) {
			return;
		}

		$pid = $promotion->get_id();
		if ( $pid === null || $pid <= 0 ) {
			return;
		}

		$batch = $this->code_batches->find( $batch_id_arg );
		if ( $batch === null ) {
			$this->batch_detail_error = __( 'Code batch not found.', 'mp-commerce-promotions' );
			return;
		}

		if ( $batch->get_promotion_id() !== $pid ) {
			$this->batch_detail_error = __( 'That code batch does not belong to this promotion.', 'mp-commerce-promotions' );
			return;
		}

		$this->batch_detail = $batch;
	}

	private function render_batch_detail_section( PromotionCodeBatch $batch ): void {
		$batch_id = $batch->get_id();
		if ( $batch_id === null || $batch_id <= 0 ) {
			return;
		}

		$linked_count = 0;
		if ( $this->promotion_codes !== null ) {
			$linked_count = $this->promotion_codes->count_for_batch( $batch_id );
		}

		$prefix = $batch->get_code_prefix();
		$limit  = $batch->get_usage_limit();

		echo '<div class="card" style="max-width:100%;padding:12px 16px;margin:16px 0;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( 'Code batch detail', 'mp-commerce-promotions' ) . '</h2>';
		echo '<p class="description">' . esc_html__(
			'Read-only batch metadata and linked codes. Full codes are not stored and cannot be recovered.',
			'mp-commerce-promotions'
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		$this->render_batch_detail_row( __( 'Batch ID', 'mp-commerce-promotions' ), (string) $batch_id );
		$this->render_batch_detail_row( __( 'Batch UUID', 'mp-commerce-promotions' ), $batch->get_batch_uuid() );
		$this->render_batch_detail_row( __( 'Name', 'mp-commerce-promotions' ), $batch->get_name() );
		$this->render_batch_detail_row( __( 'Promotion ID', 'mp-commerce-promotions' ), (string) $batch->get_promotion_id() );
		$this->render_batch_detail_row( __( 'Quantity', 'mp-commerce-promotions' ), (string) $batch->get_quantity() );
		$this->render_batch_detail_row(
			__( 'Prefix', 'mp-commerce-promotions' ),
			$prefix !== null && $prefix !== '' ? $prefix : '—'
		);
		$this->render_batch_detail_row(
			__( 'Usage limit', 'mp-commerce-promotions' ),
			$limit !== null ? (string) $limit : '—'
		);
		$this->render_batch_detail_row(
			__( 'Expires at', 'mp-commerce-promotions' ),
			$batch->get_expires_at() ?? '—'
		);
		$this->render_batch_detail_row(
			__( 'Created by', 'mp-commerce-promotions' ),
			$this->format_batch_created_by( $batch->get_created_by() )
		);
		$this->render_batch_detail_row(
			__( 'Created at', 'mp-commerce-promotions' ),
			$batch->get_created_at() ?? '—'
		);
		$this->render_batch_detail_row(
			__( 'Linked code count', 'mp-commerce-promotions' ),
			(string) $linked_count
		);
		echo '</tbody></table>';

		$this->render_batch_code_status_actions( $batch );

		$this->render_batch_linked_codes_table(
			$batch->get_promotion_id(),
			$batch_id,
			$linked_count
		);

		echo '</div>';
	}


	private function render_batch_code_status_actions( PromotionCodeBatch $batch ): void {
		if ( $this->promotion_codes === null ) {
			return;
		}

		$batch_id = $batch->get_id();
		if ( $batch_id === null || $batch_id <= 0 ) {
			return;
		}

		$promotion_id   = $batch->get_promotion_id();
		$active_count   = $this->promotion_codes->count_for_batch_with_status( $batch_id, PromotionCode::STATUS_ACTIVE );
		$disabled_count = $this->promotion_codes->count_for_batch_with_status( $batch_id, PromotionCode::STATUS_DISABLED );

		if ( $active_count === 0 && $disabled_count === 0 ) {
			return;
		}

		$form_action  = $this->batch_detail_url( $promotion_id, $batch_id );
		$nonce_action = 'mp_cp_change_batch_code_status_' . $promotion_id . '_' . $batch_id;

		echo '<h3>' . esc_html__( 'Batch code status', 'mp-commerce-promotions' ) . '</h3>';
		echo '<p class="description">' . esc_html__(
			'Apply a status change to all matching codes in this batch. Expired codes are not re-enabled.',
			'mp-commerce-promotions'
		) . '</p>';

		$buttons = array();

		if ( $active_count > 0 ) {
			$buttons[] = array(
				'label'       => __( 'Disable active codes in this batch', 'mp-commerce-promotions' ),
				'from_status' => PromotionCode::STATUS_ACTIVE,
				'to_status'   => PromotionCode::STATUS_DISABLED,
			);
		}

		if ( $disabled_count > 0 ) {
			$buttons[] = array(
				'label'       => __( 'Enable disabled codes in this batch', 'mp-commerce-promotions' ),
				'from_status' => PromotionCode::STATUS_DISABLED,
				'to_status'   => PromotionCode::STATUS_ACTIVE,
			);
			$buttons[] = array(
				'label'       => __( 'Mark disabled codes expired', 'mp-commerce-promotions' ),
				'from_status' => PromotionCode::STATUS_DISABLED,
				'to_status'   => PromotionCode::STATUS_EXPIRED,
			);
		}

		foreach ( $buttons as $button ) {
			echo '<form method="post" action="' . esc_url( $form_action ) . '" style="display:inline-block;margin:0 8px 8px 0;">';
			wp_nonce_field( $nonce_action, 'mp_cp_change_batch_code_status_nonce' );
			echo '<input type="hidden" name="mp_cp_action" value="change_batch_code_status" />';
			echo '<input type="hidden" name="promotion_id" value="' . esc_attr( (string) $promotion_id ) . '" />';
			echo '<input type="hidden" name="batch_id" value="' . esc_attr( (string) $batch_id ) . '" />';
			echo '<input type="hidden" name="from_status" value="' . esc_attr( $button['from_status'] ) . '" />';
			echo '<input type="hidden" name="to_status" value="' . esc_attr( $button['to_status'] ) . '" />';
			echo '<button type="submit" class="button">' . esc_html( $button['label'] ) . '</button>';
			echo '</form>';
		}
	}


	private function render_batch_detail_row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}

	private function format_batch_created_by( ?int $user_id ): string {
		if ( $user_id === null || $user_id <= 0 ) {
			return '—';
		}

		$user = get_userdata( $user_id );
		if ( $user instanceof \WP_User ) {
			$display = $user->display_name !== '' ? $user->display_name : $user->user_login;
			return sprintf( '%s (#%d)', $display, $user_id );
		}

		return (string) $user_id;
	}

	private function render_batch_linked_codes_table( int $promotion_id, int $batch_id, int $linked_count ): void {
		echo '<h3>' . esc_html__( 'Linked codes', 'mp-commerce-promotions' ) . '</h3>';

		if ( $this->promotion_codes === null ) {
			echo '<p>' . esc_html__( 'Promotion codes are unavailable.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		if ( $linked_count === 0 ) {
			echo '<p>' . esc_html__( 'No codes are linked to this batch.', 'mp-commerce-promotions' ) . '</p>';
			return;
		}

		$codes = $this->promotion_codes->find_for_batch( $batch_id, self::BATCH_DETAIL_CODES_LIMIT );

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Code ID', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Last 4', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Status', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Usage', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Usage limit', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Expires', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Created', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Updated', 'mp-commerce-promotions' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Actions', 'mp-commerce-promotions' ) . '</th>';
		echo '</tr></thead><tbody>';

		$batch_form_action = $this->batch_detail_url( $promotion_id, $batch_id );

		foreach ( $codes as $code ) {
			if ( ! $code instanceof PromotionCode ) {
				continue;
			}
			$code_limit = $code->get_usage_limit();
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $code->get_id() ?? '' ) ) . '</td>';
			echo '<td>****' . esc_html( $code->get_code_last4() ) . '</td>';
			echo '<td>' . esc_html( $code->get_status() ) . '</td>';
			echo '<td>' . esc_html( $this->format_code_usage( $code ) ) . '</td>';
			echo '<td>' . esc_html( $code_limit !== null ? (string) $code_limit : '—' ) . '</td>';
			echo '<td>' . esc_html( $code->get_expires_at() ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $code->get_created_at() ?? '—' ) . '</td>';
			echo '<td>' . esc_html( $code->get_updated_at() ?? '—' ) . '</td>';
			echo '<td>';
			$this->render_promotion_code_status_actions( $code, $promotion_id, $batch_form_action, $batch_id );
			echo '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( $linked_count > count( $codes ) ) {
			echo '<p class="description">' . esc_html__(
				'Showing the latest linked codes only (up to 100).',
				'mp-commerce-promotions'
			) . '</p>';
		}
	}

	private function render_promotion_code_status_actions(
		PromotionCode $code,
		int $promotion_id,
		string $form_action,
		?int $batch_id = null
	): void {
		$code_id = $code->get_id();
		if ( $code_id === null || $code_id <= 0 ) {
			return;
		}

		$status  = $code->get_status();
		$buttons = array();

		if ( $status === PromotionCode::STATUS_ACTIVE ) {
			$buttons[] = array(
				'label'      => __( 'Disable', 'mp-commerce-promotions' ),
				'new_status' => PromotionCode::STATUS_DISABLED,
			);
		} elseif ( $status === PromotionCode::STATUS_DISABLED ) {
			$buttons[] = array(
				'label'      => __( 'Enable', 'mp-commerce-promotions' ),
				'new_status' => PromotionCode::STATUS_ACTIVE,
			);
			$buttons[] = array(
				'label'      => __( 'Mark expired', 'mp-commerce-promotions' ),
				'new_status' => PromotionCode::STATUS_EXPIRED,
			);
		} elseif ( $status === PromotionCode::STATUS_EXPIRED ) {
			$buttons[] = array(
				'label'      => __( 'Disable', 'mp-commerce-promotions' ),
				'new_status' => PromotionCode::STATUS_DISABLED,
			);
		}

		if ( count( $buttons ) === 0 ) {
			echo '—';
			return;
		}

		$nonce_action = 'mp_cp_change_promotion_code_status_' . $promotion_id . '_' . $code_id;

		foreach ( $buttons as $button ) {
			echo '<form method="post" action="' . esc_url( $form_action ) . '" style="display:inline-block;margin:0 4px 4px 0;">';
			wp_nonce_field( $nonce_action, 'mp_cp_change_promotion_code_status_nonce' );
			echo '<input type="hidden" name="mp_cp_action" value="change_promotion_code_status" />';
			echo '<input type="hidden" name="promotion_id" value="' . esc_attr( (string) $promotion_id ) . '" />';
			echo '<input type="hidden" name="promotion_code_id" value="' . esc_attr( (string) $code_id ) . '" />';
			echo '<input type="hidden" name="new_status" value="' . esc_attr( $button['new_status'] ) . '" />';
			if ( $batch_id !== null && $batch_id > 0 ) {
				echo '<input type="hidden" name="batch" value="' . esc_attr( (string) $batch_id ) . '" />';
			}
			echo '<button type="submit" class="button button-small">' . esc_html( $button['label'] ) . '</button>';
			echo '</form>';
		}
	}

	/**
	 * @param array<string, string> $extra_query
	 */
	private function redirect_after_code_status_change( int $promotion_id, array $extra_query ): void {
		$batch_id = isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 0;
		$base     = $batch_id > 0
			? $this->batch_detail_url( $promotion_id, $batch_id )
			: $this->edit_url( (string) $promotion_id );

		wp_safe_redirect( add_query_arg( $extra_query, $base ) );
		exit;
	}

	/**
	 * @param array<string, string> $extra_query
	 */
	private function redirect_after_batch_code_status_change( int $promotion_id, int $batch_id, array $extra_query ): void {
		$base = $batch_id > 0
			? $this->batch_detail_url( $promotion_id, $batch_id )
			: $this->edit_url( (string) $promotion_id );

		wp_safe_redirect( add_query_arg( $extra_query, $base ) );
		exit;
	}

	private function list_url(): string {
		return AdminUrl::list_promotions();
	}

	private function edit_url( string $promotion_identifier ): string {
		return AdminUrl::edit_promotion( $promotion_identifier );
	}

	private function batch_detail_url( int $promotion_id, int $batch_id ): string {
		return AdminUrl::batch_detail( $promotion_id, $batch_id );
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

	/**
	 * @return list<int>|null Null when input is invalid or includes the current promotion ID.
	 */
	private function parse_excluded_promotion_ids_from_post( int $current_promotion_id ): ?array {
		$ids = array();

		if ( isset( $_POST['promotion_excluded_check'] ) && is_array( $_POST['promotion_excluded_check'] ) ) {
			foreach ( $_POST['promotion_excluded_check'] as $raw_id ) {
				if ( ! is_scalar( $raw_id ) ) {
					continue;
				}
				$id = (int) $raw_id;
				if ( $id <= 0 ) {
					continue;
				}
				if ( $current_promotion_id > 0 && $id === $current_promotion_id ) {
					return null;
				}
				$ids[ $id ] = $id;
			}
		}

		$raw = isset( $_POST['promotion_excluded_promotion_ids'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['promotion_excluded_promotion_ids'] ) )
			: '';

		$raw = trim( $raw );
		if ( $raw !== '' ) {
			$parts = preg_split( '/[\s,]+/', $raw );
			if ( ! is_array( $parts ) ) {
				return null;
			}

			foreach ( $parts as $part ) {
				$part = trim( (string) $part );
				if ( $part === '' ) {
					continue;
				}
				if ( ! ctype_digit( $part ) ) {
					return null;
				}
				$id = (int) $part;
				if ( $id <= 0 ) {
					return null;
				}
				if ( $current_promotion_id > 0 && $id === $current_promotion_id ) {
					return null;
				}
				$ids[ $id ] = $id;
			}
		}

		if ( $ids === array() ) {
			return array();
		}

		$result = array_values( $ids );
		sort( $result, SORT_NUMERIC );

		return $result;
	}
}
