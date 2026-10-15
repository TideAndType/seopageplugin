/**
 * TideOrbit section redesign: nested Elementor selection, review and preview.
 * The visual preview injects temporary CSS into Elementor's iframe; only
 * Apply invokes Elementor's native undoable document/elements/settings API.
 */
( function () {
	'use strict';
	var styleKeys = {
		container: [ 'background_background', 'background_color', 'padding', 'padding_tablet', 'padding_mobile', 'flex_direction', 'flex_direction_tablet', 'flex_direction_mobile', 'flex_justify_content', 'flex_align_items' ],
		section: [ 'background_background', 'background_color', 'padding', 'padding_tablet', 'padding_mobile' ],
		column: [ 'background_background', 'background_color', 'padding', 'padding_tablet', 'padding_mobile' ],
		heading: [ 'align', 'align_tablet', 'align_mobile', 'title_color', 'typography_font_size', 'typography_font_size_tablet', 'typography_font_size_mobile' ],
		'text-editor': [ 'align', 'align_tablet', 'align_mobile', 'text_color' ],
		button: [ 'align', 'align_tablet', 'align_mobile', 'background_color', 'button_text_color' ]
	};
	var idPattern = /^[a-zA-Z0-9_-]{5,64}$/;
	var escapeText = function ( str ) {
		return String( str == null ? '' : str ).replace( /<[^>]*>/g, ' ' ).replace( /\s+/g, ' ' ).trim().slice( 0, 260 );
	};
	function elementType( container ) {
		var model = container && container.model;
		return model && typeof model.get === 'function' ? String( model.get( 'widgetType' ) || model.get( 'elType' ) || '' ) : '';
	}
	function currentSettings( container, type ) {
		var raw = container && container.model && container.model.get( 'settings' );
		raw = raw && typeof raw.toJSON === 'function' ? raw.toJSON() : {};
		var settings = {};
		( styleKeys[ type ] || [] ).forEach( function ( key ) {
			if ( Object.prototype.hasOwnProperty.call( raw, key ) ) {
				settings[ key ] = raw[ key ];
			}
		} );
		return { settings: settings, raw: raw };
	}
	function inventoryFor( root ) {
		var found = [], visited = {}, scanned = 0, capped = false;
		function walk( container ) {
			if ( ! container || ! container.model || scanned > 80 ) { capped = true; return; }
			scanned++;
			var id = String( container.model.get( 'id' ) || '' );
			var type = elementType( container );
			if ( ! idPattern.test( id ) || visited[ id ] ) { return; }
			visited[ id ] = true;
			if ( styleKeys[ type ] ) {
				if ( found.length >= 18 ) { capped = true; return; }
				var values = currentSettings( container, type );
				found.push( {
					id: id,
					type: type,
					current: values.settings,
					excerpt: escapeText( values.raw.title || values.raw.editor || values.raw.text || values.raw.description_text || '' )
				} );
			}
			var children = Array.isArray( container.children ) ? container.children : [];
			children.forEach( walk );
		}
		walk( root );
		return { elements: found, capped: capped };
	}
	function iframeDocument() {
		try {
			var iframe = document.getElementById( 'elementor-preview-iframe' );
			return iframe && iframe.contentDocument && iframe.contentDocument.head ? iframe.contentDocument : null;
		} catch ( error ) { return null; }
	}
	function color( value ) { return typeof value === 'string' && /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/i.test( value ) ? value : null; }
	function numberPx( value, min, max ) {
		var n = Number( value );
		return isFinite( n ) && n >= min && n <= max ? n + 'px' : null;
	}
	function padding( value ) {
		if ( ! value || value.unit !== 'px' ) { return null; }
		var pieces = [ 'top', 'right', 'bottom', 'left' ].map( function ( side ) { return numberPx( value[ side ], 0, 160 ); } );
		return pieces.every( Boolean ) ? pieces.join( ' ' ) : null;
	}
	function styleRule( edit, breakpoint ) {
		if ( ! idPattern.test( edit.id ) ) { return ''; }
		var rules = [], selector = '.elementor-element[data-id="' + edit.id + '"]';
		var settings = edit.settings || {};
		var suffix = breakpoint === 'desktop' ? '' : '_' + breakpoint;
		var bg = color( settings.background_color );
		if ( breakpoint === 'desktop' && bg ) { rules.push( 'background-color:' + bg + ' !important' ); }
		var pad = padding( settings[ 'padding' + suffix ] );
		if ( pad ) { rules.push( 'padding:' + pad + ' !important' ); }
		var direction = settings[ 'flex_direction' + suffix ];
		if ( [ 'row', 'column', 'row-reverse', 'column-reverse' ].indexOf( direction ) !== -1 ) {
			rules.push( 'flex-direction:' + direction + ' !important' );
		}
		if ( breakpoint === 'desktop' && [ 'flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly' ].indexOf( settings.flex_justify_content ) !== -1 ) {
			rules.push( 'justify-content:' + settings.flex_justify_content + ' !important' );
		}
		if ( breakpoint === 'desktop' && [ 'stretch', 'flex-start', 'center', 'flex-end' ].indexOf( settings.flex_align_items ) !== -1 ) {
			rules.push( 'align-items:' + settings.flex_align_items + ' !important' );
		}
		var align = settings[ 'align' + suffix ];
		if ( [ 'left', 'center', 'right', 'justify' ].indexOf( align ) !== -1 ) {
			rules.push( 'text-align:' + align + ' !important' );
		}
		if ( breakpoint === 'desktop' ) {
			var fg = color( settings.title_color || settings.text_color || settings.button_text_color );
			if ( fg ) { rules.push( 'color:' + fg + ' !important' ); }
		}
		var font = settings[ 'typography_font_size' + suffix ];
		var fontsize = font && font.unit === 'px' && numberPx( font.size, 10, 100 );
		if ( rules.length ) {
			var target = edit.type === 'heading' || edit.type === 'text-editor' ? selector + ' .elementor-widget-container' : selector;
			if ( edit.type === 'heading' ) { target = selector + ' .elementor-heading-title'; }
			if ( edit.type === 'button' ) { target = selector + ' .elementor-button'; }
			if ( ( edit.type === 'container' || edit.type === 'section' || edit.type === 'column' ) && align ) { target = selector; }
			var main = target + '{' + rules.join( ';' ) + ';}';
			if ( fontsize ) { main += selector + ' .elementor-heading-title{font-size:' + fontsize + ' !important;}'; }
			return main;
		}
		return fontsize ? selector + ' .elementor-heading-title{font-size:' + fontsize + ' !important;}' : '';
	}
	function previewCss( edits ) {
		var desktop = '', tablet = '', mobile = '';
		edits.forEach( function ( edit ) {
			desktop += styleRule( edit, 'desktop' );
			tablet += styleRule( edit, 'tablet' );
			mobile += styleRule( edit, 'mobile' );
		} );
		return desktop + '@media(min-width:768px) and (max-width:1024px){' + tablet + '}' +
			'@media(max-width:767px){' + mobile + '}';
	}
	function append( element, className, text ) {
		var el = document.createElement( element );
		el.className = className;
		el.textContent = text;
		return el;
	}

	window.SCCElementorSection = function ( ctx ) {
		var root = ctx.root;
		var mode = root.querySelector( '#scc-inline-mode' );
		var generate = root.querySelector( '.scc-inline__generate' );
		var apply = root.querySelector( '.scc-inline__apply' );
		var prompt = root.querySelector( '#scc-inline-prompt' );
		var panel = root.querySelector( '.scc-inline__panel' );
		var diff = root.querySelector( '.scc-inline__diff' );
		var summary = root.querySelector( '.scc-inline__summary' );
		var proposalBox = root.querySelector( '.scc-inline__proposal' );
		var styleEl = null;
		var pending = null;
		var prior = null;
		var history = [];
		var requestSeq = 0;
		var busy = false;
		var selectedId = '';
		var previewOn = false;
		var lastPrompt = '';
		var details = append( 'p', 'scc-inline__help scc-inline__section-help', 'Select a parent container. TideOrbit will coordinate styles for its child widgets without changing their copy or links.' );
		details.hidden = true;
		generate.parentNode.insertBefore( details, generate );
		var controls = document.createElement( 'div' );
		controls.className = 'scc-inline__preview-controls';
		controls.hidden = true;
		var previewButton = append( 'button', 'scc-inline__preview-btn', 'Show design preview' );
		previewButton.type = 'button';
		previewButton.setAttribute( 'aria-pressed', 'false' );
		controls.appendChild( previewButton );
		controls.appendChild( append( 'p', 'scc-inline__help', 'Preview is temporary CSS in the Elementor canvas. Use Elementor’s responsive device toolbar to inspect tablet/mobile. No editor settings change until Apply.' ) );
		proposalBox.insertBefore( controls, apply );

		function clearPreview() {
			if ( styleEl && styleEl.parentNode ) { styleEl.parentNode.removeChild( styleEl ); }
			styleEl = null;
			previewOn = false;
			previewButton.textContent = 'Show design preview';
			previewButton.setAttribute( 'aria-pressed', 'false' );
		}
		function reset( changeSelection ) {
			++requestSeq;
			clearPreview();
			pending = null;
			proposalBox.hidden = true;
			if ( changeSelection ) { prior = null; history = []; lastPrompt = ''; }
		}
		function sectionChosen() {
			var chosen = ctx.getChosen();
			return !! chosen && [ 'container', 'section', 'column' ].indexOf( chosen.type ) !== -1;
		}
		function update() {
			var isSection = mode.value === 'section';
			details.hidden = ! isSection;
			controls.hidden = ! isSection || ! pending;
			if ( isSection ) {
				generate.disabled = busy || ! sectionChosen();
				apply.disabled = ! pending || busy;
				if ( ! sectionChosen() ) {
					ctx.setStatus( 'Select a parent container, section or column for a full redesign.', true );
				}
			}
		}
		function validateFresh( before ) {
			if ( ! sectionChosen() || ! before.length || ctx.getChosen().id !== before[0].id ) { return false; }
			for ( var i = 0; i < before.length; i++ ) {
				var container = window.elementor.getContainer( before[i].id );
				if ( ! container || elementType( container ) !== before[i].type ||
					JSON.stringify( currentSettings( container, before[i].type ).settings ) !== JSON.stringify( before[i].current ) ) {
					return false;
				}
			}
			return true;
		}
		function renderProposal( data, before ) {
			diff.textContent = '';
			summary.textContent = data.summary || 'Review your section redesign.';
			data.elements.forEach( function ( edit ) {
				var card = append( 'div', 'scc-inline__change', edit.type + ' · ' + edit.id );
				var previous = before.filter( function ( item ) { return item.id === edit.id; } )[0] || {};
				Object.keys( edit.settings || {} ).forEach( function ( key ) {
					card.appendChild( append( 'div', 'scc-inline__before', key.replace( /_/g, ' ' ) + ': ' + String( JSON.stringify( previous.current && previous.current[ key ] ) || 'default' ).slice( 0, 120 ) ) );
					card.appendChild( append( 'div', 'scc-inline__after', '→ ' + String( JSON.stringify( edit.settings[ key ] ) ).slice( 0, 160 ) ) );
				} );
				diff.appendChild( card );
			} );
			proposalBox.hidden = false;
			controls.hidden = false;
			apply.disabled = false;
		}
		function generateSection() {
			if ( busy || ! sectionChosen() || ! window.elementor || ! window.elementor.getContainer ) { return; }
			var requestText = prompt.value.trim();
			if ( requestText.length < 3 ) {
				ctx.setStatus( 'Describe the redesign you want.', true );
				return;
			}
			var container = window.elementor.getContainer( ctx.getChosen().id );
			var snapshot = inventoryFor( container );
			if ( snapshot.capped || ! snapshot.elements.length ) {
				ctx.setStatus( 'This section has too many nested elements to edit safely (maximum 18 supported items). Select a smaller nested container.', true );
				return;
			}
			reset( false );
			var token = requestSeq;
			var before = snapshot.elements;
			busy = true;
			update();
			ctx.setStatus( 'Designing this section and its responsive styles…' );
			window.wp.apiFetch( {
				url: window.SCCElementorInline.root.replace( /\/?$/, '/' ) + 'seo-command/v1/elementor/inline/section-suggest',
				method: 'POST',
				headers: { 'X-WP-Nonce': window.SCCElementorInline.nonce },
				data: {
					post_id: window.SCCElementorInline.postId,
					root_id: before[0].id,
					elements: before,
					prompt: requestText,
					history: history,
					previous_proposal: prior || []
				}
			} ).then( function ( response ) {
				if ( token !== requestSeq || mode.value !== 'section' ) { return; }
				var data = response.data || {};
				var allowedIds = {};
				before.forEach( function ( item ) { allowedIds[ item.id ] = item.type; } );
				if ( ! Array.isArray( data.elements ) || ! data.elements.length ||
					data.elements.some( function ( edit ) { return ! edit || ! allowedIds[ edit.id ] || edit.type !== allowedIds[ edit.id ]; } ) ) {
					throw new Error( 'The AI response did not match the selected Elementor elements.' );
				}
				if ( ! validateFresh( before ) ) {
					throw new Error( 'This section changed while TideOrbit was designing. Select it and try again.' );
				}
				pending = { before: before, data: data };
				prior = data.elements;
				history.push( { request: requestText.slice( 0, 500 ), summary: String( data.summary || '' ).slice( 0, 240 ) } );
				history = history.slice( -3 );
				lastPrompt = requestText;
				renderProposal( data, before );
				generate.textContent = 'Refine design';
				ctx.setStatus( 'Suggestion ready. Toggle before/after in the canvas or ask for a refinement.' );
			} ).catch( function ( error ) {
				if ( token !== requestSeq ) { return; }
				ctx.setStatus( ( error && error.message ) || 'Could not generate the section redesign.', true );
			} ).finally( function () {
				if ( token === requestSeq ) { busy = false; update(); }
			} );
		}
		function previewToggle() {
			if ( ! pending ) { return; }
			if ( previewOn ) { clearPreview(); return; }
			if ( ! validateFresh( pending.before ) ) {
				reset( false );
				ctx.setStatus( 'The section has changed. Generate another preview.', true );
				return;
			}
			var doc = iframeDocument();
			if ( ! doc ) {
				ctx.setStatus( 'Elementor preview iframe is unavailable. Use the settings comparison, then apply and review with Undo.', true );
				return;
			}
			clearPreview();
			styleEl = doc.createElement( 'style' );
			styleEl.id = 'scc-elementor-section-visual-preview';
			styleEl.textContent = previewCss( pending.data.elements );
			doc.head.appendChild( styleEl );
			previewOn = true;
			previewButton.textContent = 'Show original design';
			previewButton.setAttribute( 'aria-pressed', 'true' );
			ctx.setStatus( 'Showing AFTER in Elementor canvas. This does not alter saved settings.' );
		}
		function applySection() {
			if ( ! pending || busy ) { return; }
			var before = pending.before;
			if ( ! validateFresh( before ) ) {
				reset( false );
				ctx.setStatus( 'The section changed since this proposal. Generate a fresh design.', true );
				return;
			}
			// Ensure all target nodes exist before touching any of them.
			var targets = {};
			pending.data.elements.forEach( function ( edit ) { targets[ edit.id ] = window.elementor.getContainer( edit.id ); } );
			if ( pending.data.elements.some( function ( edit ) { return ! targets[ edit.id ]; } ) ) {
				ctx.setStatus( 'One of the selected elements is missing. No changes were applied.', true );
				return;
			}
			clearPreview();
			var changed = [];
			try {
				pending.data.elements.forEach( function ( edit ) {
					var container = targets[ edit.id ];
					var beforeData = before.filter( function ( item ) { return item.id === edit.id; } )[0];
					window.$e.run( 'document/elements/settings', {
						container: container, settings: edit.settings, options: { external: true, debounce: false }
					} );
					changed.push( { container: container, original: beforeData.current, edited: edit.settings } );
				} );
				reset( false );
				prior = null;
				history = [];
				generate.textContent = 'Suggest changes';
				ctx.setStatus( 'Design applied to Elementor’s unsaved editor. Review desktop/tablet/mobile and click Update / Publish when satisfied. Elementor History can undo the changes.' );
			} catch ( error ) {
				// Best-effort undo using the same documented editor command.
				changed.reverse().forEach( function ( item ) {
					var restore = {};
					Object.keys( item.edited ).forEach( function ( key ) {
						restore[ key ] = Object.prototype.hasOwnProperty.call( item.original, key ) ? item.original[ key ] : '';
					} );
					try { window.$e.run( 'document/elements/settings', { container: item.container, settings: restore, options: { external: true, debounce: false } } ); } catch ( ignored ) {}
				} );
				reset( false );
				ctx.setStatus( 'Elementor rejected part of the design; a rollback was attempted. Review the editor and use Elementor History if anything remains changed: ' + ( error.message || 'unknown error' ), true );
			}
		}

		var option = document.createElement( 'option' );
		option.value = 'section';
		option.textContent = 'Redesign selected section';
		mode.appendChild( option );
		generate.addEventListener( 'click', function ( event ) {
			if ( mode.value !== 'section' ) { return; }
			event.stopImmediatePropagation();
			generateSection();
		}, true );
		apply.addEventListener( 'click', function ( event ) {
			if ( mode.value !== 'section' ) { return; }
			event.stopImmediatePropagation();
			applySection();
		}, true );
		previewButton.addEventListener( 'click', previewToggle );
		window.addEventListener( 'beforeunload', clearPreview );
		return {
			isSupported: sectionChosen,
			isBusy: function () { return busy; },
			onSelection: function () {
				var id = ctx.getChosen() ? ctx.getChosen().id : '';
				if ( id !== selectedId ) { selectedId = id; reset( true ); generate.textContent = 'Suggest changes'; }
				update();
			},
			onModeChange: function () {
				clearPreview();
				if ( mode.value !== 'section' ) { reset( true ); generate.textContent = 'Suggest changes'; }
				update();
			},
			onPromptChange: function () {
				if ( mode.value !== 'section' ) { return; }
				// A proposal must never remain applicable after the instructions
				// change. Invalidate an in-flight request as well; the old result
				// is ignored by requestSeq even if its network call finishes.
				if ( pending || busy ) {
					reset( false );
					busy = false;
					update();
					ctx.setStatus( 'Instructions changed. Generate a fresh proposal before applying.' );
				} else if ( previewOn ) {
					clearPreview();
				}
			},
			onClose: clearPreview
		};
	};
} )();
