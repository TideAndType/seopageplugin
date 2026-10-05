/**
 * TideOrbit — admin JS.
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
		message = message || '';
		el.textContent = message;
		el.classList.remove( 'is-error', 'is-ok', 'scc-loading' );
		if ( state ) {
			el.classList.add( state );
		}
		// Any in-progress message (ends with an ellipsis, no ok/error state) gets a
		// calm spinner automatically — consistent async feedback everywhere.
		if ( message && ! state && ( /[…]\s*$/.test( message ) || /\.\.\.\s*$/.test( message ) ) ) {
			el.classList.add( 'scc-loading' );
		}
	}

	// Shared HTML escaper for any screen that builds markup from API data.
	// (Some screens keep a local copy; this guarantees one always exists.)
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
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
				var deepEl = document.getElementById( 'scc-deep-scan' );
				var deep = deepEl ? !! deepEl.checked : false;
				setStatus( status, deep ? 'Deep scanning (fetching rendered pages)…' : ( i18n.analyzing || 'Analyzing…' ) );
				request( '/analyze', { method: 'POST', data: { limit: 300, deep: deep } } )
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

	// ---- Per-task AI routing (model dropdowns) -------------------------
	function bindRouteModels() {
		var table = document.getElementById( 'scc-route-table' );
		if ( ! table ) {
			return;
		}
		var providers = window.SCC.providers || {};

		function fillModels( providerSel ) {
			var row = providerSel.closest( 'tr' );
			var modelSel = row.querySelector( '.scc-route-model' );
			if ( ! modelSel ) {
				return;
			}
			var pid = providerSel.value;
			var wanted = modelSel.getAttribute( 'data-selected' ) || modelSel.value || '';
			modelSel.innerHTML = '';
			var def = document.createElement( 'option' );
			def.value = '';
			def.textContent = pid ? 'Default model for this provider' : 'Default model';
			modelSel.appendChild( def );

			if ( pid && providers[ pid ] && providers[ pid ].models ) {
				modelSel.disabled = false;
				Object.keys( providers[ pid ].models ).forEach( function ( mid ) {
					var opt = document.createElement( 'option' );
					opt.value = mid;
					opt.textContent = providers[ pid ].models[ mid ];
					if ( mid === wanted ) {
						opt.selected = true;
					}
					modelSel.appendChild( opt );
				} );
			} else {
				// "Use primary provider" — model is not applicable.
				modelSel.disabled = true;
			}
		}

		Array.prototype.forEach.call( table.querySelectorAll( '.scc-route-provider' ), function ( sel ) {
			fillModels( sel ); // initial populate from saved values
			sel.addEventListener( 'change', function () {
				var row = sel.closest( 'tr' );
				var modelSel = row.querySelector( '.scc-route-model' );
				if ( modelSel ) {
					modelSel.setAttribute( 'data-selected', '' ); // reset saved on manual change
				}
				fillModels( sel );
			} );
		} );
	}

	// ---- LM Studio: detect models --------------------------------------
	function bindLmStudioDetect() {
		var btn = document.getElementById( 'scc-lmstudio-detect' );
		if ( ! btn ) {
			return;
		}
		var status = document.getElementById( 'scc-lmstudio-detect-status' );

		// Keep the hidden saved value in sync with the dropdown / custom field
		// (Connections page). bindConnections saves #scc-lmstudio-model.
		var modelHidden = document.getElementById( 'scc-lmstudio-model' );
		var modelSelectEl = document.getElementById( 'scc-lmstudio-model-select' );
		var modelCustomEl = document.getElementById( 'scc-lmstudio-model-custom' );
		if ( modelSelectEl && modelHidden ) {
			modelSelectEl.addEventListener( 'change', function () {
				modelHidden.value = modelSelectEl.value;
				if ( modelCustomEl ) { modelCustomEl.value = ''; }
			} );
		}
		if ( modelCustomEl && modelHidden ) {
			modelCustomEl.addEventListener( 'input', function () {
				if ( modelCustomEl.value.trim() ) {
					modelHidden.value = modelCustomEl.value.trim();
				} else if ( modelSelectEl ) {
					modelHidden.value = modelSelectEl.value;
				}
			} );
		}

		btn.addEventListener( 'click', function () {
			var baseEl = document.getElementById( 'scc-lmstudio-base' );
			var base = baseEl ? baseEl.value : '';
			btn.disabled = true;
			setStatus( status, 'Contacting LM Studio…' );
			request( '/lmstudio/models', { method: 'POST', data: { base_url: base } } )
				.then( function ( res ) {
					btn.disabled = false;
					var d = res.data || {};
					if ( ! d.ok ) {
						setStatus( status, d.error || 'Could not reach LM Studio.', 'is-error' );
						return;
					}
					var list = document.getElementById( 'scc-lmstudio-model-list' );
					var modelInput = document.getElementById( 'scc-lmstudio-model' ); // hidden (Connections) or text (Settings)
					var modelSelect = document.getElementById( 'scc-lmstudio-model-select' ); // real dropdown (Connections)
					if ( list ) {
						list.innerHTML = '';
						( d.models || [] ).forEach( function ( m ) {
							var opt = document.createElement( 'option' );
							opt.value = m;
							list.appendChild( opt );
						} );
					}
					if ( ! d.models || ! d.models.length ) {
						setStatus( status, 'Connected, but no model is loaded in LM Studio. Load one in LM Studio and click Detect again.', 'is-error' );
						return;
					}
					var prev = modelInput ? modelInput.value : '';
					// Populate the real dropdown (Connections page).
					if ( modelSelect ) {
						modelSelect.innerHTML = '';
						d.models.forEach( function ( m ) {
							var opt = document.createElement( 'option' );
							opt.value = m;
							opt.textContent = m;
							if ( m === prev ) { opt.selected = true; }
							modelSelect.appendChild( opt );
						} );
						// If the saved value isn't among the detected models, select the first.
						if ( d.models.indexOf( prev ) === -1 ) {
							modelSelect.value = d.models[0];
						}
						if ( modelInput ) { modelInput.value = modelSelect.value; }
					} else if ( modelInput && ( ! modelInput.value || modelInput.value === 'local-model' ) ) {
						// Settings → AI text field: auto-fill the first model.
						modelInput.value = d.models[0];
					}
					// Feed detected models into the per-task routing dropdowns too.
					if ( window.SCC && window.SCC.providers && window.SCC.providers.lmstudio ) {
						var map = {};
						d.models.forEach( function ( m ) { map[ m ] = m; } );
						window.SCC.providers.lmstudio.models = map;
						Array.prototype.forEach.call( document.querySelectorAll( '.scc-route-provider' ), function ( sel ) {
							if ( sel.value !== 'lmstudio' ) { return; }
							var row = sel.closest( 'tr' );
							var modelSel = row ? row.querySelector( '.scc-route-model' ) : null;
							if ( ! modelSel ) { return; }
							var keep = modelSel.value;
							modelSel.innerHTML = '';
							var def = document.createElement( 'option' );
							def.value = '';
							def.textContent = 'Default model for this provider';
							modelSel.appendChild( def );
							d.models.forEach( function ( m ) {
								var opt = document.createElement( 'option' );
								opt.value = m;
								opt.textContent = m;
								if ( m === keep ) { opt.selected = true; }
								modelSel.appendChild( opt );
							} );
							modelSel.disabled = false;
						} );
					}
					setStatus( status, 'Connected — ' + d.models.length + ' model(s): ' + d.models.join( ', ' ) + '. Pick one and Save.', 'is-ok' );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
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
			// Some fields on this page are settings, not credentials — save them alongside.
			var payload = { credentials: credentials };
			var settings = {};
			var gscSite = document.getElementById( 'scc-gsc-site' );
			if ( gscSite ) {
				settings.gsc_site_url = gscSite.value;
			}
			var lmBase = document.getElementById( 'scc-lmstudio-base' );
			if ( lmBase ) {
				settings.lmstudio_base_url = lmBase.value;
			}
			var lmModel = document.getElementById( 'scc-lmstudio-model' );
			if ( lmModel ) {
				settings.lmstudio_model = lmModel.value;
			}
			payload.settings = settings;
			setStatus( status, '…' );
			request( '/settings', { method: 'POST', data: payload } )
				.then( function () {
					setStatus( status, ( i18n.saved || 'Saved.' ) + ' Reloading…', 'is-ok' );
					window.location.reload();
				} )
				.catch( function ( err ) {
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );

		// GSC one-click connect button.
		var gscConnect = document.getElementById( 'scc-gsc-connect' );
		if ( gscConnect ) {
			gscConnect.addEventListener( 'click', function () {
				gscConnect.disabled = true;
				request( '/gsc/auth-url', { method: 'GET' } )
					.then( function ( res ) {
						var url = res.data && res.data.url;
						if ( url ) {
							window.location.href = url;
							return;
						}
						gscConnect.disabled = false;
					} )
					.catch( function ( err ) {
						gscConnect.disabled = false;
						window.alert( ( err && err.message ) || 'Could not start Google connection.' );
					} );
			} );
		}

		// GSC disconnect.
		var gscDisconnect = document.getElementById( 'scc-gsc-disconnect' );
		if ( gscDisconnect ) {
			gscDisconnect.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Disconnect Google Search Console from TideOrbit?' ) ) {
					return;
				}
				gscDisconnect.disabled = true;
				request( '/gsc/disconnect', { method: 'POST', data: {} } )
					.then( function () { window.location.reload(); } )
					.catch( function ( err ) {
						gscDisconnect.disabled = false;
						window.alert( ( err && err.message ) || 'Could not disconnect Search Console.' );
					} );
			} );
		}

		// Copy redirect URI for Advanced self-hosted mode.
		var copyRedirect = document.getElementById( 'scc-gsc-copy-redirect' );
		if ( copyRedirect ) {
			copyRedirect.addEventListener( 'click', function () {
				var codeEl = document.getElementById( 'scc-gsc-redirect' );
				var copyText = codeEl ? codeEl.textContent : '';
				if ( navigator.clipboard && copyText ) {
					navigator.clipboard.writeText( copyText ).then( function () {
						copyRedirect.textContent = 'Copied';
						setTimeout( function () { copyRedirect.textContent = 'Copy'; }, 1500 );
					} );
				}
			} );
		}

		// GSC verify + property discovery.
		var gscVerify = document.getElementById( 'scc-gsc-verify' );
		if ( gscVerify ) {
			var gscStatus = document.getElementById( 'scc-gsc-verify-status' );
			var gscOut = document.getElementById( 'scc-gsc-verify-out' );
			gscVerify.addEventListener( 'click', function () {
				gscVerify.disabled = true;
				setStatus( gscStatus, 'Checking…' );
				gscOut.innerHTML = '';
				request( '/gsc/verify', { method: 'GET' } )
					.then( function ( res ) {
						gscVerify.disabled = false;
						var v = ( res.data && res.data.verify ) || {};
						if ( ! v.connected ) {
							setStatus( gscStatus, v.error || 'Search Console is not connected.', 'is-error' );
							return;
						}
						if ( ! v.token_ok ) {
							setStatus( gscStatus, 'Google connection failed: ' + ( v.error || 'unknown error' ), 'is-error' );
							return;
						}
						setStatus( gscStatus, 'Connected — read-only Search Console access works.', 'is-ok' );
						if ( ! v.properties || ! v.properties.length ) {
							gscOut.appendChild( el( 'p', 'The Google account is connected but it has no Search Console properties.', 'scc-note' ) );
							return;
						}
						gscOut.appendChild( el( 'div', 'Properties this Google account can access:', 'scc-label' ) );
						var ul = el( 'ul', null, 'scc-options' );
						v.properties.forEach( function ( p ) {
							var li = el( 'li' );
							li.appendChild( el( 'code', p.siteUrl ) );
							li.appendChild( document.createTextNode( ' (' + p.permissionLevel + ')' ) );
							var useBtn = el( 'button', 'Use this', 'button button-small' );
							useBtn.style.marginLeft = '8px';
							useBtn.addEventListener( 'click', function () {
								var input = document.getElementById( 'scc-gsc-site' );
								if ( input ) { input.value = p.siteUrl; }
							} );
							li.appendChild( useBtn );
							ul.appendChild( li );
						} );
						gscOut.appendChild( ul );
						if ( ! v.property_matches ) {
							gscOut.appendChild( el( 'p', 'Choose the property for this WordPress site above, then Save connections.', 'scc-note' ) );
						} else {
							gscOut.appendChild( el( 'p', '✓ The configured property matches and Search Console data is ready.', 'scc-note' ) );
						}
					} )
					.catch( function ( err ) {
						gscVerify.disabled = false;
						setStatus( gscStatus, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

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
		// Replace the previous map on the right with a "building" placeholder, so
		// stale suggestions don't linger while a new run is in progress.
		function clearMapPanel( message ) {
			var result = document.getElementById( 'scc-keyword-result' );
			if ( ! result ) {
				return;
			}
			var heading = result.querySelector( 'h2' );
			result.innerHTML = '';
			if ( ! heading ) {
				heading = document.createElement( 'h2' );
				heading.textContent = 'Topical map';
			}
			result.appendChild( heading );
			var p = document.createElement( 'p' );
			p.className = 'scc-note';
			p.textContent = message || 'Building…';
			result.appendChild( p );
		}

		// "Build from my site" — infers inputs and generates in one click.
		var autoBtn = document.getElementById( 'scc-keyword-auto' );
		if ( autoBtn ) {
			var autoStatus = document.getElementById( 'scc-keyword-auto-status' );
			autoBtn.addEventListener( 'click', function () {
				autoBtn.disabled = true;
				clearMapPanel( 'Building your topical map… the previous suggestions will be replaced when this finishes.' );
				setStatus( autoStatus, 'Analyzing your site and building the topical map… this can take up to a minute or two with a local model — please wait.' );
				function optVal( id ) { var el = document.getElementById( id ); return el ? el.value : ''; }
				var genOpts = { map_type: optVal( 'scc-map-type' ), depth: optVal( 'scc-map-depth' ), language: optVal( 'scc-map-language' ) };
				request( '/keywords/auto', { method: 'POST', data: genOpts } )
					.then( function ( res ) {
						var d = res.data || {};
						if ( ! d.async ) {
							// Inline fallback — already done.
							setStatus( autoStatus, 'Done. Reloading…', 'is-ok' );
							window.location.reload();
							return;
						}
						// Fire the generation as a separate request we do NOT await.
						// It runs server-side with ignore_user_abort, so it finishes
						// even if this connection is dropped by a gateway timeout.
						fireProcess( d.job_id );
						pollAuto( d.job_id );
					} )
					.catch( function ( err ) {
						autoBtn.disabled = false;
						var msg = err && err.message;
						if ( ! msg || /something went wrong|gateway|cloudflare|timed? ?out|HTTP 50[0-9]|HTTP 52[0-9]|<html/i.test( msg ) ) {
							msg = 'The request was cut off before the map finished — the model usually took longer than your host allows for one request. The map may have finished and saved anyway: reload this page in a minute to check. Otherwise pick a lower “Depth” (Compact) and try again.';
						}
						setStatus( autoStatus, msg, 'is-error' );
					} );
			} );

			// Poll a background topical-map job until it finishes. The work runs
			// server-side, so the page request can never time out.
			function fireProcess( jobId ) {
				request( '/keywords/auto/process', { method: 'POST', data: { job: jobId } } )
					.then( function ( res ) {
						// If the host let this long request finish, act on it now
						// instead of waiting for the next poll.
						var d = res.data || {};
						if ( d.state === 'done' ) {
							setStatus( autoStatus, 'Done. Reloading…', 'is-ok' );
							window.location.reload();
						} else if ( d.state === 'error' && d.error ) {
							autoBtn.disabled = false;
							setStatus( autoStatus, d.error, 'is-error' );
						}
					} )
					.catch( function () { /* dropped by a gateway timeout — polling tracks it */ } );
			}

			function pollAuto( jobId ) {
				var started    = Date.now();
				var maxMs      = 15 * 60 * 1000; // 15 minutes — generous for slow local models.
				var dots       = 0;
				var lastKick   = Date.now();
				( function tick() {
					if ( Date.now() - started > maxMs ) {
						autoBtn.disabled = false;
						setStatus( autoStatus, 'Still working after 15 minutes — the model may be stuck. Check that your AI provider is reachable, try a faster model, or route the topical map to Gemini under Settings → AI.', 'is-error' );
						return;
					}
					request( '/keywords/auto/status?job=' + encodeURIComponent( jobId ), { method: 'GET' } )
						.then( function ( res ) {
							var d = res.data || {};
							if ( d.state === 'done' ) {
								setStatus( autoStatus, 'Done. Reloading…', 'is-ok' );
								window.location.reload();
								return;
							}
							if ( d.state === 'error' ) {
								autoBtn.disabled = false;
								var msg = d.error || '';
								if ( ! msg || /gateway|cloudflare|timed? ?out|HTTP 50[0-9]|HTTP 52[0-9]|<html/i.test( msg ) ) {
									msg = 'The AI model didn’t complete. If you’re using LM Studio over a tunnel, free tunnels cut off long requests — try a faster/smaller model, load it with more context, or route the topical map to Gemini under Settings → AI.';
								}
								setStatus( autoStatus, msg, 'is-error' );
								return;
							}
							// If it hasn't been picked up yet (still queued), re-fire the
							// process request — the first one may have been dropped.
							if ( d.status === 'queued' && ( Date.now() - lastKick ) > 8000 ) {
								lastKick = Date.now();
								fireProcess( jobId );
							}
							// Still running — keep the user informed and poll again.
							dots = ( dots + 1 ) % 4;
							var secs = Math.round( ( Date.now() - started ) / 1000 );
							setStatus( autoStatus, 'Building your topical map in the background' + new Array( dots + 1 ).join( '.' ) + ' (' + secs + 's) — you can leave this page open.' );
							window.setTimeout( tick, 3000 );
						} )
						.catch( function () {
							// A transient poll error (e.g. brief network blip) shouldn't
							// abort the job — keep polling.
							window.setTimeout( tick, 4000 );
						} );
				} )();
			}
		}

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
			clearMapPanel( 'Building your topical map… the previous suggestions will be replaced when this finishes.' );
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

	// ---- One-click content briefs (topical map) ------------------------
	function renderTopicBrief( brief ) {
		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s == null ? '' : String( s );
			return d.innerHTML;
		}
		function list( items, render ) {
			if ( ! items || ! items.length ) { return ''; }
			return '<ul>' + items.map( render ).join( '' ) + '</ul>';
		}
		var html = '';
		if ( brief.h1 ) { html += '<p><strong>H1:</strong> ' + esc( brief.h1 ) + '</p>'; }
		var meta = [];
		if ( brief.search_intent ) { meta.push( 'Intent: ' + esc( brief.search_intent ) ); }
		if ( brief.recommended_words ) { meta.push( '~' + esc( brief.recommended_words ) + ' words' ); }
		if ( meta.length ) { html += '<p class="scc-note">' + meta.join( ' · ' ) + '</p>'; }
		if ( brief.summary ) { html += '<p>' + esc( brief.summary ) + '</p>'; }
		if ( brief.outline && brief.outline.length ) {
			html += '<p><strong>Outline</strong></p>' + list( brief.outline, function ( o ) {
				var h = o.heading ? esc( o.heading ) : '';
				var p = o.purpose ? ' — <span class="scc-note">' + esc( o.purpose ) + '</span>' : '';
				return '<li>' + h + p + '</li>';
			} );
		}
		if ( brief.questions && brief.questions.length ) {
			html += '<p><strong>Questions to answer</strong></p>' + list( brief.questions, function ( q ) { return '<li>' + esc( q ) + '</li>'; } );
		}
		if ( brief.entities && brief.entities.length ) {
			html += '<p><strong>Entities:</strong> ' + esc( brief.entities.join( ', ' ) ) + '</p>';
		}
		if ( brief.internal_link_targets && brief.internal_link_targets.length ) {
			html += '<p><strong>Internal links</strong></p>' + list( brief.internal_link_targets, function ( t ) { return '<li>' + esc( t ) + '</li>'; } );
		}
		if ( brief.cta ) { html += '<p><strong>CTA:</strong> ' + esc( brief.cta ) + '</p>'; }
		return html || '<p class="scc-note">No brief content returned.</p>';
	}

	// ---- Search Console quick wins → create Content Plan pages ----------
	function bindGscWinsPlanButtons() {
		var table = document.getElementById( 'scc-gsc-wins-table' );
		if ( ! table ) {
			return;
		}
		table.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.scc-gsc-plan-btn' ) : null;
			if ( ! btn ) {
				return;
			}
			var q = btn.getAttribute( 'data-query' ) || '';
			var cell = btn.parentNode;
			var status = cell ? cell.querySelector( '.scc-gsc-plan-status' ) : null;
			btn.disabled = true;
			setStatus( status, 'Adding…' );
			request( '/content-plan', { method: 'POST', data: {
				title: q,
				primary_keyword: q,
				page_type: 'article',
				intent: 'informational',
				priority: 'high',
				status: 'recommended'
			} } )
				.then( function () {
					btn.textContent = 'Added ✓';
					setStatus( status, 'In your Content Plan.', 'is-ok' );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	function bindTopicBriefs() {
		var buttons = document.querySelectorAll( '.scc-brief-btn' );
		if ( ! buttons.length ) {
			return;
		}
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var wrap = btn.closest( '.scc-brief-wrap' );
				var status = wrap ? wrap.querySelector( '.scc-brief-status' ) : null;
				var out = wrap ? wrap.querySelector( '.scc-brief-out' ) : null;
				var topic;
				try {
					topic = JSON.parse( btn.getAttribute( 'data-topic' ) || '{}' );
				} catch ( e ) {
					topic = {};
				}
				btn.disabled = true;
				setStatus( status, 'Writing brief with your AI model… this can take a moment.' );
				request( '/brief/topic', { method: 'POST', data: topic } )
					.then( function ( res ) {
						btn.disabled = false;
						var brief = res.data && res.data.brief;
						if ( out && brief ) {
							out.innerHTML = renderTopicBrief( brief );
							out.hidden = false;
						}
						setStatus( status, 'Brief ready.', 'is-ok' );
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		} );
	}

	// ---- Seed content plan from architecture ---------------------------
	function bindSeedPlan() {
		var btn = document.getElementById( 'scc-seed-plan' );
		if ( ! btn ) {
			return;
		}
		var status    = document.getElementById( 'scc-seed-status' );
		var selectAll = document.getElementById( 'scc-seed-selectall' );
		var countEl   = document.getElementById( 'scc-seed-count' );

		// "Only show gaps" — collapse existing pages AND the now-empty groups /
		// pillars / "Supporting articles" labels, so no blank lines remain.
		var gapsOnly = document.getElementById( 'scc-arch-gaps-only' );
		if ( gapsOnly ) {
			var wrap = gapsOnly.closest( '.scc-wrap' ) || document.body;
			var applyGaps = function ( on ) {
				// 1) Hide recommendations that require no structural work.
				Array.prototype.forEach.call( wrap.querySelectorAll( '.scc-arch-node' ), function ( n ) {
					var decision = n.getAttribute( 'data-decision' ) || '';
					n.style.display = ( on && ( decision === 'keep' || decision === 'ignore' ) ) ? 'none' : '';
				} );
				var hasVisibleNode = function ( container ) {
					return Array.prototype.some.call( container.querySelectorAll( '.scc-arch-node' ), function ( n ) {
						return n.style.display !== 'none';
					} );
				};
				// 2) Child/article groups with no remaining gap rows (hides the label too).
				Array.prototype.forEach.call( wrap.querySelectorAll( '.scc-arch-children, .scc-arch-articles' ), function ( g ) {
					g.style.display = ( on && ! hasVisibleNode( g ) ) ? 'none' : '';
				} );
				// 3) Whole pillars where nothing is a gap.
				Array.prototype.forEach.call( wrap.querySelectorAll( '.scc-arch-pillar' ), function ( p ) {
					p.style.display = ( on && ! hasVisibleNode( p ) ) ? 'none' : '';
				} );
			};
			gapsOnly.addEventListener( 'change', function () {
				applyGaps( gapsOnly.checked );
			} );
		}

		function picks() {
			return Array.prototype.slice.call( document.querySelectorAll( '.scc-seed-pick' ) );
		}
		function selectedUrls() {
			return picks().filter( function ( c ) { return c.checked; } ).map( function ( c ) { return c.value; } );
		}
		function refreshCount() {
			var total = picks().length;
			var sel   = selectedUrls().length;
			if ( countEl ) {
				countEl.textContent = total ? ' — ' + sel + ' of ' + total + ' selected' : ' — no new pages to add';
			}
			btn.disabled = ( sel === 0 );
			if ( selectAll ) {
				selectAll.checked = ( total > 0 && sel === total );
				selectAll.indeterminate = ( sel > 0 && sel < total );
			}
		}

		picks().forEach( function ( c ) { c.addEventListener( 'change', refreshCount ); } );
		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				picks().forEach( function ( c ) { c.checked = selectAll.checked; } );
				refreshCount();
			} );
		}
		refreshCount();

		btn.addEventListener( 'click', function () {
			var urls = selectedUrls();
			if ( ! urls.length ) {
				setStatus( status, 'Select at least one page first.', 'is-error' );
				return;
			}
			btn.disabled = true;
			setStatus( status, '…' );
			request( '/content-plan/seed', { method: 'POST', data: { urls: urls } } )
				.then( function ( res ) {
					var created = ( res.data && res.data.created ) || 0;
					setStatus( status, created + ' page(s) added to your content plan.', 'is-ok' );
					refreshCount();
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- SEO Architecture Brain actions ---------------------------------
	function bindArchitectureBrain() {
		var tree = document.querySelector( '.scc-arch-tree' );
		var globalStatus = document.getElementById( 'scc-arch-global-status' );
		if ( ! tree && ! document.querySelector( '.scc-arch-merge-action' ) ) {
			return;
		}

		function nodeStatus( node ) {
			return node ? node.querySelector( '.scc-arch-action-status' ) : null;
		}

		function setArchitectureView( mode ) {
			mode = mode === 'current' ? 'current' : 'recommended';
			Array.prototype.forEach.call( document.querySelectorAll( '.scc-arch-view-btn' ), function ( btn ) {
				var active = btn.getAttribute( 'data-view' ) === mode;
				btn.classList.toggle( 'button-primary', active );
				btn.classList.toggle( 'is-active', active );
			} );
			Array.prototype.forEach.call( document.querySelectorAll( '.scc-arch-node' ), function ( node ) {
				var exists = node.getAttribute( 'data-existing' ) === '1';
				node.classList.toggle( 'scc-arch-hidden-by-view', mode === 'current' && ! exists );
			} );
			Array.prototype.forEach.call( document.querySelectorAll( '.scc-arch-branch, .scc-arch-children, .scc-arch-pillar' ), function ( group ) {
				if ( mode !== 'current' ) {
					group.classList.remove( 'scc-arch-empty-current' );
					return;
				}
				var visible = group.querySelector( '.scc-arch-node[data-existing="1"]:not(.scc-arch-hidden-by-view)' );
				var own = group.classList.contains( 'scc-arch-pillar' ) ? group.querySelector( ':scope > .scc-arch-node[data-existing="1"]' ) : null;
				group.classList.toggle( 'scc-arch-empty-current', ! visible && ! own );
			} );
			var title = document.getElementById( 'scc-arch-tree-title' );
			var help = document.getElementById( 'scc-arch-tree-help' );
			if ( title ) { title.textContent = mode === 'current' ? 'Current site architecture' : 'Recommended SEO site tree'; }
			if ( help ) {
				help.textContent = mode === 'current'
					? 'Current Site shows only pages that exist today. Switch back to Recommended SEO Architecture to see what TideOrbit recommends adding, strengthening, consolidating or reorganizing.'
					: 'Future-state view: new pages, pages to strengthen, supporting content and the service hierarchy TideOrbit recommends. Drag a child page, article, or section onto another service hub to change the planning hierarchy without changing live WordPress permalinks.';
			}
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.scc-arch-view-btn' ), function ( btn ) {
			btn.addEventListener( 'click', function () { setArchitectureView( btn.getAttribute( 'data-view' ) ); } );
		} );
		setArchitectureView( 'recommended' );

		function runNodeAction( btn, node, action, extra ) {
			var nodeId = node ? ( node.getAttribute( 'data-node-id' ) || '' ) : '';
			if ( ! nodeId ) { return; }
			btn.disabled = true;
			var status = nodeStatus( node );
			setStatus( status, 'Saving…' );
			var data = { action: action, node_id: nodeId };
			Object.keys( extra || {} ).forEach( function ( key ) { data[ key ] = extra[ key ]; } );

			request( '/architecture/action', { method: 'POST', data: data } )
				.then( function ( res ) {
					var message = ( res.data && res.data.message ) || 'Saved.';
					setStatus( status, message, 'is-ok' );
					if ( action === 'ignore' || action === 'mark_covered' || action === 'restore' || action === 'reparent' ) {
						window.location.reload();
					} else {
						btn.disabled = false;
					}
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || 'Could not save architecture action.', 'is-error' );
				} );
		}

		if ( tree ) {
			tree.addEventListener( 'click', function ( event ) {
				var draftBtn = event.target.closest ? event.target.closest( '.scc-arch-draft-generate' ) : null;
				if ( draftBtn ) {
					var draftNode = draftBtn.closest( '.scc-arch-node' );
					var draftNodeId = draftNode ? ( draftNode.getAttribute( 'data-node-id' ) || '' ) : '';
					var draftBox = draftNode ? draftNode.querySelector( '.scc-arch-draft' ) : null;
					if ( ! draftNodeId || ! draftBox ) { return; }
					draftBtn.disabled = true;
					draftBtn.textContent = 'Drafting…';
					request( '/architecture/expansion/generate', { method: 'POST', data: { node_id: draftNodeId } } )
						.then( function ( res ) {
							var draft = res.data && res.data.draft ? res.data.draft : null;
							if ( ! draft ) { throw new Error( 'No section draft was returned.' ); }
							draftBox.hidden = false;
							draftBox.innerHTML = '';
							var head = el( 'div', null, 'scc-arch-draft__head' );
							head.appendChild( el( 'strong', 'Review section draft' ) );
							if ( draft.provider || draft.model ) {
								head.appendChild( el( 'span', ' · ' + [ draft.provider, draft.model ].filter( Boolean ).join( ' / ' ), 'scc-note' ) );
							}
							draftBox.appendChild( head );
							draftBox.appendChild( el( 'h3', draft.heading || '' ) );
							var body = el( 'div', null, 'scc-arch-draft__body' );
							body.innerHTML = draft.html || '';
							draftBox.appendChild( body );
							var controls = el( 'div', null, 'scc-arch-draft__actions' );
							var apply = el( 'button', 'Apply to page', 'button button-primary button-small' );
							apply.type = 'button';
							var discard = el( 'button', 'Discard preview', 'button button-small' );
							discard.type = 'button';
							var draftStatus = el( 'span', '', 'scc-inline-status' );
							controls.appendChild( apply );
							controls.appendChild( discard );
							controls.appendChild( draftStatus );
							draftBox.appendChild( controls );
							discard.addEventListener( 'click', function () { draftBox.hidden = true; draftBox.innerHTML = ''; } );
							apply.addEventListener( 'click', function () {
								if ( ! window.confirm( 'Apply this reviewed section to the existing page? TideOrbit will create a recovery point first.' ) ) { return; }
								apply.disabled = true;
								setStatus( draftStatus, 'Applying with backup…' );
								request( '/architecture/expansion/apply', { method: 'POST', data: { post_id: draft.post_id, draft_id: draft.id } } )
									.then( function ( result ) {
										setStatus( draftStatus, ( result.data && result.data.message ) || 'Section added.', 'is-ok' );
										apply.textContent = 'Applied ✓';
										var rollback = el( 'button', 'Undo last expansion', 'button button-small' );
										rollback.type = 'button';
										controls.insertBefore( rollback, draftStatus );
										rollback.addEventListener( 'click', function () {
											if ( ! window.confirm( 'Restore the page from TideOrbit’s last expansion backup?' ) ) { return; }
											rollback.disabled = true;
											setStatus( draftStatus, 'Restoring…' );
											request( '/architecture/expansion/rollback', { method: 'POST', data: { post_id: draft.post_id } } )
												.then( function ( rr ) {
													setStatus( draftStatus, ( rr.data && rr.data.message ) || 'Previous version restored.', 'is-ok' );
													rollback.textContent = 'Restored ✓';
												} )
												.catch( function ( err ) {
													rollback.disabled = false;
													setStatus( draftStatus, ( err && err.message ) || 'Could not restore backup.', 'is-error' );
												} );
										} );
									} )
									.catch( function ( err ) {
										apply.disabled = false;
										setStatus( draftStatus, ( err && err.message ) || 'Could not apply section.', 'is-error' );
									} );
							} );
						} )
						.catch( function ( err ) {
							draftBtn.disabled = false;
							draftBtn.textContent = 'Draft missing section';
							setStatus( nodeStatus( draftNode ), ( err && err.message ) || 'Could not generate section draft.', 'is-error' );
						} );
					return;
				}


				var btn = event.target.closest ? event.target.closest( '.scc-arch-action' ) : null;
				if ( ! btn ) { return; }
				var node = btn.closest( '.scc-arch-node' );
				var action = btn.getAttribute( 'data-action' ) || '';
				runNodeAction( btn, node, action, {} );
			} );

			var dragging = null;
			Array.prototype.forEach.call( tree.querySelectorAll( '.scc-arch-node[draggable="true"]' ), function ( node ) {
				node.addEventListener( 'dragstart', function ( event ) {
					dragging = node;
					node.classList.add( 'is-dragging' );
					if ( event.dataTransfer ) {
						event.dataTransfer.effectAllowed = 'move';
						event.dataTransfer.setData( 'text/plain', node.getAttribute( 'data-node-id' ) || '' );
					}
				} );
				node.addEventListener( 'dragend', function () {
					node.classList.remove( 'is-dragging' );
					dragging = null;
					Array.prototype.forEach.call( tree.querySelectorAll( '.scc-arch-node[data-can-parent="1"]' ), function ( p ) { p.classList.remove( 'is-drop-target' ); } );
				} );
			} );

			// Every service/location node can be a hierarchy parent, including nested hubs.
			Array.prototype.forEach.call( tree.querySelectorAll( '.scc-arch-node[data-can-parent="1"]' ), function ( target ) {
				target.addEventListener( 'dragover', function ( event ) {
					if ( ! dragging || target === dragging ) { return; }
					var parentUrl = target.getAttribute( 'data-node-url' ) || '';
					var dragUrl = dragging.getAttribute( 'data-node-url' ) || '';
					var normParent = parentUrl.replace( /^\/+|\/+$/g, '' );
					var normDrag = dragUrl.replace( /^\/+|\/+$/g, '' );
					// No self-parenting and no moving a parent beneath its own descendant.
					if ( ! normParent || normParent === normDrag || ( normDrag && normParent.indexOf( normDrag + '/' ) === 0 ) ) { return; }
					event.preventDefault();
					target.classList.add( 'is-drop-target' );
				} );
				target.addEventListener( 'dragleave', function () { target.classList.remove( 'is-drop-target' ); } );
				target.addEventListener( 'drop', function ( event ) {
					event.preventDefault();
					target.classList.remove( 'is-drop-target' );
					if ( ! dragging || target === dragging ) { return; }
					var parentUrl = target.getAttribute( 'data-node-url' ) || '';
					var nodeId = dragging.getAttribute( 'data-node-id' ) || '';
					var dragUrl = dragging.getAttribute( 'data-node-url' ) || '';
					var normParent = parentUrl.replace( /^\/+|\/+$/g, '' );
					var normDrag = dragUrl.replace( /^\/+|\/+$/g, '' );
					if ( ! parentUrl || ! nodeId || normParent === normDrag || ( normDrag && normParent.indexOf( normDrag + '/' ) === 0 ) ) { return; }
					setStatus( globalStatus, 'Moving item to the new service hub…' );
					request( '/architecture/action', { method: 'POST', data: { action: 'reparent', node_id: nodeId, parent_url: parentUrl } } )
						.then( function () {
							setStatus( globalStatus, 'Parent updated. Reloading…', 'is-ok' );
							window.location.reload();
						} )
						.catch( function ( err ) { setStatus( globalStatus, ( err && err.message ) || 'Could not change parent.', 'is-error' ); } );
				} );
			} );
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.scc-arch-merge-action' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				var row = btn.closest( '.scc-arch-merge' );
				var mergeId = row ? ( row.getAttribute( 'data-merge-id' ) || '' ) : '';
				var status = row ? row.querySelector( '.scc-inline-status' ) : null;
				if ( ! mergeId ) { return; }
				btn.disabled = true;
				setStatus( status, 'Adding review…' );
				request( '/architecture/merge-action', { method: 'POST', data: { merge_id: mergeId } } )
					.then( function ( res ) {
						setStatus( status, ( res.data && res.data.message ) || 'Added to Action Queue.', 'is-ok' );
						btn.textContent = 'Queued ✓';
					} )
					.catch( function ( err ) {
						btn.disabled = false;
						setStatus( status, ( err && err.message ) || 'Could not queue consolidation review.', 'is-error' );
					} );
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
					var briefRow = row.nextElementSibling;
					if ( briefRow && briefRow.classList.contains( 'scc-brief-row' ) ) {
						briefRow.parentNode.removeChild( briefRow );
					}
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

	// Build a collapsible diagnostics block from a generation result's debug data.
	function debugBlock( d ) {
		if ( ! d || ! d.debug ) { return null; }
		var det = el( 'details' );
		det.style.marginTop = '8px';
		var sum = el( 'summary', 'Diagnostics (copy this if the draft is missing)' );
		sum.style.cursor = 'pointer';
		det.appendChild( sum );
		var pre = el( 'pre' );
		pre.style.whiteSpace = 'pre-wrap';
		pre.style.fontSize = '11px';
		pre.style.background = '#f6f7f7';
		pre.style.padding = '8px';
		pre.style.border = '1px solid #dcdcde';
		pre.style.userSelect = 'all';
		try { pre.textContent = JSON.stringify( d.debug, null, 2 ); }
		catch ( e ) { pre.textContent = String( d.debug ); }
		det.appendChild( pre );
		return det;
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
		// The generation actions live on both the dedicated Generate page and,
		// for convenience, directly on the Content Plan table.
		bindGenerateTable( document.getElementById( 'scc-generate-table' ), document.getElementById( 'scc-generate-msg' ) );
		bindGenerateTable( document.getElementById( 'scc-plan-table' ), document.getElementById( 'scc-plan-status-msg' ) );
		bindQuickGenerate();
		loadRecentGenerated();
		bindGenDebug();
	}

	// ---- Always-on generation debug trace -------------------------------
	function bindGenDebug() {
		var btn = document.getElementById( 'scc-debug-refresh' );
		if ( btn ) { btn.addEventListener( 'click', loadGenDebug ); }
		loadGenDebug();
	}

	function loadGenDebug() {
		var box = document.getElementById( 'scc-gen-debug' );
		if ( ! box ) { return; }
		request( '/debug/last', { method: 'GET' } )
			.then( function ( res ) {
				var trace = ( res.data && res.data.trace ) || [];
				box.innerHTML = '';
				if ( ! trace.length ) {
					box.appendChild( el( 'p', 'No generation traced yet. Generate a draft, then click Refresh debug.', 'scc-note' ) );
					return;
				}
				var lines = trace.map( function ( r ) {
					var data = '';
					try { data = r.data && Object.keys( r.data ).length ? ( '  ' + JSON.stringify( r.data ) ) : ''; }
					catch ( e ) { data = ''; }
					return ( r.t || '' ) + '  ' + ( r.step || '' ) + data;
				} ).join( '\n' );
				var pre = el( 'pre' );
				pre.style.whiteSpace = 'pre-wrap';
				pre.style.fontSize = '11px';
				pre.style.background = '#f6f7f7';
				pre.style.padding = '8px';
				pre.style.border = '1px solid #dcdcde';
				pre.style.maxHeight = '360px';
				pre.style.overflow = 'auto';
				pre.style.userSelect = 'all';
				pre.textContent = lines;
				box.appendChild( pre );
			} )
			.catch( function ( err ) {
				box.innerHTML = '';
				box.appendChild( el( 'p', ( err && err.message ) || 'Could not load debug trace.', 'scc-note' ) );
			} );
	}

	// ---- Recently generated (from the DB, any type/status) --------------
	function loadRecentGenerated() {
		var box = document.getElementById( 'scc-recent-generated' );
		if ( ! box ) { return; }
		request( '/generated/recent', { method: 'GET' } )
			.then( function ( res ) {
				var items = ( res.data && res.data.items ) || [];
				box.innerHTML = '';
				if ( ! items.length ) {
					box.appendChild( el( 'p', 'Nothing generated yet. When you create a draft it will appear here with a direct Edit link.', 'scc-note' ) );
					return;
				}
				var table = el( 'table', null, 'widefat striped scc-table' );
				var thead = el( 'thead' );
				thead.innerHTML = '<tr><th>Title</th><th>Type</th><th>Status</th><th>ID</th><th></th></tr>';
				table.appendChild( thead );
				var tbody = el( 'tbody' );
				items.forEach( function ( it ) {
					var tr = el( 'tr' );
					tr.appendChild( el( 'td', it.title ) );
					tr.appendChild( el( 'td', ( it.type_label || it.post_type ) + ( it.post_type === 'page' ? ' (Pages)' : ( it.post_type === 'post' ? ' (Posts)' : '' ) ) ) );
					tr.appendChild( el( 'td', it.status ) );
					tr.appendChild( el( 'td', String( it.post_id ) ) );
					var actions = el( 'td' );
					if ( it.edit_url ) {
						var a = el( 'a', 'Edit', 'button button-small button-primary' );
						a.href = it.edit_url;
						actions.appendChild( a );
					}
					if ( it.view_url && it.status === 'publish' ) {
						var v = el( 'a', 'View', 'button button-small' );
						v.href = it.view_url; v.target = '_blank'; v.rel = 'noopener';
						actions.appendChild( document.createTextNode( ' ' ) );
						actions.appendChild( v );
					}
					var lay = el( 'a', 'Build layout', 'button button-small' );
					lay.href = window.location.pathname + '?page=seo-command-center-layout&post=' + encodeURIComponent( it.post_id );
					actions.appendChild( document.createTextNode( ' ' ) );
					actions.appendChild( lay );
					tr.appendChild( actions );
					tbody.appendChild( tr );
				} );
				table.appendChild( tbody );
				box.appendChild( table );
			} )
			.catch( function ( err ) {
				box.innerHTML = '';
				box.appendChild( el( 'p', ( err && err.message ) || 'Could not load recent drafts.', 'scc-note' ) );
			} );
	}

	// ---- Simple path: topic -> draft ------------------------------------
	function bindQuickGenerate() {
		var card = document.querySelector( '.scc-quickgen' );
		if ( ! card ) {
			return;
		}
		var typeGroup = card.querySelector( '#scc-qg-type' );
		var modeNote  = card.querySelector( '#scc-qg-mode-note' );
		var tplField  = card.querySelector( '.scc-tpl-only' );
		var advToggle = card.querySelector( '#scc-qg-advanced-toggle' );
		var advBox    = card.querySelector( '#scc-qg-advanced' );
		var genBtn    = card.querySelector( '#scc-qg-generate' );
		var statusEl  = card.querySelector( '#scc-qg-status' );
		var resultEl  = card.querySelector( '#scc-qg-result' );

		function selectedType() {
			var checked = card.querySelector( 'input[name="scc_qg_type"]:checked' );
			return checked ? checked.value : 'article';
		}
		function isNative( t ) {
			return t === 'article' || t === 'blog' || t === 'blog_post' || t === 'post';
		}
		function refreshMode() {
			var t = selectedType();
			// Toggle the active pill styling.
			card.querySelectorAll( '.scc-seg' ).forEach( function ( seg ) {
				var inp = seg.querySelector( 'input' );
				seg.classList.toggle( 'is-active', !!( inp && inp.checked ) );
			} );
			if ( isNative( t ) ) {
				modeNote.textContent = 'Blog Post generates as a normal WordPress post (title, intro, H2 sections, FAQ, conclusion). No template needed.';
				if ( tplField ) { tplField.hidden = true; }
			} else {
				modeNote.textContent = 'A structured page. It renders through your template + renderer (Elementor / Gutenberg / native), using the mapped or default template unless you name one under Advanced.';
				if ( tplField ) { tplField.hidden = false; }
			}
		}
		if ( typeGroup ) {
			typeGroup.addEventListener( 'change', refreshMode );
		}
		refreshMode();

		if ( advToggle && advBox ) {
			advToggle.addEventListener( 'click', function () {
				var open = ! advBox.hidden;
				advBox.hidden = open;
				advToggle.setAttribute( 'aria-expanded', String( ! open ) );
				advToggle.textContent = open ? 'Advanced ▾' : 'Advanced ▴';
			} );
		}

		if ( ! genBtn ) {
			return;
		}
		genBtn.addEventListener( 'click', function () {
			var topic = ( card.querySelector( '#scc-qg-topic' ) || {} ).value || '';
			topic = topic.trim();
			if ( ! topic ) {
				setStatus( statusEl, 'Enter a topic first.', 'is-error' );
				return;
			}
			var val = function ( sel ) { var n = card.querySelector( sel ); return n ? n.value.trim() : ''; };
			var payload = {
				content_type: selectedType(),
				topic: topic,
				primary_keyword: val( '#scc-qg-keyword' ),
				location: val( '#scc-qg-location' ),
				category: val( '#scc-qg-category' ),
				tone: val( '#scc-qg-tone' ),
				secondary: val( '#scc-qg-secondary' ),
				word_count: val( '#scc-qg-words' ),
				template_family: val( '#scc-qg-template' )
			};
			genBtn.disabled = true;
			if ( resultEl ) { resultEl.hidden = true; resultEl.innerHTML = ''; }
			setStatus( statusEl, 'Generating draft… this runs on the server and can take a minute or two with a local model. You can leave this page open.' );

			request( '/generate/quick', { method: 'POST', data: payload } )
				.then( function ( res ) {
					var d = res.data || {};
					genBtn.disabled = false;
					setStatus( statusEl, 'Draft created.', 'is-ok' );
					if ( resultEl ) {
						resultEl.hidden = false;
						resultEl.innerHTML = '';
						var score = ( d.score && d.score.score ) || 0;
						var where = ( d.post_type === 'page' ) ? 'Pages' : 'Posts';
						var kind  = ( d.post_type === 'page' ) ? 'Page' : 'Post';
						var known = { draft: 1, publish: 1, pending: 1, future: 1, private: 1 };
						var st    = ( known[ d.status ] ) ? d.status : 'draft';
						var how   = ( d.mode === 'template' )
							? ( 'a ' + ( d.elementor ? 'Elementor' : 'templated' ) + ' ' + kind )
							: ( 'a native WordPress ' + kind );
						resultEl.appendChild( el( 'p', 'Saved as ' + st + ' — ' + how + '. Find it under ' + where + ' → Drafts.'
							+ ( score ? ( ' Optimization score ' + score + '/100 (internal guide, not a ranking guarantee).' ) : '' ) ) );
						if ( d.edit_url ) {
							var a = el( 'a', 'Edit ' + st + ' in WordPress' );
							a.href = d.edit_url; a.className = 'button button-primary';
							resultEl.appendChild( a );
						}
						if ( d.post_id && d.edit_url ) {
							var ee = el( 'a', '✏️ Edit with Elementor' );
							ee.href = d.edit_url.replace( /action=edit/, 'action=elementor' );
							ee.target = '_blank'; ee.rel = 'noopener'; ee.className = 'button';
							resultEl.appendChild( document.createTextNode( ' ' ) );
							resultEl.appendChild( ee );
							var bl = el( 'a', '🧩 Layout' );
							bl.href = window.location.pathname + '?page=seo-command-center-layout&post=' + encodeURIComponent( d.post_id );
							bl.className = 'button';
							resultEl.appendChild( document.createTextNode( ' ' ) );
							resultEl.appendChild( bl );
						}
						var dbg = debugBlock( d );
						if ( dbg ) { resultEl.appendChild( dbg ); }
					}
					loadRecentGenerated();
				} )
				.catch( function ( err ) {
					genBtn.disabled = false;
					setStatus( statusEl, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	function bindGenerateTable( table, msg ) {
		if ( ! table ) {
			return;
		}

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
				setStatus( msg, 'Generating draft… this runs on the server and can take a minute or two with a local model. You can leave this page open.' );
				var statusCell = row.querySelector( '.scc-gen-status' );
				if ( statusCell ) {
					statusCell.textContent = 'generating';
				}
				var settled = false;
				function showDone( d ) {
					if ( settled ) { return; }
					settled = true;
					if ( statusCell ) { statusCell.textContent = d.status || 'draft'; }
					if ( panel && briefRow ) {
						briefRow.hidden = false;
						panel.innerHTML = '';
						var score = ( d.score && d.score.score ) || 0;
						var where = ( d.post_type === 'page' ) ? 'Pages' : 'Posts';
						var kind  = ( d.post_type === 'page' ) ? 'Page' : 'Post';
						var known = { draft: 1, publish: 1, pending: 1, future: 1, private: 1 };
						var st    = ( known[ d.status ] ) ? d.status : 'draft';
						var how   = ( d.mode === 'template' )
							? ( 'a ' + ( d.elementor ? 'Elementor' : 'templated' ) + ' ' + kind )
							: ( 'a native WordPress ' + kind );
						panel.appendChild( el( 'p', 'Saved as ' + st + ' — ' + how + '. Find it under ' + where + ' → Drafts.'
							+ ( score ? ( ' Optimization score ' + score + '/100 (internal guide, not a ranking guarantee).' ) : '' ) ) );
						if ( d.edit_url ) {
							var a = el( 'a', 'Edit ' + st + ' in WordPress' );
							a.href = d.edit_url;
							a.className = 'button button-primary';
							panel.appendChild( a );
						}
						var dbg = debugBlock( d );
						if ( dbg ) { panel.appendChild( dbg ); }
					}
					setStatus( msg, 'Draft created.', 'is-ok' );
					e.target.disabled = false;
					loadRecentGenerated();
					loadGenDebug();
				}

				// Fire the generation. It runs server-side with ignore_user_abort,
				// so the draft finishes and saves even if this connection is cut.
				// A returned error (bad AI output, transport error, etc.) is captured
				// and surfaced instead of being hidden behind a generic timeout.
				var genError = null;
				request( '/generate', { method: 'POST', data: { entry_id: id } } )
					.then( function ( res ) { showDone( res.data || {} ); } )
					.catch( function ( err ) { genError = err && err.message ? err.message : null; loadGenDebug(); } );

				// Poll until the plan entry gets its post_id (generation complete).
				// A real failure returns a message quickly, so we don't wait long for
				// one; only a dropped connection (no message) gets the long window,
				// because the server may still be finishing the draft.
				var started = Date.now();
				( function poll() {
					var deadline = genError ? 90 * 1000 : 12 * 60 * 1000;
					if ( settled || Date.now() - started > deadline ) {
						if ( ! settled ) {
							settled = true;
							if ( statusCell ) { statusCell.textContent = genError ? 'error' : 'unknown'; }
							setStatus( msg, genError
								? ( 'Generation failed: ' + genError )
								: 'Still working (or the model stalled). Check Posts/Pages → Drafts; if nothing appears, try a shorter word count or a faster model.', 'is-error' );
							e.target.disabled = false;
						}
						return;
					}
					request( '/content-plan/gen-status?id=' + encodeURIComponent( id ), { method: 'GET' } )
						.then( function ( res ) {
							var d = res.data || {};
							if ( d.done ) { showDone( d ); return; }
							window.setTimeout( poll, 4000 );
						} )
						.catch( function () { window.setTimeout( poll, 5000 ); } );
				} )();
			}
		} );
	}

	// ---- Elementor template mapping ------------------------------------
	function bindTemplates() {
		var table = document.getElementById( 'scc-template-table' );
		if ( ! table ) {
			return;
		}
		var msg = document.getElementById( 'scc-template-msg' );
		table.addEventListener( 'change', function ( e ) {
			if ( ! e.target.classList.contains( 'scc-template-select' ) ) {
				return;
			}
			var row = e.target.closest( 'tr' );
			var contentType = row.getAttribute( 'data-content-type' );
			var opt = e.target.options[ e.target.selectedIndex ];
			var templateId = e.target.value;
			var statusCell = row.querySelector( '.scc-map-status' );
			setStatus( msg, '…' );

			if ( ! templateId ) {
				setStatus( msg, 'Select a template to map, or remove the mapping from the list.', 'is-ok' );
				return;
			}
			request( '/templates/map', {
				method: 'POST',
				data: { content_type: contentType, template_id: templateId, template_name: opt.getAttribute( 'data-name' ) },
			} )
				.then( function () {
					if ( statusCell ) {
						statusCell.innerHTML = '<span class="scc-badge scc-badge--ok">Mapped</span>';
					}
					setStatus( msg, i18n.saved || 'Saved.', 'is-ok' );
				} )
				.catch( function ( err ) {
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Internal links (advanced engine) ------------------------------
	function bindInternalLinks() {
		var msg = document.getElementById( 'scc-links-msg' );

		// AI-assisted linking toggle (persists to settings).
		var aiToggle = document.getElementById( 'scc-links-ai' );
		if ( aiToggle ) {
			var aiNote = document.getElementById( 'scc-links-ai-note' );
			aiToggle.addEventListener( 'change', function () {
				var on = aiToggle.checked;
				aiToggle.disabled = true;
				request( '/settings', { method: 'POST', data: { settings: { link_ai_enabled: on ? 1 : 0 } } } )
					.then( function () {
						aiToggle.disabled = false;
						if ( aiNote ) { aiNote.hidden = ! on; }
						setStatus( msg, on ? 'AI-assisted linking enabled.' : 'AI-assisted linking disabled.', 'is-ok' );
					} )
					.catch( function ( err ) {
						aiToggle.disabled = false;
						aiToggle.checked = ! on;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var scanBtn = document.getElementById( 'scc-links-scan' );
		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', function () {
				scanBtn.disabled = true;
				var aiOn = aiToggle && aiToggle.checked;
				setStatus( msg, aiOn ? 'Scanning with AI — reading each page… this can take a while.' : 'Indexing and scanning the site… this can take a moment.' );
				request( '/links/scan', { method: 'POST' } )
					.then( function ( res ) {
						var n = ( res.data && res.data.opportunities ) || 0;
						setStatus( msg, n + ' opportunity(ies) found. Reloading…', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						scanBtn.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var highBtn = document.getElementById( 'scc-links-apply-high' );
		if ( highBtn ) {
			highBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Insert all high-confidence links now? Each change can be reverted from the history below.' ) ) {
					return;
				}
				highBtn.disabled = true;
				setStatus( msg, 'Applying high-confidence links…' );
				request( '/links/apply-high', { method: 'POST' } )
					.then( function ( res ) {
						var d = res.data || {};
						setStatus( msg, ( d.applied || 0 ) + ' inserted, ' + ( d.skipped || 0 ) + ' skipped. Reloading…', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						highBtn.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var table = document.getElementById( 'scc-links-table' );
		if ( table ) {
			table.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'scc-apply-link' ) ) {
					return;
				}
				var row = e.target.closest( 'tr' );
				var id = row.getAttribute( 'data-id' );
				e.target.disabled = true;
				setStatus( msg, 'Inserting…' );
				request( '/links/apply', { method: 'POST', data: { id: id } } )
					.then( function () {
						row.parentNode.removeChild( row );
						setStatus( msg, 'Link inserted.', 'is-ok' );
					} )
					.catch( function ( err ) {
						e.target.disabled = false;
						e.target.textContent = 'Retry';
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		// Change-history revert.
		var hist = document.getElementById( 'scc-history-table' );
		if ( hist ) {
			var hmsg = document.getElementById( 'scc-history-msg' );
			hist.addEventListener( 'click', function ( e ) {
				if ( ! e.target.classList.contains( 'scc-revert' ) ) {
					return;
				}
				var row = e.target.closest( 'tr' );
				var id = row.getAttribute( 'data-id' );
				e.target.disabled = true;
				setStatus( hmsg, 'Reverting…' );
				request( '/history/revert', { method: 'POST', data: { id: id } } )
					.then( function () {
						setStatus( hmsg, 'Reverted.', 'is-ok' );
						e.target.outerHTML = '<span class="scc-badge">Reverted</span>';
					} )
					.catch( function ( err ) {
						e.target.disabled = false;
						setStatus( hmsg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}
	}

	// ---- GSC quick wins loader (Opportunities › Keywords) --------------
	function bindGscQuickWins() {
		var btn = document.getElementById( 'scc-gsc-load' );
		if ( ! btn ) {
			return;
		}
		var status = document.getElementById( 'scc-gsc-status' );
		var out = document.getElementById( 'scc-gsc-results' );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			setStatus( status, 'Loading Search Console data…' );
			request( '/gsc/quick-wins', { method: 'GET' } )
				.then( function ( res ) {
					btn.disabled = false;
					var d = res.data || {};
					out.innerHTML = '';
					if ( ! d.wins || ! d.wins.length ) {
						setStatus( status, 'No quick wins found in positions 4–20.', 'is-ok' );
						return;
					}
					setStatus( status, '', 'is-ok' );
					var table = el( 'table', null, 'widefat striped scc-table' );
					var thead = el( 'thead' );
					var hr = el( 'tr' );
					[ 'Query', 'Impressions', 'Clicks', 'CTR %', 'Position' ].forEach( function ( h ) {
						hr.appendChild( el( 'th', h ) );
					} );
					thead.appendChild( hr );
					table.appendChild( thead );
					var tb = el( 'tbody' );
					d.wins.forEach( function ( w ) {
						var tr = el( 'tr' );
						tr.appendChild( el( 'td', w.query ) );
						tr.appendChild( el( 'td', String( w.impressions ) ) );
						tr.appendChild( el( 'td', String( w.clicks ) ) );
						tr.appendChild( el( 'td', String( w.ctr ) ) );
						tr.appendChild( el( 'td', String( w.position ) ) );
						tb.appendChild( tr );
					} );
					table.appendChild( tb );
					out.appendChild( table );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Local citation scanner ------------------------------------------
	function bindCitationScanner() {
		var form = document.getElementById( 'scc-citation-form' );
		if ( ! form ) {
			return;
		}
		var status = document.getElementById( 'scc-citation-status' );
		var btn = document.getElementById( 'scc-citation-run' );

		function value( id ) {
			var node = document.getElementById( id );
			return node ? String( node.value || '' ).trim() : '';
		}

		function mark( value ) {
			if ( value === true ) {
				return '<span class="scc-badge scc-badge--ok">match</span>';
			}
			if ( value === false ) {
				return '<span class="scc-badge">mismatch</span>';
			}
			return '<span class="scc-badge">n/a</span>';
		}

		function render( data ) {
			var resultBox = document.getElementById( 'scc-citation-results' );
			if ( ! resultBox || ! data ) {
				return;
			}
			resultBox.style.display = 'block';

			var summary = data.summary || {};
			var score = document.getElementById( 'scc-citation-score' );
			var found = document.getElementById( 'scc-citation-found' );
			var inconsistent = document.getElementById( 'scc-citation-inconsistent' );
			var missing = document.getElementById( 'scc-citation-missing' );
			var unverified = document.getElementById( 'scc-citation-unverified' );
			var provider = document.getElementById( 'scc-citation-provider' );
			var methodology = document.getElementById( 'scc-citation-methodology' );

			if ( score ) { score.textContent = String( data.score == null ? '—' : data.score ) + ( data.score == null ? '' : '/100' ); }
			if ( found ) { found.textContent = String( summary.found || 0 ); }
			if ( inconsistent ) { inconsistent.textContent = String( summary.inconsistent || 0 ); }
			if ( missing ) { missing.textContent = String( summary.not_found || 0 ); }
			if ( unverified ) { unverified.textContent = String( summary.unverified || 0 ); }
			if ( provider ) { provider.textContent = data.provider ? 'Search provider: ' + data.provider : ''; }
			if ( methodology ) { methodology.textContent = data.methodology || ''; }

			var body = document.getElementById( 'scc-citation-table' );
			if ( ! body ) {
				return;
			}
			body.innerHTML = '';
			( data.results || [] ).forEach( function ( item ) {
				var checks = item.checks || {};
				var tr = document.createElement( 'tr' );
				var link = item.url
					? '<a href="' + esc( item.url ) + '" target="_blank" rel="noopener">Open</a>'
					: '—';
				var statusClass = item.status === 'found'
					? 'completed'
					: ( item.status === 'inconsistent' ? 'failed' : ( item.status === 'not_found' ? 'in_progress' : 'snoozed' ) );
				tr.innerHTML =
					'<td><strong>' + esc( item.name || '' ) + '</strong><br><span class="scc-note">' + esc( item.domain || '' ) + '</span></td>' +
					'<td><span class="scc-status scc-status--' + statusClass + '">' + esc( String( item.status || 'unverified' ).replace( '_', ' ' ) ) + '</span></td>' +
					'<td>' + esc( item.confidence == null ? '—' : item.confidence ) + ( item.confidence == null ? '' : '%' ) + '</td>' +
					'<td>' + mark( checks.name ) + ' ' + mark( checks.city ) + ' ' + mark( checks.phone ) + '</td>' +
					'<td>' + link + '</td>';
				body.appendChild( tr );
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var businessName = value( 'scc-citation-name' );
			var city = value( 'scc-citation-city' );
			if ( ! businessName || ! city ) {
				setStatus( status, 'Enter the business name and city first.', 'is-error' );
				return;
			}

			if ( btn ) { btn.disabled = true; }
			setStatus( status, 'Scanning citations…' );

			request( '/citation-scan', {
				method: 'POST',
				data: {
					business_name: businessName,
					address: value( 'scc-citation-address' ),
					city: city,
					state: value( 'scc-citation-state' ),
					phone: value( 'scc-citation-phone' ),
					website: value( 'scc-citation-website' )
				}
			} )
				.then( function ( response ) {
					var data = response && response.data ? response.data : response;
					render( data );
					setStatus( status, data && data.cached ? 'Loaded cached scan.' : 'Scan complete.', 'is-ok' );
				} )
				.catch( function ( err ) {
					setStatus( status, ( err && err.message ) || 'Citation scan failed.', 'is-error' );
				} )
				.then( function () {
					if ( btn ) { btn.disabled = false; }
				} );
		} );
	}

	function bindCompetitor() {
		var btn = document.getElementById( 'scc-competitor-go' );
		if ( ! btn ) {
			return;
		}
		var status = document.getElementById( 'scc-competitor-status' );
		var out = document.getElementById( 'scc-competitor-results' );
		btn.addEventListener( 'click', function () {
			var url = ( document.getElementById( 'scc-competitor-url' ) || {} ).value;
			if ( ! url ) {
				setStatus( status, 'Enter a URL first.', 'is-error' );
				return;
			}
			btn.disabled = true;
			setStatus( status, 'Fetching…' );
			request( '/competitors/analyze', { method: 'POST', data: { url: url } } )
				.then( function ( res ) {
					btn.disabled = false;
					setStatus( status, '', 'is-ok' );
					var a = ( res.data && res.data.analysis ) || {};
					out.innerHTML = '';
					out.appendChild( el( 'h3', a.title || url ) );
					if ( a.meta_description ) {
						out.appendChild( el( 'p', a.meta_description ) );
					}
					var stats = el( 'p' );
					stats.appendChild( el( 'span', 'Internal links: ' + ( a.internal_links || 0 ), 'scc-flag' ) );
					stats.appendChild( el( 'span', 'Images: ' + ( a.images || 0 ), 'scc-flag' ) );
					if ( a.schema_types && a.schema_types.length ) {
						stats.appendChild( el( 'span', 'Schema: ' + a.schema_types.join( ', ' ), 'scc-flag' ) );
					}
					out.appendChild( stats );
					if ( a.content_gaps && a.content_gaps.length ) {
						out.appendChild( el( 'div', 'Topics they cover that you may not:', 'scc-label' ) );
						var ul = el( 'ul', null, 'scc-options' );
						a.content_gaps.forEach( function ( g ) {
							ul.appendChild( el( 'li', g ) );
						} );
						out.appendChild( ul );
					}
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		} );
	}

	// ---- Competitor gap-map debug trace --------------------------------
	function loadCompDebug() {
		var box = document.getElementById( 'scc-comp-debug' );
		if ( ! box ) { return; }
		request( '/competitors/debug/last', { method: 'GET' } )
			.then( function ( res ) {
				var trace = ( res.data && res.data.trace ) || [];
				box.innerHTML = '';
				if ( ! trace.length ) {
					box.appendChild( el( 'p', 'No gap analysis traced yet. Run one, then click Refresh debug.', 'scc-note' ) );
					return;
				}
				var lines = trace.map( function ( r ) {
					var data = '';
					try { data = r.data && Object.keys( r.data ).length ? ( '  ' + JSON.stringify( r.data ) ) : ''; }
					catch ( e ) { data = ''; }
					return ( r.t || '' ) + '  ' + ( r.step || '' ) + data;
				} ).join( '\n' );
				var pre = el( 'pre' );
				pre.style.whiteSpace = 'pre-wrap';
				pre.style.fontSize = '11px';
				pre.style.background = '#f6f7f7';
				pre.style.padding = '8px';
				pre.style.border = '1px solid #dcdcde';
				pre.style.maxHeight = '360px';
				pre.style.overflow = 'auto';
				pre.style.userSelect = 'all';
				pre.textContent = lines;
				box.appendChild( pre );
			} )
			.catch( function ( err ) {
				box.innerHTML = '';
				box.appendChild( el( 'p', ( err && err.message ) || 'Could not load debug trace.', 'scc-note' ) );
			} );
	}

	// ---- Competitor content-gap map ------------------------------------
	function bindCompetitorGaps() {
		var btn = document.getElementById( 'scc-comp-go' );
		if ( ! btn ) {
			return;
		}
		var status = document.getElementById( 'scc-comp-status' );
		var out = document.getElementById( 'scc-comp-results' );

		// Always-on debug trace for the last gap-map run (mirrors gen debug).
		var dbgBtn = document.getElementById( 'scc-comp-debug-refresh' );
		if ( dbgBtn ) { dbgBtn.addEventListener( 'click', loadCompDebug ); }
		loadCompDebug();

		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s == null ? '' : String( s );
			return d.innerHTML;
		}

		function addToPlan( gap, button ) {
			button.disabled = true;
			button.textContent = 'Adding…';
			request( '/content-plan', {
				method: 'POST',
				data: {
					title: gap.title,
					url: gap.recommended_url || '',
					primary_keyword: gap.primary_keyword || '',
					intent: gap.intent || '',
					page_type: gap.page_type || 'article',
					priority: gap.priority || 'medium',
					status: 'recommended'
				}
			} ).then( function () {
				button.textContent = 'Added to plan ✓';
				button.classList.add( 'button-disabled' );
			} ).catch( function ( err ) {
				button.disabled = false;
				button.textContent = 'Add to plan';
				setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
			} );
		}

		function render( res ) {
			var data = res.data || {};
			out.innerHTML = '';
			out.hidden = false;

			// Competitors summary.
			var comps = data.competitors || [];
			var cCard = el( 'div', null, 'scc-card' );
			cCard.appendChild( el( 'h2', 'Competitors analyzed' ) );
			var cList = el( 'ul', null, 'scc-options' );
			comps.forEach( function ( c ) {
				var li = el( 'li' );
				if ( c.error ) {
					li.innerHTML = '<strong>' + esc( c.url ) + '</strong> — <span class="scc-flag scc-flag--prio-high">could not read: ' + esc( c.error ) + '</span>';
				} else {
					li.innerHTML = '<strong>' + esc( c.title || c.url ) + '</strong> <span class="scc-note">' + esc( c.url ) + '</span> — read ' + ( ( c.headings || [] ).length ) + ' headings, ~' + ( c.word_count || 0 ) + ' words of content';
				}
				cList.appendChild( li );
			} );
			cCard.appendChild( cList );
			if ( data.notes ) {
				cCard.appendChild( el( 'p', data.notes, 'scc-note' ) );
			}
			out.appendChild( cCard );

			// Gap map.
			var gaps = data.gaps || [];
			var gCard = el( 'div', null, 'scc-card' );
			var head = el( 'div', null, 'scc-card__head' );
			head.appendChild( el( 'h2', 'Content gaps to close (' + gaps.length + ')' ) );
			gCard.appendChild( head );

			if ( ! gaps.length ) {
				gCard.appendChild( el( 'p', 'No clear gaps found — your site already covers what these competitors do. Try more or different competitor pages.', 'scc-note' ) );
				out.appendChild( gCard );
				return;
			}

			var table = document.createElement( 'table' );
			table.className = 'widefat striped scc-table';
			table.innerHTML = '<thead><tr>' +
				'<th>Page to create</th><th>Primary keyword</th><th>Intent</th>' +
				'<th>Why</th><th>Priority</th><th></th></tr></thead>';
			var tbody = document.createElement( 'tbody' );
			gaps.forEach( function ( g ) {
				var tr = document.createElement( 'tr' );
				tr.innerHTML =
					'<td><strong>' + esc( g.title ) + '</strong>' + ( g.recommended_url ? '<br><code>' + esc( g.recommended_url ) + '</code>' : '' ) + '</td>' +
					'<td>' + esc( g.primary_keyword || '' ) + '</td>' +
					'<td><span class="scc-flag">' + esc( g.intent || '' ) + '</span></td>' +
					'<td class="scc-note">' + esc( g.why || '' ) + '</td>' +
					'<td><span class="scc-flag scc-flag--prio-' + esc( g.priority || 'medium' ) + '">' + esc( g.priority || 'medium' ) + '</span></td>' +
					'<td></td>';
				var addBtn = el( 'button', 'Add to plan', 'button button-small button-primary' );
				addBtn.addEventListener( 'click', function () { addToPlan( g, addBtn ); } );
				tr.lastChild.appendChild( addBtn );
				tbody.appendChild( tr );
			} );
			table.appendChild( tbody );
			gCard.appendChild( table );
			out.appendChild( gCard );
		}

		btn.addEventListener( 'click', function () {
			var raw = ( document.getElementById( 'scc-comp-urls' ) || {} ).value || '';
			var urls = raw.split( /[\r\n,]+/ ).map( function ( s ) { return s.trim(); } ).filter( Boolean );
			if ( ! urls.length ) {
				setStatus( status, 'Add at least one competitor URL.', 'is-error' );
				return;
			}
			btn.disabled = true;
			setStatus( status, 'Reading competitors and mapping gaps… this can take up to a minute.' );

			// A per-click id ties this run to the server-side record the recovery
			// poll reads — so a dropped connection can never lose the finished
			// result, and there is no clock comparison to get wrong.
			var runId = 'ui_' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 8 );
			var finished = false;

			function done( res ) {
				if ( finished ) { return; }
				finished = true;
				btn.disabled = false;
				setStatus( status, 'Done.', 'is-ok' );
				render( res );
				loadCompDebug();
			}
			function failed( msg ) {
				if ( finished ) { return; }
				finished = true;
				btn.disabled = false;
				setStatus( status, msg || i18n.error, 'is-error' );
				loadCompDebug();
			}

			// If the direct response is lost (a gateway/tunnel drops the long
			// request), the run still finishes on the server. Poll its status
			// record — matched by our run id — until it reports done or error.
			// The model can take a few minutes locally, so poll patiently.
			function recover( tries ) {
				if ( finished ) { return; }
				request( '/competitors/gap-map/last', { method: 'GET' } )
					.then( function ( last ) {
						if ( last && last.found && last.run_id === runId ) {
							if ( last.status === 'done' && last.result ) {
								done( last.result );
								return;
							}
							if ( last.status === 'error' ) {
								failed( last.message );
								return;
							}
							// status 'running' — keep waiting.
						}
						if ( tries <= 0 ) {
							failed( 'The analysis is taking longer than expected. It may still be running on the server — reload this page in a minute to see the results, or try fewer competitor URLs.' );
							return;
						}
						window.setTimeout( function () { recover( tries - 1 ); }, 5000 );
					} )
					.catch( function () {
						if ( tries <= 0 ) {
							failed( i18n.error );
							return;
						}
						window.setTimeout( function () { recover( tries - 1 ); }, 5000 );
					} );
			}

			request( '/competitors/gap-map', { method: 'POST', data: { urls: urls, run_id: runId } } )
				.then( function ( res ) { done( res ); } )
				.catch( function () {
					// The connection may have dropped while the server keeps working.
					// Switch to polling the run's status instead of erroring out.
					if ( finished ) { return; }
					setStatus( status, 'Still finishing on the server — recovering your results…' );
					recover( 60 ); // ~5 min at 5s between polls.
				} );
		} );
	}

	// ---- Intelligence layer: "What should I do next?" -----------------
	// ---- SEO Doctor ------------------------------------------------------
	function bindDoctor() {
		var root = document.getElementById( 'scc-doctor' );
		var body = document.getElementById( 'scc-doctor-body' );
		var dataEl = document.getElementById( 'scc-doctor-data' );
		if ( ! root || ! body || ! dataEl ) {
			return;
		}
		var status = document.getElementById( 'scc-doctor-status' );
		var runBtn = document.getElementById( 'scc-doctor-run' );
		var refreshBtn = document.getElementById( 'scc-doctor-refresh' );
		var boot = {};
		try { boot = JSON.parse( dataEl.textContent || '{}' ); } catch ( e ) { boot = {}; }
		var adminBase = boot.admin || '';
		var hashArea = ( /doctor-([a-z]+)/.exec( window.location.hash || '' ) || [] )[ 1 ];
		var state = { report: boot.report || null, area: hashArea && 'ignored' !== hashArea ? hashArea : 'all', view: 'ignored' === hashArea ? 'ignored' : 'problems' };
		if ( hashArea && root.scrollIntoView ) {
			root.scrollIntoView();
		}

		var SEV = { critical: 'Critical', high: 'High', medium: 'Medium', low: 'Low' };
		var SOURCE_LABEL = { technical: 'Site crawl', pagespeed: 'Google PageSpeed', architecture: 'Site structure', aeo: 'AI search', opportunities: 'Search Console' };
		var FIX_LABEL = { meta_description: 'Write description', schema: 'Add schema', social_tags: 'Turn on social tags', internal_links: 'Add internal links' };
		var SCREEN_LABEL = { 'meta-editor': 'Meta Editor', 'internal-links': 'Internal Links', 'architecture': 'Site Architecture', 'aeo': 'AEO / AI Citations', 'insights': 'Opportunities', 'topical-authority': 'Topical Authority', 'schema': 'Schema settings', 'keyword-strategy': 'Keywords', 'site-analysis': 'Page-by-page data', 'action-queue': 'Action Queue' };
		// The full tool behind each area of the diagnosis — always reachable from the Doctor.
		var AREA_TOOLS = {
			onpage: [ 'meta-editor' ],
			content: [ 'topical-authority', 'architecture', 'site-analysis' ],
			links: [ 'internal-links', 'architecture' ],
			schema: [ 'schema' ],
			ai: [ 'aeo' ],
			growth: [ 'insights', 'keyword-strategy' ]
		};
		function toolLinks( area ) {
			return ( AREA_TOOLS[ area ] || [] ).map( function ( screen ) {
				return '<a class="button button-small" href="' + esc( adminBase + 'seo-command-center-' + screen ) + '">Open ' + esc( SCREEN_LABEL[ screen ] || screen ) + ' →</a>';
			} ).join( ' ' );
		}

		function esc( s ) {
			return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
			} );
		}
		function safeUrl( u ) {
			u = String( u || '' );
			return /^https?:\/\//i.test( u ) ? u : '';
		}
		function gradeClass( g ) {
			return g ? 'scc-grade--' + String( g ).toLowerCase() : 'scc-grade--na';
		}

		function render() {
			var r = state.report;
			if ( ! r ) {
				return; // Server-rendered empty state stays.
			}
			if ( refreshBtn ) { refreshBtn.disabled = false; }
			if ( runBtn ) { runBtn.textContent = 'Run full check-up'; }
			var html = '';

			// Summary.
			var score = r.score;
			var counts = r.counts || {};
			html += '<div class="scc-doctor__summary">';
			html += '<div class="scc-doctor__score ' + gradeClass( r.grade ) + '">' +
				( score == null ? '<span class="scc-doctor__num">—</span>' : '<span class="scc-doctor__num">' + esc( score ) + '</span><span class="scc-doctor__den">/100</span>' ) +
				'<span class="scc-doctor__grade">' + esc( r.grade || '' ) + '</span></div>';
			html += '<div class="scc-doctor__verdict"><strong>' + esc( r.label || '' ) + '</strong>';
			html += '<div class="scc-doctor__counts">';
			[ 'critical', 'high', 'medium', 'low' ].forEach( function ( k ) {
				if ( counts[ k ] ) {
					html += '<span class="scc-sev scc-sev--' + k + '">' + esc( counts[ k ] ) + ' ' + SEV[ k ].toLowerCase() + '</span>';
				}
			} );
			if ( ! ( r.issues || [] ).length ) {
				html += '<span class="scc-sev scc-sev--ok">No problems found in the measured areas</span>';
			}
			if ( r.fixable ) {
				html += '<span class="scc-doctor__fixable">⚡ ' + esc( r.fixable ) + ' can be fixed in one click</span>';
			}
			html += '</div>';
			html += '<div class="scc-doctor__sources">' + sourcesHtml( r.sources || {} ) + '</div>';
			if ( r.generated_at ) {
				html += '<div class="scc-note">Last check-up: ' + esc( r.generated_at ) +
					( r.sources && r.sources.content && r.sources.content.available && adminBase ? ' · <a href="' + esc( adminBase + 'seo-command-center-site-analysis' ) + '">Page-by-page data</a>' : '' ) + '</div>';
			}
			html += '</div></div>';

			// Problems / Ignored tabs.
			var ignored = r.ignored || [];
			html += '<div class="scc-doctor__tabs" role="tablist">' +
				'<button type="button" role="tab" class="scc-doctor__tab' + ( 'problems' === state.view ? ' is-active' : '' ) + '" aria-selected="' + ( 'problems' === state.view ) + '" data-view="problems">Problems (' + esc( ( r.issues || [] ).length ) + ')</button>' +
				'<button type="button" role="tab" class="scc-doctor__tab' + ( 'ignored' === state.view ? ' is-active' : '' ) + '" aria-selected="' + ( 'ignored' === state.view ) + '" data-view="ignored">Ignored (' + esc( ignored.length ) + ')</button>' +
				'</div>';
			if ( 'ignored' === state.view ) {
				html += ignoredHtml( ignored );
				if ( r.disclaimer ) {
					html += '<p class="scc-note scc-doctor__disclaimer">' + esc( r.disclaimer ) + '</p>';
				}
				body.innerHTML = html;
				return;
			}

			// Area grades (click to filter).
			html += '<div class="scc-doctor__areas">';
			html += '<button type="button" class="scc-area' + ( 'all' === state.area ? ' is-active' : '' ) + '" data-area="all"><span class="scc-area__label">All areas</span><span class="scc-area__meta">' + esc( ( r.issues || [] ).length ) + ' issues</span></button>';
			( r.groups || [] ).forEach( function ( g ) {
				html += '<button type="button" class="scc-area' + ( state.area === g.id ? ' is-active' : '' ) + '" data-area="' + esc( g.id ) + '">' +
					'<span class="scc-area__grade ' + gradeClass( g.grade ) + '">' + esc( g.measured ? g.grade : '–' ) + '</span>' +
					'<span class="scc-area__label">' + esc( g.label ) + '</span>' +
					'<span class="scc-area__meta">' + ( g.measured ? esc( g.issues ) + ( 1 === g.issues ? ' issue' : ' issues' ) : 'Not measured' ) + '</span></button>';
			} );
			html += '</div>';

			// Issue list.
			var list = ( r.issues || [] ).filter( function ( i ) { return 'all' === state.area || i.group === state.area; } );
			var urgent = ( r.issues || [] ).filter( function ( i ) { return 'critical' === i.severity || 'high' === i.severity; } ).length;
			html += '<div class="scc-doctor__listhead"><strong>' + esc( list.length ) + ' ' + ( 1 === list.length ? 'problem' : 'problems' ) + '</strong>';
			if ( urgent ) {
				html += '<button type="button" class="button button-small" data-doctor-queue-high="1">Add all critical &amp; high to the Action Queue (' + esc( urgent ) + ')</button>';
			}
			html += '</div>';
			if ( 'all' !== state.area && ( AREA_TOOLS[ state.area ] || 'speed' === state.area ) ) {
				html += '<div class="scc-doctor__tools">' + ( 'speed' === state.area
					? '<span class="scc-note">Each slow page links to its full Google PageSpeed report.</span>'
					: '<span class="scc-note">Full tool:</span> ' + toolLinks( state.area ) ) + '</div>';
			}
			if ( ! list.length ) {
				var g = ( r.groups || [] ).filter( function ( x ) { return x.id === state.area; } )[ 0 ];
				html += '<div class="scc-empty"><p>' + ( g && ! g.measured ? esc( notMeasuredHint( g.id ) ) : 'Nothing to fix here. 🎉' ) + '</p></div>';
			}
			html += '<div class="scc-doctor__list">';
			list.forEach( function ( issue, idx ) { html += issueHtml( issue, idx ); } );
			html += '</div>';
			if ( r.disclaimer ) {
				html += '<p class="scc-note scc-doctor__disclaimer">' + esc( r.disclaimer ) + '</p>';
			}
			body.innerHTML = html;
		}

		function ignoredHtml( list ) {
			if ( ! list.length ) {
				return '<div class="scc-empty"><p>Nothing is ignored. Use <strong>Ignore</strong> on any problem — or on one page of it — to hide it. It stays hidden on every check-up until you undo it here.</p></div>';
			}
			var h = '<p class="scc-note">These stay hidden on every check-up until you undo them. Ignored site-crawl problems also stop counting toward your score.</p><div class="scc-doctor__list">';
			list.slice().reverse().forEach( function ( it ) {
				var url = safeUrl( it.url );
				var where = 'page' === it.scope
					? ( url ? '<a href="' + esc( url ) + '" target="_blank" rel="noopener">' + esc( it.page || url.replace( /^https?:\/\/[^/]+/, '' ) || '/' ) + '</a>' : esc( it.page || 'One page' ) )
					: 'All pages';
				h += '<div class="scc-issue scc-issue--ignored"><div class="scc-issue__row">' +
					( it.severity ? '<span class="scc-sev scc-sev--' + esc( it.severity ) + '">' + esc( SEV[ it.severity ] || it.severity ) + '</span>' : '' ) +
					'<span class="scc-issue__title scc-issue__title--static">' + esc( it.title ) + '</span>' +
					'<span class="scc-issue__pages">' + where + '</span>' +
					'<span class="scc-note">Ignored ' + esc( String( it.at || '' ).slice( 0, 10 ) ) + '</span>' +
					'<button type="button" class="button button-small" data-doctor-unignore="' + esc( it.key ) + '">Undo</button>' +
					'</div></div>';
			} );
			return h + '</div>';
		}

		function notMeasuredHint( area ) {
			if ( 'speed' === area ) { return 'Page speed has not been measured yet — run a full check-up to test your key pages with Google PageSpeed.'; }
			if ( 'growth' === area ) { return 'Connect Google Search Console (Connections) so the Doctor can spot pages losing traffic and keywords you are close to ranking for.'; }
			if ( 'ai' === area ) { return 'AI-search readiness has not been measured yet — run a check-up, or open AEO / AI Citations above to run it on its own.'; }
			return 'This area has not been measured yet — run a full check-up.';
		}

		function sourcesHtml( s ) {
			var out = '';
			var chip = function ( ok, text ) {
				out += '<span class="scc-src' + ( ok ? ' is-on' : '' ) + '">' + ( ok ? '✓ ' : '○ ' ) + esc( text ) + '</span>';
			};
			chip( s.content && s.content.available, s.content && s.content.available ? 'Content: ' + s.content.pages + ' pages read' : 'Content not read' );
			chip( s.technical && s.technical.available, s.technical && s.technical.available ? s.technical.pages + ' pages crawled' : 'Site not crawled' );
			chip( s.speed && s.speed.available, s.speed && s.speed.available ? 'Speed: ' + s.speed.urls + ' URLs measured' : 'Speed not measured' );
			chip( s.ai && s.ai.available, s.ai && s.ai.available ? 'AI search: ' + s.ai.pages + ' pages' : 'AI search not measured' );
			chip( s.architecture && s.architecture.available, s.architecture && s.architecture.available ? 'Site structure mapped' : 'Structure not mapped' );
			chip( s.growth && s.growth.available, s.growth && s.growth.gsc_connected ? 'Search Console connected' : 'Search Console not connected' );
			return out;
		}

		function issueHtml( issue, idx ) {
			var pages = issue.affected_count > 1 ? issue.affected_count + ' pages' : ( 'site' === issue.scope ? 'Site-wide' : ( issue.affected_count === 1 ? '1 page' : '' ) );
			var h = '<div class="scc-issue scc-issue--' + esc( issue.severity ) + '" data-issue="' + esc( issue.id ) + '">';
			h += '<div class="scc-issue__row">';
			h += '<span class="scc-sev scc-sev--' + esc( issue.severity ) + '">' + esc( SEV[ issue.severity ] || issue.severity ) + '</span>';
			h += '<button type="button" class="scc-issue__title" aria-expanded="false" data-toggle-issue="' + idx + '">' + esc( issue.title ) + '</button>';
			if ( pages ) { h += '<span class="scc-issue__pages">' + esc( pages ) + '</span>'; }
			if ( issue.fix_type ) { h += '<span class="scc-issue__quick" title="One-click fix available">⚡</span>'; }
			h += '</div>';
			h += '<div class="scc-issue__detail" hidden>';
			if ( issue.what ) { h += '<p>' + esc( issue.what ) + '</p>'; }
			if ( issue.why ) { h += '<p><strong>Why it matters:</strong> ' + esc( issue.why ) + '</p>'; }
			if ( issue.fix ) { h += '<p><strong>How to fix:</strong> ' + esc( issue.fix ) + '</p>'; }

			var examples = issue.examples || [];
			var siteFix = issue.fix_type && 'social_tags' === issue.fix_type;
			if ( examples.length ) {
				h += '<ul class="scc-issue__examples">';
				examples.forEach( function ( ex ) {
					var url = safeUrl( ex.url );
					h += '<li>';
					if ( url ) {
						h += '<a href="' + esc( url ) + '" target="_blank" rel="noopener">' + esc( url.replace( /^https?:\/\/[^/]+/, '' ) || '/' ) + '</a>';
					}
					if ( ex.evidence ) { h += ' <span class="scc-note">— ' + esc( ex.evidence ) + '</span>'; }
					if ( 'pagespeed' === issue.source && url ) {
						h += ' <a class="scc-note" href="https://pagespeed.web.dev/report?url=' + encodeURIComponent( url ) + '" target="_blank" rel="noopener">Open in PageSpeed Insights ↗</a>';
					}
					if ( issue.fix_type && ! siteFix && ex.post_id ) {
						h += ' <button type="button" class="button button-small scc-fix" data-fix="' + esc( issue.fix_type ) + '" data-post="' + esc( ex.post_id ) + '">' + esc( FIX_LABEL[ issue.fix_type ] || 'Fix' ) + '</button>';
					}
					if ( ex.post_id || url ) {
						h += ' <button type="button" class="button-link scc-ignore-page" data-doctor-ignore="' + esc( issue.id ) + '" data-post="' + esc( ex.post_id || 0 ) + '" data-url="' + esc( ex.post_id ? '' : url ) + '" title="Hide this problem for this page only">Ignore page</button>';
					}
					h += '<div class="scc-fix-preview" hidden></div>';
					h += '</li>';
				} );
				h += '</ul>';
				if ( issue.affected_count > examples.length ) {
					h += '<p class="scc-note">…and ' + esc( issue.affected_count - examples.length ) + ' more. The full list is in ' + esc( SCREEN_LABEL[ issue.screen ] || 'its screen' ) + '.</p>';
				}
			}
			h += '<div class="scc-issue__actions">';
			if ( siteFix ) {
				h += '<button type="button" class="button button-primary button-small scc-fix" data-fix="social_tags" data-post="0">' + esc( FIX_LABEL.social_tags ) + '</button>';
			}
			h += '<button type="button" class="button button-small" data-doctor-queue="' + esc( issue.id ) + '">Add to Action Queue</button>';
			h += '<button type="button" class="button button-small" data-doctor-ignore="' + esc( issue.id ) + '" data-post="0" data-url="" title="Hide this problem on every page until you undo it">Ignore</button>';
			if ( issue.screen && adminBase ) {
				h += '<a class="button button-small" href="' + esc( adminBase + 'seo-command-center-' + issue.screen ) + '">Open ' + esc( SCREEN_LABEL[ issue.screen ] || 'screen' ) + '</a>';
			}
			h += '<span class="scc-note">Source: ' + esc( SOURCE_LABEL[ issue.source ] || issue.source ) + '</span>';
			if ( siteFix ) { h += '<div class="scc-fix-preview" hidden></div>'; }
			h += '</div>';
			h += '</div></div>';
			return h;
		}

		// Preview → Apply. Nothing is written until Apply is clicked.
		function preview( btn ) {
			var issueEl = btn.closest( '[data-issue]' );
			var box = btn.parentNode.querySelector( '.scc-fix-preview' );
			var fix = btn.getAttribute( 'data-fix' );
			var postId = parseInt( btn.getAttribute( 'data-post' ), 10 ) || 0;
			btn.disabled = true;
			box.hidden = false;
			box.className = 'scc-fix-preview scc-loading';
			box.textContent = 'Working out the exact change…';
			request( '/doctor/fix', { method: 'POST', data: { fix: fix, post_id: postId, issue_id: issueEl.getAttribute( 'data-issue' ), apply: false } } )
				.then( function ( res ) {
					var p = ( res && res.data && res.data.result ) || {};
					var h = '<p><strong>This will:</strong> ' + esc( p.summary ) + '</p>';
					if ( p.before || p.after ) {
						h += '<div class="scc-fix-diff">';
						if ( p.before ) { h += '<div><span class="scc-note">Now:</span> ' + esc( p.before ) + '</div>'; }
						if ( p.after ) { h += '<div><span class="scc-note">After:</span> <strong>' + esc( p.after ) + '</strong></div>'; }
						h += '</div>';
					}
					( p.details || [] ).forEach( function ( d ) { h += '<div class="scc-note">• ' + esc( d ) + '</div>'; } );
					h += '<p><button type="button" class="button button-primary button-small scc-fix-apply">Apply fix</button> <button type="button" class="button button-small scc-fix-cancel">Cancel</button></p>';
					box.className = 'scc-fix-preview';
					box.innerHTML = h;
					box.querySelector( '.scc-fix-cancel' ).addEventListener( 'click', function () { box.hidden = true; btn.disabled = false; } );
					box.querySelector( '.scc-fix-apply' ).addEventListener( 'click', function ( e ) {
						e.target.disabled = true;
						apply( fix, postId, issueEl.getAttribute( 'data-issue' ), box, btn );
					} );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					box.className = 'scc-fix-preview is-error';
					box.textContent = ( err && err.message ) || 'This fix could not be prepared.';
				} );
		}

		function apply( fix, postId, issueId, box, btn ) {
			box.className = 'scc-fix-preview scc-loading';
			box.textContent = 'Applying…';
			request( '/doctor/fix', { method: 'POST', data: { fix: fix, post_id: postId, issue_id: issueId, apply: true } } )
				.then( function ( res ) {
					var d = ( res && res.data ) || {};
					setStatus( status, '✓ ' + ( ( d.result && d.result.summary ) || 'Fixed.' ) + ' The next check-up will confirm it.', 'is-ok' );
					if ( d.report ) {
						state.report = d.report;
						render();
					} else {
						box.className = 'scc-fix-preview is-ok';
						box.textContent = '✓ Done.';
					}
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					box.className = 'scc-fix-preview is-error';
					box.textContent = ( err && err.message ) || 'The fix could not be applied.';
				} );
		}

		function queue( data, btn ) {
			btn.disabled = true;
			request( '/doctor/queue', { method: 'POST', data: data } )
				.then( function ( res ) {
					var n = ( res && res.data && res.data.queued ) || 0;
					btn.textContent = '✓ Added (' + n + ')';
					setStatus( status, n + ' item(s) added to the Action Queue.', 'is-ok' );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || 'Could not add to the queue.', 'is-error' );
				} );
		}

		body.addEventListener( 'click', function ( e ) {
			var t = e.target;
			var area = t.closest( '[data-area]' );
			if ( area ) {
				state.area = area.getAttribute( 'data-area' );
				render();
				return;
			}
			var toggle = t.closest( '[data-toggle-issue]' );
			if ( toggle ) {
				var detail = toggle.closest( '.scc-issue' ).querySelector( '.scc-issue__detail' );
				detail.hidden = ! detail.hidden;
				toggle.setAttribute( 'aria-expanded', detail.hidden ? 'false' : 'true' );
				return;
			}
			if ( t.classList.contains( 'scc-fix' ) ) {
				preview( t );
				return;
			}
			if ( t.hasAttribute( 'data-doctor-queue' ) ) {
				queue( { issue_id: t.getAttribute( 'data-doctor-queue' ) }, t );
				return;
			}
			if ( t.hasAttribute( 'data-doctor-queue-high' ) ) {
				queue( { severity: 'high' }, t );
				return;
			}
			var tab = t.closest( '[data-view]' );
			if ( tab ) {
				state.view = tab.getAttribute( 'data-view' );
				render();
				return;
			}
			if ( t.hasAttribute( 'data-doctor-ignore' ) ) {
				var onePage = ( parseInt( t.getAttribute( 'data-post' ), 10 ) || 0 ) > 0 || !! t.getAttribute( 'data-url' );
				hideAndSave( '/doctor/ignore', { issue_id: t.getAttribute( 'data-doctor-ignore' ), post_id: parseInt( t.getAttribute( 'data-post' ), 10 ) || 0, url: t.getAttribute( 'data-url' ) || '' }, t,
					onePage ? 'Ignored for that page.' : 'Ignored on all pages.' );
				return;
			}
			if ( t.hasAttribute( 'data-doctor-unignore' ) ) {
				hideAndSave( '/doctor/unignore', { key: t.getAttribute( 'data-doctor-unignore' ) }, t, 'Restored — it is back in Problems.' );
			}
		} );

		function hideAndSave( path, data, btn, message ) {
			btn.disabled = true;
			request( path, { method: 'POST', data: data } )
				.then( function ( res ) {
					var rep = res && res.data && res.data.report;
					if ( rep ) {
						state.report = rep;
						render();
					}
					setStatus( status, '✓ ' + message + ( '/doctor/ignore' === path ? ' Undo any time in the Ignored tab.' : '' ), 'is-ok' );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( status, ( err && err.message ) || 'Could not save that.', 'is-error' );
				} );
		}

		function setBusy( busy ) {
			if ( runBtn ) { runBtn.disabled = busy; }
			if ( refreshBtn ) { refreshBtn.disabled = busy || ! state.report; }
		}

		function merge( refresh ) {
			setStatus( status, ( refresh ? 'Step 4 of 4: ' : '' ) + 'Putting the diagnosis together…' );
			return request( '/doctor/run', { method: 'POST', data: { refresh: !! refresh } } ).then( function ( res ) {
				state.report = ( res && res.data && res.data.report ) || state.report;
				render();
			} );
		}

		if ( runBtn ) {
			runBtn.addEventListener( 'click', function () {
				setBusy( true );
				var notes = [];
				var limitEl = document.getElementById( 'scc-doctor-limit' );
				var limit = limitEl ? ( parseInt( limitEl.value, 10 ) || 150 ) : 150;
				setStatus( status, 'Step 1 of 4: Reading your content…' );
				request( '/analyze', { method: 'POST', data: { limit: 300, deep: false } } )
					.catch( function ( err ) { notes.push( 'Content: ' + ( ( err && err.message ) || 'failed' ) ); } )
					.then( function () {
						setStatus( status, 'Step 2 of 4: Crawling up to ' + limit + ' pages and checking technical SEO… (this can take a few minutes)' );
						return request( '/technical-seo/audit', { method: 'POST', data: { limit: limit } } )
							.catch( function ( err ) { notes.push( 'Crawl: ' + ( ( err && err.message ) || 'failed' ) ); } );
					} )
					.then( function () {
						setStatus( status, 'Step 3 of 4: Measuring real page speed with Google PageSpeed… (about 30s per page)' );
						return request( '/doctor/pagespeed', { method: 'POST', data: { count: 3 } } )
							.then( function ( res ) {
								var ps = res && res.data && res.data.pagespeed;
								if ( ps && ! ps.measured ) {
									var first = ( ps.results || [] ).filter( function ( x ) { return x.error; } )[ 0 ];
									notes.push( 'Speed not measured' + ( first ? ': ' + first.error : '' ) );
								}
							} )
							.catch( function ( err ) { notes.push( 'Speed: ' + ( ( err && err.message ) || 'failed' ) ); } );
					} )
					.then( function () { return merge( true ); } )
					.then( function () {
						setBusy( false );
						setStatus( status, notes.length ? 'Check-up finished, with notes — ' + notes.join( ' · ' ) : '✓ Check-up complete.', notes.length ? '' : 'is-ok' );
					} )
					.catch( function ( err ) {
						setBusy( false );
						setStatus( status, ( err && err.message ) || 'The check-up could not finish.', 'is-error' );
					} );
			} );
		}
		if ( refreshBtn ) {
			refreshBtn.addEventListener( 'click', function () {
				setBusy( true );
				merge( false )
					.then( function () { setBusy( false ); setStatus( status, '✓ Refreshed from the latest results.', 'is-ok' ); } )
					.catch( function ( err ) { setBusy( false ); setStatus( status, ( err && err.message ) || 'Refresh failed.', 'is-error' ); } );
			} );
		}

		render();
	}

	function bindOpportunities() {
		// Present on both the Dashboard ("What should I do next?") and the
		// dedicated Action Queue screen.
		if ( ! document.getElementById( 'scc-opps-refresh' ) && ! document.getElementById( 'scc-opps-list' ) ) {
			return;
		}
		var msg = document.getElementById( 'scc-opps-msg' );

		var refresh = document.getElementById( 'scc-opps-refresh' );
		if ( refresh ) {
			refresh.addEventListener( 'click', function () {
				refresh.disabled = true;
				setStatus( msg, 'Recomputing opportunities…' );
				request( '/opportunities/refresh', { method: 'POST' } )
					.then( function () {
						setStatus( msg, 'Updated. Reloading…', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						refresh.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var list = document.getElementById( 'scc-opps-list' );
		if ( list ) {
			list.addEventListener( 'click', function ( e ) {
				var row = e.target.closest( '.scc-opp' );
				if ( ! row ) {
					return;
				}
				var oid = row.getAttribute( 'data-opp-id' );
				if ( e.target.classList.contains( 'scc-opp-approve' ) ) {
					e.target.disabled = true;
					setStatus( msg, 'Adding to the action queue…' );
					request( '/actions', { method: 'POST', data: { opportunity_id: oid, status: 'approved' } } )
						.then( function () {
							e.target.textContent = 'Added ✓';
							setStatus( msg, 'Added to the action queue.', 'is-ok' );
						} )
						.catch( function ( err ) {
							e.target.disabled = false;
							setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
						} );
				} else if ( e.target.classList.contains( 'scc-opp-dismiss' ) ) {
					row.style.opacity = '0.4';
					// Promote as dismissed so it stays out of the queue.
					request( '/actions', { method: 'POST', data: { opportunity_id: oid, status: 'dismissed' } } )
						.then( function () { row.parentNode.removeChild( row ); } )
						.catch( function () { row.style.opacity = '1'; } );
				}
			} );
		}
	}

	// ---- SEO Copilot (Dashboard) --------------------------------------
	function bindCopilot() {
		var card = document.getElementById( 'scc-copilot' );
		if ( ! card ) {
			return;
		}
		var input  = document.getElementById( 'scc-copilot-q' );
		var go      = document.getElementById( 'scc-copilot-go' );
		var msg     = document.getElementById( 'scc-copilot-msg' );
		var result  = document.getElementById( 'scc-copilot-result' );

		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = ( s == null ) ? '' : String( s );
			return d.innerHTML;
		}

		function oppCard( op ) {
			var wrap = el( 'div', '' );
			wrap.className = 'scc-opp';
			wrap.setAttribute( 'data-opp-id', op.id || '' );
			var factors = ( op.factors || [] ).map( function ( f ) {
				return '<span class="scc-opp__factor">+' + ( parseInt( f.points, 10 ) || 0 ) + ' ' + esc( f.label ) + '</span>';
			} ).join( '' );
			var cap = function ( s ) { s = String( s || ''); return s.charAt( 0 ).toUpperCase() + s.slice( 1 ); };
			var technical = op.source === 'technical_seo';
			var meta =
				'<div class="scc-opp__meta">' +
					'<span>Impact: <strong>' + esc( cap( op.expected_impact ) ) + '</strong></span>' +
					'<span>Effort: <strong>' + esc( op.effort || '' ) + '</strong></span>' +
					'<span>Confidence: <strong>' + ( parseInt( op.confidence, 10 ) || 0 ) + '%</strong></span>' +
				'</div>';
			var evidence = '';
			if ( op.evidence || op.url ) {
				evidence = '<div class="scc-opp__factors">';
				if ( op.url ) { evidence += '<div><strong>URL:</strong> ' + esc( op.url ) + '</div>'; }
				if ( op.evidence ) { evidence += '<div><strong>Evidence:</strong> ' + esc( op.evidence ) + '</div>'; }
				evidence += '</div>';
			}
			var details = ( op.recommended_action || factors || evidence )
				? '<details class="scc-opp__more"><summary>Details</summary>' +
					'<div class="scc-opp__do">' + esc( op.recommended_action || '' ) + '</div>' +
					evidence +
					'<div class="scc-opp__factors">' + factors + '</div></details>'
				: '';
			var actions = technical
				? '<div class="scc-opp__actions"><a class="button button-small" href="admin.php?page=seo-command-center#scc-doctor">Open SEO Doctor</a></div>'
				: '<div class="scc-opp__actions">' +
					'<button class="button button-primary button-small scc-opp-approve">Add to queue</button>' +
					'<button class="button button-small scc-opp-dismiss">Dismiss</button>' +
				  '</div>';
			wrap.innerHTML =
				'<div class="scc-opp__score"><span class="scc-opp__num">' + ( parseInt( op.score, 10 ) || 0 ) + '</span><span class="scc-opp__den">/100</span></div>' +
				'<div class="scc-opp__body">' +
					'<div class="scc-opp__title"><strong>' + esc( op.title ) + '</strong> ' +
						'<span class="scc-flag scc-flag--prio-' + esc( op.priority ) + '">' + esc( cap( op.priority ) ) + '</span></div>' +
					'<p class="scc-opp__why">' + esc( op.reason ) + '</p>' +
					meta + details +
				'</div>' +
				actions;
			return wrap;
		}

		function render( data ) {
			result.hidden = false;
			result.innerHTML = '';
			result.appendChild( el( 'p', data.what || '' ) ).className = 'scc-copilot__what';
			if ( data.why ) {
				var why = el( 'p', data.why );
				why.className = 'scc-copilot__why scc-note';
				result.appendChild( why );
			}
			( data.missing || [] ).forEach( function ( m ) {
				var n = el( 'div', m.message || '' );
				n.className = 'notice notice-warning inline';
				result.appendChild( n );
			} );
			var opps = data.opportunities || [];
			if ( opps.length ) {
				var list = el( 'div', '' );
				list.className = 'scc-opps';
				list.id = 'scc-copilot-opps';
				opps.forEach( function ( op ) { list.appendChild( oppCard( op ) ); } );
				result.appendChild( list );
			}
		}

		function ask( q ) {
			q = ( q || '' ).trim();
			if ( ! q ) { input && input.focus(); return; }
			go.disabled = true;
			setStatus( msg, 'Thinking…' );
			request( '/copilot', { method: 'POST', data: { query: q } } )
				.then( function ( res ) {
					go.disabled = false;
					setStatus( msg, '', '' );
					render( res.data || {} );
				} )
				.catch( function ( err ) {
					go.disabled = false;
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		}

		if ( go ) { go.addEventListener( 'click', function () { ask( input.value ); } ); }
		if ( input ) {
			input.addEventListener( 'keydown', function ( e ) { if ( 'Enter' === e.key ) { e.preventDefault(); ask( input.value ); } } );
		}
		card.querySelectorAll( '.scc-copilot-suggest' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () { if ( input ) { input.value = btn.textContent; } ask( btn.textContent ); } );
		} );

		// Reuse the Action Queue for Add-to-queue / Dismiss on Copilot cards.
		if ( result ) {
			result.addEventListener( 'click', function ( e ) {
				var row = e.target.closest( '.scc-opp' );
				if ( ! row ) { return; }
				var oid = row.getAttribute( 'data-opp-id' );
				if ( e.target.classList.contains( 'scc-opp-approve' ) ) {
					e.target.disabled = true;
					setStatus( msg, 'Adding to the action queue…' );
					request( '/actions', { method: 'POST', data: { opportunity_id: oid, status: 'approved' } } )
						.then( function () { e.target.textContent = 'Added ✓'; setStatus( msg, 'Added to the action queue.', 'is-ok' ); } )
						.catch( function ( err ) { e.target.disabled = false; setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' ); } );
				} else if ( e.target.classList.contains( 'scc-opp-dismiss' ) ) {
					row.style.opacity = '0.4';
					request( '/actions', { method: 'POST', data: { opportunity_id: oid, status: 'dismissed' } } )
						.then( function () { row.parentNode.removeChild( row ); } )
						.catch( function () { row.style.opacity = '1'; } );
				}
			} );
		}
	}

	// ---- Action Queue screen (lifecycle + Fix Everything Safe) --------
	function bindActionQueue() {
		var table = document.getElementById( 'scc-queue-table' );
		var fixBtn = document.getElementById( 'scc-fix-safe' );
		var msg = document.getElementById( 'scc-queue-msg' );

		if ( fixBtn ) {
			fixBtn.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Run all safe, deterministic actions now? These are reversible (internal-link insertions) — no content is edited, published, or deleted.' ) ) {
					return;
				}
				fixBtn.disabled = true;
				setStatus( msg, 'Running safe actions…' );
				request( '/actions/fix-safe', { method: 'POST' } )
					.then( function ( res ) {
						var d = res.data || {};
						setStatus( msg, ( d.executed || 0 ) + ' run, ' + ( d.failed || 0 ) + ' failed. Reloading…', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						fixBtn.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		if ( ! table ) {
			return;
		}
		table.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( 'tr' );
			if ( ! row ) {
				return;
			}
			var id = row.getAttribute( 'data-id' );
			var statusCell = row.querySelector( '.scc-q-status' );

			function patch( body, done ) {
				e.target.disabled = true;
				request( '/actions/' + id, { method: 'PUT', data: body } )
					.then( function ( res ) {
						var a = ( res.data && res.data.action ) || {};
						if ( statusCell && a.status ) { statusCell.textContent = a.status; }
						setStatus( msg, done || 'Updated.', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						e.target.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			}

			if ( e.target.classList.contains( 'scc-q-approve' ) ) {
				patch( { status: 'approved' }, 'Approved.' );
			} else if ( e.target.classList.contains( 'scc-q-dismiss' ) ) {
				patch( { status: 'dismissed' }, 'Dismissed.' );
			} else if ( e.target.classList.contains( 'scc-q-snooze' ) ) {
				patch( { status: 'snoozed', days: 14 }, 'Snoozed for 14 days.' );
			} else if ( e.target.classList.contains( 'scc-q-execute' ) ) {
				if ( ! window.confirm( 'Run this safe action now? It is reversible from the change history.' ) ) {
					return;
				}
				e.target.disabled = true;
				setStatus( msg, 'Running…' );
				request( '/actions/' + id + '/execute', { method: 'POST' } )
					.then( function ( res ) {
						var r = ( res.data && res.data.result ) || {};
						setStatus( msg, r.message || 'Done.', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						e.target.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			}
		} );
	}

	// ---- Content Ideas (ask → suggestions → generate) -----------------
	function bindContentIdeas() {
		var go = document.getElementById( 'scc-ideas-go' );
		if ( ! go ) {
			return;
		}
		var qEl = document.getElementById( 'scc-ideas-q' );
		var countEl = document.getElementById( 'scc-ideas-count' );
		var msg = document.getElementById( 'scc-ideas-msg' );
		var out = document.getElementById( 'scc-ideas-results' );
		var lastQuestion = '';
		var lastIdeas = [];

		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s == null ? '' : String( s );
			return d.innerHTML;
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.scc-idea-example' ), function ( b ) {
			b.addEventListener( 'click', function () { qEl.value = b.textContent; qEl.focus(); } );
		} );

		function planData( idea ) {
			return {
				title: idea.title,
				url: idea.recommended_url || '',
				primary_keyword: idea.primary_keyword || '',
				secondary: idea.secondary_keywords || [],
				intent: idea.intent || '',
				page_type: idea.page_type || 'article',
				priority: idea.priority || 'medium',
				status: 'recommended'
			};
		}

		// Create the plan entry, then generate a draft, polling until it lands.
		function generateDraft( idea, statusEl, btn ) {
			btn.disabled = true;
			setStatus( statusEl, 'Adding to plan…' );
			var entryId = 0;
			request( '/content-plan', { method: 'POST', data: planData( idea ) } )
				.then( function ( res ) {
					entryId = ( res.data && res.data.id ) || 0;
					if ( ! entryId ) { throw new Error( 'Could not create the plan entry.' ); }
					setStatus( statusEl, 'Generating draft… this can take up to a minute.' );
					// Fire generation; if the gateway drops it, polling still catches the draft.
					request( '/generate', { method: 'POST', data: { entry_id: entryId } } ).catch( function () {} );
					return poll( entryId, 0 );
				} )
				.catch( function ( err ) {
					btn.disabled = false;
					setStatus( statusEl, ( err && err.message ) || i18n.error, 'is-error' );
				} );

			function poll( id, tries ) {
				return request( '/content-plan/gen-status?id=' + id, { method: 'GET' } ).then( function ( res ) {
					var d = res.data || {};
					if ( d.done && d.edit_url ) {
						btn.textContent = 'Draft ready';
						statusEl.innerHTML = 'Draft created — <a href="' + esc( d.edit_url ) + '">edit it</a>.';
						statusEl.className = 'scc-inline-status is-ok';
						return;
					}
					if ( tries >= 24 ) {
						btn.disabled = false;
						setStatus( statusEl, 'Still generating — check the Publishing Queue shortly.', 'is-ok' );
						return;
					}
					return new Promise( function ( r ) { setTimeout( r, 4000 ); } ).then( function () { return poll( id, tries + 1 ); } );
				} );
			}
		}

		function render( res ) {
			var d = res.data || {};
			var ideas = d.ideas || [];
			lastIdeas = ideas;
			out.innerHTML = '';
			out.hidden = false;
			var head = el( 'div', null, 'scc-card' );
			var grounded = ( d.grounded && d.grounded.gsc ) ? 'grounded in your real pages + Search Console demand' : 'grounded in your real pages';
			if ( ideas.length ) {
				head.appendChild( el( 'p', ideas.length + ' new page ideas (' + grounded + '). Add any to your Content Plan, or generate a draft now.', 'scc-note' ) );
			} else {
				head.appendChild( el( 'p', 'No new ideas this time — everything suggested is already in your plan or live on your site.', 'scc-note' ) );
			}
			if ( d.notes ) { head.appendChild( el( 'p', d.notes, 'scc-note' ) ); }

			// Refine bar — adjust or extend this set with a follow-up instruction.
			var refine = el( 'div', null, 'scc-refine' );
			var chips = [ 'Add 5 more', 'Make them more local', 'Focus on commercial intent', 'Fewer, stronger ideas', 'More informational / blog' ];
			var chipRow = el( 'div', null, 'scc-refine__chips' );
			chips.forEach( function ( c ) {
				var b = el( 'button', c, 'button button-small scc-refine-chip' );
				b.addEventListener( 'click', function () { rInput.value = c; runRefine(); } );
				chipRow.appendChild( b );
			} );
			var rWrap = el( 'div', null, 'scc-refine__row' );
			var rInput = document.createElement( 'input' );
			rInput.type = 'text'; rInput.className = 'regular-text'; rInput.placeholder = 'Refine these ideas… (e.g. more local, add 5, commercial only)';
			var rBtn = el( 'button', 'Refine', 'button button-small button-primary' );
			var rStatus = el( 'span', '', 'scc-inline-status' );
			function runRefine() {
				var instr = ( rInput.value || '' ).trim();
				if ( ! instr ) { setStatus( rStatus, 'Type a refinement first.', 'is-error' ); return; }
				rBtn.disabled = true;
				setStatus( rStatus, 'Refining…' );
				run( { question: lastQuestion, count: ( countEl && countEl.value ) || 8, refine: instr, previous: lastIdeas }, rStatus, function () { rBtn.disabled = false; } );
			}
			rBtn.addEventListener( 'click', runRefine );
			rInput.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { runRefine(); } } );
			rWrap.appendChild( rInput ); rWrap.appendChild( rBtn ); rWrap.appendChild( rStatus );
			refine.appendChild( el( 'div', 'Refine this set:', 'scc-label' ) );
			refine.appendChild( chipRow );
			refine.appendChild( rWrap );
			head.appendChild( refine );

			out.appendChild( head );

			ideas.forEach( function ( idea ) {
				var card = el( 'div', null, 'scc-card scc-idea' );
				card.innerHTML =
					'<div class="scc-idea__head"><strong>' + esc( idea.title ) + '</strong>' +
					' <span class="scc-flag">' + esc( idea.page_type ) + '</span>' +
					' <span class="scc-flag scc-flag--prio-' + esc( idea.priority ) + '">' + esc( idea.priority ) + '</span>' +
					' <span class="scc-flag">' + esc( idea.intent ) + '</span></div>' +
					'<div class="scc-idea__meta"><span class="scc-label">Meta title</span> ' + esc( idea.meta_title || '' ) + ' <span class="scc-note">(' + ( idea.meta_title || '' ).length + ')</span></div>' +
					'<div class="scc-idea__meta"><span class="scc-label">Meta description</span> ' + esc( idea.meta_description || '' ) + '</div>' +
					'<div class="scc-idea__kw"><span class="scc-label">Keyword</span> <code>' + esc( idea.primary_keyword || '' ) + '</code>' +
					( ( idea.secondary_keywords || [] ).length ? ' <span class="scc-note">+ ' + esc( ( idea.secondary_keywords || [] ).join( ', ' ) ) + '</span>' : '' ) + '</div>' +
					( idea.recommended_url ? '<div class="scc-note"><code>' + esc( idea.recommended_url ) + '</code></div>' : '' ) +
					( idea.why ? '<div class="scc-idea__why">' + esc( idea.why ) + '</div>' : '' );

				var actions = el( 'div', null, 'scc-idea__actions' );
				var add = el( 'button', 'Add to Content Plan', 'button button-small' );
				var gen = el( 'button', '✨ Generate draft', 'button button-small button-primary' );
				var st = el( 'span', '', 'scc-inline-status' );
				add.addEventListener( 'click', function () {
					add.disabled = true;
					setStatus( st, 'Adding…' );
					request( '/content-plan', { method: 'POST', data: planData( idea ) } )
						.then( function () {
							add.textContent = 'Added ✓';
							setStatus( st, 'Added to Content Plan.', 'is-ok' );
							gen.disabled = true;
							card.classList.add( 'is-added' );
							// Drop it from the working set so a later Refine never
							// re-sends it as a "previous" idea (server also dedupes).
							lastIdeas = lastIdeas.filter( function ( x ) { return x !== idea; } );
						} )
						.catch( function ( err ) { add.disabled = false; setStatus( st, ( err && err.message ) || i18n.error, 'is-error' ); } );
				} );
				gen.addEventListener( 'click', function () { generateDraft( idea, st, gen ); } );
				actions.appendChild( add );
				actions.appendChild( gen );
				actions.appendChild( st );
				card.appendChild( actions );
				out.appendChild( card );
			} );
		}

		// Shared runner for the initial ask and refinements.
		function run( data, statusEl, done ) {
			request( '/ideas', { method: 'POST', data: data } )
				.then( function ( res ) {
					if ( done ) { done(); }
					setStatus( statusEl, 'Done.', 'is-ok' );
					render( res );
					out.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				} )
				.catch( function ( err ) {
					if ( done ) { done(); }
					setStatus( statusEl, ( err && err.message ) || i18n.error, 'is-error' );
				} );
		}

		go.addEventListener( 'click', function () {
			var q = ( qEl.value || '' ).trim();
			if ( ! q ) { setStatus( msg, 'Type what you want ideas for.', 'is-error' ); return; }
			lastQuestion = q;
			go.disabled = true;
			setStatus( msg, 'Thinking about the best SEO pages for you…' );
			run( { question: q, count: ( countEl && countEl.value ) || 8 }, msg, function () { go.disabled = false; } );
		} );
	}

	// ---- Meta Editor (bulk title/description editing) -----------------
	function bindMetaEditor() {
		var list = document.getElementById( 'scc-meta-list' );
		if ( ! list ) {
			return;
		}
		var msg = document.getElementById( 'scc-meta-msg' );
		var search = document.getElementById( 'scc-meta-search' );
		var prev = document.getElementById( 'scc-meta-prev' );
		var next = document.getElementById( 'scc-meta-next' );
		var info = document.getElementById( 'scc-meta-pageinfo' );
		var paged = 1;
		var pages = 1;
		var timer = null;

		function esc( s ) {
			var d = document.createElement( 'div' );
			d.textContent = s == null ? '' : String( s );
			return d.innerHTML;
		}
		function counterClass( len, min, max ) {
			if ( len === 0 ) { return 'is-empty'; }
			if ( len < min ) { return 'is-short'; }
			if ( len > max ) { return 'is-long'; }
			return 'is-good';
		}
		function bindCounter( inp, out, min, max ) {
			function upd() {
				var len = ( inp.value || '' ).length;
				out.textContent = len;
				out.className = 'scc-meta-count ' + counterClass( len, min, max );
			}
			inp.addEventListener( 'input', upd );
			upd();
		}

		function render( res ) {
			var d = res.data || {};
			paged = d.paged || 1;
			pages = d.pages || 1;
			var items = d.items || [];
			list.innerHTML = '';
			if ( ! items.length ) {
				list.appendChild( el( 'p', 'No pages found.', 'scc-note' ) );
			}
			items.forEach( function ( it ) {
				var row = el( 'div', null, 'scc-meta-row' );
				row.setAttribute( 'data-post-id', it.post_id );
				var head = el( 'div', null, 'scc-meta-row__head' );
				head.innerHTML = '<strong>' + esc( it.title ) + '</strong> <span class="scc-flag">' + esc( it.post_type ) + '</span> <span class="scc-flag">' + esc( it.status ) + '</span>' + ( it.is_template ? ' <span class="scc-badge scc-badge--warn">template</span>' : '' ) + ( it.url ? ' <a class="scc-note" href="' + esc( it.url ) + '" target="_blank" rel="noopener">view</a>' : '' ) + ( it.edit_url ? ' <a class="scc-note" href="' + esc( it.edit_url ) + '">edit page</a>' : '' );
				row.appendChild( head );

				var tWrap = el( 'label', null, 'scc-meta-field' );
				tWrap.appendChild( el( 'span', 'Meta title', 'scc-label' ) );
				var tInput = document.createElement( 'input' );
				tInput.type = 'text'; tInput.className = 'large-text scc-meta-title'; tInput.value = it.meta_title || '';
				tWrap.appendChild( tInput );
				var tCount = el( 'span', '', 'scc-meta-count' );
				tWrap.appendChild( tCount );
				row.appendChild( tWrap );

				var dWrap = el( 'label', null, 'scc-meta-field' );
				dWrap.appendChild( el( 'span', 'Meta description', 'scc-label' ) );
				var dInput = document.createElement( 'textarea' );
				dInput.className = 'large-text scc-meta-desc'; dInput.rows = 2; dInput.value = it.meta_description || '';
				dWrap.appendChild( dInput );
				var dCount = el( 'span', '', 'scc-meta-count' );
				dWrap.appendChild( dCount );
				row.appendChild( dWrap );

				var suggestions = el( 'div', null, 'scc-meta-suggestions' );
				suggestions.hidden = true;
				row.appendChild( suggestions );

				var actions = el( 'div', null, 'scc-meta-row__actions' );
				var suggest = el( 'button', '✨ Suggest with AI', 'button button-small scc-meta-suggest' );
				var save = el( 'button', 'Save', 'button button-primary button-small' );
				var st = el( 'span', '', 'scc-inline-status' );

				suggest.addEventListener( 'click', function () {
					suggest.disabled = true;
					setStatus( st, 'Asking AI for the strongest SEO + click options…' );
					request( '/meta/variants', { method: 'POST', data: { post_id: it.post_id } } )
						.then( function ( res ) {
							suggest.disabled = false;
							setStatus( st, '', 'is-ok' );
							var d = res.data || {};
							var variants = d.variants || [];
							suggestions.innerHTML = '';
							suggestions.hidden = false;
							if ( ! variants.length ) {
								suggestions.appendChild( el( 'p', 'No suggestions returned — try again.', 'scc-note' ) );
								return;
							}
							suggestions.appendChild( el( 'div', 'AI suggestions (click one to use it):', 'scc-label' ) );
							variants.forEach( function ( v ) {
								var opt = el( 'div', null, 'scc-meta-suggestion' );
								opt.innerHTML =
									'<div class="scc-meta-suggestion__head"><span class="scc-flag">' + esc( v.type ) + '</span> ' +
									'<span class="scc-note">' + esc( v.title || '' ).length + ' / ' + esc( v.description || '' ).length + ' chars</span></div>' +
									'<div class="scc-meta-suggestion__t"><strong>' + esc( v.title || '' ) + '</strong></div>' +
									'<div class="scc-meta-suggestion__d">' + esc( v.description || '' ) + '</div>' +
									( v.reason ? '<div class="scc-note">' + esc( v.reason ) + '</div>' : '' );
								var use = el( 'button', 'Use this', 'button button-small' );
								use.addEventListener( 'click', function () {
									tInput.value = v.title || '';
									dInput.value = v.description || '';
									tInput.dispatchEvent( new Event( 'input' ) );
									dInput.dispatchEvent( new Event( 'input' ) );
									suggestions.hidden = true;
									setStatus( st, 'Filled — review, then Save.', 'is-ok' );
								} );
								opt.appendChild( use );
								suggestions.appendChild( opt );
							} );
						} )
						.catch( function ( err ) {
							suggest.disabled = false;
							setStatus( st, ( err && err.message ) || i18n.error, 'is-error' );
						} );
				} );

				// Save this row (returns a promise; reused by "Save all").
				row._sccSave = function () {
					setStatus( st, 'Saving…' );
					return request( '/metadata/save', { method: 'POST', data: { post_id: it.post_id, meta_title: tInput.value, meta_description: dInput.value } } )
						.then( function () { setStatus( st, 'Saved ✓', 'is-ok' ); } )
						.catch( function ( err ) { setStatus( st, ( err && err.message ) || i18n.error, 'is-error' ); throw err; } );
				};
				save.addEventListener( 'click', function () {
					save.disabled = true;
					row._sccSave().then( function () { save.disabled = false; } ).catch( function () { save.disabled = false; } );
				} );
				actions.appendChild( suggest );
				actions.appendChild( save );
				actions.appendChild( st );
				row.appendChild( actions );

				// Apply a variant to this row's fields (used by "Suggest for all").
				row._sccApply = function ( v ) {
					tInput.value = v.title || '';
					dInput.value = v.description || '';
					tInput.dispatchEvent( new Event( 'input' ) );
					dInput.dispatchEvent( new Event( 'input' ) );
				};
				row._sccStatus = st;

				list.appendChild( row );
				bindCounter( tInput, tCount, 30, 60 );
				bindCounter( dInput, dCount, 70, 160 );
			} );

			info.textContent = 'Page ' + paged + ' of ' + pages + ' · ' + ( d.total || 0 ) + ' pages';
			prev.disabled = paged <= 1;
			next.disabled = paged >= pages;
		}

		var filterSel = document.getElementById( 'scc-meta-filter' );
		var templatesToggle = document.getElementById( 'scc-meta-templates' );

		function load() {
			setStatus( msg, 'Loading…' );
			var q = '/metadata?paged=' + paged +
				'&search=' + encodeURIComponent( search.value || '' ) +
				'&filter=' + encodeURIComponent( ( filterSel && filterSel.value ) || 'all' ) +
				'&include_templates=' + ( ( templatesToggle && templatesToggle.checked ) ? '1' : '0' );
			request( q, { method: 'GET' } )
				.then( function ( res ) { setStatus( msg, '', 'is-ok' ); render( res ); } )
				.catch( function ( err ) { setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' ); } );
		}

		search.addEventListener( 'input', function () {
			clearTimeout( timer );
			timer = setTimeout( function () { paged = 1; load(); }, 350 );
		} );
		if ( filterSel ) { filterSel.addEventListener( 'change', function () { paged = 1; load(); } ); }
		if ( templatesToggle ) { templatesToggle.addEventListener( 'change', function () { paged = 1; load(); } ); }
		prev.addEventListener( 'click', function () { if ( paged > 1 ) { paged--; load(); } } );
		next.addEventListener( 'click', function () { if ( paged < pages ) { paged++; load(); } } );

		// Save every visible row's current values (sequential, with progress).
		var saveAll = document.getElementById( 'scc-meta-save-all' );
		if ( saveAll ) {
			saveAll.addEventListener( 'click', function () {
				var rows = Array.prototype.slice.call( list.querySelectorAll( '.scc-meta-row' ) );
				if ( ! rows.length ) { return; }
				if ( ! window.confirm( 'Save meta title & description for all ' + rows.length + ' visible page(s)?' ) ) {
					return;
				}
				saveAll.disabled = true;
				var i = 0, saved = 0, failed = 0;
				function nextRow() {
					if ( i >= rows.length ) {
						saveAll.disabled = false;
						setStatus( msg, 'Saved ' + saved + ' page(s)' + ( failed ? ', ' + failed + ' failed' : '' ) + '.', failed ? 'is-error' : 'is-ok' );
						return;
					}
					var row = rows[ i++ ];
					setStatus( msg, 'Saving… ' + i + ' / ' + rows.length );
					var p = row._sccSave ? row._sccSave() : Promise.resolve();
					p.then( function () { saved++; nextRow(); } ).catch( function () { failed++; nextRow(); } );
				}
				nextRow();
			} );
		}

		// Suggest for all visible rows — one AI call per page, sequential to stay
		// gentle on the provider. Fills the top-ranked variant (prefers a
		// click-through option); the user still reviews and saves each row.
		var suggestAll = document.getElementById( 'scc-meta-suggest-all' );
		if ( suggestAll ) {
			suggestAll.addEventListener( 'click', function () {
				var rows = Array.prototype.slice.call( list.querySelectorAll( '.scc-meta-row' ) );
				if ( ! rows.length ) { return; }
				if ( ! window.confirm( 'Generate AI suggestions for all ' + rows.length + ' visible page(s)? This makes one AI request per page (uses AI credits). Nothing is saved automatically — you review and Save each row.' ) ) {
					return;
				}
				suggestAll.disabled = true;
				var i = 0, filled = 0, failed = 0;

				function pickBest( variants ) {
					if ( ! variants || ! variants.length ) { return null; }
					for ( var k = 0; k < variants.length; k++ ) {
						if ( variants[ k ].type === 'ctr' ) { return variants[ k ]; }
					}
					return variants[ 0 ];
				}

				function nextRow() {
					if ( i >= rows.length ) {
						suggestAll.disabled = false;
						setStatus( msg, 'Suggested ' + filled + ' page(s)' + ( failed ? ', ' + failed + ' failed' : '' ) + '. Review and Save each row.', filled ? 'is-ok' : 'is-error' );
						return;
					}
					var row = rows[ i++ ];
					var pid = row.getAttribute( 'data-post-id' );
					setStatus( msg, 'Suggesting… ' + i + ' / ' + rows.length );
					if ( row._sccStatus ) { setStatus( row._sccStatus, 'AI…' ); }
					request( '/meta/variants', { method: 'POST', data: { post_id: pid } } )
						.then( function ( res ) {
							var best = pickBest( ( res.data || {} ).variants );
							if ( best && row._sccApply ) { row._sccApply( best ); filled++; if ( row._sccStatus ) { setStatus( row._sccStatus, 'Suggested — review + Save.', 'is-ok' ); } }
							else { failed++; }
							nextRow();
						} )
						.catch( function () { failed++; if ( row._sccStatus ) { setStatus( row._sccStatus, 'AI failed', 'is-error' ); } nextRow(); } );
				}
				nextRow();
			} );
		}

		load();
	}

	// ---- Insights: snapshot + experiments -----------------------------
	function bindInsights() {
		var snap = document.getElementById( 'scc-snapshot' );
		var msg = document.getElementById( 'scc-insights-msg' );
		if ( snap ) {
			snap.addEventListener( 'click', function () {
				snap.disabled = true;
				setStatus( msg, 'Capturing…' );
				request( '/health-timeline/snapshot', { method: 'POST' } )
					.then( function () { window.location.reload(); } )
					.catch( function ( err ) { snap.disabled = false; setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' ); } );
			} );
		}

		var startBtn = document.getElementById( 'scc-exp-start' );
		if ( startBtn ) {
			var emsg = document.getElementById( 'scc-exp-msg' );
			startBtn.addEventListener( 'click', function () {
				var pid = ( document.getElementById( 'scc-exp-post' ) || {} ).value;
				var type = ( document.getElementById( 'scc-exp-type' ) || {} ).value;
				var note = ( document.getElementById( 'scc-exp-note' ) || {} ).value;
				if ( ! pid ) { setStatus( emsg, 'Enter a post ID.', 'is-error' ); return; }
				startBtn.disabled = true;
				setStatus( emsg, 'Capturing baseline…' );
				request( '/experiments', { method: 'POST', data: { post_id: pid, change_type: type, note: note } } )
					.then( function () { window.location.reload(); } )
					.catch( function ( err ) { startBtn.disabled = false; setStatus( emsg, ( err && err.message ) || i18n.error, 'is-error' ); } );
			} );
		}

		var expTable = document.getElementById( 'scc-exp-table' );
		if ( expTable ) {
			expTable.addEventListener( 'click', function ( e ) {
				var row = e.target.closest( 'tr' );
				if ( ! row ) { return; }
				var id = row.getAttribute( 'data-id' );
				var emsg2 = document.getElementById( 'scc-exp-msg' );
				if ( e.target.classList.contains( 'scc-exp-eval' ) ) {
					e.target.disabled = true;
					setStatus( emsg2, 'Evaluating…' );
					request( '/experiments/' + id, { method: 'POST' } )
						.then( function () { window.location.reload(); } )
						.catch( function ( err ) { e.target.disabled = false; setStatus( emsg2, ( err && err.message ) || i18n.error, 'is-error' ); } );
				} else if ( e.target.classList.contains( 'scc-exp-del' ) ) {
					if ( ! window.confirm( 'Delete this experiment?' ) ) { return; }
					request( '/experiments/' + id, { method: 'DELETE' } )
						.then( function () { row.parentNode.removeChild( row ); } )
						.catch( function () {} );
				}
			} );
		}
	}

	// ---- Batch jobs + publishing queue ---------------------------------
	function bindJobs() {
		var msg = document.getElementById( 'scc-jobs-msg' );
		var pauseBtn = document.getElementById( 'scc-jobs-pause' );
		if ( pauseBtn ) {
			pauseBtn.addEventListener( 'click', function () {
				var action = pauseBtn.textContent.indexOf( 'Resume' ) !== -1 ? 'resume' : 'pause';
				request( '/jobs/' + action, { method: 'POST' } )
					.then( function () {
						window.location.reload();
					} )
					.catch( function ( err ) {
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var retryBtn = document.getElementById( 'scc-jobs-retry' );
		if ( retryBtn ) {
			retryBtn.addEventListener( 'click', function () {
				setStatus( msg, 'Requeuing failed jobs…' );
				request( '/jobs/retry', { method: 'POST' } )
					.then( function () {
						setStatus( msg, 'Failed jobs requeued.', 'is-ok' );
					} )
					.catch( function ( err ) {
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var batchBtn = document.getElementById( 'scc-jobs-batch' );
		if ( batchBtn ) {
			batchBtn.addEventListener( 'click', function () {
				batchBtn.disabled = true;
				setStatus( msg, 'Finding approved entries…' );
				request( '/content-plan?status=approved', { method: 'GET' } )
					.then( function ( res ) {
						var entries = ( res.data && res.data.entries ) || [];
						batchBtn.disabled = false;
						if ( ! entries.length ) {
							setStatus( msg, 'No entries are marked Approved in your Content Plan.', 'is-error' );
							return;
						}
						var ids = entries.map( function ( e ) { return e.id; } );
						var estimate = ( ids.length * 0.05 ).toFixed( 2 );
						if ( ! window.confirm( 'You are about to generate ' + ids.length + ' page(s) in the background. Rough estimated AI cost: $' + estimate + ' (varies by model and length). Proceed?' ) ) {
							return;
						}
						setStatus( msg, 'Queuing…' );
						request( '/jobs/batch', { method: 'POST', data: { entry_ids: ids } } )
							.then( function ( r ) {
								var q = ( r.data && r.data.queued ) || 0;
								setStatus( msg, q + ' job(s) queued. They will generate in the background.', 'is-ok' );
							} )
							.catch( function ( err ) {
								setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
							} );
					} )
					.catch( function ( err ) {
						batchBtn.disabled = false;
						setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}
	}

	function bindPublishing() {
		var table = document.getElementById( 'scc-publish-table' );
		if ( ! table ) {
			return;
		}
		var msg = document.getElementById( 'scc-publish-msg' );

		function act( id, action, data ) {
			return request( '/publishing/' + action, { method: 'POST', data: Object.assign( { post_id: id }, data || {} ) } );
		}

		table.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( 'tr' );
			if ( ! row ) {
				return;
			}
			var id = row.getAttribute( 'data-id' );

			if ( e.target.classList.contains( 'scc-publish' ) ) {
				if ( ! window.confirm( 'Publish this page now?' ) ) {
					return;
				}
				e.target.disabled = true;
				setStatus( msg, 'Publishing…' );
				act( id, 'publish' ).then( function () {
					var s = row.querySelector( '.scc-pub-status' );
					if ( s ) { s.textContent = 'publish'; }
					setStatus( msg, 'Published.', 'is-ok' );
				} ).catch( function ( err ) {
					e.target.disabled = false;
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
				} );
			} else if ( e.target.classList.contains( 'scc-approve' ) ) {
				var on = e.target.getAttribute( 'data-on' ) === '1';
				act( id, on ? 'approve' : 'unapprove' ).then( function () {
					window.location.reload();
				} ).catch( function ( err ) {
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
				} );
			} else if ( e.target.classList.contains( 'scc-remove' ) ) {
				if ( ! window.confirm( 'Remove this draft from the queue? It will be moved to Trash and can be restored from there.' ) ) {
					return;
				}
				e.target.disabled = true;
				setStatus( msg, 'Removing…' );
				act( id, 'remove' ).then( function () {
					row.parentNode.removeChild( row );
					setStatus( msg, 'Removed. It is in Trash if you need it back.', 'is-ok' );
				} ).catch( function ( err ) {
					e.target.disabled = false;
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
				} );
			} else if ( e.target.classList.contains( 'scc-schedule' ) ) {
				var dt = row.querySelector( '.scc-schedule-dt' );
				if ( ! dt || ! dt.value ) {
					setStatus( msg, 'Pick a date and time first.', 'is-error' );
					return;
				}
				var value = dt.value.replace( 'T', ' ' ) + ':00';
				setStatus( msg, 'Scheduling…' );
				act( id, 'schedule', { datetime: value } ).then( function () {
					var s = row.querySelector( '.scc-pub-status' );
					if ( s ) { s.textContent = 'future'; }
					setStatus( msg, 'Scheduled.', 'is-ok' );
				} ).catch( function ( err ) {
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
				} );
			}
		} );
	}

	// ---- Unified editor SEO panel --------------------------------------
	function bindSeoPanel() {
		var panel = document.querySelector( '.scc-panel' );
		if ( ! panel ) {
			return;
		}
		var postId = panel.getAttribute( 'data-post-id' );
		var status = document.getElementById( 'scc-panel-status' );
		var out = document.getElementById( 'scc-panel-out' );

		function loadReport() {
			request( '/seo-report?post_id=' + postId, { method: 'GET' } )
				.then( function ( res ) {
					var d = res.data || {};
					var scoreEl = document.getElementById( 'scc-panel-score' );
					if ( scoreEl ) {
						scoreEl.textContent = ( d.score || 0 ) + '/100';
					}
					var rows = document.getElementById( 'scc-panel-rows' );
					rows.innerHTML = '';
					( d.items || [] ).forEach( function ( it ) {
						var row = el( 'div', null, 'scc-panel__row' );
						row.appendChild( el( 'span', it.label ) );
						var v = el( 'span', String( it.value ) + ( it.note ? ' ' + it.note : '' ), 'scc-panel__val' );
						if ( it.ok === true ) { v.classList.add( 'is-ok' ); }
						if ( it.ok === false ) { v.classList.add( 'is-bad' ); }
						row.appendChild( v );
						rows.appendChild( row );
					} );
				} )
				.catch( function () {} );
		}

		var smartBtn = document.getElementById( 'scc-panel-smart' );
		if ( smartBtn ) {
			smartBtn.addEventListener( 'click', function () {
				setStatus( status, 'Reading Page Brain, TideScore and Search Console learning…' );
				out.innerHTML = '';
				request( '/seo-report?post_id=' + postId, { method: 'GET' } )
					.then( function ( res ) {
						setStatus( status, '', 'is-ok' );
						var d = res.data || {};
						var tide = d.tidescore || {};
						var brain = d.page_brain || {};
						var learn = d.gsc_learning || {};

						var wrap = el( 'div', null, 'scc-pageopt' );
						wrap.appendChild( el( 'div', 'TideScore ' + ( tide.score == null ? '—' : tide.score + '/100' ), 'scc-panel__item-h' ) );

						( tide.factors || [] ).forEach( function ( factor ) {
							var row = el( 'div', null, 'scc-po-bar' );
							var head = el( 'div', null, 'scc-po-bar__head' );
							head.appendChild( el( 'span', factor.label || '' ) );
							head.appendChild( el( 'strong', String( factor.pct == null ? 0 : factor.pct ) + '%' ) );
							row.appendChild( head );
							var track = el( 'div', null, 'scc-po-track' );
							var fill = el( 'div', null, 'scc-po-fill' );
							fill.style.width = String( factor.pct == null ? 0 : factor.pct ) + '%';
							track.appendChild( fill );
							row.appendChild( track );
							if ( factor.note ) { row.appendChild( el( 'div', factor.note, 'scc-note' ) ); }
							wrap.appendChild( row );
						} );

						var cann = brain.cannibalization || {};
						if ( cann.level ) {
							var closest = cann.closest && cann.closest.title ? ' Closest page: ' + cann.closest.title + '.' : '';
							wrap.appendChild( el( 'div', 'Cannibalization risk: ' + String( cann.level ).toUpperCase() + ' (' + ( cann.score || 0 ) + '%).' + closest, 'scc-note' ) );
						}

						if ( ( brain.entities || [] ).length ) {
							wrap.appendChild( el( 'div', 'Planned entities/topics: ' + brain.entities.slice( 0, 8 ).join( ', ' ), 'scc-note' ) );
						}
						var coverage = tide.topic_coverage || {};
						if ( ( coverage.missing_topics || [] ).length ) {
							wrap.appendChild( el( 'div', 'Missing topic coverage: ' + coverage.missing_topics.slice( 0, 8 ).join( ', ' ), 'scc-note is-bad' ) );
						}
						if ( ( coverage.missing_questions || [] ).length ) {
							wrap.appendChild( el( 'div', 'Questions still weakly covered: ' + coverage.missing_questions.slice( 0, 5 ).join( ' • ' ), 'scc-note' ) );
						}

						var recs = learn.recommendations || [];
						if ( recs.length ) {
							wrap.appendChild( el( 'div', 'Search Console learning', 'scc-label' ) );
							recs.forEach( function ( rec ) {
								var box = el( 'div', null, 'scc-panel__item' );
								box.appendChild( el( 'div', rec.title || 'Recommendation', 'scc-panel__item-h' ) );
								box.appendChild( el( 'div', rec.reason || '', 'scc-note' ) );
								if ( rec.data && rec.data.query ) { box.appendChild( el( 'div', 'Query: ' + rec.data.query, 'scc-note' ) ); }
								wrap.appendChild( box );
							} );
						} else {
							wrap.appendChild( el( 'p', 'No stored Search Console learning recommendations yet. They appear automatically after GSC is connected and the intelligence refresh has performance data.', 'scc-note' ) );
						}

						( tide.issues || [] ).forEach( function ( issue ) {
							wrap.appendChild( el( 'div', ( issue.severity || 'medium' ).toUpperCase() + ': ' + ( issue.factor || '' ) + ' — ' + ( issue.note || '' ), 'scc-note' ) );
						} );
						if ( tide.disclaimer ) { wrap.appendChild( el( 'p', tide.disclaimer, 'scc-note' ) ); }
						out.appendChild( wrap );
					} )
					.catch( function ( err ) {
						setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		var optimizeBtn = document.getElementById( 'scc-panel-optimize' );
		if ( optimizeBtn ) {
			optimizeBtn.addEventListener( 'click', function () {
				setStatus( status, 'Scoring this page…' );
				out.innerHTML = '';
				request( '/page/' + postId + '/optimize', { method: 'GET' } )
					.then( function ( res ) {
						setStatus( status, '', 'is-ok' );
						var sc = ( res.data && res.data.scorecard ) || {};
						var scoreEl = document.getElementById( 'scc-panel-score' );
						if ( scoreEl ) { scoreEl.textContent = ( sc.score || 0 ) + '/100'; }

						var wrap = el( 'div', null, 'scc-pageopt' );

						// Component bars.
						( sc.components || [] ).forEach( function ( c ) {
							var b = el( 'div', null, 'scc-po-bar' );
							var head = el( 'div', null, 'scc-po-bar__head' );
							head.appendChild( el( 'span', c.label + ( c.note ? ' (' + c.note + ')' : '' ) ) );
							head.appendChild( el( 'strong', c.known ? ( c.pct + '%' ) : 'n/a' ) );
							b.appendChild( head );
							var track = el( 'div', null, 'scc-po-track' );
							var fill = el( 'div', null, 'scc-po-fill' + ( c.known ? '' : ' is-unknown' ) );
							fill.style.width = ( c.known ? c.pct : 0 ) + '%';
							track.appendChild( fill );
							b.appendChild( track );
							wrap.appendChild( b );
						} );

						// Prioritized recommendations.
						var recs = sc.recommendations || [];
						if ( recs.length ) {
							wrap.appendChild( el( 'div', 'Prioritized fixes', 'scc-label' ) );
							recs.forEach( function ( r ) {
								var row = el( 'div', null, 'scc-po-rec scc-po-rec--' + r.severity );
								row.appendChild( el( 'span', r.severity.toUpperCase(), 'scc-flag scc-flag--prio-' + r.severity ) );
								var t = el( 'span', null, 'scc-po-rec__t' );
								t.innerHTML = '<strong>' + ( r.label || '' ).replace( /</g, '&lt;' ) + '</strong> — ' + ( r.fix || '' ).replace( /</g, '&lt;' );
								row.appendChild( t );
								wrap.appendChild( row );
							} );
						} else {
							wrap.appendChild( el( 'p', 'No prioritized fixes — this page looks well optimized.', 'scc-note' ) );
						}

						if ( sc.disclaimer ) { wrap.appendChild( el( 'p', sc.disclaimer, 'scc-note' ) ); }
						out.appendChild( wrap );
					} )
					.catch( function ( err ) {
						setStatus( status, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		document.getElementById( 'scc-panel-links' ).addEventListener( 'click', function () {
			setStatus( status, 'Analyzing links…' );
			out.innerHTML = '';
			request( '/links/analyze', { method: 'POST', data: { post_id: postId } } )
				.then( function () {
					return request( '/links/recommendations', { method: 'GET' } );
				} )
				.then( function ( res ) {
					setStatus( status, '', 'is-ok' );
					var recs = ( ( res.data && res.data.recommendations ) || [] ).filter( function ( r ) {
						return String( r.source_post_id ) === String( postId ) || String( r.target_post_id ) === String( postId );
					} );
					if ( ! recs.length ) {
						out.appendChild( el( 'p', 'No high-relevance link opportunities found.', 'scc-note' ) );
						return;
					}
					recs.slice( 0, 12 ).forEach( function ( r ) {
						var dir = String( r.source_post_id ) === String( postId ) ? '→ ' + r.target_title : '← ' + r.source_title;
						var box = el( 'div', null, 'scc-panel__item' );
						box.appendChild( el( 'div', dir + ' (' + r.confidence + '%)', 'scc-panel__item-h' ) );
						box.appendChild( el( 'div', 'Anchor: “' + r.anchor + '” — ' + ( r.reason || '' ), 'scc-note' ) );
						var b = el( 'button', 'Insert', 'button button-small' );
						b.addEventListener( 'click', function () {
							b.disabled = true;
							request( '/links/apply', { method: 'POST', data: { id: r.id } } )
								.then( function () { b.textContent = 'Inserted'; loadReport(); } )
								.catch( function ( e ) { b.disabled = false; setStatus( status, ( e && e.message ) || i18n.error, 'is-error' ); } );
						} );
						box.appendChild( b );
						out.appendChild( box );
					} );
				} )
				.catch( function ( err ) { setStatus( status, ( err && err.message ) || i18n.error, 'is-error' ); } );
		} );

		document.getElementById( 'scc-panel-meta' ).addEventListener( 'click', function () {
			setStatus( status, 'Generating metadata variants…' );
			out.innerHTML = '';
			request( '/meta/variants', { method: 'POST', data: { post_id: postId } } )
				.then( function ( res ) {
					setStatus( status, '', 'is-ok' );
					var d = res.data || {};
					( d.variants || [] ).forEach( function ( v ) {
						var box = el( 'div', null, 'scc-panel__item' );
						box.appendChild( el( 'div', '[' + v.type + '] ' + v.title, 'scc-panel__item-h' ) );
						box.appendChild( el( 'div', v.description, 'scc-note' ) );
						if ( v.reason ) { box.appendChild( el( 'div', v.reason, 'scc-note' ) ); }
						var b = el( 'button', 'Apply', 'button button-small' );
						b.addEventListener( 'click', function () {
							b.disabled = true;
							request( '/meta/apply', { method: 'POST', data: { post_id: postId, title: v.title, description: v.description, reason: v.reason, force: true } } )
								.then( function () { b.textContent = 'Applied'; loadReport(); } )
								.catch( function ( e ) { b.disabled = false; setStatus( status, ( e && e.message ) || i18n.error, 'is-error' ); } );
						} );
						box.appendChild( b );
						out.appendChild( box );
					} );
				} )
				.catch( function ( err ) { setStatus( status, ( err && err.message ) || i18n.error, 'is-error' ); } );
		} );

		document.getElementById( 'scc-panel-schema' ).addEventListener( 'click', function () {
			setStatus( status, 'Checking schema…' );
			out.innerHTML = '';
			request( '/schema/recommend', { method: 'POST', data: { post_id: postId } } )
				.then( function ( res ) {
					setStatus( status, '', 'is-ok' );
					var d = res.data || {};
					out.appendChild( el( 'div', 'Recommended: ' + ( d.recommended || [] ).join( ', ' ), 'scc-note' ) );
					if ( d.conflicts && d.conflicts.conflicts && d.conflicts.conflicts.length ) {
						out.appendChild( el( 'div', '⚠ Possible duplicate with existing: ' + d.conflicts.conflicts.join( ', ' ), 'scc-note is-bad' ) );
					}
					var gen = el( 'button', 'Generate & save schema', 'button button-small button-primary' );
					gen.addEventListener( 'click', function () {
						gen.disabled = true;
						request( '/schema/save', { method: 'POST', data: { post_id: postId, types: d.recommended } } )
							.then( function () { gen.textContent = 'Saved'; loadReport(); } )
							.catch( function ( e ) { gen.disabled = false; setStatus( status, ( e && e.message ) || i18n.error, 'is-error' ); } );
					} );
					out.appendChild( gen );
				} )
				.catch( function ( err ) { setStatus( status, ( err && err.message ) || i18n.error, 'is-error' ); } );
		} );

		loadReport();
	}

	// ---- Schema business settings --------------------------------------
	function bindSchemaSettings() {
		var form = document.getElementById( 'scc-schema-settings-form' );
		if ( ! form ) {
			return;
		}
		var status = document.getElementById( 'scc-schema-settings-status' );
		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var data = {};
			Array.prototype.forEach.call( form.elements, function ( el ) {
				if ( el.name ) {
					data[ el.name ] = el.value;
				}
			} );
			setStatus( status, '…' );
			request( '/schema/settings', { method: 'POST', data: data } )
				.then( function () { setStatus( status, i18n.saved || 'Saved.', 'is-ok' ); } )
				.catch( function ( err ) { setStatus( status, ( err && err.message ) || i18n.error, 'is-error' ); } );
		} );
	}

	// ---- Native template engine ----------------------------------------
	function bindNativeTemplates() {
		var rendererSel = document.getElementById( 'scc-default-renderer' );
		if ( rendererSel ) {
			var rmsg = document.getElementById( 'scc-renderer-msg' );
			rendererSel.addEventListener( 'change', function () {
				request( '/templates/native/map', { method: 'POST', data: { default_renderer: rendererSel.value } } )
					.then( function () { setStatus( rmsg, i18n.saved || 'Saved.', 'is-ok' ); } )
					.catch( function ( e ) { setStatus( rmsg, ( e && e.message ) || i18n.error, 'is-error' ); } );
			} );
		}

		var newBtn = document.getElementById( 'scc-new-template' );
		if ( newBtn ) {
			var tmsg = document.getElementById( 'scc-template-msg' );
			newBtn.addEventListener( 'click', function () {
				var type = ( document.getElementById( 'scc-new-template-type' ) || {} ).value || 'service';
				newBtn.disabled = true;
				setStatus( tmsg, 'Creating…' );
				request( '/templates/native', { method: 'POST', data: { content_type: type, name: type.replace( /_/g, ' ' ) + ' template' } } )
					.then( function () { window.location.reload(); } )
					.catch( function ( e ) { newBtn.disabled = false; setStatus( tmsg, ( e && e.message ) || i18n.error, 'is-error' ); } );
			} );
		}

		var table = document.getElementById( 'scc-templates-table' );
		if ( table ) {
			var tmsg2 = document.getElementById( 'scc-template-msg' );
			var previewOut = document.getElementById( 'scc-tpl-preview-out' );
			table.addEventListener( 'click', function ( e ) {
				var row = e.target.closest( 'tr' );
				if ( ! row ) { return; }
				var id = row.getAttribute( 'data-id' );
				var family = row.getAttribute( 'data-family' );
				var type = row.getAttribute( 'data-type' );

				if ( e.target.classList.contains( 'scc-tpl-delete' ) ) {
					if ( ! window.confirm( 'Delete this template? Existing pages are unaffected.' ) ) { return; }
					request( '/templates/native/' + id, { method: 'DELETE' } )
						.then( function () { row.parentNode.removeChild( row ); setStatus( tmsg2, 'Deleted.', 'is-ok' ); } )
						.catch( function ( er ) { setStatus( tmsg2, ( er && er.message ) || i18n.error, 'is-error' ); } );
				} else if ( e.target.classList.contains( 'scc-tpl-clone' ) ) {
					request( '/templates/native/clone', { method: 'POST', data: { id: id } } )
						.then( function () { window.location.reload(); } )
						.catch( function ( er ) { setStatus( tmsg2, ( er && er.message ) || i18n.error, 'is-error' ); } );
				} else if ( e.target.classList.contains( 'scc-tpl-preview' ) ) {
					setStatus( tmsg2, 'Rendering preview…' );
					request( '/templates/native/preview', { method: 'POST', data: { content_type: type, family: family, service: 'Local SEO', city: 'Daytona Beach', primary_keyword: 'Daytona Beach Local SEO' } } )
						.then( function ( res ) {
							setStatus( tmsg2, '', 'is-ok' );
							var d = res.data || {};
							previewOut.innerHTML = '';
							previewOut.appendChild( el( 'p', 'Template: ' + d.template + ' · Renderer: ' + d.renderer + ' · Selected via: ' + d.source, 'scc-note' ) );
							var pre = el( 'pre', d.html || '' );
							pre.style.cssText = 'white-space:pre-wrap;background:#f6f7f8;border:1px solid #e0e0e2;border-radius:6px;padding:12px;max-height:360px;overflow:auto;';
							previewOut.appendChild( pre );
						} )
						.catch( function ( er ) { setStatus( tmsg2, ( er && er.message ) || i18n.error, 'is-error' ); } );
				}
			} );
		}

		var mapTable = document.getElementById( 'scc-tpl-map-table' );
		if ( mapTable ) {
			var mmsg = document.getElementById( 'scc-map-msg' );
			mapTable.addEventListener( 'change', function ( e ) {
				var row = e.target.closest( 'tr' );
				var ct = row.getAttribute( 'data-content-type' );
				var family = ( row.querySelector( '.scc-map-family' ) || {} ).value || '';
				var renderer = ( row.querySelector( '.scc-map-renderer' ) || {} ).value || '';
				request( '/templates/native/map', { method: 'POST', data: { content_type: ct, family: family, renderer: renderer } } )
					.then( function () { setStatus( mmsg, i18n.saved || 'Saved.', 'is-ok' ); } )
					.catch( function ( er ) { setStatus( mmsg, ( er && er.message ) || i18n.error, 'is-error' ); } );
			} );
		}

		var elImport = document.getElementById( 'scc-el-import' );
		if ( elImport ) {
			var elmsg = document.getElementById( 'scc-el-msg' );
			elImport.addEventListener( 'click', function () {
				var source = ( document.getElementById( 'scc-el-source' ) || {} ).value;
				var type = ( document.getElementById( 'scc-el-type' ) || {} ).value;
				elImport.disabled = true;
				setStatus( elmsg, 'Importing…' );
				request( '/templates/native/import-elementor', { method: 'POST', data: { source_id: source, content_type: type } } )
					.then( function () { window.location.reload(); } )
					.catch( function ( er ) { elImport.disabled = false; setStatus( elmsg, ( er && er.message ) || i18n.error, 'is-error' ); } );
			} );
		}

		// Inspect the tokens detected in the selected Elementor template.
		var elInspect = document.getElementById( 'scc-el-inspect' );
		if ( elInspect ) {
			var inspMsg = document.getElementById( 'scc-el-msg' );
			var inspOut = document.getElementById( 'scc-el-inspect-out' );
			elInspect.addEventListener( 'click', function () {
				var source = ( document.getElementById( 'scc-el-source' ) || {} ).value;
				if ( ! source ) {
					setStatus( inspMsg, 'Choose a template first.', 'is-error' );
					return;
				}
				elInspect.disabled = true;
				setStatus( inspMsg, 'Reading template…' );
				request( '/templates/' + source + '/variables', { method: 'GET' } )
					.then( function ( res ) {
						elInspect.disabled = false;
						setStatus( inspMsg, '', 'is-ok' );
						var d = res.data || {};
						var v = d.validation || {};
						inspOut.innerHTML = '';
						inspOut.hidden = false;
						var badge = v.status === 'ready'
							? '<span class="scc-badge scc-badge--ok">Ready</span>'
							: '<span class="scc-badge scc-badge--warn">Needs attention</span>';
						inspOut.appendChild( el( 'p' ) ).innerHTML = 'Template status: ' + badge;
						( v.errors || [] ).forEach( function ( m ) {
							inspOut.appendChild( el( 'div', '✕ ' + m, 'scc-flag scc-flag--prio-high' ) );
						} );
						( v.warnings || [] ).forEach( function ( m ) {
							inspOut.appendChild( el( 'div', '! ' + m, 'scc-note' ) );
						} );
						var tokens = ( d.detected || [] ).map( function ( t ) { return t.token; } );
						inspOut.appendChild( el( 'div', tokens.length ? ( 'Detected tokens: ' + tokens.join( ', ' ) ) : 'No {{tokens}} found in this template.', 'scc-note' ) );
					} )
					.catch( function ( er ) {
						elInspect.disabled = false;
						setStatus( inspMsg, ( er && er.message ) || i18n.error, 'is-error' );
					} );
			} );
		}

		// Live filter for the token reference table.
		var varSearch = document.getElementById( 'scc-var-search' );
		if ( varSearch ) {
			varSearch.addEventListener( 'input', function () {
				var q = varSearch.value.toLowerCase();
				document.querySelectorAll( '#scc-var-reference .scc-var-group' ).forEach( function ( group ) {
					var any = false;
					group.querySelectorAll( '.scc-var-row' ).forEach( function ( row ) {
						var match = row.textContent.toLowerCase().indexOf( q ) !== -1;
						row.hidden = ! match;
						if ( match ) { any = true; }
					} );
					group.hidden = ! any;
				} );
			} );
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindAnalysis();
		bindSettings();
		bindRouteModels();
		bindLmStudioDetect();
		bindConnections();
		bindKeywordStrategy();
		bindTopicBriefs();
		bindGscWinsPlanButtons();
		bindSeedPlan();
		bindArchitectureBrain();
		bindContentPlan();
		bindGenerate();
		bindTemplates();
		bindInternalLinks();
		bindGscQuickWins();
		bindCitationScanner();
		bindCompetitor();
		bindCompetitorGaps();
		bindJobs();
		bindPublishing();
		bindSeoPanel();
		bindSchemaSettings();
		bindNativeTemplates();
		bindDoctor();
		bindOpportunities();
		bindCopilot();
		bindActionQueue();
		bindInsights();
		bindMetaEditor();
		bindContentIdeas();
		bindLayoutEngine();
	} );

	// ---- AI Elementor Layout Engine ------------------------------------
	function bindLayoutEngine() {
		var root = document.getElementById( 'scc-layout' );
		if ( ! root ) { return; }

		var postId = parseInt( root.getAttribute( 'data-post' ), 10 ) || 0;
		var isLive = root.getAttribute( 'data-live' ) === '1';
		var pickerMsg = document.getElementById( 'scc-layout-picker-msg' );

		function cloneDraft( sourceId, button, statusEl ) {
			if ( ! sourceId ) { return; }
			if ( button ) { button.disabled = true; }
			setStatus( statusEl, 'Creating safe draft copy…' );
			request( '/layout/clone-draft', { method: 'POST', data: { post_id: sourceId } } )
				.then( function ( res ) {
					var d = res.data || {};
					setStatus( statusEl, d.message || 'Draft working copy created.', 'is-ok' );
					if ( d.layout_url ) { window.location.href = d.layout_url; }
				} )
				.catch( function ( err ) {
					if ( button ) { button.disabled = false; }
					setStatus( statusEl, ( err && err.message ) || 'Could not create a draft copy.', 'is-error' );
				} );
		}

		// Picker mode: Drafts are the default; Live is an explicit separate view.
		Array.prototype.forEach.call( document.querySelectorAll( '#scc-layout-picker-toggle [data-layout-list]' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				var wanted = btn.getAttribute( 'data-layout-list' );
				Array.prototype.forEach.call( document.querySelectorAll( '#scc-layout-picker-toggle [data-layout-list]' ), function ( b ) {
					var active = b === btn;
					b.classList.toggle( 'button-primary', active );
					b.classList.toggle( 'is-active', active );
				} );
				Array.prototype.forEach.call( document.querySelectorAll( '[data-layout-panel]' ), function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-layout-panel' ) !== wanted;
				} );
			} );
		} );
		Array.prototype.forEach.call( document.querySelectorAll( '.scc-layout-clone-picker' ), function ( btn ) {
			btn.addEventListener( 'click', function () {
				cloneDraft( parseInt( btn.getAttribute( 'data-post' ), 10 ) || 0, btn, pickerMsg );
			} );
		} );

		if ( postId <= 0 ) { return; }

		var preview = document.getElementById( 'scc-layout-preview' );
		var msg = document.getElementById( 'scc-layout-msg' );
		var metaEl = document.getElementById( 'scc-layout-meta' );
		var criticEl = document.getElementById( 'scc-layout-critic' );
		var applyBtn = document.getElementById( 'scc-layout-apply' );
		var regen = document.getElementById( 'scc-layout-regen' );
		var aiBox = document.getElementById( 'scc-layout-ai' );
		var confirmLive = document.getElementById( 'scc-layout-confirm-live' );
		var restoreBtn = document.getElementById( 'scc-layout-restore' );
		var cloneBtn = document.getElementById( 'scc-layout-clone-draft' );
		var safetyMsg = document.getElementById( 'scc-layout-safety-msg' );
		if ( ! preview || ! applyBtn ) { return; }

		var blocks = []; // [{id, name}]

		function updateApplyState() {
			applyBtn.disabled = ! blocks.length || ( isLive && ( ! confirmLive || ! confirmLive.checked ) );
		}

		function draw() {
			preview.innerHTML = '';
			if ( ! blocks.length ) {
				preview.appendChild( el( 'p', 'No blocks — try Regenerate.', 'scc-note' ) );
				updateApplyState();
				return;
			}
			blocks.forEach( function ( b, i ) {
				var row = el( 'div', null, 'scc-lblock' );
				row.appendChild( el( 'span', b.name, 'scc-lblock__name' ) );
				var ctl = el( 'span', null, 'scc-lblock__ctl' );
				var up = el( 'button', '↑', 'button button-small' ); up.type = 'button'; up.title = 'Move up'; up.disabled = ( i === 0 );
				var dn = el( 'button', '↓', 'button button-small' ); dn.type = 'button'; dn.title = 'Move down'; dn.disabled = ( i === blocks.length - 1 );
				var rm = el( 'button', '✕', 'button button-small' ); rm.type = 'button'; rm.title = 'Remove';
				up.addEventListener( 'click', function () { if ( i > 0 ) { var t = blocks[ i - 1 ]; blocks[ i - 1 ] = blocks[ i ]; blocks[ i ] = t; draw(); } } );
				dn.addEventListener( 'click', function () { if ( i < blocks.length - 1 ) { var t = blocks[ i + 1 ]; blocks[ i + 1 ] = blocks[ i ]; blocks[ i ] = t; draw(); } } );
				rm.addEventListener( 'click', function () { blocks.splice( i, 1 ); draw(); } );
				ctl.appendChild( up ); ctl.appendChild( dn ); ctl.appendChild( rm );
				row.appendChild( ctl );
				preview.appendChild( row );
			} );
			updateApplyState();
		}

		function drawCritique( critic ) {
			if ( ! criticEl ) { return; }
			criticEl.innerHTML = '';
			critic = critic || {};
			var scores = critic.scores || {};
			var labels = {
				visual_hierarchy: 'Hierarchy',
				section_variety: 'Variety',
				readability: 'Readability',
				cta_visibility: 'CTA',
				accessibility: 'Accessibility',
				seo_structure: 'SEO structure'
			};
			Object.keys( labels ).forEach( function ( key ) {
				if ( scores[ key ] == null ) { return; }
				var flag = el( 'span', labels[ key ] + ': ' + scores[ key ] + '%', 'scc-flag' );
				flag.style.marginRight = '6px';
				criticEl.appendChild( flag );
			} );
			( critic.issues || [] ).forEach( function ( issue ) {
				criticEl.appendChild( el( 'div', '• ' + ( issue.label || 'Layout' ) + ': ' + ( issue.message || '' ), 'scc-note' ) );
			} );
			criticEl.hidden = false;
		}

		function propose() {
			blocks = [];
			updateApplyState();
			setStatus( msg, 'Analyzing content and choosing blocks…' );
			request( '/layout/propose', { method: 'POST', data: { post_id: postId, use_ai: aiBox && aiBox.checked } } )
				.then( function ( res ) {
					var d = res.data || {};
					blocks = ( d.blocks || [] ).map( function ( b ) { return { id: b.id, name: b.name }; } );
					if ( metaEl ) {
						metaEl.hidden = false;
						var planner = d.source === 'ai_constrained_architect' ? 'AI constrained architect' :
							d.source === 'page_architect_ai_fallback' ? 'smart rules (AI fallback)' : 'smart page architect';
						metaEl.textContent = 'Detected: ' + ( d.content_type || '?' ) + ' · ' + ( d.search_intent || '?' ) +
							' · planned by ' + planner + ( d.ai_available ? '' : ' (no AI provider configured)' );
					}
					setStatus( msg, isLive ? 'Preview ready. Live Apply remains locked until you confirm the warning.' : 'Done.', 'is-ok' );
					drawCritique( d.critique );
					draw();
				} )
				.catch( function ( err ) {
					setStatus( msg, ( err && err.message ) || i18n.error, 'is-error' );
					updateApplyState();
				} );
		}

		function restore() {
			if ( ! window.confirm( 'Restore this page from the last TideOrbit layout backup? Current layout changes made after that backup will be replaced.' ) ) { return; }
			if ( restoreBtn ) { restoreBtn.disabled = true; }
			setStatus( safetyMsg, 'Restoring previous layout…' );
			request( '/layout/restore', { method: 'POST', data: { post_id: postId } } )
				.then( function ( res ) {
					setStatus( safetyMsg, ( res.data && res.data.message ) || 'Previous layout restored. Reloading…', 'is-ok' );
					window.location.reload();
				} )
				.catch( function ( err ) {
					if ( restoreBtn ) { restoreBtn.disabled = false; }
					setStatus( safetyMsg, ( err && err.message ) || 'Could not restore the previous layout.', 'is-error' );
				} );
		}

		function apply() {
			var applyMsg = document.getElementById( 'scc-layout-apply-msg' );
			if ( isLive && ( ! confirmLive || ! confirmLive.checked ) ) {
				setStatus( applyMsg, 'Confirm the live-page warning first, or make a draft copy.', 'is-error' );
				return;
			}
			if ( isLive && ! window.confirm( 'This will change the published page visitors see. TideOrbit will save a restore point first. Apply to the LIVE page?' ) ) {
				return;
			}
			applyBtn.disabled = true;
			setStatus( applyMsg, isLive ? 'Saving restore point and updating LIVE page…' : 'Building your Elementor layout…' );
			request( '/layout/apply', {
				method: 'POST',
				data: {
					post_id: postId,
					layout: blocks.map( function ( b ) { return b.id; } ),
					confirm_live: isLive && !! ( confirmLive && confirmLive.checked )
				}
			} )
				.then( function ( res ) {
					var d = res.data || {};
					setStatus( applyMsg, isLive ? 'Live page updated. Restore point saved.' : 'Layout created. Restore point saved.', 'is-ok' );
					preview.innerHTML = '';
					var ok = el( 'div', null, 'scc-empty' );
					ok.appendChild( el( 'div', '✅', 'scc-empty__icon' ) );
					ok.appendChild( el( 'h2', isLive ? 'Live Elementor page updated' : 'Elementor layout created' ) );
					ok.appendChild( el( 'p', 'TideOrbit saved the previous state before applying this layout.', 'scc-note' ) );
					if ( d.elementor_url ) { var e = el( 'a', 'Edit in Elementor', 'button button-primary' ); e.href = d.elementor_url; ok.appendChild( e ); }
					if ( d.edit_url ) { var ed = el( 'a', isLive ? ' Edit page' : ' Edit draft', 'button' ); ed.href = d.edit_url; ok.appendChild( document.createTextNode( ' ' ) ); ok.appendChild( ed ); }
					var undo = el( 'button', 'Restore previous layout', 'button' ); undo.type = 'button'; undo.style.marginLeft = '6px'; undo.addEventListener( 'click', restore ); ok.appendChild( undo );
					preview.appendChild( ok );
				} )
				.catch( function ( err ) {
					setStatus( applyMsg, ( err && err.message ) || i18n.error, 'is-error' );
					updateApplyState();
				} );
		}

		if ( confirmLive ) { confirmLive.addEventListener( 'change', updateApplyState ); }
		if ( cloneBtn ) { cloneBtn.addEventListener( 'click', function () { cloneDraft( postId, cloneBtn, safetyMsg ); } ); }
		if ( restoreBtn ) { restoreBtn.addEventListener( 'click', restore ); }
		if ( regen ) { regen.addEventListener( 'click', propose ); }
		if ( applyBtn ) { applyBtn.addEventListener( 'click', apply ); }
		propose();

	}
} )();
