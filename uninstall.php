<?php
/**
 * Uninstall cleanup.
 *
 * Options are always removed. The events table is only dropped when the site
 * owner opts in, so a routine uninstall cannot destroy a merchant's report.
 *
 * @package SleepFit
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'sleepfit_settings' );
delete_option( 'sleepfit_db_version' );

/**
 * Filter whether to drop the events table on uninstall.
 *
 * @param bool $drop Whether to drop the table.
 */
if ( apply_filters( 'sleepfit_delete_data_on_uninstall', false ) ) {
	global $wpdb;

	$table = $wpdb->prefix . 'sleepfit_events';

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not input.
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
}
