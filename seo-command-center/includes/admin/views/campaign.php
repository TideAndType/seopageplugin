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
		<h2>Google Business Profile — performance</h2>
		<p class="description">Google's Business Profile API requires business.manage OAuth permission even for performance reports. TideOrbit uses GET-only reporting endpoints and does not edit your listing, publish posts or reply to reviews. Enable the Business Profile Performance API in Google Cloud and ensure the OAuth app has Google Business Profile API access.</p>
		<?php if ( isset( $_GET['scc_gbp_notice'] ) ) : ?>
			<?php $gbp_notice = sanitize_key( wp_unslash( $_GET['scc_gbp_notice'] ) ); ?>
			<?php if ( 'connected' === $gbp_notice ) : ?>
				<div class="notice notice-success inline"><p>Google Business Profile authorization saved. Run a performance refresh to verify the selected location and permissions.</p></div>
			<?php else : ?>
				<div class="notice notice-error inline"><p>Google Business Profile authorization failed or was declined. Check Google Cloud API access and OAuth redirect configuration.</p></div>
			<?php endif; ?>
		<?php endif; ?>
		<p><strong>OAuth callback:</strong> <code><?php echo esc_html( SCC_GBP_Performance::redirect_uri() ); ?></code></p>
		<form id="scc-campaign-gbp-location">
			<p><label><strong>Numeric Google Business Profile location ID</strong><br>
				<input type="text" name="location_id" class="regular-text" placeholder="locations/1234567890" required></label>
				<button class="button" type="submit">Save location</button></p>
		</form>
		<p><strong>Connection:</strong> <span id="scc-campaign-gbp-status">Not connected</span></p>
		<p>
			<button type="button" class="button button-primary" id="scc-campaign-gbp-connect">Authorize Profile Performance</button>
			<button type="button" class="button" id="scc-campaign-gbp-refresh">Refresh reporting month</button>
			<button type="button" class="button" id="scc-campaign-gbp-disconnect">Disconnect GBP</button>
		</p>
		<p class="description">Call clicks do not equal answered calls; impressions and clicks do not equal qualified leads. No performance is shown unless Google's API returned actual dated measurements.</p>
	</section>
	<section class="scc-card">
		<h2>GoHighLevel — opportunity attribution</h2>
		<p class="description">Create a Private Integration Token in your client's HighLevel sub-account with <code>opportunities.readonly</code> permission. TideOrbit uses only the v3 opportunities search API, does not modify CRM records and stores only aggregate counts.</p>
		<form id="scc-campaign-ghl-connect">
			<p><label><strong>HighLevel sub-account location ID</strong><br><input name="location_id" class="regular-text" type="text" maxlength="80" required></label></p>
			<p><label><strong>Optional pipeline ID</strong><br><input name="pipeline_id" class="regular-text" type="text" maxlength="80"></label></p>
			<p><label><strong>Private Integration Token</strong><br><input name="token" class="regular-text" type="password" autocomplete="off" placeholder="Leave blank to keep saved token"></label></p>
			<p><button class="button button-primary" type="submit">Save HighLevel connection</button>
				<button class="button" type="button" id="scc-campaign-ghl-refresh">Refresh reporting month</button>
				<button class="button" type="button" id="scc-campaign-ghl-disconnect">Disconnect HighLevel</button></p>
		</form>
		<p><strong>Connection:</strong> <span id="scc-campaign-ghl-status">Not connected</span></p>
		<p class="description">Source-labeled SEO opportunities are not proof of causation. Current won opportunity value is not collected revenue. For HIPAA-sensitive sites, this report intentionally does not retain any contact identifiers or messages.</p>
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
		<h2>AI citation monitoring · paid evidence checks</h2>
		<p class="description">Measure real observed citations using DataForSEO. Save your exact brand name and optional aliases first. Google AI Overview/AI Mode checks inspect one specific question and market; ChatGPT/Google dataset checks count domain citations in DataForSEO's indexed sample, not every public AI conversation. No automated billing or cron scans.</p>
		<form id="scc-campaign-ai-config">
			<p><label><strong>Business / brand name</strong><br><input name="brand" class="regular-text" required placeholder="Your business name"></label></p>
			<p><label><strong>Optional brand aliases</strong><br><textarea name="aliases" class="large-text" rows="2" placeholder="One alias per line"></textarea></label></p>
			<button type="submit" class="button">Save brand identification</button>
		</form>
		<p class="description">Target domain is automatically derived from this WordPress site: <strong id="scc-campaign-ai-domain">—</strong>.</p>
		<form id="scc-campaign-ai-check">
			<p><label><strong>Measurement surface</strong><br>
				<select name="surface" id="scc-campaign-ai-surface">
					<option value="google_ai_overview">Google AI Overview · exact live query</option>
					<option value="google_ai_mode">Google AI Mode · exact live query</option>
					<option value="chatgpt_dataset">ChatGPT · sampled indexed domain citations</option>
					<option value="google_ai_dataset">Google AI Overviews · sampled indexed domain citations</option>
				</select>
			</label></p>
			<p><label><strong>Search question / query (live query modes)</strong><br><input type="text" name="keyword" class="large-text" maxlength="180" placeholder="best managed IT support in Spokane"></label></p>
			<p><label><strong>Google search location (live query modes)</strong><br><input type="text" name="market" class="regular-text" maxlength="120" placeholder="United States"></label></p>
			<p><button type="submit" id="scc-campaign-ai-run" class="button button-primary">Run paid AI visibility check</button></p>
		</form>
		<p><strong>DataForSEO:</strong> <span id="scc-campaign-ai-status">Not connected</span>. <strong>Requests:</strong> <span id="scc-campaign-ai-quota">0 / 10</span> today.</p>
		<p class="description">Each click triggers one paid DataForSEO request. Daily site soft cap: 10 requests, without a hard billing guarantee. Some asynchronous Google AI Overviews may cost extra. Gemini and Perplexity remain unmeasured here until a supported, verified connection exists.</p>
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
		<p class="description">Only authenticated, explicitly configured integrations populate verified measurements. Google Business Profile management, Perplexity/Gemini AI citation measurements and independently verified CRM causation are not implemented. AI citation measurements are opt-in paid DataForSEO observations.</p>
		<form id="scc-campaign-public-citations">
			<p><label><input type="checkbox" name="enabled"> Enable the public citation-scan endpoint</label></p>
			<p><label><input type="checkbox" name="paid"> Also allow paid DataForSEO public requests (maximum 10/day)</label></p>
			<p class="description">Both options default off. A higher-level billing/API hard cap is still advisable; this per-site counter is not a financial guarantee under high concurrency.</p>
			<button type="submit" class="button">Save public scanner settings</button>
		</form>
	</section>
</div>
