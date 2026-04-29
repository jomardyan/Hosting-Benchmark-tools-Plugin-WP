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
	 * - the overall score is the weighted average of the category scores.
	 *
	 * Confidence is tracked separately so optional or unavailable tests can lower
	 * the trust in the overall result without crashing the plugin.
	 *
	 * @param array $results Benchmark results.
	 * @return array
	 */
	public function score_results( array $results ) {
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

		return array(
			'overall'    => $total_weight > 0 ? (int) round( $weighted_total / $total_weight ) : 0,
			'confidence' => $planned_confidence > 0 ? (int) round( ( $earned_confidence / $planned_confidence ) * 100 ) : 100,
			'categories' => $category_scores,
		);
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