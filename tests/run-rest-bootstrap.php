<?php
/**
 * Standalone WordPress bootstrap/REST smoke test.
 *
 * Run in a fresh PHP process so classes are NOT preloaded by tests/bootstrap.php.
 * Reproduces the fatal from TideOrbit 1.99.1: Elementor inline callback was
 * registered on rest_api_init without being included in the production entrypoint.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['scc_smoke_hooks'] = array();
$GLOBALS['scc_smoke_routes'] = array();

function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'https://example.test/wp-content/plugins/seo-command-center/'; }
function plugin_basename( $file ) { return 'seo-command-center/' . basename( $file ); }
function has_action( $hook, $callback ) { return 0; }
function add_action( $hook, $callback, $priority = 10, $count = 1 ) { $GLOBALS['scc_smoke_hooks'][$hook][] = $callback; }
function add_filter( $hook, $callback, $priority = 10, $count = 1 ) { $GLOBALS['scc_smoke_hooks'][$hook][] = $callback; }
function add_shortcode( $tag, $callback ) {}
function register_activation_hook( $file, $callback ) {}
function register_deactivation_hook( $file, $callback ) {}
function is_admin() { return false; }
function do_action( $hook ) {}
function get_option( $key, $default = false ) { return $default; }
function untrailingslashit( $url ) { return rtrim( $url, '/' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function apply_filters( $hook, $value ) { return $value; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function site_url( $path = '/' ) { return 'https://example.test' . $path; }
function __( $value, $domain = '' ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function esc_url_raw( $value ) { return $value; }
function register_rest_route( $namespace, $route, $args ) { $GLOBALS['scc_smoke_routes'][] = $namespace . $route; }
function wp_remote_get( $url, $args ) {
    return array( 'response' => array( 'code' => 200 ), 'body' => '{"data":[{"id":"qwen/qwen3.6-35b-a3b"}]}' );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_Error {
    public function __construct( $code = '', $message = '' ) {}
    public function get_error_message() { return 'Simulated error'; }
}
class WP_REST_Server {
    const READABLE = 'GET';
    const CREATABLE = 'POST';
    const EDITABLE = 'POST,PUT,PATCH';
    const DELETABLE = 'DELETE';
}
class WP_REST_Request {
    public function get_json_params() { return array( 'base_url' => 'http://127.0.0.1:1234/v1' ); }
}
class WP_REST_Response {
    public $data;
    public function __construct( $data, $status = 200 ) { $this->data = $data; }
}

require_once dirname( __DIR__ ) . '/seo-command-center/seo-command-center.php';
SCC_Plugin::instance()->run();

// Every REST callback on the live plugin must be callable on a fresh request,
// before any editor assets or specific provider request has loaded it.
foreach ( $GLOBALS['scc_smoke_hooks']['rest_api_init'] as $callback ) {
    if ( ! is_callable( $callback ) ) {
        fwrite( STDERR, 'Uncallable WordPress REST bootstrap callback: ' . json_encode( $callback ) . PHP_EOL );
        exit( 1 );
    }
    call_user_func( $callback );
}
foreach ( array(
    'seo-command/v1/lmstudio/models',
    'seo-command/v1/elementor/inline/suggest',
) as $route ) {
    if ( ! in_array( $route, $GLOBALS['scc_smoke_routes'], true ) ) {
        fwrite( STDERR, 'Missing REST route: ' . $route . PHP_EOL );
        exit( 1 );
    }
}

// Call the exact failing endpoint without relying on a real LM Studio tunnel.
$response = ( new SCC_REST() )->lmstudio_models( new WP_REST_Request() );
if ( ! $response instanceof WP_REST_Response
    || ! in_array( 'qwen/qwen3.6-35b-a3b', $response->data['data']['models'] ?? array(), true ) ) {
    fwrite( STDERR, 'LM Studio detect-models endpoint failed the Qwen response contract.' . PHP_EOL );
    exit( 1 );
}
echo "WordPress bootstrap, REST registrations and LM Studio Qwen model discovery: PASS\n";
