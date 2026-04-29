<?php
/**
 * Main plugin coordinator.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark;

use WPHostingBenchmark\Admin\Page;
use WPHostingBenchmark\Benchmark\Runner;
use WPHostingBenchmark\Benchmark\Scorer;
use WPHostingBenchmark\Export\Json_Exporter;

defined( 'ABSPATH' ) || exit;

class Plugin {
	/**
	 * Result storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Benchmark runner.
	 *
	 * @var Runner
	 */
	protected $runner;

	/**
	 * Admin page.
	 *
	 * @var Page
	 */
	protected $page;

	/**
	 * Export handler.
	 *
	 * @var Json_Exporter
	 */
	protected $exporter;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->storage  = new Storage();
		$this->runner   = new Runner( $this->storage, new Scorer() );
		$this->page     = new Page( $this->runner, $this->storage );
		$this->exporter = new Json_Exporter( $this->storage );
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function run() {
		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
		add_action( 'admin_post_wp_hosting_benchmark_bootstrap_probe', array( $this, 'handle_bootstrap_probe' ) );

		if ( is_admin() ) {
			$this->page->register();
			$this->exporter->register();
		}
	}

	/**
	 * Ensure options are installed and stale temp records are cleaned up.
	 *
	 * @return void
	 */
	public function maybe_upgrade() {
		try {
			if ( Storage::SCHEMA_VERSION !== get_option( Storage::SCHEMA_OPTION ) ) {
				Storage::install();
			}

			$this->storage->cleanup_temporary_records();
		} catch ( \Throwable $throwable ) {
			$this->report_background_error( $throwable );
		}
	}

	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'wp-hosting-benchmark', false, dirname( plugin_basename( WP_HOSTING_BENCHMARK_FILE ) ) . '/languages' );
	}

	/**
	 * Respond to the internal admin-only bootstrap probe.
	 *
	 * @return void
	 */
	public function handle_bootstrap_probe() {
		try {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

			if ( 'POST' !== $method ) {
				wp_send_json_error(
					array(
						'message' => __( 'The bootstrap probe only accepts POST requests.', 'wp-hosting-benchmark' ),
					),
					405
				);
			}

			if ( ! current_user_can( Page::CAPABILITY ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to run bootstrap probes.', 'wp-hosting-benchmark' ),
					),
					403
				);
			}

			$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

			if ( ! wp_verify_nonce( $nonce, 'wp_hosting_benchmark_bootstrap_probe' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'The bootstrap probe nonce is invalid.', 'wp-hosting-benchmark' ),
					),
					403
				);
			}

			wp_send_json_success(
				array(
					'bootstrap_ms' => round( (float) timer_stop( 0, 6 ) * 1000, 3 ),
				)
			);
		} catch ( \Throwable $throwable ) {
			wp_send_json_error(
				array(
					'message' => __( 'The bootstrap probe failed unexpectedly.', 'wp-hosting-benchmark' ),
					'details' => sanitize_text_field( $throwable->getMessage() ),
				),
				500
			);
		}
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		try {
			Storage::install();
		} catch ( \Throwable $throwable ) {
			wp_die( esc_html( sanitize_text_field( $throwable->getMessage() ) ) );
		}
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		try {
			$storage = new Storage();
			$storage->cleanup_temporary_records();
		} catch ( \Throwable $throwable ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'WP Hosting Benchmark deactivation cleanup failed: ' . sanitize_text_field( $throwable->getMessage() ) );
			}
		}
	}

	/**
	 * Record a background admin-side error without fatally breaking the page.
	 *
	 * @param \Throwable $throwable Throwable instance.
	 * @return void
	 */
	protected function report_background_error( \Throwable $throwable ) {
		if ( is_admin() && current_user_can( Page::CAPABILITY ) ) {
			set_transient(
				Storage::NOTICE_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'type'    => 'error',
					'message' => sanitize_text_field( $throwable->getMessage() ),
				),
				MINUTE_IN_SECONDS
			);
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'WP Hosting Benchmark background error: ' . sanitize_text_field( $throwable->getMessage() ) );
		}
	}
}