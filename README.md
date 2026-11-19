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

1. cPanel: create the subdomain `crm.testvane.org` with document root `crm.testvane.org/public`.
2. cPanel → MySQL Databases: create database + user (e.g. `martest_barberlead`), grant ALL on that database.
3. Generate the installer code locally: `php bin/create-setup-token.php` (prints the code, writes `config/setup_token.hash`).
4. Upload the project **without** `config/config.php`, `storage/backups/*`, `tests/`, `.git/`, but **with** `config/setup_token.hash`.
5. Open https://crm.testvane.org, enter the installer code, the DB password and your CRM password.
   The installer writes `config/config.php`, runs the migrations, creates your user and disables itself.

Updates: upload the changed files, then run the migrations (they take a backup first):

```bash
php bin/migrate.php up
```

## Backups

- Manual: Settings → „Направи бекъп сега“, or `php bin/backup.php`.
- Nightly cron (cPanel → Cron Jobs), keeps the newest 30:
  `17 3 * * * /usr/local/bin/php /home/<user>/crm.testvane.org/bin/backup.php 30 >/dev/null`
- Restore (takes a safety backup first): `php bin/restore.php storage/backups/<file>.sql.gz --yes`
- Backups live in `storage/backups/` (outside the web root, 0600). Download a copy now and then.

## Migrations

`migrations/NNNN_name.up.(sql|php)` + `.down.(sql|php)`. `php bin/migrate.php status|up|down`.
`down` always backs up first and refuses rollbacks that drop tables unless `--force` is given.
