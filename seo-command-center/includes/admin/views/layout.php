<?php
/**
 * Elementor Layout Engine view.
 *
 * A focused, low-text screen: propose a block layout for a generated post,
 * preview its structure, reorder/remove blocks, then build the Elementor page.
 *
 * @package SEO_Command_Center
 * @var array $data { post_id, post_title, edit_url, elementor_active }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id  = (int) ( $data['post_id'] ?? 0 );
$title    = (string) ( $data['post_title'] ?? '' );
$el_ok    = ! empty( $data['elementor_active'] );
?>
<div class="wrap scc-wrap scc-layout" id="scc-layout" data-post="<?php echo esc_attr( $post_id ); ?>">
	<div class="scc-header">
		<span class="scc-phase-badge"><?php esc_html_e( 'Layout Engine', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Build Elementor layout', 'seo-command-center' ); ?></h1>
		<p class="scc-sub">
			<?php
			echo $title
				? esc_html( sprintf( /* translators: %s: post title */ __( 'Turn “%s” into a structured Elementor page.', 'seo-command-center' ), $title ) )
				: esc_html__( 'Turn a generated draft into a structured Elementor page.', 'seo-command-center' );
			?>
		</p>
	</div>

	<?php if ( $post_id <= 0 ) : ?>
		<div class="scc-card scc-empty">
			<div class="scc-empty__icon" aria-hidden="true">🧩</div>
			<h2><?php esc_html_e( 'Pick a draft first', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'Open this from a generated draft (Create → Recently generated) to build its Elementor layout.', 'seo-command-center' ); ?></p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-generate' ) ); ?>"><?php esc_html_e( 'Go to Create', 'seo-command-center' ); ?></a>
		</div>
	<?php elseif ( ! $el_ok ) : ?>
		<div class="scc-card scc-empty">
			<div class="scc-empty__icon" aria-hidden="true">🧩</div>
			<h2><?php esc_html_e( 'Elementor isn’t active', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'The Layout Engine builds Elementor pages. Activate Elementor (free) to use it. Your content and SEO features are unaffected.', 'seo-command-center' ); ?></p>
		</div>
	<?php else : ?>
		<div class="scc-card">
			<div class="scc-card__head">
				<h2><?php esc_html_e( 'Your page structure', 'seo-command-center' ); ?></h2>
				<span>
					<label class="scc-toggle" title="<?php esc_attr_e( 'Use AI to choose the section order when a provider is configured', 'seo-command-center' ); ?>">
						<input type="checkbox" id="scc-layout-ai"> <?php esc_html_e( 'Use AI', 'seo-command-center' ); ?>
					</label>
					<button class="button" id="scc-layout-regen"><?php esc_html_e( 'Regenerate', 'seo-command-center' ); ?></button>
				</span>
			</div>
			<span class="scc-inline-status" id="scc-layout-msg"></span>
			<p class="scc-note" id="scc-layout-meta" hidden></p>
			<div id="scc-layout-preview" class="scc-layout-preview"></div>
			<div class="scc-layout-actions">
				<button class="button button-primary button-hero" id="scc-layout-apply" disabled><?php esc_html_e( 'Generate Elementor Page', 'seo-command-center' ); ?></button>
				<span class="scc-inline-status" id="scc-layout-apply-msg"></span>
			</div>
		</div>

		<details class="scc-card">
			<summary class="scc-adv-summary"><?php esc_html_e( 'How this works', 'seo-command-center' ); ?></summary>
			<p class="scc-note"><?php esc_html_e( 'The engine picks from a fixed library of Elementor blocks based on your content type and search intent. The AI (optional) only chooses the order — it never writes Elementor code. Blocks with no matching content are skipped, your H1/H2 structure and internal links are preserved, and the page stays fully editable in Elementor.', 'seo-command-center' ); ?></p>
		</details>
	<?php endif; ?>
</div>
