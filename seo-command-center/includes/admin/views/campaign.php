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
		<h2>Google Analytics 4 — Organic Search</h2>
		<p class="description">Connect a separate read-only Google Analytics grant using the Google OAuth client from TideOrbit → Connections. Enable the Google Analytics Data API for that Google Cloud project and add the exact callback URL below under Authorized redirect URIs.</p>
		<?php if ( isset( $_GET['scc_ga4_notice'] ) ) : ?>
			<?php $ga4_notice = sanitize_key( wp_unslash( $_GET['scc_ga4_notice'] ) ); ?>
			<?php if ( 'connected' === $ga4_notice ) : ?>
				<div class="notice notice-success inline"><p>Google Analytics OAuth authorization saved. Click Refresh Analytics to verify access to your GA4 property.</p></div>
			<?php elseif ( 'failed' === $ga4_notice ) : ?>
				<div class="notice notice-error inline"><p>Google Analytics authorization was not completed. Verify the Google Cloud OAuth redirect URI and retry.</p></div>
			<?php endif; ?>
		<?php endif; ?>
		<p><strong>OAuth callback:</strong> <code><?php echo esc_html( SCC_GA4::redirect_uri() ); ?></code></p>
		<form id="scc-campaign-ga4-property">
			<p><label><strong>GA4 numeric Property ID</strong><br><input type="text" inputmode="numeric" name="property_id" class="regular-text" maxlength="20" pattern="[0-9]{4,20}" placeholder="123456789" required></label>
			<button type="submit" class="button">Save Property ID</button></p>
		</form>
		<p><strong>Connection:</strong> <span id="scc-campaign-ga4-status">Not connected</span></p>
		<p>
			<button type="button" class="button button-primary" id="scc-campaign-ga4-connect">Connect Google Analytics (read-only)</button>
			<button type="button" class="button" id="scc-campaign-ga4-refresh">Refresh Analytics for report month</button>
			<button type="button" class="button" id="scc-campaign-ga4-disconnect">Disconnect GA4</button>
		</p>
		<p class="description">Only actual Data API measurements appear as verified source data. Key events are Analytics-defined key events and do not automatically equal qualified leads. The Google Search Console connection is maintained separately.</p>
	</section>
	<section class="scc-card">
		<h2>Bing Webmaster Tools</h2>
		<p class="description">Use a read-only Bing Webmaster API key from the verified site owner's Bing Webmaster Tools → Settings → API Access. Existing key remains saved when the field is left blank.</p>
		<form id="scc-campaign-bing-connect">
			<p><label><strong>Verified site URL</strong><br><input type="url" class="regular-text" name="site_url" placeholder="https://example.com/" required></label></p>
			<p><label><strong>Bing Webmaster API key</strong><br><input type="password" class="regular-text" name="api_key" autocomplete="off" placeholder="Keep blank to retain saved key"></label></p>
			<p><button class="button button-primary" type="submit">Save Bing connection</button>
				<button type="button" class="button" id="scc-campaign-bing-refresh">Measure Bing report month</button>
				<button type="button" class="button" id="scc-campaign-bing-disconnect">Disconnect Bing</button></p>
		</form>
		<p><strong>Connection:</strong> <span id="scc-campaign-bing-status">Not connected</span></p>
		<p class="description">Bing Webmaster traffic may include multiple Bing search surfaces. It is reported separately from Google and GA4.</p>
	</section>
	<section class="scc-card">
		<h2>Microsoft Clarity</h2>
		<p class="description">Generate a Data Export API token under Clarity → Settings → Data Export. Install the Clarity tracking code using its official WordPress plugin or your existing tag manager. TideOrbit does not inject a duplicate tracker.</p>
		<form id="scc-campaign-clarity-connect">
			<p><label><strong>Clarity Project ID</strong><br><input type="text" class="regular-text" name="project_id" maxlength="32" placeholder="Project ID" required></label></p>
			<p><label><strong>Clarity Data Export API token</strong><br><input type="password" class="regular-text" name="api_token" autocomplete="off" placeholder="Keep blank to retain saved token"></label></p>
			<p><button class="button button-primary" type="submit">Save Clarity connection</button>
				<button type="button" class="button" id="scc-campaign-clarity-refresh">Capture recent Clarity insights</button>
				<button type="button" class="button" id="scc-campaign-clarity-disconnect">Disconnect Clarity</button></p>
		</form>
		<p><strong>Connection:</strong> <span id="scc-campaign-clarity-status">Not connected</span></p>
		<p class="description">Clarity exports cover only the preceding 24–72 hours and allow up to 10 API calls per project per day. TideOrbit captures one trailing 24-hour snapshot daily, keeps up to 90, and never claims they are complete monthly unique-user totals. Ensure your site's consent and privacy setup is appropriate.</p>
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
