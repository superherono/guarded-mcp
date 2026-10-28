<?php
/**
 * PHPUnit bootstrap: WordPress test library from wp-phpunit, the MCP Adapter
 * plugin and this plugin loaded as must-use code.
 *
 * Run inside wp-env: `npm run test:php`. wp-env writes a wp-tests-config.php
 * (database of the tests site) into WP_TESTS_DIR; wp-phpunit is pointed at it.
 *
 * @package guarded-mcp
 */

$guarded_mcp_root = dirname( __DIR__ );

// Composer: PHPUnit Polyfills and wp-phpunit (which sets WP_PHPUNIT__DIR).
require_once $guarded_mcp_root . '/vendor/autoload.php';

$guarded_mcp_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
if ( ! $guarded_mcp_tests_dir || ! is_dir( $guarded_mcp_tests_dir ) ) {
	fwrite( STDERR, "wp-phpunit not found: run `composer install` first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- CLI bootstrap, before WordPress loads.
	exit( 1 );
}

if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) && getenv( 'WP_TESTS_DIR' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', rtrim( (string) getenv( 'WP_TESTS_DIR' ), '/' ) . '/wp-tests-config.php' );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $guarded_mcp_root . '/vendor/yoast/phpunit-polyfills' );

require_once $guarded_mcp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () use ( $guarded_mcp_root ) {
		// The MCP Adapter plugin, installed next to this one by wp-env.
		$adapter = WP_PLUGIN_DIR . '/mcp-adapter/mcp-adapter.php';
		if ( is_readable( $adapter ) ) {
			require_once $adapter;
		}
		require_once $guarded_mcp_root . '/guarded-mcp.php';
	}
);

require $guarded_mcp_tests_dir . '/includes/bootstrap.php';
