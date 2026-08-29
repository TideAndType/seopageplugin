<?php
/**
 * Admin UI: menu registration, asset enqueue, view rendering.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin controller.
 */
class SCC_Admin {

	const SLUG = 'seo-command-center';

	/** @var SCC_AI_Manager */
	protected $ai;

	/**
	 * Constructor.
	 *
	 * @param SCC_AI_Manager $ai AI manager.
	 */
	public function __construct( SCC_AI_Manager $ai ) {
		$this->ai = $ai;
	}

	/**
	 * Register admin menus.
	 */
	public function register_menu() {
		$cap = SCC_Security::capability();

		add_menu_page(
			__( 'SEO Command Center', 'seo-command-center' ),
			__( 'SEO Command', 'seo-command-center' ),
			$cap,
			self::SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-area',
			58
		);

		$pages = array(
			self::SLUG                       => array( __( 'Dashboard', 'seo-command-center' ), 'render_dashboard' ),
			self::SLUG . '-site-analysis'    => array( __( 'Site Analysis', 'seo-command-center' ), 'render_site_analysis' ),
			self::SLUG . '-keyword-strategy' => array( __( 'Keyword Strategy', 'seo-command-center' ), 'render_keyword_strategy' ),
			self::SLUG . '-architecture'     => array( __( 'Site Architecture', 'seo-command-center' ), 'render_architecture' ),
			self::SLUG . '-content-plan'     => array( __( 'Content Plan', 'seo-command-center' ), 'render_content_plan' ),
			self::SLUG . '-generate'         => array( __( 'Generate Content', 'seo-command-center' ), 'render_generate' ),
			self::SLUG . '-elementor'        => array( __( 'Elementor Templates', 'seo-command-center' ), 'render_placeholder' ),
			self::SLUG . '-internal-links'   => array( __( 'Internal Links', 'seo-command-center' ), 'render_placeholder' ),
			self::SLUG . '-seo-audit'        => array( __( 'SEO Audit', 'seo-command-center' ), 'render_seo_audit' ),
			self::SLUG . '-schema'           => array( __( 'Schema', 'seo-command-center' ), 'render_schema_info' ),
			self::SLUG . '-publishing'       => array( __( 'Publishing Queue', 'seo-command-center' ), 'render_placeholder' ),
			self::SLUG . '-settings'         => array( __( 'Settings', 'seo-command-center' ), 'render_settings' ),
			self::SLUG . '-connections'      => array( __( 'API Connections', 'seo-command-center' ), 'render_connections' ),
		);

		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				self::SLUG,
				$page[0],
				$page[0],
				$cap,
				$slug,
				array( $this, $page[1] )
			);
		}
	}

	/**
	 * Enqueue admin assets on our pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'scc-admin',
			SCC_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			SCC_VERSION
		);

		wp_enqueue_script(
			'scc-admin',
			SCC_PLUGIN_URL . 'assets/js/admin.js',
			array( 'wp-api-fetch' ),
			SCC_VERSION,
			true
		);

		// Pass only non-secret bootstrap data + the REST nonce. Never keys.
		wp_localize_script(
			'scc-admin',
			'SCC',
			array(
				'restUrl' => esc_url_raw( rest_url( SCC_REST::NS ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'analyzing' => __( 'Analyzing your site…', 'seo-command-center' ),
					'testing'   => __( 'Testing connection…', 'seo-command-center' ),
					'saved'     => __( 'Saved.', 'seo-command-center' ),
					'error'     => __( 'Something went wrong.', 'seo-command-center' ),
				),
			)
		);
	}

	/**
	 * Render a view file with escaped, prepared data.
	 *
	 * @param string $view View filename (without extension).
	 * @param array  $data Data available to the view as $data.
	 */
	protected function view( $view, array $data = array() ) {
		if ( ! SCC_Security::current_user_can() ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'seo-command-center' ) );
		}
		$file = SCC_PLUGIN_DIR . 'includes/admin/views/' . $view . '.php';
		if ( file_exists( $file ) ) {
			include $file;
		}
	}

	/**
	 * Dashboard page.
	 */
	public function render_dashboard() {
		$latest = SCC_Analyzer::latest();
		$this->view(
			'dashboard',
			array(
				'latest'     => $latest,
				'seo_plugin' => SCC_SEO_Meta::label( SCC_SEO_Meta::detect() ),
				'elementor'  => defined( 'ELEMENTOR_VERSION' ),
				'usage'      => SCC_AI_Usage::month_summary(),
			)
		);
	}

	/**
	 * Site Analysis page.
	 */
	public function render_site_analysis() {
		$this->view(
			'site-analysis',
			array(
				'latest' => SCC_Analyzer::latest(),
			)
		);
	}

	/**
	 * Settings page.
	 */
	public function render_settings() {
		$this->view(
			'settings',
			array(
				'settings'  => SCC_Settings::all(),
				'providers' => $this->ai->get_providers(),
			)
		);
	}

	/**
	 * API Connections page.
	 */
	public function render_connections() {
		$this->view(
			'connections',
			array(
				'hints'     => SCC_Settings::credential_hints(),
				'providers' => $this->ai->get_providers(),
			)
		);
	}

	/**
	 * Keyword Strategy page.
	 */
	public function render_keyword_strategy() {
		$this->view(
			'keyword-strategy',
			array(
				'strategy' => SCC_Keyword_Strategy::latest(),
			)
		);
	}

	/**
	 * Site Architecture page.
	 */
	public function render_architecture() {
		$strategy = SCC_Keyword_Strategy::latest();
		$tree     = null;
		if ( $strategy && ! empty( $strategy['map_data'] ) ) {
			$builder = new SCC_Architecture();
			$tree    = $builder->build( $strategy['map_data'] );
		}
		$this->view(
			'architecture',
			array(
				'strategy' => $strategy,
				'tree'     => $tree,
			)
		);
	}

	/**
	 * Content Plan page.
	 */
	public function render_content_plan() {
		$this->view(
			'content-plan',
			array(
				'entries'  => SCC_Content_Plan::all(),
				'statuses' => SCC_Content_Plan::STATUSES,
			)
		);
	}

	/**
	 * Generate Content page.
	 */
	public function render_generate() {
		$entries = SCC_Content_Plan::all();
		// Generatable = not yet turned into a post.
		$generatable = array_filter(
			$entries,
			function ( $e ) {
				return empty( $e['post_id'] ) && in_array( $e['status'], array( 'recommended', 'approved', 'review', 'needs_update' ), true );
			}
		);
		$this->view(
			'generate',
			array(
				'entries'      => array_values( $generatable ),
				'auto_publish' => (bool) SCC_Settings::get( 'auto_publish', false ),
			)
		);
	}

	/**
	 * SEO Audit page (cannibalization for now; expands in later phases).
	 */
	public function render_seo_audit() {
		$detector = new SCC_Cannibalization();
		$this->view(
			'seo-audit',
			array(
				'cannibalization' => $detector->detect(),
				'has_analysis'    => (bool) SCC_Analyzer::latest(),
			)
		);
	}

	/**
	 * Schema info page.
	 */
	public function render_schema_info() {
		$this->view(
			'schema',
			array(
				'seo_plugin' => SCC_SEO_Meta::label( SCC_SEO_Meta::detect() ),
				'allowed'    => SCC_Schema::ALLOWED,
			)
		);
	}

	/**
	 * Placeholder page for not-yet-built features (honest, not fake).
	 */
	public function render_placeholder() {
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$map   = array(
			self::SLUG . '-keyword-strategy' => array( __( 'Keyword Strategy', 'seo-command-center' ), 2 ),
			self::SLUG . '-architecture'     => array( __( 'Site Architecture', 'seo-command-center' ), 2 ),
			self::SLUG . '-content-plan'     => array( __( 'Content Plan', 'seo-command-center' ), 2 ),
			self::SLUG . '-generate'         => array( __( 'Generate Content', 'seo-command-center' ), 3 ),
			self::SLUG . '-elementor'        => array( __( 'Elementor Templates', 'seo-command-center' ), 4 ),
			self::SLUG . '-internal-links'   => array( __( 'Internal Links', 'seo-command-center' ), 5 ),
			self::SLUG . '-seo-audit'        => array( __( 'SEO Audit', 'seo-command-center' ), 3 ),
			self::SLUG . '-schema'           => array( __( 'Schema', 'seo-command-center' ), 3 ),
			self::SLUG . '-publishing'       => array( __( 'Publishing Queue', 'seo-command-center' ), 7 ),
		);
		$title = isset( $map[ $page ][0] ) ? $map[ $page ][0] : __( 'Coming soon', 'seo-command-center' );
		$phase = isset( $map[ $page ][1] ) ? $map[ $page ][1] : 2;
		$this->view( 'placeholder', array( 'title' => $title, 'phase' => $phase ) );
	}
}
