# BarberLead – architecture decision and plan (Phase 0)

_Last updated: 2026-11-19_

## 1. Starting point

- The GitHub repository was empty (README only). There was no earlier Next.js CRM, database or
  records to migrate, so the project starts from scratch. The only existing real data is the
  hand-made demo **Prime Barbershop** (primebarbershop.testvane.org), imported by migration 0004.
- `barbersamokov.com.testvane.org` is **not** a demo: it is a leftover WordPress folder without
  WordPress core files (every request ends in a PHP fatal error, ~880 KB error_log). It was not imported.

## 2. Hosting audit (cPanel account "Бизнес", testvane.org, server sparkle.bulinfo.net)

Verified through the Bulinfo connector (read-only):

| Capability | Status |
|---|---|
| PHP | 8.4 (CloudLinux selector), extensions incl. `pdo_mysql`, `pdo_sqlite`, `mbstring`, `curl` via core, `zip`, `gd` |
| Database | MariaDB 10.11, unlimited databases/users (2 in use) |
| Cron | available (1 existing job: wp-cron runner) |
| Disk | 60 GB limit, ~0.7 GB used; inodes unlimited |
| Subdomains / addon domains | unlimited subdomains, 50 sites |
| SSL | AutoSSL available (connector `start_autossl`) |
| PHP limits | `memory_limit` 64M, `post_max_size` 2M (changeable in cPanel → Select PHP Version) |
| Node.js | owner confirms “Setup Node.js App” exists (not used, see §3) |
| SSH / Git Version Control | not verifiable through the connector – unknown |
| Deployment | Bulinfo connector: `start_deploy` → `add_deploy_file` → `apply_deploy`, with preview + confirmation and `rollback_deploy` |

Not possible on this hosting (shared): headless browsers, long-running workers, Docker/Redis services,
self-hosted AI models, heavy parallel crawling.

## 3. Decision

**PHP 8.4 + MariaDB, server-rendered, no framework, no build step.** Agreed with the owner.

Why not Next.js on the hosting: Node apps under CloudLinux/Passenger get killed on memory limits and
restart cold; it would add a build pipeline and a second runtime for no user-visible gain. PHP + MariaDB
is the stack already proven on this account (the booking demos).

- One centralised database on the hosting → usable from any device.
- No `vendor/` in production: own PSR-4 autoloader; dev tools (PHPUnit, PHPStan) only in CI/dev.
- Document root `crm.testvane.org` → `<project>/public`; code, config, backups and sessions live outside the web root.
- Secrets: `config/config.php` (0600, git-ignored), written by the one-time web installer – the owner
  types the DB and admin passwords into the installer, never into chat or Git.

## 4. Where work runs

