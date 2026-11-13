/**
 * Cedulio: classic checkout field behaviour, formatting and postcode autofill.
 *
 * Classic checkout: the tax ID label, example and "required" mark follow the billing country (and the
 * "I need an invoice" checkbox). Block checkout and My Account: the right field is chosen by WooCommerce
 * rules; this script only formats values and fills addresses from the postcode.
 */
( function () {
	'use strict';

	var cfg = window.taxIdCheckout;
	if ( ! cfg ) {
		return;
	}
	var $ = window.jQuery;

	function has( list, item ) {
		return list.indexOf( item ) !== -1;
	}

	function enabled( country ) {
		return ! cfg.enabled.length || has( cfg.enabled, country );
	}

	function details( country ) {
		if ( cfg.named[ country ] ) {
			return cfg.named[ country ];
		}
		return has( cfg.vat, country ) ? cfg.vatField : cfg.other;
	}

	function dotted( digits ) {
		return digits.replace( /\B(?=(\d{3})+(?!\d))/g, '.' );
	}

	/**
	 * Same output as Tax_ID_Checkout_Validator's "formatted" for the common shapes; anything else is left alone.
	 */
	function format( country, value ) {
		var raw = String( value || '' ).trim().toUpperCase();
		var id = raw.replace( /[^A-Z0-9]/g, '' );
		var digits = raw.replace( /\D/g, '' );
		var m;

		switch ( country ) {
			case 'BR':
				if ( /^\d{11}$/.test( id ) ) {
					return id.replace( /^(\d{3})(\d{3})(\d{3})(\d{2})$/, '$1.$2.$3-$4' );
				}
				if ( /^[A-Z0-9]{12}\d{2}$/.test( id ) ) {
					return id.replace( /^(.{2})(.{3})(.{3})(.{4})(\d{2})$/, '$1.$2.$3/$4-$5' );
				}
				break;
			case 'AR':
				if ( digits.length === 11 && id === digits ) {
					return digits.replace( /^(\d{2})(\d{8})(\d)$/, '$1-$2-$3' );
				}
				if ( /^\d{7,8}$/.test( id ) ) {
					return dotted( id );
				}
				break;
			case 'CL':
				if ( ( m = id.match( /^(\d{6,9})([\dK])$/ ) ) ) {
					return dotted( m[ 1 ].replace( /^0+/, '' ) ) + '-' + m[ 2 ];
				}
				break;
			case 'UY':
				if ( /^\d{7,8}$/.test( id ) ) {
					return dotted( id.slice( 0, -1 ) ) + '-' + id.slice( -1 );
				}
				break;
			case 'PY':
				return raw.replace( /[\s.]/g, '' );
			case 'CO':
				if ( ( m = raw.replace( /[\s.,]/g, '' ).match( /^(\d{6,15})-(\d)$/ ) ) ) {
					return dotted( m[ 1 ] ) + '-' + m[ 2 ];
				}
				if ( /^\d{5,10}$/.test( id ) ) {
					return dotted( id );
				}
				break;
			case 'MX':
				return raw.replace( /[\s-]/g, '' );
			default:
				if ( has( [ 'ES', 'PT', 'IT' ], country ) || has( cfg.vat, country ) ) {
					return id;
				}
		}
		return raw;
	}

	/**
	 * Set a value so React (block checkout) notices it too.
	 */
	function setValue( input, value ) {
		if ( input.value === value ) {
			return;
		}
		var setter = Object.getOwnPropertyDescriptor( window.HTMLInputElement.prototype, 'value' ).set;
		setter.call( input, value );
		input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
	}

	function storeCountry( type ) {
		var data = window.wp && window.wp.data;
		if ( ! data || ! data.select( 'wc/store/cart' ) ) {
			return '';
		}
		var customer = data.select( 'wc/store/cart' ).getCustomerData();
		var address = customer && customer[ type + 'Address' ];
		return address ? address.country : '';
	}

	/**
	 * Block checkout: update an address in the cart store. While "Use same address for billing" is ticked the
	 * billing address is only copied when the customer types, so a change made here is written to both.
	 */
	function setStoreAddress( type, address ) {
		var data = window.wp.data;
		var checkout = data.select( 'wc/store/checkout' );
		var mirrored = 'shipping' === type && checkout && checkout.getUseShippingAsBilling && checkout.getUseShippingAsBilling();
		if ( 'shipping' === type ) {
			data.dispatch( 'wc/store/cart' ).setShippingAddress( address );
		}
		if ( 'billing' === type || mirrored ) {
			data.dispatch( 'wc/store/cart' ).setBillingAddress( address );
		}
	}
	window.taxIdCheckout.setStoreAddress = setStoreAddress;

	/* ---------- Formatting (all forms) ---------- */

	document.addEventListener( 'focusout', function ( event ) {
		var input = event.target;
		if ( ! input || 'INPUT' !== input.tagName ) {
			return;
		}

		var country = '';
		if ( 'billing_tax_id' === input.id ) {
			country = ( $ && $( '#billing_country' ).val() ) || cfg.base;
		} else if ( input.hasAttribute( 'data-tax-id-checkout' ) ) {
			var slug = input.getAttribute( 'data-tax-id-checkout' );
			if ( 'vat' === slug ) {
				var prefix = input.value.replace( /[^A-Za-z]/g, '' ).slice( 0, 2 ).toUpperCase();
				country = has( cfg.vat, prefix ) ? prefix : ( 'EL' === prefix ? 'GR' : storeCountry( 'billing' ) );
			} else {
				country = 'other' === slug ? '' : slug.toUpperCase();
			}
		} else {
			return;
		}

		setValue( input, format( country, input.value ) );
	} );

	/* ---------- Classic checkout: tax ID per country ---------- */

	function syncClassic() {
		var $field = $( '#billing_tax_id_field' );
		if ( ! $field.length ) {
			return;
		}

		var country = $( '#billing_country' ).val() || cfg.base;
		var info = details( country );
		var show = enabled( country );
		var required;

		if ( 'invoice' === cfg.mode ) {
			$( '#billing_invoice_field' ).toggle( show );
			show = show && $( '#billing_invoice' ).is( ':checked' );
			required = show;
		} else if ( 'yes' === cfg.required ) {
			required = show;
		} else {
			required = show && 'countries' === cfg.required && has( cfg.requiredIn, country );
		}

		$field.toggle( show ).toggleClass( 'validate-required', required );
		if ( ! required ) {
			$field.removeClass( 'woocommerce-invalid woocommerce-invalid-required-field' );
		}

		var $label = $field.find( 'label' ).first();
		$label.text( info.label ).append(
			required ? '&nbsp;<span class="required" aria-hidden="true">*</span>' : '&nbsp;<span class="optional">(' + cfg.i18n.optional + ')</span>'
		);
		$( '#billing_tax_id' ).attr( 'placeholder', info.placeholder ).attr( 'aria-required', required ? 'true' : 'false' );
	}

	if ( $ ) {
		$( syncClassic );
		$( document.body ).on( 'country_to_state_changed updated_checkout', syncClassic );
		$( document ).on( 'change', '#billing_country, #billing_invoice', syncClassic );
	}

	/* ---------- My Account → Addresses: house number and neighborhood per country ---------- */

	function accountField( type, slug ) {
		return $( '[data-tax-id-address="' + type + '"]' ).filter( function () {
			return new RegExp( '/' + slug + '$' ).test( this.name || '' );
		} );
	}

	function syncAccountAddress() {
		$( '[data-tax-id-address]' ).each( function () {
			var $input = $( this );
			var country = $( '#' + $input.attr( 'data-tax-id-address' ) + '_country' ).val() || '';
			var countries = ( $input.attr( 'data-tax-id-countries' ) || '' ).split( ',' );
			var show = has( countries, country );
			var $row = $input.closest( '.form-row' );

			$row.toggle( show );
			$input.prop( 'disabled', ! show );
			if ( ! show ) {
				$row.removeClass( 'woocommerce-invalid woocommerce-invalid-required-field' );
			}
		} );
	}

	if ( $ ) {
		$( syncAccountAddress );
		$( document ).on( 'change', '#billing_country, #shipping_country', syncAccountAddress );
	}

	/* ---------- Postcode autofill ---------- */

	var looked = {};

	function lookup( country, postcode, done ) {
		var key = country + '|' + postcode;
		if ( looked[ key ] ) {
			return;
		}
		looked[ key ] = true;

		window.fetch( cfg.rest + ( cfg.rest.indexOf( '?' ) === -1 ? '?' : '&' ) + 'country=' + encodeURIComponent( country ) + '&postcode=' + encodeURIComponent( postcode ), { credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.ok ? response.json() : null;
			} )
			.then( function ( data ) {
				if ( data && data.found ) {
					done( data );
				}
			} )
			.catch( function () {
				looked[ key ] = false;
			} );
	}

	function ready( country, postcode ) {
		if ( ! has( cfg.autofill, country ) ) {
			return false;
		}
		return 'BR' === country ? postcode.replace( /\D/g, '' ).length === 8 : postcode.replace( /[^A-Za-z0-9]/g, '' ).length >= 3;
	}

	// Classic checkout and My Account address forms.
	function classicPostcode( input, final ) {
		var type = input.id.replace( '_postcode', '' );
		var country = $( '#' + type + '_country' ).val();
		var postcode = input.value;

		if ( 'BR' === country && /^\d{8}$/.test( postcode.replace( /\D/g, '' ) ) ) {
			input.value = postcode.replace( /\D/g, '' ).replace( /^(\d{5})(\d{3})$/, '$1-$2' );
		}
		if ( ! ( final || 'BR' === country ) || ! ready( country, postcode ) ) {
			return;
		}

		lookup( country, postcode, function ( data ) {
			if ( data.address_1 ) {
				$( '#' + type + '_address_1' ).val( data.address_1 );
			}
			if ( data.neighborhood ) {
				$( '#' + type + '_neighborhood' ).val( data.neighborhood );
				accountField( type, 'neighborhood' ).val( data.neighborhood );
			}
			if ( data.city ) {
				$( '#' + type + '_city' ).val( data.city );
			}
			if ( data.state ) {
				$( '#' + type + '_state' ).val( data.state ).trigger( 'change' );
			}
			if ( data.address_1 && $( '#' + type + '_number' ).is( ':visible' ) ) {
				$( '#' + type + '_number' ).trigger( 'focus' );
			} else if ( data.address_1 && accountField( type, 'number' ).is( ':visible' ) ) {
				accountField( type, 'number' ).trigger( 'focus' );
			}
			$( document.body ).trigger( 'update_checkout' );
		} );
	}

	if ( $ ) {
		$( document ).on( 'input', '#billing_postcode, #shipping_postcode', function () {
			classicPostcode( this, false );
		} );
		$( document ).on( 'change', '#billing_postcode, #shipping_postcode', function () {
			classicPostcode( this, true );
		} );
	}

	// Block checkout: postcode inputs are #billing-postcode / #shipping-postcode, data lives in the cart store.
	function blockPostcode( input, final ) {
		var type = 0 === input.id.indexOf( 'shipping' ) ? 'shipping' : 'billing';
		var country = storeCountry( type );
		if ( ! ( final || 'BR' === country ) || ! ready( country, input.value ) ) {
			return;
		}

		lookup( country, input.value, function ( data ) {
			var address = {};
			if ( 'BR' === country ) {
				address.postcode = input.value.replace( /\D/g, '' ).replace( /^(\d{5})(\d{3})$/, '$1-$2' );
			}
			if ( data.address_1 ) {
				address.address_1 = data.address_1;
			}
			if ( data.city ) {
				address.city = data.city;
			}
			if ( data.state ) {
				address.state = data.state;
			}
			if ( data.neighborhood ) {
				address[ 'tax-id-checkout/neighborhood' ] = data.neighborhood;
			}
			setStoreAddress( type, address );
		} );
	}

	function blockPostcodeEvent( final ) {
		return function ( event ) {
			var input = event.target;
			if ( input && /^(billing|shipping)-postcode$/.test( input.id ) && window.wp && window.wp.data ) {
				blockPostcode( input, final );
			}
		};
	}
	document.addEventListener( 'input', blockPostcodeEvent( false ) );
	document.addEventListener( 'focusout', blockPostcodeEvent( true ) );
} )();
