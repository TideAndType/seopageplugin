module.exports = function handler(req, res) {
  res.setHeader('Cache-Control', 'no-store');
  return res.status(200).json({
    ok: true,
    service: 'TideOrbit GSC Auth Broker',
    google_client_configured: Boolean(process.env.GOOGLE_CLIENT_ID && process.env.GOOGLE_CLIENT_SECRET),
    broker_secret_configured: Boolean(process.env.BROKER_SECRET),
    public_base_url_configured: Boolean(process.env.PUBLIC_BASE_URL)
  });
};
