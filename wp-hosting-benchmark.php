<?php
/**
 * Plugin Name: WP Hosting Benchmark
 * Description: Benchmarks the hosting performance of the WordPress site where it is installed.
 * Version: 1.0.7
 * Requires at least: 6.9
 * Requires PHP: 7.2.24
 * Author: GitHub Copilot
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-hosting-benchmark
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_HOSTING_BENCHMARK_FILE', __FILE__ );
define( 'WP_HOSTING_BENCHMARK_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_HOSTING_BENCHMARK_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_HOSTING_BENCHMARK_VERSION', '1.0.7' );

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
