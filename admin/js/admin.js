/**
 * WPWix SEO — admin JS (vanilla, bağımlılıksız).
 * Sadece admin ekranlarında yüklenir; frontend'e asla çıkmaz.
 */
( function () {
	'use strict';

	if ( typeof wpwixSeo === 'undefined' ) {
		return;
	}

	function post( action, data ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', wpwixSeo.nonce );
		Object.keys( data || {} ).forEach( function ( key ) {
			body.append( key, data[ key ] );
		} );
		return fetch( wpwixSeo.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( res ) { return res.json(); } );
	}

	/* ------------------------------------------------------------------
	 * Ayarlar: bağlantı testi
	 * ---------------------------------------------------------------- */
	var testBtn = document.getElementById( 'wpwix-test-connection' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var result = document.getElementById( 'wpwix-test-result' );
			var custom = document.getElementById( 'wpwix-custom-model' );
			var model  = custom && custom.value.trim() ? custom.value.trim() : document.getElementById( 'wpwix-model' ).value;

			result.textContent = wpwixSeo.i18n.testing;
			result.className = '';
			testBtn.disabled = true;

			post( 'wpwix_test_connection', {
				api_key: document.getElementById( 'wpwix-api-key' ).value,
				model: model
			} ).then( function ( res ) {
				if ( res.success ) {
					result.textContent = wpwixSeo.i18n.testOk;
					result.className = 'wpwix-ok';
				} else {
					result.textContent = wpwixSeo.i18n.error + ( res.data && res.data.message ? res.data.message : '' );
					result.className = 'wpwix-err';
				}
			} ).catch( function ( err ) {
				result.textContent = wpwixSeo.i18n.error + err;
				result.className = 'wpwix-err';
			} ).finally( function () {
				testBtn.disabled = false;
			} );
		} );
	}
} )();
