# Testing

## Automated checks (run before every commit)

```bash
npm install          # first time only (dev dependency: Playwright)
npm test             # php -l on every PHP file, node --check on JS, PHP unit tests (tests/php/run.php)
SCCM_E2E_URL=http://127.0.0.1:8080 npm run e2e   # 40 browser checks (see tests/e2e/README.md)
```

End-to-end (optional, local): `tests/e2e/README.md` explains how to run WordPress locally
with SQLite and drive it with Playwright.

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
- [ ] D1 Necessary switch on + disabled; all others off.
- [ ] D2 Expanding a category lists its cookies.
- [ ] D3 Enable Analytics only → only analytics tags load; log row `custom` with `analytics`.
- [ ] D4 Tab/Shift+Tab stay inside the modal; Esc closes without saving.

### E. Change / withdraw (feature 4)
- [ ] E1 Floating button, `[sccm_cookie_settings]` and a menu link to `#sccm-preferences`
      all open the preferences window.
- [ ] E2 After Accept All, switch Analytics off and save → `_ga*` cookies deleted, page
      reloads, analytics no longer loads, new log row.

### F. Remember & re-ask (feature 6)
- [ ] F1 Reload/other pages → no banner, choice applied.
- [ ] F2 Admin → Tools → "Ask everyone again" → banner shows again on next page view.
- [ ] F3 Add/approve a new cookie in the registry → banner shows again (if setting on).
- [ ] F4 Set consent period to 1 day, change system clock or edit cookie `t` → banner returns.

### G. Blocking (feature 7)
- [ ] G1 View page source: matching scripts have `type="text/plain" data-sccm-category`.
- [ ] G2 Manually tagged script only runs after its category is granted.
- [ ] G3 GTM/GA4 loaded by ID from settings respect consent.
- [ ] G4 `dataLayer` shows `consent default` (all denied) before any other event, then
      `consent update` after a choice.

### H. Consent records (feature 8)
- [ ] H1 Visitor's Consent ID (preferences window) matches the log row.
- [ ] H2 CSV export contains consent ID, choice, categories, date/time, GPC, version, URL.
- [ ] H3 IP shown as anonymised/hashed according to the setting.

### I. Scanner (feature 9)
- [ ] I1 Add a test script that sets `test_unknown_cookie` → it appears as Pending.
- [ ] I2 Scanner tab → "Scan now" lists detected services and cookies.
- [ ] I3 Email digest received (check with a mail-logging plugin on staging).

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
