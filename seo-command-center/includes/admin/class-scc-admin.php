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

	/** @var array<string,string> Submenu slug => section-header label, injected after registration. */
	protected $menu_sections = array();

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

		// Visible menu, organized into a few clear areas (Content / SEO / Strategy
		// / Automation / Settings) via section headers. Every screen is still here
		// — the grouping just removes the "flat wall of 15 links" sprawl.
		// Each row: slug => [ label, render_method, section_header|null ].
		$pages = array(
			self::SLUG                       => array( __( 'Dashboard', 'seo-command-center' ), 'render_dashboard', null ),

			self::SLUG . '-content-plan'     => array( __( 'Content Plan', 'seo-command-center' ), 'render_content_plan', __( 'Content', 'seo-command-center' ) ),
			self::SLUG . '-ideas'            => array( __( 'Content Ideas', 'seo-command-center' ), 'render_ideas', null ),
			self::SLUG . '-generate'         => array( __( 'Generate Content', 'seo-command-center' ), 'render_generate', null ),
			self::SLUG . '-publishing'       => array( __( 'Publishing Queue', 'seo-command-center' ), 'render_publishing', null ),

			self::SLUG . '-seo-audit'        => array( __( 'SEO Audit', 'seo-command-center' ), 'render_seo_audit', __( 'SEO', 'seo-command-center' ) ),
			self::SLUG . '-keyword-strategy' => array( __( 'Keywords', 'seo-command-center' ), 'render_keyword_strategy', null ),
			self::SLUG . '-architecture'     => array( __( 'Site Architecture', 'seo-command-center' ), 'render_architecture', null ),
			self::SLUG . '-internal-links'   => array( __( 'Internal Links', 'seo-command-center' ), 'render_internal_links', null ),
			self::SLUG . '-meta-editor'      => array( __( 'Meta Editor', 'seo-command-center' ), 'render_meta_editor', null ),

			self::SLUG . '-insights'         => array( __( 'Opportunities', 'seo-command-center' ), 'render_insights', __( 'Strategy', 'seo-command-center' ) ),
			self::SLUG . '-topical-authority'=> array( __( 'Topical Authority', 'seo-command-center' ), 'render_topical_authority', null ),
			self::SLUG . '-competitors'      => array( __( 'Competitors', 'seo-command-center' ), 'render_competitors', null ),

			self::SLUG . '-action-queue'     => array( __( 'Action Queue', 'seo-command-center' ), 'render_action_queue', __( 'Automation', 'seo-command-center' ) ),

			self::SLUG . '-templates'        => array( __( 'Templates', 'seo-command-center' ), 'render_templates', __( 'Settings', 'seo-command-center' ) ),
			self::SLUG . '-settings'         => array( __( 'Settings', 'seo-command-center' ), 'render_settings', null ),
			self::SLUG . '-connections'      => array( __( 'Connections', 'seo-command-center' ), 'render_connections', null ),
		);

		// Elementor templates only matter when Elementor is active.
		if ( class_exists( 'SCC_Elementor' ) && SCC_Elementor::is_active() ) {
			$pages[ self::SLUG . '-elementor' ] = array( __( 'Elementor Templates', 'seo-command-center' ), 'render_elementor', null );
		}

		$this->menu_sections = array();
		foreach ( $pages as $slug => $page ) {
			add_submenu_page(
				self::SLUG,
				$page[0],
				$page[0],
				$cap,
				$slug,
				array( $this, $page[1] )
			);
			if ( ! empty( $page[2] ) ) {
				$this->menu_sections[ $slug ] = $page[2];
			}
		}

		// Inject the non-clickable section headers into the submenu after all
		// items are registered (runs late on admin_menu).
		add_action( 'admin_menu', array( $this, 'inject_menu_sections' ), 999 );

		// Routable but hidden from the menu (reached from other pages / links):
		// - Site Analysis feeds the Dashboard and SEO Audit.
		// - Schema is a reference screen linked from Settings.
		$hidden = array(
			self::SLUG . '-site-analysis' => array( __( 'Site Analysis', 'seo-command-center' ), 'render_site_analysis' ),
			self::SLUG . '-schema'        => array( __( 'Schema', 'seo-command-center' ), 'render_schema_info' ),
		);
		foreach ( $hidden as $slug => $page ) {
			add_submenu_page( null, $page[0], $page[0], $cap, $slug, array( $this, $page[1] ) );
		}
	}

	/**
	 * Insert non-clickable section-header rows into our submenu so the items read
	 * as a few clear areas rather than one long flat list. Purely cosmetic — if
	 * anything is unexpected the real items still work.
	 */
	public function inject_menu_sections() {
		global $submenu;
		if ( empty( $submenu[ self::SLUG ] ) || empty( $this->menu_sections ) ) {
			return;
		}
		$cap   = SCC_Security::capability();
		$rebuilt = array();
		foreach ( $submenu[ self::SLUG ] as $item ) {
			$item_slug = isset( $item[2] ) ? $item[2] : '';
			if ( isset( $this->menu_sections[ $item_slug ] ) ) {
				// A header row: label wrapped for styling, inert slug, marker class.
				$rebuilt[] = array(
					'<span class="scc-menu-section-label">' . esc_html( $this->menu_sections[ $item_slug ] ) . '</span>',
					$cap,
					'#scc-section-' . sanitize_key( $this->menu_sections[ $item_slug ] ),
					'',
					'scc-menu-section',
				);
			}
			$rebuilt[] = $item;
		}
		$submenu[ self::SLUG ] = $rebuilt;
	}

	/**
	 * Enqueue admin assets on our pages only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		$on_plugin_page = false !== strpos( (string) $hook, self::SLUG );
		$on_editor      = in_array( $hook, array( 'post.php', 'post-new.php' ), true );
		if ( ! $on_plugin_page && ! $on_editor ) {
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

		// Provider → models map for per-operation routing dropdowns.
		$provider_models = array();
		foreach ( $this->ai->get_providers() as $pid => $prov ) {
			$provider_models[ $pid ] = array(
				'label'  => $prov->get_label(),
				'models' => $prov->list_models(),
			);
		}

		// Pass only non-secret bootstrap data + the REST nonce. Never keys.
		wp_localize_script(
			'scc-admin',
			'SCC',
			array(
				'restUrl'   => esc_url_raw( rest_url( SCC_REST::NS ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'providers' => $provider_models,
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
	 * Register meta boxes on the editor: the unified SEO panel (always) and the
	 * "designate as SEO template" box (Elementor only).
	 */
	public function register_meta_boxes() {
		$types = SCC_Analyzer::analyzable_post_types();
		foreach ( $types as $type ) {
			add_meta_box(
				'scc_seo_panel',
				__( 'SEO Command Center', 'seo-command-center' ),
				array( $this, 'render_seo_panel' ),
				$type,
				'side',
				'high'
			);
		}

		if ( ! SCC_Elementor::is_active() ) {
			return;
		}
		foreach ( array( 'page', 'post' ) as $type ) {
			add_meta_box(
				'scc_seo_template',
				__( 'SEO Command: SEO template', 'seo-command-center' ),
				array( $this, 'render_meta_box' ),
				$type,
				'side',
				'default'
			);
		}
	}

	/**
	 * Render the unified SEO Command Center editor panel.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_seo_panel( $post ) {
		$saved = get_post_meta( $post->ID, '_scc_generated', true );
		?>
		<div class="scc-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<div class="scc-panel__score">
				<span class="scc-panel__num" id="scc-panel-score">—</span>
				<span class="scc-panel__label"><?php esc_html_e( 'SEO readiness', 'seo-command-center' ); ?></span>
			</div>
			<p class="scc-note" id="scc-panel-disclaimer"><?php esc_html_e( 'Internal optimization score — not a ranking guarantee.', 'seo-command-center' ); ?></p>

			<div class="scc-panel__rows" id="scc-panel-rows"></div>

			<div class="scc-panel__actions">
				<button type="button" class="button button-primary" id="scc-panel-optimize"><?php esc_html_e( 'Optimize this page', 'seo-command-center' ); ?></button>
				<button type="button" class="button" id="scc-panel-links"><?php esc_html_e( 'Internal links', 'seo-command-center' ); ?></button>
				<button type="button" class="button" id="scc-panel-meta"><?php esc_html_e( 'Meta variants', 'seo-command-center' ); ?></button>
				<button type="button" class="button" id="scc-panel-schema"><?php esc_html_e( 'Schema', 'seo-command-center' ); ?></button>
			</div>
			<div class="scc-panel__out" id="scc-panel-out"></div>
			<span class="scc-inline-status" id="scc-panel-status"></span>
			<p class="scc-note"><?php echo $saved ? esc_html__( 'Generated by SEO Command Center.', 'seo-command-center' ) : esc_html__( 'Save the post first for the most accurate analysis.', 'seo-command-center' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render the SEO template meta box.
	 *
	 * @param WP_Post $post Post.
	 */
	public function render_meta_box( $post ) {
		if ( ! SCC_Elementor::is_elementor_post( $post->ID ) ) {
			echo '<p>' . esc_html__( 'Build this page with Elementor to use it as an SEO template.', 'seo-command-center' ) . '</p>';
			return;
		}
		$on = '1' === get_post_meta( $post->ID, '_scc_is_seo_template', true );
		wp_nonce_field( 'scc_seo_template_' . $post->ID, 'scc_seo_template_nonce' );
		echo '<label><input type="checkbox" name="scc_is_seo_template" value="1" ' . checked( $on, true, false ) . '> ';
		echo esc_html__( 'Use this Elementor page as a reusable SEO template', 'seo-command-center' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Add {{PLACEHOLDER}} tokens to text widgets, then map it to a content type under Elementor Templates.', 'seo-command-center' ) . '</p>';
	}

	/**
	 * Save the SEO template meta box.
	 *
	 * @param int $post_id Post id.
	 */
	public function save_meta_box( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['scc_seo_template_nonce'] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST['scc_seo_template_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'scc_seo_template_' . $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$on = isset( $_POST['scc_is_seo_template'] );
		SCC_Elementor::designate_template( $post_id, $on );
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
		// Top opportunities are read from cache so the dashboard stays fast; the
		// "Refresh" button recomputes on demand.
		$opportunities = class_exists( 'SCC_Opportunity_Engine' ) ? SCC_Opportunity_Engine::top( 5 ) : array();
		$this->view(
			'dashboard',
			array(
				'latest'        => $latest,
				'seo_plugin'    => SCC_SEO_Meta::label( SCC_SEO_Meta::detect() ),
				'elementor'     => defined( 'ELEMENTOR_VERSION' ),
				'usage'         => SCC_AI_Usage::month_summary(),
				'opportunities' => $opportunities,
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
	 * Handle the Google Search Console OAuth redirect (runs on admin_init).
	 *
	 * Exchanges the returned code for a refresh token, then redirects to a clean
	 * URL so a refresh does not re-process the callback.
	 */
	public function maybe_handle_gsc_oauth() {
		if ( ! is_admin() || ! SCC_Security::current_user_can() ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth state token is validated in handle_callback().
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::SLUG . '-connections' !== $page ) {
			return;
		}
		if ( ! isset( $_GET['code'] ) && ! isset( $_GET['error'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = SCC_GSC::handle_callback();
		if ( '' === $result['message'] ) {
			return;
		}
		set_transient( 'scc_gsc_notice_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-connections' ) );
		exit;
	}

	/**
	 * API Connections page.
	 */
	public function render_connections() {
		$notice = get_transient( 'scc_gsc_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'scc_gsc_notice_' . get_current_user_id() );
		}
		$this->view(
			'connections',
			array(
				'hints'         => SCC_Settings::credential_hints(),
				'providers'     => $this->ai->get_providers(),
				'lmstudio_base_url' => SCC_Settings::get( 'lmstudio_base_url', 'http://localhost:1234/v1' ),
				'lmstudio_model'    => SCC_Settings::get( 'lmstudio_model', 'local-model' ),
				'gsc_site_url'  => SCC_Settings::get( 'gsc_site_url', '' ),
				'gsc_connected' => SCC_GSC::is_connected(),
				'gsc_has_client'=> SCC_GSC::has_client(),
				'gsc_redirect'  => SCC_GSC::redirect_uri(),
				'gsc_notice'    => is_array( $notice ) ? $notice : null,
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
	 * Content Ideas — ask for SEO-driven page suggestions and generate them.
	 */
	public function render_ideas() {
		$this->view( 'ideas', array() );
	}

	/**
	 * Meta Editor — bulk edit meta titles/descriptions across pages.
	 */
	public function render_meta_editor() {
		$this->view(
			'meta-editor',
			array(
				'data' => SCC_Metadata::list_pages( array( 'paged' => 1 ) ),
			)
		);
	}

	/**
	 * Insights (health timeline, entity graph, experiments, AI visibility).
	 */
	public function render_insights() {
		if ( class_exists( 'SCC_Health_Timeline' ) ) {
			SCC_Health_Timeline::maybe_capture();
		}
		$this->view(
			'insights',
			array(
				'timeline'    => class_exists( 'SCC_Health_Timeline' ) ? SCC_Health_Timeline::timeline( 60 ) : array(),
				'entities'    => class_exists( 'SCC_Entity_Graph' ) ? SCC_Entity_Graph::build() : array(),
				'experiments' => class_exists( 'SCC_Experiments' ) ? SCC_Experiments::all() : array(),
				'change_types'=> class_exists( 'SCC_Experiments' ) ? SCC_Experiments::CHANGE_TYPES : array(),
				'ai'          => class_exists( 'SCC_AI_Visibility' ) ? SCC_AI_Visibility::status() : array(),
			)
		);
	}

	/**
	 * Action Queue (opportunities + persistent action lifecycle).
	 */
	public function render_action_queue() {
		$this->view(
			'action-queue',
			array(
				'opportunities'      => class_exists( 'SCC_Opportunity_Engine' ) ? SCC_Opportunity_Engine::all() : array(),
				'actions'            => class_exists( 'SCC_Action_Queue' ) ? SCC_Action_Queue::all() : array(),
				'safe_pending_count' => class_exists( 'SCC_Action_Queue' ) ? SCC_Action_Queue::safe_pending_count() : 0,
			)
		);
	}

	/**
	 * Topical Authority dashboard (coverage scorecard over the latest map).
	 */
	public function render_topical_authority() {
		$this->view(
			'topical-authority',
			array(
				'card' => SCC_Topical_Authority::scorecard(),
			)
		);
	}

	/**
	 * Competitor Gaps page (competitive content-gap analysis).
	 */
	public function render_competitors() {
		$this->view( 'competitors', array() );
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
	 * Publishing Queue page.
	 */
	public function render_publishing() {
		$this->view(
			'publishing',
			array(
				'queue'        => SCC_Publishing::queue(),
				'jobs'         => SCC_Jobs::status(),
				'usage'        => SCC_AI_Usage::month_summary(),
				'budget'       => (float) SCC_Settings::get( 'monthly_budget', 0 ),
				'auto_publish' => (bool) SCC_Settings::get( 'auto_publish', false ),
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
		// Existing categories for the simple form (we never create new ones here).
		$categories = array();
		if ( function_exists( 'get_categories' ) ) {
			foreach ( get_categories( array( 'hide_empty' => false, 'number' => 200 ) ) as $cat ) {
				$categories[] = array( 'id' => (int) $cat->term_id, 'name' => $cat->name );
			}
		}
		$this->view(
			'generate',
			array(
				'entries'      => array_values( $generatable ),
				'auto_publish' => (bool) SCC_Settings::get( 'auto_publish', false ),
				'categories'   => $categories,
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
				'cannibalization'      => $detector->detect(),
				'has_analysis'         => (bool) SCC_Analyzer::latest(),
				'gsc_connected'        => SCC_GSC::is_connected(),
				'dataforseo_connected' => SCC_DataForSEO::is_connected(),
			)
		);
	}

	/**
	 * Templates page (native template engine + mapping + renderers).
	 */
	public function render_templates() {
		$manager   = new SCC_Renderer_Manager();
		$renderers = array();
		foreach ( $manager->all() as $id => $r ) {
			$renderers[ $id ] = array( 'label' => $r->get_label(), 'available' => $r->is_available() );
		}
		$this->view(
			'templates',
			array(
				'templates'        => SCC_Template_Store::all_active(),
				'map'              => SCC_Template_Map::all(),
				'types'            => SCC_Template::TYPES,
				'renderers'        => $renderers,
				'default_renderer' => SCC_Settings::get( 'default_renderer', 'gutenberg' ),
				'elementor_active' => SCC_Elementor::is_active(),
				'elementor_sources'=> SCC_Elementor::is_active() ? SCC_Elementor::list_templates() : array(),
				'variables'        => SCC_Template_Variables::registry(),
				'var_categories'   => SCC_Template_Variables::categories(),
			)
		);
	}

	/**
	 * Internal Links page.
	 */
	public function render_internal_links() {
		$graph = new SCC_Link_Graph();
		$data  = $graph->build( 500 );
		$this->view(
			'internal-links',
			array(
				'totals'          => $data['totals'],
				'orphans'         => array_slice( $data['orphans'], 0, 50 ),
				'under_linked'    => array_slice( $data['under_linked'], 0, 50 ),
				'over_linked'     => array_slice( $data['over_linked'], 0, 50 ),
				'recommendations' => SCC_Link_Engine::recommendations( array( 'min_confidence' => 0, 'limit' => 200 ) ),
				'autopilot'       => (bool) SCC_Settings::get( 'autopilot_enabled', false ),
				'indexed'         => SCC_Content_Index::count(),
				'high'            => (int) SCC_Settings::get( 'link_high_confidence', 80 ),
				'history'         => SCC_Change_History::all( 0, 30 ),
			)
		);
	}

	/**
	 * Elementor Templates page.
	 */
	public function render_elementor() {
		$this->view(
			'elementor',
			array(
				'active'        => SCC_Elementor::is_active(),
				'templates'     => SCC_Elementor::is_active() ? SCC_Elementor::list_templates() : array(),
				'mappings'      => SCC_Template_Mapping::all(),
				'content_types' => SCC_Template_Mapping::CONTENT_TYPES,
				'placeholders'  => SCC_Placeholders::BUILTIN,
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
				'business'   => SCC_Schema_Engine::business(),
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
