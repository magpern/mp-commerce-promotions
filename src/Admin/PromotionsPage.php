<?php
/**
 * WooCommerce submenu: promotions list, creation, and edit routing.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

use MP\CommercePromotions\Domain\Promotion;
use MP\CommercePromotions\Domain\PromotionRepository;
use MP\CommercePromotions\Service\PromotionService;
use RuntimeException;

final class PromotionsPage {

	private const NONCE_ACTION = 'mp_cp_create_promotion';

	private PromotionRepository $promotions;

	private PromotionService $promotion_service;

	private PromotionEditPage $edit_page;

	public function __construct(
		PromotionRepository $promotions,
		PromotionService $promotion_service,
		PromotionEditPage $edit_page
	) {
		$this->promotions         = $promotions;
		$this->promotion_service = $promotion_service;
		$this->edit_page         = $edit_page;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mp-commerce-promotions' ) );
		}

		if ( isset( $_GET['promotion'] ) ) {
			$promotion_key = sanitize_text_field( wp_unslash( (string) $_GET['promotion'] ) );
			if ( $promotion_key !== '' ) {
				$this->edit_page->render( $promotion_key );
				return;
			}
		}

		$this->handle_post_create();

		$list = $this->promotions->find_all( 100, 0 );

		echo '<div class="wrap">';
		$this->render_notices();
		echo '<h1>' . esc_html__( 'Commerce Promotions', 'mp-commerce-promotions' ) . '</h1>';
		echo '<p>' . esc_html__( 'Create draft promotions, then use Edit to change details, raw JSON rules, and status (via action buttons on the edit screen). Hard delete and visual rule builder are not implemented yet.', 'mp-commerce-promotions' ) . '</p>';

		$this->render_create_form();

		if ( count( $list ) === 0 ) {
			echo '<p>' . esc_html__( 'No promotions found.', 'mp-commerce-promotions' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped" style="max-width:100%;">';
		echo '<thead><tr>';
		$headers = array(
			__( 'ID', 'mp-commerce-promotions' ),
			__( 'Name', 'mp-commerce-promotions' ),
			__( 'Status', 'mp-commerce-promotions' ),
			__( 'Priority', 'mp-commerce-promotions' ),
			__( 'Usage', 'mp-commerce-promotions' ),
			__( 'Starts', 'mp-commerce-promotions' ),
			__( 'Ends', 'mp-commerce-promotions' ),
			__( 'Created', 'mp-commerce-promotions' ),
		);
		foreach ( $headers as $h ) {
			echo '<th scope="col">' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $list as $promo ) {
			if ( ! $promo instanceof Promotion ) {
				continue;
			}
			$pid = $promo->get_id();
			$edit  = '';
			if ( $pid !== null && $pid > 0 ) {
				$edit_url = add_query_arg(
					array(
						'page'      => 'mp-commerce-promotions',
						'promotion' => (string) $pid,
					),
					admin_url( 'admin.php' )
				);
				$edit = ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Edit', 'mp-commerce-promotions' ) . '</a>';
			}
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $pid ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $promo->get_name() ) . $edit . '</td>';
			echo '<td>' . esc_html( $promo->get_status() ) . '</td>';
			echo '<td>' . esc_html( (string) $promo->get_priority() ) . '</td>';
			echo '<td>' . esc_html( $this->format_usage( $promo ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_starts_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_ends_at() ) ) . '</td>';
			echo '<td>' . esc_html( $this->format_datetime( $promo->get_created_at() ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		echo '</div>';
	}

	private function handle_post_create(): void {
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
			return;
		}

		if ( ! isset( $_POST['mp_cp_create_promotion_submit'] ) ) {
			return;
		}

		$redirect_base = $this->promotions_admin_url();

		if ( ! isset( $_POST[ self::NONCE_ACTION ] ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'missing_nonce', $redirect_base ) );
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_ACTION ] ) );
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'invalid_nonce', $redirect_base ) );
			exit;
		}

		$name = '';
		if ( isset( $_POST['promotion_name'] ) ) {
			$name = sanitize_text_field( wp_unslash( (string) $_POST['promotion_name'] ) );
		}

		if ( $name === '' ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'empty_name', $redirect_base ) );
			exit;
		}

		try {
			$this->promotion_service->create_draft( $name, (int) get_current_user_id() );
		} catch ( RuntimeException $e ) {
			wp_safe_redirect( add_query_arg( 'mp_cp_error', 'create_failed', $redirect_base ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'mp_cp_created', '1', $redirect_base ) );
		exit;
	}

	private function promotions_admin_url(): string {
		return admin_url( 'admin.php?page=mp-commerce-promotions' );
	}

	private function render_notices(): void {
		if ( isset( $_GET['mp_cp_created'] ) && sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_created'] ) ) === '1' ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Draft promotion created.', 'mp-commerce-promotions' ) . '</p></div>';
		}

		if ( isset( $_GET['mp_cp_error'] ) ) {
			$code = sanitize_text_field( wp_unslash( (string) $_GET['mp_cp_error'] ) );
			$msg  = $this->error_message_for_code( $code );
			if ( $msg !== '' ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
			}
		}
	}

	private function error_message_for_code( string $code ): string {
		switch ( $code ) {
			case 'invalid_nonce':
			case 'missing_nonce':
				return __( 'Security check failed. Please try again.', 'mp-commerce-promotions' );
			case 'empty_name':
				return __( 'Please enter a promotion name.', 'mp-commerce-promotions' );
			case 'create_failed':
				return __( 'Could not create the promotion. Please try again.', 'mp-commerce-promotions' );
			case 'promotion_not_found':
				return __( 'That promotion could not be found.', 'mp-commerce-promotions' );
			default:
				return '';
		}
	}

	private function render_create_form(): void {
		echo '<h2>' . esc_html__( 'Create draft promotion', 'mp-commerce-promotions' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $this->promotions_admin_url() ) . '" style="margin-bottom:1.5em;">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_ACTION );
		echo '<p><label for="mp_cp_promotion_name">' . esc_html__( 'Promotion name', 'mp-commerce-promotions' ) . '</label><br />';
		echo '<input type="text" class="regular-text" id="mp_cp_promotion_name" name="promotion_name" maxlength="191" required /></p>';
		echo '<p><button type="submit" name="mp_cp_create_promotion_submit" value="1" class="button button-primary">' . esc_html__( 'Create draft promotion', 'mp-commerce-promotions' ) . '</button></p>';
		echo '</form>';
	}

	private function format_usage( Promotion $promo ): string {
		$used = $promo->get_usage_count();
		$lim  = $promo->get_usage_limit();

		if ( $lim === null ) {
			return sprintf(
				/* translators: 1: usage count */
				__( '%1$s / —', 'mp-commerce-promotions' ),
				(string) $used
			);
		}

		return sprintf(
			/* translators: 1: usage count, 2: usage limit */
			__( '%1$s / %2$s', 'mp-commerce-promotions' ),
			(string) $used,
			(string) $lim
		);
	}

	private function format_datetime( ?string $value ): string {
		if ( $value === null || $value === '' ) {
			return '—';
		}

		return $value;
	}
}
