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

		// 21st.dev Design Discovery: independent of selected post so the
		// administrator can build the design library before creating a page.
		( function bindDiscovery() {
			var panel = document.getElementById( 'scc-design-discovery' );
			if ( ! panel ) { return; }
			var category = document.getElementById( 'scc-dd-category' );
			var scan = document.getElementById( 'scc-dd-scan' );
			var scanAll = document.getElementById( 'scc-dd-scan-all' );
			var stop = document.getElementById( 'scc-dd-stop' );
			var bulkRunning = false, bulkStop = false;
			var refresh = document.getElementById( 'scc-dd-refresh' );
			var message = document.getElementById( 'scc-dd-message' );
			var count = document.getElementById( 'scc-dd-count' );
			var results = document.getElementById( 'scc-dd-results' );
			function renderItems( items ) {
				results.innerHTML = '';
				if ( ! items.length ) {
					results.appendChild( el( 'p', 'No saved components in this category. Click Scan next components to start.', 'scc-note' ) );
					return;
				}
				var table = el( 'table', null, 'widefat striped' );
				var thead = el( 'thead' ), headers = el( 'tr' );
				[ 'Component', 'Designer', 'Design details', 'License', 'Actions' ].forEach( function ( label ) {
					headers.appendChild( el( 'th', label ) );
				} );
				thead.appendChild( headers ); table.appendChild( thead );
				var body = el( 'tbody' );
				items.slice( 0, 40 ).forEach( function ( item ) {
					var tr = el( 'tr' );
					var title = el( 'td' );
					if ( item.preview && item.preview.indexOf( 'https://cdn.21st.dev/' ) === 0 ) {
						var thumb = document.createElement( 'img' );
						thumb.src = item.preview;
						thumb.alt = '';
						thumb.loading = 'lazy';
						thumb.style.cssText = 'width:84px;height:64px;object-fit:cover;vertical-align:middle;margin-right:10px;border-radius:6px';
						title.appendChild( thumb );
					}
					var link = el( 'a', item.title );
					link.href = item.url;
					link.target = '_blank';
					link.rel = 'noopener noreferrer';
					title.appendChild( link ); tr.appendChild( title );
					tr.appendChild( el( 'td', item.author || 'Unknown' ) );
					tr.appendChild( el( 'td', ( item.description || '' ).slice( 0, 180 ) + ( item.signals && item.signals.length ? ' · ' + item.signals.join( ', ' ) : '' ) ) );
					tr.appendChild( el( 'td', item.license || 'Not stated' ) );
					var action = el( 'td' );
					if ( postId > 0 ) {
						var select = el( 'button', 'Use as design reference', 'button button-small' );
						select.type = 'button';
						select.addEventListener( 'click', function () {
							var prompt = document.getElementById( 'scc-layout-design-prompt' );
							if ( ! prompt ) { return; }
							var reference = 'Visual inspiration: ' + item.title + ' (' + item.url + '). ' + ( item.description || '' );
							prompt.value = ( ( prompt.value ? prompt.value + '\n' : '' ) + reference ).slice( 0, 2400 );
							var sourceInput = document.getElementById( 'scc-visual-source' );
							if ( sourceInput ) { sourceInput.value = item.url; }
							prompt.focus();
							setStatus( message, 'Reference added to Design direction. Click Regenerate design to apply it.', 'is-ok' );
						} );
						action.appendChild( select );
					}
					tr.appendChild( action );
					body.appendChild( tr );
				} );
				table.appendChild( body ); results.appendChild( table );
			}
			function load() {
				request( '/design-discovery/catalog?category=' + encodeURIComponent( category.value ), { method: 'GET' } )
					.then( function ( res ) {
						var d = res.data || {};
						count.textContent = ( d.total || 0 ) + ' saved components';
						renderItems( d.items || [] );
					} )
					.catch( function ( err ) { setStatus( message, err.message || 'Library unavailable.', 'is-error' ); } );
			}
			if ( scan ) {
				scan.addEventListener( 'click', function () {
					scan.disabled = true;
					setStatus( message, 'Scanning 21st.dev metadata for ' + category.value + '…' );
					request( '/design-discovery/scan', { method: 'POST', data: { category: category.value } } )
						.then( function ( res ) {
							var d = res.data || {};
							setStatus( message, 'Added ' + ( d.added || 0 ) + ' components from ' + ( d.found_on_page || 0 ) + ' visible links; ' + ( d.total || 0 ) + ' saved in library.', 'is-ok' );
							load();
						} )
						.catch( function ( err ) { setStatus( message, err.message || 'Scan failed.', 'is-error' ); } )
						.finally( function () { scan.disabled = false; } );
				} );
			}
			if ( scanAll ) {
				scanAll.addEventListener( 'click', function () {
					if ( bulkRunning ) { return; }
					bulkRunning = true; bulkStop = false;
					var selected = category.value, batches = 0, added = 0;
					category.disabled = true;
					if ( scan ) { scan.disabled = true; }
					scanAll.disabled = true;
					if ( stop ) { stop.hidden = false; }
					function finish( text, isError ) {
						bulkRunning = false;
						category.disabled = false;
						if ( scan ) { scan.disabled = false; }
						scanAll.disabled = false;
						if ( stop ) { stop.hidden = true; }
						setStatus( message, text, isError ? 'is-error' : 'is-ok' );
						load();
					}
					function next() {
						if ( bulkStop || batches >= 40 ) {
							finish( ( bulkStop ? 'Scan stopped.' : 'Scan batch limit reached.' ) + ' Added ' + added + ' components in ' + batches + ' batches.' ); return;
						}
						setStatus( message, 'Scanning ' + selected + ': batch ' + ( batches + 1 ) + ', ' + added + ' components saved…' );
						request( '/design-discovery/scan', { method: 'POST', data: { category: selected } } )
							.then( function ( res ) {
								var d = res.data || {}; batches++;
								added += d.added || 0;
								if ( ! d.added ) { finish( 'Category scan complete. Added ' + added + ' new components in ' + batches + ' batches.' ); }
								else { next(); }
							} )
							.catch( function ( err ) { finish( ( err.message || 'Scan failed.' ) + ' Saved ' + added + ' components before stopping. You can resume.', true ); } );
					}
					next();
				} );
			}
			if ( stop ) { stop.addEventListener( 'click', function () { bulkStop = true; setStatus( message, 'Stopping after the current batch…' ); } ); }
			if ( refresh ) { refresh.addEventListener( 'click', load ); }
			category.addEventListener( 'change', load );
			load();
		}() );

		if ( postId <= 0 ) { return; }

		var visualModeBox = document.getElementById( 'scc-visual-use' );
		( function bindVisualRecreation() {
			var root = document.getElementById( 'scc-visual-recreation' );
			if ( ! root ) { return; }
			var pick = document.getElementById( 'scc-visual-pick' );
			var pickRendered = document.getElementById( 'scc-visual-pick-rendered' );
			var analyze = document.getElementById( 'scc-visual-analyze' );
			var compare = document.getElementById( 'scc-visual-compare' );
			var refImage = document.getElementById( 'scc-visual-reference-image' );
			var source = document.getElementById( 'scc-visual-source' );
			var recipeEl = document.getElementById( 'scc-visual-recipe' );
			var statusEl = document.getElementById( 'scc-visual-message' );
			var compareInfo = document.getElementById( 'scc-visual-compare-info' );
			var refId = 0, renderedId = 0, ready = false;
			function renderRecipe( recipe ) {
				recipeEl.textContent = recipe.name + ' · ' + recipe.hero.replace( /_/g, ' ' ) +
					' hero · ' + recipe.tone + ' tone · ' + recipe.width + 'px layout width · ' +
					( recipe.card_columns || 3 ) + '-column cards. ' + ( recipe.observations || '' );
			}
			// Dedicated uploads are automatically marked disposable server-side.
			// Ordinary Media Library assets are never selected for auto-deletion.
			var fileInput = document.getElementById( 'scc-visual-file' );
			var renderedInput = document.getElementById( 'scc-visual-rendered-file' );
			var finishBtn = document.getElementById( 'scc-visual-finish' );
			function showReference( url ) {
				if ( url ) { refImage.src = url; refImage.hidden = false; }
				else { refImage.removeAttribute( 'src' ); refImage.hidden = true; }
			}
			function uploadFile( file, role ) {
				if ( ! file ) { return; }
				if ( file.size > 3145728 || [ 'image/png', 'image/jpeg', 'image/webp' ].indexOf( file.type ) === -1 ) {
					setStatus( statusEl, 'Choose a PNG, JPEG or WebP screenshot under 3 MB.', 'is-error' );
					return;
				}
				var data = new FormData();
				data.append( 'post_id', postId );
				data.append( 'role', role );
				data.append( 'image', file, file.name );
				if ( role === 'reference' ) { pick.disabled = true; }
				else { pickRendered.disabled = true; }
				setStatus( statusEl, 'Uploading temporary screenshot…' );
				request( '/layout/visual-upload', { method: 'POST', body: data } )
					.then( function ( res ) {
						var item = res.data || {};
						if ( role === 'reference' ) {
							refId = item.id || 0;
							ready = false;
							visualModeBox.checked = false;
							visualModeBox.disabled = true;
							analyze.disabled = ! refId;
							compare.disabled = true;
							showReference( item.url );
							recipeEl.textContent = 'Reference uploaded temporarily. Analyze with LM Studio to recreate the design.';
						} else {
							renderedId = item.id || 0;
							compare.disabled = ! renderedId || ! ready;
							compareInfo.textContent = file.name + ' (temporary)';
						}
						setStatus( statusEl, 'Temporary screenshot saved. It can be deleted with Finish / discard.', 'is-ok' );
					} )
					.catch( function ( err ) { setStatus( statusEl, ( err && err.message ) || 'Screenshot upload failed.', 'is-error' ); } )
					.finally( function () {
						if ( role === 'reference' ) { pick.disabled = false; }
						else { pickRendered.disabled = false; }
					} );
			}
			if ( pick && fileInput ) {
				pick.addEventListener( 'click', function () { fileInput.click(); } );
				fileInput.addEventListener( 'change', function () {
					uploadFile( fileInput.files && fileInput.files[0], 'reference' );
					fileInput.value = '';
				} );
			}
			if ( pickRendered && renderedInput ) {
				pickRendered.addEventListener( 'click', function () { renderedInput.click(); } );
				renderedInput.addEventListener( 'change', function () {
					uploadFile( renderedInput.files && renderedInput.files[0], 'comparison' );
					renderedInput.value = '';
				} );
			}
			if ( finishBtn ) {
				finishBtn.addEventListener( 'click', function () {
					if ( ! window.confirm( 'Delete temporary TideOrbit design screenshots for this page? Normal Media Library photos and Elementor content will be kept. You can retain your design recipe, but need to re-upload a screenshot to analyze or compare it again.' ) ) { return; }
					finishBtn.disabled = true;
					setStatus( statusEl, 'Deleting only temporary design screenshots…' );
					request( '/layout/visual-finish', { method: 'POST', data: { post_id: postId, confirm_cleanup: true } } )
						.then( function ( res ) {
							var result = res.data || {};
							refId = 0; renderedId = 0; ready = false;
							analyze.disabled = true; compare.disabled = true;
							visualModeBox.checked = false; visualModeBox.disabled = true;
							showReference( '' );
							compareInfo.textContent = '';
							recipeEl.textContent = 'Design recipe retained. Upload another screenshot if you want to run a new vision analysis.';
							setStatus( statusEl, 'Deleted ' + ( result.deleted || 0 ) + ' temporary screenshot(s).' +
								( result.retained ? ' ' + result.retained + ' file(s) kept because deletion was unsafe or failed.' : '' ), 'is-ok' );
						} )
						.catch( function ( err ) { setStatus( statusEl, ( err && err.message ) || 'Cleanup failed.', 'is-error' ); } )
						.finally( function () { finishBtn.disabled = false; } );
				} );
			}
			function sendAnalysis( isComparison ) {
				var button = isComparison ? compare : analyze;
				if ( ! refId ) { return; }
				button.disabled = true;
				setStatus( statusEl, isComparison ? 'Comparing the draft to the design reference in LM Studio…' : 'Studying layout and style with LM Studio vision…' );
				request( '/layout/visual-analyze', {
					method: 'POST',
					data: { post_id: postId, reference_id: refId,
						rendered_id: isComparison ? renderedId : 0,
						source_url: source ? source.value.trim() : '' }
				} ).then( function ( res ) {
					var d = res.data || {};
					ready = !! d.recipe;
					visualModeBox.disabled = ! ready;
					visualModeBox.checked = ready;
					renderRecipe( d.recipe );
					compare.disabled = ! renderedId || ! ready;
					setStatus( statusEl, ( isComparison ? 'Refined visual recipe saved. ' : 'Visual recipe saved. ' ) +
						'Click Regenerate design below to compose a native Elementor page.', 'is-ok' );
				} ).catch( function ( err ) {
					setStatus( statusEl, ( err && err.message ) || 'LM Studio visual analysis failed.', 'is-error' );
				} ).finally( function () { button.disabled = false; } );
			}
			analyze.addEventListener( 'click', function () { sendAnalysis( false ); } );
			compare.addEventListener( 'click', function () { if ( renderedId && ready ) { sendAnalysis( true ); } } );
			request( '/layout/visual-status?post_id=' + postId, { method: 'GET' } )
				.then( function ( res ) {
					var d = res.data || {};
					if ( ! d.ready ) { return; }
					refId = d.reference_id || 0; ready = true;
					showReference( d.reference_url );
					analyze.disabled = ! refId;
					visualModeBox.disabled = false;
					// Opt-in per visit: loading an old reference never silently
					// switches a live page into visual regeneration mode.
					visualModeBox.checked = false;
					if ( d.source_url && source ) { source.value = d.source_url; }
					renderRecipe( d.recipe );
					setStatus( statusEl, 'Previous visual recipe loaded. Enable it to use this style.', 'is-ok' );
				} ).catch( function () {} );
		}() );

		var preview = document.getElementById( 'scc-layout-preview' );
		var msg = document.getElementById( 'scc-layout-msg' );
		var metaEl = document.getElementById( 'scc-layout-meta' );
		var criticEl = document.getElementById( 'scc-layout-critic' );
		var applyBtn = document.getElementById( 'scc-layout-apply' );
		var regen = document.getElementById( 'scc-layout-regen' );
		var aiBox = document.getElementById( 'scc-layout-ai' );
		var designPrompt = document.getElementById( 'scc-layout-design-prompt' );
		var confirmLive = document.getElementById( 'scc-layout-confirm-live' );
		var restoreBtn = document.getElementById( 'scc-layout-restore' );
		var cloneBtn = document.getElementById( 'scc-layout-clone-draft' );
		var safetyMsg = document.getElementById( 'scc-layout-safety-msg' );
		if ( ! preview || ! applyBtn ) { return; }

		var blocks = []; // [{id, name}]
		var compositionMode = false;
		var compositionToken = '';

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
				if ( compositionMode ) {
					var badge = el( 'span', 'Native composition', 'scc-badge scc-badge--ok' );
					row.appendChild( badge );
				} else {
					var ctl = el( 'span', null, 'scc-lblock__ctl' );
					var up = el( 'button', '↑', 'button button-small' ); up.type = 'button'; up.title = 'Move up'; up.disabled = ( i === 0 );
					var dn = el( 'button', '↓', 'button button-small' ); dn.type = 'button'; dn.title = 'Move down'; dn.disabled = ( i === blocks.length - 1 );
					var rm = el( 'button', '✕', 'button button-small' ); rm.type = 'button'; rm.title = 'Remove';
					up.addEventListener( 'click', function () { if ( i > 0 ) { var t = blocks[ i - 1 ]; blocks[ i - 1 ] = blocks[ i ]; blocks[ i ] = t; draw(); } } );
					dn.addEventListener( 'click', function () { if ( i < blocks.length - 1 ) { var t = blocks[ i + 1 ]; blocks[ i + 1 ] = blocks[ i ]; blocks[ i ] = t; draw(); } } );
					rm.addEventListener( 'click', function () { blocks.splice( i, 1 ); draw(); } );
					ctl.appendChild( up ); ctl.appendChild( dn ); ctl.appendChild( rm );
					row.appendChild( ctl );
				}
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
			compositionMode = false;
			compositionToken = '';
			updateApplyState();
			var useAgent = !! ( aiBox && aiBox.checked );
			setStatus( msg, useAgent ? 'Designing with your installed Elementor widgets…' : 'Analyzing content and choosing safe components…' );
			request( '/layout/propose', {
				method: 'POST',
				data: {
					post_id: postId,
					use_ai: useAgent,
					visual_mode: !! ( visualModeBox && visualModeBox.checked ),
					design_prompt: designPrompt ? designPrompt.value : ''
				}
			} )
				.then( function ( res ) {
					var d = res.data || {};
					compositionMode = !! d.composition_mode;
					compositionToken = d.composition_token || '';
					blocks = ( d.blocks || [] ).map( function ( b ) { return { id: b.id, name: b.name }; } );
					if ( metaEl ) {
						metaEl.hidden = false;
						var planner = d.visual_recreation ? 'LM Studio Visual Recreation (native Elementor, zero React)' :
							d.source === 'ai_elementor_composition' ? 'schema-aware Elementor design agent' :
							d.source === 'schema_agent_fallback' ? 'smart page architect (AI design fallback)' :
							d.source === 'ai_constrained_architect' ? 'legacy AI component architect' :
							d.source === 'page_architect_ai_fallback' ? 'smart rules (AI fallback)' : 'smart page architect';
						var details = 'Detected: ' + ( d.content_type || '?' ) + ' · ' + ( d.search_intent || '?' ) + ' · planned by ' + planner;
						if ( compositionMode && d.widget_discovery ) {
							details += ' · ' + ( d.widget_discovery.available || 0 ) + ' installed widgets discovered';
							details += ' · ' + ( d.composition_nodes || 0 ) + ' design nodes';
							if ( d.repaired ) { details += ' · validator auto-repaired'; }
						}
						if ( ! d.ai_available ) { details += ' (no AI provider configured)'; }
						metaEl.textContent = details;
					}
					var readyMessage = d.visual_recreation ? 'Visual style recreated with editable native Elementor elements. Review before applying.' :
						compositionMode ? 'Custom Elementor composition ready.' : ( d.agent_error ? 'AI design could not be validated, so TideOrbit used the safe Page Architect fallback.' : 'Layout preview ready.' );
					if ( isLive ) { readyMessage += ' Live Apply remains locked until you confirm the warning.'; }
					setStatus( msg, readyMessage, d.agent_error ? 'is-warning' : 'is-ok' );
					drawCritique( d.critique );
					draw();
				} )
				.catch( function ( err ) {
					compositionMode = false;
					compositionToken = '';
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
			setStatus( applyMsg, isLive ? 'Saving restore point and updating LIVE page…' : ( compositionMode ? 'Compiling and building your custom Elementor design…' : 'Building your Elementor layout…' ) );
			request( '/layout/apply', {
				method: 'POST',
				data: {
					post_id: postId,
					layout: blocks.map( function ( b ) { return b.id; } ),
					composition_token: compositionMode ? compositionToken : '',
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
					if ( d.preview_url || d.view_url ) {
						var pv = el( 'a', 'Preview the design', 'button button-primary' );
						pv.href = d.preview_url || d.view_url;
						pv.target = '_blank'; pv.rel = 'noopener noreferrer';
						ok.appendChild( pv ); ok.appendChild( document.createTextNode( ' ' ) );
					}
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

	document.addEventListener('DOMContentLoaded',function(){bindLayoutEngine();});
} )();
