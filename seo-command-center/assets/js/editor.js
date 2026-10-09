/** TideOrbit SEO meta-box editor-only script. No full admin dashboard code. */
( function () {
	'use strict';
	if ( ! window.SCC || ! window.wp || ! window.wp.apiFetch ) { return; }
	var api = window.wp.apiFetch;
	var i18n = window.SCC.i18n || {};
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


	document.addEventListener( 'DOMContentLoaded', bindSeoPanel );
} )();
