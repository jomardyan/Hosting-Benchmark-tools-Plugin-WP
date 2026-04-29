<?php
/**
 * Executes benchmark tests.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Benchmark;

use WPHostingBenchmark\Admin\Page;
use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Runner {
	/**
	 * Result storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Score calculator.
	 *
	 * @var Scorer
	 */
	protected $scorer;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Result storage.
	 * @param Scorer  $scorer  Score calculator.
	 */
	public function __construct( Storage $storage, Scorer $scorer ) {
		$this->storage = $storage;
		$this->scorer  = $scorer;
	}

	/**
	 * Get available intensity levels.
	 *
	 * @return array
	 */
	public function get_intensity_levels() {
		return array(
			'low'      => array(
				'label'       => __( 'Low', 'wp-hosting-benchmark' ),
				'description' => __( 'Fastest and safest sample size for constrained shared hosting.', 'wp-hosting-benchmark' ),
			),
			'standard' => array(
				'label'       => __( 'Standard', 'wp-hosting-benchmark' ),
				'description' => __( 'Balanced default profile for routine benchmark runs.', 'wp-hosting-benchmark' ),
			),
			'high'     => array(
				'label'       => __( 'High', 'wp-hosting-benchmark' ),
				'description' => __( 'Runs more iterations while staying inside shared-hosting-safe limits.', 'wp-hosting-benchmark' ),
			),
		);
	}

	/**
	 * Get a fresh environment snapshot.
	 *
	 * @return array
	 */
	public function get_environment_snapshot() {
		return $this->get_environment_details();
	}

	/**
	 * Run all benchmarks.
	 *
	 * @param string $intensity Intensity label.
	 * @return array
	 */
	public function run_benchmark( $intensity = 'standard' ) {
		$intensity      = $this->normalize_intensity( $intensity );
		$profile        = $this->get_intensity_profile( $intensity );
		$guard          = new Time_Guard();
		$tests          = $this->get_tests();
		$results        = array();
		$environment    = $this->get_environment_details();
		$benchmark_time = microtime( true );

		foreach ( $tests as $index => $test ) {
			if ( $guard->should_abort() ) {
				$results = array_merge(
					$results,
					$this->build_unavailable_results(
						array_slice( $tests, $index ),
						__( 'Skipped to avoid reaching the server execution time limit.', 'wp-hosting-benchmark' )
					)
				);
				break;
			}

			try {
				$results[] = $this->{$test['method']}( $profile, $guard );
			} catch ( Execution_Limit_Exception $exception ) {
				$results[] = $this->build_unavailable_result( $test, $exception->getMessage() );
				$results   = array_merge(
					$results,
					$this->build_unavailable_results(
						array_slice( $tests, $index + 1 ),
						__( 'The benchmark stopped early to stay within the server execution time limit.', 'wp-hosting-benchmark' )
					)
				);
				break;
			} catch ( \RuntimeException $exception ) {
				$results[] = $this->build_failure_result( $test, $this->build_safe_error_message( $exception, __( 'This benchmark test failed unexpectedly.', 'wp-hosting-benchmark' ) ) );
			} catch ( \Throwable $throwable ) {
				$results[] = $this->build_failure_result( $test, $this->build_safe_error_message( $throwable, __( 'This benchmark test failed unexpectedly.', 'wp-hosting-benchmark' ) ) );
			}
		}

		$scores          = $this->scorer->score_results( $results );
		$recommendations = $this->build_recommendations( $results, $scores );
		$summary         = $this->summarize_results( $results, $guard );
		$run             = array(
			'intensity'       => $intensity,
			'created_at'      => current_time( 'mysql' ),
			'environment'     => $environment,
			'results'         => $results,
			'scores'          => $scores,
			'recommendations' => $recommendations,
			'summary'         => $summary,
			'total_duration'  => round( ( microtime( true ) - $benchmark_time ) * 1000, 3 ),
		);

		$run['id'] = $this->storage->save_run( $run );

		return $run;
	}

	/**
	 * Benchmark PHP execution speed.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_php_execution( array $profile, Time_Guard $guard ) {
		$test       = $this->get_test_by_slug( 'php_execution' );
		$iterations = (int) $profile['php_iterations'];
		$checksum   = 0;
		$started_at = microtime( true );

		for ( $index = 1; $index <= $iterations; $index++ ) {
			$guard->enforce_every( $index, 5000, $test['label'] );
			$checksum = ( $checksum + ( $index * 3 ) ) % 1000003;
			$checksum += strlen( (string) ( $checksum ^ $index ) );
		}

		$duration_ms    = $this->elapsed_ms( $started_at );
		$ops_per_second = $this->calculate_ops_per_second( $iterations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$ops_per_second,
			__( 'ops/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $ops_per_second,
				'poor'             => 100000,
				'excellent'        => 1200000,
				'higher_is_better' => true,
				'optional'         => false,
			),
			array(
				'checksum' => $checksum,
			)
		);
	}

	/**
	 * Benchmark CPU calculation speed.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_cpu_calculation( array $profile, Time_Guard $guard ) {
		$test       = $this->get_test_by_slug( 'cpu_calculation' );
		$iterations = (int) $profile['cpu_iterations'];
		$checksum   = 0.0;
		$started_at = microtime( true );

		for ( $index = 1; $index <= $iterations; $index++ ) {
			$guard->enforce_every( $index, 2000, $test['label'] );
			$checksum += sqrt( (float) $index ) * sin( (float) $index / 5 ) + cos( (float) $index / 9 );
		}

		$duration_ms    = $this->elapsed_ms( $started_at );
		$ops_per_second = $this->calculate_ops_per_second( $iterations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$ops_per_second,
			__( 'ops/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $ops_per_second,
				'poor'             => 20000,
				'excellent'        => 250000,
				'higher_is_better' => true,
				'optional'         => false,
			),
			array(
				'checksum' => round( $checksum, 6 ),
			)
		);
	}

	/**
	 * Benchmark memory allocation speed.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_memory_allocation( array $profile, Time_Guard $guard ) {
		$test         = $this->get_test_by_slug( 'memory_allocation' );
		$iterations   = (int) $profile['memory_iterations'];
		$chunk_size   = (int) $profile['memory_chunk_size'];
		$allocations  = array();
		$bytes_total  = 0;
		$started_at   = microtime( true );

		for ( $index = 0; $index < $iterations; $index++ ) {
			$guard->enforce_every( $index + 1, 50, $test['label'] );
			$allocations[] = str_repeat( chr( 65 + ( $index % 26 ) ), $chunk_size );
			$bytes_total  += $chunk_size;
		}

		$checksum = 0;
		foreach ( $allocations as $allocation ) {
			$checksum += strlen( $allocation );
		}

		unset( $allocations );

		if ( function_exists( 'gc_collect_cycles' ) ) {
			gc_collect_cycles();
		}

		$duration_ms         = $this->elapsed_ms( $started_at );
		$ops_per_second      = $this->calculate_ops_per_second( $iterations, $duration_ms );
		$throughput_kib_sec  = $this->calculate_throughput_kib( $bytes_total, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$throughput_kib_sec,
			__( 'KiB/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $throughput_kib_sec,
				'poor'             => 512,
				'excellent'        => 8192,
				'higher_is_better' => true,
				'optional'         => false,
			),
			array(
				'bytes_allocated' => $bytes_total,
				'checksum'        => $checksum,
			)
		);
	}

	/**
	 * Benchmark database read speed.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_database_read( array $profile, Time_Guard $guard ) {
		global $wpdb;

		$test       = $this->get_test_by_slug( 'database_read' );
		$iterations = (int) $profile['db_reads'];
		$started_at = microtime( true );
		$last_value = '';

		for ( $index = 1; $index <= $iterations; $index++ ) {
			$guard->enforce_every( $index, 10, $test['label'] );
			$last_value = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					'siteurl'
				)
			);

			if ( null === $last_value ) {
				throw new \RuntimeException( $this->storage->get_database_error_message( __( 'Database read benchmark could not read the site URL option.', 'wp-hosting-benchmark' ) ) );
			}
		}

		$duration_ms    = $this->elapsed_ms( $started_at );
		$ops_per_second = $this->calculate_ops_per_second( $iterations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$ops_per_second,
			__( 'ops/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $ops_per_second,
				'poor'             => 50,
				'excellent'        => 1000,
				'higher_is_better' => true,
				'optional'         => false,
			),
			array(
				'last_value_length' => strlen( (string) $last_value ),
			)
		);
	}

	/**
	 * Benchmark database write speed with temporary option rows.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_database_write( array $profile, Time_Guard $guard ) {
		$test        = $this->get_test_by_slug( 'database_write' );
		$iterations  = (int) $profile['db_writes'];
		$prefix      = 'wp_hosting_benchmark_temp_' . wp_generate_password( 10, false, false );
		$created     = array();
		$payload     = wp_json_encode(
			array(
				'time' => time(),
				'seed' => wp_rand( 1000, 9999 ),
			)
		);
		$started_at  = microtime( true );

		if ( false === $payload ) {
			throw new \RuntimeException( __( 'The temporary benchmark payload could not be encoded as JSON.', 'wp-hosting-benchmark' ) );
		}

		try {
			for ( $index = 1; $index <= $iterations; $index++ ) {
				$guard->enforce_every( $index, 5, $test['label'] );
				$option_name = $prefix . '_' . $index;

				if ( ! $this->storage->insert_temporary_record( $option_name, $payload ) ) {
					throw new \RuntimeException( $this->storage->get_database_error_message( __( 'A temporary benchmark option could not be written.', 'wp-hosting-benchmark' ) ) );
				}

				$created[] = $option_name;
			}
		} finally {
			foreach ( $created as $option_name ) {
				$this->storage->delete_temporary_record( $option_name );
			}
		}

		$duration_ms    = $this->elapsed_ms( $started_at );
		$ops_per_second = $this->calculate_ops_per_second( $iterations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$ops_per_second,
			__( 'ops/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $ops_per_second,
				'poor'             => 10,
				'excellent'        => 200,
				'higher_is_better' => true,
				'optional'         => false,
			),
			array(
				'rows_written' => $iterations,
			)
		);
	}

	/**
	 * Measure the size of autoloaded options data.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_autoloaded_options( array $profile, Time_Guard $guard ) {
		global $wpdb;

		$test            = $this->get_test_by_slug( 'autoloaded_options' );
		$autoload_values = $this->get_autoload_values_to_measure();
		$placeholders    = implode( ', ', array_fill( 0, count( $autoload_values ), '%s' ) );
		$sql             = "SELECT COUNT(*) AS option_count, COALESCE(SUM(LENGTH(option_name) + LENGTH(option_value)), 0) AS total_bytes FROM {$wpdb->options} WHERE autoload IN ({$placeholders})";
		$prepared_sql    = call_user_func_array( array( $wpdb, 'prepare' ), array_merge( array( $sql ), $autoload_values ) );

		$guard->enforce( $test['label'] );
		$started_at = microtime( true );
		$row        = $wpdb->get_row( $prepared_sql, ARRAY_A );
		$duration_ms = $this->elapsed_ms( $started_at );

		if ( null === $row && ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( $this->storage->get_database_error_message( __( 'The autoloaded options footprint could not be measured.', 'wp-hosting-benchmark' ) ) );
		}

		$total_bytes             = isset( $row['total_bytes'] ) ? (int) $row['total_bytes'] : 0;
		$autoload_kib            = $total_bytes / 1024;
		$autoloaded_option_count = isset( $row['option_count'] ) ? (int) $row['option_count'] : 0;

		return $this->build_success_result(
			$test,
			$duration_ms,
			1,
			null,
			__( 'Autoloaded data', 'wp-hosting-benchmark' ),
			$autoload_kib,
			__( 'KiB', 'wp-hosting-benchmark' ),
			array(
				'value'            => $autoload_kib,
				'poor'             => 1024,
				'excellent'        => 256,
				'higher_is_better' => false,
				'optional'         => false,
			),
			array(
				'autoloaded_option_count' => $autoloaded_option_count,
				'total_bytes'             => $total_bytes,
			)
		);
	}

	/**
	 * Benchmark filesystem write and read speed inside uploads.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_filesystem( array $profile, Time_Guard $guard ) {
		$test       = $this->get_test_by_slug( 'filesystem' );
		$uploads    = wp_get_upload_dir();
		$iterations = (int) $profile['file_iterations'];
		$file_size  = (int) $profile['file_size'];
		$payload    = str_repeat( 'b', $file_size );
		$created    = array();

		if ( ! empty( $uploads['error'] ) ) {
			throw new \RuntimeException( sanitize_text_field( $uploads['error'] ) );
		}

		$benchmark_dir = trailingslashit( $uploads['basedir'] ) . 'wp-hosting-benchmark';

		if ( ! wp_mkdir_p( $benchmark_dir ) ) {
			throw new \RuntimeException( __( 'The uploads benchmark directory could not be created.', 'wp-hosting-benchmark' ) );
		}

		$started_at = microtime( true );

		try {
			for ( $index = 1; $index <= $iterations; $index++ ) {
				$guard->enforce_every( $index, 2, $test['label'] );
				$file_path = trailingslashit( $benchmark_dir ) . 'benchmark-' . wp_generate_password( 8, false, false ) . '-' . $index . '.tmp';

				if ( false === file_put_contents( $file_path, $payload ) ) {
					throw new \RuntimeException( __( 'A temporary benchmark file could not be written.', 'wp-hosting-benchmark' ) );
				}

				$created[] = $file_path;
				$contents  = file_get_contents( $file_path );

				if ( false === $contents || strlen( $contents ) !== $file_size ) {
					throw new \RuntimeException( __( 'A temporary benchmark file could not be read back reliably.', 'wp-hosting-benchmark' ) );
				}
			}
		} finally {
			foreach ( $created as $file_path ) {
				if ( is_file( $file_path ) ) {
					unlink( $file_path );
				}
			}

			if ( is_dir( $benchmark_dir ) ) {
				$directory_contents = scandir( $benchmark_dir );

				if ( is_array( $directory_contents ) && count( $directory_contents ) <= 2 ) {
					rmdir( $benchmark_dir );
				}
			}
		}

		$duration_ms        = $this->elapsed_ms( $started_at );
		$ops_per_second     = $this->calculate_ops_per_second( $iterations * 2, $duration_ms );
		$throughput_kib_sec = $this->calculate_throughput_kib( $file_size * $iterations * 2, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations * 2,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$throughput_kib_sec,
			__( 'KiB/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $throughput_kib_sec,
				'poor'             => 256,
				'excellent'        => 12288,
				'higher_is_better' => true,
				'optional'         => false,
			),
			array(
				'file_size'  => $file_size,
				'iterations' => $iterations,
			)
		);
	}

	/**
	 * Benchmark persistent object cache performance when available.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_object_cache( array $profile, Time_Guard $guard ) {
		$test = $this->get_test_by_slug( 'object_cache' );

		if ( ! wp_using_ext_object_cache() ) {
			return $this->build_unavailable_result( $test, __( 'No persistent object cache drop-in is active.', 'wp-hosting-benchmark' ) );
		}

		$iterations = (int) $profile['cache_iterations'];
		$keys       = array();
		$group      = 'wp_hosting_benchmark';
		$started_at = microtime( true );

		try {
			for ( $index = 1; $index <= $iterations; $index++ ) {
				$guard->enforce_every( $index, 20, $test['label'] );
				$key = 'cache_' . $index . '_' . wp_generate_password( 5, false, false );

				if ( ! wp_cache_set( $key, $index, $group, MINUTE_IN_SECONDS ) ) {
					throw new \RuntimeException( __( 'The object cache could not store a benchmark value.', 'wp-hosting-benchmark' ) );
				}

				$value = wp_cache_get( $key, $group );

				if ( (string) $value !== (string) $index ) {
					throw new \RuntimeException( __( 'The object cache returned an unexpected benchmark value.', 'wp-hosting-benchmark' ) );
				}

				$keys[] = $key;
			}
		} finally {
			foreach ( $keys as $key ) {
				wp_cache_delete( $key, $group );
			}
		}

		$duration_ms    = $this->elapsed_ms( $started_at );
		$operations     = $iterations * 2;
		$ops_per_second = $this->calculate_ops_per_second( $operations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$operations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$ops_per_second,
			__( 'ops/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $ops_per_second,
				'poor'             => 200,
				'excellent'        => 5000,
				'higher_is_better' => true,
				'optional'         => true,
			)
		);
	}

	/**
	 * Benchmark transient API performance.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_transients( array $profile, Time_Guard $guard ) {
		$test       = $this->get_test_by_slug( 'transients' );
		$iterations = (int) $profile['transient_iterations'];
		$keys       = array();
		$started_at = microtime( true );

		try {
			for ( $index = 1; $index <= $iterations; $index++ ) {
				$guard->enforce_every( $index, 10, $test['label'] );
				$key = 'wp_hosting_benchmark_transient_' . wp_generate_password( 6, false, false ) . '_' . $index;

				if ( ! set_transient( $key, $index, MINUTE_IN_SECONDS ) ) {
					throw new \RuntimeException( __( 'A benchmark transient could not be written.', 'wp-hosting-benchmark' ) );
				}

				$value = get_transient( $key );

				if ( (string) $value !== (string) $index ) {
					throw new \RuntimeException( __( 'A benchmark transient could not be read back reliably.', 'wp-hosting-benchmark' ) );
				}

				$keys[] = $key;
			}
		} finally {
			foreach ( $keys as $key ) {
				delete_transient( $key );
			}
		}

		$duration_ms    = $this->elapsed_ms( $started_at );
		$operations     = $iterations * 2;
		$ops_per_second = $this->calculate_ops_per_second( $operations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$operations,
			$ops_per_second,
			__( 'Throughput', 'wp-hosting-benchmark' ),
			$ops_per_second,
			__( 'ops/s', 'wp-hosting-benchmark' ),
			array(
				'value'            => $ops_per_second,
				'poor'             => 20,
				'excellent'        => 400,
				'higher_is_better' => true,
				'optional'         => false,
			)
		);
	}

	/**
	 * Benchmark a realistic WordPress content query path.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_content_query( array $profile, Time_Guard $guard ) {
		$test        = $this->get_test_by_slug( 'content_query' );
		$iterations  = max( 1, (int) $profile['content_query_iterations'] );
		$page_size   = 5;
		$post_types  = $this->get_benchmark_content_post_types();
		$total_posts = 0;
		$started_at  = microtime( true );

		for ( $index = 1; $index <= $iterations; $index++ ) {
			$guard->enforce_every( $index, 1, $test['label'] );
			$offset = ( ( $index - 1 ) % 3 ) * $page_size;
			$query  = new \WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => 'publish',
					'posts_per_page'         => $page_size,
					'offset'                 => $offset,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'fields'                 => 'ids',
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);

			if ( ! is_array( $query->posts ) ) {
				throw new \RuntimeException( __( 'The content query benchmark returned an unexpected result set.', 'wp-hosting-benchmark' ) );
			}

			$total_posts += count( $query->posts );

			foreach ( $query->posts as $post_id ) {
				get_the_title( $post_id );
				get_permalink( $post_id );
			}
		}

		$duration_ms      = $this->elapsed_ms( $started_at );
		$average_query_ms = $duration_ms / $iterations;
		$ops_per_second   = $this->calculate_ops_per_second( $iterations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Average query latency', 'wp-hosting-benchmark' ),
			$average_query_ms,
			__( 'ms', 'wp-hosting-benchmark' ),
			array(
				'value'            => $average_query_ms,
				'poor'             => 250,
				'excellent'        => 40,
				'higher_is_better' => false,
				'optional'         => false,
			),
			array(
				'post_types'     => implode( ', ', $post_types ),
				'posts_returned' => $total_posts,
				'page_size'      => $page_size,
			)
		);
	}

	/**
	 * Benchmark a real internal REST API collection request.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_rest_posts_latency( array $profile, Time_Guard $guard ) {
		$test        = $this->get_test_by_slug( 'rest_posts_latency' );
		$iterations  = max( 1, (int) $profile['rest_request_iterations'] );
		$request_url = add_query_arg(
			array(
				'per_page' => 5,
				'_fields'  => 'id,date,slug,link,title.rendered',
			),
			rest_url( 'wp/v2/posts' )
		);
		$total_items = 0;
		$started_at  = microtime( true );

		for ( $index = 1; $index <= $iterations; $index++ ) {
			$guard->enforce_every( $index, 1, $test['label'] );
			$response = wp_remote_get( $request_url, $this->get_loopback_http_args( (int) $profile['http_timeout'] ) );

			if ( is_wp_error( $response ) ) {
				return $this->build_failure_result( $test, $response->get_error_message(), $this->elapsed_ms( $started_at ), $index - 1 );
			}

			$status_code = (int) wp_remote_retrieve_response_code( $response );

			if ( ! $this->is_successful_http_status( $status_code ) ) {
				return $this->build_failure_result(
					$test,
					$this->build_http_status_message( __( 'The REST posts benchmark returned an unexpected HTTP status.', 'wp-hosting-benchmark' ), $status_code ),
					$this->elapsed_ms( $started_at ),
					$index,
					null,
					array(
						'http_code' => $status_code,
					)
				);
			}

			$payload = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $payload ) ) {
				return $this->build_failure_result( $test, __( 'The REST posts benchmark returned an unexpected JSON payload.', 'wp-hosting-benchmark' ), $this->elapsed_ms( $started_at ), $index );
			}

			$total_items += count( $payload );
		}

		$duration_ms        = $this->elapsed_ms( $started_at );
		$average_latency_ms = $duration_ms / $iterations;
		$ops_per_second     = $this->calculate_ops_per_second( $iterations, $duration_ms );

		return $this->build_success_result(
			$test,
			$duration_ms,
			$iterations,
			$ops_per_second,
			__( 'Average latency', 'wp-hosting-benchmark' ),
			$average_latency_ms,
			__( 'ms', 'wp-hosting-benchmark' ),
			array(
				'value'            => $average_latency_ms,
				'poor'             => 1500,
				'excellent'        => 200,
				'higher_is_better' => false,
				'optional'         => true,
			),
			array(
				'requests'       => $iterations,
				'items_returned' => $total_items,
			)
		);
	}

	/**
	 * Benchmark HTTP loopback latency to the site home page.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_loopback_latency( array $profile, Time_Guard $guard ) {
		$test        = $this->get_test_by_slug( 'loopback_latency' );
		$guard->enforce( $test['label'] );
		$started_at  = microtime( true );
		$response    = wp_remote_get( home_url( '/' ), $this->get_loopback_http_args( (int) $profile['http_timeout'] ) );
		$duration_ms = $this->elapsed_ms( $started_at );

		if ( is_wp_error( $response ) ) {
			return $this->build_failure_result( $test, $response->get_error_message(), $duration_ms, 1 );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! $this->is_successful_http_status( $status_code ) ) {
			return $this->build_failure_result(
				$test,
				$this->build_http_status_message( __( 'The loopback request returned an unexpected HTTP status.', 'wp-hosting-benchmark' ), $status_code ),
				$duration_ms,
				1,
				null,
				array(
					'http_code' => $status_code,
				)
			);
		}

		return $this->build_success_result(
			$test,
			$duration_ms,
			1,
			null,
			__( 'Latency', 'wp-hosting-benchmark' ),
			$duration_ms,
			__( 'ms', 'wp-hosting-benchmark' ),
			array(
				'value'            => $duration_ms,
				'poor'             => 1200,
				'excellent'        => 150,
				'higher_is_better' => false,
				'optional'         => false,
			),
			array(
				'http_code' => $status_code,
			)
		);
	}

	/**
	 * Benchmark basic WordPress bootstrap timing via an internal admin-only probe.
	 *
	 * @param array      $profile Benchmark profile.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function benchmark_bootstrap_timing( array $profile, Time_Guard $guard ) {
		$test        = $this->get_test_by_slug( 'bootstrap_timing' );
		$guard->enforce( $test['label'] );
		$cookies     = $this->get_authenticated_cookies();

		if ( empty( $cookies ) ) {
			return $this->build_unavailable_result( $test, __( 'Authenticated cookies were not available for the internal bootstrap probe.', 'wp-hosting-benchmark' ) );
		}

		$started_at = microtime( true );
		$response   = wp_remote_post(
			admin_url( 'admin-post.php' ),
			array(
				'timeout'     => (int) $profile['http_timeout'],
				'redirection' => 0,
				'sslverify'   => $this->should_verify_loopback_ssl(),
				'cookies'     => $cookies,
				'body'        => array(
					'action'   => 'wp_hosting_benchmark_bootstrap_probe',
					'_wpnonce' => wp_create_nonce( 'wp_hosting_benchmark_bootstrap_probe' ),
				),
			)
		);
		$duration_ms = $this->elapsed_ms( $started_at );

		if ( is_wp_error( $response ) ) {
			return $this->build_failure_result( $test, $response->get_error_message(), $duration_ms, 1 );
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );

		if ( ! $this->is_successful_http_status( $status_code ) ) {
			return $this->build_failure_result(
				$test,
				$this->build_http_status_message( __( 'The internal bootstrap probe returned an unexpected HTTP status.', 'wp-hosting-benchmark' ), $status_code ),
				$duration_ms,
				1,
				null,
				array(
					'http_code' => $status_code,
				)
			);
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $payload ) || empty( $payload['success'] ) || ! isset( $payload['data']['bootstrap_ms'] ) || ! is_numeric( $payload['data']['bootstrap_ms'] ) ) {
			return $this->build_failure_result( $test, __( 'The internal bootstrap probe returned an unexpected response.', 'wp-hosting-benchmark' ), $duration_ms, 1 );
		}

		$bootstrap_ms = (float) $payload['data']['bootstrap_ms'];

		return $this->build_success_result(
			$test,
			$duration_ms,
			1,
			null,
			__( 'Bootstrap', 'wp-hosting-benchmark' ),
			$bootstrap_ms,
			__( 'ms', 'wp-hosting-benchmark' ),
			array(
				'value'            => $bootstrap_ms,
				'poor'             => 700,
				'excellent'        => 80,
				'higher_is_better' => false,
				'optional'         => false,
			),
			array(
				'loopback_duration_ms' => $duration_ms,
			)
		);
	}

	/**
	 * Get benchmark environment details.
	 *
	 * @return array
	 */
	protected function get_environment_details() {
		global $wpdb;

		$execution_time = (int) ini_get( 'max_execution_time' );

		return array(
			'php_version'          => PHP_VERSION,
			'wordpress_version'    => get_bloginfo( 'version' ),
			'database_version'     => method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '',
			'object_cache_enabled' => wp_using_ext_object_cache(),
			'object_cache_status'  => wp_using_ext_object_cache() ? __( 'Persistent object cache detected', 'wp-hosting-benchmark' ) : __( 'No persistent object cache detected', 'wp-hosting-benchmark' ),
			'memory_limit'         => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ),
			'max_execution_time'   => $execution_time > 0 ? $execution_time : __( 'Unlimited', 'wp-hosting-benchmark' ),
			'server_software'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : __( 'Unavailable', 'wp-hosting-benchmark' ),
		);
	}

	/**
	 * Normalize the intensity value.
	 *
	 * @param string $intensity Raw intensity.
	 * @return string
	 */
	protected function normalize_intensity( $intensity ) {
		$intensity = sanitize_key( $intensity );
		$levels    = $this->get_intensity_levels();

		return isset( $levels[ $intensity ] ) ? $intensity : 'standard';
	}

	/**
	 * Get the iteration profile for a given intensity.
	 *
	 * @param string $intensity Intensity key.
	 * @return array
	 */
	protected function get_intensity_profile( $intensity ) {
		$profiles = array(
			'low'      => array(
				'php_iterations'      => 40000,
				'cpu_iterations'      => 20000,
				'memory_iterations'   => 150,
				'memory_chunk_size'   => 4096,
				'db_reads'            => 15,
				'db_writes'           => 8,
				'content_query_iterations' => 4,
				'file_iterations'     => 4,
				'file_size'           => 16384,
				'cache_iterations'    => 50,
				'transient_iterations' => 20,
				'rest_request_iterations' => 1,
				'http_timeout'        => 6,
			),
			'standard' => array(
				'php_iterations'      => 100000,
				'cpu_iterations'      => 50000,
				'memory_iterations'   => 300,
				'memory_chunk_size'   => 8192,
				'db_reads'            => 30,
				'db_writes'           => 15,
				'content_query_iterations' => 8,
				'file_iterations'     => 6,
				'file_size'           => 32768,
				'cache_iterations'    => 120,
				'transient_iterations' => 40,
				'rest_request_iterations' => 2,
				'http_timeout'        => 8,
			),
			'high'     => array(
				'php_iterations'      => 200000,
				'cpu_iterations'      => 100000,
				'memory_iterations'   => 600,
				'memory_chunk_size'   => 8192,
				'db_reads'            => 60,
				'db_writes'           => 30,
				'content_query_iterations' => 12,
				'file_iterations'     => 10,
				'file_size'           => 65536,
				'cache_iterations'    => 240,
				'transient_iterations' => 80,
				'rest_request_iterations' => 3,
				'http_timeout'        => 10,
			),
		);

		return $profiles[ $intensity ];
	}

	/**
	 * Get all benchmark tests.
	 *
	 * @return array
	 */
	protected function get_tests() {
		return array(
			array(
				'slug'     => 'php_execution',
				'label'    => __( 'PHP execution speed', 'wp-hosting-benchmark' ),
				'category' => 'php_cpu',
				'method'   => 'benchmark_php_execution',
				'optional' => false,
			),
			array(
				'slug'     => 'cpu_calculation',
				'label'    => __( 'CPU calculation speed', 'wp-hosting-benchmark' ),
				'category' => 'php_cpu',
				'method'   => 'benchmark_cpu_calculation',
				'optional' => false,
			),
			array(
				'slug'     => 'memory_allocation',
				'label'    => __( 'Memory allocation speed', 'wp-hosting-benchmark' ),
				'category' => 'php_cpu',
				'method'   => 'benchmark_memory_allocation',
				'optional' => false,
			),
			array(
				'slug'     => 'database_read',
				'label'    => __( 'WordPress database read speed', 'wp-hosting-benchmark' ),
				'category' => 'database',
				'method'   => 'benchmark_database_read',
				'optional' => false,
			),
			array(
				'slug'     => 'database_write',
				'label'    => __( 'WordPress database write speed', 'wp-hosting-benchmark' ),
				'category' => 'database',
				'method'   => 'benchmark_database_write',
				'optional' => false,
			),
			array(
				'slug'     => 'autoloaded_options',
				'label'    => __( 'Autoloaded options footprint', 'wp-hosting-benchmark' ),
				'category' => 'database',
				'method'   => 'benchmark_autoloaded_options',
				'optional' => false,
			),
			array(
				'slug'     => 'filesystem',
				'label'    => __( 'Filesystem write and read speed', 'wp-hosting-benchmark' ),
				'category' => 'filesystem',
				'method'   => 'benchmark_filesystem',
				'optional' => false,
			),
			array(
				'slug'     => 'object_cache',
				'label'    => __( 'Object cache performance', 'wp-hosting-benchmark' ),
				'category' => 'cache',
				'method'   => 'benchmark_object_cache',
				'optional' => true,
			),
			array(
				'slug'     => 'transients',
				'label'    => __( 'Transient API performance', 'wp-hosting-benchmark' ),
				'category' => 'cache',
				'method'   => 'benchmark_transients',
				'optional' => false,
			),
			array(
				'slug'     => 'content_query',
				'label'    => __( 'Real-world content query performance', 'wp-hosting-benchmark' ),
				'category' => 'application',
				'method'   => 'benchmark_content_query',
				'optional' => false,
			),
			array(
				'slug'     => 'rest_posts_latency',
				'label'    => __( 'REST posts collection latency', 'wp-hosting-benchmark' ),
				'category' => 'application',
				'method'   => 'benchmark_rest_posts_latency',
				'optional' => true,
			),
			array(
				'slug'     => 'loopback_latency',
				'label'    => __( 'HTTP loopback request latency', 'wp-hosting-benchmark' ),
				'category' => 'network',
				'method'   => 'benchmark_loopback_latency',
				'optional' => false,
			),
			array(
				'slug'     => 'bootstrap_timing',
				'label'    => __( 'Basic WordPress bootstrap timing', 'wp-hosting-benchmark' ),
				'category' => 'network',
				'method'   => 'benchmark_bootstrap_timing',
				'optional' => false,
			),
		);
	}

	/**
	 * Look up one test descriptor by slug.
	 *
	 * @param string $slug Test slug.
	 * @return array
	 */
	protected function get_test_by_slug( $slug ) {
		foreach ( $this->get_tests() as $test ) {
			if ( $slug === $test['slug'] ) {
				return $test;
			}
		}

		return array();
	}

	/**
	 * Build a success result payload.
	 *
	 * @param array       $test          Test metadata.
	 * @param float       $duration_ms   Duration in milliseconds.
	 * @param int         $operations    Operation count.
	 * @param float|null  $ops_per_second Operations per second.
	 * @param string      $metric_label  Metric label.
	 * @param float|null  $metric_value  Metric value.
	 * @param string      $metric_unit   Metric unit.
	 * @param array       $scoring       Scoring metadata.
	 * @param array       $details       Additional detail values.
	 * @return array
	 */
	protected function build_success_result( array $test, $duration_ms, $operations, $ops_per_second, $metric_label, $metric_value, $metric_unit, array $scoring, array $details = array() ) {
		return array(
			'slug'           => $test['slug'],
			'label'          => $test['label'],
			'category'       => $test['category'],
			'category_label' => $this->scorer->get_category_label( $test['category'] ),
			'duration_ms'    => round( (float) $duration_ms, 3 ),
			'operations'     => (int) $operations,
			'ops_per_second' => null === $ops_per_second ? null : round( (float) $ops_per_second, 2 ),
			'status'         => 'success',
			'optional'       => ! empty( $test['optional'] ),
			'error_message'  => '',
			'metric_label'   => $metric_label,
			'metric_value'   => null === $metric_value ? null : round( (float) $metric_value, 2 ),
			'metric_unit'    => $metric_unit,
			'details'        => $details,
			'scoring'        => $scoring,
		);
	}

	/**
	 * Build a failed result payload.
	 *
	 * @param array      $test         Test metadata.
	 * @param string     $message      Error message.
	 * @param float      $duration_ms  Duration in milliseconds.
	 * @param int        $operations   Operation count.
	 * @param float|null $ops_per_second Operations per second.
	 * @return array
	 */
	protected function build_failure_result( array $test, $message, $duration_ms = 0.0, $operations = 0, $ops_per_second = null, array $details = array() ) {
		return array(
			'slug'           => $test['slug'],
			'label'          => $test['label'],
			'category'       => $test['category'],
			'category_label' => $this->scorer->get_category_label( $test['category'] ),
			'duration_ms'    => round( (float) $duration_ms, 3 ),
			'operations'     => (int) $operations,
			'ops_per_second' => null === $ops_per_second ? null : round( (float) $ops_per_second, 2 ),
			'status'         => 'failed',
			'optional'       => ! empty( $test['optional'] ),
			'error_message'  => sanitize_text_field( $message ),
			'metric_label'   => '',
			'metric_value'   => null,
			'metric_unit'    => '',
			'details'        => $details,
			'scoring'        => array(),
		);
	}

	/**
	 * Build an unavailable result payload.
	 *
	 * @param array  $test    Test metadata.
	 * @param string $message Unavailable reason.
	 * @return array
	 */
	protected function build_unavailable_result( array $test, $message ) {
		return array(
			'slug'           => $test['slug'],
			'label'          => $test['label'],
			'category'       => $test['category'],
			'category_label' => $this->scorer->get_category_label( $test['category'] ),
			'duration_ms'    => 0,
			'operations'     => 0,
			'ops_per_second' => null,
			'status'         => 'unavailable',
			'optional'       => ! empty( $test['optional'] ),
			'error_message'  => sanitize_text_field( $message ),
			'metric_label'   => '',
			'metric_value'   => null,
			'metric_unit'    => '',
			'details'        => array(),
			'scoring'        => array(),
		);
	}

	/**
	 * Build unavailable results for the remaining tests.
	 *
	 * @param array  $tests   Remaining tests.
	 * @param string $message Unavailable message.
	 * @return array
	 */
	protected function build_unavailable_results( array $tests, $message ) {
		$results = array();

		foreach ( $tests as $test ) {
			$results[] = $this->build_unavailable_result( $test, $message );
		}

		return $results;
	}

	/**
	 * Summarize overall benchmark execution.
	 *
	 * @param array      $results Test results.
	 * @param Time_Guard $guard   Time guard.
	 * @return array
	 */
	protected function summarize_results( array $results, Time_Guard $guard ) {
		$summary = array(
			'success'     => 0,
			'failed'      => 0,
			'unavailable' => 0,
			'elapsed_ms'  => $guard->get_elapsed_milliseconds(),
		);

		foreach ( $results as $result ) {
			if ( isset( $summary[ $result['status'] ] ) ) {
				++$summary[ $result['status'] ];
			}
		}

		return $summary;
	}

	/**
	 * Build benchmark recommendations from the scoring output.
	 *
	 * @param array $results Test results.
	 * @param array $scores  Score output.
	 * @return array
	 */
	protected function build_recommendations( array $results, array $scores ) {
		$recommendations = array();

		if ( $scores['overall'] < 40 ) {
			$recommendations[] = __( 'Overall performance is below the expected range for comfortable WordPress administration. Review hosting plan limits, plugin load, and PHP version first.', 'wp-hosting-benchmark' );
		}

		if ( isset( $scores['categories']['php_cpu']['score'] ) && null !== $scores['categories']['php_cpu']['score'] && $scores['categories']['php_cpu']['score'] < 60 ) {
			$recommendations[] = __( 'PHP and CPU throughput is modest. Prioritize a current PHP release, trim heavy plugins, and avoid synchronous work on admin requests.', 'wp-hosting-benchmark' );
		}

		if ( isset( $scores['categories']['database']['score'] ) && null !== $scores['categories']['database']['score'] && $scores['categories']['database']['score'] < 60 ) {
			$recommendations[] = __( 'Database operations are comparatively slow. Review autoloaded options, slow queries, and database server sizing.', 'wp-hosting-benchmark' );
		}

		if ( isset( $scores['categories']['filesystem']['score'] ) && null !== $scores['categories']['filesystem']['score'] && $scores['categories']['filesystem']['score'] < 60 ) {
			$recommendations[] = __( 'Uploads storage is relatively slow. Avoid large synchronous file work and offload image processing when possible.', 'wp-hosting-benchmark' );
		}

		if ( isset( $scores['categories']['network']['score'] ) && null !== $scores['categories']['network']['score'] && $scores['categories']['network']['score'] < 60 ) {
			$recommendations[] = __( 'Loopback or bootstrap timing is slow. Check local DNS, HTTPS configuration, and server-level loopback support.', 'wp-hosting-benchmark' );
		}

		if ( isset( $scores['categories']['application']['score'] ) && null !== $scores['categories']['application']['score'] && $scores['categories']['application']['score'] < 60 ) {
			$recommendations[] = __( 'Real-world WordPress request handling is modest. Review heavy theme logic, archive queries, and REST API filters or middleware.', 'wp-hosting-benchmark' );
		}

		foreach ( $results as $result ) {
			if ( 'object_cache' === $result['slug'] && 'unavailable' === $result['status'] ) {
				$recommendations[] = __( 'No persistent object cache was detected. Redis or Memcached can improve WordPress admin, cache, and transient-heavy workloads.', 'wp-hosting-benchmark' );
			}

			if ( 'autoloaded_options' === $result['slug'] && 'success' === $result['status'] && isset( $result['metric_value'] ) && is_numeric( $result['metric_value'] ) && (float) $result['metric_value'] > 800 ) {
				$recommendations[] = __( 'Autoloaded options are above the commonly recommended range. Review large autoloaded plugin or theme settings to reduce every-request overhead.', 'wp-hosting-benchmark' );
			}

			if ( in_array( $result['status'], array( 'failed', 'unavailable' ), true ) ) {
				$recommendations[] = sprintf(
					/* translators: %1$s: benchmark test label, %2$s: status message */
					__( '%1$s reported: %2$s', 'wp-hosting-benchmark' ),
					$result['label'],
					$result['error_message']
				);
			}
		}

		return array_values( array_unique( $recommendations ) );
	}

	/**
	 * Calculate elapsed milliseconds since a start timestamp.
	 *
	 * @param float $started_at Start timestamp.
	 * @return float
	 */
	protected function elapsed_ms( $started_at ) {
		return ( microtime( true ) - $started_at ) * 1000;
	}

	/**
	 * Calculate operations per second.
	 *
	 * @param int   $operations Operation count.
	 * @param float $duration_ms Duration in milliseconds.
	 * @return float
	 */
	protected function calculate_ops_per_second( $operations, $duration_ms ) {
		if ( $duration_ms <= 0 ) {
			return 0.0;
		}

		return $operations / ( $duration_ms / 1000 );
	}

	/**
	 * Calculate throughput in KiB per second.
	 *
	 * @param int   $bytes       Byte count.
	 * @param float $duration_ms Duration in milliseconds.
	 * @return float
	 */
	protected function calculate_throughput_kib( $bytes, $duration_ms ) {
		if ( $duration_ms <= 0 ) {
			return 0.0;
		}

		return ( $bytes / 1024 ) / ( $duration_ms / 1000 );
	}

	/**
	 * Determine whether an HTTP status code should count as successful.
	 *
	 * @param int $status_code HTTP status code.
	 * @return bool
	 */
	protected function is_successful_http_status( $status_code ) {
		return $status_code >= 200 && $status_code < 400;
	}

	/**
	 * Build an admin-facing HTTP status error message.
	 *
	 * @param string $fallback    Fallback message.
	 * @param int    $status_code HTTP status code.
	 * @return string
	 */
	protected function build_http_status_message( $fallback, $status_code ) {
		if ( $status_code > 0 ) {
			return sprintf(
				/* translators: 1: fallback message, 2: HTTP status code */
				__( '%1$s Status code: %2$d.', 'wp-hosting-benchmark' ),
				$fallback,
				$status_code
			);
		}

		return $fallback;
	}

	/**
	 * Build a sanitized error message from a throwable.
	 *
	 * @param \Throwable $throwable Throwable instance.
	 * @param string     $fallback  Fallback message.
	 * @return string
	 */
	protected function build_safe_error_message( \Throwable $throwable, $fallback ) {
		$message = sanitize_text_field( $throwable->getMessage() );

		return '' !== $message ? $message : $fallback;
	}

	/**
	 * Build HTTP args for loopback requests.
	 *
	 * @param int $timeout Timeout in seconds.
	 * @return array
	 */
	protected function get_loopback_http_args( $timeout ) {
		return array(
			'timeout'     => max( 3, $timeout ),
			'redirection' => 3,
			'sslverify'   => $this->should_verify_loopback_ssl(),
			'headers'     => array(
				'Cache-Control' => 'no-cache',
			),
		);
	}

	/**
	 * Decide whether loopback requests should verify SSL.
	 *
	 * @return bool
	 */
	protected function should_verify_loopback_ssl() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
			return false;
		}

		if ( preg_match( '/\.(local|test)$/', $host ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the set of autoload values WordPress treats as loaded on every request.
	 *
	 * @return array
	 */
	protected function get_autoload_values_to_measure() {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			$autoload_values = wp_autoload_values_to_autoload();

			if ( is_array( $autoload_values ) && ! empty( $autoload_values ) ) {
				return array_values( array_unique( array_map( 'sanitize_key', $autoload_values ) ) );
			}
		}

		return array( 'yes' );
	}

	/**
	 * Get public content post types for the real-world content query benchmark.
	 *
	 * @return array
	 */
	protected function get_benchmark_content_post_types() {
		$post_types = get_post_types( array( 'publicly_queryable' => true ), 'names' );

		if ( isset( $post_types['attachment'] ) ) {
			unset( $post_types['attachment'] );
		}

		$post_types = array_values( array_filter( array_map( 'sanitize_key', $post_types ) ) );

		return ! empty( $post_types ) ? $post_types : array( 'post', 'page' );
	}

	/**
	 * Build authenticated cookies for the internal admin-post probe.
	 *
	 * @return array
	 */
	protected function get_authenticated_cookies() {
		$cookies  = array();
		$prefixes = array( 'wordpress_', 'wp-settings-', 'wp-settings-time-', 'wp_lang' );

		foreach ( $_COOKIE as $name => $value ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					$cookies[] = new \WP_Http_Cookie(
						array(
							'name'  => $name,
							'value' => (string) $value,
						)
					);
					break;
				}
			}
		}

		return $cookies;
	}
}