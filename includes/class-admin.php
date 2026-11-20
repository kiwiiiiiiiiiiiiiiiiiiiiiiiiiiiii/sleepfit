<?php
/**
 * Merchant settings and the match report.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > SleepFit.
 */
final class Admin {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'settings' ) );
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public static function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'SleepFit', 'sleepfit' ),
			__( 'SleepFit', 'sleepfit' ),
			'manage_woocommerce',
			'sleepfit',
			array( __CLASS__, 'page' )
		);
	}

	/**
	 * One array option, sanitised in a single place.
	 */
	public static function settings() {
		register_setting(
			'sleepfit',
			'sleepfit_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Matcher::DEFAULTS,
			)
		);
	}

	/**
	 * Whitelist everything that can be saved.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$current = Matcher::settings();
		$input   = is_array( $input ) ? $input : array();
		$out     = Matcher::DEFAULTS;

		foreach ( array( 'attr_firmness', 'attr_support', 'attr_cooling', 'attr_motion' ) as $key ) {
			$out[ $key ] = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : $current[ $key ];
		}

		$out['category']       = isset( $input['category'] ) ? sanitize_title( wp_unslash( $input['category'] ) ) : '';
		$out['limit']          = isset( $input['limit'] ) ? max( 2, min( 100, absint( $input['limit'] ) ) ) : 40;
		$out['events_enabled'] = ! empty( $input['events_enabled'] ) ? 1 : 0;
		$out['ai_enabled']     = ! empty( $input['ai_enabled'] ) ? 1 : 0;
		$out['ai_model']       = isset( $input['ai_model'] ) ? sanitize_text_field( wp_unslash( $input['ai_model'] ) ) : $current['ai_model'];

		$base_url = isset( $input['ai_base_url'] ) ? esc_url_raw( wp_unslash( $input['ai_base_url'] ) ) : $current['ai_base_url'];

		if ( 'https' !== wp_parse_url( $base_url, PHP_URL_SCHEME ) ) {
			$base_url = Matcher::DEFAULTS['ai_base_url'];
		}

		$out['ai_base_url'] = rtrim( $base_url, '/' );

		$key = isset( $input['ai_key'] ) ? trim( (string) wp_unslash( $input['ai_key'] ) ) : '';

		// A masked field posts the placeholder back; keep the stored key.
		if ( 0 === strpos( $key, "\xE2\x80\xA2" ) ) { // phpcs:ignore -- literal bullet placeholder.
			$key = (string) $current['ai_key'];
		}

		$out['ai_key'] = sanitize_text_field( $key );

		return $out;
	}

	/**
	 * Render the page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings    = Matcher::settings();
		$has_constant = defined( 'SLEEPFIT_AI_KEY' ) && SLEEPFIT_AI_KEY;
		$report      = Events::summary( 30 );
		?>
		<div class="wrap sleepfit-admin">
			<h1><?php esc_html_e( 'SleepFit', 'sleepfit' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'A four-question finder that shortlists two beds from your catalogue. Add it to any page with the [sleepfit] shortcode.', 'sleepfit' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'sleepfit' ); ?>

				<div class="sleepfit-admin__card">
					<h2><?php esc_html_e( 'Attribute mapping', 'sleepfit' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'Enter the attribute slug used in WooCommerce, for example pa_firmness. Plain custom attributes such as Firmness also work.', 'sleepfit' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tbody>
							<?php
							self::text_field( 'attr_firmness', __( 'Firmness attribute', 'sleepfit' ), $settings['attr_firmness'] );
							self::text_field( 'attr_support', __( 'Support attribute', 'sleepfit' ), $settings['attr_support'] );
							self::text_field( 'attr_cooling', __( 'Cooling attribute', 'sleepfit' ), $settings['attr_cooling'] );
							self::text_field( 'attr_motion', __( 'Motion isolation attribute', 'sleepfit' ), $settings['attr_motion'] );
							self::text_field( 'category', __( 'Limit to product category (slug)', 'sleepfit' ), $settings['category'] );
							self::text_field( 'limit', __( 'Products to consider', 'sleepfit' ), $settings['limit'], 'number' );
							self::checkbox_field( 'events_enabled', __( 'Record anonymous match profiles', 'sleepfit' ), ! empty( $settings['events_enabled'] ) );
							?>
						</tbody>
					</table>
				</div>

				<div class="sleepfit-admin__card">
					<h2><?php esc_html_e( 'Explanation model (optional)', 'sleepfit' ); ?></h2>
					<p class="description">
						<?php esc_html_e( 'With no key, matches use the deterministic reasons below. With a key, the top match gets one short AI-written line, cached for 30 days.', 'sleepfit' ); ?>
					</p>
					<table class="form-table" role="presentation">
						<tbody>
							<?php self::checkbox_field( 'ai_enabled', __( 'Enable AI explanation', 'sleepfit' ), ! empty( $settings['ai_enabled'] ) ); ?>
							<?php self::text_field( 'ai_base_url', __( 'OpenAI-compatible base URL', 'sleepfit' ), $settings['ai_base_url'], 'url' ); ?>
							<?php self::text_field( 'ai_model', __( 'Model', 'sleepfit' ), $settings['ai_model'] ); ?>
							<tr>
								<th scope="row"><?php esc_html_e( 'API key', 'sleepfit' ); ?></th>
								<td>
									<?php if ( $has_constant ) : ?>
										<input type="text" class="regular-text" value="<?php echo esc_attr__( 'Set in wp-config.php', 'sleepfit' ); ?>" disabled />
										<p class="description"><?php esc_html_e( 'SLEEPFIT_AI_KEY is defined in wp-config.php and takes priority over the field.', 'sleepfit' ); ?></p>
									<?php else : ?>
										<input
											type="password"
											class="regular-text"
											name="sleepfit_settings[ai_key]"
											value="<?php echo $settings['ai_key'] ? esc_attr( "\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2\xE2\x80\xA2" ) : ''; ?>"
											autocomplete="off"
										/>
										<p class="description"><?php esc_html_e( 'Prefer defining SLEEPFIT_AI_KEY in wp-config.php so the key stays out of database exports.', 'sleepfit' ); ?></p>
									<?php endif; ?>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>

			<div class="sleepfit-admin__card">
				<h2><?php esc_html_e( 'Last 30 days', 'sleepfit' ); ?></h2>
				<?php if ( empty( $report['total'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'No matches recorded yet. The report fills up as shoppers use the finder.', 'sleepfit' ); ?></p>
				<?php else : ?>
					<p><strong><?php echo esc_html( (string) $report['total'] ); ?></strong> <?php esc_html_e( 'finds completed', 'sleepfit' ); ?></p>

					<h3><?php esc_html_e( 'Sleep positions', 'sleepfit' ); ?></h3>
					<?php foreach ( (array) $report['positions'] as $name => $count ) : ?>
						<?php
						$percent = (int) round( 100 * $count / max( 1, (int) $report['total'] ) );
						$labels  = array(
							'side'        => __( 'Side', 'sleepfit' ),
							'back'        => __( 'Back', 'sleepfit' ),
							'stomach'     => __( 'Stomach', 'sleepfit' ),
							'combination' => __( 'Moves around', 'sleepfit' ),
						);
						?>
						<div class="sleepfit-admin__bar-row">
							<span><?php echo esc_html( isset( $labels[ $name ] ) ? $labels[ $name ] : $name ); ?></span>
							<span class="sleepfit-admin__bar"><span style="width: <?php echo esc_attr( $percent ); ?>%"></span></span>
							<span><?php echo esc_html( (string) $count ); ?></span>
						</div>
					<?php endforeach; ?>

					<h3><?php esc_html_e( 'Most matched beds', 'sleepfit' ); ?></h3>
					<ul>
						<?php foreach ( (array) $report['products'] as $row ) : ?>
							<li>
								<?php echo esc_html( $row['name'] ); ?>
								&mdash; <?php echo esc_html( (string) $row['hits'] ); ?> <?php esc_html_e( 'matches', 'sleepfit' ); ?>
								(<?php echo esc_html( (string) $row['average'] ); ?>%)
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<p class="description">
				<?php esc_html_e( 'The report stores answer slugs, product id and score only. No IP address, user id or session data.', 'sleepfit' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render a text field row.
	 *
	 * @param string $key   Option key.
	 * @param string $label Label.
	 * @param mixed  $value Value.
	 * @param string $type  Input type.
	 */
	private static function text_field( $key, $label, $value, $type = 'text' ) {
		?>
		<tr>
			<th scope="row"><label for="sleepfit-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input
					type="<?php echo esc_attr( $type ); ?>"
					id="sleepfit-<?php echo esc_attr( $key ); ?>"
					class="regular-text"
					name="sleepfit_settings[<?php echo esc_attr( $key ); ?>]"
					value="<?php echo esc_attr( (string) $value ); ?>"
				/>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render a checkbox row with a hidden "off" value.
	 *
	 * @param string $key   Option key.
	 * @param string $label Label.
	 * @param bool   $checked Whether checked.
	 */
	private static function checkbox_field( $key, $label, $checked ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<input type="hidden" name="sleepfit_settings[<?php echo esc_attr( $key ); ?>]" value="0" />
				<label>
					<input
						type="checkbox"
						name="sleepfit_settings[<?php echo esc_attr( $key ); ?>]"
						value="1"
						<?php checked( $checked ); ?>
					/>
					<?php esc_html_e( 'Enabled', 'sleepfit' ); ?>
				</label>
			</td>
		</tr>
		<?php
	}
}
