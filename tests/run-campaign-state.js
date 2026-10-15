'use strict';
/**
 * Browser-side regression for the TideOrbit Campaign Settings screen.
 * Run: node tests/run-campaign-state.js
 *
 * The 1.99.2 bundle used data.public_citations.enabled without checking
 * whether the REST response included public_citations. This test fails on
 * that production script and checks that an incomplete payload cannot
 * silently change public/paid scanner settings.
 */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const script = fs.readFileSync(require('path').join(__dirname, '../seo-command-center/assets/js/campaign.js'), 'utf8');

async function checkResponse(campaignResponse, expectations) {
    const nodes = {};
    function makeNode(id) {
        const fields = new Proxy({}, {
            get(target, name) {
                if (typeof name !== 'string') return undefined;
                if (!target[name]) target[name] = { value: '', checked: false, disabled: false };
                return target[name];
            }
        });
        return {
            id, value: '', checked: false, disabled: false, textContent: '', className: '',
            style: {}, elements: fields, children: [], firstChild: null,
            classList: { contains(name) { return name === 'scc-mode-settings-ai'; } },
            addEventListener(name, cb) { if (!this.handlers) this.handlers = {}; this.handlers[name] = cb; },
            appendChild(child) { this.children.push(child); this.firstChild = this.children[0]; return child; },
            removeChild(child) { this.children.splice(this.children.indexOf(child), 1); this.firstChild = this.children[0] || null; },
            setAttribute() {}, querySelectorAll() { return []; }
        };
    }
    const doc = {
        getElementById(id) { if (!nodes[id]) nodes[id] = makeNode(id); return nodes[id]; },
        createElement(tag) { return makeNode(tag); }
    };
    const form = doc.getElementById('scc-campaign-public-citations');
    form.elements.enabled.checked = true;
    form.elements.paid.checked = true;
    const requests = [];
    const api = opts => { requests.push(opts.path); return Promise.resolve(campaignResponse); };
    api.use = () => {};
    api.createNonceMiddleware = () => () => {};
    const win = {
        wp: { apiFetch: api },
        sccCampaign: { nonce: 'test', api: '/seo-command/v1/campaign' },
        confirm: () => false
    };
    vm.runInNewContext(script, {
        window: win, wp: win.wp, sccCampaign: win.sccCampaign,
        document: doc, console, setTimeout, clearTimeout
    }, { filename: 'campaign.js', timeout: 1500 });
    await new Promise(resolve => setTimeout(resolve, 40));
    const status = doc.getElementById('scc-campaign-status').textContent;
    assert(requests.includes('/seo-command/v1/campaign'));
    assert.strictEqual(form.elements.enabled.checked, expectations.enabled);
    assert.strictEqual(form.elements.paid.checked, expectations.paid);
    assert(expectations.statusRe.test(status), 'Unexpected Campaign status: ' + status);
}

(async () => {
    await checkResponse(
        { campaign: {}, public_citations: { enabled: false, paid: false } },
        { enabled: false, paid: false, statusRe: /^$/ }
    );
    await checkResponse(
        { campaign: {} },
        { enabled: true, paid: true, statusRe: /missing public citation scanner settings/ }
    );
    await checkResponse(
        null,
        { enabled: true, paid: true, statusRe: /invalid response/ }
    );
    console.log('Campaign Settings REST state smoke: PASS');
})().catch(error => { console.error(error); process.exitCode = 1; });
