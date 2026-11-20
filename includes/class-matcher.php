<?php
/**
 * Reads the catalogue and turns products into match payloads.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * Catalogue access and match assembly.
 */
final class Matcher {

	/**
	 * Defaults live here so the admin screen, the REST endpoint and the
	 * shortcode all read the same shape.
	 */
	const DEFAULTS = array(
		'attr_firmness'     => 'pa_firmness',
		'attr_support'      => 'pa_support',
		'attr_cooling'      => 'pa_cooling',
		'attr_motion'       => 'pa_motion',
		'category'          => '',
		'limit'             => 40,
		'events_enabled'    => 1,
		'ai_enabled'        => 0,
		'ai_base_url'       => 'https://api.openai.com/v1',
		'ai_model'          => 'gpt-4o-mini',
		'ai_key'            => '',
	);

	/**
	 * How many products the finder will show.
	 */
	const RESULT_COUNT = 2;

	/**
	 * Merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings() {
		$saved = get_option( 'sleepfit_settings', array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array_merge( self::DEFAULTS, $saved );
	}

	/**
	 * Build the ranked payload for a set of answers.
	 *
	 * @param array<string, mixed> $answers Raw answers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function match( $answers ) {
		$answers = Scoring::sanitize_answers( $answers );

		if ( empty( $answers ) ) {
			return array();
		}

		$profile    = Scoring::profile( $answers );
		$candidates = self::candidates();

		if ( empty( $candidates ) ) {
			return array();
		}

		$ranked = array();

		foreach ( $candidates as $index => $product ) {
			$attributes = self::attributes_for( $product );
			$result     = Scoring::score( $profile, $attributes );

			$ranked[] = array(
				'product'    => $product,
				'attributes' => $attributes,
				'score'      => $result['score'],
				'index'      => $index,
			);
		}

		usort(
			$ranked,
			function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['index'] <=> $b['index'];
				}

				return $b['score'] <=> $a['score'];
			}
		);

		$matches = array_slice( $ranked, 0, self::RESULT_COUNT );
		$payload = array();

		foreach ( $matches as $position => $row ) {
			$product = $row['product'];
			$why     = Scoring::reasons( $answers, $row['attributes'] );

			$blurb = '';

			// One AI call per request, on the top result only. Two calls doubled
			// latency for a line nobody read past anyway.
			if ( 0 === $position ) {
				$blurb = LLM::explain( $answers, $product, $why );
			}

			$payload[] = self::to_payload( $product, $row['attributes'], $row['score'], $why, $blurb );
		}

		/**
		 * Filter the final payload before it leaves the plugin.
		 *
		 * @param array<int, array<string, mixed>> $payload Matches.
		 * @param array<string, string>            $answers Sanitised answers.
		 */
		return apply_filters( 'sleepfit_matches', $payload, $answers );
	}

	/**
	 * Published, in-stock products, cheapest first is deliberately not used:
	 * menu order keeps the merchant's merchandising in charge of ties.
	 *
	 * @return array<int, \WC_Product>
	 */
	public static function candidates() {
		$settings = self::settings();

		$args = array(
			'status'  => 'publish',
			'limit'   => max( 2, min( 100, (int) $settings['limit'] ) ),
			'orderby' => 'menu_order',
			'order'   => 'ASC',
			'return'  => 'objects',
		);

		if ( ! empty( $settings['category'] ) ) {
			$args['category'] = array( sanitize_title( $settings['category'] ) );
		}

		$products = wc_get_products( $args );

		if ( ! is_array( $products ) ) {
			return array();
		}

		$in_stock = array();

		foreach ( $products as $product ) {
			if ( $product instanceof \WC_Product && $product->is_in_stock() ) {
				$in_stock[] = $product;
			}
		}

		return $in_stock;
	}

	/**
	 * Map a product's attributes onto the four scoring dimensions.
	 *
	 * Supports real taxonomy attributes (pa_firmness) and plain custom
	 * attributes ("Firmness: Medium Firm"), because catalogues use both.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string, string>
	 */
	public static function attributes_for( $product ) {
		$settings = self::settings();
		$lookup   = array(
			'firmness' => self::normalize_name( $settings['attr_firmness'] ),
			'support'  => self::normalize_name( $settings['attr_support'] ),
			'cooling'  => self::normalize_name( $settings['attr_cooling'] ),
			'motion'   => self::normalize_name( $settings['attr_motion'] ),
		);

		$attributes = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}

			$name = self::normalize_name( $attribute->get_name() );

			$dimension = array_search( $name, $lookup, true );

			if ( false === $dimension || isset( $attributes[ $dimension ] ) ) {
				continue;
			}

			if ( $attribute->is_taxonomy() ) {
				$values = wc_get_product_terms(
					$product->get_id(),
					$attribute->get_name(),
					array( 'fields' => 'names' )
				);
			} else {
				$values = $attribute->get_options();
			}

			if ( empty( $values ) ) {
				continue;
			}

			$attributes[ $dimension ] = Scoring::normalize_value( reset( $values ) );
		}

		return $attributes;
	}

	/**
	 * "pa_firmness" and "Firmness" both become "firmness".
	 *
	 * @param string $name Raw attribute name.
	 * @return string
	 */
	public static function normalize_name( $name ) {
		$name = strtolower( trim( (string) $name ) );

		if ( 0 === strpos( $name, 'pa_' ) ) {
			$name = substr( $name, 3 );
		}

		return Scoring::normalize_value( $name );
	}

	/**
	 * Shape one product for the browser.
	 *
	 * @param \WC_Product         $product    Product.
	 * @param array<string,string> $attributes Matched attributes.
	 * @param int                 $score      Zero to one hundred.
	 * @param array<int, string>  $reasons    Plain-language reasons.
	 * @param string              $blurb      Optional AI line.
	 * @return array<string, mixed>
	 */
	public static function to_payload( $product, $attributes, $score, $reasons, $blurb ) {
		$image_id  = $product->get_image_id();
		$image     = $image_id ? wp_get_attachment_image_src( $image_id, 'woocommerce_thumbnail' ) : false;

		if ( ! $image && $image_id ) {
			// SVGs have no generated thumbnail size; fall back to the original.
			$full = wp_get_attachment_url( $image_id );

			if ( $full ) {
				$image = array( $full, 800, 600 );
			}
		}

		$image_alt = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';

		return array(
			'id'         => $product->get_id(),
			'name'       => wp_strip_all_tags( $product->get_name() ),
			'url'        => get_permalink( $product->get_id() ),
			'price'      => (float) $product->get_price(),
			'price_html' => $product->get_price_html(),
			'image'      => $image ? array(
				'url'    => $image[0],
				'width'  => (int) $image[1],
				'height' => (int) $image[2],
				'alt'    => $image_alt ? $image_alt : $product->get_name(),
			) : null,
			'attributes' => $attributes,
			'score'      => (int) $score,
			'band'       => Scoring::band( (int) $score ),
			'reasons'    => $reasons,
			'blurb'      => $blurb,
		);
	}
}
