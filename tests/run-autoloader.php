<?php
/**
 * Standalone class-map check: the normal test bootstrap eagerly loads classes,
 * so it cannot detect a broken lazy loader. This deliberately does not include
 * WordPress or the normal bootstrap.
 */
error_reporting( E_ALL & ~E_DEPRECATED );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SCC_PLUGIN_DIR', dirname( __DIR__ ) . '/seo-command-center/' );
require_once SCC_PLUGIN_DIR . 'includes/class-scc-autoloader.php';
SCC_Autoloader::register();

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator( SCC_PLUGIN_DIR . 'includes', FilesystemIterator::SKIP_DOTS )
);
$checked = 0;
$failed = 0;
foreach ( $files as $file ) {
    if ( ! $file->isFile() || 'php' !== $file->getExtension() ) { continue; }
    $filename = $file->getBasename( '.php' );
    if ( ! preg_match( '/^(class|interface)-scc-(.+)$/', $filename, $parts ) ) { continue; }
    $name = 'SCC_' . str_replace( '-', '_', $parts[2] );
    if ( 'interface-scc-ai-provider' === $filename ) { $name = 'SCC_AI_Provider_Interface'; }
    // The main plugin, WordPress, and DB are intentionally NOT initialized here.
    $loaded = class_exists( $name, true ) || interface_exists( $name, true );
    $checked++;
    if ( ! $loaded ) {
        $failed++;
        fwrite( STDERR, 'Missing autoload declaration: ' . $name . ' (' . $file->getPathname() . ')' . PHP_EOL );
    } elseif ( realpath( ( new ReflectionClass( $name ) )->getFileName() ) !== realpath( $file->getPathname() ) ) {
        $failed++;
        fwrite( STDERR, 'Autoload resolves the wrong file for ' . $name . PHP_EOL );
    }
}
echo "Checked {$checked} class/interface declarations; {$failed} missing or mismatched." . PHP_EOL;
exit( $failed ? 1 : 0 );
