<?php
/**
 * Plugin Name:       Guarded MCP
 * Description:       A security-first MCP server for WordPress. AI agents get 15 narrow tools for pages, posts, terms, media and menus, inside limits that hold even when the model is wrong: drafts only, no deletes, a block allowlist, single-use upload links, menu dry runs with backups.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  mcp-adapter
 * Author:            Dmitrii Morozan
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       guarded-mcp
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

define( 'GUARDED_MCP_VERSION', '0.1.0' );
define( 'GUARDED_MCP_DIR', __DIR__ );

// Order matters: helpers the abilities depend on first, then the server, then
// the ability definitions. Everything after that runs on hooks.
require_once __DIR__ . '/includes/support.php';
require_once __DIR__ . '/includes/content-guard.php';
require_once __DIR__ . '/includes/posts.php';
require_once __DIR__ . '/includes/seo.php';
require_once __DIR__ . '/includes/terms.php';
require_once __DIR__ . '/includes/media.php';
require_once __DIR__ . '/includes/menus.php';
require_once __DIR__ . '/includes/access.php';
require_once __DIR__ . '/includes/server.php';
require_once __DIR__ . '/includes/media-upload.php';

// Auto-load every ability definition. Drop a new file in includes/abilities/ to
// add one; it registers itself and (via the server filter) exposes itself on
// the server.
foreach ( (array) glob( __DIR__ . '/includes/abilities/*.php' ) as $guarded_mcp_ability_file ) {
	require_once $guarded_mcp_ability_file;
}
unset( $guarded_mcp_ability_file );
