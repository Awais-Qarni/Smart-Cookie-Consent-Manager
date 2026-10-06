# Testing

## Automated checks (run before every commit)

```bash
npm install          # first time only (dev dependency: Playwright)
npm test             # php -l on every PHP file, node --check on JS, PHP unit tests (tests/php/run.php)

# Need a development WordPress with the plugin active (see tests/e2e/README.md):
SCCM_E2E_URL=http://127.0.0.1:8080 npm run e2e                  # 99 browser checks (visitor side)
SCCM_WP=/path/to/wp npm run test:wp                              # 83 checks: cookie list, browser-scan rules, settings, email, policy page, cache clearing (WP-CLI)
SCCM_E2E_URL=… SCCM_WP=/path/to/wp npm run e2e:admin             # 35 checks of the admin screens, incl. a real browser scan
```

`test:wp` and `e2e:admin` reset the cookie list and settings of the site they run on: dev sites only.

## Manual test checklist (staging)

Use a private/incognito window for each scenario and open DevTools → Application → Cookies,
and Network. "Non-essential tags" = anything in Analytics/Functional/Marketing.

### A. First visit
- [ ] A1 Banner appears; Accept and Reject buttons look identical and are side by side.
- [ ] A2 Before any choice: no `_ga*`/marketing cookies, no requests to google-analytics /
      googletagmanager (Strict mode) or only cookieless pings (Advanced mode).
- [ ] A3 Blocked iframes show the placeholder; blocked fonts fall back to system fonts.
- [ ] A4 Reloading without choosing keeps showing the banner (no implied consent).

### B. Accept All (feature 1)
- [ ] B1 Banner closes, tags load without reload, `sccm_consent` cookie written.
- [ ] B2 Consent Log shows a row: choice `accept_all`, all categories, date/time, consent ID.

### C. Reject Non-Essential (feature 2)
- [ ] C1 Banner closes; no non-essential requests or cookies.
- [ ] C2 Consent Log row: choice `reject_all`, categories = necessary only.

### D. Manage Preferences (features 3, 10)
- [ ] D1 Necessary switch on + disabled; all others off (in the banner and in the window).
- [ ] D2 Expanding a category lists its cookies.
- [ ] D0 Default (compact) banner: Allow all, Deny, Customize. Customize opens the window with
      the tabs and Allow selection; Esc/close without choosing shows the banner again.
- [ ] D5 Banner style "Detailed": banner has Consent / Details / About tabs; Consent shows Necessary / Preferences /
      Statistics / Marketing switches; "Allow selection" releases only the ticked ones; the three
      buttons look identical; Banner tab → Button order changes the order (four orders; "Allow
      selection" / "Customize" share a place). No separate "Show details" link (the Details tab
      is the way in).
- [ ] D7 Choose "Allow all", then Banner tab → "Preview the banner": the preview looks like a
      first visit (no category switched on; About says you have not made a choice yet).
- [ ] D8 Button texts stay on one line in every position (bottom/top bar, corners, centre) on
      desktop and phone; in a wide bottom/top bar the buttons of the detailed style sit on the
      right at a natural width.
- [ ] D9 Theme look does not leak in: on a site whose theme styles buttons (e.g. Elementor
      global buttons) the banner buttons keep the plugin's size, font and colours, also on hover.
- [ ] D6 Details tab: each category opens to providers, each provider to cookie cards (name,
      purpose, maximum storage duration, type). About tab: choice, date and consent ID; Copy works.
- [ ] D3 Enable Analytics only → only analytics tags load; log row `custom` with `analytics`.
- [ ] D4 Tab/Shift+Tab stay inside the modal; Esc closes without saving.

### E. Change / withdraw (feature 4)
- [ ] E0 After updating the plugin on a site with NitroPack/CDN: purge once, then a private
      window shows the same banner as a logged-in browser (script URL is
      `…/uploads/sccm-assets/sccm-frontend.<hash>.js`).
- [ ] E1 Floating button, `[sccm_cookie_settings]` and a menu link to `#sccm-preferences`
      all open the cookie settings dialog.
- [ ] E3 Banner tab → cookie settings button: try all five positions. Corners show a round icon;
      bottom centre / left edge / right edge show a "Cookies" tab attached to the edge; no
      horizontal scrolling on mobile.
