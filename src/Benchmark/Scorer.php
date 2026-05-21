<?php
/**
 * Converts raw benchmark metrics into transparent category and overall scores.
 *
 * @package WPHostingBenchmark
 */

namespace WPHostingBenchmark\Benchmark;

defined( 'ABSPATH' ) || exit;

class Scorer {
	/**
	 * Category metadata.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array(
			'php_cpu'    => array(
				'label'  => __( 'PHP and CPU', 'wp-hosting-benchmark' ),
				'weight' => 25,
			),
			'database'   => array(
				'label'  => __( 'Database', 'wp-hosting-benchmark' ),
				'weight' => 25,
			),
			'filesystem' => array(
				'label'  => __( 'Filesystem', 'wp-hosting-benchmark' ),
				'weight' => 10,
			),
			'cache'      => array(
				'label'  => __( 'Cache', 'wp-hosting-benchmark' ),
				'weight' => 10,
			),
			'network'    => array(
				'label'  => __( 'Network loopback', 'wp-hosting-benchmark' ),
				'weight' => 15,
			),
			'application' => array(
				'label'  => __( 'Real-world WordPress', 'wp-hosting-benchmark' ),
				'weight' => 15,
			),
		);
	}

	/**
	 * Get a category label.
	 *
	 * @param string $category Category key.
	 * @return string
	 */
	public function get_category_label( $category ) {
		$categories = $this->get_categories();

		return isset( $categories[ $category ]['label'] ) ? $categories[ $category ]['label'] : $category;
	}

	/**
	 * Score all benchmark results.
	 *
	 * The scoring model is intentionally simple and documented here so the admin
	 * report can explain it transparently:
	 * - each successful metric is mapped to 0-100 by linear interpolation between
	 *   a "poor" threshold and an "excellent" threshold;
	 * - metrics better than excellent are clamped to 100, and worse than poor are
	 *   clamped to 0;
	 * - category scores are the average of their available metric scores;
	 * - the overall score blends the category weighted average with a dedicated
	 *   total-runtime score so end-to-end request time materially impacts
	 *   comparisons between hosts.
	 *
	 * Confidence is tracked separately so optional or unavailable tests can lower
	 * the trust in the overall result without crashing the plugin.
	 *
	 * @param array       $results           Benchmark results.
	 * @param float|null  $total_duration_ms Total benchmark request duration in milliseconds.
	 * @param string      $intensity         Intensity level used for the run.
	 * @return array
	 */
	public function score_results( array $results, $total_duration_ms = null, $intensity = 'standard' ) {
		$categories          = $this->get_categories();
		$category_scores     = array();
		$planned_confidence  = 0.0;
		$earned_confidence   = 0.0;
		$total_weight        = 0.0;
		$weighted_total      = 0.0;

		foreach ( $categories as $category => $metadata ) {
			$category_scores[ $category ] = array(
				'label'       => $metadata['label'],
				'weight'      => $metadata['weight'],
				'score'       => null,
				'test_scores' => array(),
				'test_count'  => 0,
			);
		}

		foreach ( $results as $result ) {
			if ( empty( $result['category'] ) || ! isset( $category_scores[ $result['category'] ] ) ) {
				continue;
			}

			$confidence_weight = ! empty( $result['optional'] ) ? 0.75 : 1.0;
			$planned_confidence += $confidence_weight;
			++$category_scores[ $result['category'] ]['test_count'];

			if ( 'success' === $result['status'] && ! empty( $result['scoring'] ) ) {
				$score = $this->score_single_metric( $result['scoring'] );
				$category_scores[ $result['category'] ]['test_scores'][] = $score;
				$earned_confidence += $confidence_weight;
			} elseif ( 'failed' === $result['status'] ) {
				if ( empty( $result['optional'] ) ) {
					$category_scores[ $result['category'] ]['test_scores'][] = 0.0;
				}
				$earned_confidence += 0.25 * $confidence_weight;
			} else {
				$earned_confidence += ! empty( $result['optional'] ) ? 0.5 * $confidence_weight : 0.0;
			}
		}

		foreach ( $category_scores as $category => $data ) {
			if ( ! empty( $data['test_scores'] ) ) {
				$category_scores[ $category ]['score'] = round( array_sum( $data['test_scores'] ) / count( $data['test_scores'] ) );
				$weighted_total += $category_scores[ $category ]['score'] * $data['weight'];
				$total_weight   += $data['weight'];
			}
		}

		$base_overall         = $total_weight > 0 ? (int) round( $weighted_total / $total_weight ) : 0;
		$runtime_score        = $this->score_total_runtime( $total_duration_ms, $intensity );
		$runtime_weight       = 45;
		$category_weight      = 100 - $runtime_weight;
		$overall              = $base_overall;

		if ( null !== $runtime_score ) {
			$overall = (int) round( ( $base_overall * $category_weight + $runtime_score * $runtime_weight ) / 100 );
		}

		return array(
			'overall'         => $overall,
			'base_overall'    => $base_overall,
			'runtime_score'   => null === $runtime_score ? null : round( $runtime_score, 2 ),
			'runtime_weight'  => $runtime_weight,
			'confidence' => $planned_confidence > 0 ? (int) round( ( $earned_confidence / $planned_confidence ) * 100 ) : 100,
			'categories' => $category_scores,
		);
	}

