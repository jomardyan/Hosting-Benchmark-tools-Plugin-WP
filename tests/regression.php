<?php
// Deterministic scoring behavior without starting a live benchmark.
define( 'ABSPATH', __DIR__ . '/' );
function __( $message, $domain = '' ) { return $message; }
require dirname( __DIR__ ) . '/src/Benchmark/Scorer.php';
function check( $condition, $message ) {
    if ( ! $condition ) { throw new RuntimeException( $message ); }
}
$scorer = new WPHostingBenchmark\Benchmark\Scorer();
$metric = array( 'category' => 'php_cpu', 'status' => 'success', 'scoring' => array( 'value' => 50, 'poor' => 0, 'excellent' => 100, 'higher_is_better' => true ) );
$scores = $scorer->score_results( array( $metric ) );
check( 50 === $scores['overall'], 'A midpoint metric must receive the midpoint score.' );
check( 100 === $scores['confidence'], 'Successful metrics must have full confidence.' );
$metric['scoring']['value'] = 200;
check( 100 === $scorer->score_results( array( $metric ) )['overall'], 'Scores must be capped at 100.' );
$metric['status'] = 'failed';
check( 0 === $scorer->score_results( array( $metric ) )['overall'], 'Failed required tests must lower the score.' );
echo "4 benchmark scoring regressions passed.\n";
