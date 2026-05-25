<?php
/**
 * CSV export handler for benchmark runs.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Export;

use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Csv_Exporter {
	/**
	 * Result storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Storage.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_wp_hosting_benchmark_export_csv', array( $this, 'handle_export' ) );
	}

	/**
	 * Handle the CSV export request.
	 *
	 * @return void
	 */
	public function handle_export() {
		try {
			$run_id = isset( $_REQUEST['benchmark_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['benchmark_id'] ) ) : '';
			$nonce  = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

			if ( '' === $run_id || ! wp_verify_nonce( $nonce, 'wp_hosting_benchmark_export_csv_' . $run_id ) ) {
				wp_die( esc_html__( 'The CSV export request could not be verified.', 'wp-hosting-benchmark' ) );
			}

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have permission to export benchmark results.', 'wp-hosting-benchmark' ) );
			}

			$run = $this->storage->get_run( $run_id );

			if ( ! $run ) {
				wp_die( esc_html__( 'The requested benchmark run could not be found.', 'wp-hosting-benchmark' ) );
			}

			$filename = 'wp-hosting-benchmark-' . sanitize_file_name( str_replace( array( ' ', ':' ), '-', $run['created_at'] ) ) . '.csv';

			nocache_headers();
			header( 'Content-Type: text/csv; charset=' . get_option( 'blog_charset' ) );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( 'X-Content-Type-Options: nosniff' );

			$handle = fopen( 'php://output', 'w' );

			if ( false === $handle ) {
				wp_die( esc_html__( 'The CSV export stream could not be opened.', 'wp-hosting-benchmark' ) );
			}

			fputcsv(
				$handle,
				array(
					'run_id',
					'created_at',
					'intensity',
					'overall_score',
					'confidence',
					'test_slug',
					'test_label',
					'category',
					'status',
					'metric_label',
					'metric_value',
					'metric_unit',
					'duration_ms',
					'ops_per_second',
				)
			);

			$results = isset( $run['results'] ) && is_array( $run['results'] ) ? $run['results'] : array();

			foreach ( $results as $result ) {
				fputcsv(
					$handle,
					array(
						(string) $run['id'],
						(string) $run['created_at'],
						(string) $run['intensity'],
						(string) ( isset( $run['scores']['overall'] ) ? $run['scores']['overall'] : '' ),
						(string) ( isset( $run['scores']['confidence'] ) ? $run['scores']['confidence'] : '' ),
						(string) ( isset( $result['slug'] ) ? $result['slug'] : '' ),
						(string) ( isset( $result['label'] ) ? $result['label'] : '' ),
						(string) ( isset( $result['category'] ) ? $result['category'] : '' ),
						(string) ( isset( $result['status'] ) ? $result['status'] : '' ),
						(string) ( isset( $result['metric_label'] ) ? $result['metric_label'] : '' ),
						(string) ( isset( $result['metric_value'] ) ? $result['metric_value'] : '' ),
						(string) ( isset( $result['metric_unit'] ) ? $result['metric_unit'] : '' ),
						(string) ( isset( $result['duration_ms'] ) ? $result['duration_ms'] : '' ),
						(string) ( isset( $result['ops_per_second'] ) ? $result['ops_per_second'] : '' ),
					)
				);
			}

			fclose( $handle );
			exit;
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html( sanitize_text_field( $throwable->getMessage() ) ) );
		}
	}
}
