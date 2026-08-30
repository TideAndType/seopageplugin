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
					var modelInput = document.getElementById( 'scc-lmstudio-model' );
					if ( list ) {
						list.innerHTML = '';
						( d.models || [] ).forEach( function ( m ) {
							var opt = document.createElement( 'option' );
							opt.value = m;
							list.appendChild( opt );
						} );
					}
					if ( ! d.models || ! d.models.length ) {
						setStatus( status, 'Connected, but no model is loaded in LM Studio. Load one and retry.', 'is-error' );
						return;
					}
					// Auto-fill the first model if the field is empty or the default.
					if ( modelInput && ( ! modelInput.value || modelInput.value === 'local-model' ) ) {
						modelInput.value = d.models[0];
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

		// GSC connect (OAuth) button.
		var gscConnect = document.getElementById( 'scc-gsc-connect' );
		if ( gscConnect ) {
			gscConnect.addEventListener( 'click', function () {
				gscConnect.disabled = true;
				request( '/gsc/auth-url', { method: 'GET' } )
					.then( function ( res ) {
						var url = res.data && res.data.url;
						if ( url ) {
							window.location.href = url;
						} else {
							gscConnect.disabled = false;
						}
					} )
					.catch( function ( err ) {
						gscConnect.disabled = false;
						window.alert( ( err && err.message ) || 'Save your Client ID and secret first.' );
					} );
			} );
		}

		// Copy redirect URI.
		var copyRedirect = document.getElementById( 'scc-gsc-copy-redirect' );
		if ( copyRedirect ) {
			copyRedirect.addEventListener( 'click', function () {
				var codeEl = document.getElementById( 'scc-gsc-redirect' );
				var text = codeEl ? codeEl.textContent : '';
				if ( navigator.clipboard && text ) {
					navigator.clipboard.writeText( text ).then( function () {
						copyRedirect.textContent = 'Copied';
						setTimeout( function () { copyRedirect.textContent = 'Copy'; }, 1500 );
					} );
				}
			} );
		}

		// GSC verify button.
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
						if ( ! v.has_all_fields ) {
							setStatus( gscStatus, v.error || 'Missing OAuth fields.', 'is-error' );
							return;
						}
						if ( ! v.token_ok ) {
							setStatus( gscStatus, 'Token refresh failed: ' + ( v.error || 'unknown error' ), 'is-error' );
							gscOut.appendChild( el( 'p', 'Google rejected the refresh token. Re-generate it for the webmasters.readonly scope and make sure the Client ID/secret belong to the same OAuth client.', 'scc-note' ) );
							return;
						}
						setStatus( gscStatus, 'Connected — token works.', 'is-ok' );
						if ( ! v.properties || ! v.properties.length ) {
							gscOut.appendChild( el( 'p', 'The token works but this Google account has no Search Console properties.', 'scc-note' ) );
							return;
						}
						gscOut.appendChild( el( 'div', 'Verified properties this account can access:', 'scc-label' ) );
						var ul = el( 'ul', null, 'scc-options' );
						v.properties.forEach( function ( p ) {
							var li = el( 'li' );
							var code = el( 'code', p.siteUrl );
							li.appendChild( code );
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
							gscOut.appendChild( el( 'p', '⚠ Your configured property (' + v.configured_property + ') is not in the list above. Pick one of these exact values (click “Use this”), then Save connections.', 'scc-note is-bad' ) );
						} else {
							gscOut.appendChild( el( 'p', '✓ Your configured property matches — Search Console data will load.', 'scc-note' ) );
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
		// "Build from my site" — infers inputs and generates in one click.
		var autoBtn = document.getElementById( 'scc-keyword-auto' );
		if ( autoBtn ) {
			var autoStatus = document.getElementById( 'scc-keyword-auto-status' );
			autoBtn.addEventListener( 'click', function () {
				autoBtn.disabled = true;
				setStatus( autoStatus, 'Analyzing your site and building the topical map… this can take up to a minute.' );
				request( '/keywords/auto', { method: 'POST' } )
					.then( function () {
						setStatus( autoStatus, 'Done. Reloading…', 'is-ok' );
						window.location.reload();
					} )
					.catch( function ( err ) {
						autoBtn.disabled = false;
						setStatus( autoStatus, ( err && err.message ) || i18n.error, 'is-error' );
					} );
			} );
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

		var scanBtn = document.getElementById( 'scc-links-scan' );
		if ( scanBtn ) {
			scanBtn.addEventListener( 'click', function () {
				scanBtn.disabled = true;
				setStatus( msg, 'Indexing and scanning the site… this can take a moment.' );
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

	// ---- SEO Audit: GSC quick wins + competitor analysis ---------------
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
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindAnalysis();
		bindSettings();
		bindRouteModels();
		bindLmStudioDetect();
		bindConnections();
		bindKeywordStrategy();
		bindSeedPlan();
		bindContentPlan();
		bindGenerate();
		bindTemplates();
		bindInternalLinks();
		bindGscQuickWins();
		bindCompetitor();
		bindJobs();
		bindPublishing();
		bindSeoPanel();
		bindSchemaSettings();
		bindNativeTemplates();
	} );
} )();
