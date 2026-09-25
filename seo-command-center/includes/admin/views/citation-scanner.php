<?php
/**
 * Citation Scanner admin view.
 *
 * @package SEO_Command_Center
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<div class="wrap scc-wrap">
	<div class="scc-header">
		<h1><?php esc_html_e( 'Citation Scanner', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Check important local citation sources for listing presence and obvious NAP inconsistencies. Unverified sources are never treated as missing.', 'seo-command-center' ); ?></p>
	</div>

	<div class="scc-columns">
		<div class="scc-card">
			<h2><?php esc_html_e( 'Business details', 'seo-command-center' ); ?></h2>
			<form id="scc-citation-form" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" method="get">
				<input type="hidden" name="page" value="seo-command-center-opportunities">
				<input type="hidden" name="tab" value="citations">
				<p><label><strong><?php esc_html_e( 'Business name', 'seo-command-center' ); ?></strong><br><input id="scc-citation-name" type="text" class="regular-text" required></label></p>
				<p><label><strong><?php esc_html_e( 'Address', 'seo-command-center' ); ?></strong><br><input id="scc-citation-address" type="text" class="regular-text"></label></p>
				<p><label><strong><?php esc_html_e( 'City', 'seo-command-center' ); ?></strong><br><input id="scc-citation-city" type="text" class="regular-text" required></label></p>
				<p><label><strong><?php esc_html_e( 'State', 'seo-command-center' ); ?></strong><br><input id="scc-citation-state" type="text" maxlength="2" style="width:90px"></label></p>
				<p><label><strong><?php esc_html_e( 'Phone', 'seo-command-center' ); ?></strong><br><input id="scc-citation-phone" type="text" class="regular-text"></label></p>
				<p><label><strong><?php esc_html_e( 'Website', 'seo-command-center' ); ?></strong><br><input id="scc-citation-website" type="url" class="regular-text"></label></p>
				<p><button type="submit" class="button button-primary button-hero" id="scc-citation-run"><?php esc_html_e( 'Run citation scan', 'seo-command-center' ); ?></button> <span class="scc-inline-status" id="scc-citation-status"></span></p>
			</form>
		</div>
		<div class="scc-card">
			<h2><?php esc_html_e( 'How the scan works', 'seo-command-center' ); ?></h2>
			<p><?php esc_html_e( 'High-value sources receive a targeted search. A broader discovery search looks for additional directory listings without generating dozens of requests.', 'seo-command-center' ); ?></p>
			<ul class="scc-actions">
				<li><?php esc_html_e( 'Found: a relevant listing was discovered.', 'seo-command-center' ); ?></li>
				<li><?php esc_html_e( 'Inconsistent: a listing was found with a conflicting phone number.', 'seo-command-center' ); ?></li>
				<li><?php esc_html_e( 'Not found: a targeted check completed without finding a listing.', 'seo-command-center' ); ?></li>
				<li><?php esc_html_e( 'Unverified: the discovery layer could not prove either result.', 'seo-command-center' ); ?></li>
			</ul>
			<p class="scc-note"><?php esc_html_e( 'When DataForSEO is connected, TideOrbit uses it for SERP discovery. Otherwise it uses a keyless DuckDuckGo HTML fallback.', 'seo-command-center' ); ?></p>
		</div>
	</div>

	<div id="scc-citation-results" style="display:none">
		<div class="scc-grid scc-stats">
			<div class="scc-stat"><div class="scc-stat__num" id="scc-citation-score">—</div><div class="scc-stat__label"><?php esc_html_e( 'Citation score', 'seo-command-center' ); ?></div></div>
			<div class="scc-stat"><div class="scc-stat__num" id="scc-citation-found">0</div><div class="scc-stat__label"><?php esc_html_e( 'Found', 'seo-command-center' ); ?></div></div>
			<div class="scc-stat scc-stat--warn"><div class="scc-stat__num" id="scc-citation-inconsistent">0</div><div class="scc-stat__label"><?php esc_html_e( 'Inconsistent', 'seo-command-center' ); ?></div></div>
			<div class="scc-stat"><div class="scc-stat__num" id="scc-citation-missing">0</div><div class="scc-stat__label"><?php esc_html_e( 'Not found', 'seo-command-center' ); ?></div></div>
			<div class="scc-stat"><div class="scc-stat__num" id="scc-citation-unverified">0</div><div class="scc-stat__label"><?php esc_html_e( 'Unverified', 'seo-command-center' ); ?></div></div>
		</div>
		<div class="scc-card">
			<div class="scc-card__head"><h2><?php esc_html_e( 'Directory results', 'seo-command-center' ); ?></h2><span class="scc-note" id="scc-citation-provider"></span></div>
			<table class="widefat striped scc-table">
				<thead><tr><th><?php esc_html_e( 'Source', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Status', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Confidence', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'NAP checks', 'seo-command-center' ); ?></th><th><?php esc_html_e( 'Listing', 'seo-command-center' ); ?></th></tr></thead>
				<tbody id="scc-citation-table"></tbody>
			</table>
			<p class="scc-note" id="scc-citation-methodology"></p>
		</div>
	</div>
</div>
