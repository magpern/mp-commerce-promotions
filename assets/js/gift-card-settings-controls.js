/**
 * Gift card settings: logo media picker and accent color picker only.
 *
 * Does not depend on email preview AJAX config.
 *
 * @package MP\CommercePromotions
 */
( function () {
	'use strict';

	var LOGO_INPUT_ID = 'mp_cp_gift_card_logo_url';
	var ACCENT_INPUT_ID = 'mp_cp_gift_card_accent_color';
	var CHOOSE_LOGO_ID = 'mp-cp-gc-choose-logo';
	var REMOVE_LOGO_ID = 'mp-cp-gc-remove-logo';

	var mediaNoticeShown = false;
	var colorNoticeShown = false;
	var mediaFrame = null;

	function isDebug() {
		return Boolean(
			window.mpCpGiftCardSettingsControls
			&& window.mpCpGiftCardSettingsControls.debug
		);
	}

	function debugInfo( message, detail ) {
		if ( ! isDebug() || ! window.console || ! window.console.info ) {
			return;
		}
		if ( detail !== undefined ) {
			window.console.info( '[mp-cp-gift-card-settings]', message, detail );
		} else {
			window.console.info( '[mp-cp-gift-card-settings]', message );
		}
	}

	function getJquery() {
		if ( typeof window.jQuery !== 'undefined' ) {
			return window.jQuery;
		}
		return null;
	}

	function i18n( key, fallback ) {
		var cfg = window.mpCpGiftCardSettingsControls || {};
		if ( cfg.i18n && cfg.i18n[ key ] ) {
			return cfg.i18n[ key ];
		}
		return fallback;
	}

	function dispatchInput( node ) {
		if ( ! node ) {
			return;
		}
		node.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		node.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function showInlineNotice( anchor, className, message ) {
		if ( ! anchor || ! anchor.parentNode ) {
			return;
		}

		var notice = document.createElement( 'div' );
		notice.className = className;
		notice.setAttribute( 'role', 'status' );
		notice.innerHTML = '<p>' + message + '</p>';
		anchor.parentNode.insertBefore( notice, anchor.nextSibling );
	}

	function updateLogoThumb( url ) {
		var wrap = document.querySelector( '.mp-cp-gc-logo-thumb-wrap' );
		var img = document.getElementById( 'mp-cp-gc-logo-thumb' );
		var removeBtn = document.getElementById( REMOVE_LOGO_ID );

		if ( wrap && img ) {
			if ( url ) {
				img.src = url;
				wrap.style.display = '';
			} else {
				img.removeAttribute( 'src' );
				wrap.style.display = 'none';
			}
		}

		if ( removeBtn ) {
			removeBtn.style.display = url ? '' : 'none';
		}
	}

	function initLogoPicker() {
		var chooseBtn = document.getElementById( CHOOSE_LOGO_ID );
		var removeBtn = document.getElementById( REMOVE_LOGO_ID );
		var input = document.getElementById( LOGO_INPUT_ID );

		if ( ! chooseBtn || ! input ) {
			return;
		}

		chooseBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			if ( typeof window.wp === 'undefined' || ! window.wp.media ) {
				window.alert(
					i18n(
						'mediaUnavailable',
						'Media library is not available on this screen. Paste a logo URL manually.'
					)
				);
				if ( ! mediaNoticeShown ) {
					showInlineNotice(
						document.querySelector( '.mp-cp-gc-logo-field' ) || chooseBtn,
						'notice notice-warning inline mp-cp-gc-media-unavailable-notice',
						i18n(
							'mediaUnavailable',
							'Media library is not available on this screen. Paste a logo URL manually.'
						)
					);
					mediaNoticeShown = true;
				}
				return;
			}

			if ( ! mediaFrame ) {
				mediaFrame = window.wp.media( {
					title: i18n( 'chooseLogo', 'Choose logo' ),
					button: { text: i18n( 'useLogo', 'Use image' ) },
					library: { type: 'image' },
					multiple: false,
				} );
				mediaFrame.on( 'select', function () {
					var attachment = mediaFrame.state().get( 'selection' ).first().toJSON();
					if ( attachment && attachment.url ) {
						input.value = attachment.url;
						dispatchInput( input );
						updateLogoThumb( attachment.url );
					}
				} );
			}

			mediaFrame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = '';
				dispatchInput( input );
				updateLogoThumb( '' );
			} );
		}

		input.addEventListener( 'input', function () {
			updateLogoThumb( input.value );
		} );
	}

	function initColorPicker() {
		var input = document.getElementById( ACCENT_INPUT_ID );
		if ( ! input ) {
			return;
		}

		var $ = getJquery();
		if ( ! $ || ! $.fn || ! $.fn.wpColorPicker ) {
			if ( ! colorNoticeShown ) {
				showInlineNotice(
					input,
					'notice notice-warning inline mp-cp-gc-color-unavailable-notice',
					i18n( 'colorUnavailable', 'Color picker could not load.' )
				);
				colorNoticeShown = true;
			}
			return;
		}

		if ( $( input ).closest( '.wp-picker-container' ).length ) {
			return;
		}

		var fallback = input.getAttribute( 'data-default-color' ) || '#2271b1';

		$( input ).wpColorPicker( {
			defaultColor: fallback,
			change: function () {
				dispatchInput( input );
			},
			clear: function () {
				input.value = fallback;
				dispatchInput( input );
			},
		} );
	}

	function init() {
		var hasMedia = typeof window.wp !== 'undefined' && Boolean( window.wp.media );
		var $ = getJquery();
		var hasColorPicker = Boolean( $ && $.fn && $.fn.wpColorPicker );

		debugInfo( 'MP CP gift card settings controls initialized' );
		debugInfo( 'wp.media available', hasMedia );
		debugInfo( 'wpColorPicker available', hasColorPicker );

		initLogoPicker();
		initColorPicker();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
