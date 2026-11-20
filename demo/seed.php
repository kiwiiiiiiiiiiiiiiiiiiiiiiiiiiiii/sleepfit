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

		$sku         = 'SLEEPFIT-DEMO-' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT );
		$existing_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;

		if ( $existing_id ) {
			wp_delete_post( $existing_id, true );
		}

		$product->set_sku( $sku );
		$image_id = sleepfit_demo_image( $data['name'], $index );

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
function sleepfit_demo_image( $name, $index ) {
	$filename = 'sleepfit-' . str_pad( (string) ( $index + 1 ), 2, '0', STR_PAD_LEFT ) . '.svg';
	$source   = SLEEPFIT_DIR . 'assets/demo/' . $filename;

	if ( ! file_exists( $source ) ) {
		return 0;
	}

	// Copy the committed SVG into uploads so WordPress can build a normal
	// attachment URL. wp_upload_bits() rejects SVG on some installs.
	$uploads = wp_upload_dir();
	$subdir  = gmdate( 'Y/m' );
	$dir     = $uploads['basedir'] . '/' . $subdir;

	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return 0;
	}

	$target = $dir . '/' . $filename;

	if ( ! copy( $source, $target ) ) {
		return 0;
	}

	$relative = $subdir . '/' . $filename;
	$url      = $uploads['baseurl'] . '/' . $relative;
	$id       = wp_insert_attachment(
		array(
			'guid'           => $url,
			'post_mime_type' => 'image/svg+xml',
			'post_title'     => $name,
			'post_status'    => 'inherit',
		),
		$target
	);

	if ( ! $id ) {
		return 0;
	}

	update_post_meta( $id, '_wp_attached_file', $relative );
	wp_update_attachment_metadata(
		$id,
		array(
			'width'  => 800,
			'height' => 600,
			'file'   => $relative,
		)
	);

	return $id;
}

return sleepfit_demo_seed();
