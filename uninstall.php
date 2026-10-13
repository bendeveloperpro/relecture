<?php
/**
 * Uninstall: removes the plugin's tables and options on every site.
 *
 * Define RELECTURE_KEEP_DATA as true in wp-config.php to keep the reviews.
 *
 * @package Relecture
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'RELECTURE_KEEP_DATA' ) && RELECTURE_KEEP_DATA ) {
	return;
}

/**
 * Drops the tables and options of the current site.
 *
 * @return void
 */
function relecture_uninstall_site() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Removing the plugin's own tables.
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'relecture_items' ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'relecture_reviews' ) );
	// phpcs:enable
	delete_option( 'relecture_db_version' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $relecture_site_id ) {
		switch_to_blog( $relecture_site_id );
		relecture_uninstall_site();
		restore_current_blog();
	}
} else {
	relecture_uninstall_site();
}

// The “rows per page” screen option is stored per user.
delete_metadata( 'user', 0, 'relecture_per_page', '', true );
