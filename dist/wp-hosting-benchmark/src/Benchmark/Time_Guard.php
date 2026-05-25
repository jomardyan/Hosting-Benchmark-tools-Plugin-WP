<?php
/**
 * Keeps benchmark runs inside a safe execution window.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Benchmark;

defined( 'ABSPATH' ) || exit;

class Time_Guard {
	/**
	 * Request start timestamp.
	 *
	 * @var float
	 */
	protected $started_at;

	/**
	 * Allowed runtime in seconds.
	 *
	 * @var float
	 */
	protected $allowed_runtime_seconds;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->started_at              = microtime( true );
		$this->allowed_runtime_seconds = $this->determine_allowed_runtime_seconds();
	}

	/**
	 * Get the elapsed runtime in milliseconds.
	 *
	 * @return float
	 */
	public function get_elapsed_milliseconds() {
		return round( ( microtime( true ) - $this->started_at ) * 1000, 3 );
	}

	/**
	 * Determine whether the run should stop.
	 *
	 * @return bool
	 */
	public function should_abort() {
		return ( microtime( true ) - $this->started_at ) >= $this->allowed_runtime_seconds;
	}

	/**
	 * Throw when the benchmark is too close to the PHP execution limit.
	 *
	 * @param string $context Current benchmark label.
	 * @return void
	 */
	public function enforce( $context ) {
		if ( $this->should_abort() ) {
			throw new Execution_Limit_Exception(
				sprintf(
					/* translators: %s: benchmark label */
					__( 'The benchmark stopped while running %s to stay within the server execution limit.', 'wp-hosting-benchmark' ),
					$context
				)
			);
		}
	}

	/**
	 * Run the time guard every N iterations instead of every loop pass.
	 *
	 * @param int    $iteration Current iteration.
	 * @param int    $frequency Guard frequency.
	 * @param string $context   Current benchmark label.
	 * @return void
	 */
	public function enforce_every( $iteration, $frequency, $context ) {
		if ( $iteration % max( 1, $frequency ) === 0 ) {
			$this->enforce( $context );
		}
	}

	/**
	 * Determine a safe runtime budget from PHP settings.
	 *
	 * @return float
	 */
	protected function determine_allowed_runtime_seconds() {
		$max_execution_time = (int) ini_get( 'max_execution_time' );

		if ( $max_execution_time <= 0 ) {
			return 20.0;
		}

		$buffer = min( 3.0, max( 1.0, $max_execution_time * 0.15 ) );

		return max( 2.0, min( 20.0, $max_execution_time - $buffer ) );
	}
}