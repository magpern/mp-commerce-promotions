/**
 * Gift card email settings: live preview, AJAX test email, media logo picker, color picker.
 */
( function ( $ ) {
	'use strict';

	var cfg = window.mpCpGiftCardEmailPreview || {};

	var fieldIds = {
		subject: 'mp_cp_gift_card_email_subject',
		heading: 'mp_cp_gift_card_email_heading',
		intro: 'mp_cp_gift_card_email_intro',
		redeem: 'mp_cp_gift_card_email_redeem_instructions',
		footer: 'mp_cp_gift_card_email_footer_text',
		support: 'mp_cp_gift_card_support_email_text',
		logo: 'mp_cp_gift_card_logo_url',
		accent: 'mp_cp_gift_card_accent_color',
	};

	var previewTimer = null;
	var mediaNoticeShown = false;

	function hasPreviewAjax() {
		return Boolean( cfg.ajaxUrl && cfg.nonce && cfg.previewAction );
	}

	function debugWarn( message ) {
		var debug = document.documentElement.classList.contains( 'wp-debug' )
			|| document.documentElement.classList.contains( 'wp-debug-log' );
		if ( debug && window.console && window.console.warn ) {
			window.console.warn( '[mp-cp-gift-card-email]', message );
		}
	}

	function fieldValue( id ) {
		var node = document.getElementById( id );
		return node ? node.value : '';
	}

	function collectPayload() {
		var payload = {
			nonce: cfg.nonce || '',
			preview_amount: fieldValue( 'mp_cp_gc_settings_test_amount' ) || '25',
			preview_currency: fieldValue( 'mp_cp_gc_settings_test_currency' ) || 'EUR',
		};

		Object.keys( fieldIds ).forEach( function ( key ) {
			payload[ fieldIds[ key ] ] = fieldValue( fieldIds[ key ] );
		} );

		var styleChecked = document.querySelector( 'input[name="mp_cp_gift_card_email_style"]:checked' );
		if ( styleChecked ) {
			payload.mp_cp_gift_card_email_style = styleChecked.value;
		}

		return payload;
	}

	function schedulePreview() {
		if ( ! hasPreviewAjax() ) {
			return;
		}

		if ( previewTimer ) {
			window.clearTimeout( previewTimer );
		}
		previewTimer = window.setTimeout( refreshPreview, 350 );
	}

	function refreshPreview() {
		if ( ! hasPreviewAjax() ) {
			return;
		}

		var wrap = document.getElementById( 'mp-cp-gc-email-preview-wrap' );
		if ( ! wrap ) {
			return;
		}

		wrap.classList.add( 'mp-cp-gc-email-preview-frame--loading' );

		var data = collectPayload();
		data.action = cfg.previewAction;

		$.post( cfg.ajaxUrl, data )
			.done( function ( response ) {
				if ( ! response || ! response.success || ! response.data ) {
					return;
				}
				wrap.innerHTML = response.data.html || '';
				var subjectEl = document.getElementById( 'mp-cp-gc-email-subject-preview' );
				if ( subjectEl && response.data.subject ) {
					subjectEl.textContent = response.data.subject;
				}
			} )
			.fail( function () {
				// Keep last good preview on transient errors.
			} )
			.always( function () {
				wrap.classList.remove( 'mp-cp-gc-email-preview-frame--loading' );
			} );
	}

	function bindPreviewFields() {
		if ( ! hasPreviewAjax() ) {
			debugWarn( 'Preview AJAX config missing; logo and color picker still work.' );
			return;
		}

		Object.keys( fieldIds ).forEach( function ( key ) {
			var node = document.getElementById( fieldIds[ key ] );
			if ( ! node ) {
				return;
			}
			node.addEventListener( 'input', schedulePreview );
			node.addEventListener( 'change', schedulePreview );
		} );

		document.querySelectorAll( 'input[name="mp_cp_gift_card_email_style"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', schedulePreview );
		} );

		[ 'mp_cp_gc_settings_test_amount', 'mp_cp_gc_settings_test_currency' ].forEach( function ( id ) {
			var node = document.getElementById( id );
			if ( node ) {
				node.addEventListener( 'input', schedulePreview );
				node.addEventListener( 'change', schedulePreview );
			}
		} );
	}

	function showMediaUnavailableNotice() {
		if ( mediaNoticeShown ) {
			return;
		}
		mediaNoticeShown = true;

		var message = ( cfg.i18n && cfg.i18n.mediaUnavailable )
			? cfg.i18n.mediaUnavailable
			: 'Media library is unavailable. Paste a logo URL instead.';

		var logoField = document.querySelector( '.mp-cp-gc-logo-field' );
		if ( ! logoField ) {
			return;
		}

		var notice = document.createElement( 'div' );
		notice.className = 'notice notice-warning inline mp-cp-gc-media-unavailable-notice';
		notice.setAttribute( 'role', 'status' );
		notice.innerHTML = '<p>' + message + '</p>';
		logoField.parentNode.insertBefore( notice, logoField );
	}

	function initLogoPicker() {
		var chooseBtn = document.getElementById( 'mp-cp-gc-choose-logo' );
		var removeBtn = document.getElementById( 'mp-cp-gc-remove-logo' );
		var input = document.getElementById( fieldIds.logo );
		if ( ! chooseBtn || ! input ) {
			return;
		}

		var frame;

		chooseBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			if ( typeof wp === 'undefined' || ! wp.media ) {
				debugWarn( 'wp.media is not available on this screen.' );
				showMediaUnavailableNotice();
				return;
			}

			if ( ! frame ) {
				frame = wp.media( {
					title: cfg.i18n && cfg.i18n.chooseLogo ? cfg.i18n.chooseLogo : 'Choose logo',
					button: { text: cfg.i18n && cfg.i18n.useLogo ? cfg.i18n.useLogo : 'Use image' },
					library: { type: 'image' },
					multiple: false,
				} );
				frame.on( 'select', function () {
					var attachment = frame.state().get( 'selection' ).first().toJSON();
					if ( attachment && attachment.url ) {
						input.value = attachment.url;
						input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
						updateLogoThumb( attachment.url );
						schedulePreview();
					}
				} );
			}

			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = '';
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
				updateLogoThumb( '' );
				removeBtn.style.display = 'none';
				schedulePreview();
			} );
		}

		input.addEventListener( 'input', function () {
			updateLogoThumb( input.value );
			if ( removeBtn ) {
				removeBtn.style.display = input.value ? '' : 'none';
			}
		} );
	}

	function initColorPicker() {
		var input = document.getElementById( fieldIds.accent );
		if ( ! input ) {
			return;
		}

		if ( ! $.fn.wpColorPicker ) {
			debugWarn( 'wpColorPicker is not available; accent field stays a plain text input.' );
			return;
		}

		if ( $( input ).closest( '.wp-picker-container' ).length ) {
			return;
		}

		var $input = $( input );
		var fallback = input.getAttribute( 'data-default-color' ) || '#2271b1';

		$input.wpColorPicker( {
			defaultColor: fallback,
			change: function () {
				schedulePreview();
			},
			clear: function () {
				input.value = fallback;
				schedulePreview();
			},
		} );

		$input.on( 'input change', schedulePreview );
	}

	function updateLogoThumb( url ) {
		var wrap = document.querySelector( '.mp-cp-gc-logo-thumb-wrap' );
		var img = document.getElementById( 'mp-cp-gc-logo-thumb' );
		if ( ! wrap || ! img ) {
			return;
		}
		if ( url ) {
			img.src = url;
			wrap.style.display = '';
		} else {
			img.removeAttribute( 'src' );
			wrap.style.display = 'none';
		}

		var removeBtn = document.getElementById( 'mp-cp-gc-remove-logo' );
		if ( removeBtn ) {
			removeBtn.style.display = url ? '' : 'none';
		}
	}

	function initTestEmail() {
		if ( ! cfg.ajaxUrl || ! cfg.testAction ) {
			return;
		}

		var btn = document.getElementById( 'mp-cp-gc-send-test-email' );
		var notice = document.getElementById( 'mp-cp-gc-test-email-notice' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var data = collectPayload();
			data.action = cfg.testAction;
			data.mp_cp_gc_settings_test_to = fieldValue( 'mp_cp_gc_settings_test_to' );
			data.mp_cp_gc_settings_test_amount = fieldValue( 'mp_cp_gc_settings_test_amount' );
			data.mp_cp_gc_settings_test_currency = fieldValue( 'mp_cp_gc_settings_test_currency' );

			btn.disabled = true;
			if ( notice ) {
				notice.textContent = cfg.i18n && cfg.i18n.sending ? cfg.i18n.sending : 'Sending…';
				notice.className = 'mp-cp-gc-test-email-notice mp-cp-gc-test-email-notice--pending';
			}

			$.post( cfg.ajaxUrl, data )
				.done( function ( response ) {
					if ( notice ) {
						if ( response && response.success ) {
							notice.textContent = ( response.data && response.data.message ) || ( cfg.i18n && cfg.i18n.testSent );
							notice.className = 'mp-cp-gc-test-email-notice mp-cp-gc-test-email-notice--success';
						} else {
							notice.textContent = ( response && response.data && response.data.message ) || ( cfg.i18n && cfg.i18n.testFailed );
							notice.className = 'mp-cp-gc-test-email-notice mp-cp-gc-test-email-notice--error';
						}
					}
				} )
				.fail( function () {
					if ( notice ) {
						notice.textContent = cfg.i18n && cfg.i18n.testFailed ? cfg.i18n.testFailed : 'Test email failed.';
						notice.className = 'mp-cp-gc-test-email-notice mp-cp-gc-test-email-notice--error';
					}
				} )
				.always( function () {
					btn.disabled = false;
				} );
		} );
	}

	$( function () {
		initColorPicker();
		initLogoPicker();
		bindPreviewFields();
		initTestEmail();
	} );
}( jQuery ) );
