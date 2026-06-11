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

	/* ------------------------------------------------------------------
	 * Metabox: karakter sayaçları + snippet önizleme
	 * ---------------------------------------------------------------- */
	document.querySelectorAll( '.wpwix-counter' ).forEach( function ( counter ) {
		var field = document.getElementById( counter.dataset.for );
		var max   = parseInt( counter.dataset.max, 10 );
		if ( ! field ) {
			return;
		}

		function update() {
			var len = field.value.length;
			counter.textContent = len + '/' + max;
			counter.classList.toggle( 'wpwix-over', len > max );
		}

		field.addEventListener( 'input', update );
		update();
	} );

	function bindPreview( fieldId, previewId ) {
		var field = document.getElementById( fieldId );
		var preview = document.getElementById( previewId );
		if ( field && preview ) {
			field.addEventListener( 'input', function () {
				preview.textContent = field.value;
			} );
		}
	}
	bindPreview( 'wpwix-field-title', 'wpwix-preview-title' );
	bindPreview( 'wpwix-field-desc', 'wpwix-preview-desc' );

	/* ------------------------------------------------------------------
	 * "AI ile Doldur" butonu (metabox)
	 * ---------------------------------------------------------------- */
	document.querySelectorAll( '.wpwix-generate' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var status = btn.parentNode.querySelector( '.wpwix-generate-status' );
			btn.disabled = true;
			if ( status ) {
				status.textContent = wpwixSeo.i18n.generating;
				status.className = 'wpwix-generate-status';
			}

			post( 'wpwix_generate_single', { product_id: btn.dataset.product } )
				.then( function ( res ) {
					if ( ! res.success ) {
						throw ( res.data && res.data.message ) || '';
					}

					var map = {
						'wpwix-field-title': 'seo_title',
						'wpwix-field-desc': 'meta_description',
						'wpwix-field-kw': 'focus_keyword',
						'wpwix-field-alt': 'image_alt'
					};
					Object.keys( map ).forEach( function ( id ) {
						var field = document.getElementById( id );
						if ( field ) {
							field.value = res.data.fields[ map[ id ] ] || '';
							field.dispatchEvent( new Event( 'input' ) );
						}
					} );

					var badge = document.getElementById( 'wpwix-score-badge' );
					if ( badge && typeof res.data.score !== 'undefined' ) {
						badge.textContent = res.data.score + '/100';
						badge.className = 'wpwix-score-badge ' +
							( res.data.score >= 80 ? 'wpwix-score-green' : res.data.score >= 50 ? 'wpwix-score-yellow' : 'wpwix-score-red' );
					}

					if ( status ) {
						status.textContent = wpwixSeo.i18n.generated;
						status.className = 'wpwix-generate-status wpwix-ok';
					}
				} )
				.catch( function ( err ) {
					if ( status ) {
						status.textContent = wpwixSeo.i18n.error + err;
						status.className = 'wpwix-generate-status wpwix-err';
					}
				} )
				.finally( function () {
					btn.disabled = false;
				} );
		} );
	} );
} )();
