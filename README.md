# WP Hosting Benchmark

WP Hosting Benchmark is a production-ready WordPress plugin that lets administrators run short, safe hosting benchmarks from wp-admin and review a clear report with scores, raw metrics, environment details, history, and JSON export.

## File tree

```text
wp-hosting-benchmark.php
uninstall.php
README.md
readme.txt
src/
  Admin/
    Page.php
  Benchmark/
    Runner.php
    Scorer.php
    Time_Guard.php
  Export/
    Json_Exporter.php
  Plugin.php
  Storage.php
```

## Installation

1. Copy the plugin folder into `wp-content/plugins/wp-hosting-benchmark`.
2. Activate **WP Hosting Benchmark** from the WordPress Plugins screen.
3. Open **Hosting Benchmark** from the WordPress admin menu.

## Build ZIP

To create a WordPress-ready plugin ZIP that contains only distributable plugin files:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/build-plugin.ps1
```

If you use `make`, the same build is available as:

```bash
make zip
```

The archive is written to `dist/wp-hosting-benchmark-<version>.zip`.

### Playground quick start

From the plugin root, you can mount the plugin into a disposable Playground site:

```bash
npx @wp-playground/cli@latest server --auto-mount
```

Then open `/wp-admin/admin.php?page=wp-hosting-benchmark` inside the Playground instance.

## Usage

1. Choose an intensity level: Low, Standard, or High.
2. Click **Run benchmark**.
3. Review the latest summary, detailed result table, environment details, and benchmark history.
4. Use **Export JSON** to download the selected run.
5. Use **Clear history** to remove stored benchmark data.

## What the plugin benchmarks

- PHP execution speed
- CPU calculation speed
- Memory allocation speed
- WordPress database read speed
- WordPress database write speed using temporary benchmark records
- Autoloaded options footprint
- Filesystem write and read speed inside uploads
- Object cache performance when available
- Transient API performance
- Real-world content query performance
- REST posts collection latency
- HTTP loopback latency
- Basic WordPress bootstrap timing through an internal admin-only probe

## Security notes

- All benchmark actions require `manage_options`.
- All form actions and exports use WordPress nonces.
- No public benchmark endpoint is exposed.
- The internal bootstrap timing probe runs through authenticated `admin-post.php` only.
- Temporary database rows and temporary files are deleted after each run.
- Benchmarks never write outside the uploads directory.
- Results stay on the site and are never sent to external servers.
- The plugin does not collect personal data.

## Storage model

Benchmark history is stored in the non-autoloaded option `wp_hosting_benchmark_history`.
Temporary database write tests use short-lived `wp_hosting_benchmark_temp_*` option rows and delete them immediately after the test completes.

## Scoring logic

The scoring model is intentionally transparent.

- Each successful test exposes one primary raw metric.
- Each metric defines a `poor` threshold and an `excellent` threshold in code.
- Scores are calculated by linear interpolation from 0 to 100 between those thresholds.
- Better-than-excellent values clamp at 100.
- Worse-than-poor values clamp at 0.
- Category scores are averages of their available test scores.
- Overall score is a weighted average of category scores.
- Confidence is tracked separately so optional or unavailable tests reduce trust in the report without crashing the plugin.

### Category weights

- PHP and CPU: 25
- Database: 25
- Filesystem: 10
- Cache: 10
- Network loopback: 15
- Real-world WordPress: 15

## Performance safety

- Default intensity is Standard.
- High intensity shows a confirmation prompt before it runs.
- All tests are intentionally short and shared-hosting-safe.
- The time guard stops remaining tests before the request gets too close to PHP's execution limit.
- Failed or unavailable tests are reported individually and do not break the full run.

## Testing checklist

- Activate the plugin with `WP_DEBUG` enabled and confirm no fatal errors occur.
- Open the admin page and confirm the menu loads for administrators only.
- Run benchmarks using Low and Standard intensity.
- Confirm High intensity shows a confirmation dialog.
- Confirm a failed optional test, such as missing persistent object cache, renders as unavailable rather than crashing.
- Confirm the content query and REST request benchmarks return informative results on a typical site.
- Confirm benchmark history stores multiple runs and can be cleared.
- Confirm JSON export downloads the selected run.
- Confirm temporary files are removed from uploads after the filesystem test.
- Confirm temporary `wp_hosting_benchmark_temp_*` option rows are removed after the database write test.
- Confirm uninstall removes stored history and temporary benchmark options.

## Suggested future improvements

- Add multisite-aware site targeting and network summaries.
- Add optional CSV export alongside JSON.
- Add trend charts for repeated benchmark history.
- Add a WP-CLI command for scripted benchmark runs in controlled environments.
- Add automated Playground or wp-env smoke tests once a local test harness is introduced.

## Notes for local tooling

This repository does not include WordPress stubs or Composer dependencies, so some IDE static analysis tools will flag core WordPress functions as undefined outside a live WordPress environment. The plugin files themselves pass `php -l` syntax validation.