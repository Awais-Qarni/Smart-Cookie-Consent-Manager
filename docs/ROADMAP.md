# Roadmap

Tick items (`[x]`) as they are completed and log each session in `PROGRESS.md`.

## Phase 0 — Planning & docs
- [x] Analyse requirements (12 features) and choose approach → `ARCHITECTURE.md`
- [x] Project docs: AGENTS.md, CLAUDE.md, FEATURES.md, ROADMAP.md, PROGRESS.md, TESTING.md

## Phase 1 — Core (MVP foundations)
- [x] Plugin bootstrap, constants, activation/deactivation, uninstall
- [x] Install: DB tables `sccm_cookies`, `sccm_consent_log`; default options; upgrade routine
- [x] Settings class with defaults, sanitising, import/export JSON
- [x] Categories class (4 fixed keys, labels, Consent Mode mapping)
- [x] Cookie registry (CRUD, wildcard matching, pending items, version bump on change)
- [x] Service library (GA4, GTM, Google Ads, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity,
      HubSpot, YouTube, Vimeo, Google Maps, Google Fonts, reCAPTCHA, WordPress core, WooCommerce)

## Phase 2 — Visitor side (features 1–6, 10–12)
- [x] Head boot script: Consent Mode default + returning-visitor update + config JSON
- [x] Banner (3 layouts: bar, box, modal), equal buttons, accessible
- [x] Preferences modal with toggles, cookie lists, consent ID, GPC notice
- [x] Release engine (scripts sequential, iframes, links), placeholder for iframes
- [x] Cookie/localStorage cleanup for denied categories; reload on withdrawal
- [x] Re-ask logic (version, expiry, reject grace period)
- [x] Floating settings button, `#sccm-preferences` links, `[sccm_cookie_settings]`
- [x] GPC detection + scope setting
- [x] Optimiser-exclusion attributes on own scripts

## Phase 3 — Server side (features 7–9)
- [x] Output-buffer blocker for script/iframe/link tags (rules from settings + services)
- [x] Optional GTM / GA4 loader by ID
- [x] REST `/sccm/v1/consent` → consent log (validation, rate limit, IP anonymise/hash)
- [x] REST `/sccm/v1/report` → pending cookies (validation, rate limit)
- [x] Server scanner (cron + manual) + service detection
- [x] Email digest for new items (max 1/day)
- [x] Log retention purge (daily cron)

## Phase 4 — Admin
- [x] Menu + tabs: General, Appearance, Texts, Categories, Cookies, Blocking, Scanner, Consent Log, Tools
      (v0.1.0; **simplified in Phase 5b to Dashboard, Cookies, Banner, Settings, Consent Records, Tools**)
- [x] Cookies tab: list, add/edit/delete, approve pending, add from library
- [x] Consent Log tab: list, search, date filter, CSV export, purge
- [x] Tools tab: export/import settings JSON, "Ask everyone again", create Cookie Policy page
- [x] `[sccm_cookie_policy]` shortcode

## Phase 5 — Quality
- [x] Automated checks: `php -l`, `node --check`, PHP unit tests (`npm test`)
- [x] Local WordPress end-to-end run of TESTING.md (all 12 features)
- [x] readme.txt, CHANGELOG
- [ ] `.pot` translation template (generate with `wp i18n make-pot . languages/smart-cookie-consent-manager.pot`)
- [ ] Staging test on a real site with the TESTING.md checklist (incl. Compliance review)
- [ ] Release 1.0.0 (tag + zip)

## Phase 5b — Pre-staging changes requested after v0.1.0 (2026-10-05)
- [x] Simpler admin: Dashboard (status, to-do, key numbers), 6 tabs, Help panel
- [x] Cookiebot-style category names; category switches in the banner; consent status/ID/date
- [x] Quiet scanner: short accurate cookie list, Needs review inbox, upgrade clean-up
- [x] Optional consent expiry (presets, custom days, session); five widget positions incl. edge tabs
- [x] Round 2 (owner feedback): cache-busted assets, Cookiebot-style tabbed dialog (Consent /
      Details / About), button order, "Cookies" edge tabs, browser scan + third-party cookie library
- [x] Round 3 (owner feedback): compact banner style (default) + Customize, hashed asset files
      (cache-proof on NitroPack/CDNs), NitroPack/GTranslate/chat/pixel services, menu pages
      in the scan, scan diagnostics, Help button alignment
- [x] HTML alert email, several recipients, sample email
- [x] Performance review (see `PERFORMANCE.md`)
- [ ] Review against the live Cookiebot banner and account (not reachable from the build environment)

## Later (not in scope for 1.0)
- Geo-targeting (different banner per region)
- IAB TCF v2.x / Google-certified CMP
- Multisite network-level settings
- Block editor block for the cookie policy
- Consent sharing across subdomains
- "Do Not Sell or Share" link mode for US state laws
- Server-set consent cookie (Safari caps JavaScript-set cookies at 7 days)
