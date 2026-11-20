<?php
/**
 * The public match endpoint.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/sleepfit/v1/match
 */
final class Rest {

	/**
	 * REST namespace.
	 */
	const NS = 'sleepfit/v1';

	/**
	 * Requests allowed per IP per minute.
	 */
	const RATE_LIMIT = 20;

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			self::NS,
			'/match',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				// Public by design: the finder runs for logged-out shoppers. The
				// endpoint writes only aggregate counters, and a nonce would break
				// on cached pages. Input is whitelisted, so there is nothing to forge
				// beyond a match request the shopper could send anyway.
				'permission_callback' => '__return_true',
				'args'                => array(
					'answers' => array(
						'required' => true,
						'type'     => 'object',
					),
				),
			)
		);
	}

	/**
	 * Sanitise, rank, respond.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$answers = Scoring::sanitize_answers( $request->get_param( 'answers' ) );

		if ( empty( $answers ) ) {
			return new \WP_Error(
				'sleepfit_invalid_answers',
				__( 'Please answer every question.', 'sleepfit' ),
				array( 'status' => 400 )
			);
		}

		if ( ! self::within_rate_limit() ) {
			$error = new \WP_Error(
				'sleepfit_rate_limited',
				__( 'Too many tries. Give it a minute.', 'sleepfit' ),
				array( 'status' => 429 )
			);

			return $error;
		}

		$matches = Matcher::match( $answers );

		if ( ! empty( $matches ) ) {
			Events::record( $answers, $matches[0]['id'], $matches[0]['score'] );
		}

		return rest_ensure_response(
			array(
				'matches' => $matches,
				'total'   => count( $matches ),
			)
		);
	}

	/**
	 * Tiny fixed-window limiter. Good enough to stop a loop from filling the
	 * events table; not a substitute for edge rate limiting.
	 *
	 * @return bool
	 */
	private static function within_rate_limit() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return true;
		}

		$key   = 'sleepfit_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return true;
	}
}
