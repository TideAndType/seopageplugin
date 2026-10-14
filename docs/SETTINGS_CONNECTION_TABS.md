# TideOrbit 1.99 — Settings connection tabs

## New organization

The TideOrbit admin menu now has **Settings** as the single visible configuration entry. It uses standard WordPress tabs:

| Settings tab | Purpose |
| --- | --- |
| **General** | Default content/AI providers, task routing, site-wide optimization, publishing and spending settings |
| **SEO Connections** | Google Search Console OAuth/property, DataForSEO credentials, Google PageSpeed key, Google Maps/GBP rank-tracker browser runtime, Google Business Profile OAuth/location, Bing Webmaster Tools |
| **AI Connections** | Anthropic/OpenAI/Gemini generation keys, LM Studio tunnel URL/model/key and model test controls, Perplexity Agent citation API key, independently scoped Gemini grounding citation API key, brand identity/aliases, comparison domains |
| **Analytics & CRM** | Google Analytics 4 reporting OAuth/property, Microsoft Clarity Data Export, GoHighLevel opportunities attribution |

**SEO Campaign** now focuses on campaign planning, paid AI citation *measurements*, report-month SERP checks, evidence, deliverables, and reviews rather than inviting users to enter credentials there.

### No credential migration

The new views reuse the **existing credential options, REST routes, field IDs, provider settings and WordPress OAuth handlers**. No stored tokens are copied, renamed or migrated. Existing connection history, campaign performance snapshots and data remain intact.

The original **TideOrbit → Connections** URL is still registered as a hidden compatibility route for saved links and Google Search Console OAuth redirect callbacks. Google OAuth consent callbacks for GA4 and GBP still use their existing established handlers. API secrets are never prefilled in inputs or returned to the JavaScript application.

### Implementation

The connection views reuse existing WordPress forms and the original Campaign controller. For compatibility with previously configured installations, the old forms are retained in the admin-only Campaign template and are **visually scoped** by the active Settings tab: SEO shows GBP/Bing, Analytics shows GA4/Clarity/GHL, AI shows Perplexity/Gemini/brand fields; Campaign shows measurement controls and reporting. This does **not** inject any integration script into the site's frontend and does not add a second copy of any provider client.

The legacy credential form groups are separately displayed under SEO vs AI Settings. The client only submits **visible** non-secret settings in each respective tab, avoiding a hidden AI field resetting SEO runtime configuration and vice versa. Empty password fields keep their previously saved values (unless an explicit supported Clear/Disconnect action is used).

### Test and manual verification

Automated regression tests validate menu/tab registration, source credential option reuse, the existing Campaign REST fields, compatibility route and measurement-vs-connection view scoping. Test all three supported PHP versions and the WordPress-install ZIP in GitHub Actions.

On staging, visit each tab, save an AI key and an SEO key independently, check that the other remains connected, and verify OAuth callback behavior from Google Cloud using the exact redirect URI shown in SEO Connections. Confirm that:

1. TideOrbit → Settings → General, SEO Connections, AI Connections, Analytics & CRM all render and link correctly.
2. Original Campaign continues to display and execute paid AI checks and monthly report retrieval, but not connection setup cards.
3. Connecting/disconnecting Bing, GBP, GA4, GHL, Clarity, Perplexity or Gemini from Settings updates the state in Campaign without changing saved OAuth scopes.
4. WordPress plugins on a real site can coexist with the Settings tabs; the code does not require any JavaScript framework or external stylesheet.

The code tests cannot verify your live WordPress page and OAuth/provider credentials. Perform a staging check before upgrading a client's production WordPress site.
