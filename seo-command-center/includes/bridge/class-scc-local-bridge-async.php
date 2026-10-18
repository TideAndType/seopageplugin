<?php
/**
 * Generic first-party TideBridge AI queue.
 *
 * The Mac makes short outbound WordPress requests and talks to LM Studio only
 * on loopback. Other TideOrbit REST features can complete a local-model request
 * without storing a public LM Studio URL or keeping a PHP connection open.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Local_Bridge_Async {
    const INDEX = 'scc_mac_async_ids';
    const PREFIX = 'scc_mac_async_';
    const LIMIT = 80;
    const MAX_RESULT = 2097152;

    private static function key( $id ) {
        return self::PREFIX . sanitize_key( (string) $id );
    }

    private static function get( $id ) {
        $v = get_option( self::key( $id ), array() );
        return is_array( $v ) ? $v : array();
    }

    private static function set( $id, array $data ) {
        update_option( self::key( $id ), $data, false );
    }

    private static function ids() {
        return array_values( array_filter( (array) get_option( self::INDEX, array() ),
            function( $id ) { return is_string( $id ) && 1 === preg_match( '/^ai_[a-f0-9]{32}$/', $id ); } ) );
    }

    public static function configured() {
        return (bool) get_option( SCC_Local_Bridge::TOKEN_KEY, '' );
    }

    private static function error_response( $code, $message, $status = 503, $job_id = '' ) {
        $r = new SCC_AI_Response();
        $data = array( 'status' => $status );
        if ( $job_id ) { $data['job_id'] = $job_id; }
        $r->provider = 'lmstudio';
        $r->error = new WP_Error( $code, $message, $data );
        return $r;
    }

    /**
     * Called by SCC_AI_Manager when LM Studio is the user's selected provider.
     * The nonce-authenticated browser retries its original request after the
     * Mac has finished, using the same per-interaction X-TideBridge-Job header.
     */
    public static function complete( array $request, $operation, $model ) {
        if ( ! self::configured() ) { return null; }
        // Do not allow a public non-browser request to silently fall back to an
        // old Cloudflare URL. Timed cron and other non-interactive tasks require
        // their own async workflow and report this explicitly.
        $interaction = sanitize_key( (string) ( $_SERVER['HTTP_X_TIDEBRIDGE_JOB'] ?? '' ) );
        if ( ! preg_match( '/^tb_[a-z0-9]{12,64}$/', $interaction ) || ! get_current_user_id() ) {
            return self::error_response( 'scc_mac_needs_client',
                __( 'LM Studio is connected through TideBridge. Start this AI task from TideOrbit in your WordPress admin so its progress can be tracked; direct server-to-laptop AI requests are disabled.', 'seo-command-center' ), 409 );
        }

        $encoded = wp_json_encode( array( $request, $operation, $model ) );
        $id = 'ai_' . substr( hash_hmac( 'sha256', (string) get_current_user_id() . ':' . $interaction . ':' . $encoded, wp_salt( 'auth' ) ), 0, 32 );
        $job = self::get( $id );
        $now = time();
        if ( $job ) {
            if ( (int) ( $job['created'] ?? 0 ) < $now - 7200 ) {
                delete_option( self::key( $id ) );
                $job = array();
            } elseif ( (int) ( $job['owner'] ?? 0 ) !== get_current_user_id() ) {
                return self::error_response( 'scc_mac_forbidden', __( 'This TideBridge request belongs to another user.', 'seo-command-center' ), 403 );
            }
        }

        if ( $job ) {
            if ( 'ready' === ( $job['state'] ?? '' ) ) {
                $r = new SCC_AI_Response();
                $r->provider = 'lmstudio';
                $r->model = (string) ( $job['model'] ?? $model );
                $r->content = (string) ( $job['content'] ?? '' );
                return $r;
            }
            if ( 'error' === ( $job['state'] ?? '' ) ) {
                return self::error_response( 'scc_mac_failed', (string) ( $job['error'] ?? 'TideBridge generation failed.' ), 502 );
            }
            if ( 'working' === ( $job['state'] ?? '' ) && $now > (int) ( $job['lease_until'] ?? 0 ) ) {
                $job['state'] = 'queued';
                self::set( $id, $job );
            }
        } else {
            $queue = self::ids();
            $recent = array();
            foreach ( $queue as $old ) {
                $item = self::get( $old );
                if ( ! $item || (int) ( $item['created'] ?? 0 ) < $now - 7200 ) {
                    delete_option( self::key( $old ) );
                    continue;
                }
                $recent[] = $old;
            }
            if ( count( $recent ) >= self::LIMIT ) {
                return self::error_response( 'scc_mac_busy', __( 'TideBridge has too many queued jobs. Finish or retry those before starting another.', 'seo-command-center' ), 429 );
            }
            $request['model'] = $model;
            $request['max_tokens'] = -1;
            $job = array(
                'id' => $id, 'owner' => get_current_user_id(),
                'created' => $now, 'state' => 'queued', 'lease' => '', 'lease_until' => 0,
                'model' => $model, 'request' => $request, 'content' => '', 'error' => '',
            );
            self::set( $id, $job );
            $recent[] = $id;
            update_option( self::INDEX, $recent, false );
        }
        return self::error_response( 'scc_mac_pending',
            __( 'TideBridge is generating this with LM Studio on your Mac. Keep TideBridge and LM Studio running.', 'seo-command-center' ), 503, $id );
    }

    /** Called by the authenticated Mac job-poll endpoint. */
    public static function next() {
        foreach ( self::ids() as $id ) {
            $job = self::get( $id );
            if ( ! $job ) { continue; }
            if ( 'working' === ( $job['state'] ?? '' ) && time() > (int) ( $job['lease_until'] ?? 0 ) ) {
                $job['state'] = 'queued';
            }
            if ( 'queued' !== ( $job['state'] ?? '' ) ) { continue; }
            $lease = bin2hex( random_bytes( 16 ) );
            $job['state'] = 'working';
            $job['lease'] = $lease;
            $job['lease_until'] = time() + 7200;
            self::set( $id, $job );
            return array( 'id' => $id, 'lease' => $lease, 'kind' => 'ai', 'request' => $job['request'] );
        }
        return null;
    }

    /** Called after validating the per-site Mac token. */
    public static function complete_job( WP_REST_Request $request ) {
        $id = sanitize_key( (string) $request->get_param( 'id' ) );
        if ( 0 === strpos( $id, 'br_' ) ) {
            return SCC_Local_Bridge_Browser::status( $request );
        }
        if ( ! preg_match( '/^ai_[a-f0-9]{32}$/', $id ) ) {
            return new WP_Error( 'scc_mac_job_id', 'Invalid AI job identifier.', array( 'status' => 400 ) );
        }
        $job = self::get( $id );
        $lease = (string) $request->get_param( 'lease' );
        if ( ! $job || ! $lease || ! hash_equals( (string) ( $job['lease'] ?? '' ), $lease ) ) {
            return new WP_Error( 'scc_mac_lease', 'Invalid TideBridge job claim.', array( 'status' => 409 ) );
        }
        if ( 'ready' === ( $job['state'] ?? '' ) || 'error' === ( $job['state'] ?? '' ) ) {
            return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'state' => $job['state'] ) ) );
        }
        if ( 'working' !== ( $job['state'] ?? '' ) ) {
            return new WP_Error( 'scc_mac_state', 'Job is not being processed.', array( 'status' => 409 ) );
        }
        $error = sanitize_text_field( (string) $request->get_param( 'error' ) );
        $content = (string) $request->get_param( 'content' );
        if ( strlen( $content ) > self::MAX_RESULT ) {
            return new WP_Error( 'scc_mac_size', 'LM Studio output is larger than 2 MB.', array( 'status' => 413 ) );
        }
        if ( $error ) {
            $job['error'] = substr( $error, 0, 500 );
            $job['state'] = 'error';
        } elseif ( '' !== trim( $content ) ) {
            $job['content'] = $content;
            $job['state'] = 'ready';
            unset( $job['request'] ); // Once complete, no need to keep large prompts.
        } else {
            $job['error'] = 'LM Studio returned an empty response.';
            $job['state'] = 'error';
        }
        self::set( $id, $job );
        return new WP_REST_Response( array( 'ok' => true, 'data' => array( 'state' => $job['state'] ) ) );
    }

    /** Called from wp-admin with a valid REST nonce, never using the Mac token. */
    public static function status( WP_REST_Request $request ) {
        $id = sanitize_key( (string) $request->get_param( 'id' ) );
        if ( ! preg_match( '/^ai_[a-f0-9]{32}$/', $id ) ) {
            return new WP_Error( 'scc_mac_job_id', 'Invalid AI job identifier.', array( 'status' => 400 ) );
        }
        $job = self::get( $id );
        if ( ! $job || (int) ( $job['owner'] ?? 0 ) !== get_current_user_id() ) {
            return new WP_Error( 'scc_mac_job_missing', 'This TideBridge job was not found.', array( 'status' => 404 ) );
        }
        return new WP_REST_Response( array( 'ok' => true, 'data' => array(
            'state' => (string) ( $job['state'] ?? 'queued' ),
            'ready' => 'ready' === ( $job['state'] ?? '' ),
            'error' => (string) ( $job['error'] ?? '' ),
        ) ) );
    }
}
