# TideOrbit Google Search Console Auth Broker

This small stateless service powers TideOrbit's one-click **Connect Google Search Console** button.

## Why it exists

Google Search Console private data requires OAuth 2.0. Shipping a Google OAuth client secret inside a public WordPress plugin would expose that secret. The broker keeps the Google client credentials server-side and lets WordPress store only an opaque sealed TideOrbit connection token.

The broker does not need a database:

1. WordPress creates a one-time verifier and sends only its SHA-256 challenge.
2. The broker sends the user to Google for the `webmasters.readonly` scope.
3. Google returns to one fixed broker callback URL.
4. The broker exchanges the Google code and returns a short-lived sealed ticket to WordPress.
5. WordPress exchanges the ticket using its original verifier.
6. The broker returns a long-lived sealed connection token containing the Google refresh token.
7. WordPress stores that opaque token. When it needs GSC data it asks the broker for a short-lived Google access token.

## Vercel configuration

Deploy this directory as its own Vercel project with Node.js 24.

Set:

- `GOOGLE_CLIENT_ID` — Google OAuth Web application client ID
- `GOOGLE_CLIENT_SECRET` — matching client secret
- `BROKER_SECRET` — long random secret; keep stable or existing connections will stop decrypting
- `PUBLIC_BASE_URL` — production broker origin, e.g. `https://auth.tideandtype.com`

Optional for local testing only:

- `ALLOW_INSECURE_CALLBACKS=true`

In Google Auth Platform, enable the **Google Search Console API** and add exactly this Authorized redirect URI:

```
https://auth.tideandtype.com/api/gsc/callback
```

For broad public use, complete any Google OAuth consent-screen verification Google requires for the requested Search Console scope.

## Endpoints

- `GET /api/health`
- `GET /api/gsc/connect`
- `GET /api/gsc/callback`
- `POST /api/gsc/exchange`
- `POST /api/gsc/token`
- `POST /api/gsc/revoke`

The service intentionally has no endpoint that exposes the Google client secret or a plaintext stored refresh token.
