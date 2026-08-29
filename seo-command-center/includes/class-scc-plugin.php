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
		$this->admin  = new SCC_Admin( $this->ai );
		$this->rest   = new SCC_REST( $this->ai );
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

		// REST.
		$this->loader->add_action( 'rest_api_init', $this->rest, 'register_routes' );

		$this->loader->run();
	}

	/**
	 * Load translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'seo-command-center', false, dirname( SCC_PLUGIN_BASENAME ) . '/languages' );
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
