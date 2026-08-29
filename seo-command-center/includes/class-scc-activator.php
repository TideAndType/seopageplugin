<?php
/**
 * Plugin activation: create tables, default settings, capability.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activation routine.
 */
class SCC_Activator {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		SCC_DB::install();

		// Default settings (non-secret) if none exist.
		if ( false === get_option( 'scc_settings' ) ) {
			add_option( 'scc_settings', self::default_settings() );
		} else {
			// Merge any new defaults into existing settings.
			$existing = get_option( 'scc_settings' );
			update_option( 'scc_settings', wp_parse_args( $existing, self::default_settings() ) );
		}

		// Credentials option is autoload=no so keys are not loaded on every request.
		if ( false === get_option( 'scc_credentials' ) ) {
			add_option( 'scc_credentials', array(), '', 'no' );
		}

		update_option( 'scc_db_version', SCC_DB_VERSION );

		// Recurring safety-net dispatcher for the background job queue.
		if ( ! wp_next_scheduled( 'scc_run_jobs' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'scc_run_jobs' );
		}

		// Ensure administrators have the plugin capability (a real cap, not just the map).
		$role = get_role( 'administrator' );
		if ( $role && ! $role->has_cap( 'manage_options' ) ) {
			$role->add_cap( 'manage_options' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Default non-secret settings.
	 *
	 * @return array
	 */
	public static function default_settings() {
		return array(
			// AI.
			'default_provider'      => 'claude',
			'fallback_provider'     => '',
			'claude_model'          => 'claude-sonnet-5',
			'openai_model'          => 'gpt-4o',
			'gemini_model'          => 'gemini-2.5-flash',
			'lmstudio_model'        => 'local-model',
			'lmstudio_base_url'     => 'http://localhost:1234/v1',
			// SEO.
			'default_word_count'    => 1200,
			'max_internal_links'    => 8,
			// Internal Link Autopilot.
			'autopilot_enabled'      => false,
			'autopilot_auto_insert'  => true,
			'link_high_confidence'   => 80,
			'link_medium_confidence' => 55,
			'link_max_per_destination' => 1,
			'link_avoid_headings'    => true,
			'link_vary_anchor'       => true,
			// Metadata.
			'meta_storage'           => 'auto',
			// Rendering.
			'default_renderer'       => 'gutenberg',
			// Google Search Console property (e.g. sc-domain:example.com or https://example.com/).
			'gsc_site_url'           => '',
			// Publishing.
			'draft_by_default'      => true,
			'auto_publish'          => false,
			// Limits.
			'monthly_budget'        => 0, // 0 = no limit.
			'max_pages_per_batch'   => 25,
			'max_articles_per_batch'=> 25,
			// Housekeeping.
			'remove_data_on_uninstall' => false,
		);
	}
}
