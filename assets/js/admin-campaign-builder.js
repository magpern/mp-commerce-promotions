/**
 * Campaign Builder — lightweight pickers and wizard UX (no frameworks).
 */
( function () {
	'use strict';

	var cfg = window.mpCbAdmin || {};
	var ajaxUrl = cfg.ajaxUrl || '';
	var searchNonce = cfg.searchNonce || '';

	function debounce( fn, ms ) {
		var t;
		return function () {
			var args = arguments;
			clearTimeout( t );
			t = setTimeout( function () {
				fn.apply( null, args );
			}, ms );
		};
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function initPicker( root ) {
		var type = root.getAttribute( 'data-mp-cb-picker' );
		if ( ! type || ! ajaxUrl ) {
			return;
		}

		var searchInput = root.querySelector( '.mp-cb-picker__search' );
		var resultsEl = root.querySelector( '.mp-cb-picker__results' );
		var selectedEl = root.querySelector( '.mp-cb-picker__selected' );
		var action = type === 'products' ? 'mp_cb_search_products' : 'mp_cb_search_categories';
		var nameAttr = type === 'products' ? 'product_ids[]' : 'category_ids[]';
		var selected = {};

		root.querySelectorAll( '.mp-cb-picker__pill' ).forEach( function ( pill ) {
			var id = pill.getAttribute( 'data-id' );
			var label = pill.getAttribute( 'data-label' ) || id;
			if ( id ) {
				selected[ id ] = label;
			}
		} );

		function renderSelected() {
			if ( ! selectedEl ) {
				return;
			}
			selectedEl.innerHTML = '';
			Object.keys( selected ).forEach( function ( id ) {
				var pill = document.createElement( 'span' );
				pill.className = 'mp-cb-picker__pill';
				pill.setAttribute( 'data-id', id );
				pill.setAttribute( 'data-label', selected[ id ] );
				pill.innerHTML =
					'<span class="mp-cb-picker__pill-label">' +
					escapeHtml( selected[ id ] ) +
					'</span>' +
					'<button type="button" class="mp-cb-picker__pill-remove" aria-label="Remove">&times;</button>' +
					'<input type="hidden" name="' +
					nameAttr +
					'" value="' +
					escapeHtml( id ) +
					'" />';
				pill.querySelector( '.mp-cb-picker__pill-remove' ).addEventListener( 'click', function () {
					delete selected[ id ];
					renderSelected();
				} );
				selectedEl.appendChild( pill );
			} );
		}

		function addItem( id, label ) {
			selected[ String( id ) ] = label;
			renderSelected();
			if ( resultsEl ) {
				resultsEl.innerHTML = '';
			}
			if ( searchInput ) {
				searchInput.value = '';
			}
		}

		var runSearch = debounce( function () {
			if ( ! searchInput || ! resultsEl ) {
				return;
			}
			var q = searchInput.value.trim();
			if ( q.length < ( type === 'products' ? 2 : 1 ) ) {
				resultsEl.innerHTML = '';
				return;
			}
			var url =
				ajaxUrl +
				'?action=' +
				encodeURIComponent( action ) +
				'&nonce=' +
				encodeURIComponent( searchNonce ) +
				'&q=' +
				encodeURIComponent( q );

			resultsEl.innerHTML = '<p class="mp-cb-picker__loading">…</p>';
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( payload ) {
					var items = ( payload.data && payload.data.items ) || [];
					if ( ! items.length ) {
						resultsEl.innerHTML = '<p class="mp-cb-picker__empty">No matches</p>';
						return;
					}
					resultsEl.innerHTML = '';
					items.forEach( function ( item ) {
						var btn = document.createElement( 'button' );
						btn.type = 'button';
						btn.className = 'mp-cb-picker__result';
						var label = item.label || '#' + item.id;
						if ( item.sku ) {
							label += ' (' + item.sku + ')';
						}
						btn.textContent = label;
						btn.addEventListener( 'click', function () {
							addItem( item.id, item.label || label );
						} );
						resultsEl.appendChild( btn );
					} );
				} )
				.catch( function () {
					resultsEl.innerHTML = '<p class="mp-cb-picker__empty">Search failed</p>';
				} );
		}, 280 );

		if ( searchInput ) {
			searchInput.addEventListener( 'input', runSearch );
		}

		renderSelected();
	}

	function initStickyPreview() {
		var aside = document.querySelector( '.mp-cb-layout__aside' );
		if ( ! aside || window.matchMedia( '(max-width: 1100px)' ).matches ) {
			return;
		}
		aside.classList.add( 'mp-cb-layout__aside--sticky' );
	}

	function init() {
		document.querySelectorAll( '.mp-cb-picker' ).forEach( initPicker );
		initStickyPreview();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
