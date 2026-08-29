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

	// ---- Keyword strategy generation -----------------------------------
	function bindKeywordStrategy() {
		var form = document.getElementById( 'scc-keyword-form' );
		if ( ! form ) {
			return;
		}
		var status = document.getElementById( 'scc-keyword-status' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var data = {};
			Array.prototype.forEach.call( form.elements, function ( el ) {
				if ( el.name ) {
					data[ el.name ] = el.value;
				}
			} );
			setStatus( status, 'Building topical map… this can take a moment.' );
			var btn = form.querySelector( 'button[type=submit]' );
			if ( btn ) {
				btn.disabled = true;
			}
			request( '/keywords', { method: 'POST', data: data } )
				.then( function () {
					setStatus( status, 'Done. Reloading…', 'is-ok' );
					window.location.reload();
				} )
				.catch( function ( err ) {
					if ( btn ) {
						btn.disabled = false;
					}
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Seed content plan from architecture ---------------------------
	function bindSeedPlan() {
		var btn = document.getElementById( 'scc-seed-plan' );
		if ( ! btn ) {
			return;
		}
		var status = document.getElementById( 'scc-seed-status' );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			setStatus( status, '…' );
			request( '/content-plan/seed', { method: 'POST' } )
				.then( function ( res ) {
					var created = ( res.data && res.data.created ) || 0;
					setStatus( status, created + ' new page(s) added to your content plan.', 'is-ok' );
					btn.disabled = false;
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Content plan status / delete ----------------------------------
	function bindContentPlan() {
		var table = document.getElementById( 'scc-plan-table' );
		if ( ! table ) {
			return;
		}
		var status = document.getElementById( 'scc-plan-status-msg' );

		table.addEventListener( 'change', function ( e ) {
			if ( ! e.target.classList.contains( 'scc-plan-status' ) ) {
				return;
			}
			var row = e.target.closest( 'tr' );
			var id = row.getAttribute( 'data-id' );
			request( '/content-plan/' + id, { method: 'PUT', data: { status: e.target.value } } )
				.then( function () {
					setStatus( status, i18n.saved || 'Saved.', 'is-ok' );
				} )
				.catch( function ( err ) {
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );

		table.addEventListener( 'click', function ( e ) {
			if ( ! e.target.classList.contains( 'scc-plan-delete' ) ) {
				return;
			}
			e.preventDefault();
			var row = e.target.closest( 'tr' );
			var id = row.getAttribute( 'data-id' );
			if ( ! window.confirm( 'Remove this entry from the plan?' ) ) {
				return;
			}
			request( '/content-plan/' + id, { method: 'DELETE' } )
				.then( function () {
					row.parentNode.removeChild( row );
					setStatus( status, 'Removed.', 'is-ok' );
				} )
				.catch( function ( err ) {
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Generate content (brief + draft) ------------------------------
	function el( tag, text, cls ) {
		var e = document.createElement( tag );
		if ( text ) {
			e.textContent = text;
		}
		if ( cls ) {
			e.className = cls;
		}
		return e;
	}

	function renderBrief( panel, brief ) {
		panel.innerHTML = '';
		panel.appendChild( el( 'h3', 'Content brief' ) );
		if ( brief.summary ) {
			panel.appendChild( el( 'p', brief.summary ) );
		}
		var meta = el( 'div' );
		meta.appendChild( el( 'span', 'Intent: ' + ( brief.search_intent || '—' ), 'scc-flag' ) );
		meta.appendChild( el( 'span', 'Target: ' + ( brief.recommended_words || '—' ) + ' words', 'scc-flag' ) );
		panel.appendChild( meta );

		function list( title, items ) {
			if ( ! items || ! items.length ) {
				return;
			}
			panel.appendChild( el( 'div', title, 'scc-label' ) );
			var ul = el( 'ul' );
			items.forEach( function ( it ) {
				if ( typeof it === 'object' ) {
					ul.appendChild( el( 'li', ( it.heading || '' ) + ( it.purpose ? ' — ' + it.purpose : '' ) ) );
				} else {
					ul.appendChild( el( 'li', it ) );
				}
			} );
			panel.appendChild( ul );
		}
		list( 'Outline', brief.outline );
		list( 'Questions to answer', brief.questions );
		list( 'Entities', brief.entities );
		list( 'Internal link targets', brief.internal_link_targets );
		if ( brief.cta ) {
			panel.appendChild( el( 'div', 'CTA', 'scc-label' ) );
			panel.appendChild( el( 'p', brief.cta ) );
		}
	}

	function bindGenerate() {
		var table = document.getElementById( 'scc-generate-table' );
		if ( ! table ) {
			return;
		}
		var msg = document.getElementById( 'scc-generate-msg' );

		table.addEventListener( 'click', function ( e ) {
			var isBrief = e.target.classList.contains( 'scc-brief-btn' );
			var isGen = e.target.classList.contains( 'scc-generate-btn' );
			if ( ! isBrief && ! isGen ) {
				return;
			}
			var row = e.target.closest( 'tr' );
			var id = row.getAttribute( 'data-id' );
			var briefRow = row.nextElementSibling;
			var panel = briefRow ? briefRow.querySelector( '.scc-brief-panel' ) : null;
			e.target.disabled = true;

			if ( isBrief ) {
				setStatus( msg, 'Generating brief…' );
				request( '/brief', { method: 'POST', data: { entry_id: id } } )
					.then( function ( res ) {
						briefRow.hidden = false;
						renderBrief( panel, ( res.data && res.data.brief ) || {} );
						setStatus( msg, i18n.saved || '', 'is-ok' );
						e.target.disabled = false;
					} )
					.catch( function ( err ) {
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
						e.target.disabled = false;
					} );
			} else {
				setStatus( msg, 'Generating draft… this can take up to a minute.' );
				var statusCell = row.querySelector( '.scc-gen-status' );
				if ( statusCell ) {
					statusCell.textContent = 'generating';
				}
				request( '/generate', { method: 'POST', data: { entry_id: id } } )
					.then( function ( res ) {
						var d = res.data || {};
						if ( statusCell ) {
							statusCell.textContent = d.status || 'draft';
						}
						if ( panel && briefRow ) {
							briefRow.hidden = false;
							panel.innerHTML = '';
							var score = ( d.score && d.score.score ) || 0;
							panel.appendChild( el( 'p', 'Draft created — optimization score ' + score + '/100 (internal guide, not a ranking guarantee).' ) );
							if ( d.edit_url ) {
								var a = el( 'a', 'Edit draft in WordPress' );
								a.href = d.edit_url;
								a.className = 'button button-primary';
								panel.appendChild( a );
							}
						}
						setStatus( msg, 'Draft created.', 'is-ok' );
						e.target.disabled = false;
					} )
					.catch( function ( err ) {
						if ( statusCell ) {
							statusCell.textContent = 'failed';
						}
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
						e.target.disabled = false;
					} );
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindAnalysis();
		bindSettings();
		bindConnections();
		bindKeywordStrategy();
		bindSeedPlan();
		bindContentPlan();
		bindGenerate();
	} );
} )();
