<?php
/**
 * UI source contract. Guards CSS scope, dashboard endpoints and core controls
 * while the visual design can evolve without a JavaScript framework.
 */
$base = dirname( __DIR__ ) . '/seo-command-center/';
$admin = (string) file_get_contents( $base . 'includes/admin/class-scc-admin.php' );
$dashboard = (string) file_get_contents( $base . 'includes/admin/views/dashboard.php' );
$css = (string) file_get_contents( $base . 'assets/css/admin.css' );
$js = (string) file_get_contents( $base . 'assets/js/admin.js' );
$errors = array();
foreach ( array(
    'scc-app-nav', 'aria-current="page"', 'render_primary_navigation',
    'scc-app-nav__link', 'scc-app-brand__mark',
) as $needle ) {
    if ( false === strpos( $admin, $needle ) && false === strpos( $css, $needle ) ) {
        $errors[] = 'Navigation contract: ' . $needle;
    }
}
foreach ( array(
    'scc-doctor', 'scc-doctor-run', 'scc-doctor-refresh', 'scc-doctor-body',
    'scc-doctor-data', 'scc-copilot', 'scc-copilot-q', 'scc-copilot-go',
    'scc-metric-score', 'scc-metric-issues', 'scc-metric-fixable',
    'scc-dashboard-hero', 'scc-dashboard-shortcuts',
) as $needle ) {
    if ( false === strpos( $dashboard, $needle ) ) {
        $errors[] = 'Missing dashboard control: ' . $needle;
    }
}
foreach ( array( 'scc-metric-score', 'scc-metric-issues', 'scc-metric-fixable', 'scc-metric-track' ) as $needle ) {
    if ( false === strpos( $js, $needle ) ) {
        $errors[] = 'Missing live metric binding: ' . $needle;
    }
}
foreach ( array( '.scc-metric-grid', '.scc-dashboard-hero', '.scc-app-nav', '.scc-hub-tabs', '.scc-settings-panel[hidden]', ':focus-visible', 'prefers-reduced-motion', 'max-width:530px' ) as $needle ) {
    if ( false === strpos( $css, $needle ) ) {
        $errors[] = 'Missing UI rule: ' . $needle;
    }
}
if ( preg_match( '~@import\s|url\(\s*["\']?https?://~i', $css ) ) {
    $errors[] = 'UI stylesheet must not add remote dependencies';
}
foreach ( $errors as $error ) { fwrite( STDERR, $error . PHP_EOL ); }
echo 'TideOrbit native UI contracts: ' . ( $errors ? count( $errors ) . ' failures' : 'PASS' ) . PHP_EOL;
exit( $errors ? 1 : 0 );
