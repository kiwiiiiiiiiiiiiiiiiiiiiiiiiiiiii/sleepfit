<?php
/**
 * Optional explanation layer.
 *
 * The plugin works with no API key. When one is present, the top match gets one
 * short line written by a chat-completions provider. The rules that keep this
 * from becoming a liability:
 *
 * - one call per request, top result only;
 * - six second timeout, zero redirects, https only;
 * - cache keyed on answers + product + model + prompt version, 30 days;
 * - hard fallback to the deterministic reasons;
 * - output stripped, length-capped and checked for medical claims.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-agnostic chat completions client.
 */
final class LLM {

	/**
	 * Bump when the prompt or output rules change, so old cache entries are ignored.
	 */
	const PROMPT_VERSION = '1';

	/**
	 * Cache lifetime.
	 */
	const CACHE_TTL = 2592000; // 30 days.

	/**
	 * Is a key available?
	 *
	 * @return bool
	 */
	public static function configured() {
		return '' !== self::api_key();
	}

	/**
	 * Key from wp-config wins over the database. A constant keeps the key out of
	 * exports and database dumps, which is where keys usually leak.
	 *
	 * @return string
	 */
	public static function api_key() {
		if ( defined( 'SLEEPFIT_AI_KEY' ) && SLEEPFIT_AI_KEY ) {
			return (string) SLEEPFIT_AI_KEY;
		}

		$settings = Matcher::settings();

		return isset( $settings['ai_key'] ) ? trim( (string) $settings['ai_key'] ) : '';
	}

	/**
	 * Generate a short explanation, or return an empty string.
	 *
	 * @param array<string, string> $answers Sanitised answers.
	 * @param \WC_Product           $product Matched product.
	 * @param array<int, string>    $reasons Deterministic reasons.
	 * @return string
	 */
	public static function explain( $answers, $product, $reasons ) {
		$settings = Matcher::settings();

		if ( empty( $settings['ai_enabled'] ) || ! self::configured() ) {
			return '';
		}

		/**
		 * Short-circuit the model call. The no-JavaScript fallback uses this to
		 * keep a server-rendered page fast instead of waiting on a provider.
		 *
		 * @param bool $enabled Whether to call the provider.
		 */
		if ( ! apply_filters( 'sleepfit_llm_enabled', true ) ) {
			return '';
		}

		$base_url = self::base_url();

		if ( '' === $base_url ) {
			return '';
		}

		$cache_key = 'sleepfit_ai_' . md5(
			wp_json_encode(
				array(
					'answers'  => $answers,
					'product'  => $product->get_id(),
					'model'    => $settings['ai_model'],
					'prompt'   => self::PROMPT_VERSION,
					'brand'    => get_bloginfo( 'name' ),
				)
			)
		);

		$cached = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$body = array(
			'model'       => (string) $settings['ai_model'],
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => 'You write concise product copy for a sleep products store. Use only the facts supplied. Never make medical claims, never promise pain relief or a cure, and never invent specifications. Reply with at most two sentences and no more than 45 words. Plain, warm, specific.',
				),
				array(
					'role'    => 'user',
					'content' => self::prompt( $answers, $product, $reasons ),
				),
			),
			'temperature' => 0.4,
			'max_tokens'  => 90,
		);

		/**
		 * Filter the request body, e.g. to add a provider-specific parameter.
		 *
		 * @param array<string, mixed> $body Request body.
		 */
		$body = apply_filters( 'sleepfit_llm_request', $body );

		$response = wp_remote_post(
			$base_url . '/chat/completions',
			array(
				'timeout'     => 6,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => array(
					'Authorization' => 'Bearer ' . self::api_key(),
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'data_format' => 'body',
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$text    = isset( $decoded['choices'][0]['message']['content'] ) ? (string) $decoded['choices'][0]['message']['content'] : '';

		$text = self::clean( $text );

		if ( '' === $text ) {
			return '';
		}

		set_transient( $cache_key, $text, self::CACHE_TTL );

		return $text;
	}

	/**
	 * Build the user prompt from the same facts the score used.
	 *
	 * @param array<string, string> $answers Answers.
	 * @param \WC_Product           $product Product.
	 * @param array<int, string>    $reasons Reasons.
	 * @return string
	 */
	private static function prompt( $answers, $product, $reasons ) {
		$labels = array(
			'position' => 'Sleep position',
			'firmness' => 'Firmness preference',
			'heat'     => 'Temperature',
			'partner'  => 'Bed sharing',
		);

		$lines = array();

		foreach ( $labels as $key => $label ) {
			if ( isset( $answers[ $key ] ) ) {
				$lines[] = $label . ': ' . $answers[ $key ];
			}
		}

		$summary = wp_strip_all_tags( (string) $product->get_short_description() );

		return sprintf(
			"Store: %s\nShopper answers:\n%s\nProduct: %s\nProduct summary: %s\nMatch notes: %s\n\nWrite the two-sentence reason this product suits this shopper. Do not start with \"This product\".",
			get_bloginfo( 'name' ),
			implode( "\n", $lines ),
			$product->get_name(),
			wp_html_excerpt( $summary, 400, '...' ),
			implode( ' ', (array) $reasons )
		);
	}

	/**
	 * Strip markup, keep two sentences, cap length, reject medical claims.
	 *
	 * @param string $text Raw model output.
	 * @return string
	 */
	public static function clean( $text ) {
		$text = trim( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/\s+/', ' ', $text );

		if ( '' === $text ) {
			return '';
		}

		$sentences = preg_split( '/(?<=[.!?])\s+/', $text, 3 );

		if ( is_array( $sentences ) ) {
			$text = implode( ' ', array_slice( $sentences, 0, 2 ) );
		}

		$text = wp_html_excerpt( $text, 240, '...' );

		if ( preg_match( '/\b(cure|cures|treat|treats|medical|diagnos\w*)\b/i', $text ) ) {
			return '';
		}

		return trim( $text );
	}

	/**
	 * Validate the configured base URL.
	 *
	 * @return string Base URL without a trailing slash, or an empty string.
	 */
	private static function base_url() {
		$settings = Matcher::settings();
		$url      = rtrim( (string) $settings['ai_base_url'], '/' );

		if ( ! wp_http_validate_url( $url ) ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );

		if ( 'https' !== $scheme && ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return '';
		}

		return $url;
	}
}
