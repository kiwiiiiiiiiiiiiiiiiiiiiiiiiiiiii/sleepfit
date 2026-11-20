<?php
/**
 * Demo seeder. Not loaded by the plugin at runtime.
 *
 * Creates a small sleep-brand catalogue so the finder can be demonstrated with
 * real WooCommerce products in WordPress Playground. Run once on a throwaway
 * install only; it deletes its own products first so it is safe to re-run.
 *
 * @package SleepFit
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seed the demo store.
 */
function sleepfit_demo_seed() {
	if ( ! class_exists( 'WC_Product_Simple' ) ) {
		return new WP_Error( 'sleepfit_no_wc', 'WooCommerce is not active.' );
	}

	$attributes = array(
		'firmness' => array( 'Soft', 'Medium', 'Medium Firm', 'Firm' ),
		'support'  => array( 'Pressure Relief', 'Balanced', 'Firm Support' ),
		'cooling'  => array( 'Low', 'Medium', 'High' ),
		'motion'   => array( 'Low', 'Medium', 'High' ),
	);

	foreach ( $attributes as $name => $terms ) {
		$taxonomy = 'pa_' . $name;

		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy( $taxonomy, 'product', array( 'hierarchical' => false, 'public' => false ) );
		}

		$existing = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'fields' => 'id=>slug' ) );

		foreach ( $terms as $term_name ) {
			$slug = sanitize_title( $term_name );

			if ( ! in_array( $slug, (array) $existing, true ) ) {
				wp_insert_term( $term_name, $taxonomy, array( 'slug' => $slug ) );
			}
		}
	}

	// Global attribute definitions, so WooCommerce exposes them in the UI too.
	global $wpdb;

	if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
		$defined = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$defined[] = $attribute->attribute_name;
		}

		foreach ( array_keys( $attributes ) as $name ) {
			if ( ! in_array( $name, $defined, true ) ) {
				wc_create_attribute(
					array(
						'name'         => ucfirst( $name ),
						'slug'         => $name,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					)
				);
			}
		}

		if ( function_exists( 'wc_register_attribute_taxonomies' ) ) {
			wc_register_attribute_taxonomies();
		}
	}

	$products = array(
		array(
			'name'     => 'Cloudline Plush',
			'price'    => 1890,
			'firmness' => 'Soft',
			'support'  => 'Pressure Relief',
			'cooling'  => 'Low',
			'motion'   => 'High',
			'color'    => '#d9c3ae',
			'blurb'    => 'A soft, enclosing feel for side sleepers who like to settle in.',
		),
		array(
			'name'     => 'Haven Pocket Spring',
			'price'    => 1590,
			'firmness' => 'Medium',
			'support'  => 'Balanced',
			'cooling'  => 'Medium',
			'motion'   => 'High',
			'color'    => '#b7c8bb',
			'blurb'    => 'A balanced, quietly sprung bed for couples who move in their sleep.',
		),
		array(
			'name'     => 'Nimbus 22',
			'price'    => 2490,
			'firmness' => 'Medium Firm',
			'support'  => 'Balanced',
			'cooling'  => 'Medium',
			'motion'   => 'High',
			'color'    => '#9fb2c4',
			'blurb'    => 'The middle ground: enough give on top, enough support underneath.',
		),
		array(
			'name'     => 'Terra Firm',
			'price'    => 2190,
			'firmness' => 'Firm',
			'support'  => 'Firm Support',
			'cooling'  => 'Medium',
			'motion'   => 'Low',
			'color'    => '#c8b49a',
			'blurb'    => 'A flat, supportive surface for stomach and back sleepers.',
		),
		array(
			'name'     => 'AeroCool Hybrid',
			'price'    => 2890,
			'firmness' => 'Medium Firm',
			'support'  => 'Balanced',
			'cooling'  => 'High',
			'motion'   => 'High',
			'color'    => '#a8c9cc',
			'blurb'    => 'A breathable hybrid for hot sleepers who still want a flat surface.',
		),
		array(
			'name'     => 'Drift Latex',
			'price'    => 3290,
			'firmness' => 'Medium',
			'support'  => 'Pressure Relief',
			'cooling'  => 'High',
			'motion'   => 'Low',
			'color'    => '#c1b9d0',
			'blurb'    => 'Springy, cool latex with give at the shoulder and minimal motion transfer.',
		),
	);

	$ids = array();

	foreach ( $products as $index => $data ) {
		$product = new WC_Product_Simple();
		$product->set_name( $data['name'] );
		$product->set_status( 'publish' );
		$product->set_regular_price( (string) $data['price'] );
		$product->set_short_description( $data['blurb'] );
		$product->set_description( $data['blurb'] . ' Built for sleepers who care about how a bed actually feels.' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_menu_order( $index );
		$product->set_stock_status( 'instock' );

		$image_id = sleepfit_demo_image( $data['name'], $data['color'], $index );

		if ( $image_id ) {
			$product->set_image_id( $image_id );
		}

		$terms = array();

		foreach ( array( 'firmness', 'support', 'cooling', 'motion' ) as $dimension ) {
			$slug      = sanitize_title( $data[ $dimension ] );
			$attribute = new WC_Product_Attribute();
			$attribute->set_id( 0 );
			$attribute->set_name( 'pa_' . $dimension );
			$attribute->set_options( array( $slug ) );
			$attribute->set_visible( true );
			$attribute->set_variation( false );
			$terms[] = $attribute;
		}

		$product->set_attributes( $terms );
		$product->save();

		$ids[] = $product->get_id();
	}

	// A page with the shortcode.
	$page = get_page_by_path( 'find-your-mattress' );

	if ( ! $page ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => 'Find your mattress',
				'post_name'    => 'find-your-mattress',
				'post_content' => '[sleepfit]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);
	} else {
		$page_id = $page->ID;
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => '[sleepfit]',
			)
		);
	}

	update_option( 'woocommerce_currency', 'MYR' );
	update_option( 'woocommerce_default_country', 'MY:01' );
	update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
	update_option( 'sleepfit_settings', array_merge( \SleepFit\Matcher::DEFAULTS, array( 'limit' => 40 ) ) );

	flush_rewrite_rules();

	return array(
		'products' => $ids,
		'page'     => $page_id,
		'url'      => get_permalink( $page_id ),
	);
}

