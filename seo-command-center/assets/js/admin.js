/**
 * SEO Command Center — admin JS.
 * Talks to the internal REST API using wp.apiFetch with the wp_rest nonce.
 * No API keys are ever present in this file or the page.
 */
( function () {
	'use strict';

	if ( typeof window.SCC === 'undefined' || typeof window.wp === 'undefined' || ! window.wp.apiFetch ) {
		return;
	}

	var api = window.wp.apiFetch;
	var i18n = window.SCC.i18n || {};

	// Attach the REST nonce to every request.
	api.use( api.createNonceMiddleware( window.SCC.nonce ) );
	api.use( api.createRootURLMiddleware( window.SCC.restUrl.replace( /seo-command\/v1$/, '' ) ) );

	function setStatus( el, message, state ) {
		if ( ! el ) {
			return;
		}
		el.textContent = message || '';
		el.classList.remove( 'is-error', 'is-ok' );
		if ( state ) {
			el.classList.add( state );
		}
	}

	function request( path, options ) {
		options = options || {};
		options.path = '/seo-command/v1' + path;
		return api( options );
	}

	// ---- Run analysis ---------------------------------------------------
	function bindAnalysis() {
		var buttons = document.querySelectorAll( '#scc-run-analysis' );
		var status = document.getElementById( 'scc-analysis-status' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				btn.disabled = true;
				setStatus( status, i18n.analyzing || 'Analyzing…' );
				request( '/analyze', { method: 'POST', data: { limit: 300 } } )
					.then( function ( res ) {
						setStatus( status, 'Done. Reloading…', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		} );
	}

	// ---- Settings save --------------------------------------------------
	function bindSettings() {
		var form = document.getElementById( 'scc-settings-form' );
		if ( ! form ) {
			return;
		}
		var status = document.getElementById( 'scc-settings-status' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var settings = {};
			Array.prototype.forEach.call( form.elements, function ( el ) {
				if ( ! el.name ) {
					return;
				}
				if ( el.type === 'checkbox' ) {
					settings[ el.name ] = el.checked;
				} else {
					settings[ el.name ] = el.value;
				}
			} );
			setStatus( status, '…' );
			request( '/settings', { method: 'POST', data: { settings: settings } } )
				.then( function () {
					setStatus( status, i18n.saved || 'Saved.', 'is-ok' );
				} )
				.catch( function ( err ) {
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Connections (API keys) ----------------------------------------
	function bindConnections() {
		var form = document.getElementById( 'scc-connections-form' );
		if ( ! form ) {
			return;
		}
		var status = document.getElementById( 'scc-connections-status' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var credentials = {};
			// Key inputs.
			Array.prototype.forEach.call( form.querySelectorAll( 'input[data-field]' ), function ( el ) {
				var field = el.getAttribute( 'data-field' );
				if ( el.value ) {
					credentials[ field ] = el.value;
				}
			} );
			// Clear checkboxes.
			Array.prototype.forEach.call( form.querySelectorAll( 'input[data-clear]' ), function ( el ) {
				if ( el.checked ) {
					var field = el.getAttribute( 'data-clear' );
					credentials[ field ] = '';
					credentials[ field + '_clear' ] = true;
				}
			} );
			setStatus( status, '…' );
			request( '/settings', { method: 'POST', data: { credentials: credentials } } )
				.then( function () {
					setStatus( status, ( i18n.saved || 'Saved.' ) + ' Reloading…', 'is-ok' );
					window.location.reload();
				} )
				.catch( function ( err ) {
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );

		// Provider test buttons.
		Array.prototype.forEach.call( form.querySelectorAll( '[data-test-provider]' ), function ( btn ) {
			var testStatus = document.getElementById( 'scc-test-status' );
			btn.addEventListener( 'click', function () {
				var provider = btn.getAttribute( 'data-test-provider' );
				btn.disabled = true;
				setStatus( testStatus, i18n.testing || 'Testing…' );
				request( '/ai/test', { method: 'POST', data: { provider: provider } } )
					.then( function ( res ) {
						var d = res.data || {};
						setStatus( testStatus, 'OK — ' + ( d.model || provider ) + ' (' + ( d.latency_ms || '?' ) + 'ms)', 'is-ok' );
						btn.disabled = false;
					} )
					.catch( function ( err ) {
						setStatus( testStatus, ( err && err.message ) || i18n.error, 'is-error' );
						btn.disabled = false;
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindAnalysis();
		bindSettings();
		bindConnections();
	} );
} )();
