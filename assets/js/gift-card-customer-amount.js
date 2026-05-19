/**
 * Suggested amount chips for customer-entered gift card products.
 */
( function () {
	'use strict';

	function init() {
		var input = document.getElementById( 'mp_cp_gift_card_customer_amount' );
		if ( ! input ) {
			return;
		}

		document.querySelectorAll( '.mp-cp-gc-suggested-amount' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				var amount = button.getAttribute( 'data-amount' );
				if ( amount ) {
					input.value = amount;
					input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
				}
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
