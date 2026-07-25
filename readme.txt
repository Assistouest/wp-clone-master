=== Clone Master ===
Contributors: adrienpiron
Tags: backup, migration, clone, restore, nextcloud
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.1.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create WP Commander-compatible append-only WPCM backups and perform staged WordPress restores with strict validation and automatic rollback.

== Description ==

Clone Master exports the WordPress database and site content into the native `.wpcm` format used by the WP Commander agent. Site backups are stored only as native WPCM containers.

The `WPCMARCHIVE2` container is append-only. Each bounded block carries its own SHA-256, completed bytes are never rewritten, and an interrupted writer resumes from the last durable authenticated offset. The final footer authenticates every preceding byte.

= Reliability features =

* Exact binary compatibility with WP Commander agent `WPCMARCHIVE2` backups.
* HMAC-authenticated durable state with monotonic sequence numbers and alternating recovery slots.
* Same-directory temporary writes, flush, optional fsync, and atomic publication by `rename()`.
* Non-blocking `flock()` locks to prevent concurrent mutation of one operation.
* Composite-aware database keyset export without `LIMIT/OFFSET` traversal.
* Fixed upper key boundaries, row-count validation, and normalized schema hashes.
* Grouped SQL inserts and exact WordPress percent-placeholder restoration.
* Block-by-block WPCM validation and atomic extraction.
* Independent, locked, SHA-256-verified browser upload parts.
* Database import under a temporary prefix before destination tables are changed.
* One atomic MySQL `RENAME TABLE` statement for the live database switch.
* Same-filesystem staged file promotion with a signed write-ahead rollback journal.
* Temporary must-use recovery bootstrap for interrupted destructive phases.
* Required health checks before rollback data is deleted.

= Percent-character integrity =

Clone Master never replaces `%` globally in a database dump. It refuses to persist unresolved WordPress placeholders matching `{64 hexadecimal characters}`. Serialized values are modified with a class-disabled token parser that recalculates string byte lengths and validates the resulting payload.

Automated tests preserve `/%postname%/`, `%%title%%`, `58%`, URLs containing `%20`, and serialized values containing those strings.

= Backup contents =

The WPCM container contains exactly one `database.sql` entry and zero or more regular files below `wp-content/`. The plugin can include WordPress-prefixed base tables, themes, plugins, uploads, must-use plugins, and WordPress language files.

For safety, Clone Master does not automatically restore `.htaccess`, `.user.ini`, `php.ini`, or `wp-config.php`, and it does not disable unrelated security plugins.

= Local storage and Nginx =

Published backups remain in:

`wp-content/wpcm-backups/`

Clone Master works without `.htaccess` and without `fastcgi_finish_request()`. Nginx users can add the optional fixed-path deny rule shown in the administration screen for stronger direct-access protection.

= Scheduled backups =

Manual and scheduled backups share one durable state machine. WP-Cron can schedule continuation, while authenticated administration polling can process fallback slices on hosts that block loopback cron requests.

= Nextcloud storage =

Administrators can optionally send completed `.wpcm` files to their own Nextcloud WebDAV endpoint. Credentials use AES-256-GCM authenticated encryption. URLs are restricted to public addresses, redirects are disabled, and streaming transfers pin validated DNS results when supported.

No analytics or telemetry is sent to the plugin author.

= Database limitations =

A non-empty table must have a primary key or another `UNIQUE NOT NULL` key. Keys may contain one or more columns. WordPress-prefixed views and triggers are rejected because dependency-aware transactional restore is not implemented for those objects.

A sliced live backup cannot be one database transaction across multiple HTTP requests. Clone Master freezes high-water keys and validates schema and row-count drift, but a maintenance window remains recommended for highly active transactional sites.

== Installation ==

1. Install and activate Clone Master through the WordPress plugin administration screen.
2. Open Clone Master in the administration menu.
3. Review server diagnostics before the first backup.
4. On Nginx, optionally apply the fixed backup-directory deny rule shown by the plugin.

== Frequently Asked Questions ==

= Are plugin backups compatible with WP Commander? =

Yes. Clone Master and the WP Commander agent use the same `WPCMARCHIVE2` binary layout, entry names, block size, hashes, manifest schema, and footer. A `.wpcm` backup produced by either implementation can be validated and imported by the other.

= Does Clone Master work with Nginx? =

Yes. Backup, upload, scheduling, download, and restore logic do not depend on Apache configuration. Cache-response request identifiers are diagnostic only; a proxy omitting them does not block a valid authenticated operation.

= Is restoration atomic? =

The database switch is one multi-table MySQL `RENAME TABLE` operation. File changes use same-filesystem renames recorded in a signed write-ahead journal. Because MySQL and the filesystem are separate systems, Clone Master coordinates their atomic transitions with maintenance mode, a health gate, and automatic rollback.

= What happens when PHP stops during a restore? =

