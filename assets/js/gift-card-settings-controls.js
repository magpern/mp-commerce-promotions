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
	var syncingAccent = false;

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

	function expandShortHex( hex ) {
		if ( ! /^#[0-9a-fA-F]{3}$/.test( hex ) ) {
			return hex;
		}
		return (
			'#'
			+ hex.charAt( 1 )
			+ hex.charAt( 1 )
			+ hex.charAt( 2 )
			+ hex.charAt( 2 )
			+ hex.charAt( 3 )
			+ hex.charAt( 3 )
		).toLowerCase();
	}

	function normalizeHex( color ) {
		if ( color === null || color === undefined ) {
			return '';
		}

		var value = String( color ).trim();
		if ( value === '' ) {
			return '';
		}

		if ( value.charAt( 0 ) !== '#' ) {
			value = '#' + value;
		}

		if ( /^#[0-9a-fA-F]{6}$/.test( value ) ) {
			return value.toLowerCase();
		}

		if ( /^#[0-9a-fA-F]{3}$/.test( value ) ) {
			return expandShortHex( value );
		}

		return '';
	}

	function colorFromUi( ui ) {
		if ( ui && ui.color && typeof ui.color.toString === 'function' ) {
			return ui.color.toString();
		}
		return '';
	}

	function getAccentDefault( $field ) {
		return normalizeHex( $field.attr( 'data-default-color' ) || '#2271b1' ) || '#2271b1';
	}

	function notifyAccentChange( node ) {
		dispatchInput( node );
		if ( typeof window.CustomEvent === 'function' ) {
			document.dispatchEvent(
				new CustomEvent( 'mp-cp-gift-card-accent-change', { bubbles: true } )
			);
		}
	}

	function applyAccentColor( $, color, options ) {
		options = options || {};
		var $field = $( '#' + ACCENT_INPUT_ID );
		if ( ! $field.length || syncingAccent ) {
			return;
		}

		var hex = normalizeHex( color );
		if ( hex === '' && options.useDefaultOnEmpty ) {
			hex = getAccentDefault( $field );
		}

		if ( hex === '' && ! options.allowEmpty ) {
			return;
		}

		syncingAccent = true;

		$field.val( hex );

		if ( $field.hasClass( 'wp-color-picker' ) && $.fn.wpColorPicker ) {
			try {
				$field.wpColorPicker( 'color', hex || getAccentDefault( $field ) );
			} catch ( e ) {
				// Picker may not be fully initialized yet.
			}
		}

		syncingAccent = false;
		notifyAccentChange( $field.get( 0 ) );
	}

	function onAccentPickerChange( $, source, ui ) {
		var raw = colorFromUi( ui );
		if ( raw === '' && source ) {
			raw = $( source ).val();
		}
		applyAccentColor( $, raw );
	}

	function attachAccentIrisListeners( $, $field ) {
		$field.off( 'irischange.mpCpAccent' );
		$field.on( 'irischange.mpCpAccent', function ( event, ui ) {
			onAccentPickerChange( $, $field.get( 0 ), ui );
		} );

		$field.closest( '.wp-picker-container' ).off( 'click.mpCpAccent', '.wp-picker-clear' );
		$field.closest( '.wp-picker-container' ).on( 'click.mpCpAccent', '.wp-picker-clear', function () {
			window.setTimeout( function () {
				applyAccentColor( $, '', { useDefaultOnEmpty: true } );
			}, 10 );
		} );
	}

	function bindAccentManualInput( $, $field ) {
		var node = $field && $field.length ? $field.get( 0 ) : document.getElementById( ACCENT_INPUT_ID );
		if ( ! node ) {
			return;
		}

		var handler = function () {
			if ( syncingAccent ) {
				return;
			}
			var raw = $field && $field.length ? $field.val() : node.value;
			var hex = normalizeHex( raw );
			if ( hex !== '' && $ ) {
				applyAccentColor( $, hex );
				return;
			}
			if ( hex !== '' ) {
				node.value = hex;
			}
			notifyAccentChange( node );
		};

		if ( $ && $field && $field.length ) {
			$field.off( 'input.mpCpAccent change.mpCpAccent' );
			$field.on( 'input.mpCpAccent change.mpCpAccent', handler );
			return;
		}

		node.removeEventListener( 'input', handler );
		node.removeEventListener( 'change', handler );
		node.addEventListener( 'input', handler );
		node.addEventListener( 'change', handler );
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
			bindAccentManualInput( null, null );
			return;
		}

		var $field = $( input );
		var fallback = getAccentDefault( $field );
		var alreadyWrapped = $field.closest( '.wp-picker-container' ).length > 0;

		if ( alreadyWrapped ) {
			attachAccentIrisListeners( $, $field );
			bindAccentManualInput( $, $field );
			return;
		}

		var current = normalizeHex( $field.val() );
		if ( current !== '' ) {
			$field.val( current );
		}

		$field.wpColorPicker( {
			defaultColor: fallback,
			change: function ( event, ui ) {
				onAccentPickerChange( $, this, ui );
			},
			clear: function () {
				applyAccentColor( $, '', { useDefaultOnEmpty: true } );
			},
		} );

		attachAccentIrisListeners( $, $field );
		bindAccentManualInput( $, $field );
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
