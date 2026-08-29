<?php
/**
 * Main plugin orchestrator (singleton). Wires components to WordPress via the
 * loader.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin bootstrap.
 */
class SCC_Plugin {

	/** @var SCC_Plugin|null */
	protected static $instance = null;

	/** @var SCC_Loader */
	protected $loader;

	/** @var SCC_AI_Manager */
	protected $ai;

	/** @var SCC_Admin */
	protected $admin;

	/** @var SCC_REST */
	protected $rest;

	/** @var SCC_Jobs */
	protected $jobs;

	/**
	 * Singleton accessor.
	 *
	 * @return SCC_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->loader = new SCC_Loader();
		$this->ai     = new SCC_AI_Manager();
		$this->jobs   = new SCC_Jobs( $this->ai );
		$this->admin  = new SCC_Admin( $this->ai );
		$this->rest   = new SCC_REST( $this->ai, $this->jobs );
	}

	/**
	 * Expose the AI manager (for tests / add-ons).
	 *
	 * @return SCC_AI_Manager
	 */
	public function ai() {
		return $this->ai;
	}

	/**
	 * Register hooks and run.
	 */
	public function run() {
		// i18n.
		$this->loader->add_action( 'init', $this, 'load_textdomain' );

		// DB upgrade check on admin load.
		$this->loader->add_action( 'admin_init', $this, 'maybe_upgrade_db' );

		// Admin.
		$this->loader->add_action( 'admin_menu', $this->admin, 'register_menu' );
		$this->loader->add_action( 'admin_enqueue_scripts', $this->admin, 'enqueue_assets' );
		$this->loader->add_action( 'add_meta_boxes', $this->admin, 'register_meta_boxes' );
		$this->loader->add_action( 'save_post', $this->admin, 'save_meta_box' );

		// REST.
		$this->loader->add_action( 'rest_api_init', $this->rest, 'register_routes' );

		// Front-end: output stored JSON-LD schema for generated posts.
		$this->loader->add_action( 'wp_head', $this, 'output_schema', 20 );

		// Background jobs dispatcher.
		$this->loader->add_action( SCC_Jobs::CRON_HOOK, $this->jobs, 'run' );

		$this->loader->run();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'seo-command-center', false, dirname( SCC_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Output stored JSON-LD schema for a singular generated post.
	 */
	public function output_schema() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$stored  = get_post_meta( $post_id, '_scc_schema', true );
		if ( empty( $stored ) ) {
			return;
		}
		$nodes = json_decode( (string) $stored, true );
		if ( ! is_array( $nodes ) || empty( $nodes ) ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || is_wp_error( SCC_Schema::validate( $node ) ) ) {
				continue;
			}
			// Default encoding escapes forward slashes (\/), so a "</script>"
			// inside any string value cannot break out of this inline script.
			echo "\n" . '<script type="application/ld+json">'
				. wp_json_encode( $node )
				. '</script>' . "\n";
		}
	}

	/**
	 * Run the DB installer if the stored version is behind.
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'scc_db_version' ) !== SCC_DB_VERSION ) {
			SCC_DB::install();
			update_option( 'scc_db_version', SCC_DB_VERSION );
		}
	}
}
