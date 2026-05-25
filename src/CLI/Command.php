<?php
/**
 * WP-CLI commands.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\CLI;

use WPHostingBenchmark\Benchmark\Runner;
use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Run and manage hosting benchmark runs from WP-CLI.
 */
class Command {
	/**
	 * Benchmark runner.
	 *
	 * @var Runner
	 */
	protected $runner;

	/**
	 * Storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Runner  $runner  Runner.
	 * @param Storage $storage Storage.
	 */
	public function __construct( Runner $runner, Storage $storage ) {
		$this->runner  = $runner;
		$this->storage = $storage;
	}

	/**
	 * Register the WP-CLI command.
	 *
	 * @param Runner  $runner  Runner.
	 * @param Storage $storage Storage.
	 * @return void
	 */
	public static function register( Runner $runner, Storage $storage ) {
		if ( ! class_exists( '\\WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'hosting-benchmark', new self( $runner, $storage ) );
	}

	/**
	 * Run a hosting benchmark.
	 *
	 * ## OPTIONS
	 *
	 * [--intensity=<intensity>]
	 * : Intensity level (low, standard, high). Defaults to the configured setting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp hosting-benchmark run
	 *     wp hosting-benchmark run --intensity=high
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		unset( $args );
		$settings  = Storage::get_settings();
		$intensity = isset( $assoc_args['intensity'] ) ? (string) $assoc_args['intensity'] : $settings['default_intensity'];

		try {
			$run = $this->runner->run_benchmark( $intensity );
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: run id, 2: overall score, 3: confidence. */
				__( 'Benchmark complete (id %1$s): score %2$d/100, confidence %3$d%%.', 'wp-hosting-benchmark' ),
				$run['id'],
				(int) $run['scores']['overall'],
				(int) $run['scores']['confidence']
			)
		);
	}

	/**
	 * List stored benchmark runs.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Maximum runs to list. Defaults to 20.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$history = $this->storage->get_history( $limit );

		if ( empty( $history ) ) {
			\WP_CLI::log( __( 'No benchmark runs have been recorded yet.', 'wp-hosting-benchmark' ) );
			return;
		}

		$rows = array();
		foreach ( $history as $run ) {
			$rows[] = array(
				'id'         => $run['id'],
				'created_at' => $run['created_at'],
				'intensity'  => $run['intensity'],
				'score'      => (int) $run['scores']['overall'],
				'confidence' => (int) $run['scores']['confidence'],
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'created_at', 'intensity', 'score', 'confidence' ) );
	}

	/**
	 * Delete a stored benchmark run.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The ID of the run to delete.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function delete( $args, $assoc_args ) {
		unset( $assoc_args );
		$id = isset( $args[0] ) ? (string) $args[0] : '';

		if ( '' === $id ) {
			\WP_CLI::error( __( 'A run id is required.', 'wp-hosting-benchmark' ) );
			return;
		}

		try {
			$deleted = $this->storage->delete_run( $id );
		} catch ( \Throwable $throwable ) {
			\WP_CLI::error( $throwable->getMessage() );
			return;
		}

		if ( $deleted ) {
			\WP_CLI::success( __( 'Run deleted.', 'wp-hosting-benchmark' ) );
		} else {
			\WP_CLI::warning( __( 'No run with that id was found.', 'wp-hosting-benchmark' ) );
		}
	}

	/**
	 * Export a benchmark run as JSON to stdout.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Run id. Defaults to the latest run.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		unset( $assoc_args );
		$id  = isset( $args[0] ) ? (string) $args[0] : '';
		$run = '' !== $id ? $this->storage->get_run( $id ) : $this->storage->get_latest_run();

		if ( ! $run ) {
			\WP_CLI::error( __( 'No matching benchmark run could be found.', 'wp-hosting-benchmark' ) );
			return;
		}

		$payload = wp_json_encode( $run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $payload ) {
			\WP_CLI::error( __( 'The benchmark run could not be encoded as JSON.', 'wp-hosting-benchmark' ) );
			return;
		}

		\WP_CLI::log( $payload );
	}
}
