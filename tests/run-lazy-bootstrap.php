<?php
/**
 * Standalone public-request boot contract.
 *
 * Exercises actual SCC_Plugin::run() with minimal WP hook stubs, proving the
 * fast path does not parse or create AI, Elementor or worker implementations.
 * Does not connect to a WordPress database or make external network calls.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SCC_PLUGIN_DIR', dirname( __DIR__ ) . '/seo-command-center/' );
class SCC_Loader {
    public $actions = array();
    public function add_action( $hook, $object, $method, $priority = 10, $accepted_args = 1 ) {
        $this->actions[ $hook ][] = array( $object, $method, $priority, $accepted_args );
    }
    public function run() {}
}
$GLOBALS['scc_runtime_filters'] = array();
$GLOBALS['scc_runtime_shortcodes'] = array();
function is_admin() { return false; }
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['scc_runtime_filters'][ $hook ][] = $callback;
}
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
    $GLOBALS['scc_runtime_filters'][ $hook ][] = $callback;
}
function add_shortcode( $tag, $callback ) {
    $GLOBALS['scc_runtime_shortcodes'][ $tag ] = $callback;
}
require_once SCC_PLUGIN_DIR . 'includes/class-scc-autoloader.php';
SCC_Autoloader::register();
$plugin = SCC_Plugin::instance();
$plugin->run();

$failed = 0;
foreach ( array(
    'SCC_AI_Manager', 'SCC_Jobs', 'SCC_Autopilot',
    'SCC_Page_Architect', 'SCC_Block_Registry', 'SCC_Citation_Scanner',
    'SCC_REST', 'SCC_Admin',
) as $name ) {
    if ( class_exists( $name, false ) ) {
        fwrite( STDERR, "Unexpected eager class: {$name}\n" );
        $failed++;
    }
}
if ( ! isset( $GLOBALS['scc_runtime_shortcodes']['tideorbit_citation_scan'] ) ) {
    fwrite( STDERR, "Citation shortcode not registered.\n" );
    $failed++;
}
if ( ! isset( $GLOBALS['scc_runtime_filters']['scc_layout_blocks'] ) ) {
    fwrite( STDERR, "Page Architect filter not registered.\n" );
    $failed++;
}
$loader = new ReflectionProperty( 'SCC_Plugin', 'loader' );
$loader->setAccessible( true );
$actions = $loader->getValue( $plugin )->actions;
foreach ( array( 'scc_run_jobs', 'scc_run_jobs_kick', 'save_post', 'before_delete_post' ) as $hook ) {
    if ( ! isset( $actions[ $hook ] ) ) {
        fwrite( STDERR, "Missing deferred hook: {$hook}\n" );
        $failed++;
    }
}
// Route discovery also happens on every WordPress REST request, including
// endpoints owned by other plugins. Register TideOrbit's routes without AI.
class WP_REST_Server {
    const READABLE = 'GET';
    const CREATABLE = 'POST';
    const EDITABLE = 'POST, PUT, PATCH';
    const DELETABLE = 'DELETE';
}
$GLOBALS['scc_runtime_routes'] = array();
function register_rest_route( $namespace, $route, $options ) {
    $GLOBALS['scc_runtime_routes'][] = $namespace . $route;
}
$plugin->register_rest_routes();
if ( count( $GLOBALS['scc_runtime_routes'] ) < 100 ) {
    fwrite( STDERR, "TideOrbit REST routes were not all registered.\n" );
    $failed++;
}
foreach ( array( 'SCC_AI_Manager', 'SCC_Jobs' ) as $name ) {
    if ( class_exists( $name, false ) ) {
        fwrite( STDERR, "REST route registration initialized {$name}.\n" );
        $failed++;
    }
}

echo "Public-request lazy bootstrap: " . ( $failed ? "{$failed} failures" : 'PASS' ) . PHP_EOL;
exit( $failed ? 1 : 0 );
