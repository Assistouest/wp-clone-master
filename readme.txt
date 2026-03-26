=== Clone Master ===
Contributors: adrienpiron
Tags: clone, backup, migrate, migration, transfer
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Clone, migrate and backup your entire WordPress site with intelligent URL replacement and adaptive chunking.

== Description ==

Clone Master creates a complete, exact clone of your WordPress site — database, themes, plugins, uploads, and all configuration — packaged into a single portable archive.

**Key Features:**

* **Full Site Export** — Database (all tables, including custom plugin tables), themes, plugins, uploads, mu-plugins, root files (.htaccess, robots.txt, etc.)
* **Serialization-Safe URL Replacement** — Handles PHP serialized data (widgets, theme options, page builders), JSON-encoded data (Elementor, Gutenberg), protocol variations (http/https), JSON-escaped URLs, server path changes, and URL-encoded strings.
* **Adaptive Chunking** — Detects server constraints (memory_limit, max_execution_time, upload_max_filesize) and automatically adjusts batch sizes and chunk splitting.
* **Step-by-Step AJAX Processing** — Each operation runs in discrete AJAX steps with progress tracking and error recovery.
* **Server Environment Detection** — Full diagnostic of PHP version, MySQL, extensions, disk space, directory permissions, and server type (Apache/Nginx/LiteSpeed).
* **React Admin Dashboard** — Modern, polished interface with real-time progress, logs, backup management, and server diagnostics.
* **Nextcloud Integration** — Optionally upload backups to your own Nextcloud instance via WebDAV. Credentials are encrypted with AES-256-CBC before being stored in the WordPress database.

**What Gets Cloned:**

* All database tables (core + custom plugin tables like WooCommerce, ACF, etc.)
* All themes (active + inactive)
* All plugins (active + inactive)
* All uploads (media library)
* Must-use plugins (mu-plugins)
* Root config files (.htaccess, robots.txt, wp-config.php structure)
* WordPress options, permalink structure, active theme/plugin state

**URL Replacement Handles:**

