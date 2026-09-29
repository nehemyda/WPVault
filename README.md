# WPVault

Local WordPress backup, restore, and migration. Chunked, resumable, and verified — built to actually finish and actually restore.

> Early-stage / not yet released on WordPress.org. `readme.txt` in this repo is the WordPress.org-format changelog; this file is for anyone setting up or developing the plugin itself.

## What it does

- **Backup** — full (database + files), database-only, or files-only, packaged into a single versioned `.wpvault` file
- **Chunked and resumable** — a timeout or closed browser tab doesn't restart a backup from zero; a `wp_cron` safety net keeps an interrupted job moving even if nobody is watching
- **Verified** — SHA-256 checksums on every package; a backup is only ever marked "Verified" once its structure and contents are confirmed intact
- **Restore** — any verified backup back onto the site, with an automatic safety snapshot of the current state taken first by default. `wp-config.php` and `.htaccess` are never overwritten
- **Migrate** — import a `.wpvault` package made on a *different* site, then restore it here; serialization-aware URL replacement handles the domain change. Bring the package in by uploading it directly, or by picking it from a connected Google Drive/OneDrive account if it was saved there
- **Scheduled backups** — daily or weekly, at a set time, with automatic retention (keeps the N most recent *scheduled* backups only -- manual, CLI, and imported backups are never pruned)
- **Pre-update backups** — automatically backs up right before a plugin, theme, or core update applies (including background auto-updates and WP-CLI updates), never blocking or failing the update itself
- **Google Drive** — save a copy of any verified backup to your own Google Drive, connected with one click (no Google Cloud project or OAuth app to set up yourself): on demand from a dropdown next to each backup, automatically for every scheduled backup, or automatically for a one-off "Backup Now" -- an expired or revoked connection is detected and surfaced clearly rather than failing silently
- **OneDrive** — the same save-a-copy feature, to your own OneDrive instead, with the same one-click device-code connect flow. Drive and OneDrive can both be enabled at once; a backup uploads to each in turn, and either one failing never affects the other or the backup itself
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

3. Open **WPVault** in the wp-admin sidebar: Dashboard, Backups, and Settings. Backups is the hub for everything backup-related — create, import, restore, and history all live on that one screen, with create/import/restore each opening as a popup rather than a separate page. Scheduled backups, pre-update backups, Google Drive, and OneDrive are all configured on the Settings screen.

Scheduled backups run through the same once-a-minute `wp_cron` safety net as everything else in this plugin: a separate 15-minute tick checks whether the configured time has arrived and, if so, starts a backup the exact same way the "Create Backup" button does. Like any `wp_cron` schedule, it only actually fires on a page load (or a real system cron hitting `wp-cron.php`) -- a site with zero traffic overnight won't back itself up until someone visits.

Pre-update backups work differently: they hook `upgrader_pre_install`, the filter WordPress's own updater runs through right before touching files, and drive the backup job inline for up to 20 seconds before letting the update proceed regardless (the usual `wp_cron` safety net finishes the backup afterward if it needed more time). This covers plugin, theme, and core updates from wp-admin, WP-Cron background auto-updates, and WP-CLI's `wp plugin update` / `wp core update` alike, since they all go through the same code path. A bulk update in wp-admin fires that filter once per item (as separate requests, not a loop in one), so the feature uses a short-lived transient lock to collapse one browser bulk-update action into a single backup rather than one per plugin.