	/**
	 * Score total runtime using intensity-aware thresholds.
	 *
	 * @param float|null $total_duration_ms Total duration in milliseconds.
	 * @param string     $intensity         Intensity key.
	 * @return float|null
	 */
	protected function score_total_runtime( $total_duration_ms, $intensity ) {
		if ( ! is_numeric( $total_duration_ms ) || (float) $total_duration_ms <= 0 ) {
			return null;
		}

		$thresholds = $this->get_runtime_thresholds( $intensity );

		return $this->score_single_metric(
			array(
				'value'            => (float) $total_duration_ms,
				'poor'             => (float) $thresholds['poor'],
				'excellent'        => (float) $thresholds['excellent'],
				'higher_is_better' => false,
			)
		);
	}

	/**
	 * Runtime thresholds by intensity profile.
	 *
	 * @param string $intensity Intensity key.
	 * @return array
	 */
	protected function get_runtime_thresholds( $intensity ) {
		$thresholds = array(
			'low'      => array(
				'excellent' => 350,
				'poor'      => 8000,
			),
			'standard' => array(
				'excellent' => 600,
				'poor'      => 12000,
			),
			'high'     => array(
				'excellent' => 900,
				'poor'      => 20000,
			),
		);

		$intensity = sanitize_key( (string) $intensity );

		return isset( $thresholds[ $intensity ] ) ? $thresholds[ $intensity ] : $thresholds['standard'];
	}

	/**
	 * Score one metric descriptor.
	 *
	 * @param array $metric Metric descriptor.
	 * @return float
	 */
	protected function score_single_metric( array $metric ) {
		$value             = isset( $metric['value'] ) ? (float) $metric['value'] : 0.0;
		$poor              = isset( $metric['poor'] ) ? (float) $metric['poor'] : 0.0;
		$excellent         = isset( $metric['excellent'] ) ? (float) $metric['excellent'] : 0.0;
		$higher_is_better  = ! empty( $metric['higher_is_better'] );

		if ( $poor === $excellent ) {
			return 100.0;
		}

		if ( $higher_is_better ) {
			if ( $value <= $poor ) {
				return 0.0;
			}

			if ( $value >= $excellent ) {
				return 100.0;
			}

			return ( ( $value - $poor ) / ( $excellent - $poor ) ) * 100;
		}

		if ( $value >= $poor ) {
			return 0.0;
		}

		if ( $value <= $excellent ) {
			return 100.0;
		}

		return ( ( $poor - $value ) / ( $poor - $excellent ) ) * 100;
	}
}