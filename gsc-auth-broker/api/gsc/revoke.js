const {
  GOOGLE_REVOKE,
  json,
  parseBody,
  unseal
} = require('../../lib/broker');

module.exports = async function handler(req, res) {
  if (req.method !== 'POST') return json(res, 405, { error: 'Method not allowed' });

  try {
    const body = parseBody(req);
    const connection = unseal(String(body.connection_token || ''));
    if (connection.kind !== 'connection' || !connection.refresh_token) throw new Error('Invalid TideOrbit Google connection.');

    const response = await fetch(GOOGLE_REVOKE, {
      method: 'POST',
      headers: { 'content-type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ token: connection.refresh_token })
    });

    if (!response.ok && response.status !== 400) {
      throw new Error(`Google revoke endpoint returned HTTP ${response.status}`);
    }
    return json(res, 200, { ok: true });
  } catch (error) {
    return json(res, 400, { error: error.message || 'Could not revoke Google connection.' });
  }
};
