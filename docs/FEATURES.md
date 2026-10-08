# Features — the contract

These 12 features are the agreed scope. Every release must keep all of them working.
Each has acceptance criteria that map to steps in `docs/TESTING.md`.

Cookie categories are fixed to four keys (they map cleanly to Google Consent Mode and to
typical compliance inventories). Labels and descriptions are editable in the admin. The default
labels follow the Cookiebot convention.

| Key | Default label | Can be refused? | Google Consent Mode types |
|---|---|---|---|
| `necessary` | Necessary | No (always on) | `security_storage` |
| `functional` | Preferences | Yes | `functionality_storage`, `personalization_storage` |
| `analytics` | Statistics | Yes | `analytics_storage` |
| `marketing` | Marketing | Yes | `ad_storage`, `ad_user_data`, `ad_personalization` |

---

## What visitors see

### 1. Accept All
One button in the banner that grants every category.
- [ ] Saves consent (cookie + consent record) and closes the banner.
- [ ] Blocked scripts/iframes/fonts load immediately without a page reload.

### 2. Reject Non-Essential
One button ("Deny") that grants only `necessary`, next to "Allow all" on the first layer.
Banner style is a setting: **Compact** (default: Allow all, Deny, Customize) or **Detailed**
(the tabbed dialog with a switch per category and "Allow selection" on the first layer). Button
order is a setting (Allow all first by default, or Deny first); Allow all and Deny always look
the same.
- [ ] Same size, style and prominence as Accept All; visible on the first layer.
- [ ] Saves consent (cookie + record); nothing non-essential loads.

### 3. Manage Preferences
One dialog with three tabs: **Consent** (text + a switch per category), **Details** (an
accordion per category → per provider → a card per cookie) and **About** (what cookies are +
the visitor's own consent). It opens from "Customize" on the compact banner (closing it without
a choice brings the banner back), and again later, centred, with a close button.
- [ ] `necessary` shown as always on (disabled switch).
- [ ] All other switches OFF by default (never pre-ticked), unless the visitor consented before.
- [ ] Each category can be expanded to show its cookies (name, provider, purpose, duration).
- [ ] Buttons: Allow all, Allow selection, Deny (identical style).
- [ ] Keyboard accessible (focus trap, Esc closes without saving, ARIA labels).

### 4. Change or withdraw anytime
- [ ] Optional floating "Cookie settings" button (on by default) in five positions: bottom
      left/right (round icon) and bottom centre, left edge, right edge (a slim tab with a text
      label, "Cookies" by default, attached to the screen edge).
- [ ] Shortcode `[sccm_cookie_settings]` renders a link/button.
- [ ] Any element with class `sccm-open-preferences` or a link to `#sccm-preferences`
      (e.g. a menu item) opens the cookie settings dialog.
- [ ] Withdrawing a category deletes its known cookies and reloads the page so already-loaded
      scripts stop; the change is recorded.

### 5. Fair buttons
- [ ] Accept and Reject have identical styling by default (admin can change colours, not
      weight/order rules).
- [ ] No close (×) button that implies consent; closing the modal = no change.
- [ ] Ignoring the banner = no consent; banner stays until a choice is made.

### 6. Remember and re-ask
- [ ] Choice stored in first-party cookie `sccm_consent` (no personal data) for the period the
      site owner chooses: a preset or any number of days from 1 to 395 (default 365; browsers do
      not keep a cookie longer), or 0 = until the browser is closed.
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
      page URL, date/time (UTC), IP (setting: shortened `203.0.113.***` (default), one-way code,
      full, or not saved), user agent.
- [ ] Admin list with search by consent ID or IP address (finds full, shortened and coded
      records) and date filter; CSV export; retention period
      with automatic purge.
- [ ] Visitor can see their current choice, its date and their Consent ID (with a Copy button)
      in the About tab (for evidence requests). The ID is also in the `dataLayer`
      event and available as `SCCM.getConsentId()`.

### 9. Cookie scanner
- [ ] Visitor-side detection: browsers report **cookie** names that are not in the registry
      (names only, never values; rate-limited; never from logged-in users; local storage is not
      reported). An unknown cookie is listed only after enough different visitors
      reported it within a period (settings, default 2 visitors within 14 days); at most 50
      items wait for review. A known service cookie reported by browsers needs the same, and visitor reports never ask everyone again.
- [ ] Server-side scan (scheduled + "Scan now"): fetches the planned pages (all pages of a small
      site; 40–80 for bigger ones: main pages first, then sub and sub-sub pages of every
      section), reads `Set-Cookie` headers and third-party scripts/iframes/fonts, matches the
      service library. Works in short steps, so no request runs into the PHP time limit.
- [ ] Browser scan ("Scan now"): the same pages open in a hidden, sandboxed frame in the
      admin's browser in scan mode (all categories allowed; admin-only one-time token; never
      cached, stored or logged). Cookie and storage names set by scripts, and the third-party
      services the pages load, are recorded; third-party cookies come from the service library.
      Keeps going in a background tab; if the page is left it continues on the next plugin page.
- [ ] Known cookies are added automatically as Active in the right category; only cookies the
      service always sets are added (conditional ones are categorised when actually seen).
- [ ] Unknown cookies appear under **Needs review** in the Cookies tab; the admin must choose a
      category to approve (never pre-selected) or ignore them.
- [ ] Daily change email at a time the owner chooses, sent only when something changed in the
      last 24 hours (HTML + plain text, readable in dark mode, no admin links), to the site admin
      and up to 10 more addresses; a sample email can be sent on demand; the result of the last
      email is shown in the settings.

### 10. Cookie list for visitors
- [ ] Preferences window lists cookies per category (from the registry).
- [ ] Shortcode `[sccm_cookie_policy]` outputs a full table grouped by category; one-click
      "Create Cookie Policy page" in the admin; both update automatically.

### 11. Privacy signal (GPC)
- [ ] If `navigator.globalPrivacyControl === true`, non-essential categories are treated as
      rejected (scope setting: all non-essential [default] or advertising only).
- [ ] Recorded in the consent log as `gpc`; the Details tab shows
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
