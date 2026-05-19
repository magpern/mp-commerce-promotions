/**
 * Gift card email settings: live preview and AJAX test email.
 *
 * Logo/color pickers: assets/js/gift-card-settings-controls.js
 */
( function ( $ ) {
	'use strict';

	if ( typeof $ === 'undefined' ) {
		return;
	}

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
			debugWarn( 'Preview AJAX config missing; email preview updates are disabled.' );
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
		bindPreviewFields();
		initTestEmail();
	} );
}( window.jQuery ) );
