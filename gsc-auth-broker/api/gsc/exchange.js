const {
  json,
  parseBody,
  safeEqual,
  seal,
  sha256Challenge,
  unseal
} = require('../../lib/broker');

module.exports = async function handler(req, res) {
  if (req.method !== 'POST') return json(res, 405, { error: 'Method not allowed' });

  try {
    const body = parseBody(req);
    const ticket = unseal(String(body.ticket || ''));
    const verifier = String(body.verifier || '');
    if (ticket.kind !== 'exchange') throw new Error('Invalid TideOrbit exchange ticket.');
    if (!verifier || !safeEqual(sha256Challenge(verifier), ticket.challenge)) throw new Error('Google connection verifier did not match.');
    if (String(body.site || '').replace(/\/+$/, '') !== String(ticket.site || '').replace(/\/+$/, '')) throw new Error('WordPress site did not match the connection request.');

    const connectionToken = seal({
      kind: 'connection',
      refresh_token: ticket.refresh_token,
      scope: ticket.scope,
      site: ticket.site,
      created_at: Date.now()
    });

    return json(res, 200, {
      connection_token: connectionToken,
      scope: ticket.scope
    });
  } catch (error) {
    return json(res, 400, { error: error.message || 'Could not complete Google connection.' });
  }
};
