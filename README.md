# WPVault

Local WordPress backup, restore, and migration. Chunked, resumable, and verified — built to actually finish and actually restore.

> Early-stage / not yet released on WordPress.org. `readme.txt` in this repo is the WordPress.org-format changelog; this file is for anyone setting up or developing the plugin itself.

## What it does

- **Backup** — full (database + files), database-only, or files-only, packaged into a single versioned `.wpvault` file
- **Chunked and resumable** — a timeout or closed browser tab doesn't restart a backup from zero; a `wp_cron` safety net keeps an interrupted job moving even if nobody is watching
- **Verified** — SHA-256 checksums on every package; a backup is only ever marked "Verified" once its structure and contents are confirmed intact
- **Restore** — any verified backup back onto the site, with an automatic safety snapshot of the current state taken first by default. `wp-config.php` and `.htaccess` are never overwritten
- **Migrate** — import a `.wpvault` package made on a *different* site, then restore it here; serialization-aware URL replacement handles the domain change
- **Scheduled backups** — daily or weekly, at a set time, with automatic retention (keeps the N most recent *scheduled* backups only -- manual, CLI, and imported backups are never pruned)
- **WP-CLI** — `wp wpvault backup|backups|verify|restore|status|schedule|cleanup`, the same engine as the admin UI

See `readme.txt` for the full feature list and version history.

## Requirements

- PHP 7.4+
- WordPress 6.2+
- The PHP `zip` extension (checked by the plugin's own preflight before a backup or restore starts)

## Setup (local development)

1. Clone this repo somewhere outside your WordPress install, then symlink it into a site's plugins directory:

   ```bash
   ln -s /path/to/WPVault /path/to/your-site/wp-content/plugins/wpvault
   ```

2. Activate it — via wp-admin (**Plugins → WPVault → Activate**) or WP-CLI:

   ```bash
   wp plugin activate wpvault
   ```

   Activation creates three database tables (`wp_wpvault_backups`, `wp_wpvault_jobs`, `wp_wpvault_logs`) and the local storage directory at `wp-content/wpvault/` (`backups/`, `temp/`, `logs/`), each protected from direct web access.

3. Open **WPVault** in the wp-admin sidebar: Dashboard, Backups, and Settings. Backups is the hub for everything backup-related — create, import, restore, and history all live on that one screen, with create/import/restore each opening as a popup rather than a separate page. Scheduled backups are configured on the Settings screen.

Scheduled backups run through the same once-a-minute `wp_cron` safety net as everything else in this plugin: a separate 15-minute tick checks whether the configured time has arrived and, if so, starts a backup the exact same way the "Create Backup" button does. Like any `wp_cron` schedule, it only actually fires on a page load (or a real system cron hitting `wp-cron.php`) -- a site with zero traffic overnight won't back itself up until someone visits.

No build step — no Composer or npm dependencies. It's plain PHP (namespaced, PSR-ish autoloader) and vanilla JS/CSS.

## WP-CLI

```bash
wp wpvault backup [--type=full|database|files] [--exclude-cache=<bool>]
wp wpvault backups [--format=table|csv|json|yaml|count]
wp wpvault verify <backup-id>
wp wpvault restore <backup-id> [--yes] [--snapshot=<bool>] [--old-url=<url>] [--new-url=<url>]
wp wpvault status <job-id>
wp wpvault schedule
wp wpvault cleanup
```

`schedule` is read-only -- change the configuration from the Settings screen in wp-admin.

`wp help wpvault <command>` for full option details on any of them.

## Project structure

```
wpvault.php                  Plugin bootstrap: headers, constants, activation/deactivation hooks
uninstall.php                Drops WPVault's own tables/options on uninstall; leaves backup files in place
includes/
  class-autoloader.php       WPVault\Foo\Bar_Baz -> includes/foo/class-bar-baz.php
  class-plugin.php           Central wiring: DB tables, cron, REST, admin, WP-CLI
  class-activator.php        class-deactivator.php
  capabilities.php           manage_wpvault capability
  backup/                    Scanner, database exporter, package builder, backup store
  jobs/                      The chunked/resumable job engine (Job_Store, Job_Runner, Cron_Runner, Scheduled_Backups)
  restore/                   Extractor, database importer, URL replacer, restore phase machine
  storage/                   Local storage adapter (wp-content/wpvault/)
  security/                  Path-traversal guard used during extraction
  diagnostics/               Preflight checks, log store
  admin/                     The five wp-admin screens + the download handler
  rest/                      REST API controllers backing the admin UI
  cli/                       WP-CLI commands
assets/
  css/, js/                  Admin screen styling and REST polling logic
  WPVault_Local_MVP_Technical_Specification.docx   Original product/technical spec this plugin implements
```

## Architecture notes

- **Everything is a job.** Backup and restore both run as chunked jobs in `wpvault_jobs` — a bounded amount of work per `Job_Runner::step()` call, driven either by the browser polling a REST endpoint or by a once-a-minute cron tick if the browser goes away mid-job. Neither the admin UI, the REST API, nor WP-CLI have their own copy of the engine; they all call the same job/backup/restore classes.
- **The package format is versioned** (`manifest.json`'s `format_version`) so newer plugin versions can keep reading older backups.
- **Local storage only in this version.** The storage layer sits behind a `Storage_Adapter` interface specifically so a future remote-storage adapter is a new class, not a rewrite.

## Not in this version yet

- Remote/cloud storage
- Automatic pre-update backups (backing up right before a plugin/theme/core update)

## License

GPLv2 or later — see `readme.txt`.
