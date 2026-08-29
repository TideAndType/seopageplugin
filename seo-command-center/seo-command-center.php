<?php
/**
 * Plugin Name:       SEO Command Center
 * Plugin URI:        https://tideandtype.com/seo-command-center
 * Description:       AI-powered SEO Command Center for WordPress + Elementor: analyze your site, build an SEO strategy and architecture, and generate on-brand pages and articles — always as drafts by default, you stay in control.
 * Version:           1.0.0
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
// Constants.
// ---------------------------------------------------------------------------
define( 'SCC_VERSION', '1.0.0' );
define( 'SCC_DB_VERSION', '1.0.0' );
define( 'SCC_PLUGIN_FILE', __FILE__ );
define( 'SCC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SCC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// ---------------------------------------------------------------------------
// Autoload-free, explicit includes (predictable load order, no dependencies).
// ---------------------------------------------------------------------------
require_once SCC_PLUGIN_DIR . 'includes/class-scc-loader.php';
require_once SCC_PLUGIN_DIR . 'includes/database/class-scc-db.php';
require_once SCC_PLUGIN_DIR . 'includes/security/class-scc-security.php';
require_once SCC_PLUGIN_DIR . 'includes/logging/class-scc-logger.php';
require_once SCC_PLUGIN_DIR . 'includes/class-scc-activator.php';
require_once SCC_PLUGIN_DIR . 'includes/class-scc-deactivator.php';

require_once SCC_PLUGIN_DIR . 'includes/ai/class-scc-ai-response.php';
require_once SCC_PLUGIN_DIR . 'includes/ai/interface-scc-ai-provider.php';
require_once SCC_PLUGIN_DIR . 'includes/ai/class-scc-ai-usage.php';
require_once SCC_PLUGIN_DIR . 'includes/ai/class-scc-claude-provider.php';
require_once SCC_PLUGIN_DIR . 'includes/ai/class-scc-openai-provider.php';
require_once SCC_PLUGIN_DIR . 'includes/ai/class-scc-ai-manager.php';

require_once SCC_PLUGIN_DIR . 'includes/analysis/class-scc-seo-meta.php';
require_once SCC_PLUGIN_DIR . 'includes/analysis/class-scc-crawler.php';
require_once SCC_PLUGIN_DIR . 'includes/analysis/class-scc-analyzer.php';

require_once SCC_PLUGIN_DIR . 'includes/strategy/class-scc-keyword-strategy.php';
require_once SCC_PLUGIN_DIR . 'includes/strategy/class-scc-architecture.php';
require_once SCC_PLUGIN_DIR . 'includes/strategy/class-scc-content-plan.php';
require_once SCC_PLUGIN_DIR . 'includes/strategy/class-scc-cannibalization.php';

require_once SCC_PLUGIN_DIR . 'includes/generation/class-scc-content-brief.php';
require_once SCC_PLUGIN_DIR . 'includes/generation/class-scc-schema.php';
require_once SCC_PLUGIN_DIR . 'includes/generation/class-scc-metadata.php';
require_once SCC_PLUGIN_DIR . 'includes/generation/class-scc-quality-score.php';
require_once SCC_PLUGIN_DIR . 'includes/generation/class-scc-generator.php';

require_once SCC_PLUGIN_DIR . 'includes/elementor/class-scc-elementor.php';
require_once SCC_PLUGIN_DIR . 'includes/elementor/class-scc-placeholders.php';
require_once SCC_PLUGIN_DIR . 'includes/elementor/class-scc-template-mapping.php';
require_once SCC_PLUGIN_DIR . 'includes/elementor/class-scc-elementor-builder.php';

require_once SCC_PLUGIN_DIR . 'includes/links/class-scc-link-graph.php';
require_once SCC_PLUGIN_DIR . 'includes/links/class-scc-link-recommender.php';
require_once SCC_PLUGIN_DIR . 'includes/links/class-scc-link-inserter.php';

require_once SCC_PLUGIN_DIR . 'includes/admin/class-scc-settings.php';
require_once SCC_PLUGIN_DIR . 'includes/admin/class-scc-admin.php';
require_once SCC_PLUGIN_DIR . 'includes/rest/class-scc-rest.php';

require_once SCC_PLUGIN_DIR . 'includes/class-scc-plugin.php';

// ---------------------------------------------------------------------------
// Activation / deactivation lifecycle.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'SCC_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'SCC_Deactivator', 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded (so we can detect SEO plugins,
 * Elementor, etc.).
 */
function scc_bootstrap() {
	SCC_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'scc_bootstrap' );
