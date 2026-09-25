const {
  GSC_SCOPE,
  env,
  googleToken,
  pluginRedirect,
  seal,
  unseal,
  validatePluginCallback
} = require('../../lib/broker');

module.exports = async function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  if (req.method !== 'GET') return res.status(405).json({ error: 'Method not allowed' });

  let state;
  try {
    state = unseal(String(req.query.state || ''));
    if (state.kind !== 'oauth-state') throw new Error('Invalid Google connection state.');
    const callback = validatePluginCallback(state.callback, state.site);

    if (req.query.error) {
      return res.redirect(302, pluginRedirect(callback, {
        state: state.plugin_state,
        error: String(req.query.error)
      }));
    }
    if (!req.query.code) throw new Error('Google did not return an authorization code.');

    const tokens = await googleToken({
      code: String(req.query.code),
      client_id: env('GOOGLE_CLIENT_ID'),
      client_secret: env('GOOGLE_CLIENT_SECRET'),
      redirect_uri: require('../../lib/broker').callbackUrl(),
      grant_type: 'authorization_code'
    });

    const granted = String(tokens.scope || '');
    if (!granted.split(/\s+/).includes(GSC_SCOPE)) {
      throw new Error('The required read-only Search Console permission was not granted.');
    }
    if (!tokens.refresh_token) {
      throw new Error('Google did not return offline access. Reconnect and approve Search Console access again.');
    }

    const ticket = seal({
      kind: 'exchange',
      refresh_token: tokens.refresh_token,
      scope: granted,
      challenge: state.challenge,
      site: state.site,
      iat: Date.now(),
      exp: Date.now() + 5 * 60 * 1000
    });

    return res.redirect(302, pluginRedirect(callback, {
      state: state.plugin_state,
      ticket
    }));
  } catch (error) {
    if (state && state.callback && state.site) {
      try {
        const callback = validatePluginCallback(state.callback, state.site);
        return res.redirect(302, pluginRedirect(callback, {
          state: state.plugin_state || '',
          error: error.message || 'Google connection failed.'
        }));
      } catch (_) {}
    }
    return res.status(400).json({ error: error.message || 'Google connection failed.' });
  }
};
