<?php
/**
 * Content Ideas view — ask for SEO-driven page suggestions, then generate them.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$examples = array(
	__( 'I have no industry pages — create industry pages optimized for CTR and top keywords', 'seo-command-center' ),
	__( 'Suggest location pages for the cities we serve', 'seo-command-center' ),
	__( 'What comparison / “vs” pages should we create to capture buyers?', 'seo-command-center' ),
	__( 'Blog articles that answer our customers’ top questions', 'seo-command-center' ),
);
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'AI Content Strategist', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Content Ideas', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Ask in plain language for SEO content ideas. Save promising ideas to Planned Content (not yet a WordPress page), or generate a draft immediately. Every saved idea is accessible under Create Content → Planned Content.', 'seo-command-center' ); ?></p>
	</div>

	<p class="scc-next-actions"><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=seo-command-center-create&tab=plan' ) ); ?>"><?php esc_html_e( 'View saved ideas → Planned Content', 'seo-command-center' ); ?></a></p>
	<div class="scc-card">
		<div class="scc-field">
			<label for="scc-ideas-q" class="scc-label"><?php esc_html_e( 'What would you like ideas for?', 'seo-command-center' ); ?></label>
			<textarea id="scc-ideas-q" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'e.g. I have no industry pages — create industry pages that are SEO-driven for CTR and top keywords', 'seo-command-center' ); ?>"></textarea>
		</div>
		<div class="scc-ideas-examples">
			<?php foreach ( $examples as $ex ) : ?>
				<button type="button" class="button button-small scc-idea-example"><?php echo esc_html( $ex ); ?></button>
			<?php endforeach; ?>
		</div>
		<div style="margin-top:10px;">
			<label><?php esc_html_e( 'How many:', 'seo-command-center' ); ?>
				<select id="scc-ideas-count">
					<option value="5">5</option>
					<option value="8" selected>8</option>
					<option value="12">12</option>
					<option value="16">16</option>
				</select>
			</label>
			<button class="button button-primary button-hero" id="scc-ideas-go"><?php esc_html_e( 'Get ideas', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-ideas-msg"></span>
		</div>
	</div>

	<div id="scc-ideas-results" hidden></div>
</div>
