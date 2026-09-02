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

	/** @var SCC_Autopilot */
	protected $autopilot;

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
		$this->jobs      = new SCC_Jobs( $this->ai );
		$this->autopilot = new SCC_Autopilot();
		$this->admin     = new SCC_Admin( $this->ai );
		$this->rest      = new SCC_REST( $this->ai, $this->jobs );
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
		$this->loader->add_action( 'admin_init', $this->admin, 'maybe_handle_gsc_oauth' );

		// REST.
		$this->loader->add_action( 'rest_api_init', $this->rest, 'register_routes' );

		// Front-end: output stored JSON-LD schema for generated posts.
		$this->loader->add_action( 'wp_head', $this, 'output_schema', 20 );
		$this->loader->add_action( 'wp_head', $this, 'front_styles', 8 );

		// Background jobs dispatcher.
		$this->loader->add_action( SCC_Jobs::CRON_HOOK, $this->jobs, 'run' );

		// Intelligence layer: capture a daily health snapshot and run autopilot
		// (safe, deterministic actions only) on the existing job cron.
		$this->loader->add_action( SCC_Jobs::CRON_HOOK, $this, 'run_intelligence_cron' );

		// Internal Link Autopilot: keep the index fresh + analyze new content.
		$this->loader->add_action( 'save_post', $this->autopilot, 'on_save_post', 20, 3 );
		$this->loader->add_action( 'before_delete_post', $this->autopilot, 'on_delete_post', 10, 1 );

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
	 * Minimal front-end styling for the generated FAQ accordion. Only prints on
	 * singular pages that actually contain a generated FAQ block, so it adds no
	 * weight elsewhere. Themes can override these classes.
	 */
	public function front_styles() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post( get_queried_object_id() );
		if ( ! $post || false === strpos( (string) $post->post_content, 'scc-faq' ) ) {
			return;
		}
		echo '<style id="scc-faq-style">'
			. '.scc-faq{margin:1.25rem 0;display:flex;flex-direction:column;gap:.6rem}'
			. '.scc-faq__item{border:1px solid #e2e4ea;border-radius:10px;background:#fff;overflow:hidden}'
			. '.scc-faq__q{cursor:pointer;list-style:none;padding:.9rem 1.1rem;font-weight:600;position:relative;padding-right:2.4rem}'
			. '.scc-faq__q::-webkit-details-marker{display:none}'
			. '.scc-faq__q::after{content:"+";position:absolute;right:1.1rem;top:50%;transform:translateY(-50%);font-size:1.2rem;line-height:1;color:#6b7280}'
			. '.scc-faq__item[open] .scc-faq__q::after{content:"–"}'
			. '.scc-faq__item[open] .scc-faq__q{border-bottom:1px solid #eef0f4}'
			. '.scc-faq__a{padding:.85rem 1.1rem 1rem}'
			. '.scc-faq__a>*:first-child{margin-top:0}.scc-faq__a>*:last-child{margin-bottom:0}'
			. '</style>' . "\n";
	}

	/**
	 * Intelligence-layer cron work: a daily health snapshot + autopilot pass.
	 * Both are guarded (snapshot is once/day; autopilot only runs in autopilot
	 * mode), so this is safe to fire on the hourly job cron.
	 */
	public function run_intelligence_cron() {
		if ( class_exists( 'SCC_Health_Timeline' ) ) {
			SCC_Health_Timeline::maybe_capture();
		}
		if ( class_exists( 'SCC_Action_Queue' ) ) {
			SCC_Action_Queue::run_autopilot( 5 );
		}
	}

	/**
	 * Run the DB installer if the stored version is behind.
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'scc_db_version' ) !== SCC_DB_VERSION ) {
			SCC_DB::install();
			$this->migrate_settings();
			update_option( 'scc_db_version', SCC_DB_VERSION );
		}
	}

	/**
	 * One-time settings migrations on upgrade.
	 */
	protected function migrate_settings() {
		$settings = get_option( 'scc_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}
		$changed = false;

		// Replace a retired Gemini model saved by an older version.
		if ( ! empty( $settings['gemini_model'] ) && class_exists( 'SCC_Gemini_Provider' ) ) {
			$resolved = SCC_Gemini_Provider::resolve_model( $settings['gemini_model'] );
			if ( $resolved !== $settings['gemini_model'] ) {
				$settings['gemini_model'] = $resolved;
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( 'scc_settings', $settings );
		}
	}
}
