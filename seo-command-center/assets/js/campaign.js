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
	function renderReport(r) {
		currentReport = r;
		var host = byId('scc-campaign-report'); clear(host);
		var c = r.campaign || {};
		node('h3', host, (c.name || 'Campaign') + ' · ' + r.month);
		var dl = r.deliverables || {};
		node('p', host, 'Completed deliverables: ' + dl.completed + ' / ' + dl.total);
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
		renderTasks(r);
		renderConnections(r.connections || {});
	}
	function renderConnections(c) {
		var host = byId('scc-campaign-connections');
		clear(host);
		var tbl = node('table', host, null, 'widefat striped'), body = node('tbody', tbl);
		[['Google Search Console', c.gsc], ['Google Business Profile', c.gbp], ['Google Analytics 4', c.ga4], ['CRM', c.crm], ['AI citation monitoring', c.ai_citations]].forEach(function (item) {
			var tr = node('tr', body); node('th', tr, item[0]); node('td', tr, item[1] || 'not connected');
		});
	}
	function loadReport() {
		var value = byId('scc-campaign-month').value || nowMonth();
		month = value;
		return request('/report?month=' + encodeURIComponent(value), 'GET').then(renderReport).catch(showFailure);
	}
	function loadState() {
		return request('/campaign'.replace('/campaign', ''), 'GET').then(function (data) {
			state = data;
			fillConfig(data.campaign || {});
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
	byId('scc-campaign-load-report').addEventListener('click', loadReport);
	byId('scc-campaign-print').addEventListener('click', function () { if (currentReport) window.print(); });
	loadState();
}());
