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
			<form id="scc-citation-form">
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
<script>
(function(){
	var form=document.getElementById('scc-citation-form'); if(!form||typeof SCC==='undefined')return;
	var status=document.getElementById('scc-citation-status'), btn=document.getElementById('scc-citation-run');
	form.addEventListener('submit',function(e){e.preventDefault(); btn.disabled=true; status.textContent='Scanning…'; status.className='scc-inline-status';
		var payload={business_name:v('scc-citation-name'),address:v('scc-citation-address'),city:v('scc-citation-city'),state:v('scc-citation-state'),phone:v('scc-citation-phone'),website:v('scc-citation-website')};
		window.wp.apiFetch({path:'/seo-command-center/v1/citation-scan',method:'POST',data:payload}).then(function(res){render(res.data||res); status.textContent=(res.data&&res.data.cached)?'Loaded cached scan.':'Scan complete.'; status.className='scc-inline-status is-ok';}).catch(function(err){status.textContent=(err&&err.message)||'Scan failed.'; status.className='scc-inline-status is-error';}).finally(function(){btn.disabled=false;});
	});
	function v(id){var e=document.getElementById(id);return e?e.value:'';}
	function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];});}
	function mark(x){if(x===true)return '<span class="scc-badge scc-badge--ok">match</span>'; if(x===false)return '<span class="scc-badge">mismatch</span>'; return '<span class="scc-badge">n/a</span>';}
	function render(d){document.getElementById('scc-citation-results').style.display='block';document.getElementById('scc-citation-score').textContent=d.score+'/100';document.getElementById('scc-citation-found').textContent=d.summary.found;document.getElementById('scc-citation-inconsistent').textContent=d.summary.inconsistent;document.getElementById('scc-citation-missing').textContent=d.summary.not_found;document.getElementById('scc-citation-unverified').textContent=d.summary.unverified;document.getElementById('scc-citation-provider').textContent='Search provider: '+d.provider;document.getElementById('scc-citation-methodology').textContent=d.methodology||'';
		var body=document.getElementById('scc-citation-table');body.innerHTML='';(d.results||[]).forEach(function(x){var tr=document.createElement('tr'); var link=x.url?'<a href="'+esc(x.url)+'" target="_blank" rel="noopener">Open</a>':'—';tr.innerHTML='<td><strong>'+esc(x.name)+'</strong><br><span class="scc-note">'+esc(x.domain)+'</span></td><td><span class="scc-status scc-status--'+(x.status==='found'?'completed':x.status==='inconsistent'?'failed':x.status==='not_found'?'in_progress':'snoozed')+'">'+esc(x.status.replace('_',' '))+'</span></td><td>'+esc(x.confidence)+'%</td><td>'+mark(x.checks.name)+' '+mark(x.checks.city)+' '+mark(x.checks.phone)+'</td><td>'+link+'</td>';body.appendChild(tr);});
	}
})();
</script>
