=== SleepFit - Guided mattress finder ===
Contributors: kiwiiiiiiiiiiiiiiiiiiiiiiiiiiiii
Tags: woocommerce, product finder, quiz, recommendations, ai
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
WC requires at least: 8.0
WC tested up to: 9.9
Stable tag: 1.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A four-question product finder for sleep stores. Ranks real WooCommerce products and explains the shortlist.

== Description ==

SleepFit asks four questions - sleep position, preferred firmness, temperature and who shares the bed - then scores the catalogue against the answers. It shows two products instead of twenty, with a match score and plain-language reasons.

The scoring model is plain PHP and fully testable. An optional AI layer can write one short line for the top result, with a 30-day cache, a 6 second timeout and a deterministic fallback. No key is required.

Privacy: events store answer slugs, product ID and score only. No IP address, user ID or session data.

Use the `[sleepfit]` shortcode on any page. Settings live under WooCommerce > SleepFit.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/sleepfit/`.
2. Activate SleepFit. WooCommerce must be active.
3. Add `[sleepfit]` to a page.
4. In WooCommerce > SleepFit, map the attribute slugs used in your catalogue.

Defaults assume `pa_firmness`, `pa_support`, `pa_cooling` and `pa_motion`.

== Frequently Asked Questions ==

= Does this work without an AI key? =

Yes. The ranking and the reason bullets always work. The key only adds one short generated line to the top match.

= Does it work without JavaScript? =

Yes. Every question is server-rendered and a normal form POST produces the same result cards. JavaScript only adds the step-by-step flow.

= What data is stored? =

Four answer slugs, the matched product ID and the score. Nothing that identifies a shopper.

= Can I use another AI provider? =

Yes. Any chat-completions compatible endpoint works. Set the base URL and model in settings, and define `SLEEPFIT_AI_KEY` in wp-config.php.

== Screenshots ==

1. A single question, shown one step at a time.
2. The two matched beds with reasons.

== Changelog ==

= 1.0.0 =
* Initial release.
* Four-question finder, transparent scoring, optional AI explanation, anonymous match report.
