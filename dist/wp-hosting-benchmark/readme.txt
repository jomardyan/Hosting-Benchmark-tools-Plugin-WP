=== WP Hosting Benchmark ===
Contributors: github-copilot
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: benchmark, performance, hosting, diagnostics, admin

Benchmarks safe hosting performance from the WordPress admin area and stores a clear report with history and JSON export.

== Description ==

WP Hosting Benchmark runs short, shared-hosting-safe diagnostics for PHP, CPU, memory, database, autoloaded option footprint, filesystem, cache, real-world WordPress request paths, loopback latency, and WordPress bootstrap timing.

Features include:

* Admin-only benchmark execution
* Transparent 0-100 scoring with category scores
* Real-world content query and REST API request coverage
* Environment details and recommendations
* Benchmark history
* JSON export
* Uninstall cleanup

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-hosting-benchmark/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Open the **Hosting Benchmark** admin page.

== Frequently Asked Questions ==

= Is this safe for shared hosting? =

Yes. The benchmarks are intentionally short, non-destructive, and guarded against running too close to the PHP execution limit.

= Does this expose public benchmark endpoints? =

No. Benchmark actions are admin-only and require `manage_options` plus valid nonces.

= Where is history stored? =

History is stored in the `wp_hosting_benchmark_history` option.

== Changelog ==

= 1.1.0 =
* New Settings page: default intensity, history limit (1–200), schedule (off/daily/weekly), and per-test enable/disable.
* New Compare page: side-by-side comparison of any two stored runs with delta indicators.
* New CSV export for each run (alongside JSON export).
* New WP-Cron based scheduled benchmark runs with a 5-minute lock to prevent overlap.
* New Site Health integration: latest run score and loopback latency surfaced under Tools → Site Health.
* New WP-CLI command: `wp hosting-benchmark run|list|delete|export`.
* New benchmark history trend sparkline on the dashboard.
* Reorganised admin menu with a dedicated Dashboard submenu plus Settings and Compare entries.
* Hardened option writes to avoid false-positive "save failed" notices when a run is identical.
* Reordered nonce-before-capability checks in bootstrap probe and JSON export handler.
* Bumped supported WordPress to 6.5+ and minimum PHP to 7.4.
* Increased default history retention from 20 to 50 runs.
* Improved labels, empty states, and admin notices.

= 1.0.2 =

* Redesigned the admin dashboard with a responsive layout.
* Added a final verdict panel and speedometer-style score graphic.
* Improved table autofit behavior for smaller screens.

= 1.0.1 =

* Added autoloaded options footprint measurement.
* Added real-world content query and REST posts latency benchmarks.
* Expanded benchmark guidance in the admin UI.

= 1.0.0 =

* Initial release.