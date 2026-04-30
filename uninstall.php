<?php
/**
 * Cleanup for WP Hosting Benchmark.
 *
 * @package WPHostingBenchmark
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'wp_hosting_benchmark_history' );
delete_option( 'wp_hosting_benchmark_schema_version' );
delete_transient( 'wp_hosting_benchmark_cleanup_throttle' );

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'wp_hosting_benchmark_temp_' ) . '%'
	)
);

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wp_hosting_benchmark_notice_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wp_hosting_benchmark_notice_' ) . '%'
	)
);