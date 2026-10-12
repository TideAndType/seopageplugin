<?php
/** TideOrbit SEO Campaign Operations administrative screen. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
?>
<style>@media print { body * { visibility: hidden !important; } #scc-campaign-report, #scc-campaign-report * { visibility: visible !important; } #scc-campaign-report { position: absolute; top: 0; left: 0; width: 100%; } }</style>
<div class="wrap scc-wrap" id="scc-campaign-root">
	<div class="scc-header">
		<h1><?php esc_html_e( 'SEO Campaign Operations', 'seo-command-center' ); ?></h1>
		<p class="scc-sub"><?php esc_html_e( 'Manage your Tide & Type plan, search themes, monthly deliverables and evidence. Missing integrations never appear as measured data.', 'seo-command-center' ); ?></p>
	</div>
	<p class="scc-inline-status" id="scc-campaign-status" role="status" aria-live="polite"></p>
	<section class="scc-card">
		<h2>Campaign setup</h2>
		<form id="scc-campaign-config">
			<p><label><strong>Client or campaign</strong><br><input type="text" name="name" class="regular-text" required maxlength="180"></label></p>
			<p><label><strong>SEO plan</strong><br>
				<select name="plan" id="scc-campaign-tier">
					<option value="local">Local SEO · 1 market / 10 themes / 1 content asset</option>
					<option value="growth">SEO Growth · 3 markets / 25 themes / 3 content assets</option>
					<option value="authority">SEO Authority · 5 markets / 50 themes / 4+ content assets</option>
				</select>
			</label></p>
			<p><label><strong>Target markets</strong><br><textarea name="markets" rows="3" class="large-text" placeholder="Ormond Beach, FL&#10;Daytona Beach, FL"></textarea></label></p>
			<p><label><strong>Priority search themes</strong><br><textarea name="themes" rows="5" class="large-text" placeholder="web design ormond beach&#10;local seo agency daytona beach"></textarea></label></p>
			<p><label><strong>Competitor domains</strong><br><textarea name="competitors" rows="2" class="large-text"></textarea></label></p>
			<p><label><strong>Monthly content assets (plan minimum applies)</strong><br><input name="assets" type="number" min="1" max="30" value="1" class="small-text"></label></p>
			<p><label><strong>Business goal</strong><br><textarea name="goal" rows="2" class="large-text" placeholder="Generate qualified consulting inquiries"></textarea></label></p>
			<p><button type="submit" class="button button-primary">Save campaign and monthly plan</button></p>
		</form>
	</section>
	<section class="scc-card">
		<h2>Monthly SEO report</h2>
		<p><label>Report month <input type="month" id="scc-campaign-month"></label>
			<button type="button" class="button" id="scc-campaign-load-report">Load report</button>
			<button type="button" class="button" id="scc-campaign-print">Print / save PDF</button>
			<button type="button" class="button" id="scc-campaign-gsc">Refresh Search Console</button>
		</p>
		<p><strong>Optional paid organic rank check</strong> (up to 5 queries per request; 20/day):
			<label>Market <select id="scc-campaign-serp-market"></select></label>
			<label>Start at search theme # <input type="number" id="scc-campaign-serp-offset" value="1" min="1" max="50" class="small-text"></label>
			<button type="button" class="button" id="scc-campaign-serp-run">Run measured SERP check</button>
		</p>
		<p class="description">Uses paid DataForSEO live results, only after confirmation. This is separate from Search Console average position and from the Google Maps grid.</p>
		<div id="scc-campaign-report" aria-live="polite"></div>
	</section>
	<section class="scc-card">
		<h2>Deliverables and approvals</h2>
		<p class="description">Change status only when work is actually performed; attach evidence links when available.</p>
		<div id="scc-campaign-tasks"></div>
	</section>
	<section class="scc-card">
		<h2>Record measured outcomes</h2>
		<p class="description">For GBP, GA4, call, CRM, backlink and AI visibility information, record actual numbers from the source. Manual entries remain labeled unverified.</p>
		<form id="scc-campaign-metric">
			<p><label>Metric <select name="type">
				<option value="organic_leads">Organic leads</option>
				<option value="organic_sessions">Organic sessions</option>
				<option value="calls">Calls</option>
				<option value="appointments">Appointments</option>
				<option value="revenue">Attributed revenue</option>
				<option value="ga4_conversions">GA4 conversions</option>
				<option value="gbp_calls">Google Business Profile calls</option>
				<option value="gbp_views">Google Business Profile views</option>
				<option value="maps_visibility">Maps visibility %</option>
				<option value="organic_position">Organic rank (separate SERP measurement)</option>
				<option value="ai_citations">Measured AI citations</option>
				<option value="backlinks_earned">Earned backlinks</option>
			</select></label>
			<label>Value <input name="value" type="number" min="0" step="0.01" required class="small-text"></label>
			<label>Month <input name="month" type="month" id="scc-campaign-metric-month" required></label></p>
			<p><label>Market <input type="text" name="market" class="regular-text"></label>
				<label>Evidence URL <input name="evidence_url" type="url" class="regular-text" placeholder="https://"></label></p>
			<p><label>Notes <input name="note" type="text" class="large-text"></label></p>
			<button type="submit" class="button button-primary">Record outcome</button>
		</form>
	</section>
	<section class="scc-card">
		<h2>Local citation management</h2>
		<p class="description">Track real listing checks, claims, corrections and verification. These statuses are updated by your team; TideOrbit does not submit directory forms or claim ownership of listings.</p>
		<details><summary>Open citation inventory and status tracking</summary><div id="scc-campaign-citations"></div></details>
	</section>
	<section class="scc-card">
		<h2>Authority outreach pipeline</h2>
		<p class="description">Run a Backlink Gap scan in Growth Lab, then import identified prospects here. Nothing sends messages or creates backlinks automatically.</p>
		<p><button type="button" class="button" id="scc-campaign-outreach-sync">Import Backlink Gap opportunities</button></p>
		<details><summary>Open backlink outreach worklist</summary><div id="scc-campaign-outreach"></div></details>
	</section>
	<section class="scc-card">
		<h2>Connections and safety</h2>
		<div id="scc-campaign-connections"></div>
		<p class="description">GBP management, GA4 and CRM connectors are not installed; tracked tasks and manual results do not create API connections.</p>
		<form id="scc-campaign-public-citations">
			<p><label><input type="checkbox" name="enabled"> Enable the public citation-scan endpoint</label></p>
			<p><label><input type="checkbox" name="paid"> Also allow paid DataForSEO public requests (maximum 10/day)</label></p>
			<p class="description">Both options default off. A higher-level billing/API hard cap is still advisable; this per-site counter is not a financial guarantee under high concurrency.</p>
			<button type="submit" class="button">Save public scanner settings</button>
		</form>
	</section>
</div>
