<?php
/**
 * Regression: the production plugin bootstrap must register lazy callback
 * classes before rest_api_init fires (Detect models used to fatal here).
 * No WordPress install, credentials, network or database is required.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.com/wp-content/plugins/seo-command-center/'; }
function plugin_basename( $f ) { return 'seo-command-center/seo-command-center.php'; }
function register_activation_hook( $f, $cb ) {}
function register_deactivation_hook( $f, $cb ) {}
function has_action( $hook, $cb ) { return false; }
function add_action( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['test_hooks'][ $hook ][] = $cb; }
function add_filter( $hook, $cb, $priority = 10, $args = 1 ) { $GLOBALS['test_filters'][ $hook ][] = $cb; }
function add_shortcode( $tag, $cb ) {}
function is_admin() { return false; }
function register_rest_route( $ns, $route, $opts ) { $GLOBALS['test_routes'][] = $ns . $route; }
class WP_REST_Server {
    const CREATABLE = 'POST';
}
require dirname( __DIR__ ) . '/seo-command-center/seo-command-center.php';
scc_bootstrap();
$callbacks = isset( $GLOBALS['test_hooks']['rest_api_init'] ) ? $GLOBALS['test_hooks']['rest_api_init'] : array();
if ( count( $callbacks ) < 2 ) {
    fwrite( STDERR, "REST hooks missing after plugin bootstrap.\n" );
    exit( 1 );
}
foreach ( $callbacks as $cb ) {
    if ( ! is_callable( $cb ) ) {
        fwrite( STDERR, 'Invalid REST callback: ' . ( is_array( $cb ) ? ( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0] ) . '::' . $cb[1] : 'other' ) . "\n" );
        exit( 1 );
    }
}
if ( ! class_exists( 'SCC_Elementor_Inline' ) || ! class_exists( 'SCC_Elementor_Section_Designer' ) ) {
    fwrite( STDERR, "Elementor AI designer autoload failed.\n" );
    exit( 1 );
}
// Run the two Elementor registration callbacks, not merely class_exists().
SCC_Elementor_Inline::register_route();
foreach ( array( '/elementor/inline/suggest', '/elementor/inline/section-suggest' ) as $route ) {
    if ( ! in_array( 'seo-command/v1' . $route, $GLOBALS['test_routes'], true ) ) {
        fwrite( STDERR, "Elementor REST route missing: {$route}\n" );
        exit( 1 );
    }
}
echo "Production REST bootstrap, Detect models prerequisite and Elementor routes: PASS\n";
