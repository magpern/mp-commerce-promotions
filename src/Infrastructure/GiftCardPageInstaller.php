<?php
/**
 * Creates optional storefront pages for gift card customer UX.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Infrastructure;

use MP\CommercePromotions\Service\Settings;

final class GiftCardPageInstaller {

	public static function maybe_create_balance_page( Settings $settings ): void {
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return;
		}

		$page_id = $settings->gift_card_balance_page_id();
		if ( $page_id > 0 && get_post_status( $page_id ) ) {
			return;
		}

		$existing = get_page_by_path( 'gift-card-balance' );
		if ( $existing instanceof \WP_Post ) {
			$settings->set_gift_card_balance_page_id( (int) $existing->ID );
			return;
		}

		$new_id = wp_insert_post(
			array(
				'post_title'   => __( 'Gift card balance', 'mp-commerce-promotions' ),
				'post_name'    => 'gift-card-balance',
				'post_content' => '[mp_cp_gift_card_balance]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			),
			true
		);

		if ( ! is_wp_error( $new_id ) && (int) $new_id > 0 ) {
			$settings->set_gift_card_balance_page_id( (int) $new_id );
		}
	}
}
