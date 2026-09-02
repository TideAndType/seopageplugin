<?php
/**
 * Competitor Gaps view — competitive content-gap analysis.
 *
 * Enter competitor URLs; the plugin crawls their public pages, compares them to
 * your real site, and returns a content map of the pages you are missing. Each
 * gap can be sent straight to the Content Plan.
 *
 * @package SEO_Command_Center
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<span class="scc-phase-badge">✦ <?php esc_html_e( 'Competitive Intelligence', 'seo-command-center' ); ?></span>
		<h1><?php esc_html_e( 'Competitor Gaps', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Paste a few competitor URLs. We read their public pages, compare them to the pages your site already has, and map out the content they cover that you don’t — then you can send any gap straight to your Content Plan.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-card">
		<div class="scc-field">
			<label for="scc-comp-urls" class="scc-label"><?php esc_html_e( 'Competitor URLs', 'seo-command-center' ); ?></label>
			<textarea id="scc-comp-urls" rows="4" class="large-text code" placeholder="https://competitor-one.com/services/&#10;https://competitor-two.com/"></textarea>
			<p class="scc-note"><?php esc_html_e( 'One URL per line (or comma-separated). Up to 5. Use the most relevant pages — a services or key landing page beats the homepage. Public pages only; anything blocked by robots.txt or behind a login is skipped.', 'seo-command-center' ); ?></p>
		</div>
		<div>
			<button class="button button-primary button-hero" id="scc-comp-go"><?php esc_html_e( 'Find content gaps', 'seo-command-center' ); ?></button>
			<span class="scc-inline-status" id="scc-comp-status"></span>
		</div>
	</div>

	<div id="scc-comp-results" hidden></div>
</div>
