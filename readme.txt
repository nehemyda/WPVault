=== WPVault ===
Contributors: wpvault
Tags: backup, restore, migration, database, export
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.4.0
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
* WP-CLI: `wp wpvault backup`, `backups`, `verify`, `restore`, `status`, `cleanup` -- the same engine as the admin UI, useful for cron-driven backups and scripted restores

**Not in this version yet:** remote storage, and resumable/chunked upload for very large import files (a single import is bounded by this server's own upload size limit, shown on the Backups screen).

== Where backups are stored ==

Packages are written to `wp-content/wpvault/backups/`, a directory this plugin protects from direct web access. Removing the plugin does not delete files already stored there -- see `uninstall.php` for exactly what is and isn't removed.

== Changelog ==

= 0.4.0 =
* WP-CLI commands: backup, backups, verify, restore, status, cleanup -- reuse the same engine as the admin UI.

= 0.3.0 =
* Import: bring a .wpvault package made on a different site into Backups, then restore it with the existing restore engine.

= 0.2.0 =
* Restore workflow: safety snapshots, path-guarded file extraction, chunked database import, serialization-aware URL replacement.

= 0.1.0 =
* Initial release: local backup engine (scan, database export, packaging, verification), backup history, and the admin UI to drive them.
