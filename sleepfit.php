<?php
/**
 * Plugin Name:       SleepFit — Guided mattress finder
 * Plugin URI:        https://github.com/kiwiiiiiiiiiiiiiiiiiiiiiiiiiiiii/sleepfit
 * Description:       A guided product finder for sleep stores. Asks four questions, scores the catalogue against sleep attributes, and optionally writes the match explanation with an LLM.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * Author:            Farish
 * Author URI:        https://farish-portfolio.vercel.app
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       sleepfit
 * Domain Path:       /languages
 *
 * @package SleepFit
 */

defined( 'ABSPATH' ) || exit;

define( 'SLEEPFIT_VERSION', '1.0.0' );
define( 'SLEEPFIT_FILE', __FILE__ );
define( 'SLEEPFIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLEEPFIT_URL', plugin_dir_url( __FILE__ ) );

require_once SLEEPFIT_DIR . 'includes/class-scoring.php';
require_once SLEEPFIT_DIR . 'includes/class-matcher.php';
require_once SLEEPFIT_DIR . 'includes/class-events.php';
require_once SLEEPFIT_DIR . 'includes/class-llm.php';
require_once SLEEPFIT_DIR . 'includes/class-rest.php';
require_once SLEEPFIT_DIR . 'includes/class-shortcode.php';
require_once SLEEPFIT_DIR . 'includes/class-admin.php';
require_once SLEEPFIT_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( '\SleepFit\Events', 'install' ) );

/**
 * Declare compatibility with WooCommerce features that change storage or behaviour.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'SleepFit needs WooCommerce to be active. The finder is idle until then.', 'sleepfit' );
					echo '</p></div>';
				}
			);
			return;
		}

		\SleepFit\Plugin::instance()->boot();
	}
);
