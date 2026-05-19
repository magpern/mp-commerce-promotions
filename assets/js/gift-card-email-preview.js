/**
 * Live gift card email preview on Gift Cards → Settings (sample data only).
 */
( function () {
	'use strict';

	var cfg = window.mpCpGiftCardEmailPreview;
	if ( ! cfg || ! cfg.sample ) {
		return;
	}

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

	function el( id ) {
		return document.getElementById( id );
	}

	function fieldValue( id ) {
		var node = el( id );
		return node ? node.value : '';
	}

	function replacePlaceholders( text ) {
		if ( ! text ) {
			return '';
		}
		var out = text;
		( cfg.placeholders || [] ).forEach( function ( key ) {
			var token = '{' + key + '}';
			var val = cfg.sample[ key ] != null ? String( cfg.sample[ key ] ) : '';
			out = out.split( token ).join( val );
		} );
		return out;
	}

	function escapeHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function nl2brEscaped( str ) {
		return escapeHtml( str ).replace( /\r?\n/g, '<br />' );
	}

	function sanitizeColor( color ) {
		color = String( color || '' ).trim();
		return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test( color ) ? color : '#2271b1';
	}

	function updatePreview() {
		var wrap = el( 'mp-cp-gc-email-preview-wrap' );
		if ( ! wrap ) {
			return;
		}

		var accent = sanitizeColor( fieldValue( fieldIds.accent ) );
		var heading = replacePlaceholders( fieldValue( fieldIds.heading ) );
		var intro = replacePlaceholders( fieldValue( fieldIds.intro ) );
		var redeem = replacePlaceholders( fieldValue( fieldIds.redeem ) );
		var footer = replacePlaceholders( fieldValue( fieldIds.footer ) );
		var support = replacePlaceholders( fieldValue( fieldIds.support ) );
		var logo = fieldValue( fieldIds.logo );

		var subjectEl = el( 'mp-cp-gc-email-subject-preview' );
		if ( subjectEl ) {
			subjectEl.textContent = replacePlaceholders( fieldValue( fieldIds.subject ) );
		}

		var headingNode = wrap.querySelector( '[data-mp-cp-email="heading"]' );
		if ( headingNode ) {
			headingNode.textContent = heading;
		}

		var introNode = wrap.querySelector( '[data-mp-cp-email="intro"]' );
		if ( introNode ) {
			introNode.innerHTML = nl2brEscaped( intro );
		}

		var redeemNode = wrap.querySelector( '[data-mp-cp-email="redeem"]' );
		if ( redeemNode ) {
			redeemNode.innerHTML = nl2brEscaped( redeem );
		}

		var footerNode = wrap.querySelector( '[data-mp-cp-email="footer"]' );
		if ( footerNode ) {
			if ( footer ) {
				footerNode.style.display = '';
				footerNode.innerHTML = nl2brEscaped( footer );
			} else {
				footerNode.style.display = 'none';
				footerNode.innerHTML = '';
			}
		}

		var supportNode = wrap.querySelector( '[data-mp-cp-email="support"]' );
		if ( supportNode ) {
			if ( support ) {
				supportNode.style.display = '';
				supportNode.innerHTML = nl2brEscaped( support );
			} else {
				supportNode.style.display = 'none';
				supportNode.innerHTML = '';
			}
		}

		var logoNode = wrap.querySelector( '[data-mp-cp-email="logo"]' );
		if ( logoNode ) {
			if ( logo ) {
				logoNode.src = logo;
				logoNode.style.display = '';
			} else {
				logoNode.removeAttribute( 'src' );
				logoNode.style.display = 'none';
			}
		}

		wrap.querySelectorAll( '[data-mp-cp-email-accent="header"]' ).forEach( function ( node ) {
			node.style.background = accent;
		} );

		var card = wrap.querySelector( '[data-mp-cp-email="card"]' );
		if ( card ) {
			card.style.borderLeftColor = accent;
		}
	}

	function bindField( id ) {
		var node = el( id );
		if ( ! node ) {
			return;
		}
		node.addEventListener( 'input', updatePreview );
		node.addEventListener( 'change', updatePreview );
	}

	Object.keys( fieldIds ).forEach( function ( key ) {
		bindField( fieldIds[ key ] );
	} );

	document.querySelectorAll( 'input[name="mp_cp_gift_card_email_style"]' ).forEach( function ( radio ) {
		radio.addEventListener( 'change', updatePreview );
	} );

	var testForm = el( 'mp-cp-gc-test-email-form' );
	if ( testForm ) {
		testForm.addEventListener( 'submit', function () {
			var names = [
				fieldIds.subject,
				fieldIds.heading,
				fieldIds.intro,
				fieldIds.redeem,
				fieldIds.footer,
				fieldIds.support,
				fieldIds.logo,
				fieldIds.accent,
			];
			names.forEach( function ( name ) {
				var source = el( name );
				if ( ! source ) {
					return;
				}
				var hidden = document.createElement( 'input' );
				hidden.type = 'hidden';
				hidden.name = name;
				hidden.value = source.value;
				testForm.appendChild( hidden );
			} );
			var styleChecked = document.querySelector( 'input[name="mp_cp_gift_card_email_style"]:checked' );
			if ( styleChecked ) {
				var styleInput = document.createElement( 'input' );
				styleInput.type = 'hidden';
				styleInput.name = 'mp_cp_gift_card_email_style';
				styleInput.value = styleChecked.value;
				testForm.appendChild( styleInput );
			}
		} );
	}

	updatePreview();
}() );
