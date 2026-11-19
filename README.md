# BarberLead CRM

CRM for finding Bulgarian barbershops without a website (or with a weak one), tracking demo sites and
offers. Bulgarian UI, PHP 8.2+ and MariaDB/MySQL, runs on ordinary cPanel shared hosting.

Architecture, hosting audit and roadmap: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md).

## What works now (Phase 1)

- Secure single-owner login (bcrypt, lockout after 5 failed attempts, CSRF on every form, audit log)
- Sofia: 24 districts and their neighborhoods, plus Plovdiv, Varna and Burgas, in a searchable sidebar
- Business list: search (name, address, phone, neighborhood, Instagram, website), filters, sorting, pagination
- Business profile: data, website status, score, history, notes, demo links, outreach message with copy button
- **„Пусната оферта“**: records channel, amount, demo URL, note and a follow-up reminder (default 5 days).
  It never sends anything.
- Kanban (drag & drop, dropdown on the phone), offers page with reminders, dashboard with real figures
- Settings: outreach template, password change, manual backup

## Local development

```bash
cp config/config.example.php config/config.php   # set db + 'env' => 'development', 'secure_cookies' => false
php bin/migrate.php up
php bin/create-user.php martin                    # then type the password
php -S 127.0.0.1:8099 -t public bin/dev-router.php
```

Tests need a disposable MariaDB database named `*_test` (see `phpunit.xml`):

```bash
composer install
composer check        # php -l, PHPStan, PHPUnit
```

## Deploying to cPanel (crm.testvane.org)

1. cPanel: create the subdomain `crm.testvane.org` (document root = the project folder; the root `.htaccess` routes everything into `public/`).
2. cPanel → MySQL Databases: create database + user (e.g. `martest_barberlead`), grant ALL on that database.
3. Generate the installer code locally: `php bin/create-setup-token.php` (prints the code, writes `config/setup_token.php`).
4. Upload the project **without** `config/config.php`, `storage/backups/*`, `tests/`, `.git/`, but **with** `config/setup_token.php`.
5. Open https://crm.testvane.org, enter the installer code, the DB password and your CRM password.
   The installer writes `config/config.php`, runs the migrations, creates your user and disables itself.

Updates: upload the changed files, then run the migrations (they take a backup first):

```bash
php bin/migrate.php up
```

## Background worker (scans)

cPanel → Cron Jobs, every 5 minutes:
`*/5 * * * * /usr/local/bin/php /home/<user>/crm.testvane.org/bin/worker.php >/dev/null`

Scans also progress while the neighborhood page is open in the browser. Both paths use the same database
lock, so a job never runs twice at the same time.

## Google Places (optional)

1. Google Cloud Console → new project → enable **Places API (New)** → create an API key.
2. Restrict the key: API restriction “Places API (New)”, application restriction “IP addresses” = the hosting IP.
3. Set a quota cap in Google Cloud (APIs & Services → Places API (New) → Quotas) as a second safety net.
4. CRM → Настройки → Google Places: paste the key and keep the monthly cap below the free tier.

## Backups

- Manual: Settings → „Направи бекъп сега“, or `php bin/backup.php`.
- Nightly cron (cPanel → Cron Jobs), keeps the newest 30:
  `17 3 * * * /usr/local/bin/php /home/<user>/crm.testvane.org/bin/backup.php 30 >/dev/null`
- Restore (takes a safety backup first): `php bin/restore.php storage/backups/<file>.sql.gz --yes`
- Backups live in `storage/backups/` (outside the web root, 0600). Download a copy now and then.

## Migrations

`migrations/NNNN_name.up.(sql|php)` + `.down.(sql|php)`. `php bin/migrate.php status|up|down`.
`down` always backs up first and refuses rollbacks that drop tables unless `--force` is given.