| Workload | Runs on | Notes |
|---|---|---|
| CRM UI, records, offers, Kanban | hosting (PHP) | always available |
| Nightly DB backup | hosting cron → `bin/backup.php` | keeps 30 files |
| Neighborhood discovery (Phase 2) | hosting cron, small batches | OSM Overpass + Google Places (free monthly quota, needs owner's Google Cloud key) |
| Light website checks (Phase 3) | hosting cron | HTTP status, SSL, viewport meta, response time, booking links |
| Screenshots / visual review, demo generation (Phase 3–5) | Claude Code session (cloud or local) | uses the owner's subscription; not callable from the CRM backend |

Job model (Phase 2+): a `jobs` table (`queued → running → done/failed`, `locked_until`, `attempts`,
`heartbeat_at`). Cron claims jobs with an atomic `UPDATE … WHERE locked_until < NOW()` so runs never
overlap; each run works for at most ~40 s and leaves the rest for the next run. The UI shows the real
state, including “no worker has reported for N minutes”.

## 5. Claude Code options (subscription, no API key)

| Option | Unattended? | Fit |
|---|---|---|
| Interactive Claude Code (owner starts it) | no | Phase 4 start: “make a demo for the next lead in the queue” |
| Claude Code cloud sessions + scheduled tasks | yes, on a schedule | Phase 5 candidate to keep the 5-demo queue filled; counts against subscription limits; needs network access to crm.testvane.org |
| Anthropic API from the CRM | yes | **not available** – would need a separately paid API key |
| Local worker on the owner's Linux PC | while the PC is on | fallback; UI must show it offline when the PC is off |

The CRM exposes the queue and accepts results (Phase 4–5); Claude sessions pull work and push results
through an authenticated endpoint or the Bulinfo deploy connector. Nothing in the CRM pretends that
generation happens by itself.

## 6. Checklist

- [x] Phase 0 – audit, decision, plan (this document)
- [x] Phase 1 – CRM core: login (bcrypt, lockout, CSRF, secure session, audit log), Sofia district /
      neighborhood tree with search, business list with filters/sorting/pagination, business profile,
      manual create/edit with duplicate warning, history & notes, „Пусната оферта“ with follow-ups,
      Kanban (drag & drop + select), settings (outreach template, password, backups), installer,
      migrations with rollback, backup/restore, tests, CI
- [x] Deploy Phase 1 to crm.testvane.org (subdomain, MariaDB `martest_barberlead`, nightly backup cron)
- [x] Phase 2 – discovery:
  - `jobs` table with atomic claiming (`lock_token`/`locked_until`), one active scan per area (unique
    generated column), slices of ≤40 s, back-off retries (1/5/15 min, 3 attempts), cancel, schedules
  - runs from the open area page (JS calls `/jobs/{id}/run`) **and** from cron (`bin/worker.php`, every 5 min)
  - sources: OpenStreetMap Overpass (free) + Google Places Text Search (optional key, monthly request cap,
    usage counter; Google fields other than the place id are purged after 30 days)
  - OSM boundary import: Sofia districts (admin_level 9) and neighborhoods (place=suburb/quarter/
    neighbourhood) → areas get polygons, wrong district parents are corrected, unknown neighborhoods added
  - point-in-polygon area assignment; a neighborhood without its own OSM polygon scans its district and says so
  - relevance classification (barbershop / unclear hairdresser / women-only or beauty → hidden by default)
  - identity resolution: source id → phone → own website domain → Instagram → name similarity + distance;
    ≥0.85 attach, 0.5–0.85 new record + duplicate review queue, never silent merges; manual merge/dismiss
  - provenance: `business_sources` keeps each source's values + last seen; conflicts logged in history
  - `website_status = none` only when OSM **and** Google answered and neither listed a website
- [ ] Phase 3 – website finder/classifier (light checks on hosting), lead scoring with explanations,
      manual overrides; screenshots via Claude sessions
- [ ] Phase 4 – design brief from verified facts; Claude Code demo-generation workflow; static demo
      template with per-business design; licensed images only
- [ ] Phase 5 – 5-slot approval queue with DB-level locking, deploy to `<slug>.testvane.org`
      (noindex, unguessable slug), revisions and version history, rollback
- [ ] Phase 6 – nightly backup cron, restore drill, monitoring page, optional 2FA (TOTP)

## 7. Known limitations

Phase 2:
- OSM coverage of Sofia barbershops is incomplete; Google Places fills most gaps but needs the owner's key.
- Google Places terms restrict storing Places content. The CRM keeps the place id, refreshes data on rescans
  and purges raw Google data after 30 days; values copied into the business record (phone, website) are what
  the owner works with day to day — review this against Google's current terms if the CRM is ever shared.
- “Завършено” (done) means the configured sources were processed, not that every real business was found.
- Sofia neighborhoods that OSM does not map as polygons are assigned to their district only.

Phase 1:

- No automatic discovery yet; businesses are added manually.
- Neighborhood → district mapping is seeded from general knowledge and marked `approx` until the OSM import.
- 2FA is not implemented yet (planned for Phase 6).
- PHPStan runs only in CI (could not be executed in the development container).
