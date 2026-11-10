<?php
/** Copyright (c) 2026 Hayk Jomardyan - Lolisoft. See LICENSE. */
/**
 * Plugin Name: LS Hosting Benchmarks
 * Description: Benchmarks the hosting performance of the WordPress site where it is installed.
 * Version: 1.1.2
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Author: Hayk Jomardyan - Lolisoft
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-hosting-benchmark
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

// Private and directory editions share classes, hooks and stored data.
// The whole bootstrap is conditional so either load order is safe.
if ( ! defined( 'WP_HOSTING_BENCHMARK_VERSION' ) ) {
define( 'WP_HOSTING_BENCHMARK_FILE', __FILE__ );
define( 'WP_HOSTING_BENCHMARK_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_HOSTING_BENCHMARK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_HOSTING_BENCHMARK_VERSION', '1.1.2' );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPHostingBenchmark\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$file           = WP_HOSTING_BENCHMARK_DIR . 'src/' . str_replace( '\\', '/', $relative_class ) . '.php';

		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

function wp_hosting_benchmark() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new WPHostingBenchmark\Plugin();
	}

	return $plugin;
}

register_activation_hook( __FILE__, array( 'WPHostingBenchmark\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPHostingBenchmark\\Plugin', 'deactivate' ) );

wp_hosting_benchmark()->run();

}
