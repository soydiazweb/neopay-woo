/**
 * Metodo NeoPay en el checkout por bloques.
 *
 * Los datos de la tarjeta viajan en paymentMethodData solo durante la
 * peticion de pago; el servidor nunca los guarda.
 *
 * @package NeoPay_Woo
 */
( function ( window ) {
	'use strict';

	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settings = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;

	if ( ! registry || ! settings || ! element ) {
		return;
	}

	var el = element.createElement;
	var useEffect = element.useEffect;
	var useRef = element.useRef;
	var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function ( v ) {
		return v;
	};
	var data = settings.getSetting( 'neopay_woo_data', {} );

	function luhn( pan ) {
		if ( pan.length < 13 || pan.length > 19 ) {
			return false;
		}
		var sum = 0;
		for ( var i = 0; i < pan.length; i++ ) {
			var d = parseInt( pan.charAt( pan.length - 1 - i ), 10 );
			if ( i % 2 ) {
				d *= 2;
				if ( d > 9 ) {
					d -= 9;
				}
			}
			sum += d;
		}
		return 0 === sum % 10;
	}

	function brand( pan ) {
		if ( /^4/.test( pan ) ) {
			return 'visa';
		}
		if ( /^(5[1-5]|222[1-9]|22[3-9]|2[3-6]|27[01]|2720)/.test( pan ) ) {
			return 'mastercard';
		}
		return '';
	}

	function row( key, label, input ) {
		return el( 'div', { key: key, className: 'neopay-blocks-row' }, [
			el( 'label', { key: 'l', htmlFor: input.props.id }, label ),
			input
		] );
	}

	function CardForm( props ) {
		var onPaymentSetup = props.eventRegistration.onPaymentSetup;
		var responseTypes = props.emitResponse.responseTypes;
		var noticeContexts = props.emitResponse.noticeContexts;

		var nameRef = useRef( null );
		var numberRef = useRef( null );
		var expiryRef = useRef( null );
		var cvcRef = useRef( null );
		var planRef = useRef( null );

		useEffect( function () {
			return onPaymentSetup( function () {
				var name = ( nameRef.current ? nameRef.current.value : '' ).trim();
				var pan = ( numberRef.current ? numberRef.current.value : '' ).replace( /\D/g, '' );
				var exp = ( expiryRef.current ? expiryRef.current.value : '' ).replace( /\D/g, '' );
				var cvc = ( cvcRef.current ? cvcRef.current.value : '' ).replace( /\D/g, '' );
				var plan = planRef.current ? planRef.current.value : '0';

				var fail = function ( message ) {
					return { type: responseTypes.ERROR, message: message, messageContext: noticeContexts.PAYMENTS };
				};

				if ( name.length < 3 ) {
					return fail( 'Ingrese el nombre como aparece en la tarjeta.' );
				}
				if ( ! luhn( pan ) ) {
					return fail( 'El numero de tarjeta no es valido.' );
				}
				if ( ! brand( pan ) ) {
					return fail( 'Solo se aceptan tarjetas Visa y Mastercard.' );
				}
				var month = parseInt( exp.slice( 0, 2 ), 10 );
				var year = 2000 + parseInt( exp.slice( 2, 4 ), 10 );
				var now = new Date();
				if ( 4 !== exp.length || month < 1 || month > 12 || year < now.getFullYear() ||
					( year === now.getFullYear() && month < now.getMonth() + 1 ) ) {
					return fail( 'La fecha de vencimiento no es valida.' );
				}
				if ( ! /^\d{3,4}$/.test( cvc ) || /^(0{3,4}|9{3,4})$/.test( cvc ) ) {
					return fail( 'El codigo de seguridad (CVV) no es valido.' );
				}

				return {
					type: responseTypes.SUCCESS,
					meta: {
						paymentMethodData: {
							neopay_card_name: name,
							neopay_card_number: pan,
							neopay_card_expiry: exp,
							neopay_card_cvc: cvc,
							neopay_installments: plan
						}
					}
				};
			} );
		}, [ onPaymentSetup, responseTypes, noticeContexts ] );

		var children = [];
		var plans = data.installments || {};
		var planKeys = Object.keys( plans );

		if ( data.description ) {
			children.push( el( 'p', { key: 'desc' }, decode( data.description ) ) );
		}
		if ( data.is_test ) {
			children.push( el( 'p', { key: 'test', className: 'neopay-test-banner' }, el( 'strong', null, 'AMBIENTE DE PRUEBAS: no se realizaran cargos reales.' ) ) );
			if ( data.test_cards && data.test_cards.length ) {
				children.push( el( 'details', { key: 'cards', className: 'neopay-test-cards' }, [
					el( 'summary', { key: 's' }, 'Tarjetas de prueba' ),
					el( 'ul', { key: 'u' }, data.test_cards.map( function ( c, i ) {
						return el( 'li', { key: i }, [ el( 'code', { key: 'c' }, c.number ), ' ' + c.brand ] );
					} ).concat( [ el( 'li', { key: 'x' }, 'Vencimiento 01/29 (2901), CVV 123' ) ] ) )
				] ) );
			}
		}

		if ( planKeys.length > 1 ) {
			children.push( row( 'plan', data.installments_label || 'Cuotas', el( 'select', { key: 'i', id: 'neopay-b-plan', ref: planRef },
				planKeys.map( function ( k ) {
					return el( 'option', { key: k, value: k }, plans[ k ] );
				} )
			) ) );
			if ( data.installments_note ) {
				children.push( el( 'small', { key: 'note', className: 'neopay-note' }, data.installments_note ) );
			}
		}

		children.push( row( 'name', 'Nombre en la tarjeta', el( 'input', {
			key: 'i', id: 'neopay-b-name', ref: nameRef, type: 'text', autoComplete: 'cc-name', maxLength: 45, spellCheck: false
		} ) ) );
		children.push( row( 'number', 'Numero de tarjeta', el( 'input', {
			key: 'i', id: 'neopay-b-number', ref: numberRef, type: 'text', inputMode: 'numeric', autoComplete: 'cc-number', maxLength: 23, spellCheck: false,
			onInput: function ( e ) {
				e.target.value = e.target.value.replace( /\D/g, '' ).slice( 0, 19 ).replace( /(\d{4})(?=\d)/g, '$1 ' );
			}
		} ) ) );
		children.push( el( 'div', { key: 'grid', className: 'neopay-blocks-grid' }, [
			row( 'exp', 'Vencimiento (MM / AA)', el( 'input', {
				key: 'i', id: 'neopay-b-exp', ref: expiryRef, type: 'text', inputMode: 'numeric', autoComplete: 'cc-exp', maxLength: 7, placeholder: 'MM / AA',
				onInput: function ( e ) {
					var raw = e.target.value.replace( /\D/g, '' ).slice( 0, 4 );
					e.target.value = raw.length >= 3 ? raw.slice( 0, 2 ) + ' / ' + raw.slice( 2 ) : raw;
				}
			} ) ),
			row( 'cvc', 'CVV', el( 'input', {
				key: 'i', id: 'neopay-b-cvc', ref: cvcRef, type: 'password', inputMode: 'numeric', autoComplete: 'cc-csc', maxLength: 4, placeholder: '•••',
				onInput: function ( e ) {
					e.target.value = e.target.value.replace( /\D/g, '' );
				}
			} ) )
		] ) );

		if ( data.logos ) {
			children.push( el( 'p', { key: 'logos', className: 'neopay-brands' }, Object.keys( data.logos ).map( function ( k ) {
				return el( 'img', { key: k, src: data.logos[ k ], alt: k, width: 46, height: 28 } );
			} ) ) );
		}

		return el( 'div', { className: 'neopay-blocks-form' }, children );
	}

	var title = decode( data.title || 'NeoPay' );

	registry.registerPaymentMethod( {
		name: 'neopay_woo',
		label: el( 'span', { className: 'neopay-blocks-label' }, title ),
		ariaLabel: title,
		content: el( CardForm, null ),
		edit: el( 'div', null, title ),
		canMakePayment: function () {
			return true;
		},
		supports: { features: data.supports || [ 'products' ] }
	} );
} )( window );
