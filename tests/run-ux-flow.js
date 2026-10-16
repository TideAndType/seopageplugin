'use strict';
/**
 * Guided-content-flow UX contract. No external libraries.
 * Run: node tests/run-ux-flow.js
 */
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.join(__dirname, '..');
const admin = fs.readFileSync(path.join(root, 'seo-command-center/includes/admin/class-scc-admin.php'), 'utf8');
const js = fs.readFileSync(path.join(root, 'seo-command-center/assets/js/admin.js'), 'utf8');
const content = fs.readFileSync(path.join(root, 'seo-command-center/includes/admin/views/content-plan.php'), 'utf8');
const architecture = fs.readFileSync(path.join(root, 'seo-command-center/includes/admin/views/architecture.php'), 'utf8');

for (const phrase of [
    'Planned Content', 'Write a Draft', 'Review Drafts',
    'render_content_workflow(', 'More creation tools', 'Advanced research tools',
    'My planned content',
]) assert(admin.includes(phrase), 'Guided navigation missing: ' + phrase);
assert(content.includes('ideas saved') || content.includes('Ideas saved'), 'Plan explains source of ideas');
assert(content.includes('NOT WordPress pages'), 'Plan explains idea vs. draft');
assert(architecture.includes('Save selected ideas to Planned Content'), 'Architecture labels save action');
assert(architecture.includes('scc-seed-plan'), 'Legacy seed action remains accessible');

// Actually run the new DOM status helper in a tiny mock browser. A raw success
// message is not enough: a click target must point to the canonical Create tab.
const start = js.indexOf('\tfunction showPlanDestination(');
const end = js.indexOf('\n\t// Shared HTML escaper', start);
assert(start >= 0 && end > start, 'Missing plan-destination helper');
const helper = js.slice(start, end);
const nodes = [];
const status = {
    children: [],
    appendChild(node) { this.children.push(node); },
};
const document = {
    createTextNode(text) { return { text }; },
    createElement(tag) {
        const node = { tagName: tag.toUpperCase(), href: '', textContent: '', className: '' };
        nodes.push(node);
        return node;
    },
};
let received = [];
const context = { document, status, window: { location: { pathname: '/wp-admin/admin.php' } },
    setStatus(el, message, className) { received.push({ el, message, className }); } };
vm.runInNewContext(helper + '\nshowPlanDestination(status, "Saved.");', context, { timeout: 1500 });
assert.strictEqual(received[0].className, 'is-ok');
assert.strictEqual(nodes.length, 1);
assert.strictEqual(nodes[0].tagName, 'A');
assert.strictEqual(nodes[0].href, '/wp-admin/admin.php?page=seo-command-center-create&tab=plan');
assert.strictEqual(nodes[0].textContent, 'Open Planned Content →');
for (const phrase of [
    'showPlanDestination( status', 'showPlanDestination( st',
    'showPlanDestination( status, created > 0',
]) assert(js.includes(phrase), 'Missing clear destination on creation action: ' + phrase);
console.log('Guided navigation, preserved create actions and clickable save destinations: PASS');
