# TideBridge — your own Mac connection (no tunnel)

TideBridge connects the LM Studio already running on your Mac to TideOrbit installed on your WordPress site. It is a **native first-party Mac app**, not a Cloudflare/Tailscale/Nginx tunnel. There is no VPS, router configuration, domain to purchase, or third-party network agent.

**How it works:** TideOrbit places a draft-generation request into its own WordPress database. TideBridge running on your Mac makes a short outbound HTTPS call every five seconds, claims the request, runs LM Studio at \`127.0.0.1:1234\` entirely on the Mac, and returns the result so the existing WordPress draft-generation pipeline can save a draft.

## Setup

1. Upgrade TideOrbit to **1.99.8** in WordPress.
2. Download **TideBridge-Mac-1.0.0.dmg** and drag the app to **Applications**. It is currently an **ad-hoc signed, not notarized** build. The first launch may require **Control-click → Open**, or **System Settings → Privacy & Security → Open Anyway**. Do not disable Gatekeeper globally.
3. Start LM Studio's local server in **Developer → Start Server** with your desired model loaded. It can listen only on localhost; the default \`http://127.0.0.1:1234/v1\` is correct.
4. In WordPress open **TideOrbit → Settings → Connect my Mac** and select **Generate connection code**.
5. In TideBridge paste your WordPress site URL (HTTPS) and six-digit code, then press **Connect Mac**. The private pairing secret is saved in your Mac's Keychain. The code expires in ten minutes and is one-time use.
6. Open **TideOrbit → Create Content → Planned Content**, choose a topic and click **Generate draft**. You will see **Waiting for Mac → Mac generating → ready/finalizing → draft**. The browser may be closed while the Mac finishes. TideOrbit saves a WordPress draft after the model returns.

## Supported in this first version

- Planned Content → Generate draft using the installed Mac app and local LM Studio.
- Automatic retrieval of full long-form output through the existing WordPress site, avoiding any Cloudflare tunnel's 524 proxy timeout on the LM Studio connection.
- Secret is per WordPress site, hashed server-side, stored in macOS Keychain on the Mac. Revoke from the Connect my Mac page.
- 90-minute browser polling window (the Mac can keep working if the browser closes).
- Normal TideOrbit operation if the Mac is not paired. Other AI tools keep their existing provider setup.

## Important limitations

- **No general-purpose public HTTP tunnel.** Our pull bridge supports TideOrbit jobs without exposing your Mac, which is safer and simpler for this use case.
- The Mac must remain awake, TideBridge must stay running and LM Studio's server must be started. To start TideBridge at login, go to **System Settings → General → Login Items** and add TideBridge.
- Content generation waits for LM Studio **on the Mac**, not through Cloudflare's request to LM Studio. However WordPress hosting/network failures or Mac sleep can still interrupt a job.
- It doesn't yet route the rest of TideOrbit's AI operations (Topical Map, Design, Copilot) through the Mac. Those continue using current connections until supported in future revisions.
- This is a first integration: test in WordPress staging, especially if you've had prior PHP plugin fatals. The Mac app's compiled build and PHP automated tests do not substitute for testing with your real WordPress and LM Studio.

## Security

The Mac makes outbound HTTPS requests only to the site address you provide; LM Studio requests go only to \`127.0.0.1\` or \`localhost\`. Network redirects are not followed; there are no open inbound ports. WordPress pairing codes expire in ten minutes with rate limiting. Successful pairing replaces the previous secret. Do not share the pairing code or the Mac's Keychain contents.
