<?php
/**
 * The match model.
 *
 * Everything here is pure PHP on purpose: no WordPress calls, no database, no
 * HTTP. That makes the ranking testable outside a full WordPress boot, which is
 * where the interesting bugs live.
 *
 * The model is intentionally boring: each answer produces a small target map
 * (attribute value => how much we want it), and a product scores by how close
 * its attributes sit to that map. No magic weights hidden in a prompt.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * Answer schema and scoring.
 */
final class Scoring {

	/**
	 * Dimension => relative importance. Motion only enters the score when the
	 * shopper shares a bed, so a solo sleeper is not upsold a motion-isolation bed.
	 */
	const WEIGHTS = array(
		'firmness' => 1.0,
		'support'  => 1.0,
		'cooling'  => 0.8,
		'motion'   => 0.6,
	);

	/**
	 * Ordered feel ladders. Distance on the ladder becomes partial credit.
	 */
	const LADDERS = array(
		'firmness' => array( 'soft', 'medium', 'medium-firm', 'firm' ),
		'support'  => array( 'pressure-relief', 'balanced', 'firm-support' ),
		'cooling'  => array( 'low', 'medium', 'high' ),
		'motion'   => array( 'low', 'medium', 'high' ),
	);

	/**
	 * Merchant spellings that should resolve to the same attribute value.
	 */
	const ALIASES = array(
		'extra-firm'     => 'firm',
		'extra-soft'     => 'soft',
		'plush'          => 'soft',
		'medium-plush'   => 'medium',
		'medium-soft'    => 'medium',
		'medium-firm'    => 'medium-firm',
		'firm-support'   => 'firm-support',
		'pressure-relief' => 'pressure-relief',
		'pressurerelief' => 'pressure-relief',
		'high'           => 'high',
		'yes'            => 'high',
		'low'            => 'low',
		'no'             => 'low',
	);

	/**
	 * The four questions, with their copy in one place so it stays translatable.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function questions() {
		return array(
			array(
				'id'       => 'position',
				'legend'   => __( 'How do you sleep?', 'sleepfit' ),
				'help'     => __( 'Pick the position you land in most nights.', 'sleepfit' ),
				'options'  => array(
					'side'        => __( 'On my side', 'sleepfit' ),
					'back'        => __( 'On my back', 'sleepfit' ),
					'stomach'     => __( 'On my stomach', 'sleepfit' ),
					'combination' => __( 'I move around', 'sleepfit' ),
				),
			),
			array(
				'id'       => 'firmness',
				'legend'   => __( 'How firm do you like a bed?', 'sleepfit' ),
				'help'     => __( 'There is no wrong answer, only a preference.', 'sleepfit' ),
				'options'  => array(
					'soft'        => __( 'Soft, I like to sink in', 'sleepfit' ),
					'medium'      => __( 'Medium', 'sleepfit' ),
					'medium-firm' => __( 'Medium firm', 'sleepfit' ),
					'firm'        => __( 'Firm', 'sleepfit' ),
					'unsure'      => __( 'Not sure yet', 'sleepfit' ),
				),
			),
			array(
				'id'       => 'heat',
				'legend'   => __( 'How do you sleep, temperature-wise?', 'sleepfit' ),
				'help'     => __( 'Think about the middle of the night, not when you get in.', 'sleepfit' ),
				'options'  => array(
					'hot'     => __( 'I run hot', 'sleepfit' ),
					'neutral' => __( 'About average', 'sleepfit' ),
					'cold'    => __( 'I get cold', 'sleepfit' ),
				),
			),
			array(
				'id'       => 'partner',
				'legend'   => __( 'Who shares the bed?', 'sleepfit' ),
				'help'     => __( 'This decides how much motion isolation matters.', 'sleepfit' ),
				'options'  => array(
					'alone'  => __( 'Just me', 'sleepfit' ),
					'shares' => __( 'A partner', 'sleepfit' ),
					'kids'   => __( 'Kids or pets too', 'sleepfit' ),
				),
			),
		);
	}

	/**
	 * Validate raw input against the schema. Returns an empty array if anything
	 * is missing or unrecognised, so callers can fail closed.
	 *
	 * @param array<string, mixed> $raw Raw answers.
	 * @return array<string, string>
	 */
	public static function sanitize_answers( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( self::questions() as $question ) {
			$id = $question['id'];

			if ( ! isset( $raw[ $id ] ) || ! is_scalar( $raw[ $id ] ) ) {
				return array();
			}

			$value = strtolower( trim( (string) $raw[ $id ] ) );

			if ( ! array_key_exists( $value, $question['options'] ) ) {
				return array();
			}

			$clean[ $id ] = $value;
		}

		return $clean;
	}

