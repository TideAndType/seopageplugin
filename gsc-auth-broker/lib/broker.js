const crypto = require('crypto');

const GOOGLE_AUTH = 'https://accounts.google.com/o/oauth2/v2/auth';
const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
const GOOGLE_REVOKE = 'https://oauth2.googleapis.com/revoke';
const GSC_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';

function env(name) {
  const value = process.env[name];
  if (!value) throw new Error(`Missing required environment variable: ${name}`);
  return value;
}

function baseUrl() {
  return env('PUBLIC_BASE_URL').replace(/\/+$/, '');
}

function callbackUrl() {
  return `${baseUrl()}/api/gsc/callback`;
}

function key() {
  return crypto.createHash('sha256').update(env('BROKER_SECRET'), 'utf8').digest();
}

function b64url(input) {
  return Buffer.from(input).toString('base64url');
}

function seal(payload) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', key(), iv);
  const plaintext = Buffer.from(JSON.stringify(payload), 'utf8');
  const encrypted = Buffer.concat([cipher.update(plaintext), cipher.final()]);
  const tag = cipher.getAuthTag();
  return ['v1', b64url(iv), b64url(tag), b64url(encrypted)].join('.');
}

function unseal(token) {
  const parts = String(token || '').split('.');
  if (parts.length !== 4 || parts[0] !== 'v1') throw new Error('Invalid TideOrbit connection token.');
  const iv = Buffer.from(parts[1], 'base64url');
  const tag = Buffer.from(parts[2], 'base64url');
  const encrypted = Buffer.from(parts[3], 'base64url');
  const decipher = crypto.createDecipheriv('aes-256-gcm', key(), iv);
  decipher.setAuthTag(tag);
  const plaintext = Buffer.concat([decipher.update(encrypted), decipher.final()]);
  const payload = JSON.parse(plaintext.toString('utf8'));
  if (payload.exp && Date.now() > payload.exp) throw new Error('This Google connection request expired. Start the connection again.');
  return payload;
}

function sha256Challenge(verifier) {
  return crypto.createHash('sha256').update(String(verifier), 'utf8').digest('base64url');
}

function safeEqual(a, b) {
  const aa = Buffer.from(String(a || ''), 'utf8');
  const bb = Buffer.from(String(b || ''), 'utf8');
  return aa.length === bb.length && crypto.timingSafeEqual(aa, bb);
}

function parseBody(req) {
  if (req.body && typeof req.body === 'object') return req.body;
  if (!req.body) return {};
  try { return JSON.parse(String(req.body)); } catch (_) { return {}; }
}

function json(res, status, body) {
  res.setHeader('Cache-Control', 'no-store');
  res.status(status).json(body);
}

function normalizeHost(host) {
  return String(host || '').toLowerCase().replace(/^www\./, '');
}

function validatePluginCallback(rawCallback, rawSite) {
  const callback = new URL(rawCallback);
  const site = new URL(rawSite);
  const insecure = process.env.ALLOW_INSECURE_CALLBACKS === 'true';
  if (!insecure && callback.protocol !== 'https:') throw new Error('WordPress callback must use HTTPS.');
  if (!callback.pathname.endsWith('/wp-admin/admin.php')) throw new Error('Invalid TideOrbit WordPress callback.');
  if (callback.searchParams.get('page') !== 'seo-command-center-connections') throw new Error('Invalid TideOrbit connection page.');
  if (normalizeHost(callback.hostname) !== normalizeHost(site.hostname)) throw new Error('Callback and WordPress site do not match.');
  return callback;
}

async function googleToken(params) {
  const body = new URLSearchParams(params);
  const response = await fetch(GOOGLE_TOKEN, {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(data.error_description || data.error || `Google token endpoint returned HTTP ${response.status}`);
  }
  return data;
}

function googleAuthUrl(state) {
  const url = new URL(GOOGLE_AUTH);
  url.searchParams.set('client_id', env('GOOGLE_CLIENT_ID'));
  url.searchParams.set('redirect_uri', callbackUrl());
  url.searchParams.set('response_type', 'code');
  url.searchParams.set('scope', GSC_SCOPE);
  url.searchParams.set('access_type', 'offline');
  url.searchParams.set('prompt', 'consent');
  url.searchParams.set('state', state);
  return url.toString();
}

function pluginRedirect(callback, params) {
  const url = new URL(callback.toString());
  url.searchParams.set('scc_gsc_broker', '1');
  for (const [name, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(name, String(value));
  }
  return url.toString();
}

module.exports = {
  GSC_SCOPE,
  GOOGLE_REVOKE,
  baseUrl,
  callbackUrl,
  env,
  googleAuthUrl,
  googleToken,
  json,
  parseBody,
  pluginRedirect,
  safeEqual,
  seal,
  sha256Challenge,
  unseal,
  validatePluginCallback
};
