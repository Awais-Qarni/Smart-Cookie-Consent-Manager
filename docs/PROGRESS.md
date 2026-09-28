# Progress log

Newest entry first. Every session ends with an entry: **Done / Next / Notes & blockers**.
Anyone (human or AI) continuing the work should start from the latest "Next".

---

## Current status

- **Version:** 0.1.0-dev
- **Phase:** see `ROADMAP.md`
- **Last updated:** 2026-09-28

---

## 2026-09-28 — Session 1 (planning)

**Done**
- Analysed requirements (12 features agreed with Compliance) and chose the architecture:
  static cache-safe HTML + browser-side consent state + server-side tag rewriting
  (see `ARCHITECTURE.md`).
- Wrote project docs: `AGENTS.md`, `CLAUDE.md`, `FEATURES.md`, `ARCHITECTURE.md`,
  `ROADMAP.md`, `TESTING.md`, this file.

**Next**
- Phase 1 (core) → Phase 2 (visitor side) → Phase 3 (server side) → Phase 4 (admin).

**Notes**
- Plugin must stay generic (plug and play) — no site-specific names or code.
