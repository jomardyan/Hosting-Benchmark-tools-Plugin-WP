=== WP Hosting Benchmark ===
Contributors: github-copilot
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.2.24
Stable tag: 1.0.7
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