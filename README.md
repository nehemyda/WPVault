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
- **Pre-update backups** — automatically backs up right before a plugin, theme, or core update applies (including background auto-updates and WP-CLI updates), never blocking or failing the update itself
- **Google Drive** — save a copy of any verified backup to your own Google Drive, connected once via your own Google Cloud OAuth app: on demand from a dropdown next to each backup, automatically for every scheduled backup, or automatically for a one-off "Backup Now" -- an expired or revoked connection is detected and surfaced clearly rather than failing silently
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

3. Open **WPVault** in the wp-admin sidebar: Dashboard, Backups, and Settings. Backups is the hub for everything backup-related — create, import, restore, and history all live on that one screen, with create/import/restore each opening as a popup rather than a separate page. Scheduled backups, pre-update backups, and Google Drive are all configured on the Settings screen.

Scheduled backups run through the same once-a-minute `wp_cron` safety net as everything else in this plugin: a separate 15-minute tick checks whether the configured time has arrived and, if so, starts a backup the exact same way the "Create Backup" button does. Like any `wp_cron` schedule, it only actually fires on a page load (or a real system cron hitting `wp-cron.php`) -- a site with zero traffic overnight won't back itself up until someone visits.

Pre-update backups work differently: they hook `upgrader_pre_install`, the filter WordPress's own updater runs through right before touching files, and drive the backup job inline for up to 20 seconds before letting the update proceed regardless (the usual `wp_cron` safety net finishes the backup afterward if it needed more time). This covers plugin, theme, and core updates from wp-admin, WP-Cron background auto-updates, and WP-CLI's `wp plugin update` / `wp core update` alike, since they all go through the same code path. A bulk update in wp-admin fires that filter once per item (as separate requests, not a loop in one), so the feature uses a short-lived transient lock to collapse one browser bulk-update action into a single backup rather than one per plugin.

**Google Drive** connects once and stays connected: Backups are always created locally first, exactly as before -- Drive is an extra copy, uploaded in chunks via a resumable session the same way everything else in this plugin is chunked, in any of three ways: on demand from a dropdown next to a verified backup ("Download to Local" vs "Save to Google Drive"), automatically after every scheduled backup (a checkbox on the Scheduled Backups settings once connected), or automatically for a one-off "Backup Now" (a checkbox in the Create Backup popup). In all three cases a Drive failure never fails the backup itself -- the local backup stays exactly as verified, just without a Drive copy, and the reason is logged. There's no shared/shipped OAuth credential: a self-hosted, open-source plugin has no backend of its own to broker one safely, so connecting it means creating your own free Google Cloud OAuth app (Client ID + Secret, redirect URI shown on the Settings screen) and completing the consent screen once -- after that, WPVault refreshes its own access token automatically and no further login is needed, for any of the three upload paths. The requested scope is `drive.file`, which only ever grants access to files this plugin itself creates in your Drive, never your existing files. If Google ever rejects the stored connection outright (revoked from your Google account, or the OAuth app's access removed), WPVault detects that specifically and Settings shows a clear "reconnect" prompt instead of the same generic failure a transient network error would show.

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
  jobs/                      The chunked/resumable job engine (Job_Store, Job_Runner, Cron_Runner, Scheduled_Backups, Pre_Update_Backups, Drive_Upload_Job)
  restore/                   Extractor, database importer, URL replacer, restore phase machine
  storage/                   Local storage adapter (wp-content/wpvault/), Google Drive OAuth + API client
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
- **Backups are always created locally first.** The `Storage_Adapter` interface exists for a future backup-engine-level remote backend, but Google Drive doesn't implement it -- it's deliberately a separate, on-demand "send an existing local backup there too" feature (`Google_Drive`, `Drive_Upload_Job`), not a replacement for where backups themselves get made.

## License

GPLv2 or later — see `readme.txt`.
