<?php
/**
 * Bootstrap and shared registration.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin singleton.
 */
final class Plugin {

	/**
	 * Instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get the instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wire hooks.
	 */
	public function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		Shortcode::register();

		// Registering routes on plugins_loaded calls rest_get_server() before
		// $wp_rewrite exists, which is a fatal on a fresh install.
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		if ( is_admin() ) {
			Admin::register();
		}
	}

	/**
	 * Load translations at init, not at plugins_loaded.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'sleepfit', false, dirname( plugin_basename( SLEEPFIT_FILE ) ) . '/languages' );
	}

	/**
	 * Register but do not enqueue. Assets load only on pages with [sleepfit].
	 */
	public function register_assets() {
		wp_register_style( 'sleepfit', SLEEPFIT_URL . 'assets/css/sleepfit.css', array(), SLEEPFIT_VERSION );
		wp_register_script( 'sleepfit', SLEEPFIT_URL . 'assets/js/sleepfit.js', array(), SLEEPFIT_VERSION, true );

		wp_localize_script(
			'sleepfit',
			'sleepfitConfig',
			array(
				'restUrl' => esc_url_raw( rest_url( Rest::NS . '/match' ) ),
				'i18n'    => array(
					'progress' => __( 'Question %1$d of %2$d', 'sleepfit' ),
					'next'     => __( 'Continue', 'sleepfit' ),
					'submit'   => __( 'Show my matches', 'sleepfit' ),
					'required' => __( 'Choose an answer to continue.', 'sleepfit' ),
					'loading'  => __( 'Finding your matches...', 'sleepfit' ),
					'error'    => __( 'Could not reach the shop right now. Please try again.', 'sleepfit' ),
					'empty'    => __( 'No products matched. Ask the shop to add sleep attributes to the catalogue.', 'sleepfit' ),
					'results'  => __( 'Your two closest matches', 'sleepfit' ),
					'note'     => __( 'Based only on the four answers above. Nothing about your body or budget was stored.', 'sleepfit' ),
					'again'    => __( 'Start again', 'sleepfit' ),
				),
			)
		);
	}
}
