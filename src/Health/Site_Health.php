<?php
/**
 * Site Health integration.
 *
 * Adds asynchronous Site Health checks backed by the most recent benchmark run.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Health;

use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Site_Health {
	/**
	 * Storage.
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
		add_filter( 'site_status_tests', array( $this, 'register_tests' ) );
	}

	/**
	 * Register Site Health tests.
	 *
	 * @param array $tests Existing tests.
	 * @return array
	 */
	public function register_tests( $tests ) {
		if ( ! is_array( $tests ) ) {
			$tests = array( 'direct' => array(), 'async' => array() );
		}

		$tests['direct']['wp_hosting_benchmark_latest_run'] = array(
			'label' => __( 'Hosting Benchmark report', 'wp-hosting-benchmark' ),
			'test'  => array( $this, 'test_latest_run' ),
		);

		$tests['direct']['wp_hosting_benchmark_loopback'] = array(
			'label' => __( 'Hosting Benchmark loopback latency', 'wp-hosting-benchmark' ),
			'test'  => array( $this, 'test_loopback_latency' ),
		);

		return $tests;
	}

	/**
	 * Report whether the last benchmark scored well.
	 *
	 * @return array
	 */
	public function test_latest_run() {
		$run = $this->storage->get_latest_run();
		$base_actions = '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-hosting-benchmark' ) ) . '">' . esc_html__( 'Open Hosting Benchmark', 'wp-hosting-benchmark' ) . '</a></p>';

		if ( ! $run ) {
			return array(
				'label'       => __( 'No hosting benchmark has been run yet.', 'wp-hosting-benchmark' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => __( 'Performance', 'wp-hosting-benchmark' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'Run the hosting benchmark at least once to capture a baseline of your server performance.', 'wp-hosting-benchmark' ) . '</p>',
				'actions'     => $base_actions,
				'test'        => 'wp_hosting_benchmark_latest_run',
			);
		}

		$score  = isset( $run['scores']['overall'] ) ? (int) $run['scores']['overall'] : 0;
		$status = 'good';

		if ( $score < 40 ) {
			$status = 'critical';
		} elseif ( $score < 65 ) {
			$status = 'recommended';
		}

		$label = sprintf(
			/* translators: %d: benchmark overall score 0–100. */
			__( 'Latest hosting benchmark overall score: %d/100.', 'wp-hosting-benchmark' ),
			$score
		);

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Performance', 'wp-hosting-benchmark' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'This score combines PHP, database, filesystem, cache, application, and network signals captured during the last benchmark run.', 'wp-hosting-benchmark' ) . '</p>',
			'actions'     => $base_actions,
			'test'        => 'wp_hosting_benchmark_latest_run',
		);
	}

	/**
	 * Report the latest loopback latency reading.
	 *
	 * @return array
	 */
	public function test_loopback_latency() {
		$run = $this->storage->get_latest_run();
		$base_actions = '<p><a href="' . esc_url( admin_url( 'admin.php?page=wp-hosting-benchmark' ) ) . '">' . esc_html__( 'Open Hosting Benchmark', 'wp-hosting-benchmark' ) . '</a></p>';

		$default = array(
			'label'       => __( 'Loopback latency not measured yet.', 'wp-hosting-benchmark' ),
			'status'      => 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'wp-hosting-benchmark' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Run a benchmark to capture an HTTP loopback latency measurement.', 'wp-hosting-benchmark' ) . '</p>',
			'actions'     => $base_actions,
			'test'        => 'wp_hosting_benchmark_loopback',
		);

		if ( ! $run || empty( $run['results'] ) || ! is_array( $run['results'] ) ) {
			return $default;
		}

		foreach ( $run['results'] as $result ) {
			if ( ! isset( $result['slug'] ) || 'loopback_latency' !== $result['slug'] ) {
				continue;
			}

			if ( 'success' !== ( isset( $result['status'] ) ? $result['status'] : '' ) ) {
				continue;
			}

			$latency = isset( $result['metric_value'] ) ? (float) $result['metric_value'] : 0.0;
			$status  = 'good';

			if ( $latency > 600 ) {
				$status = 'critical';
			} elseif ( $latency > 250 ) {
				$status = 'recommended';
			}

			return array(
				'label'       => sprintf(
					/* translators: %s: latency in milliseconds. */
					__( 'Loopback latency: %s ms', 'wp-hosting-benchmark' ),
					number_format_i18n( $latency, 1 )
				),
				'status'      => $status,
				'badge'       => array(
					'label' => __( 'Performance', 'wp-hosting-benchmark' ),
					'color' => 'blue',
				),
				'description' => '<p>' . esc_html__( 'Lower loopback latency means WordPress can talk to itself faster, which improves admin-ajax, cron, and REST workflows.', 'wp-hosting-benchmark' ) . '</p>',
				'actions'     => $base_actions,
				'test'        => 'wp_hosting_benchmark_loopback',
			);
		}

		return $default;
	}
}
