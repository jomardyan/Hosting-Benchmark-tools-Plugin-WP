<?php
/**
 * Cleanup for WP Hosting Benchmark.
 *
 * @package WPHostingBenchmark
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_hosting_benchmark_history' );
delete_option( 'wp_hosting_benchmark_schema_version' );

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wp_hosting_benchmark_temp_' ) . '%'
	)
);