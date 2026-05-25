<?php
/**
 * Plugin settings admin page.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Admin;

use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Settings_Page {
	/**
	 * Required capability.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'wp-hosting-benchmark-settings';

	/**
	 * Settings group name.
	 */
	const OPTION_GROUP = 'wp_hosting_benchmark_settings_group';

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add the Settings submenu under the Hosting Benchmark menu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'wp-hosting-benchmark',
			__( 'Hosting Benchmark Settings', 'wp-hosting-benchmark' ),
			__( 'Settings', 'wp-hosting-benchmark' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the Settings API option.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			Storage::SETTINGS_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => Storage::get_default_settings(),
			)
		);
	}

	/**
	 * Sanitize the settings array submitted from the form.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$defaults = Storage::get_default_settings();
		$input    = is_array( $input ) ? $input : array();

		$intensity = isset( $input['default_intensity'] ) ? sanitize_key( $input['default_intensity'] ) : $defaults['default_intensity'];
		$schedule  = isset( $input['schedule'] ) ? sanitize_key( $input['schedule'] ) : $defaults['schedule'];

		$disabled_raw = isset( $input['disabled_tests'] ) ? (array) $input['disabled_tests'] : array();
		$disabled     = array();
		foreach ( $disabled_raw as $slug ) {
			$slug = sanitize_key( $slug );
			if ( '' !== $slug ) {
				$disabled[] = $slug;
			}
		}

		$history_limit = isset( $input['history_limit'] ) ? (int) $input['history_limit'] : $defaults['history_limit'];

		return array(
			'default_intensity' => in_array( $intensity, array( 'low', 'standard', 'high' ), true ) ? $intensity : 'standard',
			'history_limit'     => max( 1, min( 200, $history_limit ) ),
			'disabled_tests'    => array_values( array_unique( $disabled ) ),
			'schedule'          => in_array( $schedule, array( 'disabled', 'daily', 'weekly' ), true ) ? $schedule : 'disabled',
		);
	}

	/**
	 * Render the settings screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-hosting-benchmark' ) );
		}

		$settings = Storage::get_settings();
		$plugin   = wp_hosting_benchmark();
		$tests    = $plugin && method_exists( $plugin, 'get_runner' ) ? $plugin->get_runner()->get_all_tests() : array();

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Hosting Benchmark Settings', 'wp-hosting-benchmark' ) . '</h1>';
		echo '<p>' . esc_html__( 'Configure the default benchmark behaviour, retention, scheduled runs, and which tests should be included.', 'wp-hosting-benchmark' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::OPTION_GROUP );

		echo '<h2>' . esc_html__( 'Defaults', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		// Default intensity.
		echo '<tr><th scope="row"><label for="wp_hosting_benchmark_default_intensity">' . esc_html__( 'Default intensity', 'wp-hosting-benchmark' ) . '</label></th><td>';
		echo '<select id="wp_hosting_benchmark_default_intensity" name="' . esc_attr( Storage::SETTINGS_OPTION ) . '[default_intensity]">';
		foreach ( array(
			'low'      => __( 'Low — quick health check', 'wp-hosting-benchmark' ),
			'standard' => __( 'Standard — routine benchmarking (recommended)', 'wp-hosting-benchmark' ),
			'high'     => __( 'High — denser sample, longer admin request', 'wp-hosting-benchmark' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['default_intensity'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Used as the pre-selected intensity on the dashboard and for scheduled benchmark runs.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</td></tr>';

		// History retention.
		echo '<tr><th scope="row"><label for="wp_hosting_benchmark_history_limit">' . esc_html__( 'History retention', 'wp-hosting-benchmark' ) . '</label></th><td>';
		echo '<input type="number" min="1" max="200" step="1" id="wp_hosting_benchmark_history_limit" name="' . esc_attr( Storage::SETTINGS_OPTION ) . '[history_limit]" value="' . esc_attr( (int) $settings['history_limit'] ) . '" class="small-text" /> ';
		echo esc_html__( 'runs', 'wp-hosting-benchmark' );
		echo '<p class="description">' . esc_html__( 'How many benchmark runs are kept in history. Older runs are dropped automatically. Allowed range: 1–200.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</td></tr>';

		// Schedule.
		echo '<tr><th scope="row"><label for="wp_hosting_benchmark_schedule">' . esc_html__( 'Scheduled benchmarks', 'wp-hosting-benchmark' ) . '</label></th><td>';
		echo '<select id="wp_hosting_benchmark_schedule" name="' . esc_attr( Storage::SETTINGS_OPTION ) . '[schedule]">';
		foreach ( array(
			'disabled' => __( 'Disabled (run manually)', 'wp-hosting-benchmark' ),
			'daily'    => __( 'Daily (via WP-Cron)', 'wp-hosting-benchmark' ),
			'weekly'   => __( 'Weekly (via WP-Cron)', 'wp-hosting-benchmark' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $settings['schedule'], $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__( 'Scheduled runs use the default intensity setting and rely on WP-Cron. They are skipped if a run is already in progress.', 'wp-hosting-benchmark' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';

		// Disabled tests.
		echo '<h2>' . esc_html__( 'Active tests', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<p>' . esc_html__( 'Uncheck any benchmark you want to exclude. Disabling a test reduces runtime but may lower the confidence of category and overall scores.', 'wp-hosting-benchmark' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';

		$disabled = isset( $settings['disabled_tests'] ) ? (array) $settings['disabled_tests'] : array();

		foreach ( $tests as $test ) {
			$slug    = isset( $test['slug'] ) ? sanitize_key( $test['slug'] ) : '';
			$label   = isset( $test['label'] ) ? $test['label'] : $slug;
			$enabled = ! in_array( $slug, $disabled, true );

			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			// Submitting the inverse (slug only when disabled) keeps the option simple.
			echo '<label><input type="checkbox" name="' . esc_attr( Storage::SETTINGS_OPTION ) . '[disabled_tests][]" value="' . esc_attr( $slug ) . '" ' . checked( ! $enabled, true, false ) . ' /> ';
			echo esc_html__( 'Skip this test on every run', 'wp-hosting-benchmark' );
			echo '</label>';
			echo '</td></tr>';
		}

		echo '</tbody></table>';

		submit_button();
		echo '</form>';
		echo '</div>';
	}
}
