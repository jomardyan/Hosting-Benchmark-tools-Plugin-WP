<?php
/** Copyright (c) 2026 Hayk Jomardyan - Lolisoft. GPL-2.0-or-later. */
if ( ! defined( 'ABSPATH' ) || count( $args ) !== 3 ) { throw new RuntimeException( 'Use wp eval-file FILE first.php second.php FILE_CONSTANT.' ); }
global $wp_filter;
$fixture_snapshot = static function () use ( &$wp_filter ) {
    $result = array();
    foreach ( $wp_filter as $name => $hook ) { $result[$name] = array_sum( array_map( 'count', $hook->callbacks ) ); }
    ksort( $result );
    return $result;
};
require $args[0];
$fixture_before = $fixture_snapshot();
$fixture_file = constant( $args[2] );
require $args[1];
require $args[0];
if ( $fixture_before !== $fixture_snapshot() || realpath( $fixture_file ) !== realpath( $args[0] ) || $fixture_file !== constant( $args[2] ) ) {
    throw new RuntimeException( 'Duplicate edition changed hook registrations or the first bootstrap identity.' );
}
echo "Both editions and repeated includes preserve the first bootstrap identity and hook counts.\n";
