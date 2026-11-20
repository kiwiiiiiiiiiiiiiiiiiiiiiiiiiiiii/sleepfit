<?php
/**
 * Shortcode: [sleepfit]
 *
 * The markup is server-rendered and the form works without JavaScript. The JS
 * only enhances it: it hides all but the current question, advances the steps
 * and renders results in place. No duplicate question copy in two languages.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * Finder markup.
 */
final class Shortcode {

	/**
	 * Register [sleepfit].
	 */
	public static function register() {
		add_shortcode( 'sleepfit', array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the finder.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'heading' => __( 'Find the bed that fits how you sleep', 'sleepfit' ),
			),
			$atts,
			'sleepfit'
		);

		wp_enqueue_style( 'sleepfit' );
		wp_enqueue_script( 'sleepfit' );

		ob_start();

		$submitted = isset( $_POST['sleepfit_submitted'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified below.
		$nonce_ok  = isset( $_POST['sleepfit_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['sleepfit_nonce'] ) ), 'sleepfit_match' );

		if ( $submitted && ! $nonce_ok ) {
			echo '<p class="sleepfit__notice">' . esc_html__( 'That form expired. Please try again.', 'sleepfit' ) . '</p>';
		}

		$matches = array();

		if ( $submitted && $nonce_ok ) {
			$raw = isset( $_POST['sleepfit_answers'] ) && is_array( $_POST['sleepfit_answers'] ) ? wp_unslash( $_POST['sleepfit_answers'] ) : array();

			add_filter( 'sleepfit_llm_enabled', '__return_false' );
			$matches = Matcher::match( $raw );
			remove_filter( 'sleepfit_llm_enabled', '__return_false' );

			if ( ! empty( $matches ) ) {
				Events::record( Scoring::sanitize_answers( $raw ), $matches[0]['id'], $matches[0]['score'] );
			}
		}

		$questions = Scoring::questions();
		$total     = count( $questions );
		?>
		<section class="sleepfit" data-sleepfit aria-label="<?php echo esc_attr__( 'SleepFit mattress finder', 'sleepfit' ); ?>">
			<div class="sleepfit__intro">
				<p class="sleepfit__eyebrow"><?php esc_html_e( 'SleepFit / 4 questions', 'sleepfit' ); ?></p>
				<h2 class="sleepfit__heading"><?php echo esc_html( $atts['heading'] ); ?></h2>
				<p class="sleepfit__lede"><?php esc_html_e( 'Answer four quick questions. We will shortlist two beds from the range and explain why each one fits.', 'sleepfit' ); ?></p>
			</div>

			<form class="sleepfit__form" method="post" data-sleepfit-form>
				<input type="hidden" name="sleepfit_submitted" value="1" />
				<?php wp_nonce_field( 'sleepfit_match', 'sleepfit_nonce' ); ?>

				<ol class="sleepfit__steps">
					<?php foreach ( $questions as $index => $question ) : ?>
						<li class="sleepfit__step" data-sleepfit-step>
							<fieldset class="sleepfit__fieldset" aria-labelledby="sleepfit-q-<?php echo esc_attr( $question['id'] ); ?>">
								<div class="sleepfit__legend" id="sleepfit-q-<?php echo esc_attr( $question['id'] ); ?>">
									<span class="sleepfit__count"><?php echo esc_html( sprintf( '%1$d / %2$d', $index + 1, $total ) ); ?></span>
									<?php echo esc_html( $question['legend'] ); ?>
								</div>
								<p class="sleepfit__help"><?php echo esc_html( $question['help'] ); ?></p>
								<div class="sleepfit__options">
									<?php foreach ( $question['options'] as $value => $label ) : ?>
										<label class="sleepfit__option">
											<input
												type="radio"
												name="sleepfit_answers[<?php echo esc_attr( $question['id'] ); ?>]"
												value="<?php echo esc_attr( $value ); ?>"
											/>
											<span class="sleepfit__option-label"><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						</li>
					<?php endforeach; ?>
				</ol>

				<p class="sleepfit__progress" data-sleepfit-progress aria-live="polite" hidden></p>

				<div class="sleepfit__controls">
					<button class="sleepfit__button sleepfit__button--back" type="button" data-sleepfit-back hidden>
						<?php esc_html_e( 'Back', 'sleepfit' ); ?>
					</button>
					<button class="sleepfit__button sleepfit__button--next" type="button" data-sleepfit-next hidden>
						<?php esc_html_e( 'Continue', 'sleepfit' ); ?>
					</button>
					<button class="sleepfit__button sleepfit__button--submit" type="submit" data-sleepfit-submit>
						<?php esc_html_e( 'Show my matches', 'sleepfit' ); ?>
					</button>
				</div>

				<p class="sleepfit__status" data-sleepfit-status role="status" aria-live="polite"></p>
			</form>

			<?php if ( ! empty( $matches ) ) : ?>
				<div class="sleepfit__results" data-sleepfit-results tabindex="-1">
					<div class="sleepfit__results-head">
						<h3 class="sleepfit__results-title"><?php esc_html_e( 'Your two closest matches', 'sleepfit' ); ?></h3>
						<p class="sleepfit__results-note"><?php esc_html_e( 'Based only on the four answers above. Nothing about your body or budget was stored.', 'sleepfit' ); ?></p>
					</div>
					<div class="sleepfit__cards">
						<?php foreach ( $matches as $match ) : ?>
							<?php echo self::card( $match ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside card(). ?>
						<?php endforeach; ?>
					</div>
					<a class="sleepfit__again" href="<?php echo esc_url( get_permalink() ); ?>">
						<?php esc_html_e( 'Start again', 'sleepfit' ); ?>
					</a>
				</div>
			<?php else : ?>
				<div class="sleepfit__results" data-sleepfit-results hidden tabindex="-1"></div>
			<?php endif; ?>

			<template data-sleepfit-template>
				<?php echo self::card( null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside card(). ?>
			</template>
		</section>
		<?php

		return ob_get_clean();
	}

	/**
	 * One result card. Passing null gives the empty shell that JS clones.
	 *
	 * @param array<string, mixed>|null $match Match payload, or null for a template.
	 * @return string
	 */
	public static function card( $match ) {
		$image = isset( $match['image'] ) && is_array( $match['image'] ) ? $match['image'] : null;

		ob_start();
		?>
		<article class="sleepfit__card">
			<div class="sleepfit__media" data-slot="media">
				<?php if ( $image && ! empty( $image['url'] ) ) : ?>
					<img
						src="<?php echo esc_url( $image['url'] ); ?>"
						alt="<?php echo esc_attr( $image['alt'] ); ?>"
						width="<?php echo esc_attr( $image['width'] ); ?>"
						height="<?php echo esc_attr( $image['height'] ); ?>"
						loading="lazy"
						decoding="async"
					/>
				<?php endif; ?>
			</div>
			<div class="sleepfit__card-body">
				<p class="sleepfit__band"><span data-slot="score"></span><span class="sleepfit__band-label" data-slot="band"></span></p>
				<h4 class="sleepfit__name">
					<a href="<?php echo $match ? esc_url( $match['url'] ) : '#'; ?>" data-slot="link">
						<?php echo $match ? esc_html( $match['name'] ) : ''; ?>
					</a>
				</h4>
				<p class="sleepfit__price" data-slot="price"><?php echo $match ? wp_kses_post( $match['price_html'] ) : ''; ?></p>
				<p class="sleepfit__blurb" data-slot="blurb" <?php echo ( ! $match || empty( $match['blurb'] ) ) ? 'hidden' : ''; ?>>
					<?php echo $match && ! empty( $match['blurb'] ) ? esc_html( $match['blurb'] ) : ''; ?>
				</p>
				<ul class="sleepfit__reasons" data-slot="reasons">
					<?php if ( $match ) : ?>
						<?php foreach ( (array) $match['reasons'] as $reason ) : ?>
							<li><?php echo esc_html( $reason ); ?></li>
						<?php endforeach; ?>
					<?php endif; ?>
				</ul>
			</div>
		</article>
		<?php

		return ob_get_clean();
	}
}
