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
			var base = ''; // The paired Mac reports models through TideBridge.
			btn.disabled = true;
			setStatus( status, 'Checking TideBridge Mac…' );
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
			var lmModel = document.getElementById( 'scc-lmstudio-model' );
			if ( lmModel ) {
				settings.lmstudio_model = lmModel.value;
			}
			var browserMode = document.getElementById( 'scc-browser-runtime-mode' );
			if ( browserMode ) {
				settings.browser_runtime_mode = browserMode.value;
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

		// TideOrbit Browser Runtime / GBP Rank Tracker bridge test.
		var browserTest = document.getElementById( 'scc-browser-runtime-test' );
		if ( browserTest ) {
			var browserStatus = document.getElementById( 'scc-browser-runtime-test-status' );
			browserTest.addEventListener( 'click', function () {
				browserTest.disabled = true;
				setStatus( browserStatus, 'Testing Rank Tracker…' );
				request( '/browser-runtime/test', { method: 'POST', data: {} } )
					.then( function ( res ) {
						browserTest.disabled = false;
						var d = res.data || {};
						var queue = d.queue || {};
						var active = typeof queue.active === 'number' ? queue.active : 0;
						var queued = typeof queue.queued === 'number' ? queue.queued : 0;
						setStatus( browserStatus, 'Connected — Chromium ready. Active scans: ' + active + ', queued: ' + queued + '.', 'is-ok' );
					} )
					.catch( function ( err ) {
						browserTest.disabled = false;
						setStatus( browserStatus, ( err && err.message ) || 'Could not reach the Rank Tracker bridge.', 'is-error' );
					} );
			} );
		}

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


	function bindSettingsTabs() {
		var form = document.getElementById('scc-settings-form') || document.getElementById('scc-connections-form');
		if (!form) { return; }
		var connections = form.id === 'scc-connections-form';
		var headings = Array.from(form.children).filter(function(x) { return x.tagName === 'H2'; });
		var order = connections ? ['ai','ai','seo','seo','seo','seo'] :
			['ai','ai','content','seo','automation','publishing','publishing','automation','advanced'];
		if (headings.length !== order.length) { return; }
		var submit = form.querySelector('p.submit'), first = headings[0];
		if (!submit) { return; }
		var nav = document.createElement('nav'); nav.className='nav-tab-wrapper scc-settings-tabs';
		nav.setAttribute('aria-label','TideOrbit settings sections');
		form.insertBefore(nav, first);
		var panels={}, current='', children=Array.from(form.childNodes);
		children.forEach(function(child) {
			if (child===first || current) {
				if (child.nodeType===1 && child.tagName==='H2') {
					var idx=headings.indexOf(child); if (idx>=0) { current=order[idx]; }
				}
				if (child===submit) { current=''; return; }
				if (current) {
					if (!panels[current]) {
						panels[current]=document.createElement('section');
						panels[current].className='scc-settings-panel';
						form.insertBefore(panels[current],submit);
					}
					panels[current].appendChild(child);
				}
			}
		});
		if (!connections) {
			var toggle=form.querySelector('input[name="layout_auto_build"]'), row=toggle && toggle.closest('tr');
			if (row) {
				var pane=document.createElement('section'), title=document.createElement('h2'), table=document.createElement('table');
				pane.className='scc-settings-panel'; title.textContent='Elementor'; table.className='form-table';
				table.setAttribute('role','presentation'); table.appendChild(row);
				pane.appendChild(title); pane.appendChild(table); form.insertBefore(pane,submit); panels.elementor=pane;
			}
		}
		var defs=connections ? [['ai','AI Connections'],['seo','SEO Connections']] :
			[['ai','AI Settings'],['content','Content Style'],['seo','SEO Defaults'],['elementor','Elementor'],
			 ['automation','Automation'],['publishing','Publishing'],['advanced','Advanced']];
		var buttons={};
		function activate(key) {
			if (!panels[key]) { key='ai'; }
			Object.keys(panels).forEach(function(p) { panels[p].hidden=p!==key; });
			Object.keys(buttons).forEach(function(p) {
				buttons[p].classList.toggle('nav-tab-active',p===key);
				buttons[p].setAttribute('aria-selected',p===key?'true':'false');
			});
		}
		defs.forEach(function(d) {
			if (!panels[d[0]]) { return; }
			var b=document.createElement('button'); b.type='button'; b.className='nav-tab';
			b.textContent=d[1]; b.setAttribute('role','tab');
			b.addEventListener('click',function(){activate(d[0]);window.location.hash='tab-'+d[0];});
			buttons[d[0]]=b; nav.appendChild(b);
		});
		var links=connections ? [['Settings','seo-command-center-settings']] :
			[['AI Connections','seo-command-center-connections&tab=ai'],
			 ['SEO Connections','seo-command-center-connections&tab=seo']];
		links.forEach(function(d) {
			var link=document.createElement('a'); link.className='nav-tab'; link.textContent=d[0];
			link.href='admin.php?page='+d[1]; nav.appendChild(link);
		});
		var query=(new URLSearchParams(window.location.search)).get('tab');
		var hash=(window.location.hash||'').replace(/^#tab-/,'');
		activate(panels[query] ? query : hash);
	}

	document.addEventListener('DOMContentLoaded',function(){bindSettingsTabs();bindSettings();bindRouteModels();bindLmStudioDetect();bindConnections();});
} )();
