# Roadmap

Tick items (`[x]`) as they are completed and log each session in `PROGRESS.md`.

## Phase 0 — Planning & docs
- [x] Analyse requirements (12 features) and choose approach → `ARCHITECTURE.md`
- [x] Project docs: AGENTS.md, CLAUDE.md, FEATURES.md, ROADMAP.md, PROGRESS.md, TESTING.md

## Phase 1 — Core (MVP foundations)
- [ ] Plugin bootstrap, constants, activation/deactivation, uninstall
- [ ] Install: DB tables `sccm_cookies`, `sccm_consent_log`; default options; upgrade routine
- [ ] Settings class with defaults, sanitising, import/export JSON
- [ ] Categories class (4 fixed keys, labels, Consent Mode mapping)
- [ ] Cookie registry (CRUD, wildcard matching, pending items, version bump on change)
- [ ] Service library (GA4, GTM, Google Ads, Meta Pixel, LinkedIn, TikTok, Hotjar, Clarity,
      HubSpot, YouTube, Vimeo, Google Maps, Google Fonts, reCAPTCHA, WordPress core, WooCommerce)

## Phase 2 — Visitor side (features 1–6, 10–12)
- [ ] Head boot script: Consent Mode default + returning-visitor update + config JSON
- [ ] Banner (3 layouts: bar, box, modal), equal buttons, accessible
- [ ] Preferences modal with toggles, cookie lists, consent ID, GPC notice
- [ ] Release engine (scripts sequential, iframes, links), placeholder for iframes
- [ ] Cookie/localStorage cleanup for denied categories; reload on withdrawal
- [ ] Re-ask logic (version, expiry, reject grace period)
- [ ] Floating settings button, `#sccm-preferences` links, `[sccm_cookie_settings]`
- [ ] GPC detection + scope setting
- [ ] Optimiser-exclusion attributes on own scripts

## Phase 3 — Server side (features 7–9)
- [ ] Output-buffer blocker for script/iframe/link tags (rules from settings + services)
- [ ] Optional GTM / GA4 loader by ID
- [ ] REST `/sccm/v1/consent` → consent log (validation, rate limit, IP anonymise/hash)
- [ ] REST `/sccm/v1/report` → pending cookies (validation, rate limit)
- [ ] Server scanner (cron + manual) + service detection
- [ ] Email digest for new items (max 1/day)
- [ ] Log retention purge (daily cron)

## Phase 4 — Admin
- [ ] Menu + tabs: General, Appearance, Categories, Cookies, Blocking, Scanner, Consent Log, Tools
- [ ] Cookies tab: list, add/edit/delete, approve pending, add from library
- [ ] Consent Log tab: list, search, date filter, CSV export, purge
- [ ] Tools tab: export/import settings JSON, "Ask everyone again", create Cookie Policy page
- [ ] `[sccm_cookie_policy]` shortcode

## Phase 5 — Quality
- [ ] Automated checks: `php -l`, `node --check`, JS unit tests (`npm test`)
- [ ] Local WordPress end-to-end run of TESTING.md (all 12 features)
- [ ] readme.txt, CHANGELOG, `.pot` file
- [ ] Release 1.0.0 (tag + zip)

## Later (not in scope for 1.0)
- Geo-targeting (different banner per region)
- IAB TCF v2.x / Google-certified CMP
- Multisite network-level settings
- Block editor block for the cookie policy
- Consent sharing across subdomains
- "Do Not Sell or Share" link mode for US state laws
