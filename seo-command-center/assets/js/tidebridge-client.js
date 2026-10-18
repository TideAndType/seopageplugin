/**
 * TideBridge asynchronous AI transport for WordPress admin.
 *
 * Adds one unique ID per user action and transparently retries the same
 * authenticated REST request after our Mac finishes the model operation.
 * Works across Create Content, Site Plan, Optimize, Elementor and Settings.
 * Does not contact a third-party tunnel or expose LM Studio publicly.
 */
( function () {
    'use strict';
    if ( ! window.wp || ! window.wp.apiFetch ) { return; }
    var api = window.wp.apiFetch;
    var count = 0;

    api.use( function ( options, next ) {
        var path = String( options.path || '' );
        if ( path.indexOf( '/seo-command/v1' ) === -1 ||
             path.indexOf( '/seo-command/v1/mac/' ) !== -1 ) {
            return next( options );
        }

        var headers = Object.assign( {}, options.headers || {} );
        if ( ! headers[ 'X-TideBridge-Job' ] ) {
            count++;
            // Request ID is an idempotency key, not a credential. WordPress
            // binds each cached model response to the authenticated user.
            headers[ 'X-TideBridge-Job' ] =
                'tb_' + Date.now().toString( 36 ) + count.toString( 36 ) +
                Math.random().toString( 36 ).slice( 2, 16 );
        }
        var original = Object.assign( {}, options, { headers: headers } );
        var started = Date.now();
        var maxWaitMs = 90 * 60 * 1000;

        function sleep( ms ) {
            return new Promise( function ( resolve ) { window.setTimeout( resolve, ms ); } );
        }

        function poll( jobId ) {
            if ( Date.now() - started > maxWaitMs ) {
                return Promise.reject( new Error( 'TideBridge has not finished this AI task yet. Check the Mac app and LM Studio, then retry from TideOrbit.' ) );
            }
            return sleep( 4000 ).then( function () {
                return next( {
                    path: '/seo-command/v1/mac/async-status',
                    method: 'POST',
                    data: { id: jobId },
                } );
            } ).then( function ( result ) {
                var d = result && result.data ? result.data : {};
                if ( d.state === 'error' ) {
                    throw new Error( 'TideBridge: ' + ( d.error || 'Local model generation failed.' ) );
                }
                return d.ready ? true : poll( jobId );
            } );
        }

        function run() {
            return next( original ).catch( function ( error ) {
                if ( ! error ||
                    ( error.code !== 'scc_mac_pending' && error.code !== 'scc_browser_pending' ) ||
                    ! error.data || ! /^(?:ai|br)_[a-f0-9]{32}$/.test( String( error.data.job_id || '' ) ) ) {
                    throw error;
                }
                return poll( error.data.job_id ).then( run );
            } );
        }
        return run();
    } );
} )();
