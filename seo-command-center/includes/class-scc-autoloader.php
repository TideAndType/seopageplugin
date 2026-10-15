<?php
/**
 * TideOrbit lightweight class map. Class paths are resolved without loading
 * their implementations until the corresponding feature is used.
 *
 * Generated from the formerly eager bootstrap paths. Keep this manifest updated
 * if you add/remove a class file. PHP names are case insensitive.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( ! class_exists( 'SCC_Autoloader', false ) ) {
    class SCC_Autoloader {
        private static $registered = false;
        private static $map = array();

        public static function register() {
            if ( self::$registered ) { return; }
            $paths = array(
                'includes/class-scc-loader.php',
                'includes/database/class-scc-db.php',
                'includes/security/class-scc-security.php',
                'includes/net/class-scc-url.php',
                'includes/net/class-scc-robots.php',
                'includes/logging/class-scc-logger.php',
                'includes/class-scc-activator.php',
                'includes/class-scc-deactivator.php',
                'includes/ai/class-scc-ai-response.php',
                'includes/ai/interface-scc-ai-provider.php',
                'includes/ai/class-scc-ai-usage.php',
                'includes/ai/class-scc-claude-provider.php',
                'includes/ai/class-scc-openai-provider.php',
                'includes/ai/class-scc-gemini-provider.php',
                'includes/ai/class-scc-lmstudio-provider.php',
                'includes/ai/class-scc-ai-manager.php',
                'includes/analysis/class-scc-seo-meta.php',
                'includes/analysis/class-scc-crawler.php',
                'includes/analysis/class-scc-analyzer.php',
                'includes/strategy/class-scc-keyword-strategy.php',
                'includes/strategy/class-scc-topical-authority.php',
                'includes/strategy/class-scc-architecture.php',
                'includes/strategy/class-scc-content-plan.php',
                'includes/strategy/class-scc-cannibalization.php',
                'includes/generation/class-scc-content-brief.php',
                'includes/generation/class-scc-schema.php',
                'includes/generation/class-scc-metadata.php',
                'includes/generation/class-scc-quality-score.php',
                'includes/generation/class-scc-generator.php',
                'includes/generation/class-scc-content-ideas.php',
                'includes/elementor/class-scc-elementor.php',
                'includes/elementor/class-scc-elementor-inline.php',
                'includes/elementor/class-scc-placeholders.php',
                'includes/elementor/class-scc-template-mapping.php',
                'includes/elementor/class-scc-elementor-builder.php',
                'includes/index/class-scc-content-index.php',
                'includes/index/class-scc-change-history.php',
                'includes/index/class-scc-seo-report.php',
                'includes/intelligence/class-scc-brand-brain.php',
                'includes/intelligence/class-scc-site-knowledge.php',
                'includes/intelligence/class-scc-page-brain.php',
                'includes/links/class-scc-link-graph.php',
                'includes/links/class-scc-link-recommender.php',
                'includes/links/class-scc-link-inserter.php',
                'includes/links/class-scc-anchor-engine.php',
                'includes/links/class-scc-link-engine.php',
                'includes/links/class-scc-autopilot.php',
                'includes/meta/class-scc-meta-history.php',
                'includes/meta/class-scc-meta-optimizer.php',
                'includes/schema/class-scc-schema-engine.php',
                'includes/template/class-scc-content-object.php',
                'includes/template/class-scc-template-variables.php',
                'includes/template/class-scc-template.php',
                'includes/template/class-scc-template-store.php',
                'includes/template/class-scc-template-map.php',
                'includes/template/class-scc-template-selector.php',
                'includes/render/class-scc-content-presenter.php',
                'includes/render/interface-scc-renderer.php',
                'includes/render/class-scc-wordpress-renderer.php',
                'includes/render/class-scc-gutenberg-renderer.php',
                'includes/render/class-scc-elementor-renderer.php',
                'includes/render/class-scc-renderer-manager.php',
                'includes/layout/class-scc-block-registry.php',
                'includes/layout/class-scc-elementor-capabilities.php',
                'includes/layout/class-scc-elementor-widget-catalog.php',
                'includes/layout/class-scc-elementor-widget-schema.php',
                'includes/layout/class-scc-elementor-content-bank.php',
                'includes/layout/class-scc-elementor-composition.php',
                'includes/layout/class-scc-design-discovery.php',
                'includes/layout/class-scc-visual-recreation.php',
                'includes/layout/class-scc-elementor-design-blueprint.php',
                'includes/layout/class-scc-elementor-design-agent.php',
                'includes/layout/class-scc-design-intel.php',
                'includes/layout/class-scc-design-handoff.php',
                'includes/layout/class-scc-design-composer.php',
                'includes/layout/class-scc-block-variant-selector.php',
                'includes/layout/class-scc-layout-analyzer.php',
                'includes/layout/class-scc-page-architect.php',
                'includes/layout/class-scc-layout-validator.php',
                'includes/layout/class-scc-content-mapper.php',
                'includes/layout/class-scc-native-elementor-blocks.php',
                'includes/layout/class-scc-block-elementor-renderer.php',
                'includes/layout/class-scc-layout-service.php',
                'includes/integrations/class-scc-gsc.php',
                'includes/intelligence/class-scc-gsc-learning.php',
                'includes/integrations/class-scc-dataforseo.php',
                'includes/integrations/class-scc-competitor-analysis.php',
                'includes/local/class-scc-citation-scanner.php',
                'includes/intelligence/class-scc-gsc-cannibalization.php',
                'includes/intelligence/class-scc-link-boost.php',
                'includes/intelligence/class-scc-preflight.php',
                'includes/local/class-scc-local-grid.php',
                'includes/intelligence/class-scc-browser-runtime.php',
                'includes/intelligence/class-scc-backlink-gap.php',
                'includes/jobs/class-scc-jobs.php',
                'includes/publishing/class-scc-publishing.php',
                'includes/intelligence/class-scc-content-decay.php',
                'includes/intelligence/class-scc-intent-drift.php',
                'includes/intelligence/class-scc-opportunity-engine.php',
                'includes/intelligence/class-scc-action-queue.php',
                'includes/intelligence/class-scc-page-optimizer.php',
                'includes/intelligence/class-scc-technical-seo.php',
                'includes/intelligence/class-scc-pagespeed.php',
                'includes/intelligence/class-scc-seo-doctor.php',
                'includes/intelligence/class-scc-doctor-fixer.php',
                'includes/meta/class-scc-social-tags.php',
                'includes/meta/class-scc-meta-tags.php',
                'includes/intelligence/class-scc-architecture-brain.php',
                'includes/intelligence/class-scc-architecture-expansion.php',
                'includes/intelligence/class-scc-aeo-expert.php',
                'includes/intelligence/class-scc-seo-growth-architect.php',
                'includes/intelligence/class-scc-topic-coverage.php',
                'includes/intelligence/class-scc-tidescore.php',
                'includes/intelligence/class-scc-page-critic.php',
                'includes/intelligence/class-scc-health-timeline.php',
                'includes/intelligence/class-scc-experiments.php',
                'includes/intelligence/class-scc-entity-graph.php',
                'includes/intelligence/class-scc-ai-visibility.php',
                'includes/intelligence/class-scc-copilot.php',
                'includes/admin/class-scc-settings.php',
                'includes/admin/class-scc-admin.php',
                'includes/rest/class-scc-rest.php',
                'includes/class-scc-plugin.php',
            );
            foreach ( $paths as $path ) {
                $basename = basename( $path, '.php' );
                if ( preg_match( '/^(class|interface)-scc-(.+)$/', $basename, $match ) ) {
                    self::$map[ 'scc_' . str_replace( '-', '_', $match[2] ) ] = $path;
                }
            }
            // Interfaces and any non-conventional names must be listed here.
            self::$map['scc_ai_provider_interface'] = 'includes/ai/interface-scc-ai-provider.php';
            self::$map['scc_renderer_interface'] = 'includes/render/interface-scc-renderer.php';
            self::$registered = true;
            spl_autoload_register( array( __CLASS__, 'load' ) );
        }

        public static function load( $class ) {
            $key = strtolower( ltrim( (string) $class, '\\' ) );
            if ( isset( self::$map[ $key ] ) ) {
                require_once SCC_PLUGIN_DIR . self::$map[ $key ];
            }
        }
    }
}
