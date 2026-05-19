/**
 * Cart gift card / store credit — WooCommerce-style disclosure (details/summary).
 *
 * @package MP\CommercePromotions
 */
( function () {
	'use strict';

	function syncDetails( details ) {
		var summary = details.querySelector( '.mp-cp-credit-cart-disclosure__trigger' );
		var panel = details.querySelector( '.mp-cp-credit-cart-disclosure__panel' );
		if ( ! summary ) {
			return;
		}

		if ( panel && ! panel.id ) {
			panel.id = 'mp-cp-credit-cart-disclosure-panel';
		}
		if ( panel && panel.id ) {
			summary.setAttribute( 'aria-controls', panel.id );
		}

		summary.setAttribute( 'role', 'button' );
		summary.setAttribute( 'aria-expanded', details.open ? 'true' : 'false' );

		details.addEventListener( 'toggle', function () {
			summary.setAttribute( 'aria-expanded', details.open ? 'true' : 'false' );
			details.classList.toggle( 'mp-cp-credit-cart-disclosure__details--open', details.open );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var nodes = document.querySelectorAll( '.mp-cp-credit-cart-disclosure__details' );
		for ( var i = 0; i < nodes.length; i++ ) {
			syncDetails( nodes[ i ] );
			if ( nodes[ i ].open ) {
				nodes[ i ].classList.add( 'mp-cp-credit-cart-disclosure__details--open' );
			}
		}
	} );
} )();
