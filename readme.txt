=== WP Clone Master ===
Contributors: wpclonemaster
Tags: clone, backup, migrate, migration, duplicate, copy, transfer
Requires at least: 5.6
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

Clone, migrate and backup your entire WordPress site with intelligent URL replacement and adaptive chunking.

== Description ==

WP Clone Master creates a complete, exact clone of your WordPress site — database, themes, plugins, uploads, and all configuration — packaged into a single portable archive.

**Key Features:**

* **Full Site Export** — Database (all tables, including custom plugin tables), themes, plugins, uploads, mu-plugins, root files (.htaccess, robots.txt, etc.)
* **Serialization-Safe URL Replacement** — The critical differentiator. Handles PHP serialized data (widgets, theme options, page builders), JSON-encoded data (Elementor, Gutenberg), protocol variations (http/https), JSON-escaped URLs, server path changes, and URL-encoded strings.
* **Adaptive Chunking** — Detects server constraints (memory_limit, max_execution_time, upload_max_filesize) and automatically adjusts batch sizes and chunk splitting.
* **Step-by-Step AJAX Processing** — Each operation runs in discrete AJAX steps with progress tracking and error recovery.
* **Server Environment Detection** — Full diagnostic of PHP version, MySQL, extensions, disk space, directory permissions, and server type (Apache/Nginx/LiteSpeed).
* **React Admin Dashboard** — Modern, polished interface with real-time progress, logs, backup management, and server diagnostics.

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

1. Upload the `wp-clone-master` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu
3. Go to 'Clone Master' in the admin sidebar

== Usage ==

**To Export/Clone:**
1. Go to Clone Master → Export tab
2. Click "Start Full Export"
3. Wait for all steps to complete
4. Download the .zip archive

**To Import/Migrate:**
1. Install WP Clone Master on the target site
2. Go to Clone Master → Import tab
3. Upload the .zip archive
4. Enter the new site URL if different
5. Click "Start Import"
6. All URLs are automatically replaced

== Changelog ==

= 1.0.0 =
* Initial release
* Full site export with intelligent chunking
* Serialization-safe URL replacement engine
* Adaptive server constraint detection
* React admin dashboard
* Backup archive management
