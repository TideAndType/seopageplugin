<?php
/**
 * TideBridge: our own Mac-to-WordPress pull bridge.
 *
 * WordPress never connects to a user's laptop. The Mac app authenticates
 * outbound to this existing site, claims an AI job, runs LM Studio on loopback,
 * and returns the completed result. No tunnel, VPS, inbound ports or proxy.
 *
 * Content Plan Generate is intercepted only while a Mac is paired. All other
 * TideOrbit providers, endpoints and existing installations work as before.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class SCC_Local_Bridge {
    const NS = 'seo-command/v1';
    const PAIR_KEY = 'scc_mac_pair';
    const TOKEN_KEY = 'scc_mac_token_hash';
    const JOB_INDEX = 'scc_mac_job_ids';
    const MAX_BODY = 2097152;
    const MAX_JOBS = 30;

    public static function register() {
        add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
        add_filter( 'rest_pre_dispatch', array( __CLASS__, 'intercept_generate' ), 30, 3 );
        add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ), 99 );
    }

    public static function admin_menu() {
        add_submenu_page( null, __( 'Connect your Mac', 'seo-command-center' ), __( 'Connect your Mac', 'seo-command-center' ),
            'manage_options', 'seo-command-center-mac', array( __CLASS__, 'admin_page' ) );
    }

    public static function admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Not allowed.' ); }
        $code = '';
        if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
            check_admin_referer( 'scc_mac_connect' );
            $action = isset( $_POST['scc_mac_action'] ) ? sanitize_key( wp_unslash( $_POST['scc_mac_action'] ) ) : '';
            if ( 'new_code' === $action ) {
                $code = (string) random_int( 100000, 999999 );
                update_option( self::PAIR_KEY, array( 'hash' => self::secret_hash( $code ), 'expires' => time() + 600 ), false );
            } elseif ( 'disconnect' === $action ) {
                delete_option( self::TOKEN_KEY );
                delete_option( self::PAIR_KEY );
            }
        }
        $paired = (bool) get_option( self::TOKEN_KEY, '' );
        $last = (int) get_option( 'scc_mac_last_seen', 0 );
        echo '<div class="wrap"><h1>Connect your Mac to TideOrbit</h1>';
        echo '<p>No tunnel, no VPS, no open ports. Your Mac checks this WordPress site for AI work, runs LM Studio locally, and sends drafts back securely.</p>';
        echo '<p><strong>Connection: ' . esc_html( $paired ? 'Paired' : 'Not connected' ) . '</strong>';
        if ( $last ) { echo ' &middot; Last Mac check: ' . esc_html( human_time_diff( $last, time() ) ) . ' ago'; }
        echo '</p><p>1. Open the <strong>TideBridge</strong> app on your Mac.</p>';
        echo '<p>2. Enter this WordPress address: <code>' . esc_html( home_url( '/' ) ) . '</code></p>';
        echo '<p>3. Click below to get a one-time six-digit pairing code. Enter it in the Mac app.</p>';
        echo '<form method="post">';
        wp_nonce_field( 'scc_mac_connect' );
        echo '<input type="hidden" name="scc_mac_action" value="new_code">';
        submit_button( $paired ? 'Pair a new Mac (replaces old connection)' : 'Generate connection code', 'primary', 'submit', false );
        echo '</form>';
        if ( $code ) {
            echo '<div class="notice notice-info inline"><p>Pairing code (expires in 10 minutes): <strong style="font-size:28px;letter-spacing:4px;">' . esc_html( $code ) . '</strong></p></div>';
        }
        if ( $paired ) {
            echo '<form method="post" style="margin-top:25px;">';
            wp_nonce_field( 'scc_mac_connect' );
            echo '<input type="hidden" name="scc_mac_action" value="disconnect">';
            submit_button( 'Disconnect Mac / revoke access', 'secondary', 'submit', false );
            echo '</form>';
        }
        echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=seo-command-center-settings' ) ) . '">Back to TideOrbit Settings</a></p></div>';
    }

    private static function secret_hash( $secret ) {
        return hash_hmac( 'sha256', (string) $secret, wp_salt( 'auth' ) );
    }

    private static function response( $data, $status = 200 ) {
        return new WP_REST_Response( array( 'ok' => true, 'data' => $data ), $status );
    }

    private static function denied( $message = 'Unauthorized.' ) {
        return new WP_Error( 'scc_mac_unauthorized', $message, array( 'status' => 401 ) );
    }

    private static function authorized( WP_REST_Request $request ) {
        $expected = (string) get_option( self::TOKEN_KEY, '' );
        $token = (string) $request->get_header( 'x-tidebridge-key' );
        return $expected && strlen( $token ) >= 48 && hash_equals( $expected, self::secret_hash( $token ) );
    }

    public static function routes() {
        register_rest_route( self::NS, '/mac/pair', array(
            'methods' => 'POST', 'callback' => array( __CLASS__, 'pair' ), 'permission_callback' => '__return_true',
        ) );
        foreach ( array( 'next', 'complete', 'finalize', 'status' ) as $name ) {
            register_rest_route( self::NS, '/mac/' . $name, array(
                'methods' => 'POST', 'callback' => array( __CLASS__, $name ), 'permission_callback' => array( __CLASS__, 'mac_permission' ),
            ) );
        }
    }

    public static function mac_permission( WP_REST_Request $request ) {
        return self::authorized( $request ) ? true : self::denied();
    }

    public static function pair( WP_REST_Request $request ) {
        if ( ! is_ssl() && 'https' !== wp_parse_url( home_url(), PHP_URL_SCHEME ) ) {
            return new WP_Error( 'scc_mac_https', 'HTTPS is required to connect the Mac.', array( 'status' => 403 ) );
        }
        $ip = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' );
        $limit_key = 'scc_mac_pair_limit_' . md5( $ip );
        $attempts = (int) get_transient( $limit_key );
        if ( $attempts >= 5 ) {
            return new WP_Error( 'scc_mac_rate_limit', 'Too many pairing attempts. Try again later.', array( 'status' => 429 ) );
        }
        set_transient( $limit_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
        $code = (string) $request->get_param( 'code' );
        $pair = get_option( self::PAIR_KEY, array() );
        if ( ! preg_match( '/^\d{6}$/', $code ) || ! is_array( $pair ) || time() > (int) ( $pair['expires'] ?? 0 ) ||
            ! hash_equals( (string) ( $pair['hash'] ?? '' ), self::secret_hash( $code ) ) ) {
            return self::denied( 'Incorrect or expired pairing code.' );
        }
        $token = bin2hex( random_bytes( 32 ) );
        update_option( self::TOKEN_KEY, self::secret_hash( $token ), false );
        update_option( 'scc_mac_last_seen', time(), false );
        delete_option( self::PAIR_KEY );
        return self::response( array( 'token' => $token, 'name' => get_bloginfo( 'name' ) ) );
    }

    private static function job_key( $id ) {
        return 'scc_mac_job_' . (int) $id;
    }

    private static function job( $id ) {
        $job = get_option( self::job_key( $id ), array() );
        return is_array( $job ) ? $job : array();
    }

    private static function save_job( $id, array $job ) {
        update_option( self::job_key( $id ), $job, false );
    }

    private static function job_ids() {
        $ids = (array) get_option( self::JOB_INDEX, array() );
        return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
    }

    private static function add_job_id( $id ) {
        $ids = self::job_ids();
        array_unshift( $ids, (int) $id );
        $ids = array_values( array_unique( $ids ) );
        $cut = array_slice( $ids, self::MAX_JOBS );
        foreach ( $cut as $old ) {
            $j = self::job( $old );
            if ( ! empty( $j['created'] ) && ( time() - (int) $j['created'] ) > DAY_IN_SECONDS * 2 ) {
                delete_option( self::job_key( $old ) );
            }
        }
        update_option( self::JOB_INDEX, array_slice( $ids, 0, self::MAX_JOBS ), false );
    }

    /** Only intercept Content Plan generation. Quick Create and other AI tools retain existing behavior. */
    public static function intercept_generate( $result, $server, $request ) {
        if ( null !== $result || ! $request || '/seo-command/v1/generate' !== $request->get_route() ||
            'POST' !== $request->get_method() || ! get_option( self::TOKEN_KEY, '' ) ) {
            return $result;
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'scc_mac_forbidden', 'You cannot create content.', array( 'status' => 403 ) );
        }
        if ( ! class_exists( 'SCC_Content_Plan' ) || ! class_exists( 'SCC_Generator' ) ) { return $result; }
        $id = absint( $request->get_param( 'entry_id' ) );
        if ( ! $id ) { return new WP_Error( 'scc_mac_entry', 'Select a content plan entry.', array( 'status' => 400 ) ); }
        $entry = SCC_Content_Plan::find( $id );
        if ( ! $entry ) { return new WP_Error( 'scc_mac_entry', 'The content plan entry was not found.', array( 'status' => 404 ) ); }
        if ( ! empty( $entry['post_id'] ) && get_post( (int) $entry['post_id'] ) ) {
            return self::response( array( 'done' => true, 'post_id' => (int) $entry['post_id'],
                'edit_url' => get_edit_post_link( (int) $entry['post_id'], 'raw' ), 'status' => 'draft' ) );
        }
        $existing = self::job( $id );
        if ( $existing && in_array( $existing['state'] ?? '', array( 'queued', 'working', 'ready', 'finalizing', 'done' ), true ) ) {
            return self::response( array( 'queued' => true, 'state' => $existing['state'], 'entry_id' => $id ) );
        }

        $params = $request->get_json_params();
        $brief = is_array( $params ) && isset( $params['brief'] ) && is_array( $params['brief'] ) ? $params['brief'] : null;
        $capture = new SCC_Local_Bridge_Capture_Manager();
        $generator = new SCC_Generator( $capture );
        try {
            $generator->generate( $entry, $brief );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'scc_mac_capture', $e->getMessage(), array( 'status' => 500 ) );
        }
        if ( ! $capture->request ) {
            return new WP_Error( 'scc_mac_capture', 'TideOrbit did not produce an AI prompt.', array( 'status' => 500 ) );
        }
        $request_data = $capture->request;
        $request_data['model'] = (string) SCC_Settings::get( 'lmstudio_model', 'local-model' );
        $request_data['max_tokens'] = -1;
        $job = array(
            'id' => $id, 'state' => 'queued', 'created' => time(), 'updated' => time(),
            'request' => $request_data, 'brief' => $brief, 'result' => '', 'error' => '', 'post_id' => 0,
            'lease' => '', 'lease_until' => 0,
        );
        self::save_job( $id, $job );
        self::add_job_id( $id );
        return self::response( array( 'queued' => true, 'state' => 'queued', 'entry_id' => $id ) );
    }

    public static function next( WP_REST_Request $request ) {
        update_option( 'scc_mac_last_seen', time(), false );
        foreach ( self::job_ids() as $id ) {
            $job = self::job( $id );
            if ( ! $job ) { continue; }
            if ( 'working' === ( $job['state'] ?? '' ) && time() > (int) ( $job['lease_until'] ?? 0 ) ) {
                $job['state'] = 'queued'; // Mac closed; return work to queue.
            }
            if ( 'queued' !== ( $job['state'] ?? '' ) ) { continue; }
            $lease = bin2hex( random_bytes( 16 ) );
            $lock = 'scc_mac_lock_' . $id;
            // WordPress add_option provides an atomic insert; prevent two Macs
            // claiming the same job. A stale lock can be reset after 45 min.
            $locked = get_option( $lock, array() );
            if ( is_array( $locked ) && (int) ( $locked['expires'] ?? 0 ) < time() ) {
                delete_option( $lock );
            }
            if ( ! add_option( $lock, array( 'expires' => time() + 2700 ), '', false ) ) { continue; }
            $job['state'] = 'working';
            $job['lease'] = $lease;
            $job['lease_until'] = time() + 2700;
            $job['updated'] = time();
            self::save_job( $id, $job );
            delete_option( $lock );
            return self::response( array( 'job' => array( 'id' => $id, 'lease' => $lease, 'request' => $job['request'] ) ) );
        }
        return self::response( array( 'job' => null ) );
    }

    public static function complete( WP_REST_Request $request ) {
        $id = absint( $request->get_param( 'id' ) );
        $lease = (string) $request->get_param( 'lease' );
        $job = self::job( $id );
        if ( ! $job || ! $lease || ! hash_equals( (string) ( $job['lease'] ?? '' ), $lease ) ) {
            return new WP_Error( 'scc_mac_lease', 'Job claim invalid or expired.', array( 'status' => 409 ) );
        }
        if ( 'ready' === ( $job['state'] ?? '' ) || 'done' === ( $job['state'] ?? '' ) ) {
            return self::response( array( 'state' => $job['state'] ) );
        }
        if ( 'working' !== ( $job['state'] ?? '' ) ) { return new WP_Error( 'scc_mac_state', 'Job is not in progress.', array( 'status' => 409 ) ); }
        $error = sanitize_text_field( (string) $request->get_param( 'error' ) );
        $raw = (string) $request->get_param( 'content' );
        if ( strlen( $raw ) > self::MAX_BODY ) {
            return new WP_Error( 'scc_mac_size', 'Generated response is too large.', array( 'status' => 413 ) );
        }
        if ( '' !== $error ) {
            $job['state'] = 'error'; $job['error'] = substr( $error, 0, 500 );
        } elseif ( strlen( trim( $raw ) ) > 100 ) {
            $job['state'] = 'ready'; $job['result'] = $raw;
        } else {
            $job['state'] = 'error'; $job['error'] = 'LM Studio returned empty or very short content.';
        }
        $job['updated'] = time();
        self::save_job( $id, $job );
        return self::response( array( 'state' => $job['state'] ) );
    }

    public static function finalize( WP_REST_Request $request ) {
        $id = absint( $request->get_param( 'id' ) );
        $lease = (string) $request->get_param( 'lease' );
        $job = self::job( $id );
        if ( ! $job || ! $lease || ! hash_equals( (string) ( $job['lease'] ?? '' ), $lease ) ) {
            return new WP_Error( 'scc_mac_lease', 'Job claim invalid.', array( 'status' => 409 ) );
        }
        if ( 'done' === ( $job['state'] ?? '' ) ) {
            return self::response( array( 'state' => 'done', 'post_id' => (int) ( $job['post_id'] ?? 0 ) ) );
        }
        if ( 'ready' !== ( $job['state'] ?? '' ) ) {
            return new WP_Error( 'scc_mac_state', 'Model result not ready.', array( 'status' => 409 ) );
        }
        $entry = SCC_Content_Plan::find( $id );
        if ( ! $entry ) { return new WP_Error( 'scc_mac_entry', 'Content plan entry deleted.', array( 'status' => 404 ) ); }
        if ( ! empty( $entry['post_id'] ) && get_post( (int) $entry['post_id'] ) ) {
            $job['state'] = 'done'; $job['post_id'] = (int) $entry['post_id'];
            self::save_job( $id, $job );
            return self::response( array( 'state' => 'done', 'post_id' => $job['post_id'] ) );
        }
        // Atomic one-time finalize lock, safe if Mac retries after HTTP timeout.
        if ( ! add_option( 'scc_mac_finalize_' . $id, time(), '', false ) ) {
            return new WP_Error( 'scc_mac_finalizing', 'Draft is being created already.', array( 'status' => 409 ) );
        }
        $job['state'] = 'finalizing'; self::save_job( $id, $job );
        try {
            $manager = new SCC_Local_Bridge_Results_Manager( (string) $job['result'] );
            $generator = new SCC_Generator( $manager );
            $res = $generator->generate( $entry, $job['brief'] ?? null );
            if ( is_wp_error( $res ) ) {
                $job['state'] = 'error'; $job['error'] = $res->get_error_message();
            } else {
                $job['state'] = 'done'; $job['post_id'] = (int) ( $res['post_id'] ?? 0 );
                $job['result'] = ''; $job['request'] = array(); // Drop large prompts when done.
            }
        } catch ( \Throwable $e ) {
            $job['state'] = 'error'; $job['error'] = $e->getMessage();
        }
        $job['updated'] = time();
        self::save_job( $id, $job );
        delete_option( 'scc_mac_finalize_' . $id );
        return self::response( array( 'state' => $job['state'], 'post_id' => (int) $job['post_id'], 'error' => (string) $job['error'] ) );
    }

    public static function status( WP_REST_Request $request ) {
        $id = absint( $request->get_param( 'id' ) );
        $job = self::job( $id );
        return self::response( array( 'state' => (string) ( $job['state'] ?? 'unknown' ),
            'error' => (string) ( $job['error'] ?? '' ), 'post_id' => (int) ( $job['post_id'] ?? 0 ) ) );
    }

    /** Read-only helper for the authenticated WordPress status-poll route. */
    public static function status_for_entry( $id ) {
        $job = self::job( $id );
        return array( 'bridge_state' => (string) ( $job['state'] ?? '' ),
            'bridge_error' => (string) ( $job['error'] ?? '' ) );
    }
}

/** Intercepts the existing generator's first AI call without contacting LM Studio. */
class SCC_Local_Bridge_Capture_Manager extends SCC_AI_Manager {
    public $request = null;
    public function __construct() {}
    public function complete( array $request, $operation = 'generic' ) {
        if ( null === $this->request && 'content-generation' === $operation ) { $this->request = $request; }
        $r = new SCC_AI_Response();
        $r->error = new WP_Error( 'scc_mac_queued', 'Sending work to the TideBridge Mac app.', array( 'status' => 202 ) );
        return $r;
    }
}

/** Reuses the exact existing WordPress draft pipeline with completed Mac output. */
class SCC_Local_Bridge_Results_Manager extends SCC_AI_Manager {
    private $output;
    public function __construct( $output ) { $this->output = (string) $output; }
    public function complete( array $request, $operation = 'generic' ) {
        $r = new SCC_AI_Response();
        if ( 'content-generation' === $operation ) {
            $r->provider = 'lmstudio'; $r->content = $this->output;
        } else {
            $r->error = new WP_Error( 'scc_mac_unsupported', 'This optional follow-up AI step is not available during bridge finalization.', array( 'status' => 400 ) );
        }
        return $r;
    }
}