/**
 * Create a simple SVG product plate in the media library.
 *
 * @param string $name  Product name.
 * @param string $color Accent colour.
 * @param int    $index Plate number.
 * @return int Attachment id.
 */
function sleepfit_demo_image( $name, $color, $index ) {
	$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600" role="img" aria-label="' . esc_attr( $name ) . '">'
		. '<rect width="800" height="600" fill="#efece4"/>'
		. '<rect x="0" y="0" width="800" height="10" fill="' . $color . '"/>'
		. '<text x="48" y="86" font-family="Georgia,serif" font-size="34" fill="#16241d">' . esc_html( $name ) . '</text>'
		. '<text x="48" y="120" font-family="monospace" font-size="16" letter-spacing="4" fill="#5c6b61">SLEEP / ' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) . '</text>'
		. '<rect x="120" y="290" width="560" height="160" rx="12" fill="#ffffff" stroke="#c9d2c6" stroke-width="4"/>'
		. '<rect x="160" y="240" width="180" height="70" rx="10" fill="' . $color . '" opacity="0.75"/>'
		. '<rect x="380" y="240" width="180" height="70" rx="10" fill="' . $color . '" opacity="0.55"/>'
		. '<path d="M120 360 H680" stroke="#c9d2c6" stroke-width="3"/>'
		. '<rect x="120" y="450" width="560" height="18" fill="' . $color . '"/>'
		. '</svg>';

	$upload = wp_upload_bits( 'sleepfit-' . ( $index + 1 ) . '.svg', null, $svg );

	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'guid'           => $upload['url'],
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$upload['file']
	);

	if ( $attachment_id ) {
		wp_update_attachment_metadata(
			$attachment_id,
			array(
				'width'  => 800,
				'height' => 600,
				'file'   => str_replace( wp_upload_dir()['basedir'] . '/', '', $upload['file'] ),
			)
		);
	}

	return $attachment_id;
}

return sleepfit_demo_seed();
