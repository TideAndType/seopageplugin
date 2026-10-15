/**
 * TideOrbit assistant inside the native Elementor editor.
 *
 * Does not write post meta or replace the page. Elementor's own settings
 * command updates the selected element and keeps normal save/undo behavior.
 */
( function () {
	'use strict';
	var booted = false;
	var chosen = null;
	var proposal = null;
	var sequence = 0;
	var busy = false;
	var sectionBridge = null;
	var app = window.SCCElementorInline;
	if ( ! app || ! window.wp || ! window.wp.apiFetch ) { return; }

	var supported = {
		content: {
			heading: [ 'title' ],
			'text-editor': [ 'editor' ],
			button: [ 'text' ],
			'image-box': [ 'title_text', 'description_text' ],
			'icon-box': [ 'title_text', 'description_text' ],
			testimonial: [ 'testimonial_content' ]
		},
		design: {
			heading: [ 'align', 'title_color', 'typography_font_size' ],
			'text-editor': [ 'align', 'text_color' ],
			button: [ 'align', 'button_text_color', 'background_color' ],
			container: [ 'background_background', 'background_color', 'padding' ],
			section: [ 'background_background', 'background_color', 'padding' ],
			column: [ 'background_background', 'background_color', 'padding' ]
		}
	};

	function node( tag, cls, value ) {
		var n = document.createElement( tag );
		if ( cls ) { n.className = cls; }
		if ( value !== undefined ) { n.textContent = value; }
		return n;
	}
	function getContainer() {
		if ( ! chosen || ! window.elementor || typeof window.elementor.getContainer !== 'function' ) { return null; }
		return window.elementor.getContainer( chosen.id );
	}
	function settingsFor( container ) {
		if ( ! container || ! container.model || typeof container.model.get !== 'function' ) { return null; }
		var settings = container.model.get( 'settings' );
		return settings && typeof settings.toJSON === 'function' ? settings.toJSON() : null;
	}
	function allowedKeys( mode ) {
		return ( supported[ mode ] && chosen && supported[ mode ][ chosen.type ] ) || [];
	}
	function snapshot( mode ) {
		var raw = settingsFor( getContainer() );
		if ( ! raw ) { return null; }
		var filtered = {};
		allowedKeys( mode ).forEach( function ( key ) {
			if ( Object.prototype.hasOwnProperty.call( raw, key ) ) {
				filtered[ key ] = raw[ key ];
			}
		} );
		return filtered;
	}
	function hasDynamicContent( mode ) {
		if ( mode !== 'content' ) { return false; }
		var current = settingsFor( getContainer() );
		if ( ! current || ! current.__dynamic__ ) { return false; }
		var dynamic = current.__dynamic__;
		if ( typeof dynamic === 'string' ) {
			try { dynamic = JSON.parse( dynamic ); } catch ( e ) { return true; }
		}
		return allowedKeys( mode ).some( function ( key ) { return dynamic && !! dynamic[ key ]; } );
	}
	function displayValue( value ) {
		if ( value === undefined ) { return 'Not set'; }
		if ( typeof value === 'object' ) { return JSON.stringify( value ); }
		return String( value );
	}
	function init() {
		if ( booted || ! window.elementor || ! window.elementor.hooks ||
			typeof window.elementor.hooks.addAction !== 'function' ||
			! window.$e || typeof window.$e.run !== 'function' ) {
			return;
		}
		booted = true;
		var root = node( 'div', 'scc-inline' );
		root.innerHTML =
			'<button class="scc-inline__toggle" type="button" aria-controls="scc-inline-panel" aria-expanded="false">✦ TideOrbit AI</button>' +
			'<aside id="scc-inline-panel" class="scc-inline__panel" aria-label="TideOrbit Elementor assistant" hidden>' +
			'<header class="scc-inline__header"><div><strong>✦ TideOrbit</strong><span>Elementor AI Assistant</span></div><button class="scc-inline__close" type="button" aria-label="Close assistant">×</button></header>' +
			'<div class="scc-inline__body">' +
			'<p class="scc-inline__help">Select a widget or container in Elementor, then describe the change.</p>' +
			'<div class="scc-inline__selected" aria-live="polite">No element selected</div>' +
			'<label class="scc-inline__label" for="scc-inline-mode">What to edit</label>' +
			'<select id="scc-inline-mode"><option value="content">Content / copy</option><option value="design">Design / style</option></select>' +
			'<label class="scc-inline__label" for="scc-inline-prompt">Your instructions</label>' +
			'<textarea id="scc-inline-prompt" rows="5" maxlength="1600" placeholder="Make this headline more compelling without changing the facts."></textarea>' +
			'<button class="scc-inline__generate" type="button">Suggest changes</button>' +
			'<p class="scc-inline__status" role="status" aria-live="polite"></p>' +
			'<section class="scc-inline__proposal" hidden><h3>Review suggested edits</h3><p class="scc-inline__summary"></p><div class="scc-inline__diff"></div>' +
			'<button class="scc-inline__apply" type="button">Apply to selected element</button>' +
			'<p class="scc-inline__help">Uses Elementor history. Save with Elementor Update / Publish when ready.</p></section>' +
			'</div></aside>';
		document.body.appendChild( root );

		var toggle = root.querySelector( '.scc-inline__toggle' );
		var panel = root.querySelector( '.scc-inline__panel' );
		var close = root.querySelector( '.scc-inline__close' );
		var selected = root.querySelector( '.scc-inline__selected' );
		var mode = root.querySelector( '#scc-inline-mode' );
		var prompt = root.querySelector( '#scc-inline-prompt' );
		var generate = root.querySelector( '.scc-inline__generate' );
		var status = root.querySelector( '.scc-inline__status' );
		var proposalBox = root.querySelector( '.scc-inline__proposal' );
		var summary = root.querySelector( '.scc-inline__summary' );
		var diff = root.querySelector( '.scc-inline__diff' );
		var apply = root.querySelector( '.scc-inline__apply' );

		function showStatus( message, error ) {
			status.textContent = message;
			status.classList.toggle( 'scc-inline__status--error', !! error );
		}
		function invalidate() {
			sequence++;
			proposal = null;
			proposalBox.hidden = true;
			apply.disabled = true;
		}
		function refresh() {
			var keys = mode.value === 'section' && sectionBridge ? ( sectionBridge.isSupported() ? [ 'section' ] : [] ) : allowedKeys( mode.value );
			selected.textContent = chosen ? 'Selected: ' + chosen.type + ' · ' + chosen.id : 'No element selected';
			generate.disabled = busy || ( sectionBridge && sectionBridge.isBusy() ) || ! keys.length;
			if ( chosen && ! keys.length ) {
				showStatus( 'This element does not support ' + mode.value + ' editing yet. Try another element or mode.', true );
			} else if ( ! chosen ) {
				showStatus( 'Click a heading, text widget, button or container in Elementor.' );
			} else if ( ! busy ) {
				showStatus( '' );
			}
		}
		function onSelection( editorPanel, model ) {
			if ( ! model || typeof model.get !== 'function' ) { return; }
			var id = String( model.get( 'id' ) || '' );
			var type = String( model.get( 'widgetType' ) || model.get( 'elType' ) || '' );
			if ( ! /^[a-zA-Z0-9_-]{5,64}$/.test( id ) ) { return; }
			if ( ! chosen || id !== chosen.id || type !== chosen.type ) {
				chosen = { id: id, type: type };
				invalidate();
				refresh();
				if ( sectionBridge ) { sectionBridge.onSelection(); }
			}
		}
		[ 'widget', 'container', 'section', 'column' ].forEach( function ( kind ) {
			window.elementor.hooks.addAction( 'panel/open_editor/' + kind, onSelection );
		} );
		toggle.addEventListener( 'click', function () {
			panel.hidden = ! panel.hidden;
			toggle.setAttribute( 'aria-expanded', String( ! panel.hidden ) );
			if ( ! panel.hidden ) { refresh(); } else if ( sectionBridge ) { sectionBridge.onClose(); }
		} );
		close.addEventListener( 'click', function () {
			panel.hidden = true;
			toggle.setAttribute( 'aria-expanded', 'false' );
			toggle.focus();
			if ( sectionBridge ) { sectionBridge.onClose(); }
		} );
		mode.addEventListener( 'change', function () {
			invalidate();
			prompt.placeholder = mode.value === 'section' ? 'Redesign this entire section with better visual hierarchy and intentional mobile spacing.' : ( mode.value === 'design' ? 'Use a clean modern look with centered alignment and a softer background.' : 'Improve this copy while keeping all claims accurate.' );
			refresh();
			if ( sectionBridge ) { sectionBridge.onModeChange(); }
		} );
		prompt.addEventListener( 'input', function () { invalidate(); if ( sectionBridge ) { sectionBridge.onPromptChange(); } } );
		generate.addEventListener( 'click', function () {
			if ( mode.value === 'section' || busy || ! chosen || ! allowedKeys( mode.value ).length ) { return; }
			if ( prompt.value.trim().length < 3 ) {
				showStatus( 'Enter a specific change you want TideOrbit to make.', true );
				return;
			}
			if ( hasDynamicContent( mode.value ) ) {
				showStatus( 'This widget uses dynamic content. Edit its source instead so the connection is preserved.', true );
				return;
			}
			var before = snapshot( mode.value );
			if ( ! before ) {
				showStatus( 'Select an Elementor element before generating edits.', true );
				return;
			}
			var target = { id: chosen.id, type: chosen.type, mode: mode.value, before: before };
			invalidate();
			var requestId = sequence;
			busy = true;
			refresh();
			showStatus( 'Generating a safe edit suggestion…' );
			window.wp.apiFetch( {
				url: app.root.replace( /\/?$/, '/' ) + 'seo-command/v1/elementor/inline/suggest',
				method: 'POST',
				headers: { 'X-WP-Nonce': app.nonce },
				data: {
					post_id: app.postId,
					element_id: target.id,
					element_type: target.type,
					mode: target.mode,
					prompt: prompt.value.trim(),
					current: before
				}
			} ).then( function ( res ) {
				if ( requestId !== sequence ) { return; }
				var data = res.data || {};
				if ( ! data.settings || ! Object.keys( data.settings ).length ) {
					throw new Error( 'No safe changes were returned.' );
				}
				proposal = { target: target, data: data };
				diff.textContent = '';
				summary.textContent = data.summary || 'Review the suggested settings.';
				Object.keys( data.settings ).forEach( function ( key ) {
					var block = node( 'div', 'scc-inline__change' );
					block.appendChild( node( 'strong', '', key.replace( /_/g, ' ' ) ) );
					block.appendChild( node( 'div', 'scc-inline__before', 'Before: ' + displayValue( before[ key ] ).slice( 0, 280 ) ) );
					block.appendChild( node( 'div', 'scc-inline__after', 'After: ' + displayValue( data.settings[ key ] ).slice( 0, 400 ) ) );
					diff.appendChild( block );
				} );
				proposalBox.hidden = false;
				apply.disabled = false;
				showStatus( 'Ready to apply in Elementor. No changes have been made yet.' );
			} ).catch( function ( err ) {
				if ( requestId !== sequence ) { return; }
				showStatus( ( err && err.message ) || 'The suggestion could not be generated.', true );
			} ).finally( function () {
				busy = false;
				refresh();
			} );
		} );
		apply.addEventListener( 'click', function () {
			if ( mode.value === 'section' ) { return; }
			if ( ! proposal || ! chosen || proposal.target.id !== chosen.id || proposal.target.type !== chosen.type ||
				proposal.target.mode !== mode.value ) {
				invalidate();
				showStatus( 'Selection changed. Generate a new suggestion.', true );
				return;
			}
			var current = snapshot( mode.value );
			if ( ! current || JSON.stringify( current ) !== JSON.stringify( proposal.target.before ) ) {
				invalidate();
				showStatus( 'Elementor settings changed since this suggestion. Generate a fresh suggestion.', true );
				return;
			}
			if ( hasDynamicContent( mode.value ) ) {
				invalidate();
				showStatus( 'Dynamic content is connected to this widget. Edit its source instead.', true );
				return;
			}
			var container = getContainer();
			if ( ! container ) {
				showStatus( 'The selected element is no longer available.', true );
				return;
			}
			try {
				window.$e.run( 'document/elements/settings', {
					container: container,
					settings: proposal.data.settings,
					options: { external: true }
				} );
				invalidate();
				showStatus( 'Applied in Elementor. Review the page and click Update / Publish to save. Use Elementor Undo to revert.' );
			} catch ( err ) {
				showStatus( 'Elementor could not apply those settings: ' + ( err.message || 'unknown error' ), true );
			}
		} );
		if ( window.SCCElementorSection ) {
			sectionBridge = window.SCCElementorSection( {
				root: root,
				getChosen: function () { return chosen; },
				setStatus: showStatus
			} );
		}
		refresh();
	}

	if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor:init', init );
	}
	window.addEventListener( 'elementor:init', init );
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
