const assert = require('assert');

process.env.BROKER_SECRET = 'test-secret-that-is-long-enough-for-tideorbit';
process.env.PUBLIC_BASE_URL = 'https://auth.tideandtype.com';
process.env.GOOGLE_CLIENT_ID = 'test.apps.googleusercontent.com';
process.env.GOOGLE_CLIENT_SECRET = 'test-secret';

const {
  callbackUrl,
  seal,
  unseal,
  sha256Challenge,
  safeEqual,
  validatePluginCallback
} = require('../lib/broker');

assert.equal(callbackUrl(), 'https://auth.tideandtype.com/api/gsc/callback');

const token = seal({ kind: 'connection', value: 'secret', created_at: Date.now() });
const decoded = unseal(token);
assert.equal(decoded.kind, 'connection');
assert.equal(decoded.value, 'secret');
assert(!token.includes('secret'));

const verifier = 'a'.repeat(96);
const challenge = sha256Challenge(verifier);
assert(safeEqual(challenge, sha256Challenge(verifier)));
assert(!safeEqual(challenge, sha256Challenge('different')));

const callback = validatePluginCallback(
  'https://example.com/wp-admin/admin.php?page=seo-command-center-connections',
  'https://www.example.com/'
);
assert.equal(callback.hostname, 'example.com');

assert.throws(() => validatePluginCallback(
  'https://evil.example/callback?page=seo-command-center-connections',
  'https://example.com/'
));

const expired = seal({ kind: 'exchange', exp: Date.now() - 1000 });
assert.throws(() => unseal(expired), /expired/i);

console.log('TideOrbit GSC broker tests passed.');
