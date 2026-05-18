<?php
/**
 * Lightweight AJAX search for Campaign Builder targeting pickers.
 *
 * @package MP\CommercePromotions
 */

declare(strict_types=1);

namespace MP\CommercePromotions\Admin;

final class CampaignBuilderAjax {

	private const NONCE_ACTION = 'mp_cb_search';

	public function register(): void {
		add_action( 'wp_ajax_mp_cb_search_products', array( $this, 'search_products' ) );
		add_action( 'wp_ajax_mp_cb_search_categories', array( $this, 'search_categories' ) );
	}

	public function search_products(): void {
		$this->authorize();
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		if ( strlen( $term ) < 2 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$items = array();
		if ( function_exists( 'wc_get_products' ) ) {
			$products = wc_get_products(
				array(
					'limit'   => 20,
					'status'  => 'publish',
					'search'  => $term,
					'return'  => 'objects',
					'orderby' => 'relevance',
				)
			);
			foreach ( $products as $product ) {
				if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
					continue;
				}
				$id = (int) $product->get_id();
				if ( $id <= 0 ) {
					continue;
				}
				$items[] = array(
					'id'    => $id,
					'label' => (string) $product->get_name(),
					'sku'   => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
				);
			}
		} else {
			$query = new \WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					's'              => $term,
					'posts_per_page' => 20,
					'fields'         => 'ids',
				)
			);
			foreach ( $query->posts as $post_id ) {
				$id = (int) $post_id;
				if ( $id > 0 ) {
					$items[] = array(
						'id'    => $id,
						'label' => get_the_title( $id ),
						'sku'   => '',
					);
				}
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	public function search_categories(): void {
		$this->authorize();
		$term = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		if ( strlen( $term ) < 1 ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$items = array();
		if ( function_exists( 'get_terms' ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => 'product_cat',
					'hide_empty' => false,
					'number'     => 25,
					'search'     => $term,
				)
			);
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term_obj ) {
					if ( ! isset( $term_obj->term_id, $term_obj->name ) ) {
						continue;
					}
					$items[] = array(
						'id'    => (int) $term_obj->term_id,
						'label' => (string) $term_obj->name,
					);
				}
			}
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	private function authorize(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}
}
