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

	/* ------------------------------------------------------------------
	 * "AI ile Açıklama Yaz" butonu (metabox) — editöre yerleştirir,
	 * kullanıcı kontrol edip kendisi kaydeder.
	 * ---------------------------------------------------------------- */
	function setEditorContent( html ) {
		if ( window.tinyMCE && tinyMCE.get( 'content' ) && ! tinyMCE.get( 'content' ).isHidden() ) {
			tinyMCE.get( 'content' ).setContent( html );
			return true;
		}
		var textarea = document.getElementById( 'content' );
		if ( textarea ) {
			textarea.value = html;
			return true;
		}
		return false;
	}

	document.querySelectorAll( '.wpwix-generate-desc' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			var status = btn.parentNode.querySelector( '.wpwix-generate-status' );
			btn.disabled = true;
			if ( status ) {
				status.textContent = wpwixSeo.i18n.generating;
				status.className = 'wpwix-generate-status';
			}

			post( 'wpwix_generate_description', { product_id: btn.dataset.product } )
				.then( function ( res ) {
					if ( ! res.success ) {
						throw ( res.data && res.data.message ) || '';
					}
					if ( ! setEditorContent( res.data.description ) ) {
						throw 'editor not found';
					}
					if ( status ) {
						status.textContent = wpwixSeo.i18n.descReady;
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

	/* ------------------------------------------------------------------
	 * Toplu işlemler: tara, üret (progress + durdur/devam)
	 * ---------------------------------------------------------------- */
	var scanBtn  = document.getElementById( 'wpwix-bulk-scan' );
	var startBtn = document.getElementById( 'wpwix-bulk-start' );
	var stopBtn  = document.getElementById( 'wpwix-bulk-stop' );

	if ( scanBtn && startBtn && stopBtn ) {
		var message  = document.getElementById( 'wpwix-bulk-message' );
		var progress = document.getElementById( 'wpwix-bulk-progress' );
		var bar      = document.getElementById( 'wpwix-bulk-bar' );
		var barText  = document.getElementById( 'wpwix-bulk-text' );
		var report   = document.getElementById( 'wpwix-bulk-report' );
		var running  = false;

		function renderProgress( data ) {
			progress.style.display = 'block';
			var pct = data.total > 0 ? Math.round( ( data.done / data.total ) * 100 ) : 0;
			bar.style.width = pct + '%';
			barText.textContent = data.done + '/' + data.total + ( data.current ? ' — ' + data.current : '' );
		}

		function renderReport( data ) {
			var errors = Object.keys( data.errors || {} ).length;
			var text = wpwixSeo.i18n.done + ': ' + data.success + ' ✅';
			if ( errors > 0 ) {
				text += ' / ' + errors + ' ❌';
			}
			report.innerHTML = '';
			var p = document.createElement( 'p' );
			p.innerHTML = '<strong>' + text + '</strong>';
			report.appendChild( p );

			if ( errors > 0 ) {
				var list = document.createElement( 'ul' );
				Object.keys( data.errors ).forEach( function ( id ) {
					var li = document.createElement( 'li' );
					li.textContent = '#' + id + ': ' + data.errors[ id ];
					list.appendChild( li );
				} );
				report.appendChild( list );
			}
		}

		function step() {
			if ( ! running ) {
				return;
			}

			post( 'wpwix_bulk_step', {} ).then( function ( res ) {
				if ( ! res.success ) {
					throw ( res.data && res.data.message ) || '';
				}

				renderProgress( res.data );

				if ( res.data.finished ) {
					running = false;
					stopBtn.style.display = 'none';
					startBtn.disabled = true;
					renderReport( res.data );
					return;
				}

				// Ücretsiz katman koruması: istekler arası bekleme.
				window.setTimeout( step, Math.max( 0, wpwixSeo.requestDelay ) * 1000 );
			} ).catch( function ( err ) {
				running = false;
				stopBtn.style.display = 'none';
				startBtn.disabled = false;
				message.textContent = wpwixSeo.i18n.error + err;
			} );
		}

		scanBtn.addEventListener( 'click', function () {
			scanBtn.disabled = true;
			var contentBox = document.getElementById( 'wpwix-bulk-content' );
			post( 'wpwix_bulk_scan', { with_content: contentBox && contentBox.checked ? 1 : 0 } ).then( function ( res ) {
				if ( res.success ) {
					message.textContent = res.data.message;
					startBtn.disabled = res.data.count === 0;
					progress.style.display = 'none';
					report.innerHTML = '';
				}
			} ).finally( function () {
				scanBtn.disabled = false;
			} );
		} );

		startBtn.addEventListener( 'click', function () {
			running = true;
			startBtn.disabled = true;
			stopBtn.style.display = '';
			report.innerHTML = '';
			step();
		} );

		stopBtn.addEventListener( 'click', function () {
			running = false;
			stopBtn.style.display = 'none';
			startBtn.disabled = false;
			startBtn.textContent = startBtn.textContent.trim();
			message.textContent = wpwixSeo.i18n.stopped;
		} );
	}
} )();
