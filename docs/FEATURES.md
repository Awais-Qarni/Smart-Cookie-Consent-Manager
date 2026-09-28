# Features — the contract

These 12 features are the agreed scope. Every release must keep all of them working.
Each has acceptance criteria that map to steps in `docs/TESTING.md`.

Cookie categories are fixed to four keys (they map cleanly to Google Consent Mode and to
typical compliance inventories). Labels and descriptions are editable in the admin.

| Key | Default label | Can be refused? | Google Consent Mode types |
|---|---|---|---|
| `necessary` | Necessary | No (always on) | `security_storage` |
| `functional` | Functional | Yes | `functionality_storage`, `personalization_storage` |
| `analytics` | Analytics | Yes | `analytics_storage` |
| `marketing` | Tracking / Advertising | Yes | `ad_storage`, `ad_user_data`, `ad_personalization` |

---

## What visitors see

### 1. Accept All
One button in the banner that grants every category.
- [ ] Saves consent (cookie + consent record) and closes the banner.
- [ ] Blocked scripts/iframes/fonts load immediately without a page reload.

### 2. Reject Non-Essential
One button that grants only `necessary`.
- [ ] Same size, style and prominence as Accept All; visible on the first layer.
- [ ] Saves consent (cookie + record); nothing non-essential loads.

### 3. Manage Preferences
A settings window (modal) with an on/off switch per category.
- [ ] `necessary` shown as always on (disabled switch).
- [ ] All other switches OFF by default (never pre-ticked), unless the visitor consented before.
- [ ] Each category can be expanded to show its cookies (name, provider, purpose, duration).
- [ ] Buttons: Save preferences, Accept all, Reject non-essential.
- [ ] Keyboard accessible (focus trap, Esc closes without saving, ARIA labels).

### 4. Change or withdraw anytime
- [ ] Optional floating "Cookie settings" button (on by default).
- [ ] Shortcode `[sccm_cookie_settings]` renders a link/button.
- [ ] Any element with class `sccm-open-preferences` or a link to `#sccm-preferences`
      (e.g. a menu item) opens the preferences window.
- [ ] Withdrawing a category deletes its known cookies and reloads the page so already-loaded
      scripts stop; the change is recorded.

### 5. Fair buttons
- [ ] Accept and Reject have identical styling by default (admin can change colours, not
      weight/order rules).
- [ ] No close (×) button that implies consent; closing the modal = no change.
- [ ] Ignoring the banner = no consent; banner stays until a choice is made.

### 6. Remember and re-ask
- [ ] Choice stored in first-party cookie `sccm_consent` (no personal data) for the configured
      period (default 365 days, max 395).
- [ ] Banner shown again when the consent period expires.
- [ ] Banner shown again when the **consent version** changes: automatically when a new cookie
      is added/approved in the registry (setting, on by default), or manually ("Ask everyone
      again" button).
- [ ] Optional: minimum days before asking again after a Reject (default 0).

## Behind the scenes

### 7. Block until consent
- [ ] Google Consent Mode v2 default = denied for all non-necessary types, printed as the very
      first script in `<head>`; returning visitors' choice is applied before any tag fires.
- [ ] Two modes: **Strict (basic)** — Google tags are blocked until consent (default);
      **Advanced** — Google tags load but run cookieless until consent.
- [ ] Server-side HTML rewrite blocks `<script>`, `<iframe>`, and `<link>` (stylesheet /
      preconnect) tags that match a rule of a non-granted category; they are released in the
      original order when consent is given.
- [ ] Manual tagging supported: `<script type="text/plain" data-sccm-category="analytics">`.
- [ ] Blocked iframes (e.g. YouTube, Maps) show a placeholder with an "Allow & load" button.
- [ ] Optional: load GTM / GA4 by ID from the plugin with correct consent handling.

### 8. Consent record
- [ ] Every choice stored in DB table `{prefix}sccm_consent_log`: consent ID, choice
      (accept_all / reject_all / custom / gpc), granted categories, GPC flag, consent version,
      page URL, date/time (UTC), anonymised or hashed IP (setting), user agent.
- [ ] Admin list with search by consent ID and date filter; CSV export; retention period
      with automatic purge.
- [ ] Visitor can see their Consent ID in the preferences window (for evidence requests).

### 9. Cookie scanner
- [ ] Visitor-side detection: browser reports cookie/localStorage names that are not in the
      registry (names only, never values; rate-limited).
- [ ] Server-side scan (scheduled + "Scan now"): fetches key pages, reads `Set-Cookie`
      headers and third-party scripts/iframes/fonts, matches the service library.
- [ ] New items appear as **Pending** in the Cookies tab; admin assigns a category.
- [ ] Email alert (digest, max once per day) to the configured address.

### 10. Cookie list for visitors
- [ ] Preferences window lists cookies per category (from the registry).
- [ ] Shortcode `[sccm_cookie_policy]` outputs a full table grouped by category; one-click
      "Create Cookie Policy page" in the admin; both update automatically.

### 11. Privacy signal (GPC)
- [ ] If `navigator.globalPrivacyControl === true`, non-essential categories are treated as
      rejected (scope setting: all non-essential [default] or advertising only).
- [ ] Recorded in the consent log as `gpc`; preferences window shows
      "Your Global Privacy Control signal has been honoured."
- [ ] GPC overrides a stored "accept" for the affected categories.

### 12. Works with any setup (plug and play)
- [ ] Cache-safe: identical HTML for every visitor; state handled in the browser.
- [ ] Our own inline scripts carry exclusion attributes for common optimisers
      (`data-no-optimize`, `data-no-defer`, `data-cfasync="false"`, `nitro-exclude`,
      `data-noptimize`, `data-nowprocket`).
- [ ] Banner text is real DOM text, so page-translation plugins can translate it; all strings
      translatable via `.po/.mo` and editable in the admin.
- [ ] Settings export/import (JSON) to reuse a configuration on other websites.
- [ ] Responsive (mobile), themable with CSS variables, RTL-friendly.
