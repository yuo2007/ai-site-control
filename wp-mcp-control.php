<?php
/**
 * Plugin Name:       WP MCP Control
 * Plugin URI:        https://github.com/yuo2007/ultimate-mcp-wordpress
 * Description:        Free, open Model Context Protocol (MCP) server for WordPress. Lets Claude, ChatGPT and other MCP clients manage posts, media, users, taxonomies, comments, options, plugins, themes, WooCommerce and Polylang translations.
 * Version:           0.6.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            You
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-mcp-control
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

define( 'WPMCP_VERSION', '0.6.0' );
define( 'WPMCP_FILE', __FILE__ );
define( 'WPMCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPMCP_URL', plugin_dir_url( __FILE__ ) );
define( 'WPMCP_NAMESPACE', 'wp-mcp/v1' );
define( 'WPMCP_MIN_WP', '5.8' );
define( 'WPMCP_MIN_PHP', '7.0' );

/**
 * Check that the stack is new enough to run the plugin.
 *
 * The "Requires at least" header already stops activation on an older
 * WordPress, but a site can be downgraded (or the plugin dropped in by FTP)
 * while the plugin stays active, so the same check runs here too.
 *
 * @return string Empty string when supported, otherwise the reason.
 */
function unsupported_reason() {
	if ( version_compare( PHP_VERSION, WPMCP_MIN_PHP, '<' ) ) {
		return sprintf(
			/* translators: 1: required PHP version, 2: current PHP version. */
			__( 'WP MCP Control requires PHP %1$s or newer. This site runs PHP %2$s.', 'wp-mcp-control' ),
			WPMCP_MIN_PHP,
			PHP_VERSION
		);
	}

	$wp_version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';
	if ( version_compare( $wp_version, WPMCP_MIN_WP, '<' ) ) {
		return sprintf(
			/* translators: 1: required WordPress version, 2: current WordPress version. */
			__( 'WP MCP Control requires WordPress %1$s or newer. This site runs WordPress %2$s.', 'wp-mcp-control' ),
			WPMCP_MIN_WP,
			$wp_version
		);
	}

	return '';
}

/**
 * Boot the plugin once all plugins are loaded so integrations
 * (WooCommerce, Polylang) can be detected reliably.
 */
function wpmcp() {
	return Plugin::instance();
}

$wpmcp_unsupported = unsupported_reason();

if ( '' !== $wpmcp_unsupported ) {
	// Stay inert rather than fatal: an unsupported site keeps its admin.
	add_action(
		'admin_notices',
		function () {
			$reason = unsupported_reason();
			if ( '' === $reason || ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>' . esc_html( $reason ) . '</p></div>';
		}
	);
} else {
	require_once WPMCP_DIR . 'includes/class-plugin.php';
	wpmcp();
}