**Google Drive** connects once and stays connected: Backups are always created locally first, exactly as before -- Drive is an extra copy, uploaded in chunks via a resumable session the same way everything else in this plugin is chunked, in any of three ways: on demand from a dropdown next to a verified backup ("Download to Local" vs "Save to Google Drive"), automatically after every scheduled backup (a checkbox on the Scheduled Backups settings once connected), or automatically for a one-off "Backup Now" (a checkbox in the Create Backup popup). In all three cases a Drive failure never fails the backup itself -- the local backup stays exactly as verified, just without a Drive copy, and the reason is logged. Connecting is one click: WPVault uses the OAuth 2.0 Device Authorization Grant against one Client ID shared by every install (no redirect URI needed, so it works identically on any domain) -- "Connect Google Drive" shows a short code, you enter it at a Google-hosted page, and WPVault detects the approval and finishes connecting on its own; no Google Cloud project or OAuth app to create yourself. The requested scope is `drive.file`, which only ever grants access to files this plugin itself creates in your Drive, never your existing files. If Google ever rejects the stored connection outright (revoked from your Google account, or the same Google account already granted this shared Client ID a connection for another site), WPVault detects that specifically and Settings shows a clear "reconnect" prompt instead of the same generic failure a transient network error would show.

**OneDrive** works the same way as Google Drive, as a fully separate sibling implementation rather than a shared abstraction (`One_Drive`, `Onedrive_Upload_Job` -- deliberately not built on `Storage_Adapter` either): the same three upload triggers (on-demand dropdown, scheduled backups, "Backup Now"), the same never-fail-the-backup-on-upload-failure behavior, and the same one-click connect, using Microsoft's OAuth 2.0 Device Authorization Grant against one shared, public-client Azure app registration (no client secret, so it's safe to ship in the plugin) with the `Files.ReadWrite.AppFolder` scope -- access to a single app-specific OneDrive folder only, never your existing files. Unlike Google, Microsoft rotates the refresh token on every use, which WPVault accounts for when persisting it. If both Drive and OneDrive are enabled for the same backup, the upload phases run one after the other (Drive, then OneDrive), not in parallel, since a job occupies one upload phase at a time; either one failing doesn't block the other from being attempted.

**Importing from Google Drive/OneDrive** is the reverse direction of the same connections: the Import popup lists `.wpvault` files already sitting in whichever provider is connected (`Google_Drive`/`One_Drive::list_backup_files()`) and, when one is picked, downloads it straight from that provider to this server -- no browser upload involved, which is exactly why this path *is* a chunked job (`Drive_Import_Job` / `Onedrive_Import_Job`) unlike a local-file import (see `import_init()`'s own docblock for why that one isn't). Both download in chunks via HTTP Range requests and finish through the same validate-then-finalize pipeline a browser upload uses (extracted into `Import_Finalizer` so all three import paths -- browser upload, Drive, OneDrive -- share it). Since `drive.file`/`Files.ReadWrite.AppFolder` only ever grants access to files that *app* created, this only ever lists WPVault's own uploads, but that includes ones made from a *different* site connected under the same account -- a straightforward way to move a backup between two sites without touching a local file at all. OneDrive's download needs an extra step Google's doesn't: Graph's `/content` endpoint 302s to a `my.microsoftpersonalcontent.com` URL with its own pre-signed `tempauth`, and that host rejects the request outright if our Graph-scoped Authorization header is forwarded to it -- so `One_Drive::resolve_download_url()` resolves that URL once (without auto-following the redirect) and every chunk after that is fetched from it with no Authorization header, mirroring how the upload side's resumable session URL already works.

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
  backup/                    Scanner, database exporter, package builder, backup store, Import_Finalizer
  jobs/                      The chunked/resumable job engine (Job_Store, Job_Runner, Cron_Runner, Scheduled_Backups, Pre_Update_Backups, Drive_Upload_Job, Onedrive_Upload_Job, Drive_Import_Job, Onedrive_Import_Job)
  restore/                   Extractor, database importer, URL replacer, restore phase machine
  storage/                   Local storage adapter (wp-content/wpvault/), Google Drive and OneDrive OAuth + API clients
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
- **Backups are always created locally first.** The `Storage_Adapter` interface exists for a future backup-engine-level remote backend, but Google Drive and OneDrive don't implement it -- each is deliberately a separate, on-demand "send an existing local backup there too" feature (`Google_Drive`/`Drive_Upload_Job` and `One_Drive`/`Onedrive_Upload_Job`, kept as parallel siblings rather than a shared cloud-storage abstraction), not a replacement for where backups themselves get made.

## License

GPLv2 or later — see `readme.txt`.