- [ ] E2 After Accept All, switch Analytics off and save → `_ga*` cookies deleted, page
      reloads, analytics no longer loads, new log row.

### F. Remember & re-ask (feature 6)
- [ ] F1 Reload/other pages → no banner, choice applied.
- [ ] F2 Admin → Tools → "Ask everyone again" → banner shows again on next page view.
- [ ] F5 Settings → "Remember the choice for": pick "Until the browser is closed" → the
      `sccm_consent` cookie is a session cookie; pick 3 months → it expires in about 90 days.
- [ ] F3 Add/approve a new cookie in the registry → banner shows again (if setting on).
- [ ] F4 Set consent period to 1 day, change system clock or edit cookie `t` → banner returns.

### G. Blocking (feature 7)
- [ ] G1 View page source: matching scripts have `type="text/plain" data-sccm-category`.
- [ ] G2 Manually tagged script only runs after its category is granted.
- [ ] G3 GTM/GA4 loaded by ID from settings respect consent.
- [ ] G4 `dataLayer` shows `consent default` (all denied) before any other event, then
      `consent update` after a choice.

### H. Consent records (feature 8)
- [ ] H1 Visitor's Consent ID (About tab) matches the log row.
- [ ] H2 CSV export contains consent ID, choice, categories, date/time, GPC, version, URL.
- [ ] H3 IP shown as anonymised/hashed according to the setting.

### I. Scanner (feature 9)
- [ ] I1 Add a test script that sets `test_unknown_cookie`; browse the site with two different
      browsers/devices (logged out) → after both, it appears under **Needs review**. A single
      browser, or a logged-in admin, never adds it.
- [ ] I2 Cookies tab → "Scan now": the progress shows step 1 (server) and step 2 (each page in
      your browser). Afterwards cookies set by scripts (e.g. `_ga`, HubSpot) and third-party
      cookies of embedded services (e.g. YouTube, Calendly) are listed in the right category.
      Compare the totals with Cookiebot's report for the same site.
- [ ] I3 Settings → Cookie scan and email alerts → add two addresses → "Send me a sample email":
      both receive a readable HTML email (check spam folder and a mail-logging plugin).
- [ ] I4 Approving a cookie needs a category choice; "Ignore all" empties the review list.
- [ ] I5 After updating the plugin, the admin screens look right without clearing any cache
      (assets are versioned by file time).
- [ ] I6 Open the sample email in a mail app in dark mode (Outlook, Gmail app, Apple Mail):
      readable, header not inverted into a light block, the "Review and approve" button has
      its padding.

### L. Cookie Policy page (feature 10)
- [ ] L1 On a dark theme the list is readable and uses the theme font; no words split in the
      middle ("Provider", "YouTube").
- [ ] L2 No widget sidebar on the page (Banner → Links → "Hide the sidebar"); with the option
      off the theme sidebar is back.
- [ ] L3 Approve a cookie (or run a scan that adds one) with NitroPack / WP Engine cache on: the
      page shows it after a reload without clearing the cache by hand.

### J. GPC (feature 11)
- [ ] J1 Enable GPC in the browser (Firefox: Settings → Privacy → "Tell websites not to sell
      or share my data"; Brave: on by default; Chrome: GPC extension from
      globalprivacycontrol.org). Check in console: `navigator.globalPrivacyControl === true`.
- [ ] J2 First visit with GPC: non-essential stays blocked; preferences show the
      "GPC signal honoured" notice; log row with choice `gpc` and GPC = yes.
- [ ] J3 With GPC on, clicking Accept All does not enable the categories covered by the GPC
      scope; notice explains why.
- [ ] J4 GPC scope "Advertising only": analytics can still be accepted, marketing cannot.

### K. Compatibility (feature 12)
- [ ] K1 With a page-cache plugin active, two different browsers get the same HTML and each
      sees its own correct state.
- [ ] K2 Language switcher / translation plugin translates banner text.
- [ ] K3 Mobile (375px): banner and modal usable, no horizontal scroll.
- [ ] K4 Export settings on site A → import on site B → same configuration.
- [ ] K5 With Cookiebot (or another consent plugin) still active, the Dashboard warns about it.
      Deactivate it so visitors see one banner.
- [ ] K6 AMP pages (official AMP plugin) show no banner and no rewritten scripts.
