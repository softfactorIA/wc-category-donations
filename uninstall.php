<?php
/**
 * Uninstall handler: remove plugin options, term meta, and the donations
 * table (both the current wccd_* names and any pre-1.1.0 "razas" legacy
 * leftovers).
 *
 * @package WcCategoryDonations
 * @license GPL-2.0-or-later
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Current option names.
delete_option( 'wccd_default_donation_percentage' );
delete_option( 'wccd_category_settings' );
delete_option( 'wccd_db_version' );
delete_option( 'wccd_migrated_from_razas' );

// Legacy pre-1.1.0 option names.
delete_option( 'razas_donation_message' );
delete_option( 'razas_default_donation_percentage' );
delete_option( 'razas_category_settings' );
delete_option( 'razas_db_version' );

// Current term meta keys.
delete_metadata( 'term', 0, 'wccd_donation_percentage', '', true );
delete_metadata( 'term', 0, 'wccd_category_cause', '', true );
delete_metadata( 'term', 0, 'wccd_donation_initial_amount', '', true );

// Legacy term meta keys.
delete_metadata( 'term', 0, 'razas_donation_percentage', '', true );
delete_metadata( 'term', 0, 'razas_category_cause', '', true );
delete_metadata( 'term', 0, 'razas_donation_initial_amount', '', true );

// Current and legacy order meta (processed refunds), post meta and HPOS.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", 'wccd_processed_refunds', 'razas_processed_refunds' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

$orders_meta_table = $wpdb->prefix . 'wc_orders_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $orders_meta_table ) ) ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$orders_meta_table} WHERE meta_key IN (%s, %s)", 'wccd_processed_refunds', 'razas_processed_refunds' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wccd_donations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}razas_donations" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery