<?php
/**
 * Bot identity: role, user and token.
 *
 * The "who" of MCP writes:
 *  - the `guarded_mcp_bot` role: page, post, term, upload and menu rights,
 *    NO publish, NO delete, NO `unfiltered_html`;
 *  - the bot user (login from guarded_mcp_bot_login()), created by a person and
 *    given this role — see README "Installation";
 *  - the secret token the custom MCP server checks before it runs a request as
 *    the bot.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Capabilities of the bot role.
 *
 * Drafts and edits only. No publish_pages / publish_posts: publishing is a
 * human action (ARCHITECTURE.md, invariant 4). No delete_*: there is no delete
 * ability, and the role could not delete through core either.
 *
 * @return array<string,bool>
 */
function guarded_mcp_bot_caps() {
	return array(
		'read'                   => true,
		// get/create/update-page. edit_published_pages lets the bot edit a page
		// a person has published; the change goes into a revision.
		'edit_pages'             => true,
		'edit_others_pages'      => true,
		'edit_published_pages'   => true,
		// get/create/update-post. Assigning an existing category maps to edit_posts.
		'edit_posts'             => true,
		'edit_others_posts'      => true,
		'edit_published_posts'   => true,
		// create-term / update-term. manage_categories is the edit_terms cap of
		// categories and tags alike. WordPress ties delete_terms to the same cap,
		// but MCP exposes no delete.
		'manage_categories'      => true,
		// prepare-media-upload / get-media / update-media. Uploads go only through
		// a single-use ticket (media-upload.php); the role has no delete_posts, so
		// it cannot delete media either.
		'upload_files'           => true,
		// get-menus / update-menu. The plugin's own cap instead of
		// edit_theme_options, which would also open the Customizer and widgets;
		// only the menu abilities check it.
		'guarded_mcp_edit_menus' => true,
	);
}

/**
 * Login of the bot user the token-authenticated server runs requests as.
 *
 * Set GUARDED_MCP_BOT_LOGIN in wp-config.php to use another login.
 *
 * @return string
 */
function guarded_mcp_bot_login() {
	return defined( 'GUARDED_MCP_BOT_LOGIN' ) ? (string) GUARDED_MCP_BOT_LOGIN : 'mcp-bot';
}

/**
 * Create or re-sync the bot role for MCP access, WITHOUT unfiltered_html. Runs on init.
 *
 * Defined in code (not only via wp-cli): portable, a guaranteed-correct cap
 * set, moves with the plugin. On single-site Editor/Admin have unfiltered_html —
 * so we don't reuse a built-in role, we create our own from scratch (a new role
 * doesn't get unfiltered_html; on sync we also strip it explicitly).
 *
 * Idempotent: created once; bump $version when guarded_mcp_bot_caps() changes.
 * A sync also removes caps the role should not have, so a cap taken out of the
 * list is taken off existing sites too.
 *
 * @return void
 */
function guarded_mcp_sync_bot_role() {
	$role_slug = 'guarded_mcp_bot';
	$version   = '1'; // Bump when guarded_mcp_bot_caps() changes.
	$caps      = guarded_mcp_bot_caps();

	$role = get_role( $role_slug );
	if ( ! $role ) {
		add_role( $role_slug, 'Guarded MCP Bot', $caps ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.custom_role_add_role -- Not a VIP-hosted plugin; the role is created once and re-synced by version.
		update_option( 'guarded_mcp_role_version', $version );
		return;
	}

	if ( get_option( 'guarded_mcp_role_version' ) !== $version ) {
		foreach ( array_keys( $role->capabilities ) as $cap ) {
			if ( empty( $caps[ $cap ] ) ) {
				$role->remove_cap( $cap );
			}
		}
		$role->remove_cap( 'unfiltered_html' ); // Just in case.
		foreach ( $caps as $cap => $grant ) {
			$role->add_cap( $cap, $grant );
		}
		update_option( 'guarded_mcp_role_version', $version );
	}
}
add_action( 'init', 'guarded_mcp_sync_bot_role' );

/**
 * The bot user, if it exists and is safe to act as.
 *
 * Invariant 5: requests behind the token run as a dedicated low-privilege bot,
 * never as an administrator. A bot user that was given extra roles or caps by
 * mistake (unfiltered_html, manage_options, publishing, deleting) is refused
 * rather than trusted.
 *
 * @return WP_User|WP_Error
 */
function guarded_mcp_bot_user() {
	$bot = get_user_by( 'login', guarded_mcp_bot_login() );
	if ( ! $bot ) {
		return new WP_Error(
			'guarded_mcp_no_bot_user',
			__( 'MCP bot user not found.', 'guarded-mcp' ),
			array( 'status' => 500 )
		);
	}

	$too_strong = array( 'unfiltered_html', 'manage_options', 'edit_theme_options', 'publish_pages', 'publish_posts', 'delete_posts', 'delete_pages', 'delete_others_posts', 'delete_others_pages' );
	foreach ( $too_strong as $cap ) {
		if ( user_can( $bot, $cap ) ) {
			return new WP_Error(
				'guarded_mcp_bot_too_privileged',
				sprintf(
					/* translators: %s: capability. */
					__( 'The MCP bot user has "%s". Give it only the Guarded MCP Bot role.', 'guarded-mcp' ),
					$cap
				),
				array( 'status' => 500 )
			);
		}
	}

	return $bot;
}

/**
 * Secret token authenticating the MCP connector.
 *
 * Some MCP clients (the Claude connector among them) support only OAuth or no
 * auth: no Basic auth, no custom headers. So the custom server takes a URL token
 * (?token=...), checks it and runs the request as the bot user. The token lives
 * in an option (not autoloaded) and is generated automatically.
 * Read: `wp option get guarded_mcp_token`. Rotate: `wp option delete guarded_mcp_token`.
 *
 * @return string
 */
function guarded_mcp_get_token() {
	$token = get_option( 'guarded_mcp_token' );
	if ( ! is_string( $token ) || '' === $token ) {
		$token = wp_generate_password( 48, false, false );
		update_option( 'guarded_mcp_token', $token, false );
	}
	return $token;
}

// Ensure the token exists (so it can be read via `wp option get`).
add_action(
	'init',
	function () {
		guarded_mcp_get_token();
	}
);
