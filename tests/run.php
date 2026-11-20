<?php
/**
 * Dependency-free tests for the scoring core.
 *
 * Run: php tests/run.php
 *
 * @package SleepFit
 */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation stub so the pure functions can be loaded outside WP.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	function __( $text ) {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-scoring.php';

use SleepFit\Scoring;

$passed = 0;
$failed = 0;

/**
 * Assert equal.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $label    Test name.
 */
function check( $expected, $actual, $label ) {
	global $passed, $failed;

	if ( $expected === $actual ) {
		++$passed;
		echo "  ok   {$label}\n";
		return;
	}

	++$failed;
	echo "  FAIL {$label}\n";
	echo '       expected: ' . var_export( $expected, true ) . "\n";
	echo '       actual:   ' . var_export( $actual, true ) . "\n";
}

/**
 * Assert that a comparison is true.
 *
 * @param bool   $condition Condition.
 * @param string $label     Test name.
 */
function ok( $condition, $label ) {
	check( true, (bool) $condition, $label );
}

$side_hot = array(
	'position' => 'side',
	'firmness' => 'unsure',
	'heat'     => 'hot',
	'partner'  => 'shares',
);

$stomach_cold = array(
	'position' => 'stomach',
	'firmness' => 'firm',
	'heat'     => 'cold',
	'partner'  => 'alone',
);

$cloudline = array(
	'firmness' => 'soft',
	'support'  => 'pressure-relief',
	'cooling'  => 'low',
	'motion'   => 'high',
);

$terra = array(
	'firmness' => 'firm',
	'support'  => 'firm-support',
	'cooling'  => 'medium',
	'motion'   => 'low',
);

echo "Profile construction\n";

$profile = Scoring::profile( $side_hot );

check( 1.0, $profile['support']['pressure-relief'], 'side sleeper wants pressure relief' );
check( 1.0, $profile['cooling']['high'], 'hot sleeper wants high cooling' );
check( 1.0, $profile['motion']['high'], 'shared bed wants motion isolation' );
ok( ! isset( $profile['firmness']['firm'] ), 'side sleeper is not pushed toward firm' );

$profile2 = Scoring::profile( $stomach_cold );

ok( ! isset( $profile2['motion'] ), 'solo sleeper omits the motion dimension' );
check( 0.7, $profile2['cooling']['low'], 'cold sleeper wants low cooling' );

echo "Ranking\n";

$side_cloudline = Scoring::score( $profile, $cloudline );
$side_terra     = Scoring::score( $profile, $terra );

ok( $side_cloudline['score'] > $side_terra['score'], 'side/hot/shared ranks Cloudline above Terra' );
ok( $side_cloudline['score'] >= 0 && $side_cloudline['score'] <= 100, 'score stays in range' );

$stomach_profile = Scoring::profile( $stomach_cold );
$stomach_terra   = Scoring::score( $stomach_profile, $terra );
$stomach_cloud   = Scoring::score( $stomach_profile, $cloudline );

ok( $stomach_terra['score'] > $stomach_cloud['score'], 'stomach/firm/cold ranks Terra above Cloudline' );
ok( in_array( 'firmness', $stomach_terra['hits'], true ), 'strong firmness hit is recorded' );

echo "Partial credit and missing data\n";

$firm_profile = Scoring::profile( array( 'firmness' => 'firm' ) );
check( 1.0, $firm_profile['firmness']['firm'], 'exact firmness gets full weight' );
check( 0.45, $firm_profile['firmness']['medium-firm'], 'adjacent firmness gets partial weight' );
ok( ! isset( $firm_profile['firmness']['soft'] ), 'distant firmness gets nothing' );

$partial = Scoring::score( Scoring::profile( $side_hot ), array( 'support' => 'pressure-relief' ) );
check( 65, $partial['score'], 'one perfect dimension plus three half-credits gives 65' );

$empty = Scoring::score( Scoring::profile( $side_hot ), array() );
check( 50, $empty['score'], 'a product with no attributes scores 50, not zero' );

echo "Normalisation and validation\n";

check( 'medium-firm', Scoring::normalize_value( 'Medium Firm' ), 'label becomes ladder slug' );
check( 'firm', Scoring::normalize_value( 'extra firm' ), 'alias resolves' );
check( 'soft', Scoring::normalize_value( 'Plush' ), 'plush resolves to soft' );
check( 'pressure-relief', Scoring::normalize_value( 'Pressure_Relief' ), 'underscores normalise' );

check( $side_hot, Scoring::sanitize_answers( $side_hot ), 'valid answers pass through' );
check( array(), Scoring::sanitize_answers( array_merge( $side_hot, array( 'heat' => 'freezing' ) ) ), 'unknown value is rejected' );
check( array(), Scoring::sanitize_answers( array( 'position' => 'side' ) ), 'incomplete answers are rejected' );
check( array(), Scoring::sanitize_answers( 'not an array' ), 'non-array input is rejected' );

echo "Reasons\n";

$reasons = Scoring::reasons( $side_hot, $cloudline );
ok( count( $reasons ) <= 3, 'at most three reasons' );
ok( count( $reasons ) === count( array_unique( $reasons ) ), 'reasons are unique' );
ok( count( Scoring::reasons( $side_hot, array() ) ) >= 1, 'fallback reason always exists' );

echo "\n{$passed} passed, {$failed} failed\n";

exit( $failed > 0 ? 1 : 0 );
