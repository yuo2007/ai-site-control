<?php
/**
 * Remove everything the plugin stored.
 *
 * Deleting a plugin should not leave credentials behind. This one accumulates
 * real ones — a hashed bearer token, OAuth access and refresh tokens, the
 * registered clients that can exchange them — and leaving those in the options
 * table means a reinstall silently restores working access that nobody
 * remembers granting.
 *
 * @package WPMCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Clear this site's data.
 */
function wpmcp_uninstall_site() {
	global $wpdb;

	$options = array(
		'wpmcp_settings',
		'wpmcp_oauth_clients',
		'wpmcp_oauth_tokens',
		'wpmcp_oauth_refresh',
		'wpmcp_audit_log',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Authorization codes, the one-time token display, failure counters and
	// registration throttles all live in transients whose names carry a hash,
	// so they cannot be listed. An expired transient would be collected
	// eventually, but "eventually" is not when someone asked for the plugin to
	// be removed.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall, one pass, no cache to invalidate.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wpmcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wpmcp_' ) . '%'
		)
	);
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $wpmcp_site_id ) {
		switch_to_blog( $wpmcp_site_id );
		wpmcp_uninstall_site();
		restore_current_blog();
	}
} else {
	wpmcp_uninstall_site();
}
