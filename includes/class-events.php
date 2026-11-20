<?php
/**
 * Aggregate match events.
 *
 * Privacy is the design constraint: we store the four answer slugs, the matched
 * product and the score. No IP address, no user id, no session id, no user agent.
 * That is enough to answer "which sleep profiles ask, and what do they get?"
 * without turning a mattress quiz into a tracking pixel.
 *
 * @package SleepFit
 */

namespace SleepFit;

defined( 'ABSPATH' ) || exit;

/**
 * Event storage and the merchant report.
 */
final class Events {

	/**
	 * Table name without the prefix.
	 */
	const TABLE = 'sleepfit_events';

	/**
	 * Create or upgrade the table.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			profile varchar(64) NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			score tinyint(3) unsigned NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY product_id (product_id)
		) {$charset};";

		dbDelta( $sql );

		update_option( 'sleepfit_db_version', SLEEPFIT_VERSION );
	}

	/**
	 * Whether recording is switched on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$settings = Matcher::settings();

		return (bool) apply_filters( 'sleepfit_events_enabled', ! empty( $settings['events_enabled'] ) );
	}

	/**
	 * Store one completed match.
	 *
	 * @param array<string, string> $answers    Sanitised answers.
	 * @param int                   $product_id Matched product.
	 * @param int                   $score      Match score.
	 */
	public static function record( $answers, $product_id, $score ) {
		global $wpdb;

		if ( ! self::enabled() || empty( $answers ) || $product_id < 1 ) {
			return;
		}

		$profile = self::profile_key( $answers );

		if ( '' === $profile ) {
			return;
		}

		$wpdb->insert(
			$wpdb->prefix . self::TABLE,
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'profile'    => $profile,
				'product_id' => (int) $product_id,
				'score'      => max( 0, min( 100, (int) $score ) ),
			),
			array( '%s', '%s', '%d', '%d' )
		);
	}

	/**
	 * Stable, readable key for a profile: "side|medium|hot|shares".
	 *
	 * @param array<string, string> $answers Sanitised answers.
	 * @return string
	 */
	public static function profile_key( $answers ) {
		$parts = array();

		foreach ( array( 'position', 'firmness', 'heat', 'partner' ) as $key ) {
			if ( isset( $answers[ $key ] ) ) {
				$parts[] = preg_replace( '/[^a-z0-9\-]/', '', (string) $answers[ $key ] );
			}
		}

		return substr( implode( '|', $parts ), 0, 64 );
	}

	/**
	 * Totals for the admin report.
	 *
	 * @param int $days Look-back window in days.
	 * @return array<string, mixed>
	 */
	public static function summary( $days = 30 ) {
		global $wpdb;

		$days  = max( 1, min( 365, (int) $days ) );
		$table = $wpdb->prefix . self::TABLE;
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from $wpdb->prefix.
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since )
		);

		$profiles = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT profile, COUNT(*) AS hits FROM {$table} WHERE created_at >= %s GROUP BY profile ORDER BY hits DESC LIMIT 50",
				$since
			),
			ARRAY_A
		);

		$products = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, COUNT(*) AS hits, ROUND(AVG(score)) AS average FROM {$table} WHERE created_at >= %s GROUP BY product_id ORDER BY hits DESC LIMIT 8",
				$since
			),
			ARRAY_A
		);
		// phpcs:enable

		$positions = array();

		foreach ( (array) $profiles as $row ) {
			$parts = explode( '|', (string) $row['profile'] );
			$key   = isset( $parts[0] ) && '' !== $parts[0] ? $parts[0] : 'unknown';

			if ( ! isset( $positions[ $key ] ) ) {
				$positions[ $key ] = 0;
			}

			$positions[ $key ] += (int) $row['hits'];
		}

		arsort( $positions );

		$top = array();

		foreach ( (array) $products as $row ) {
			$id = (int) $row['product_id'];

			$top[] = array(
				'id'      => $id,
				'name'    => $id ? get_the_title( $id ) : __( 'Deleted product', 'sleepfit' ),
				'hits'    => (int) $row['hits'],
				'average' => (int) $row['average'],
			);
		}

		return array(
			'days'      => $days,
			'total'     => $total,
			'positions' => $positions,
			'products'  => $top,
		);
	}

	/**
	 * Drop the table. Called from uninstall.php only when the merchant opted in.
	 */
	public static function drop() {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not input.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}
}