	/**
	 * Turn answers into attribute targets.
	 *
	 * @param array<string, string> $answers Sanitised answers.
	 * @return array<string, array<string, float>>
	 */
	public static function profile( $answers ) {
		$targets = array();

		/**
		 * Record a target, keeping the strongest reason for it.
		 *
		 * @param string $dimension Dimension key.
		 * @param string $value     Attribute value slug.
		 * @param float  $weight    Zero to one.
		 */
		$want = function ( $dimension, $value, $weight ) use ( &$targets ) {
			if ( ! isset( $targets[ $dimension ][ $value ] ) ) {
				$targets[ $dimension ][ $value ] = 0.0;
			}

			$targets[ $dimension ][ $value ] = max( $targets[ $dimension ][ $value ], $weight );
		};

		$position = isset( $answers['position'] ) ? $answers['position'] : '';

		switch ( $position ) {
			case 'side':
				$want( 'support', 'pressure-relief', 1.0 );
				$want( 'support', 'balanced', 0.35 );
				$want( 'firmness', 'medium', 0.5 );
				$want( 'firmness', 'medium-firm', 0.5 );
				break;

			case 'back':
				$want( 'support', 'balanced', 1.0 );
				$want( 'support', 'firm-support', 0.4 );
				$want( 'firmness', 'medium-firm', 0.8 );
				$want( 'firmness', 'medium', 0.4 );
				break;

			case 'stomach':
				$want( 'support', 'firm-support', 1.0 );
				$want( 'firmness', 'firm', 0.85 );
				$want( 'firmness', 'medium-firm', 0.4 );
				break;

			case 'combination':
				$want( 'support', 'balanced', 1.0 );
				$want( 'support', 'pressure-relief', 0.5 );
				$want( 'firmness', 'medium', 0.7 );
				$want( 'firmness', 'medium-firm', 0.5 );
				break;
		}

		$firmness = isset( $answers['firmness'] ) ? $answers['firmness'] : '';

		if ( isset( self::LADDERS['firmness'] ) && 'unsure' !== $firmness && '' !== $firmness ) {
			$ladder = self::LADDERS['firmness'];
			$index  = array_search( $firmness, $ladder, true );

			if ( false !== $index ) {
				$want( 'firmness', $firmness, 1.0 );

				if ( isset( $ladder[ $index - 1 ] ) ) {
					$want( 'firmness', $ladder[ $index - 1 ], 0.45 );
				}

				if ( isset( $ladder[ $index + 1 ] ) ) {
					$want( 'firmness', $ladder[ $index + 1 ], 0.45 );
				}
			}
		}

		$heat = isset( $answers['heat'] ) ? $answers['heat'] : '';

		switch ( $heat ) {
			case 'hot':
				$want( 'cooling', 'high', 1.0 );
				$want( 'cooling', 'medium', 0.45 );
				break;

			case 'neutral':
				$want( 'cooling', 'medium', 0.8 );
				$want( 'cooling', 'high', 0.4 );
				break;

			case 'cold':
				$want( 'cooling', 'low', 0.7 );
				$want( 'cooling', 'medium', 0.5 );
				break;
		}

		$partner = isset( $answers['partner'] ) ? $answers['partner'] : '';

		switch ( $partner ) {
			case 'shares':
				$want( 'motion', 'high', 1.0 );
				$want( 'motion', 'medium', 0.45 );
				break;

			case 'kids':
				$want( 'motion', 'high', 0.8 );
				$want( 'motion', 'medium', 0.5 );
				break;

			case 'alone':
				// Motion isolation is not a purchase reason for a solo sleeper,
				// so the dimension is left out and the score renormalises.
				break;
		}

		return $targets;
	}

	/**
	 * Score one product.
	 *
	 * @param array<string, array<string, float>> $profile    Targets from profile().
	 * @param array<string, string>               $attributes Product attributes, dimension => value.
	 * @return array{score:int, hits:array<int, string>}
	 */
	public static function score( $profile, $attributes ) {
		$earned = 0.0;
		$total  = 0.0;
		$hits   = array();

		foreach ( $profile as $dimension => $targets ) {
			$weight = isset( self::WEIGHTS[ $dimension ] ) ? self::WEIGHTS[ $dimension ] : 0.5;
			$total += $weight;

			$actual = isset( $attributes[ $dimension ] ) ? $attributes[ $dimension ] : '';

			if ( '' === $actual ) {
				// Missing attribute data should not beat a bed that actually
				// declares its spec, but it should not be scored as a total miss
				// either, or a half-filled catalogue returns nothing.
				$earned += $weight * 0.5;
				continue;
			}

			$affinity = isset( $targets[ $actual ] ) ? (float) $targets[ $actual ] : 0.0;
			$earned  += $weight * $affinity;

			if ( $affinity >= 0.8 ) {
				$hits[] = $dimension;
			}
		}

		$score = $total > 0 ? (int) round( 100 * $earned / $total ) : 0;

		return array(
			'score' => max( 0, min( 100, $score ) ),
			'hits'  => $hits,
		);
	}

