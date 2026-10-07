/**
 * Formato de los campos de tarjeta en el checkout clasico.
 *
 * @package NeoPay_Woo
 */
( function ( $ ) {
	'use strict';

	$( document.body ).on( 'input', '#neopay_card_number', function () {
		var raw = this.value.replace( /\D/g, '' ).slice( 0, 19 );
		this.value = raw.replace( /(\d{4})(?=\d)/g, '$1 ' );
	} );

	$( document.body ).on( 'input', '#neopay_card_expiry', function ( event ) {
		var raw = this.value.replace( /\D/g, '' ).slice( 0, 4 );
		var deleting = event.originalEvent && 'deleteContentBackward' === event.originalEvent.inputType;

		if ( raw.length >= 3 ) {
			this.value = raw.slice( 0, 2 ) + ' / ' + raw.slice( 2 );
		} else if ( 2 === raw.length && ! deleting ) {
			this.value = raw + ' / ';
		} else {
			this.value = raw;
		}
	} );

	$( document.body ).on( 'input', '#neopay_card_cvc', function () {
		this.value = this.value.replace( /\D/g, '' ).slice( 0, 4 );
	} );
} )( jQuery );
