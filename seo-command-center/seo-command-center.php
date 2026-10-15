<?php
/**
 * Plugin Name:       TideOrbit
 * Plugin URI:        https://tideandtype.com/seo-command-center
 * Description:       TideOrbit — AI-powered SEO + AEO for WordPress and Elementor: analyze your site, architect future growth, improve AI citation readiness, and generate on-brand content with draft-first live-page safety.
 * Version:           1.92.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Tide & Type
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seo-command-center
 * Domain Path:       /languages
 *
 * @package SEO_Command_Center
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Idempotent load. Earlier versions bailed out entirely when scc_bootstrap()
// already existed, to fatal-proof against a second plugin folder — but some
// hosts/scanners re-include a plugin's own main file in the same request, and
// that bail wrongly hid the whole plugin (menu included). We no longer bail.
// Instead every top-level declaration below is written to be safe if this same
// file is included more than once: constants are guarded with defined(), class
// files use require_once (which dedupes by path), and the boot function + hook
// are guarded with function_exists()/has_action(). A single install therefore
// always loads fully; a same-file re-include is a harmless no-op.
// ---------------------------------------------------------------------------

// ---------------------------------------------------------------------------
// Constants (guarded so a same-file re-include never re-defines them).
// ---------------------------------------------------------------------------
if ( ! defined( 'SCC_VERSION' ) ) {
	define( 'SCC_VERSION', '1.92.3' );
	define( 'SCC_DB_VERSION', '1.20.0' );
	define( 'SCC_PLUGIN_FILE', __FILE__ );
	define( 'SCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
	define( 'SCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
	define( 'SCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

// ---------------------------------------------------------------------------
// Lazy class loading. Hooks and public APIs remain unchanged; unused feature
// classes are not parsed on every visitor request.
// ---------------------------------------------------------------------------
require_once SCC_PLUGIN_DIR . 'includes/class-scc-autoloader.php';
SCC_Autoloader::register();

// ---------------------------------------------------------------------------
// Activation / deactivation lifecycle.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'SCC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCC_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded (so we can detect SEO plugins,
 * Elementor, etc.). Guarded so a re-include of this file never redeclares it.
 */
if ( ! function_exists( 'scc_bootstrap' ) ) {
	function scc_bootstrap() {
		SCC_Plugin::instance()->run();
	}
}
if ( ! has_action( 'plugins_loaded', 'scc_bootstrap' ) ) {
	add_action( 'plugins_loaded', 'scc_bootstrap' );
}

// Async Browser Runtime polling. Each accepted tunnel scan schedules its own
// lightweight poll; the result is normalized back into the Local Grid store.
if ( ! has_action( SCC_Browser_Runtime::CRON_HOOK, array( 'SCC_Browser_Runtime', 'poll_job' ) ) ) {
	add_action( SCC_Browser_Runtime::CRON_HOOK, array( 'SCC_Browser_Runtime', 'poll_job' ), 10, 1 );
}

/**
 * Flush PHP OPcache when this plugin is updated, so the new files are executed
 * immediately instead of stale cached bytecode (managed hosts often run
 * opcache.validate_timestamps=0). Safe no-op when OPcache is unavailable.
 */
if ( ! function_exists( 'scc_reset_opcache_on_update' ) ) {
	function scc_reset_opcache_on_update( $upgrader, $data ) {
		if ( ! function_exists( 'opcache_reset' ) ) {
			return;
		}
		if ( is_array( $data ) && isset( $data['type'] ) && 'plugin' === $data['type'] ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}
if ( ! has_action( 'upgrader_process_complete', 'scc_reset_opcache_on_update' ) ) {
	add_action( 'upgrader_process_complete', 'scc_reset_opcache_on_update', 10, 2 );
}
