/* TideBridge's async apiFetch adapter: test the actual client middleware. */
'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const source = fs.readFileSync(__dirname + '/../seo-command-center/assets/js/tidebridge-client.js', 'utf8');
let installed;
const api = function () {};
api.use = function (fn) { installed = fn; };
const ctx = {
  window: {
    wp: { apiFetch: api },
    setTimeout: function (cb) { cb(); }
  },
  Promise,
  Date,
  Math,
  Object,
  String,
};
vm.runInNewContext(source, ctx);
assert.equal(typeof installed, 'function', 'middleware installed');

async function scenario(code, prefix) {
  let requests = 0;
  let polls = 0;
  let id;
  const next = function (opts) {
    if (opts.path === '/seo-command/v1/mac/async-status') {
      polls++;
      assert.equal(opts.data.id, prefix + 'a'.repeat(32));
      return Promise.resolve({ data: { ready: polls >= 2 } });
    }
    requests++;
    assert.equal(opts.method, 'POST');
    assert.ok(opts.headers['X-TideBridge-Job'].startsWith('tb_'));
    if (id) assert.equal(id, opts.headers['X-TideBridge-Job'], 'retry retains original interaction id');
    id = opts.headers['X-TideBridge-Job'];
    if (requests === 1) {
      return Promise.reject({ code: code, data: { job_id: prefix + 'a'.repeat(32) } });
    }
    return Promise.resolve({ data: { saved: true } });
  };
  const result = await installed({ path: '/seo-command/v1/some-ai-operation', method: 'POST', data: { idea: 'A' } }, next);
  assert.equal(result.data.saved, true, 'original WordPress action resumed');
  assert.equal(requests, 2, 'same action automatically retried once after completion');
  assert.equal(polls, 2, 'browser waited until Mac completed');
}

async function run() {
  await scenario('scc_mac_pending', 'ai_');
  await scenario('scc_browser_pending', 'br_');
  let passed = false;
  try {
    await installed({ path: '/seo-command/v1/any', method: 'POST' }, () => Promise.reject({ code: 'forbidden' }));
  } catch (e) {
    passed = e.code === 'forbidden';
  }
  assert.ok(passed, 'unrelated WordPress errors propagate as before');
  console.log('TideBridge admin async-middleware smoke: PASS');
}
run().catch(err => { console.error(err); process.exitCode = 1; });
