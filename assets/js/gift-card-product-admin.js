/**
 * Gift card product data tab: conditional fields (simple + variations).
 */
( function ( $ ) {
	'use strict';

	var PANEL = '#mp_cp_gift_card_product_data';

	function isGiftCardCheckboxChecked( $checkbox ) {
		return $checkbox.is( ':checked' );
	}

	function toggleShowIfSellsGiftCard( $scope ) {
		var $root = $scope && $scope.length ? $scope : $( PANEL );
		if ( ! $root.length ) {
			return;
		}

		var checked = isGiftCardCheckboxChecked( $( '#mp_cp_sells_gift_card' ) );
		$root.find( '.show_if_mp_cp_sells_gift_card' ).toggle( checked );
		$root.find( '.mp-cp-gift-card-product-options' ).toggle( checked );
	}

	function toggleAmountModeFields( $scope ) {
		var $containers = $scope && $scope.length
			? $scope.find( '.mp-cp-gift-card-product-options, .mp-cp-gift-card-variation-fields' )
			: $( PANEL ).find( '.mp-cp-gift-card-product-options' ).add(
					'.mp-cp-gift-card-variation-fields:visible'
			  );

		$containers.each( function () {
			var $container = $( this );
			if ( $container.hasClass( 'mp-cp-gift-card-variation-fields--hidden' ) ) {
				return;
			}

			var mode = $container.find( '.mp_cp_gift_card_amount_mode_field select' ).val();
			var showFixed = mode === 'fixed_amount';
			var showCustomer = mode === 'customer_amount';
			$container
				.find( '.mp_cp_gift_card_fixed_amount_field' )
				.toggle( showFixed )
				.toggleClass( 'mp-cp-hidden', ! showFixed );
			$container
				.find( '.mp_cp_gift_card_customer_amount_field' )
				.toggle( showCustomer )
				.toggleClass( 'mp-cp-hidden', ! showCustomer );
		} );
	}

	function toggleVariationBlock( $checkbox ) {
		var $row = $checkbox.closest( '.woocommerce_variation' );
		var checked = isGiftCardCheckboxChecked( $checkbox );
		var $fields = $row.find( '.mp-cp-gift-card-variation-fields' );

		$fields.toggleClass( 'mp-cp-gift-card-variation-fields--hidden', ! checked );
		$fields.toggle( checked );

		if ( checked ) {
			toggleAmountModeFields( $row );
		}
	}

	function bindVariationRows() {
		var $variations = $( '#variable_product_options' );
		if ( ! $variations.length ) {
			return;
		}

		$variations.off( 'change.mpCpGiftCard', 'input[id^="mp_cp_sells_gift_card_var"]' );
		$variations.on( 'change.mpCpGiftCard', 'input[id^="mp_cp_sells_gift_card_var"]', function () {
			toggleVariationBlock( $( this ) );
		} );

		$variations.find( 'input[id^="mp_cp_sells_gift_card_var"]' ).each( function () {
			toggleVariationBlock( $( this ) );
		} );
	}

	function init() {
		if ( ! $( '#woocommerce-product-data' ).length ) {
			return;
		}

		var $panel = $( PANEL );
		if ( $panel.length ) {
			$( '#mp_cp_sells_gift_card' ).on( 'change.mpCpGiftCard', function () {
				toggleShowIfSellsGiftCard( $panel );
				toggleAmountModeFields( $panel );
			} );

			$panel.on( 'change.mpCpGiftCard', '.mp_cp_gift_card_amount_mode_field select', function () {
				var $container = $( this ).closest(
					'.mp-cp-gift-card-product-options, .mp-cp-gift-card-variation-fields'
				);
				toggleAmountModeFields( $container.length ? $container : $panel );
			} );

			toggleShowIfSellsGiftCard( $panel );
			toggleAmountModeFields( $panel );
		}

		bindVariationRows();

		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_added.mpCpGiftCard', function () {
			bindVariationRows();
		} );
	}

	$( init );
} )( jQuery );
