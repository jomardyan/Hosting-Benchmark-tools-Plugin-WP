<?php
/**
 * Benchmark result persistence.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark;

defined( 'ABSPATH' ) || exit;

class Storage {
	/**
	 * Schema version option name.
	 */
	const SCHEMA_OPTION = 'wp_hosting_benchmark_schema_version';

	/**
	 * Current schema version.
	 */
	const SCHEMA_VERSION = '1.0.0';

	/**
	 * Benchmark history option name.
	 */
	const HISTORY_OPTION = 'wp_hosting_benchmark_history';

	/**
	 * Maximum number of runs retained in history.
	 */
	const MAX_HISTORY = 50;

	/**
	 * Run notice transient key prefix.
	 */
	const NOTICE_TRANSIENT_PREFIX = 'wp_hosting_benchmark_notice_';

	/**
	 * Plugin settings option name.
	 */
	const SETTINGS_OPTION = 'wp_hosting_benchmark_settings';

	/**
	 * Default plugin settings.
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'default_intensity' => 'standard',
			'history_limit'     => self::MAX_HISTORY,
			'disabled_tests'    => array(),
			'schedule'          => 'disabled',
		);
	}

	/**
	 * Get sanitized plugin settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$stored   = get_option( self::SETTINGS_OPTION, array() );
		$defaults = self::get_default_settings();

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( $defaults, $stored );

		$settings['default_intensity'] = in_array( $settings['default_intensity'], array( 'low', 'standard', 'high' ), true ) ? $settings['default_intensity'] : 'standard';
		$settings['history_limit']     = max( 1, min( 200, (int) $settings['history_limit'] ) );
		$settings['disabled_tests']    = is_array( $settings['disabled_tests'] ) ? array_values( array_unique( array_map( 'sanitize_key', $settings['disabled_tests'] ) ) ) : array();
		$settings['schedule']          = in_array( $settings['schedule'], array( 'disabled', 'daily', 'weekly' ), true ) ? $settings['schedule'] : 'disabled';

		return $settings;
	}

	/**
	 * Throttle key for the daily temporary-record cleanup sweep.
	 */
	const CLEANUP_TRANSIENT = 'wp_hosting_benchmark_cleanup_throttle';

	/**
	 * Install storage options.
	 *
	 * @return void
	 */
	public static function install() {
		if ( false === get_option( self::HISTORY_OPTION, false ) ) {
			if ( false === add_option( self::HISTORY_OPTION, array(), '', false ) ) {
				throw new \RuntimeException( __( 'The benchmark history option could not be created.', 'wp-hosting-benchmark' ) );
			}
		}

		$current_version = get_option( self::SCHEMA_OPTION );

		if ( self::SCHEMA_VERSION !== $current_version && false === update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION, false ) ) {
			throw new \RuntimeException( __( 'The benchmark storage schema version could not be updated.', 'wp-hosting-benchmark' ) );
		}
	}

	/**
	 * Save a benchmark run.
	 *
	 * @param array $run Run data.
	 * @return string
	 */
	public function save_run( array $run ) {
		$history = $this->read_history();
		$run     = $this->normalize_run( $run );

		if ( empty( $run['id'] ) ) {
			$run['id'] = wp_generate_uuid4();
		}

		if ( empty( $run['created_at'] ) ) {
			$run['created_at'] = current_time( 'mysql' );
		}

		array_unshift( $history, $run );
		$history = array_values( array_slice( $history, 0, $this->get_history_limit() ) );

		if ( ! $this->save_history( $history ) ) {
			throw new \RuntimeException( $this->get_database_error_message( __( 'The benchmark results could not be saved to WordPress options.', 'wp-hosting-benchmark' ) ) );
		}

		return $run['id'];
	}

	/**
	 * Get the configured maximum history size.
	 *
	 * Filterable via `wp_hosting_benchmark_history_limit` and configurable
	 * through the plugin settings page.
	 *
	 * @return int
	 */
	public function get_history_limit() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		$limit    = is_array( $settings ) && isset( $settings['history_limit'] ) ? (int) $settings['history_limit'] : self::MAX_HISTORY;
		$limit    = max( 1, min( 200, $limit ) );

		/**
		 * Filter the maximum number of benchmark runs retained in history.
		 *
		 * @param int $limit Maximum stored runs.
		 */
		return (int) apply_filters( 'wp_hosting_benchmark_history_limit', $limit );
	}

	/**
	 * Persist the history option, ignoring "false because identical" returns.
	 *
	 * @param array $history Updated history.
	 * @return bool True on success or no-op, false only on DB failure.
	 */
	protected function save_history( array $history ) {
		global $wpdb;

		$existing = get_option( self::HISTORY_OPTION, null );

		if ( $existing === $history ) {
			return true;
		}

		if ( false === update_option( self::HISTORY_OPTION, $history, false ) ) {
			return empty( $wpdb->last_error );
		}

		return true;
	}

	/**
	 * Get the latest benchmark run.
	 *
	 * @return array|null
	 */
	public function get_latest_run() {
		$history = $this->get_history( 1 );

		return isset( $history[0] ) ? $history[0] : null;
	}

	/**
	 * Get a benchmark run by ID.
	 *
	 * @param string $run_id Run ID.
	 * @return array|null
	 */
	public function get_run( $run_id ) {
		$run_id = sanitize_text_field( (string) $run_id );

		foreach ( $this->read_history() as $run ) {
			if ( isset( $run['id'] ) && $run_id === $run['id'] ) {
				return $run;
			}
		}

		return null;
	}

	/**
	 * Get benchmark history.
	 *
	 * @param int $limit Max results.
	 * @return array
	 */
	public function get_history( $limit = 10 ) {
		$history = $this->read_history();

		return array_values( array_slice( $history, 0, max( 1, (int) $limit ) ) );
	}

	/**
	 * Delete one benchmark run from history.
	 *
	 * @param string $run_id Run ID.
	 * @return bool
	 */
	public function delete_run( $run_id ) {
		$run_id = sanitize_text_field( (string) $run_id );

		if ( '' === $run_id ) {
			return false;
		}

		$history = $this->read_history();
		$updated = array();
		$deleted = false;

		foreach ( $history as $run ) {
			if ( isset( $run['id'] ) && $run_id === $run['id'] ) {
				$deleted = true;
				continue;
			}

			$updated[] = $run;
		}

		if ( ! $deleted ) {
			return false;
		}

		if ( ! $this->save_history( array_values( $updated ) ) ) {
			throw new \RuntimeException( $this->get_database_error_message( __( 'The selected benchmark run could not be deleted.', 'wp-hosting-benchmark' ) ) );
		}

		return true;
	}

	/**
	 * Clear benchmark history.
	 *
	 * @return void
	 */
	public function clear_history() {
		$history = $this->read_history();

		if ( ! empty( $history ) && ! $this->save_history( array() ) ) {
			throw new \RuntimeException( $this->get_database_error_message( __( 'The stored benchmark history could not be cleared.', 'wp-hosting-benchmark' ) ) );
		}

		delete_transient( self::CLEANUP_TRANSIENT );
		$this->cleanup_temporary_records();
	}

	/**
	 * Insert a temporary option row for the database write benchmark.
	 *
	 * @param string $option_name Option name.
	 * @param string $option_value Option value.
	 * @return bool
	 */
	public function insert_temporary_record( $option_name, $option_value ) {
		global $wpdb;

		$result = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $option_name,
				'option_value' => $option_value,
				'autoload'     => 'off',
			),
			array( '%s', '%s', '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete one temporary benchmark option row.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	public function delete_temporary_record( $option_name ) {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->options,
			array(
				'option_name' => $option_name,
			),
			array( '%s' )
		);

		return false !== $result;
	}

	/**
	 * Delete leftover temporary benchmark records.
	 *
	 * @param string $prefix Prefix used for temporary option names.
	 * @return void
	 */
	public function cleanup_temporary_records( $prefix = 'wp_hosting_benchmark_temp_' ) {
		global $wpdb;

		$pattern = $wpdb->esc_like( $prefix ) . '%';
		$result  = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$pattern
			)
		);

		if ( false === $result && ! empty( $wpdb->last_error ) ) {
			throw new \RuntimeException( $this->get_database_error_message( __( 'Temporary benchmark records could not be cleaned up.', 'wp-hosting-benchmark' ) ) );
		}
	}

	/**
	 * Get a sanitized database error message.
	 *
	 * @param string $fallback Fallback message.
	 * @return string
	 */
	public function get_database_error_message( $fallback ) {
		global $wpdb;

		if ( ! empty( $wpdb->last_error ) ) {
			return sprintf(
				/* translators: %s: database error details */
				__( '%s Database error: %s', 'wp-hosting-benchmark' ),
				$fallback,
				sanitize_text_field( $wpdb->last_error )
			);
		}

		return $fallback;
	}

	/**
	 * Read the full benchmark history array.
	 *
	 * @return array
	 */
	protected function read_history() {
		$history = get_option( self::HISTORY_OPTION, array() );

		if ( ! is_array( $history ) ) {
			return array();
		}

		$normalized_history = array();

		foreach ( $history as $run ) {
			if ( is_array( $run ) ) {
				$normalized_history[] = $this->normalize_run( $run );
			}
		}

		return $normalized_history;
	}

	/**
	 * Normalize a stored benchmark run.
	 *
	 * @param array $run Raw run payload.
	 * @return array
	 */
	protected function normalize_run( array $run ) {
		$run = wp_parse_args(
			$run,
			array(
				'id'              => '',
				'intensity'       => 'standard',
				'created_at'      => current_time( 'mysql' ),
				'environment'     => array(),
				'results'         => array(),
				'scores'          => array(
					'overall'    => 0,
					'confidence' => 0,
					'categories' => array(),
				),
				'recommendations' => array(),
				'summary'         => array(
					'success'     => 0,
					'failed'      => 0,
					'unavailable' => 0,
					'elapsed_ms'  => 0,
				),
				'total_duration'  => 0,
			)
		);

		$run['id']              = sanitize_text_field( (string) $run['id'] );
		$run['intensity']       = sanitize_key( (string) $run['intensity'] );
		$run['created_at']      = sanitize_text_field( (string) $run['created_at'] );
		$run['environment']     = $this->normalize_environment( $run['environment'] );
		$run['results']         = $this->normalize_results( $run['results'] );
		$run['scores']          = is_array( $run['scores'] ) ? $run['scores'] : array();
		$run['recommendations'] = $this->normalize_recommendations( $run['recommendations'] );
		$run['summary']         = is_array( $run['summary'] ) ? $run['summary'] : array();
		$run['total_duration']  = is_numeric( $run['total_duration'] ) ? (float) $run['total_duration'] : 0;

		$run['scores'] = wp_parse_args(
			$run['scores'],
			array(
				'overall'    => 0,
				'confidence' => 0,
				'categories' => array(),
			)
		);

		$run['scores']['overall']    = is_numeric( $run['scores']['overall'] ) ? (int) $run['scores']['overall'] : 0;
		$run['scores']['confidence'] = is_numeric( $run['scores']['confidence'] ) ? (int) $run['scores']['confidence'] : 0;
		$run['scores']['categories'] = $this->normalize_categories( $run['scores']['categories'] );

		$run['summary'] = wp_parse_args(
			$run['summary'],
			array(
				'success'     => 0,
				'failed'      => 0,
				'unavailable' => 0,
				'elapsed_ms'  => 0,
			)
		);

		$run['summary']['success']     = is_numeric( $run['summary']['success'] ) ? (int) $run['summary']['success'] : 0;
		$run['summary']['failed']      = is_numeric( $run['summary']['failed'] ) ? (int) $run['summary']['failed'] : 0;
		$run['summary']['unavailable'] = is_numeric( $run['summary']['unavailable'] ) ? (int) $run['summary']['unavailable'] : 0;
		$run['summary']['elapsed_ms']  = is_numeric( $run['summary']['elapsed_ms'] ) ? (float) $run['summary']['elapsed_ms'] : 0;

		return $run;
	}

	/**
	 * Normalize environment details.
	 *
	 * @param mixed $environment Raw environment payload.
	 * @return array
	 */
	protected function normalize_environment( $environment ) {
		if ( ! is_array( $environment ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $environment as $key => $value ) {
			$normalized[ sanitize_key( (string) $key ) ] = is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}

		return $normalized;
	}

	/**
	 * Normalize stored result rows.
	 *
	 * @param mixed $results Raw results payload.
	 * @return array
	 */
	protected function normalize_results( $results ) {
		if ( ! is_array( $results ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $results as $result ) {
			if ( is_array( $result ) ) {
				$normalized[] = wp_parse_args(
					$result,
					array(
						'slug'           => '',
						'label'          => '',
						'category'       => '',
						'category_label' => '',
						'duration_ms'    => 0,
						'operations'     => 0,
						'ops_per_second' => null,
						'status'         => 'unavailable',
						'optional'       => false,
						'error_message'  => '',
						'metric_label'   => '',
						'metric_value'   => null,
						'metric_unit'    => '',
						'details'        => array(),
						'scoring'        => array(),
					)
				);
			}
		}

		return $normalized;
	}

	/**
	 * Normalize stored recommendations.
	 *
	 * @param mixed $recommendations Raw recommendations payload.
	 * @return array
	 */
	protected function normalize_recommendations( $recommendations ) {
		if ( ! is_array( $recommendations ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $recommendations as $recommendation ) {
			if ( is_scalar( $recommendation ) ) {
				$normalized[] = sanitize_text_field( (string) $recommendation );
			}
		}

		return $normalized;
	}

	/**
	 * Normalize category score rows.
	 *
	 * @param mixed $categories Raw category score payload.
	 * @return array
	 */
	protected function normalize_categories( $categories ) {
		if ( ! is_array( $categories ) ) {
			return array();
		}

		$normalized = array();

		foreach ( $categories as $key => $category ) {
			if ( ! is_array( $category ) ) {
				continue;
			}

			$normalized[ sanitize_key( (string) $key ) ] = wp_parse_args(
				$category,
				array(
					'label'       => '',
					'weight'      => 0,
					'score'       => null,
					'test_scores' => array(),
					'test_count'  => 0,
				)
			);
		}

		return $normalized;
	}
}
