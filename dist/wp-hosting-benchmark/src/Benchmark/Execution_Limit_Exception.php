<?php
/**
 * Signals that the benchmark should stop to stay inside the execution budget.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Benchmark;

defined( 'ABSPATH' ) || exit;

class Execution_Limit_Exception extends \RuntimeException {
}