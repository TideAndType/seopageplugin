const {
  env,
  googleToken,
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

    const tokens = await googleToken({
      client_id: env('GOOGLE_CLIENT_ID'),
      client_secret: env('GOOGLE_CLIENT_SECRET'),
      refresh_token: connection.refresh_token,
      grant_type: 'refresh_token'
    });

    return json(res, 200, {
      access_token: tokens.access_token,
      expires_in: tokens.expires_in || 3600,
      scope: tokens.scope || connection.scope || ''
    });
  } catch (error) {
    return json(res, 401, { error: error.message || 'Could not refresh Google access.' });
  }
};
