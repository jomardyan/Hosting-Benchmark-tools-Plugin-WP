<?php
/**
 * Side-by-side benchmark run comparison page.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Admin;

use WPHostingBenchmark\Storage;

defined( 'ABSPATH' ) || exit;

class Compare_Page {
	/**
	 * Required capability.
	 */
	const CAPABILITY = 'manage_options';

	/**
	 * Page slug.
	 */
	const PAGE_SLUG = 'wp-hosting-benchmark-compare';

	/**
	 * Result storage.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 *
	 * @param Storage $storage Result storage.
	 */
	public function __construct( Storage $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	/**
	 * Add the Compare submenu.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_submenu_page(
			'wp-hosting-benchmark',
			__( 'Compare benchmark runs', 'wp-hosting-benchmark' ),
			__( 'Compare runs', 'wp-hosting-benchmark' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the comparison view.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-hosting-benchmark' ) );
		}

		$history = $this->storage->get_history( 200 );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Compare benchmark runs', 'wp-hosting-benchmark' ) . '</h1>';

		if ( count( $history ) < 2 ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'You need at least two stored benchmark runs to use the comparison view.', 'wp-hosting-benchmark' ) . '</p></div>';
			echo '</div>';
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selectors; comparison data is not state-changing.
		$run_a_id = isset( $_GET['run_a'] ) ? sanitize_text_field( wp_unslash( $_GET['run_a'] ) ) : $history[0]['id'];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only selectors; comparison data is not state-changing.
		$run_b_id = isset( $_GET['run_b'] ) ? sanitize_text_field( wp_unslash( $_GET['run_b'] ) ) : ( isset( $history[1]['id'] ) ? $history[1]['id'] : '' );

		echo '<form method="get" action="">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<p>';
		echo '<label>' . esc_html__( 'Run A', 'wp-hosting-benchmark' ) . ' ';
		$this->render_run_select( 'run_a', $history, $run_a_id );
		echo '</label> ';
		echo '<label>' . esc_html__( 'Run B', 'wp-hosting-benchmark' ) . ' ';
		$this->render_run_select( 'run_b', $history, $run_b_id );
		echo '</label> ';
		submit_button( __( 'Compare', 'wp-hosting-benchmark' ), 'secondary', '', false );
		echo '</p>';
		echo '</form>';

		$run_a = $this->storage->get_run( $run_a_id );
		$run_b = $this->storage->get_run( $run_b_id );

		if ( ! $run_a || ! $run_b ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'One of the selected runs could not be loaded. Please pick valid runs from the dropdowns above.', 'wp-hosting-benchmark' ) . '</p></div>';
			echo '</div>';
			return;
		}

		if ( $run_a['id'] === $run_b['id'] ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Please pick two different runs to compare.', 'wp-hosting-benchmark' ) . '</p></div>';
		}

		$this->render_summary_table( $run_a, $run_b );
		$this->render_results_table( $run_a, $run_b );

		echo '</div>';
	}

	/**
	 * Render a select element listing benchmark runs.
	 *
	 * @param string $name     Field name.
	 * @param array  $history  History array.
	 * @param string $selected Selected ID.
	 * @return void
	 */
	protected function render_run_select( $name, array $history, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( $history as $run ) {
			$intensity = isset( $run['intensity'] ) ? (string) $run['intensity'] : 'standard';
			$score     = isset( $run['scores']['overall'] ) ? (int) $run['scores']['overall'] : 0;
			$label     = sprintf(
				/* translators: 1: created at, 2: intensity, 3: score */
				__( '%1$s — %2$s (score %3$d)', 'wp-hosting-benchmark' ),
				$run['created_at'],
				ucfirst( $intensity ),
				$score
			);
			echo '<option value="' . esc_attr( $run['id'] ) . '" ' . selected( $selected, $run['id'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Render the summary comparison table.
	 *
	 * @param array $run_a Run A.
	 * @param array $run_b Run B.
	 * @return void
	 */
	protected function render_summary_table( array $run_a, array $run_b ) {
		echo '<h2>' . esc_html__( 'Summary', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Metric', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Run A', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Run B', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Difference (B − A)', 'wp-hosting-benchmark' ) . '</th>';
		echo '</tr></thead><tbody>';

		$rows = array(
			__( 'Recorded at', 'wp-hosting-benchmark' )   => array( $run_a['created_at'], $run_b['created_at'], null ),
			__( 'Intensity', 'wp-hosting-benchmark' )     => array( ucfirst( (string) $run_a['intensity'] ), ucfirst( (string) $run_b['intensity'] ), null ),
			__( 'Overall score', 'wp-hosting-benchmark' ) => array( (int) $run_a['scores']['overall'], (int) $run_b['scores']['overall'], 'int' ),
			__( 'Confidence (%)', 'wp-hosting-benchmark' ) => array( (int) $run_a['scores']['confidence'], (int) $run_b['scores']['confidence'], 'int' ),
			__( 'Total runtime (ms)', 'wp-hosting-benchmark' ) => array( (float) $run_a['total_duration'], (float) $run_b['total_duration'], 'float' ),
		);

		foreach ( $rows as $label => $values ) {
			list( $a, $b, $type ) = $values;
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			echo '<td>' . esc_html( (string) $a ) . '</td>';
			echo '<td>' . esc_html( (string) $b ) . '</td>';
			if ( null === $type ) {
				echo '<td>—</td>';
			} else {
				$diff = ( 'int' === $type ) ? (int) $b - (int) $a : (float) $b - (float) $a;
				echo '<td>' . esc_html( ( $diff >= 0 ? '+' : '' ) . ( 'int' === $type ? (string) $diff : number_format_i18n( $diff, 2 ) ) ) . '</td>';
			}
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the per-test comparison table.
	 *
	 * @param array $run_a Run A.
	 * @param array $run_b Run B.
	 * @return void
	 */
	protected function render_results_table( array $run_a, array $run_b ) {
		$by_slug = array();

		foreach ( (array) $run_a['results'] as $result ) {
			if ( isset( $result['slug'] ) ) {
				$by_slug[ $result['slug'] ]['a'] = $result;
			}
		}

		foreach ( (array) $run_b['results'] as $result ) {
			if ( isset( $result['slug'] ) ) {
				$by_slug[ $result['slug'] ]['b'] = $result;
			}
		}

		echo '<h2>' . esc_html__( 'Per-test results', 'wp-hosting-benchmark' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Test', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Run A status', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Run A value', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Run B status', 'wp-hosting-benchmark' ) . '</th>';
		echo '<th>' . esc_html__( 'Run B value', 'wp-hosting-benchmark' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $by_slug as $slug => $pair ) {
			$a = isset( $pair['a'] ) ? $pair['a'] : null;
			$b = isset( $pair['b'] ) ? $pair['b'] : null;
			$label = $a && isset( $a['label'] ) ? $a['label'] : ( $b && isset( $b['label'] ) ? $b['label'] : $slug );

			echo '<tr>';
			echo '<td>' . esc_html( $label ) . '</td>';
			echo '<td>' . esc_html( $a ? (string) $a['status'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $this->format_value( $a ) ) . '</td>';
			echo '<td>' . esc_html( $b ? (string) $b['status'] : '—' ) . '</td>';
			echo '<td>' . esc_html( $this->format_value( $b ) ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Format a result's primary metric value.
	 *
	 * @param array|null $result Result.
	 * @return string
	 */
	protected function format_value( $result ) {
		if ( ! is_array( $result ) ) {
			return '—';
		}

		$value = isset( $result['metric_value'] ) ? $result['metric_value'] : null;
		$unit  = isset( $result['metric_unit'] ) ? (string) $result['metric_unit'] : '';

		if ( null === $value ) {
			return isset( $result['error_message'] ) && $result['error_message'] ? (string) $result['error_message'] : '—';
		}

		return rtrim( number_format_i18n( (float) $value, 2 ) . ' ' . $unit );
	}
}
