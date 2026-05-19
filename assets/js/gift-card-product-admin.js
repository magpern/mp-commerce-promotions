/**
 * Gift card product data panel: show/hide options and fixed amount field.
 */
( function ( $ ) {
	'use strict';

	function toggleSimpleOptions() {
		var checked = $( '#mp_cp_sells_gift_card' ).is( ':checked' );
		$( '.mp-cp-gift-card-product-options' ).toggle( checked );
	}

	function toggleFixedAmount( $scope ) {
		var $root = $scope && $scope.length ? $scope : $( '#woocommerce-product-data' );
		$root.find( '.mp-cp-gift-card-product-options, .mp-cp-gift-card-variation-fields' ).each( function () {
			var $container = $( this );
			var mode = $container.find( '.mp_cp_gift_card_amount_mode_field select' ).val();
			$container
				.find( '.mp_cp_gift_card_fixed_amount_field' )
				.toggle( mode === 'fixed_amount' );
		} );
	}

	function toggleVariationBlock( $checkbox ) {
		var $row = $checkbox.closest( '.woocommerce_variation' );
		$row.find( '.mp-cp-gift-card-variation-fields' ).toggle( $checkbox.is( ':checked' ) );
		toggleFixedAmount( $row );
	}

	function bindVariationRows() {
		$( '#variable_product_options' ).on(
			'change',
			'input[id^="mp_cp_sells_gift_card_var"]',
			function () {
				toggleVariationBlock( $( this ) );
			}
		);
		$( 'input[id^="mp_cp_sells_gift_card_var"]' ).each( function () {
			toggleVariationBlock( $( this ) );
		} );
	}

	function init() {
		var $panel = $( '#woocommerce-product-data' );
		if ( ! $panel.length ) {
			return;
		}

		$( '#mp_cp_sells_gift_card' ).on( 'change', function () {
			toggleSimpleOptions();
			toggleFixedAmount( $panel );
		} );

		$panel.on( 'change', '.mp_cp_gift_card_amount_mode_field select', function () {
			var $container = $( this ).closest(
				'.mp-cp-gift-card-product-options, .mp-cp-gift-card-variation-fields'
			);
			toggleFixedAmount( $container.length ? $container : $panel );
		} );

		toggleSimpleOptions();
		toggleFixedAmount( $panel );
		bindVariationRows();

		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_added', function () {
			bindVariationRows();
		} );
	}

	$( init );
} )( jQuery );
