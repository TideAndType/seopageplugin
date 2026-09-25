const {
  googleAuthUrl,
  seal,
  validatePluginCallback
} = require('../../lib/broker');

module.exports = async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  if (req.method !== 'GET') return res.status(405).json({ error: 'Method not allowed' });

  try {
    const callback = String(req.query.callback || '');
    const state = String(req.query.state || '');
    const challenge = String(req.query.challenge || '');
    const site = String(req.query.site || '');

    if (!callback || !state || !challenge || !site) throw new Error('Missing TideOrbit connection parameters.');
    validatePluginCallback(callback, site);
    if (!/^[A-Za-z0-9_-]{40,100}$/.test(challenge)) throw new Error('Invalid connection challenge.');

    const brokerState = seal({
      kind: 'oauth-state',
      callback,
      plugin_state: state,
      challenge,
      site,
      version: String(req.query.version || ''),
      iat: Date.now(),
      exp: Date.now() + 15 * 60 * 1000
    });

    return res.redirect(302, googleAuthUrl(brokerState));
  } catch (error) {
    return res.status(400).json({ error: error.message || 'Could not start Google connection.' });
  }
};
