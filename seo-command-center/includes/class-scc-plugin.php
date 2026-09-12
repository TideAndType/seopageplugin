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
   AI Elementor Layout Engine — a real design system for generated
   pages. Sections live in full-width Elementor containers; ALL the
   visual design (bands, type scale, spacing, grids, hero, cards,
   process, stats, CTA) is painted here so the look never depends on
   per-widget Elementor settings. Design tokens are set per preset
   class on each section, and the brand accent inherits the site's
   Elementor kit colour via --scc-accent.
   ============================================================ */
.scc-sec{
  --scc-accent:#4f46e5;--scc-ink:#0f172a;--scc-muted:#5b6472;--scc-border:#e6e8ef;
  --scc-space:72px;--scc-maxw:1120px;--scc-read:760px;--scc-radius:16px;--scc-h1:clamp(2.1rem,4.6vw,3.4rem);--scc-h2:clamp(1.6rem,2.6vw,2.2rem);
  padding:var(--scc-space) 24px;color:var(--scc-ink);position:relative;
}
.scc-sec__in{max-width:var(--scc-maxw);margin:0 auto}
/* Preset token overrides */
.scc-preset--professional{--scc-radius:8px;--scc-space:64px;--scc-h1:clamp(2rem,4vw,3rem)}
.scc-preset--bold{--scc-radius:22px;--scc-space:88px;--scc-h1:clamp(2.4rem,5.4vw,4rem);--scc-h2:clamp(1.8rem,3vw,2.6rem)}
/* Bands */
.scc-sec--feature{background:linear-gradient(180deg,#f4f6fc,#eef1fb)}
.scc-sec--tint{background:#f7f8fb}
.scc-sec--brand{background:var(--scc-accent);--scc-ink:#fff}
.scc-sec--brand *{color:#fff}
/* Typography */
.scc-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.8rem;font-weight:700;color:var(--scc-accent);margin:0 0 .8em}
.scc-block__title{font-size:var(--scc-h2);line-height:1.15;letter-spacing:-.02em;margin:0 0 .8em;color:var(--scc-ink)}
.scc-sec--readable{--scc-space:52px}
.scc-sec--readable .scc-sec__in{max-width:var(--scc-read)}
.scc-sec--readable h2{font-size:var(--scc-h2);letter-spacing:-.02em;margin:0 0 .5em}
.scc-sec--readable h2:first-child,.scc-sec--readable h3:first-child{margin-top:0}
.scc-sec--readable p{font-size:1.075rem;line-height:1.75;color:#33404f;margin:0 0 1.1em}
.scc-sec--readable a{color:var(--scc-accent);text-decoration:underline;text-underline-offset:2px}
/* Buttons */
.scc-btn{display:inline-block;padding:.9em 1.7em;border-radius:calc(var(--scc-radius) - 6px);background:var(--scc-accent);color:#fff!important;text-decoration:none;font-weight:600;line-height:1.2;box-shadow:0 10px 22px -12px rgba(37,99,235,.65);transition:transform .12s ease,box-shadow .12s ease,filter .12s ease}
.scc-btn:hover{transform:translateY(-2px);filter:brightness(1.05);box-shadow:0 16px 28px -12px rgba(37,99,235,.7)}
.scc-btn:focus-visible{outline:2px solid var(--scc-accent);outline-offset:3px}
/* Hero */
.scc-hero{text-align:center;max-width:760px;margin:0 auto}
.scc-hero__title{font-size:var(--scc-h1);line-height:1.08;letter-spacing:-.03em;margin:0 0 .35em;color:var(--scc-ink)}
.scc-hero__sub{font-size:1.2rem;line-height:1.65;color:var(--scc-muted);margin:0 auto 1.6em;max-width:56ch}
.scc-hero__cta .scc-btn{padding:1.05em 2.1em;font-size:1.05rem}
/* Service / feature cards */
.scc-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1.4em}
.scc-cards.scc-cards--2{grid-template-columns:repeat(auto-fit,minmax(320px,1fr))}
.scc-card-item{position:relative;border:1px solid var(--scc-border);border-radius:var(--scc-radius);padding:1.7em 1.6em;background:#fff;box-shadow:0 1px 2px rgba(16,19,28,.04),0 16px 34px -22px rgba(16,19,28,.28);transition:transform .15s ease,box-shadow .15s ease}
.scc-card-item::before{content:"";position:absolute;left:0;top:1.6em;width:4px;height:1.6em;border-radius:0 4px 4px 0;background:var(--scc-accent)}
.scc-card-item:hover{transform:translateY(-4px);box-shadow:0 1px 2px rgba(16,19,28,.05),0 26px 48px -24px rgba(16,19,28,.34)}
.scc-card-item h3{margin:0 0 .5em;font-size:1.2rem;letter-spacing:-.01em}
.scc-card-item p{color:var(--scc-muted);line-height:1.6;margin:0}
.scc-card__link{display:inline-block;margin-top:.9em;font-weight:600;color:var(--scc-accent);text-decoration:none}
/* Benefits — numbered */
.scc-benefits{list-style:none;counter-reset:b;padding:0;margin:1.2em auto 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1.4em 2em;max-width:960px}
.scc-benefits li{counter-increment:b;position:relative;padding-left:3.4em;line-height:1.55;min-height:2.4em}
.scc-benefits li::before{content:counter(b,decimal-leading-zero);position:absolute;left:0;top:-.1em;font-size:1.5rem;font-weight:800;color:var(--scc-accent);opacity:.9;letter-spacing:-.03em}
/* Stats band */
.scc-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1.6em;text-align:center}
.scc-stat__value{display:block;font-size:clamp(2.4rem,4vw,3.2rem);font-weight:800;line-height:1;letter-spacing:-.03em;color:var(--scc-accent)}
.scc-sec--brand .scc-stat__value{color:#fff}
.scc-stat__label{display:block;margin-top:.5em;color:var(--scc-muted);font-size:.98rem}
.scc-sec--brand .scc-stat__label{color:rgba(255,255,255,.88)}
/* Process — connected numbered steps */
.scc-steps{list-style:none;counter-reset:s;padding:0;margin:0 auto;max-width:1000px;display:grid;gap:1.2em}
.scc-steps--row{grid-auto-flow:column;grid-auto-columns:1fr;gap:0}
.scc-steps li{counter-increment:s;position:relative;padding:3.4em 1.2em 0;text-align:center}
.scc-steps:not(.scc-steps--row) li{text-align:left;padding:1.2em 1.4em 1.2em 4em;background:#fff;border:1px solid var(--scc-border);border-radius:var(--scc-radius)}
.scc-steps li::before{content:counter(s,decimal-leading-zero);position:absolute;top:0;left:50%;transform:translateX(-50%);width:2.6em;height:2.6em;border-radius:50%;background:var(--scc-accent);color:#fff;display:grid;place-items:center;font-weight:700;font-size:1rem}
.scc-steps:not(.scc-steps--row) li::before{left:1em;top:1.05em;transform:none;width:2.1em;height:2.1em;font-size:.95rem}
.scc-steps--row li::after{content:"";position:absolute;top:1.3em;left:calc(50% + 1.6em);right:calc(-50% + 1.6em);height:2px;background:var(--scc-border)}
.scc-steps--row li:last-child::after{display:none}
.scc-steps li strong{display:block;margin-bottom:.25em;font-size:1.08rem}
/* Related / service area */
.scc-related{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:.7em;justify-content:center}
.scc-related li a,.scc-related li{display:inline-block;padding:.6em 1.2em;border:1px solid var(--scc-border);border-radius:999px;text-decoration:none;color:var(--scc-accent);background:#fff;font-weight:500;transition:border-color .12s ease,transform .12s ease}
.scc-related li a:hover{border-color:var(--scc-accent);transform:translateY(-1px)}
/* TOC */
.scc-toc{border:1px solid var(--scc-border);border-radius:var(--scc-radius);padding:1.3em 1.5em;background:#fff;max-width:760px;margin:0 auto;box-shadow:0 12px 30px -24px rgba(16,19,28,.3)}
.scc-toc__title{font-weight:700;margin:0 0 .6em;text-transform:uppercase;letter-spacing:.08em;font-size:.82rem;color:var(--scc-muted)}
.scc-toc ul{margin:0;padding-left:1.1em;columns:2;column-gap:2.4em}
.scc-toc a{color:var(--scc-accent);text-decoration:none}
/* Split image + content */
.scc-split{display:grid;grid-template-columns:1fr 1fr;gap:2.6em;align-items:center;max-width:1040px;margin:0 auto}
.scc-split img{border-radius:var(--scc-radius);width:100%;height:auto;box-shadow:0 24px 48px -28px rgba(16,19,28,.4)}
/* Callout / highlight */
.scc-callout{max-width:var(--scc-read);margin:0 auto;background:#fff;border:1px solid var(--scc-border);border-left:4px solid var(--scc-accent);border-radius:var(--scc-radius);padding:1.3em 1.5em}
.scc-callout__label{margin:0 0 .3em;font-weight:700;color:var(--scc-accent)}
.scc-callout__body{margin:0;color:#33404f}
/* Quote / testimonial */
.scc-quote{border:0;margin:0 auto;max-width:820px;padding:0;font-size:clamp(1.3rem,2.4vw,1.7rem);line-height:1.5;text-align:center;font-weight:500}
.scc-quote cite{display:block;font-size:.95rem;color:var(--scc-muted);margin-top:.9em;font-style:normal;font-weight:600}
/* FAQ */
.scc-block--faq .scc-sec__in{max-width:820px}
/* CTA band */
.scc-block--cta .scc-cta{text-align:center;max-width:680px;margin:0 auto;background:none;border:0;padding:0}
.scc-block--cta .scc-block__title{color:#fff;margin-bottom:.35em;font-size:var(--scc-h1)}
.scc-block--cta .scc-cta p{font-size:1.2rem;margin:0 0 1.5em;color:rgba(255,255,255,.92)}
.scc-block--cta .scc-btn{background:#fff;color:var(--scc-accent)!important;padding:1.05em 2.1em;font-size:1.05rem;box-shadow:0 14px 30px -14px rgba(0,0,0,.5)}
/* Responsive */
@media(max-width:900px){
.scc-steps--row{grid-auto-flow:row;grid-auto-columns:auto;gap:1.2em}
.scc-steps--row li{text-align:left;padding:1.2em 1.4em 1.2em 4em;background:#fff;border:1px solid var(--scc-border);border-radius:var(--scc-radius)}
.scc-steps--row li::before{left:1em;top:1.05em;transform:none;width:2.1em;height:2.1em}
.scc-steps--row li::after{display:none}
}
@media(max-width:768px){
.scc-sec{--scc-space:48px}
.scc-toc ul{columns:1}
.scc-split{grid-template-columns:1fr;gap:1.6em}
}
@media(max-width:600px){
.scc-stats{grid-template-columns:1fr 1fr}
}
@media(prefers-reduced-motion:reduce){.scc-btn,.scc-card-item,.scc-related li a{transition:none!important}}
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
