<?php
/**
 * JSON export handler.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Export;

use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Json_Exporter {
	/**
	 * Result storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Result storage.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Register export hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_wp_hosting_benchmark_export', array( $this, 'handle_export' ) );
	}

	/**
	 * Handle JSON export.
	 *
	 * @return void
	 */
	public function handle_export() {
		try {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to export benchmark results.', 'wp-hosting-benchmark' ) );
			}

			$run_id = isset( $_REQUEST['benchmark_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['benchmark_id'] ) ) : '';
			$nonce  = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

			if ( '' === $run_id || ! wp_verify_nonce( $nonce, 'wp_hosting_benchmark_export_' . $run_id ) ) {
				wp_die( esc_html__( 'The export request could not be verified.', 'wp-hosting-benchmark' ) );
			}

			$run = $this->storage->get_run( $run_id );

			if ( ! $run ) {
				wp_die( esc_html__( 'The requested benchmark run could not be found.', 'wp-hosting-benchmark' ) );
			}

			$filename = 'wp-hosting-benchmark-' . sanitize_file_name( str_replace( array( ' ', ':' ), '-', $run['created_at'] ) ) . '.json';
			$payload  = wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

			if ( false === $payload ) {
				wp_die( esc_html__( 'The benchmark run could not be encoded as JSON.', 'wp-hosting-benchmark' ) );
			}

			nocache_headers();
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'X-Content-Type-Options: nosniff' );
			echo $payload;
			exit;
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html( sanitize_text_field( $throwable->getMessage() ) ) );
		}
	}
}