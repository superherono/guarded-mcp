<?php
/**
 * Custom token-authenticated MCP server.
 *
 * Exposes the plugin's abilities as direct tools on a dedicated endpoint
 * (/wp-json/guarded-mcp/v1/mcp), authenticated by a URL token (?token=...) that
 * runs the request as the bot user (access.php).
 *
 * The exposed ability list is collected from the `guarded_mcp_server_abilities`
 * filter — each ability file adds itself, so dropping in a new ability file
 * exposes it here automatically (no edit to this file).
 *
 * Adapter class references are resolved only when mcp_adapter_init fires (i.e.
 * when the MCP Adapter plugin is actually loaded) — without it the hook never
 * runs, so there's no fatal.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ability names exposed on the custom MCP server.
 * Populated by each ability file via the filter.
 *
 * @return string[]
 */
function guarded_mcp_server_abilities() {
	return array_values( array_unique( (array) apply_filters( 'guarded_mcp_server_abilities', array() ) ) );
}

add_action(
	'mcp_adapter_init',
	function ( $adapter ) {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			'guarded-mcp',
			'guarded-mcp/v1',
			'mcp',
			'Guarded MCP',
			'Guarded WordPress MCP server: get/create/update for pages and posts (always saved as drafts, block allowlist, optional Yoast SEO fields), get/create/update for categories and tags, image upload to the media library through single-use links with get/update of images, menus with dry run and backups, site-health.',
			GUARDED_MCP_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			guarded_mcp_server_abilities(),
			array(), // resources
			array(), // prompts
			function ( \WP_REST_Request $request ) {
				$provided = (string) $request->get_param( 'token' );
				$expected = guarded_mcp_get_token();

				if ( '' === $provided || ! is_string( $expected ) || ! hash_equals( $expected, $provided ) ) {
					return new WP_Error(
						'guarded_mcp_bad_token',
						__( 'Invalid or missing MCP token.', 'guarded-mcp' ),
						array( 'status' => 401 )
					);
				}

				$bot = guarded_mcp_bot_user();
				if ( is_wp_error( $bot ) ) {
					return $bot;
				}

				wp_set_current_user( $bot->ID );
				return true;
			}
		);
	}
);
