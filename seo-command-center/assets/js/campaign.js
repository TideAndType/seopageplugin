/* TideOrbit SEO Campaign Operations. No external JS libraries or fake metrics. */
(function () {
	'use strict';
	if (!window.wp || !wp.apiFetch || !window.sccCampaign) return;
	var api = wp.apiFetch;
	api.use(api.createNonceMiddleware(sccCampaign.nonce));
	var base = sccCampaign.api;
	var root = document.getElementById('scc-campaign-root');
	if (!root) return;
	var state = null;
	var currentReport = null;
	var byId = function (id) { return document.getElementById(id); };
	var node = function (tag, parent, value, className) {
		var el = document.createElement(tag);
		if (value !== undefined && value !== null) el.textContent = String(value);
		if (className) el.className = className;
		if (parent) parent.appendChild(el);
		return el;
	};
	var clear = function (el) { while (el.firstChild) el.removeChild(el.firstChild); };
	var fmt = function (n) { return typeof n === 'number' ? n.toLocaleString() : 'Not measured'; };
	var lines = function (s) { return (s || '').split(/[\r\n,]+/).map(function (v) { return v.trim(); }).filter(Boolean); };
	var nowMonth = function () {
		var d = new Date(), m = String(d.getMonth() + 1);
		return d.getFullYear() + '-' + (m.length < 2 ? '0' + m : m);
	};
	var month = nowMonth();
	var flash = function (message, error) {
		var box = byId('scc-campaign-status');
		box.textContent = message;
		box.className = 'scc-inline-status ' + (error ? 'is-error' : 'is-ok');
	};
	var request = function (path, method, data) {
		var opts = { path: base + path };
		if (method) opts.method = method;
		if (data) opts.data = data;
		return api(opts);
	};
	var showFailure = function (err) { flash(err && err.message ? err.message : 'Unable to complete the request.', true); };
	function fillConfig(c) {
		var form = byId('scc-campaign-config');
		if (!form || !c) return;
		['name', 'plan', 'assets', 'goal'].forEach(function (key) {
			if (form.elements[key] && c[key] !== undefined) form.elements[key].value = c[key];
		});
		['markets', 'themes', 'competitors'].forEach(function (key) {
			if (form.elements[key]) form.elements[key].value = (c[key] || []).join('\n');
		});
	}
	function renderTasks(report) {
		var host = byId('scc-campaign-tasks');
		clear(host);
		var tasks = report.deliverables.tasks || [];
		if (!tasks.length) { node('p', host, 'Save a campaign to create monthly tasks.'); return; }
		node('p', host, report.deliverables.completed + ' of ' + report.deliverables.total + ' completed this month.');
		var table = node('table', host, null, 'widefat striped');
		var head = node('thead', table), tr = node('tr', head);
		['Deliverable', 'Status', 'Notes', 'Evidence', 'Action'].forEach(function (label) { node('th', tr, label); });
		var body = node('tbody', table);
		tasks.forEach(function (t) {
			var row = node('tr', body);
			node('td', row, t.title);
			var statusCell = node('td', row), status = node('select', statusCell);
			['todo', 'in_progress', 'review', 'done', 'blocked'].forEach(function (value) {
				var option = node('option', status, value.replace(/_/g, ' '));
				option.value = value;
			});
			status.value = t.status;
			var notesCell = node('td', row), notes = node('input', notesCell);
			notes.type = 'text'; notes.value = t.notes || ''; notes.style.maxWidth = '220px';
			var evidenceCell = node('td', row), evidence = node('input', evidenceCell);
			evidence.type = 'url'; evidence.value = t.evidence_url || ''; evidence.placeholder = 'https://'; evidence.style.maxWidth = '220px';
			var action = node('td', row), save = node('button', action, 'Save', 'button');
			save.type = 'button';
			save.addEventListener('click', function () {
				save.disabled = true;
				request('/task', 'POST', { id: t.id, status: status.value, notes: notes.value, evidence_url: evidence.value })
					.then(function () { flash('Deliverable updated.'); loadReport(); })
					.catch(showFailure).finally(function () { save.disabled = false; });
			});
		});
	}

	function renderPipeline(hostId, entries, statuses, route, labelKey) {
		var host = byId(hostId);
		if (!host) return;
		clear(host);
		if (!entries.length) {
			node('p', host, 'No tracked items yet. Save a campaign or import opportunities.');
			return;
		}
		node('p', host, entries.length + ' tracked opportunities. Statuses are entered manually; do not imply automatic verification.');
		var table = node('table', host, null, 'widefat striped');
		var head = node('tr', node('thead', table));
		['Directory / domain', 'Status', 'Notes', 'Evidence', 'Action'].forEach(function (h) { node('th', head, h); });
		var body = node('tbody', table);
		entries.forEach(function (item) {
			var tr = node('tr', body);
			var title = node('td', tr);
			node('strong', title, item[labelKey] || item.domain);
			if (item.priority) node('div', title, item.priority + ' · ' + (item.type || ''));
			var status = node('select', node('td', tr));
			statuses.forEach(function (key) {
				var opt = node('option', status, key.replace(/_/g, ' ')); opt.value = key;
			});
			status.value = item.status;
			var notes = node('input', node('td', tr));
			notes.type = 'text'; notes.value = item.notes || ''; notes.style.maxWidth = '180px';
			var evidence = node('input', node('td', tr));
			evidence.type = 'url'; evidence.value = item.evidence_url || ''; evidence.style.maxWidth = '180px';
			evidence.placeholder = 'https://';
			var button = node('button', node('td', tr), 'Save', 'button'); button.type = 'button';
			button.addEventListener('click', function () {
				button.disabled = true;
				request(route, 'POST', { id: item.id, status: status.value, notes: notes.value, evidence_url: evidence.value })
					.then(function () { flash('Worklist updated.'); loadReport(); })
					.catch(showFailure).finally(function () { button.disabled = false; });
			});
		});
	}
	function renderReport(r) {
		currentReport = r;
		var host = byId('scc-campaign-report'); clear(host);
		var c = r.campaign || {};
		node('h3', host, (c.name || 'Campaign') + ' · ' + r.month);
		var dl = r.deliverables || {};
		node('p', host, 'Completed deliverables: ' + dl.completed + ' / ' + dl.total);
		var aggregates = r.ai_visibility_summary || [];
		node('h4', host, 'AI visibility scorecard · by platform');
		if (!aggregates.length) {
			node('p', host, 'No verified AI provider observations saved for this reporting month.');
		} else {
			var overviewTable = node('table', host, null, 'widefat striped');
			var overviewHead = node('tr', node('thead', overviewTable));
			['Platform', 'Answers observed', 'Brand mentions', 'Citations / verified answers', 'Citation status unknown', 'Indexed sample citations'].forEach(function (h) { node('th', overviewHead, h); });
			var overviewBody = node('tbody', overviewTable);
			aggregates.forEach(function (summary) {
				var row = node('tr', overviewBody);
				[
					summary.surface,
					summary.answer_observed + ' / ' + summary.observations + ' checks',
					summary.own_brand_mentioned + ' observed',
					summary.own_domain_cited + ' / ' + summary.confirmed_source_checks,
					summary.unverified_citation_status,
					summary.indexed_samples ? fmt(summary.latest_indexed_citation_count) + ' · sample, not impressions' : 'Not applicable'
				].forEach(function (t) { node('td', row, t); });
			});
		}
		var ai = r.ai_citation_observations || [];
		node('h4', host, 'Observed AI visibility and citations');
		if (!ai.length) {
			node('p', host, 'No paid AI citation measurements recorded for this month; unavailable does not mean absent.');
		} else {
			var ait = node('table', host, null, 'widefat striped'), aih = node('tr', node('thead', ait));
			['Captured', 'Source/surface', 'Question or dataset', 'Brand mentioned', 'Domain cited', 'Citation count', 'Evidence'].forEach(function (x) { node('th', aih, x); });
			var aib = node('tbody', ait);
			ai.slice(-40).forEach(function (a) {
				var tr = node('tr', aib);
				node('td', tr, a.captured_at);
				node('td', tr, a.surface + ' · ' + a.source);
				node('td', tr, a.keyword ? a.keyword + ' · ' + a.market : 'US indexed response sample');
				node('td', tr, a.brand_mentioned === undefined ? 'Not measured' : a.brand_mentioned ? 'Yes' : 'No');
				node('td', tr, a.domain_cited === undefined ? 'Aggregate sample' : a.domain_cited === null ? 'Unverified' : a.domain_cited ? 'Yes' : a.state === 'ai_result_not_shown' ? 'AI answer not returned' : 'No');
				node('td', tr, a.cited_domain_mentions === undefined ? (a.citations || []).length : fmt(a.cited_domain_mentions));
				var td = node('td', tr);
				var cites = (a.citations || []).slice(0, 5);
				if (!cites.length) { node('span', td, a.state === 'measured_sample' ? 'DataForSEO aggregate · not individual citations' : a.domain_cited === null ? 'Citations not independently verified' : 'No matching cited URLs'); }
				cites.forEach(function (c) {
					if (!/^https?:\/\//i.test(c.url)) return;
					var link = node('a', td, c.title || c.url);
					link.href = c.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
					node('span', td, ' · ');
				});
				if (a.competitor_citations && a.competitor_citations.length) {
					var comparisons = (a.competitor_citations || []).map(function (d) {
						return d.domain + ': ' + (d.cited === null ? 'unverified' : d.cited ? 'cited' : 'not cited');
					});
					var extra = node('tr', aib); var cell = node('td', extra, 'Same-answer citation comparison: ' + comparisons.join(' · '));
					cell.colSpan = 7;
				}
			});
			node('p', host, 'Live SERP checks show one observed answer. ChatGPT and Google indexed counts reflect DataForSEO samples, NOT all user responses, impressions, global market share or conversions.', 'description');
		}
		var gsc = r.search_console;
		var box = node('div', host);
		node('h4', box, 'Search Console measurements');
		if (gsc && Array.isArray(gsc.terms)) {
			node('p', box, 'Captured ' + gsc.captured_at + '. Position is an impression-weighted average over ' + gsc.window_days + ' days, not an exact rank.');
			var table = node('table', box, null, 'widefat striped'), thead = node('thead', table), hdr = node('tr', thead);
			['Target search theme', 'State', 'Avg. position', 'Clicks', 'Impressions'].forEach(function (label) { node('th', hdr, label); });
			var tbody = node('tbody', table);
			gsc.terms.forEach(function (term) {
				var tr = node('tr', tbody);
				[term.theme, term.state, fmt(term.position), fmt(term.clicks), fmt(term.impressions)].forEach(function (v) { node('td', tr, v); });
			});
		} else {
			node('p', box, 'No Search Console snapshot for this month. Connect GSC and click Refresh Search Console. An absent theme is not assumed to rank zero.');
		}
		var ga4 = r.google_analytics;
		node('h4', host, 'Google Analytics 4 · Organic Search');
		if (ga4 && ga4.state === 'measured') {
			var ga = node('table', host, null, 'widefat striped'), gaBody = node('tbody', ga);
			[['Organic sessions', fmt(ga4.sessions)], ['Organic total users', fmt(ga4.total_users)], ['Organic key events', fmt(ga4.key_events)], ['Reporting window', ga4.date_start + ' to ' + ga4.date_end], ['Source', 'GA4 Data API · read-only · property ' + ga4.property_id], ['Coverage', ga4.thresholded ? 'Google privacy thresholding applies' : 'Reported without indicated thresholding']].forEach(function (pair) {
				var gaRow = node('tr', gaBody); node('th', gaRow, pair[0]); node('td', gaRow, pair[1]);
			});
			node('p', host, ga4.notice, 'description');
		} else {
			node('p', host, 'No GA4 API measurement stored for this month. Connect and authorize the numeric GA4 property, then refresh. This is unavailable, not zero.');
		}
		var gbp = r.gbp_performance;
		node('h4', host, 'Google Business Profile · Search and Maps');
		if (gbp && gbp.metrics && gbp.state === 'measured_partial_possible') {
			var profileTable = node('table', host, null, 'widefat striped'), profileBody = node('tbody', profileTable);
			[['WEBSITE_CLICKS', 'Website button clicks'], ['CALL_CLICKS', 'Call button clicks'], ['BUSINESS_DIRECTION_REQUESTS', 'Direction requests'], ['BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'Mobile Maps impressions'], ['BUSINESS_IMPRESSIONS_DESKTOP_MAPS', 'Desktop Maps impressions'], ['BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'Mobile Search impressions'], ['BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'Desktop Search impressions']].forEach(function (field) {
				var metric = gbp.metrics[field[0]] || {};
				var tr = node('tr', profileBody);
				node('th', tr, field[1]);
				node('td', tr, metric.total === null || metric.total === undefined ? 'Not measured' : fmt(metric.total) + ' · ' + metric.measured_days + ' observed dates');
			});
			node('p', host, 'Location ' + gbp.location_id + ' · ' + gbp.start_date + ' to ' + gbp.end_date + ' · ' + gbp.captured_at);
			node('p', host, gbp.notice, 'description');
		} else {
			node('p', host, 'No verified Business Profile performance for this month. Connect and authorize a location, then refresh.');
		}
		var ghl = r.ghl_opportunities;
		node('h4', host, 'GoHighLevel · opportunity outcomes');
		if (ghl && ghl.state === 'measured') {
			var crmTable = node('table', host, null, 'widefat striped'), crmBody = node('tbody', crmTable);
			[['Created in selected month', fmt(ghl.opportunities_created_in_month)], ['Explicitly SEO/organic-labeled sources', fmt(ghl.source_breakdown.organic_labeled)], ['Other source labels', fmt(ghl.source_breakdown.other)], ['Missing/unknown source', fmt(ghl.source_breakdown.unknown)], ['Currently open', fmt(ghl.current_pipeline_status.open)], ['Currently won', fmt(ghl.current_pipeline_status.won)], ['Currently lost', fmt(ghl.current_pipeline_status.lost)], ['Current won opportunity value (not revenue)', fmt(ghl.current_won_opportunity_value)]].forEach(function (line) {
				var row = node('tr', crmBody);
				node('th', row, line[0]); node('td', row, line[1]);
			});
			node('p', host, ghl.notice, 'description');
		} else {
			node('p', host, 'No complete HighLevel opportunity snapshot. Connect a read-only sub-account and refresh the reporting month.');
		}
		var bing = r.bing_webmaster;
		node('h4', host, 'Bing Webmaster Tools');
		if (bing && bing.status === 'measured') {
			var bingTable = node('table', host, null, 'widefat striped');
			var bingBody = node('tbody', bingTable);
			[['Site', bing.site_url], ['Bing clicks', fmt(bing.clicks)], ['Bing impressions', fmt(bing.impressions)], ['Measured reporting days', bing.measured_days], ['Captured', bing.captured_at]].forEach(function (pair) {
				var row = node('tr', bingBody); node('th', row, pair[0]); node('td', row, pair[1]);
			});
			node('p', host, bing.notice, 'description');
			if (bing.query_rows && bing.query_rows.length) {
				var queryTable = node('table', host, null, 'widefat striped');
				var header = node('tr', node('thead', queryTable));
				['Top Bing queries', 'Clicks', 'Impressions', 'Avg impression position'].forEach(function (h) { node('th', header, h); });
				var rows = node('tbody', queryTable);
				bing.query_rows.slice(0, 12).forEach(function (term) {
					var tr = node('tr', rows);
					[term.query, fmt(term.clicks), fmt(term.impressions), fmt(term.avg_impression_position)].forEach(function (value) { node('td', tr, value); });
				});
			}
		} else {
			node('p', host, 'No measured Bing traffic exists for this month. An absent report or unverified site is not a zero.');
		}
		node('h4', host, 'Microsoft Clarity · rolling behavioral snapshots');
		var insights = r.clarity_insights || [];
		if (!insights.length) {
			node('p', host, 'No Clarity export snapshots stored for this month. Connect a project and capture recent insights.');
		} else {
			node('p', host, insights.length + ' captured rolling 24-hour windows. These are not cumulative month-to-date users or conversions.');
			insights.slice(-12).forEach(function (snap) {
				node('h4', host, 'Snapshot ' + snap.captured_at);
				var keys = Object.keys(snap.metrics || {});
				if (!keys.length) return;
				var table = node('table', host, null, 'widefat striped'), body = node('tbody', table);
				keys.forEach(function (name) {
					var row = node('tr', body);
					node('th', row, name);
					var measured = snap.metrics[name] || {};
					var cell = node('td', row);
					if (!measured.rows || !measured.rows.length) {
						node('span', cell, 'Metric category returned; no numeric value');
					} else {
						var pairs = Object.keys(measured.rows[0]).slice(0, 8).map(function (k) {
							return k + ': ' + fmt(measured.rows[0][k]);
						});
						node('span', cell, pairs.join(' · ') + (measured.truncated ? ' · more rows in Microsoft Clarity' : ''));
					}
				});
			});
		}
		node('h4', host, 'Exact organic SERP checks (paid DataForSEO)');
		var serp = r.organically_measured_serps || [];
		if (!serp.length) { node('p', host, 'No paid organic SERP measurements recorded in this month.'); }
		serp.forEach(function (snap) {
			node('p', host, snap.market + ' · ' + snap.captured_at + ' · five or fewer measured targets');
			var table = node('table', host, null, 'widefat striped'), header = node('tr', node('thead', table));
			['Keyword', 'Status', 'Exact observed rank'].forEach(function (text) { node('th', header, text); });
			var body = node('tbody', table);
			(snap.results || []).forEach(function (metric) {
				var row = node('tr', body);
				node('td', row, metric.keyword);
				node('td', row, metric.state === 'measured_not_found' ? 'Not found in measured top 100' : metric.state);
				node('td', row, metric.rank === null ? '—' : fmt(metric.rank));
			});
		});
		node('h4', host, 'Other evidence-based outcomes (manual, not independently verified)');
		if (!r.measurements || !r.measurements.length) {
			node('p', host, 'No conversion, Maps, GBP, earned-link or AI citation measurements have been recorded.');
		} else {
			var mt = node('table', host, null, 'widefat striped'), mh = node('tr', node('thead', mt));
			['Metric', 'Value', 'Market', 'Source / evidence'].forEach(function (x) { node('th', mh, x); });
			var body = node('tbody', mt);
			r.measurements.forEach(function (m) {
				var tr = node('tr', body);
				node('td', tr, m.type.replace(/_/g, ' '));
				node('td', tr, fmt(m.value));
				node('td', tr, m.market || '—');
				var td = node('td', tr, m.provenance + ' · unverified');
				if (m.evidence_url && /^https?:\/\//i.test(m.evidence_url)) {
					var a = node('a', td, ' · Open evidence'); a.href = m.evidence_url; a.target = '_blank'; a.rel = 'noopener noreferrer';
				}
			});
		}
		node('p', host, r.disclaimer, 'description');
		renderOpportunities(r.ai_opportunities || []);
		renderTasks(r);
		renderPipeline('scc-campaign-citations', r.citations || [], ['unverified', 'missing', 'needs_correction', 'claimed', 'submitted', 'verified', 'not_applicable'], '/citation', 'name');
		renderPipeline('scc-campaign-outreach', r.outreach || [], ['identified', 'qualified', 'pitched', 'responded', 'earned', 'declined', 'not_relevant'], '/outreach', 'domain');
		renderConnections(r.connections || {});
	}
	/** Reviewable, source-grounded suggestions. DOM text only; no HTML injection. */
	function renderPageImprovement(card, opportunity) {
		if (!opportunity.handoff || opportunity.handoff.mode !== 'existing') return;
		var zone = node('div', card);
		zone.style.marginTop = '12px'; zone.style.padding = '10px';
		zone.style.borderTop = '1px solid #dcdcde';
		node('h4', zone, 'Existing-page AI improvement · source page unchanged');
		var brief = (currentReport && currentReport.ai_page_briefs || {})[opportunity.id];
		var details = node('div', zone);
		function showAudit(audit) {
			clear(details);
			node('p', details, 'Read-only audit · Source post #' + audit.source_post_id + ' · ' + audit.question);
			node('p', details, 'Words observed: ' + audit.signals.word_count +
				' · Uses Elementor: ' + (audit.signals.uses_elementor ? 'yes' : 'no') +
				' · Question terms observed: ' + (audit.signals.question_terms_present || []).join(', '));
			node('p', details, 'Question terms not found in supplied text (NOT proof of missing coverage): ' +
				(audit.signals.question_terms_not_observed || []).join(', '));
			(audit.review_checklist || []).forEach(function (step) { node('p', details, '• ' + step); });
		}
		function showBrief(value) {
			brief = value;
			clear(details);
			node('p', details, 'LM Studio-only improvement brief · human fact-check required · ' + value.created_at);
			node('p', details, value.editorial_rationale);
			var list = node('ol', details);
			(value.draft_sections || []).forEach(function (section) {
				var li = node('li', list);
				node('strong', li, section.heading);
				node('p', li, section.body);
			});
			if ((value.verification_needed || []).length) {
				node('strong', details, 'Verify before publishing');
				(value.verification_needed || []).forEach(function (check) { node('p', details, '• ' + check); });
			}
		}
		var auditButton = node('button', zone, 'Audit existing page');
		auditButton.type = 'button'; auditButton.className = 'button'; auditButton.style.marginRight = '8px';
		auditButton.addEventListener('click', function () {
			auditButton.disabled = true;
			request('/ai-page/audit', 'POST', {id: opportunity.id})
				.then(showAudit).catch(showFailure).finally(function () { auditButton.disabled = false; });
		});
		var briefButton = node('button', zone, brief ? 'Regenerate LM Studio brief' : 'Create LM Studio improvement brief');
		briefButton.type = 'button'; briefButton.className = 'button'; briefButton.style.marginRight = '8px';
		briefButton.addEventListener('click', function () {
			if (!window.confirm('Send a bounded excerpt of this existing WordPress page and its AI citation question to your configured LM Studio server? This calls LM Studio only; no hosted AI fallback.')) return;
			briefButton.disabled = true;
			request('/ai-page/brief', 'POST', {id: opportunity.id, confirm_local_ai: true})
				.then(function (result) {
					showBrief(result);
					flash('Local LM Studio generated a review-only editorial brief. Check all suggested facts.');
					return loadReport();
				}).catch(showFailure).finally(function () { briefButton.disabled = false; });
		});
		if (brief) showBrief(brief);
		if (brief) {
			var draft = node('button', zone, 'Create separate Elementor/WordPress review draft');
			draft.type = 'button'; draft.className = 'button button-primary';
			draft.addEventListener('click', function () {
				if (!window.confirm('Create one new DRAFT with a copy of the existing page and a clearly labeled proposed AI improvement section? The source page and its Elementor JSON will NOT change. Verify all content before manually publishing.')) return;
				draft.disabled = true;
				request('/ai-page/draft', 'POST', {id: opportunity.id, confirm_draft: true})
					.then(function (result) {
						flash(result.notice || 'Separate review draft created.');
						var target = node('p', zone);
						var link = node('a', target, result.has_elementor ? 'Edit separate draft in Elementor' : 'Edit separate WordPress draft');
						link.href = result.has_elementor && result.elementor_url ? result.elementor_url : result.edit_url;
						link.target = '_blank'; link.rel = 'noopener noreferrer';
					}).catch(showFailure).finally(function () { draft.disabled = false; });
			});
		}
	}
	function renderOpportunities(items) {
		var host = byId('scc-campaign-ai-opportunities'); clear(host);
		if (!items.length) {
			node('p', host, 'No reviewed opportunities for this month. Run a measured AI query first, then analyze saved evidence.');
			return;
		}
		node('p', host, items.length + ' evidence-derived suggestions; nothing has been applied to live pages.');
		items.slice(0, 35).forEach(function (item) {
			var card = node('div', host);
			card.style.border = '1px solid #dcdcde'; card.style.padding = '12px';
			card.style.margin = '12px 0'; card.style.borderRadius = '5px';
			node('h4', card, item.title);
			node('p', card, 'Priority: ' + item.priority + ' · Review: ' + item.status + ' · ' + item.surface + ' · ' + item.captured_at);
			node('p', card, 'Measured search question: ' + item.question + ' · ' + item.market);
			node('p', card, 'Citation evidence: ' + (item.own_domain_cited === null ? 'unverified' : item.own_domain_cited ? 'site cited' : 'site not cited in the observed answer') + ' · Brand mentioned: ' + (item.brand_mentioned ? 'yes' : 'no'));
			if ((item.competitor_domains || []).length) {
				node('p', card, 'Competitors actually cited in same verified answer: ' + item.competitor_domains.join(', '));
			}
			var ul = node('ul', card);
			(item.steps || []).forEach(function (step) { node('li', ul, step); });
			if (item.review_notes) node('p', card, 'Review notes: ' + item.review_notes);
			if (item.handoff) {
				var handoff = node('p', card, item.handoff.notice || 'Review handoff recorded.');
				if (item.handoff.edit_url && /^https?:\/\//.test(item.handoff.edit_url)) {
					var a = node('a', handoff, ' Open destination'); a.href = item.handoff.edit_url;
				}
				renderPageImprovement(card, item);
				return;
			}
			var notes = node('textarea', card);
			notes.rows = 2; notes.style.width = '100%';
			notes.placeholder = 'What did you verify? Review evidence before approving.';
			notes.value = item.review_notes || '';
			if (item.status !== 'approved') {
				['approved', 'dismissed'].forEach(function (decision) {
					var b = node('button', card, decision === 'approved' ? 'Approve recommendation' : 'Dismiss');
					b.type = 'button'; b.className = 'button'; b.style.marginRight = '8px';
					b.addEventListener('click', function () {
						b.disabled = true;
						request('/ai-opportunities/review', 'POST', {id: item.id, decision: decision, notes: notes.value})
							.then(function () { flash('Opportunity ' + decision + '.'); return loadReport(); })
							.catch(showFailure).finally(function () { b.disabled = false; });
					});
				});
				return;
			}
			var mode = node('select', card);
			[['existing', 'Review an existing WordPress page (no edits)'], ['content_plan', 'Suggest a NEW article in Content Plan (no generation)']].forEach(function (opt) {
				var option = node('option', mode, opt[1]); option.value = opt[0];
			});
			mode.style.display = 'block'; mode.style.margin = '10px 0';
			var existing = node('div', card), create = node('div', card);
			var search = node('input', existing);
			search.type = 'search'; search.placeholder = 'Find an existing page by title'; search.style.minWidth = '240px';
			var find = node('button', existing, 'Find pages'); find.type = 'button'; find.className = 'button';
			var select = node('select', existing); select.style.display = 'block'; select.style.maxWidth = '100%';
			node('option', select, 'Search first').value = '';
			find.addEventListener('click', function () {
				if ((search.value || '').trim().length < 2) { flash('Search with at least two characters.', true); return; }
				find.disabled = true;
				request('/ai-opportunities/pages?search=' + encodeURIComponent(search.value), 'GET')
					.then(function (data) {
						clear(select);
						if (!data.posts || !data.posts.length) {
							node('option', select, 'No editable WordPress pages found').value = ''; return;
						}
						data.posts.forEach(function (p) {
							var option = node('option', select, p.title + ' · ' + p.post_type + ' · ' + p.status + ' (#' + p.id + ')');
							option.value = String(p.id);
						});
					}).catch(showFailure).finally(function () { find.disabled = false; });
			});
			node('p', create, 'New article requires its own non-duplicated URL slug. Check for existing relevant content first.');
			var title = node('input', create);
			title.type = 'text'; title.placeholder = 'Descriptive new article title'; title.maxLength = 150; title.className = 'regular-text'; title.style.display = 'block';
			var path = node('input', create);
			path.type = 'text'; path.placeholder = 'new-article-slug'; path.maxLength = 180; path.className = 'regular-text'; path.style.display = 'block';
			create.style.display = 'none';
			mode.addEventListener('change', function () {
				existing.style.display = mode.value === 'existing' ? '' : 'none';
				create.style.display = mode.value === 'content_plan' ? '' : 'none';
			});
			var submit = node('button', card, 'Send approved recommendation to workflow');
			submit.type = 'button'; submit.className = 'button button-primary'; submit.style.marginTop = '8px';
			submit.addEventListener('click', function () {
				if (!window.confirm('Record this approved handoff? Existing pages will NOT be changed, and new content ideas stay in the recommended Content Plan queue.')) return;
				submit.disabled = true;
				request('/ai-opportunities/handoff', 'POST', {
					id: item.id, mode: mode.value, post_id: Number(select.value || 0),
					title: title.value, path: path.value
				}).then(function (result) {
					flash((result.handoff && result.handoff.notice) || 'Review handoff recorded.');
					return loadReport();
				}).catch(showFailure).finally(function () { submit.disabled = false; });
			});
		});
	}
	function renderConnections(c) {
		var host = byId('scc-campaign-connections');
		clear(host);
		var tbl = node('table', host, null, 'widefat striped'), body = node('tbody', tbl);
		[['Google Search Console', c.gsc], ['Bing Webmaster', c.bing], ['Microsoft Clarity', c.clarity], ['Google Business Profile', c.gbp], ['Google Analytics 4', c.ga4], ['CRM', c.crm], ['AI citation monitoring', c.ai_citations]].forEach(function (item) {
			var tr = node('tr', body); node('th', tr, item[0]); node('td', tr, item[1] || 'not connected');
		});
	}
	function loadReport() {
		var value = byId('scc-campaign-month').value || nowMonth();
		month = value;
		return request('/report?month=' + encodeURIComponent(value), 'GET').then(renderReport).catch(showFailure);
	}
	function loadState() {
		return request('', 'GET').then(function (data) {
			state = data;
			fillConfig(data.campaign || {});
			var ai = data.ai_citations || {};
			var aiCfg = ai.config || {};
			var direct = ai.direct_providers || {};
			byId('scc-campaign-ai-perplexity-status').textContent = direct.perplexity_connected ? 'Connected (not verified until run)' : 'Not connected';
			byId('scc-campaign-ai-gemini-status').textContent = direct.gemini_connected ? 'Connected (not verified until run)' : 'Not connected';
			byId('scc-campaign-ai-competitors').elements.domains.value = (direct.competitor_domains || []).join('\n');
			byId('scc-campaign-ai-provider-perplexity').elements.api_key.value = '';
			byId('scc-campaign-ai-provider-gemini').elements.api_key.value = '';
			byId('scc-campaign-ai-config').elements.brand.value = aiCfg.brand || '';
			byId('scc-campaign-ai-config').elements.aliases.value = (aiCfg.aliases || []).join('\n');
			byId('scc-campaign-ai-domain').textContent = aiCfg.domain || 'Not configured';
			byId('scc-campaign-ai-status').textContent = ai.dataforseo_connected ? 'Connected for explicit paid scans' : 'Connect DataForSEO first';
			byId('scc-campaign-ai-quota').textContent = (ai.paid_calls_today || 0) + ' / ' + (ai.daily_soft_cap || 10);
			var gbp = data.gbp || {};
			byId('scc-campaign-gbp-location').elements.location_id.value = gbp.location_id || '';
			byId('scc-campaign-gbp-status').textContent = (gbp.status || 'not_available').replace(/_/g, ' ');
			var ghl = data.ghl || {};
			var ghlForm = byId('scc-campaign-ghl-connect');
			ghlForm.elements.location_id.value = ghl.location_id || '';
			ghlForm.elements.pipeline_id.value = ghl.pipeline_id || '';
			ghlForm.elements.token.value = '';
			byId('scc-campaign-ghl-status').textContent = (ghl.status || 'not_available').replace(/_/g, ' ') + (ghl.has_private_token ? ' · private token saved' : '');
			var bing = data.bing || {};
			byId('scc-campaign-bing-connect').elements.site_url.value = bing.site_url || '';
			byId('scc-campaign-bing-connect').elements.api_key.value = '';
			byId('scc-campaign-bing-status').textContent = (bing.status || 'not_available').replace(/_/g, ' ') + (bing.has_api_key ? ' · API key saved' : '');
			var clarity = data.clarity || {};
			byId('scc-campaign-clarity-connect').elements.project_id.value = clarity.project_id || '';
			byId('scc-campaign-clarity-connect').elements.api_token.value = '';
			byId('scc-campaign-clarity-status').textContent = (clarity.status || 'not_available').replace(/_/g, ' ') + ' · ' + (clarity.requests_reserved_today || 0) + '/' + (clarity.request_limit || 8) + ' daily API requests';
			var ga4 = data.ga4 || {};
			var ga4Form = byId('scc-campaign-ga4-property');
			if (ga4Form && ga4Form.elements.property_id) ga4Form.elements.property_id.value = ga4.property_id || '';
			byId('scc-campaign-ga4-status').textContent = (ga4.status || 'not_available').replace(/_/g, ' ') + (ga4.last_capture ? ' · last captured ' + ga4.last_capture : '');
			var marketSelect = byId('scc-campaign-serp-market'); clear(marketSelect);
			(data.campaign && data.campaign.markets || []).forEach(function (market, i) {
				var option = node('option', marketSelect, market); option.value = String(i);
			});
			var settings = byId('scc-campaign-public-citations');
			settings.elements.enabled.checked = !!data.public_citations.enabled;
			settings.elements.paid.checked = !!data.public_citations.paid;
			return loadReport();
		}).catch(showFailure);
	}
	byId('scc-campaign-month').value = month;
	byId('scc-campaign-metric-month').value = month;
	byId('scc-campaign-config').addEventListener('submit', function (evt) {
		evt.preventDefault();
		var f = evt.currentTarget;
		var data = { name: f.elements.name.value, plan: f.elements.plan.value, markets: lines(f.elements.markets.value), themes: lines(f.elements.themes.value), competitors: lines(f.elements.competitors.value), assets: f.elements.assets.value, goal: f.elements.goal.value };
		request('/config', 'POST', data).then(function () { flash('Campaign saved and monthly work generated.'); loadState(); }).catch(showFailure);
	});
	byId('scc-campaign-metric').addEventListener('submit', function (evt) {
		evt.preventDefault();
		var f = evt.currentTarget, fields = ['type', 'value', 'month', 'market', 'note', 'evidence_url'], data = {};
		fields.forEach(function (x) { data[x] = f.elements[x].value; });
		request('/metric', 'POST', data).then(function () { flash('Measured outcome recorded as manual/unverified.'); loadReport(); }).catch(showFailure);
	});
	byId('scc-campaign-public-citations').addEventListener('submit', function (evt) {
		evt.preventDefault();
		var f = evt.currentTarget;
		request('/public-citations', 'POST', { enabled: f.elements.enabled.checked, paid: f.elements.paid.checked })
			.then(function () { flash('Public citation scanner settings updated.'); loadState(); }).catch(showFailure);
	});
	byId('scc-campaign-gsc').addEventListener('click', function (evt) {
		var b = evt.currentTarget; b.disabled = true;
		request('/gsc-refresh', 'POST').then(function () { flash('New Search Console snapshot recorded.'); loadReport(); })
			.catch(showFailure).finally(function () { b.disabled = false; });
	});

	function submitCredential(formId, path, keys, notice) {
		var form = byId(formId);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			var data = {};
			keys.forEach(function (key) { data[key] = form.elements[key].value; });
			request(path, 'POST', data).then(function () {
				flash(notice);
				return loadState();
			}).catch(showFailure);
		});
	}
	submitCredential('scc-campaign-ghl-connect', '/ghl/connect', ['token', 'location_id', 'pipeline_id'], 'HighLevel sub-account configured. Run a measurement to verify its read-only opportunities permission.');
	submitCredential('scc-campaign-gbp-location', '/gbp/location', ['location_id'], 'Business Profile location saved; authorize Google and refresh to verify its performance.');
	submitCredential('scc-campaign-bing-connect', '/bing/connect', ['site_url', 'api_key'], 'Bing connection saved. Refresh the report month to verify API access.');
	submitCredential('scc-campaign-clarity-connect', '/clarity/connect', ['project_id', 'api_token'], 'Clarity token saved. Capture an export to verify API access.');
	[
		['scc-campaign-bing-refresh', '/bing/refresh', true],
		['scc-campaign-clarity-refresh', '/clarity/refresh', false]
	].forEach(function (entry) {
		byId(entry[0]).addEventListener('click', function (event) {
			var button = event.currentTarget; button.disabled = true;
			var args = entry[2] ? {month: byId('scc-campaign-month').value || nowMonth()} : {};
			request(entry[1], 'POST', args).then(function () {
				flash('Microsoft measurement captured and saved with its source label.');
				return loadState();
			}).catch(showFailure).finally(function () { button.disabled = false; });
		});
	});
	[
		['scc-campaign-bing-disconnect', '/bing/disconnect'],
		['scc-campaign-clarity-disconnect', '/clarity/disconnect']
	].forEach(function (entry) {
		byId(entry[0]).addEventListener('click', function (event) {
			if (!window.confirm('Disconnect this Microsoft data source and delete its locally stored measurements?')) return;
			var button = event.currentTarget; button.disabled = true;
			request(entry[1], 'POST').then(function () {
				flash('Microsoft data source disconnected and saved snapshots cleared.');
				return loadState();
			}).catch(showFailure).finally(function () { button.disabled = false; });
		});
	});
	byId('scc-campaign-gbp-connect').addEventListener('click', function (event) {
		var button = event.currentTarget; button.disabled = true;
		request('/gbp/auth-url', 'POST').then(function (r) {
			if (!r.url || !/^https:\/\/accounts\.google\.com\//.test(r.url)) throw new Error('Unexpected Google authorization endpoint.');
			window.location.assign(r.url);
		}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	[
		['scc-campaign-gbp-refresh', '/gbp/refresh'],
		['scc-campaign-ghl-refresh', '/ghl/refresh']
	].forEach(function (pair) {
		byId(pair[0]).addEventListener('click', function (event) {
			var b = event.currentTarget; b.disabled = true;
			request(pair[1], 'POST', {month: byId('scc-campaign-month').value || nowMonth()}).then(function () {
				flash('API measurements recorded with source and coverage labels.');
				return loadState();
			}).catch(showFailure).finally(function () { b.disabled = false; });
		});
	});
	[
		['scc-campaign-gbp-disconnect', '/gbp/disconnect'],
		['scc-campaign-ghl-disconnect', '/ghl/disconnect']
	].forEach(function (pair) {
		byId(pair[0]).addEventListener('click', function (event) {
			if (!window.confirm('Disconnect this service and erase its saved reporting snapshots?')) return;
			var b = event.currentTarget; b.disabled = true;
			request(pair[1], 'POST').then(function () {
				flash('Service disconnected; its saved reporting snapshots were cleared.');
				return loadState();
			}).catch(showFailure).finally(function () { b.disabled = false; });
		});
	});
	byId('scc-campaign-ga4-property').addEventListener('submit', function (evt) {
		evt.preventDefault();
		var property = evt.currentTarget.elements.property_id.value;
		request('/ga4/property', 'POST', {property_id: property}).then(function () {
			flash('GA4 property saved. Google authorization is separate from Search Console.');
			loadState();
		}).catch(showFailure);
	});
	byId('scc-campaign-ga4-connect').addEventListener('click', function (evt) {
		var button = evt.currentTarget; button.disabled = true;
		request('/ga4/auth-url', 'POST').then(function (result) {
			if (result.url && /^https:\/\/accounts\.google\.com\//.test(result.url)) {
				window.location.assign(result.url);
			} else throw new Error('Invalid Google authorization response.');
		}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	byId('scc-campaign-ga4-refresh').addEventListener('click', function (evt) {
		var button = evt.currentTarget; button.disabled = true;
		request('/ga4/refresh', 'POST', {month: byId('scc-campaign-month').value || nowMonth()}).then(function () {
			flash('Verified GA4 Organic Search metrics were captured for this report month.');
			return loadState();
		}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	byId('scc-campaign-ga4-disconnect').addEventListener('click', function (evt) {
		if (!window.confirm('Disconnect GA4 and delete its saved campaign analytics snapshots? Search Console will remain connected.')) return;
		var button = evt.currentTarget; button.disabled = true;
		request('/ga4/disconnect', 'POST').then(function () {
			flash('GA4 token and saved GA4 snapshots removed locally. Search Console was not changed.');
			return loadState();
		}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	[['perplexity', 'scc-campaign-ai-provider-perplexity'], ['gemini', 'scc-campaign-ai-provider-gemini']].forEach(function (pair) {
		var provider = pair[0]; var form = byId(pair[1]);
		form.addEventListener('submit', function (event) {
			event.preventDefault();
			request('/ai-providers/key', 'POST', {provider: provider, api_key: form.elements.api_key.value}).then(function () {
				flash('Provider API key configured. No paid call has been made.');
				return loadState();
			}).catch(showFailure);
		});
		byId('scc-campaign-ai-disconnect-' + provider).addEventListener('click', function (event) {
			if (!window.confirm('Remove the ' + provider + ' API credential? Previous labeled measurement history is retained.')) return;
			var b = event.currentTarget; b.disabled = true;
			request('/ai-providers/disconnect', 'POST', {provider: provider}).then(function () {
				flash(provider + ' disconnected.'); return loadState();
			}).catch(showFailure).finally(function () { b.disabled = false; });
		});
	});
	byId('scc-campaign-ai-competitors').addEventListener('submit', function (event) {
		event.preventDefault();
		request('/ai-providers/competitors', 'POST', {domains: event.currentTarget.elements.domains.value}).then(function () {
			flash('Competitor citation comparison domains saved.'); return loadState();
		}).catch(showFailure);
	});
	byId('scc-campaign-ai-opportunities-refresh').addEventListener('click', function (event) {
		var button = event.currentTarget; button.disabled = true;
		request('/ai-opportunities/refresh', 'POST', {month: byId('scc-campaign-month').value || nowMonth()})
			.then(function (data) {
				flash('Analyzed saved evidence: ' + data.added + ' new opportunities, ' + data.updated + ' updated. No paid request.');
				return loadReport();
			}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	byId('scc-campaign-ai-config').addEventListener('submit', function (event) {
		event.preventDefault();
		var form = event.currentTarget;
		request('/ai-citations/config', 'POST', {brand: form.elements.brand.value, aliases: form.elements.aliases.value}).then(function () {
			flash('AI search brand identity saved. Previous history is cleared if the identity changes.');
			return loadState();
		}).catch(showFailure);
	});
	byId('scc-campaign-ai-check').addEventListener('submit', function (event) {
		event.preventDefault();
		var form = event.currentTarget;
		var surface = form.elements.surface.value;
		var isDataset = /_dataset$/.test(surface);
		var direct = surface === 'perplexity_agent_api' || surface === 'gemini_grounded_api';
		var message = direct
			? 'Run one potentially billed provider API observation? This sends your non-sensitive question to ' + (surface === 'perplexity_agent_api' ? 'Perplexity Agent' : 'Gemini') + '. API behavior is not the same as the consumer app; individual calls and grounding may be charged.'
			: isDataset
			? 'Run ONE PAID DataForSEO indexed-response citation count? This is a sampled provider dataset, not live ChatGPT consumer traffic.'
			: 'Run ONE PAID DataForSEO live Google AI result check? Asynchronous AI Overviews may have an additional API charge.';
		if (!window.confirm(message)) return;
		var button = byId('scc-campaign-ai-run'); button.disabled = true;
		request('/ai-citations/measure', 'POST', {
			surface: surface,
			keyword: form.elements.keyword.value,
			market: form.elements.market.value || 'United States',
			confirm_paid: true
		}).then(function (result) {
			flash('Recorded paid ' + result.source + ' result: ' + result.state);
			return loadState();
		}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	byId('scc-campaign-serp-run').addEventListener('click', function (evt) {
		if (!window.confirm('This runs up to FIVE PAID DataForSEO organic SERP requests. Continue?')) return;
		var b = evt.currentTarget; b.disabled = true;
		request('/serp-ranks', 'POST', {
			market_index: Number(byId('scc-campaign-serp-market').value || 0),
			start: Math.max(0, Number(byId('scc-campaign-serp-offset').value || 1) - 1),
			confirm_paid: true
		}).then(function () { flash('SERP rank check recorded.'); loadReport(); })
			.catch(showFailure).finally(function () { b.disabled = false; });
	});
	byId('scc-campaign-outreach-sync').addEventListener('click', function (evt) {
		var button = evt.currentTarget; button.disabled = true;
		request('/outreach/sync', 'POST').then(function (r) {
			flash('Imported ' + r.imported + ' backlink prospects into the manual outreach pipeline.'); loadReport();
		}).catch(showFailure).finally(function () { button.disabled = false; });
	});
	byId('scc-campaign-load-report').addEventListener('click', loadReport);
	byId('scc-campaign-print').addEventListener('click', function () { if (currentReport) window.print(); });
	loadState();
}());