Non-destructive work resumes from authenticated cursors. During destructive phases, alternating HMAC journals and a temporary must-use recovery bootstrap can infer completed renames and restore the previous database and files.

= Can it migrate HTTP to HTTPS or change domains? =

Yes. Replacement runs against staged tables before live promotion. Plain strings, JSON, and safe PHP serialized values are supported without global percent replacement.

= What PHP extensions are required? =

MySQLi, JSON, zlib, and OpenSSL are required. The native WPCM engine requires no generic archive extension.

= Where are local backups stored? =

In `wp-content/wpcm-backups/`. Published `.wpcm` backups are preserved during plugin uninstall so recovery data is not removed unexpectedly.

== Privacy Policy ==

Clone Master contains no analytics, advertising, telemetry, or tracking. Outbound requests occur only when an administrator explicitly configures Nextcloud, and they target that configured server.

== Changelog ==

= 3.1.6 =
* Fix: Follow WP-CLI search-replace semantics by treating a value as serialized only after the complete payload passes strict parsing.
* Fix: Process truncated serialization-like excerpts as ordinary text instead of aborting transactional URL replacement.
* Security: Keep the class-free token parser and never call unserialize() on imported archive data.
* Test: Reproduce the exact incomplete nested length field reported by unmedia_references.excerpt.

= 3.1.5 =
* Fix: Treat serialization-like plain text prefixes as ordinary text unless they contain a canonical PHP serialization header.
* Fix: Prevent path replacement in excerpt and reference columns from failing on values beginning with `s:`, `a:`, `O:`, or `i:`.
* Test: Add migration coverage for plain text containing old absolute paths after serialization-like prefixes.

= 3.1.4 =
* Fixed automatic rollback before WordPress loads pluggable.php by deriving the recovery envelope key from bootstrap-safe WordPress salts.
* Added safe token-level URL replacement for nested PHP serialized objects without instantiating their classes.
* Added table and column context to URL replacement failures and regression tests for both issues.

= 3.1.3 =
* Fixed staged schema validation when temporary table prefixes appear in table, reference, or constraint identifiers.
* Restricted staging rewrites so column and index identifiers are never renamed accidentally.
* New WPCM archives begin with the normalized site domain. Automatic archives keep the domain first and use the backup-auto marker.

= 3.1.2 =

* Fixed the React diagnostics crash that removed the complete administration interface after the first journal response.
* Moved byte formatting into shared application scope so server and diagnostic panels use the same safe helper.
* Added per-panel and application-level React error boundaries with persistent redacted diagnostics and recovery controls.
* Replaced silent server-information promise failures with a visible React error state.

= 3.1.1 =

* Fixed a bootstrap fatal error caused by calling `wp_rand()` before WordPress loaded pluggable functions.
* Made request IDs, event IDs, JSON encoding, path normalization, directory creation, and log clearing safe during early plugin bootstrap.
* Added a regression test that loads and writes the persistent logger without any WordPress helper functions.

= 3.1.0 =

* Added a persistent production diagnostic journal with automatic secret redaction, rotation, download, filtering, and fatal-error capture.
* Fixed installer preparation so PHP `Error` and `TypeError` failures return structured JSON with a diagnostic event ID.
* Captured unexpected HTML, empty, cached, and malformed responses in the persistent journal instead of showing an untraceable generic error.
* Reworked tab navigation so every operation panel remains mounted while the administrator changes sections.
* Removed the fragile schedule-tab DOM injection and integrated scheduling as a normal persistent panel.
* Added a page-leave safety warning and blocked conflicting operations within the administration workspace.
* Removed the em dash character from all visible plugin text and bundled documentation.
* Hardened JSON encoding checks for encrypted installer configuration and redundant recovery journals.

* Replaced every legacy site-backup archive workflow with the WP Commander-compatible `WPCMARCHIVE2` format.
* Added exact append-only checkpoint resume, per-block SHA-256 verification, and final whole-payload authentication.
* Added bidirectional binary compatibility regression tests against an independent implementation of the agent record layout.
* Removed the legacy generic archive runtime requirement.
* Made AJAX request identifiers diagnostic instead of restore-blocking when a proxy omits an echo field.
* Added monotonic, alternating HMAC restore-state slots with float-preserving canonical JSON.
* Added strict percent-character integrity guards and automated backup/restore fixtures.
* Refused unresolved `{64-hex}` WordPress placeholders before SQL persistence.
* Added safe recursive serialized replacement with canonical round-trip validation.
* Preserved composite database keys and transactional table-prefix promotion with rollback.

= 2.2.4 =

* Fixed generated Nginx guidance collisions during restore analysis.

= 2.2.3 =

* Added composite primary and unique key traversal.

= 2.2.2 =

* Added authenticated durable cursors, transactional restore staging, rollback, and cache-loop safeguards.

= 1.1.0 =

* Initial public release.

== Screenshots ==

1. Main administration dashboard with WPCM backup progress and activity details.
2. Server diagnostics with PHP, database, WordPress, storage, and web-server information.
