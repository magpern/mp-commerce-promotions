<?php
/**
 * Unified cart/checkout gift card + store credit redemption UI.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Woo;

use MP\CommercePromotions\GiftCard\GiftCard;
use MP\CommercePromotions\GiftCard\GiftCardLedger;
use MP\CommercePromotions\GiftCard\GiftCardRedemptionService;
use MP\CommercePromotions\GiftCard\StoreCreditCheckoutService;

final class GiftCardRedemptionCheckout {

	private const NONCE_GIFT = 'mp_cp_apply_gift_card';

	private const NONCE_GIFT_FIELD = 'mp_cp_gift_card_nonce';

	private const NONCE_SC = 'mp_cp_apply_store_credit';

	private const NONCE_SC_FIELD = 'mp_cp_store_credit_nonce';

	private GiftCardLedger $ledger;

	private GiftCardRedemptionService $redemption;

	private GiftCardCartApplier $gift_applier;

	private StoreCreditCheckoutService $store_credit;

	private StoreCreditCartApplier $sc_applier;

	private static bool $panel_rendered = false;

	public function __construct(
		GiftCardLedger $ledger,
		GiftCardCartApplier $gift_applier,
		StoreCreditCheckoutService $store_credit,
		StoreCreditCartApplier $sc_applier,
		?GiftCardRedemptionService $redemption = null
	) {
		$this->ledger       = $ledger;
		$this->gift_applier = $gift_applier;
		$this->store_credit = $store_credit;
		$this->sc_applier   = $sc_applier;
		$this->redemption   = $redemption ?? new GiftCardRedemptionService( $ledger );
	}

	public function register(): void {
		$post_hooks = array(
			'woocommerce_before_cart',
			'woocommerce_before_checkout_form',
		);
		foreach ( $post_hooks as $hook ) {
			add_action( $hook, array( $this, 'maybe_handle_post' ), 5 );
		}
		// Inside the cart form coupon/actions row (no nested <form>; uses parent cart form submit).
		add_action( 'woocommerce_cart_coupon', array( $this, 'render_cart_inline' ), 20 );
		add_action( 'woocommerce_cart_actions', array( $this, 'render_cart_inline_fallback' ), 5 );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_panel' ), 12 );
	}

	/**
	 * Whether the redemption accordion should render expanded on first paint.
	 *
	 * @param array<string, mixed>|null $gift_applied
	 * @param array<string, mixed>|null $sc_applied
	 */
	public static function should_expand_accordion(
		?array $gift_applied,
		?array $sc_applied,
		float $sc_balance,
		bool $can_apply_store_credit,
		bool $has_checkout_notices
	): bool {
		if ( $gift_applied !== null ) {
			return true;
		}

		if ( $sc_applied !== null && (float) ( $sc_applied['applied_amount'] ?? 0 ) > 0 ) {
			return true;
		}

		if ( $has_checkout_notices ) {
			return true;
		}

		if ( $can_apply_store_credit && $sc_balance > 0 ) {
			return true;
		}

		return false;
	}

	public function maybe_handle_post(): void {
		try {
			$this->handle_gift_card_post();
			$this->handle_store_credit_post();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( '[mp-commerce-promotions] GiftCardRedemptionCheckout: ' . $e->getMessage() );
			}
		}
	}

	private function handle_gift_card_post(): void {
		if ( ! isset( $_POST['mp_cp_gift_card_action'] ) || ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_gift_card_action'] ) );
		if (
			! isset( $_POST[ self::NONCE_GIFT_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_GIFT_FIELD ] ) ),
				self::NONCE_GIFT
			)
		) {
			$this->notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		if ( $action === 'remove' ) {
			GiftCardSession::clear();
			$this->notice( __( 'Gift card removed.', 'mp-commerce-promotions' ), 'success' );
			$this->recalculate();
			return;
		}

		if ( $action !== 'apply' ) {
			return;
		}

		$plain = isset( $_POST['mp_cp_gift_card_code'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['mp_cp_gift_card_code'] ) )
			: '';
		if ( $plain === '' ) {
			$this->notice( __( 'Enter a gift card code.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$card = $this->ledger->find_by_plain_code( $plain );
		if ( $card === null ) {
			$this->notice( __( 'We could not find a gift card with that code. Check the code from your email and try again.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$redeem_error = $this->redemption->redeemability_error( $card );
		if ( $redeem_error !== null ) {
			$this->notice( $redeem_error, 'error' );
			return;
		}

		$id = $card->get_id();
		if ( $id === null || $id <= 0 ) {
			$this->notice( __( 'This gift card is not valid or cannot be used on this order.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$payable = $this->payable_total();
		$amount  = $this->redemption->preview_apply_amount( $card, $payable );
		if ( $amount <= 0 ) {
			$this->notice( __( 'No balance can be applied to this cart.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		GiftCardSession::set(
			array(
				'gift_card_id'   => $id,
				'code_last4'     => $card->get_code_last4(),
				'applied_amount' => $amount,
			)
		);
		GiftCardMyAccount::stash_reveal_code( $plain );

		$remaining = GiftCard::money( $card->get_balance() - $amount );
		$this->notice(
			sprintf(
				/* translators: 1: applied amount, 2: estimated remaining balance */
				__( 'Gift card applied: %1$s. Estimated remaining balance on this card: %2$s.', 'mp-commerce-promotions' ),
				function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : (string) $amount,
				function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $remaining ) ) : (string) $remaining
			),
			'success'
		);
		$this->recalculate();
	}

	private function handle_store_credit_post(): void {
		if ( ! isset( $_POST['mp_cp_store_credit_action'] ) || ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		if ( ! $this->store_credit->can_apply() ) {
			return;
		}

		if (
			! isset( $_POST[ self::NONCE_SC_FIELD ] )
			|| ! wp_verify_nonce(
				sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_SC_FIELD ] ) ),
				self::NONCE_SC
			)
		) {
			$this->notice( __( 'Security check failed. Please try again.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$action = sanitize_key( wp_unslash( (string) $_POST['mp_cp_store_credit_action'] ) );
		if ( $action === 'remove' ) {
			$this->store_credit->remove_from_session();
			$this->notice( __( 'Store credit removed.', 'mp-commerce-promotions' ), 'success' );
			$this->recalculate();
			return;
		}

		if ( $action !== 'apply' ) {
			return;
		}

		$customer_id = $this->store_credit->get_current_customer_id();
		$currency    = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
		if ( ! $this->store_credit->apply_to_session( $customer_id, $currency, $this->payable_total() ) ) {
			$this->notice( __( 'No store credit balance can be applied to this cart.', 'mp-commerce-promotions' ), 'error' );
			return;
		}

		$applied = $this->store_credit->get_applied_from_session();
		$amount  = $applied !== null ? (float) $applied['applied_amount'] : 0.0;
		$this->notice(
			sprintf(
				/* translators: %s: formatted amount */
				__( 'Store credit applied: %s. Any unused balance stays in your wallet.', 'mp-commerce-promotions' ),
				function_exists( 'wc_price' ) ? wp_strip_all_tags( wc_price( $amount ) ) : (string) $amount
			),
			'success'
		);
		$this->recalculate();
	}


	public function render_cart_inline(): void {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		$this->render_panel( true );
	}

	/**
	 * When coupons are disabled, {@see woocommerce_cart_coupon} does not run.
	 */
	public function render_cart_inline_fallback(): void {
		if ( function_exists( 'wc_coupons_enabled' ) && wc_coupons_enabled() ) {
			return;
		}

		$this->render_cart_inline();
	}

	public function render_checkout_panel(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		$this->render_panel( false );
	}

	/**
	 * @param bool $cart_inline Render inside cart form (no nested forms).
	 */
	private function render_panel( bool $cart_inline ): void {
		if ( self::$panel_rendered || ! function_exists( 'WC' ) || ! CartSessionHelper::has_wc_session() ) {
			return;
		}

		self::$panel_rendered = true;

		GiftCardCustomerAssets::enqueue();

		$gift_applied = GiftCardSession::get();
		$can_sc       = $this->store_credit->can_apply();
		$sc_applied   = $can_sc ? $this->store_credit->get_applied_from_session() : null;
		$sc_balance   = 0.0;
		if ( $can_sc ) {
			$sc_balance = $this->store_credit->get_available_balance(
				$this->store_credit->get_current_customer_id(),
				function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR'
			);
		}

		$expand = self::should_expand_accordion(
			$gift_applied,
			$sc_applied,
			$sc_balance,
			$can_sc,
			$this->has_checkout_notices()
		);

		$body_id = 'mp-cp-credit-accordion-body';

		if ( $cart_inline ) {
			echo '<div class="mp-cp-credit-inline mp-cp-credit-cart-row">';
		}

		$accordion_class = 'mp-cp-credit-accordion mp-cp-gift-card-checkout';
		if ( $cart_inline ) {
			$accordion_class .= ' mp-cp-credit-accordion--cart';
		}
		echo '<details class="' . esc_attr( $accordion_class ) . '"' . ( $expand ? ' open' : '' ) . '>';
		$this->render_accordion_summary( $gift_applied, $sc_applied, $sc_balance, $can_sc );
		echo '<div id="' . esc_attr( $body_id ) . '" class="mp-cp-credit-accordion__body">';
		if ( ! $cart_inline ) {
			$this->render_applied_chips( $gift_applied, $sc_applied );
		}
		$this->render_gift_card_form( $gift_applied, $cart_inline );
		if ( $can_sc ) {
			$this->render_store_credit_form( $sc_applied, $sc_balance, $cart_inline );
		}
		echo '<p class="mp-cp-credit-help">';
		echo esc_html__( 'Enter a gift card code or use available store credit.', 'mp-commerce-promotions' );
		echo ' <span class="mp-cp-credit-help__hint" title="'
			. esc_attr__( 'Full codes are not stored after delivery.', 'mp-commerce-promotions' )
			. '">' . esc_html__( 'Partial payment is supported.', 'mp-commerce-promotions' ) . '</span>';
		echo '</p>';
		echo '</div></details>';

		if ( $cart_inline ) {
			echo '</div>';
		}
	}

	/**
	 * @param array<string, mixed>|null $gift_applied
	 * @param array<string, mixed>|null $sc_applied
	 */
	private function render_accordion_summary(
		?array $gift_applied,
		?array $sc_applied,
		float $sc_balance,
		bool $can_sc
	): void {
		echo '<summary class="mp-cp-credit-accordion__toggle">';
		echo '<div class="mp-cp-credit-accordion__header">';
		echo '<div class="mp-cp-credit-accordion__header-main">';
		echo '<span class="mp-cp-credit-accordion__chevron" aria-hidden="true"></span>';
		echo '<span class="mp-cp-credit-accordion__icon" aria-hidden="true">';
		echo '<svg width="16" height="16" viewBox="0 0 24 24" focusable="false"><path fill="currentColor" d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 14H4V8h16v10zm-8-7c1.66 0 3-1.34 3-3S13.66 5 12 5 9 6.34 9 8s1.34 3 3 3z"/></svg>';
		echo '</span>';
		echo '<span class="mp-cp-credit-accordion__title">' . esc_html__( 'Gift card or store credit', 'mp-commerce-promotions' ) . '</span>';
		echo '</div>';
		echo '<span class="mp-cp-credit-accordion__summary-text">';
		$this->render_summary_meta( $gift_applied, $sc_applied, $sc_balance, $can_sc );
		echo '</span>';
		echo '</div>';
		echo '</summary>';
	}

	/**
	 * @param array<string, mixed>|null $gift_applied
	 * @param array<string, mixed>|null $sc_applied
	 */
	private function render_summary_meta(
		?array $gift_applied,
		?array $sc_applied,
		float $sc_balance,
		bool $can_sc
	): void {
		$parts = array();

		if ( $gift_applied !== null ) {
			$parts[] = '<span class="mp-cp-credit-chip mp-cp-credit-chip--summary">' . esc_html(
				sprintf(
					/* translators: %s: last four digits */
					__( 'Gift card ****%s applied', 'mp-commerce-promotions' ),
					(string) ( $gift_applied['code_last4'] ?? '' )
				)
			) . '</span>';
		}

		if ( $sc_applied !== null && (float) ( $sc_applied['applied_amount'] ?? 0 ) > 0 ) {
			$parts[] = esc_html__( 'Store credit applied', 'mp-commerce-promotions' );
		} elseif ( $can_sc ) {
			if ( $sc_balance > 0 ) {
				$parts[] = esc_html(
					sprintf(
						/* translators: %s: formatted balance */
						__( 'Available: %s', 'mp-commerce-promotions' ),
						$this->format_price( $sc_balance )
					)
				);
			} else {
				$parts[] = '<span class="mp-cp-credit-accordion__meta-muted">' . esc_html__( 'No store credit balance', 'mp-commerce-promotions' ) . '</span>';
			}
		}

		if ( $parts === array() ) {
			echo esc_html__( 'Have a gift card?', 'mp-commerce-promotions' );
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- segments escaped above.
		echo implode( '<span class="mp-cp-credit-accordion__meta-sep"> · </span>', $parts );
	}

	/**
	 * @param array<string, mixed>|null $gift_applied
	 * @param array<string, mixed>|null $sc_applied
	 */
	private function render_applied_chips( ?array $gift_applied, ?array $sc_applied ): void {
		if ( $gift_applied !== null ) {
			$amount = (float) ( $gift_applied['applied_amount'] ?? 0 );
			echo '<div class="mp-cp-credit-chip mp-cp-credit-chip--gift">';
			echo '<span class="mp-cp-credit-chip__label">';
			printf(
				/* translators: %s: last four digits */
				esc_html__( 'Gift card ****%s applied', 'mp-commerce-promotions' ),
				esc_html( (string) ( $gift_applied['code_last4'] ?? '' ) )
			);
			echo '</span>';
			if ( $amount > 0 ) {
				echo '<span class="mp-cp-credit-chip__amount">';
				printf(
					/* translators: %s: formatted amount */
					esc_html__( 'Applying up to %s', 'mp-commerce-promotions' ),
					esc_html( $this->format_price( $amount ) )
				);
				echo '</span>';
			}
			echo '</div>';
		}

		if ( $sc_applied !== null && (float) ( $sc_applied['applied_amount'] ?? 0 ) > 0 ) {
			$sc_amount = (float) $sc_applied['applied_amount'];
			echo '<div class="mp-cp-credit-chip mp-cp-credit-chip--store">';
			echo '<span class="mp-cp-credit-chip__label">' . esc_html__( 'Store credit applied', 'mp-commerce-promotions' ) . '</span>';
			echo '<span class="mp-cp-credit-chip__amount">';
			printf(
				/* translators: %s: formatted amount */
				esc_html__( 'Applying up to %s', 'mp-commerce-promotions' ),
				esc_html( $this->format_price( $sc_amount ) )
			);
			echo '</span></div>';
		}
	}

	/**
	 * @param array<string, mixed>|null $gift_applied
	 */
	private function render_gift_card_form( ?array $gift_applied, bool $cart_inline = false ): void {
		echo '<div class="mp-cp-credit-accordion__section">';

		if ( $gift_applied !== null ) {
			if ( ! $cart_inline ) {
				echo '<form method="post" class="mp-cp-credit-accordion__form">';
			}
			wp_nonce_field( self::NONCE_GIFT, self::NONCE_GIFT_FIELD );
			echo '<input type="hidden" name="mp_cp_gift_card_action" value="remove" />';
			echo '<button type="submit" class="mp-cp-credit-link">' . esc_html__( 'Remove gift card', 'mp-commerce-promotions' ) . '</button>';
			if ( ! $cart_inline ) {
				echo '</form>';
			}
		} else {
			if ( ! $cart_inline ) {
				echo '<form method="post" class="mp-cp-credit-accordion__form mp-cp-credit-form-row mp-cp-credit-accordion__form-row mp-cp-credit-accordion__form--inline">';
			} else {
				echo '<div class="mp-cp-credit-accordion__form mp-cp-credit-form-row mp-cp-credit-accordion__form-row mp-cp-credit-accordion__form--inline">';
			}
			wp_nonce_field( self::NONCE_GIFT, self::NONCE_GIFT_FIELD );
			echo '<input type="hidden" name="mp_cp_gift_card_action" value="apply" />';
			echo '<label class="screen-reader-text" for="mp_cp_gift_card_code">' . esc_html__( 'Gift card code', 'mp-commerce-promotions' ) . '</label>';
			echo '<input type="text" class="input-text" name="mp_cp_gift_card_code" id="mp_cp_gift_card_code" autocomplete="off" placeholder="'
				. esc_attr__( 'Gift card code', 'mp-commerce-promotions' ) . '" />';
			echo '<button type="submit" class="button">' . esc_html__( 'Apply gift card', 'mp-commerce-promotions' ) . '</button>';
			echo $cart_inline ? '</div>' : '</form>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string, mixed>|null $sc_applied
	 */
	private function render_store_credit_form( ?array $sc_applied, float $sc_balance, bool $cart_inline = false ): void {
		echo '<div class="mp-cp-credit-accordion__section mp-cp-credit-accordion__section--wallet">';

		if ( $sc_applied !== null && (float) ( $sc_applied['applied_amount'] ?? 0 ) > 0 ) {
			if ( ! $cart_inline ) {
				echo '<form method="post" class="mp-cp-credit-accordion__form">';
			}
			echo '<input type="hidden" name="mp_cp_store_credit_action" value="remove" />';
			wp_nonce_field( self::NONCE_SC, self::NONCE_SC_FIELD );
			echo '<button type="submit" class="mp-cp-credit-link">' . esc_html__( 'Remove store credit', 'mp-commerce-promotions' ) . '</button>';
			if ( ! $cart_inline ) {
				echo '</form>';
			}
		} elseif ( $sc_balance > 0 ) {
			if ( $cart_inline ) {
				echo '<div class="mp-cp-credit-accordion__form mp-cp-credit-form-row mp-cp-credit-accordion__form-row mp-cp-credit-accordion__form--inline">';
			} else {
				echo '<form method="post" class="mp-cp-credit-accordion__form mp-cp-credit-form-row mp-cp-credit-accordion__form-row mp-cp-credit-accordion__form--inline">';
			}
			echo '<input type="hidden" name="mp_cp_store_credit_action" value="apply" />';
			wp_nonce_field( self::NONCE_SC, self::NONCE_SC_FIELD );
			echo '<span class="mp-cp-credit-accordion__wallet-label">';
			printf(
				/* translators: %s: balance */
				esc_html__( 'Wallet balance: %s', 'mp-commerce-promotions' ),
				esc_html( $this->format_price( $sc_balance ) )
			);
			echo '</span>';
			echo '<button type="submit" class="button">' . esc_html__( 'Apply store credit', 'mp-commerce-promotions' ) . '</button>';
			echo $cart_inline ? '</div>' : '</form>';
		}

		echo '</div>';
	}

	private function format_price( float $amount ): string {
		if ( function_exists( 'wc_price' ) ) {
			return wp_strip_all_tags( wc_price( $amount ) );
		}

		return number_format( $amount, 2 );
	}

	private function has_checkout_notices(): bool {
		if ( ! function_exists( 'wc_get_notices' ) ) {
			return false;
		}

		foreach ( array( 'error', 'success' ) as $type ) {
			$notices = wc_get_notices( $type );
			if ( is_array( $notices ) && $notices !== array() ) {
				return true;
			}
		}

		return false;
	}


	private function payable_total(): float {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) ) {
			return $this->gift_applier->estimate_cart_payable( WC()->cart );
		}

		return 0.0;
	}

	private function notice( string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, $type );
		}
	}

	private function recalculate(): void {
		if ( function_exists( 'WC' ) && isset( WC()->cart ) && is_object( WC()->cart ) && method_exists( WC()->cart, 'calculate_totals' ) ) {
			WC()->cart->calculate_totals();
		}
	}
}
