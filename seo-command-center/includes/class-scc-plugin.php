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
		if ( ! $post ) {
			return;
		}
		$content = (string) $post->post_content;
		$needed  = ( false !== strpos( $content, 'scc-content' ) )
			|| ( false !== strpos( $content, 'scc-faq' ) )
			|| ( '' !== (string) get_post_meta( $post->ID, '_scc_generated', true ) );
		if ( ! $needed ) {
			return;
		}

		echo '<style id="scc-content-style">' . self::front_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * The front-end presentation CSS for generated content. Scoped to
	 * .scc-content (plus the standalone .scc-faq widget), inherits the theme's
	 * fonts/colours via CSS custom properties, fully responsive, accessible and
	 * CSS-only (no JavaScript). Filterable via scc_front_css.
	 *
	 * @return string
	 */
	protected static function front_css() {
		$css = <<<'CSS'
.scc-content{--scc-accent:var(--wp--preset--color--primary,#2563eb);--scc-border:rgba(2,6,23,.12);--scc-soft:rgba(2,6,23,.04);--scc-muted:rgba(2,6,23,.62);--scc-card:#fff;--scc-radius:14px}
.scc-content{max-width:100%;}
.scc-content>*:first-child{margin-top:0}
.scc-content h2{margin-top:2.4em;margin-bottom:.6em;padding-top:1.1em;border-top:1px solid var(--scc-border);line-height:1.25}
.scc-content h2:first-of-type{border-top:0;padding-top:0;margin-top:1.4em}
.scc-content h3{margin-top:1.6em;margin-bottom:.5em;line-height:1.3}
.scc-content p{line-height:1.75;margin:0 0 1.05em}
.scc-content ul,.scc-content ol{line-height:1.7;margin:0 0 1.15em;padding-left:1.3em}
.scc-content li{margin:.35em 0}
.scc-content a{text-decoration:underline;text-underline-offset:2px}
.scc-content :where(a,summary,[tabindex]):focus-visible{outline:2px solid var(--scc-accent);outline-offset:2px;border-radius:4px}
/* Callouts */
.scc-callout{margin:1.5em 0;padding:1em 1.15em 1.05em;border:1px solid var(--scc-border);border-left:4px solid var(--scc-accent);border-radius:var(--scc-radius);background:var(--scc-soft)}
.scc-callout__label{margin:0 0 .25em;font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--scc-accent)}
.scc-callout__body{margin:0}
.scc-callout--tip{border-left-color:#16a34a}.scc-callout--tip .scc-callout__label{color:#16a34a}
.scc-callout--important{border-left-color:#d97706}.scc-callout--important .scc-callout__label{color:#b45309}
.scc-callout--warning{border-left-color:#dc2626}.scc-callout--warning .scc-callout__label{color:#dc2626}
.scc-callout--key{border-left-color:var(--scc-accent)}
.scc-callout--info{border-left-color:#0ea5e9}.scc-callout--info .scc-callout__label{color:#0284c7}
.scc-callout--note{border-left-color:#64748b}.scc-callout--note .scc-callout__label{color:#475569}
/* Key takeaways card */
.scc-takeaways{margin:1.6em 0;padding:1.15em 1.3em;border:1px solid var(--scc-border);border-radius:var(--scc-radius);background:var(--scc-card);box-shadow:0 1px 2px rgba(2,6,23,.04)}
.scc-takeaways__title{margin:0 0 .6em;font-size:.8rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--scc-muted)}
.scc-takeaways ul{margin:0;padding:0;list-style:none;display:grid;gap:.55em}
.scc-takeaways li{position:relative;padding-left:1.7em;margin:0}
.scc-takeaways li::before{content:"";position:absolute;left:0;top:.36em;width:1.05em;height:1.05em;border-radius:50%;background:var(--scc-accent);
 -webkit-mask:no-repeat center/.7em url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath fill='white' d='M9 16.2l-3.5-3.5L4 14.2l5 5 11-11-1.5-1.5z'/%3E%3C/svg%3E");mask:no-repeat center/.7em url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'%3E%3Cpath d='M9 16.2l-3.5-3.5L4 14.2l5 5 11-11-1.5-1.5z'/%3E%3C/svg%3E")}
/* Process steps */
.scc-steps{list-style:none;counter-reset:scc-step;margin:1.6em 0;padding:0;display:grid;gap:.9em}
.scc-steps>li{counter-increment:scc-step;position:relative;padding:1em 1.1em 1em 3.6em;border:1px solid var(--scc-border);border-radius:var(--scc-radius);background:var(--scc-card)}
.scc-steps>li::before{content:counter(scc-step,decimal-leading-zero);position:absolute;left:1.05em;top:.9em;font-weight:700;font-size:.95rem;color:#fff;background:var(--scc-accent);width:1.9em;height:1.9em;display:flex;align-items:center;justify-content:center;border-radius:9px;font-variant-numeric:tabular-nums}
/* Timeline */
.scc-timeline{list-style:none;margin:1.6em 0;padding:0 0 0 1.4em;border-left:2px solid var(--scc-border)}
.scc-timeline>li{position:relative;margin:0 0 1.1em;padding:0 0 0 1.1em}
.scc-timeline>li::before{content:"";position:absolute;left:calc(-1.4em - 1px);top:.45em;width:.8em;height:.8em;border-radius:50%;background:var(--scc-accent);box-shadow:0 0 0 4px var(--scc-soft);transform:translateX(-50%)}
/* Stat cards */
.scc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.9em;margin:1.4em 0}
.scc-stat{display:flex;flex-direction:column;gap:.15em;padding:1.1em;border:1px solid var(--scc-border);border-radius:var(--scc-radius);background:var(--scc-card);text-align:center}
.scc-stat__value{font-size:1.6rem;font-weight:800;line-height:1.1;color:var(--scc-accent)}
.scc-stat__label{font-size:.86rem;color:var(--scc-muted)}
/* Responsive tables */
.scc-table{margin:1.6em 0}
.scc-table__scroll{overflow-x:auto;border:1px solid var(--scc-border);border-radius:var(--scc-radius)}
.scc-table table{width:100%;border-collapse:collapse;margin:0;min-width:32rem}
.scc-table th,.scc-table td{padding:.7em .9em;text-align:left;border-bottom:1px solid var(--scc-border);vertical-align:top}
.scc-table thead th{background:var(--scc-soft);font-weight:700}
.scc-table tbody tr:last-child td{border-bottom:0}
.scc-table tbody tr:nth-child(even) td{background:rgba(2,6,23,.015)}
/* CTA block (native posts) */
.scc-cta{margin:2em 0 .5em;padding:1.4em 1.5em;border-radius:14px;background:var(--scc-soft,rgba(2,6,23,.04));border:1px solid var(--scc-border,rgba(2,6,23,.12));display:flex;flex-wrap:wrap;align-items:center;gap:1em;justify-content:space-between}
.scc-cta__text{margin:0;font-weight:600;font-size:1.05rem}
.scc-cta__btn{display:inline-block;padding:.7em 1.3em;border-radius:10px;background:var(--scc-accent,#2563eb);color:#fff;text-decoration:none;font-weight:600}
.scc-section-title{margin-top:2em}
/* FAQ accordion (also used standalone by the {{FAQ}} widget) */
.scc-faq{margin:1.5em 0;display:flex;flex-direction:column;gap:.6rem}
.scc-faq__item{border:1px solid var(--scc-border,#e2e4ea);border-radius:10px;background:var(--scc-card,#fff);overflow:hidden}
.scc-faq__q{cursor:pointer;list-style:none;padding:.9rem 1.1rem;font-weight:600;position:relative;padding-right:2.4rem}
.scc-faq__q::-webkit-details-marker{display:none}
.scc-faq__q::after{content:"+";position:absolute;right:1.1rem;top:50%;transform:translateY(-50%);font-size:1.2rem;line-height:1;color:#6b7280}
.scc-faq__item[open] .scc-faq__q::after{content:"\2013"}
.scc-faq__item[open] .scc-faq__q{border-bottom:1px solid var(--scc-border,#eef0f4)}
.scc-faq__a{padding:.85rem 1.1rem 1rem}
.scc-faq__a>*:first-child{margin-top:0}.scc-faq__a>*:last-child{margin-bottom:0}
.scc-faq__q:focus-visible{outline:2px solid var(--scc-accent,#2563eb);outline-offset:-2px}
/* ============================================================
   AI Elementor Layout Engine — designed page sections.
   Applies to the native fallback (post_content) AND the HTML
   widgets inside the Elementor page. Section BANDS (background +
   full-width) come from the Elementor container; here we style the
   content and, for the native copy, paint the band via .scc-sec.
   ============================================================ */
.scc-block{margin:0}
.scc-sec{padding:64px 0}
.scc-sec__in{max-width:1140px;margin:0 auto;padding:0 20px}
.scc-sec--feature{background:#f3f5fb}
.scc-sec--tint{background:#f7f8fb}
.scc-sec--brand{background:var(--scc-accent,#4f46e5)}
.scc-sec--brand,.scc-sec--brand *{color:#fff}
.scc-block__title{font-size:1.9rem;line-height:1.2;letter-spacing:-.02em;margin:0 0 .8em;text-align:center}
.scc-btn{display:inline-block;padding:.85em 1.6em;border-radius:10px;background:var(--scc-accent,#4f46e5);color:#fff!important;text-decoration:none;font-weight:600;box-shadow:0 8px 20px -10px rgba(37,99,235,.6);transition:transform .12s ease,box-shadow .12s ease}
.scc-btn:hover{transform:translateY(-1px);box-shadow:0 12px 24px -10px rgba(37,99,235,.7)}
.scc-btn:focus-visible{outline:2px solid #fff;outline-offset:2px}
/* Hero */
.scc-hero{text-align:center;max-width:820px;margin:0 auto}
.scc-hero__title{font-size:clamp(2rem,4vw,3.1rem);line-height:1.1;letter-spacing:-.03em;margin:0 0 .4em}
.scc-hero__sub{font-size:1.2rem;line-height:1.6;color:var(--scc-muted,#5b6472);margin:0 auto 1.4em;max-width:60ch}
/* Cards */
.scc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1.2em}
.scc-card-item{border:1px solid var(--scc-border,#e6e8ef);border-radius:16px;padding:1.5em;background:#fff;box-shadow:0 1px 2px rgba(16,19,28,.04),0 12px 28px -18px rgba(16,19,28,.22);transition:transform .14s ease,box-shadow .14s ease}
.scc-card-item:hover{transform:translateY(-3px);box-shadow:0 1px 2px rgba(16,19,28,.05),0 20px 40px -20px rgba(16,19,28,.3)}
.scc-card-item h3{margin:0 0 .5em;font-size:1.2rem}
.scc-card__link{display:inline-block;margin-top:.6em;font-weight:600;color:var(--scc-accent,#4f46e5);text-decoration:none}
.scc-card__link::after{content:" \2192"}
/* Benefits */
.scc-benefits{list-style:none;padding:0;margin:1em auto 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:.8em 1.6em;max-width:900px}
.scc-benefits li{position:relative;padding-left:2em;line-height:1.5}
.scc-benefits li::before{content:"\2713";position:absolute;left:0;top:.05em;width:1.35em;height:1.35em;border-radius:50%;background:var(--scc-accent,#4f46e5);color:#fff;font-size:.8em;display:grid;place-items:center;font-weight:700}
/* Stats */
.scc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1.2em;text-align:center}
.scc-stat{padding:1em}
.scc-stat__value{display:block;font-size:2.6rem;font-weight:800;line-height:1;letter-spacing:-.02em;color:var(--scc-accent,#4f46e5)}
.scc-sec--brand .scc-stat__value{color:#fff}
.scc-stat__label{display:block;margin-top:.4em;color:var(--scc-muted,#6b7280);font-size:.95rem}
.scc-sec--brand .scc-stat__label{color:rgba(255,255,255,.85)}
/* Process steps */
.scc-steps{list-style:none;counter-reset:s;padding:0;margin:0;display:grid;gap:1em;max-width:820px;margin:0 auto}
.scc-steps li{counter-increment:s;position:relative;padding:1.1em 1.2em 1.1em 3.6em;border:1px solid var(--scc-border,#e6e8ef);border-radius:14px;background:#fff}
.scc-steps li::before{content:counter(s);position:absolute;left:1em;top:1.05em;width:1.8em;height:1.8em;border-radius:50%;background:var(--scc-accent,#4f46e5);color:#fff;display:grid;place-items:center;font-weight:700}
/* Related / service area */
.scc-related{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:.6em;justify-content:center}
.scc-related li a,.scc-related li{display:inline-block;padding:.5em 1.1em;border:1px solid var(--scc-border,#e6e8ef);border-radius:999px;text-decoration:none;color:var(--scc-accent,#4f46e5);background:#fff}
/* TOC */
.scc-toc{border:1px solid var(--scc-border,#e6e8ef);border-radius:14px;padding:1.2em 1.4em;background:#fff;max-width:760px;margin:0 auto}
.scc-toc__title{font-weight:700;margin:0 0 .5em}
.scc-toc ul{margin:0;padding-left:1.1em;columns:2;gap:2em}
/* Split / image + content */
.scc-split{display:grid;grid-template-columns:1fr 1fr;gap:2em;align-items:center;max-width:1000px;margin:0 auto}
.scc-split img{border-radius:16px;width:100%;height:auto}
/* Quote */
.scc-quote{border-left:4px solid var(--scc-accent,#4f46e5);margin:0 auto;max-width:760px;padding:.6em 0 .6em 1.4em;font-size:1.3rem;line-height:1.5}
.scc-quote cite{display:block;font-size:.95rem;color:var(--scc-muted,#6b7280);margin-top:.6em;font-style:normal}
/* CTA band */
.scc-block--cta .scc-cta{display:block;text-align:center;background:none;border:0;padding:0;margin:0}
.scc-block--cta .scc-cta .scc-block__title{color:#fff;margin-bottom:.4em}
.scc-block--cta .scc-cta p{font-size:1.15rem;margin:0 0 1.2em;color:rgba(255,255,255,.9)}
.scc-block--cta .scc-btn{background:#fff;color:var(--scc-accent,#4f46e5)!important;box-shadow:0 10px 24px -12px rgba(0,0,0,.45)}
/* The full-body content block keeps normal article width */
.scc-block--content .scc-sec__in,.scc-sec--readable .scc-sec__in{max-width:820px}
.scc-sec--readable h2:first-child,.scc-sec--readable h3:first-child{margin-top:0}
.scc-sec--readable{padding:48px 0}
@media(max-width:768px){
.scc-sec{padding:44px 0}
.scc-toc ul{columns:1}
.scc-split{grid-template-columns:1fr}
}
@media(max-width:600px){
.scc-content h2{margin-top:2em}
.scc-stats{grid-template-columns:1fr 1fr}
.scc-cta{flex-direction:column;align-items:flex-start}
}
@media(prefers-reduced-motion:reduce){.scc-content *,.scc-card-item,.scc-btn{transition:none!important;animation:none!important}}
CSS;

		/**
		 * Filter the front-end presentation CSS.
		 *
		 * @param string $css The CSS.
		 */
		return (string) apply_filters( 'scc_front_css', $css );
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
		// Re-run the installer when the version changed OR when a core table is
		// missing (self-heals an install whose tables failed to create — e.g. an
		// older schema a strict-mode MySQL rejected).
		if ( get_option( 'scc_db_version' ) !== SCC_DB_VERSION || ! SCC_DB::core_tables_present() ) {
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