* https://old-domain.com → https://new-domain.com
* http:// ↔ https:// protocol changes
* Protocol-relative URLs (//domain.com)
* JSON-escaped URLs (Elementor, Gutenberg blocks)
* Server absolute paths (/home/old/public_html → /home/new/www)
* URL-encoded strings
* Nested serialized data (serialized within serialized)
* All database tables, not just core WordPress tables

== Installation ==

1. Upload the `clone-master` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **Clone Master** in the admin sidebar

== Usage ==

**To Export / Clone:**

1. Go to Clone Master → Export tab
2. Click "Start Full Export"
3. Wait for all steps to complete
4. Download the generated .zip archive

**To Import / Migrate:**

1. Install Clone Master on the target site
2. Go to Clone Master → Import tab
3. Upload the .zip archive
4. Enter the new site URL if it differs from the origin
5. Click "Start Import" — all URLs are automatically replaced

**Nextcloud Backup Storage:**

1. Go to Clone Master → Schedule tab
2. Select "Nextcloud" as the storage driver
3. Authenticate via the Nextcloud Login Flow v2 (OAuth-style, no password typed)
4. Your app-password is encrypted with AES-256-CBC before storage

== Frequently Asked Questions ==

= Does the plugin work on shared hosting? =

Yes. The plugin automatically detects server constraints (memory limit, execution time, upload size) and adjusts its processing using adaptive chunking.

= Can I migrate from HTTP to HTTPS? =

Yes. The URL replacement engine handles protocol changes (http ↔ https), including inside PHP serialized data and Gutenberg/Elementor JSON blocks.

= What is the maximum supported site size? =

There is no fixed size limit. The plugin processes files in chunks to work around PHP limits on any hosting environment.

= Can automatic backups be stored on Nextcloud? =

Yes. You can choose between local storage (wp-content/wpcm-backups/) and your own Nextcloud instance via WebDAV. Configure this in the Schedule tab. Your Nextcloud app-password is encrypted with AES-256-CBC before being stored in the WordPress database and is never transmitted to any third party.

= What happens if I enable the indexing block during migration? =

The plugin temporarily forces noindex mode across the entire site. Go to Settings → Reading and save to re-enable search engine indexing once the migrated site has been validated.

= Does Clone Master send any data to external servers? =

No. The plugin does not collect, transmit, or share any data with external servers. The only outbound connections it makes are to your own Nextcloud instance, and only when you explicitly configure and use the Nextcloud storage feature.

= What data is stored in the WordPress database? =

The plugin stores schedule settings, backup history, and — when Nextcloud is configured — your encrypted Nextcloud app-password. All data is removed from the database when the plugin is deleted via the Plugins screen.

== Privacy Policy ==

Clone Master does not collect, store, or transmit any personal data to external servers or third parties. The plugin operates entirely within your own WordPress installation.

**Data stored locally (in your WordPress database):**

* Schedule settings (wpcm_schedule_settings) — backup frequency, email notification address, storage driver preference.
* Backup history (wpcm_backup_history) — a log of past backups (filenames, dates, sizes) stored on your own server.
* Nextcloud credentials — if you configure Nextcloud storage, your Nextcloud app-password is encrypted using AES-256-CBC before being saved to the WordPress database. The encryption key is derived from your WordPress secret keys. The raw password is never stored in plain text and is never sent anywhere except to your own Nextcloud server.
* Noindex flag (wpcm_noindex) — a temporary flag set during import to prevent search engine indexing. It is cleared when you save Settings → Reading.

**External connections:**

The only outbound HTTP connections made by this plugin are to your own Nextcloud server (the URL you supply), and only when you explicitly use the Nextcloud storage feature. No data is ever sent to the plugin author, to WordPress.org, or to any analytics or tracking service.

**Data removal:**

All data stored by Clone Master is permanently deleted from the WordPress database when you delete the plugin via Plugins → Delete. Backup archives stored on disk (in wp-content/wpcm-backups/) must be removed manually if desired.

For the general WordPress privacy policy, see https://automattic.com/privacy/.

== Changelog ==

= 1.1.0 =
* First public release on WordPress.org.
* Full site export with step-by-step AJAX processing and adaptive chunking (detects server memory_limit, max_execution_time, and upload_max_filesize automatically).
* Serialization-safe URL replacement engine: handles PHP serialized data, JSON-encoded blocks (Elementor, Gutenberg), protocol changes (http ↔ https), URL-encoded strings, server absolute paths, and nested serialized structures.
* Standalone import architecture: a static installer.php handles DB restore, file extraction, and URL replacement outside the WordPress bootstrap, avoiding timeout and self-overwrite issues.
* Automatic backup scheduler with WP-Cron: hourly, twice-daily, daily, weekly, and monthly frequencies; count-based and age-based retention policies; email notifications on success, error, or never.
* Nextcloud storage integration via WebDAV: authenticate with Login Flow v2 (no password typed in the UI); app-password encrypted with AES-256-CBC before storage; DNS-pinned outbound connections to prevent SSRF/DNS rebinding.
* Chunked file upload for large archives; magic-byte ZIP validation; ZIP Slip protection on extraction.
* React admin dashboard with real-time progress, activity log, backup management (list, download, restore, delete), server diagnostics, and schedule configuration.
* Full i18n support: .pot, .po, .mo files and JS JSON translation files for admin and schedule scripts.

= 1.2.0 =
* New: Introduction of the "Adaptive Database Engine 2.0" specifically designed for massive databases (tested up to 2GB+).
* New: Real-time feedback loop for database exports: the algorithm now monitors server response time and memory pressure to dynamically adjust batch sizes (from 25 up to 2,000 rows per request).
* New: Intelligent "Warm-up" phase: the exporter starts with conservative batch sizes and accelerates safely based on server performance.
* New: MySQL Packet Protection: automatic row-size sampling to prevent "max_allowed_packet" errors on tables with large metadata or longtext columns.
* Improved: Resilience on shared hosting: advanced transient-based checkpointing to resume exports even after micro-network cuts or proxy timeouts.
* Improved: Support for high-table-count environments (optimized for 400+ tables).
* Improved: UI/UX updates in the React dashboard to display real-time adaptive throughput and per-table progress.
* Fix: Refined memory cleanup (gc_collect_cycles) during heavy export loops to maintain low RAM footprint.

== Screenshots ==
1. Main dashboard showing real-time export progress and activity log.
2. Detailed server diagnostics screen showing PHP, MySQL, and WordPress versions along with critical server limits (memory, execution time, and chunk size detection).