	/**
	 * Human-readable band for a score.
	 *
	 * @param int $score Zero to one hundred.
	 * @return string
	 */
	public static function band( $score ) {
		if ( $score >= 85 ) {
			return __( 'Strong match', 'sleepfit' );
		}

		if ( $score >= 65 ) {
			return __( 'Good match', 'sleepfit' );
		}

		return __( 'Worth a look', 'sleepfit' );
	}

	/**
	 * Plain-language reasons, drawn from the same data that produced the score.
	 * Capped at three so the result card stays scannable.
	 *
	 * @param array<string, string> $answers    Sanitised answers.
	 * @param array<string, string> $attributes Product attributes.
	 * @return array<int, string>
	 */
	public static function reasons( $answers, $attributes ) {
		$reasons = array();

		$position = isset( $answers['position'] ) ? $answers['position'] : '';
		$support  = isset( $attributes['support'] ) ? $attributes['support'] : '';

		if ( 'side' === $position && 'pressure-relief' === $support ) {
			$reasons[] = __( 'Softer give at the shoulder and hip, where side sleepers carry their weight.', 'sleepfit' );
		} elseif ( 'back' === $position && 'balanced' === $support ) {
			$reasons[] = __( 'Balanced support that keeps the spine level when you sleep on your back.', 'sleepfit' );
		} elseif ( 'stomach' === $position && 'firm-support' === $support ) {
			$reasons[] = __( 'Firmer support so the hips stay level when you sleep on your stomach.', 'sleepfit' );
		} elseif ( 'combination' === $position && 'balanced' === $support ) {
			$reasons[] = __( 'Balanced support for sleepers who change position through the night.', 'sleepfit' );
		}

		$heat    = isset( $answers['heat'] ) ? $answers['heat'] : '';
		$cooling = isset( $attributes['cooling'] ) ? $attributes['cooling'] : '';

		if ( 'hot' === $heat && 'high' === $cooling ) {
			$reasons[] = __( 'A cooler surface, for sleepers who run hot in the middle of the night.', 'sleepfit' );
		}

		$partner = isset( $answers['partner'] ) ? $answers['partner'] : '';
		$motion  = isset( $attributes['motion'] ) ? $attributes['motion'] : '';

		if ( in_array( $partner, array( 'shares', 'kids' ), true ) && 'high' === $motion ) {
			$reasons[] = __( 'Movement stays on your side of the bed, so a partner rolling over is less of a wake-up.', 'sleepfit' );
		}

		$firmness = isset( $answers['firmness'] ) ? $answers['firmness'] : '';
		$actual   = isset( $attributes['firmness'] ) ? $attributes['firmness'] : '';

		if ( '' !== $firmness && 'unsure' !== $firmness && $firmness === $actual ) {
			$labels    = array(
				'soft'        => __( 'soft', 'sleepfit' ),
				'medium'      => __( 'medium', 'sleepfit' ),
				'medium-firm' => __( 'medium firm', 'sleepfit' ),
				'firm'        => __( 'firm', 'sleepfit' ),
			);
			$reasons[] = sprintf(
				/* translators: %s: firmness label, e.g. "medium firm". */
				__( 'The %s feel you asked for.', 'sleepfit' ),
				isset( $labels[ $firmness ] ) ? $labels[ $firmness ] : $firmness
			);
		}

		if ( empty( $reasons ) ) {
			$reasons[] = __( 'One of the closest fits in the range for how you sleep.', 'sleepfit' );
		}

		return array_slice( array_values( array_unique( $reasons ) ), 0, 3 );
	}

	/**
	 * Normalise a merchant attribute value to a ladder slug.
	 *
	 * Handles taxonomy slugs (medium-firm), labels (Medium Firm) and common
	 * spellings from real catalogues (Plush, Extra Firm).
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function normalize_value( $value ) {
		$value = strtolower( trim( (string) $value ) );
		$value = str_replace( array( '_', ' ' ), '-', $value );
		$value = preg_replace( '/[^a-z0-9\-]/', '', $value );
		$value = preg_replace( '/-+/', '-', (string) $value );

		return isset( self::ALIASES[ $value ] ) ? self::ALIASES[ $value ] : $value;
	}
}
