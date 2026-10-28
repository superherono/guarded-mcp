<?php
/**
 * Uninstall: remove the bot role, the menu capability and the plugin's options.
 *
 * Content the bot created (drafts, terms, images) and the bot user stay: they
 * belong to the site, and deleting a user means choosing who inherits its posts.
 *
 * @package guarded-mcp
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

remove_role( 'guarded_mcp_bot' );

// The menu cap may have been granted to other roles by hand.
foreach ( wp_roles()->role_objects as $guarded_mcp_role ) {
	$guarded_mcp_role->remove_cap( 'guarded_mcp_edit_menus' );
}

delete_option( 'guarded_mcp_token' );
delete_option( 'guarded_mcp_role_version' );
delete_option( 'guarded_mcp_menu_backups' );

// Open upload tickets: one option per ticket, named by the ticket hash.
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
		$wpdb->esc_like( 'guarded_mcp_upload_' ) . '%'
	)
);

// File hashes used to find duplicate uploads.
delete_post_meta_by_key( '_guarded_mcp_sha256' );
