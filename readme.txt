=== WPVault ===
Contributors: wpvault
Tags: backup, restore, migration, database, export
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Local WordPress backup and restore. Chunked, resumable, and verified -- built to actually finish and actually restore.

== Description ==

WPVault backs up your WordPress database and files into a single versioned `.wpvault` package, stored locally on your own server.

**In this version:**

* Full (database + files), database-only, and files-only backups
* Chunked, resumable processing -- a timeout or closed browser tab doesn't restart the backup from zero. A background check every minute keeps an interrupted backup moving even if nobody is watching.
* SHA-256 checksum verification -- a backup is only ever marked "Verified" after its package, manifest, and components are all confirmed present and intact
* Backup history with download, on-demand re-verification, and deletion
* Plain-language preflight checks (PHP version, zip support, database connectivity, writable storage) before a backup starts
* Restore any verified backup back onto this site -- chunked and resumable the same way backups are, with an automatic safety snapshot of the current state taken first by default
* Serialization-aware URL replacement during restore, for when the site's URL has changed since the backup was made
* wp-config.php and .htaccess are never overwritten by a restore
* Import a `.wpvault` package made on a *different* site, then restore it here -- the same restore engine, safety snapshot, and URL replacement handle the rest
* Scheduled backups: daily or weekly, at a chosen time, with automatic retention that only ever prunes backups the schedule itself created
* Pre-update backups: automatically backs up right before a plugin, theme, or core update is applied -- including background auto-updates and WP-CLI updates -- without ever blocking or failing the update
* Google Drive: save a copy of any verified backup to your own Google Drive on demand, from a dropdown next to it on the Backups screen. Connect once with your own Google Cloud OAuth app (Client ID + Secret); WPVault only ever requests access to files it creates itself, never your existing Drive files
* WP-CLI: `wp wpvault backup`, `backups`, `verify`, `restore`, `status`, `schedule`, `cleanup` -- the same engine as the admin UI, useful for cron-driven backups and scripted restores

== Where backups are stored ==

Packages are written to `wp-content/wpvault/backups/`, a directory this plugin protects from direct web access. Removing the plugin does not delete files already stored there -- see `uninstall.php` for exactly what is and isn't removed.

== Changelog ==

= 0.9.0 =
* Google Drive: save a copy of any verified backup to Google Drive on demand from the Backups screen, uploaded in chunks. Connect your own Google Cloud OAuth app once from Settings; backups are always created locally first, Drive is only ever an extra copy you choose to send.

= 0.8.0 =
* Pre-update backups: automatically backs up right before a plugin, theme, or core update is applied, configured on the Settings screen. Never blocks or fails an update over a backup problem; retention keeps only the most recent N pre-update backups.

= 0.7.0 =
* Scheduled backups: daily or weekly, at a set time, configured on the Settings screen. Automatic retention keeps only the most recent N *scheduled* backups -- manual, CLI, and imported backups are never affected.

= 0.6.0 =
* Import now uploads in chunks instead of one request -- no longer limited by this server's upload size setting, only by free disk space.

= 0.5.1 =
* Dashboard's "Backup Now" and "Restore" now open straight into the matching popup on the Backups screen instead of just landing on the page.

= 0.5.0 =
* Backups screen: Restore is now a popup opened from a backup's own row, like Create Backup and Import Backup, instead of a separate screen. There is no longer a dedicated Restore admin page.

= 0.4.1 =
* Backups screen: Create Backup and Import Backup are now popups opened from two buttons, instead of always-open sections. Warns before leaving the page while either is in progress.

= 0.4.0 =
* WP-CLI commands: backup, backups, verify, restore, status, cleanup -- reuse the same engine as the admin UI.

= 0.3.0 =
* Import: bring a .wpvault package made on a different site into Backups, then restore it with the existing restore engine.

= 0.2.0 =
* Restore workflow: safety snapshots, path-guarded file extraction, chunked database import, serialization-aware URL replacement.

= 0.1.0 =
* Initial release: local backup engine (scan, database export, packaging, verification), backup history, and the admin UI to drive them.
