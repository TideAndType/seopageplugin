<?php
/**
 * Google Maps Rank Tracker requests via TideBridge instead of a public tunnel.
 *
 * The already-paired Mac calls the scanner only at 127.0.0.1:4317 and returns
 * JSON. WordPress never has the scanner's publicly accessible URL.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Local_Bridge_Browser {
    const PREFIX = 'scc_mac_browser_';
    const INDEX = 'scc_mac_browser_ids';

    private static function key( $id ) { return self::PREFIX . sanitize_key( (string) $id ); }
    private static function get( $id ) {
        $j = get_option( self::key( $id ), array() );
        return is_array( $j ) ? $j : array();
    }
    private static function set( $id, array $j ) { update_option( self::key( $id ), $j, false ); }
    private static function ids() { return (array) get_option( self::INDEX, array() ); }

    public static function request( $method, $path, array $body, $scanner_key ) {
        if ( ! SCC_Local_Bridge_Async::configured() ) {
            return new WP_Error( 'scc_browser_not_paired', 'Connect TideBridge on your Mac to use the local Rank Tracker.' );
        }
        if ( ! preg_match( '#^/api/tideorbit/(?:health|geocode\?address=[^ ]{1,2048}|scans(?:/[a-zA-Z0-9_-]{1,128})?)$#', $path ) ) {
            return new WP_Error( 'scc_browser_path', 'Unsupported local scanner endpoint.', array( 'status' => 400 ) );
        }
        $method = strtoupper( (string) $method );
        if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
            return new WP_Error( 'scc_browser_method', 'Unsupported scanner method.', array( 'status' => 400 ) );
        }
        $now = time();
        // Reuse a recent response when a browser/cron callback is retried,
        // while ensuring subsequent scan-status polls fetch fresh data.
        $interval = 'GET' === $method ? 60 : 300;
        $bucket = (int) floor( $now / $interval );
        $id = 'br_' . substr( hash_hmac( 'sha256',
            wp_json_encode( array( $method, $path, $body, $bucket ) ), wp_salt( 'auth' ) ), 0, 32 );
        $j = self::get( $id );
        if ( $j ) {
            if ( 'ready' === ( $j['state'] ?? '' ) ) {
                $response = json_decode( (string) ( $j['content'] ?? '' ), true );
                if ( ! is_array( $response ) ) {
                    return new WP_Error( 'scc_browser_json', 'Local scanner returned invalid JSON.' );
                }
                return $response;
            }
            if ( 'error' === ( $j['state'] ?? '' ) ) {
                return new WP_Error( 'scc_browser_mac_error', (string) ( $j['error'] ?? 'Scanner unavailable.' ) );
            }
            if ( 'working' === ( $j['state'] ?? '' ) && $now > (int) ( $j['lease_until'] ?? 0 ) ) {
                $j['state'] = 'queued'; self::set( $id, $j );
            }
        } else {
            $queue = array();
            foreach ( self::ids() as $prior ) {
                if ( ! preg_match( '/^br_[a-f0-9]{32}$/', (string) $prior ) ) { continue; }
                $old = self::get( $prior );
                if ( ! $old || $now - (int) ( $old['created'] ?? 0 ) > 900 ) {
                    delete_option( self::key( $prior ) );
                } else {
                    $queue[] = $prior;
                }
            }
            if ( count( $queue ) >= 50 ) {
                return new WP_Error( 'scc_browser_busy', 'Too many pending local scanner requests.' );
            }
            $j = array(
                'state' => 'queued', 'created' => $now, 'lease' => '',
                'lease_until' => 0, 'owner' => get_current_user_id(),
                'request' => array(
                    'method' => $method, 'path' => $path, 'body' => $body,
                    'key' => (string) $scanner_key,
                ),
                'content' => '', 'error' => '',
            );
            self::set( $id, $j );
            $queue[] = $id;
            update_option( self::INDEX, $queue, false );
        }
        return new WP_Error( 'scc_browser_pending', 'TideBridge is checking your local Google Maps Rank Tracker.',
            array( 'status' => 503, 'job_id' => $id ) );
    }

    public static function next() {
        foreach ( self::ids() as $id ) {
            if ( ! preg_match( '/^br_[a-f0-9]{32}$/', (string) $id ) ) { continue; }
            $j = self::get( $id );
            if ( 'working' === ( $j['state'] ?? '' ) && time() > (int) ( $j['lease_until'] ?? 0 ) ) {
                $j['state'] = 'queued';
            }
            if ( 'queued' !== ( $j['state'] ?? '' ) ) { continue; }
            $j['state'] = 'working';
            $j['lease'] = bin2hex( random_bytes( 16 ) );
            $j['lease_until'] = time() + 180;
            self::set( $id, $j );
            return array( 'id' => $id, 'lease' => $j['lease'],
                'kind' => 'browser', 'request' => $j['request'] );
        }
        return null;
    }

    public static function complete_job( WP_REST_Request $req ) {
        $id = sanitize_key( (string) $req->get_param( 'id' ) );
        $j = self::get( $id );
        $lease = (string) $req->get_param( 'lease' );
        if ( ! $j || ! $lease || ! hash_equals( (string) ( $j['lease'] ?? '' ), $lease ) ) {
            return new WP_Error( 'scc_browser_lease', 'Invalid local scanner claim.', array( 'status' => 409 ) );
        }
        if ( 'ready' === ( $j['state'] ?? '' ) || 'error' === ( $j['state'] ?? '' ) ) {
            return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'state' => $j['state'] ) ) );
        }
        if ( 'working' !== ( $j['state'] ?? '' ) ) {
            return new WP_Error( 'scc_browser_state', 'Scanner request not running.', array( 'status' => 409 ) );
        }
        $raw = (string) $req->get_param( 'content' );
        $err = sanitize_text_field( (string) $req->get_param( 'error' ) );
        if ( strlen( $raw ) > 1048576 ) {
            return new WP_Error( 'scc_browser_size', 'Local scanner response is too large.', array( 'status' => 413 ) );
        }
        if ( $err ) {
            $j['error'] = substr( $err, 0, 400 );
            $j['state'] = 'error';
        } elseif ( is_array( json_decode( $raw, true ) ) ) {
            $j['content'] = $raw;
            $j['state'] = 'ready';
        } else {
            $j['error'] = 'Local scanner did not return JSON.';
            $j['state'] = 'error';
        }
        unset( $j['request'] );
        self::set( $id, $j );
        return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'state' => $j['state'] ) ) );
    }

    public static function status( WP_REST_Request $request ) {
        $id = sanitize_key( (string) $request->get_param( 'id' ) );
        $j = self::get( $id );
        if ( ! $j || ( (int) ( $j['owner'] ?? 0 ) > 0 &&
            (int) $j['owner'] !== get_current_user_id() ) ) {
            return new WP_Error( 'scc_browser_job_missing', 'Scanner request missing.', array( 'status' => 404 ) );
        }
        return new WP_REST_Response( array( 'ok' => true, 'data' => array(
            'state' => $j['state'], 'ready' => 'ready' === $j['state'], 'error' => $j['error'],
        ) ) );
    }
}
