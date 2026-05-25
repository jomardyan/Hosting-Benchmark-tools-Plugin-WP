<?php
/**
 * WP-Cron coordinator for scheduled benchmark runs.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Schedule;

use WPHostingBenchmark\Benchmark\Runner;
use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Cron {
	/**
	 * Cron hook for scheduled runs.
	 */
	const HOOK = 'wp_hosting_benchmark_scheduled_run';

	/**
	 * In-progress transient name.
	 */
	const LOCK_TRANSIENT = 'wp_hosting_benchmark_scheduled_lock';

	/**
	 * Benchmark runner.
	 *
	 * @var Runner
	 */
	protected $runner;

	/**
	 * Constructor.
	 *
	 * @param Runner $runner Runner.
	 */
	public function __construct( Runner $runner ) {
		$this->runner = $runner;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run_scheduled_benchmark' ) );
		add_action( 'update_option_' . Storage::SETTINGS_OPTION, array( $this, 'sync_schedule' ), 10, 2 );
		add_action( 'add_option_' . Storage::SETTINGS_OPTION, array( $this, 'sync_schedule_added' ), 10, 2 );

		// Reconcile the schedule defensively on admin init in case it drifted.
		add_action( 'admin_init', array( $this, 'reconcile_schedule' ) );
	}

	/**
	 * Reconcile the WP-Cron schedule with current settings.
	 *
	 * @return void
	 */
	public function reconcile_schedule() {
		$settings = Storage::get_settings();
		$desired  = $settings['schedule'];

		$current = wp_get_scheduled_event( self::HOOK );

		if ( 'disabled' === $desired ) {
			if ( $current ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}

		$recurrence = ( 'weekly' === $desired ) ? 'weekly' : 'daily';

		// Weekly recurrence is provided by WP since 5.4.
		if ( ! $current || $current->schedule !== $recurrence ) {
			wp_clear_scheduled_hook( self::HOOK );
			wp_schedule_event( time() + HOUR_IN_SECONDS, $recurrence, self::HOOK );
		}
	}

	/**
	 * Re-sync the schedule when the option is updated.
	 *
	 * @param mixed $old_value Old value.
	 * @param mixed $new_value New value.
	 * @return void
	 */
	public function sync_schedule( $old_value, $new_value ) {
		unset( $old_value, $new_value );
		$this->reconcile_schedule();
	}

	/**
	 * Re-sync the schedule when the option is added for the first time.
	 *
	 * @param string $option Option name.
	 * @param mixed  $value  New value.
	 * @return void
	 */
	public function sync_schedule_added( $option, $value ) {
		unset( $option, $value );
		$this->reconcile_schedule();
	}

	/**
	 * Run the scheduled benchmark.
	 *
	 * @return void
	 */
	public function run_scheduled_benchmark() {
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}

		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		try {
			$settings  = Storage::get_settings();
			$intensity = isset( $settings['default_intensity'] ) ? $settings['default_intensity'] : 'standard';
			$this->runner->run_benchmark( $intensity );
		} catch ( \Throwable $throwable ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP Hosting Benchmark scheduled run failed: ' . sanitize_text_field( $throwable->getMessage() ) );
			}
		} finally {
			delete_transient( self::LOCK_TRANSIENT );
		}
	}

	/**
	 * Clear all scheduled events for the plugin.
	 *
	 * @return void
	 */
	public static function clear_all_events() {
		wp_clear_scheduled_hook( self::HOOK );
	}
}
