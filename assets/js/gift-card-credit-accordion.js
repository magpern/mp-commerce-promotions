/**
 * Gift card / store credit accordion — enhances <details> toggle affordance.
 *
 * @package MP\CommercePromotions
 */
( function () {
	'use strict';

	function initAccordion( details ) {
		var summary = details.querySelector( '.mp-cp-credit-accordion__toggle' );
		var body = details.querySelector( '.mp-cp-credit-accordion__body' );
		if ( ! summary || ! body ) {
			return;
		}

		var bodyId = body.id || 'mp-cp-credit-accordion-body';
		if ( ! body.id ) {
			body.id = bodyId;
		}
		summary.setAttribute( 'aria-controls', bodyId );

		function syncAria() {
			var open = details.hasAttribute( 'open' );
			summary.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		syncAria();
		details.addEventListener( 'toggle', syncAria );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var nodes = document.querySelectorAll( '.mp-cp-credit-accordion' );
		for ( var i = 0; i < nodes.length; i++ ) {
			initAccordion( nodes[ i ] );
		}
	} );
} )();
