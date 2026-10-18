# TideBridge 1.1.0 — your first-party Mac connection

**No Cloudflare, Tailscale, ngrok, VPS, public tunnel address or router port forwarding.** TideBridge is a native macOS app. Your existing WordPress site stores pending jobs, and the Mac polls WordPress securely to collect work and deliver results.

## One-time setup

1. Install **TideOrbit 1.99.9** on your WordPress site.
2. Install **TideBridge 1.1.0** on the Mac that runs LM Studio.
3. In WordPress: **TideOrbit → Settings → Connect my Mac**. Generate a six-digit pairing code.
4. In TideBridge: paste the website's HTTPS URL, the code, and click **Connect Mac**. The app stores its token in macOS Keychain; no code is needed next time.
5. In LM Studio: **Developer → Start Server**, choose/load a model, and click **Test LM Studio** in TideBridge. **Test WordPress Connection** should also pass.
6. From TideOrbit choose LM Studio as the primary or per-feature AI provider and select your local model. **Detect models** now reads the Mac's heartbeat, rather than contacting the Mac from WordPress.

## What runs through TideBridge

- **Content Plan → Generate draft** still uses its existing durable queue and WordPress draft pipeline.
- **Other TideOrbit admin AI tasks that call the shared LM Studio AI manager** (content briefs, site planning, AI writing/revisions, metadata, Elementor design, etc.) enqueue an AI request when invoked from the WordPress admin. The browser waits for the Mac to finish and then retries the original request using a signed, user-scoped idempotency key. Other provider choices (Claude/OpenAI/Gemini) are unchanged.
- **Google Maps Rank Tracker:** if its own desktop scanner runs on the same Mac, TideBridge sends scanner calls only to \`http://127.0.0.1:4317\`. WordPress retains the *Rank Tracker* pairing key, but has no scanner tunnel URL. **Automatic mode** can still fall back to DataForSEO if the scanner is offline.

## Important boundaries

- TideBridge is **not a public-purpose tunnel**. It is an authenticated pull-based job runner for TideOrbit.
- The Mac must be awake, TideBridge open, and LM Studio's server running. Add TideBridge under macOS **System Settings → General → Login Items** if desired.
- The WordPress admin async continuation is not yet a general-purpose queue for independent WordPress cron jobs or third-party plugins that invoke TideOrbit outside its admin UI. Those tasks receive an explicit error instead of silently using an old public endpoint.
- A WordPress request may perform preliminary processing before yielding the AI task. TideBridge retries the same REST action after the result is available. Test this on staging for content that writes data or modifies an existing page.
- Browser status waits can last up to 90 minutes. An interrupted connection, Mac sleep, server outage or expired WordPress session can still interrupt the original action; it cannot trigger a Cloudflare LM Studio proxy timeout because LM Studio runs entirely on the Mac.
- The Mac app is ad-hoc signed, not Apple-notarized. On first launch, use **Control-click → Open** or **System Settings → Privacy & Security → Open Anyway** if macOS blocks it; do not disable Gatekeeper globally.

## Security and privacy

WordPress pairing is one-time, expires after 10 minutes and is rate limited. Subsequent requests use an encrypted HTTPS connection and a random per-site secret held in macOS Keychain and hashed server-side. The local LM Studio API is called exclusively over loopback. The Rank Tracker pairing key is passed only to the paired Mac and its local scanner. WordPress admin queue status is restricted to the authenticated user. No inbound port is opened on your Mac.

The WordPress PHP CI and native macOS builds validate packaging and code contracts, **not** a real WordPress ↔ Mac ↔ LM Studio / Rank Tracker deployment. Verify end-to-end on staging before production.
