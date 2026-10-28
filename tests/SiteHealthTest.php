<?php
/**
 * The site-health ability takes no input and never shows a server path.
 *
 * ARCHITECTURE.md, invariant 6: the diagnostics never accept a path from the
 * client, so it cannot be turned into a file reader.
 *
 * @package guarded-mcp
 */

/**
 * Tests for the guarded-mcp/site-health ability.
 */
class SiteHealthTest extends WP_UnitTestCase {

	/**
	 * Run as a user who may call it.
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'guarded_mcp_bot' ) ) );
	}

	/**
	 * The ability.
	 *
	 * @return WP_Ability
	 */
	private function ability() {
		$ability = wp_get_ability( 'guarded-mcp/site-health' );
		$this->assertInstanceOf( WP_Ability::class, $ability );
		return $ability;
	}

	/**
	 * The ability declares no input at all.
	 */
	public function test_site_health_has_no_input_schema() {
		$this->assertSame( array(), $this->ability()->get_input_schema() );
	}

	/**
	 * Inputs a client could try.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function data_inputs() {
		return array(
			'path to a file' => array( array( 'path' => '/etc/passwd' ) ),
			'relative path'  => array( array( 'file' => '../../wp-config.php' ) ),
			'bare string'    => array( 'wp-config.php' ),
			'empty object'   => array( array() ),
			'list of files'  => array( array( 'files' => array( 'wp-config.php' ) ) ),
		);
	}

	/**
	 * Any input is refused before the ability runs.
	 *
	 * @dataProvider data_inputs
	 *
	 * @param mixed $input Input.
	 */
	public function test_site_health_refuses_any_input( $input ) {
		$ran = false;
		add_filter(
			'guarded_mcp_trusted_core_blocks',
			function ( $blocks ) use ( &$ran ) {
				$ran = true;
				return $blocks;
			}
		);

		$result = $this->ability()->execute( $input );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_missing_input_schema', $result->get_error_code() );
		$this->assertFalse( $ran, 'The diagnostics must not run with input.' );
	}

	/**
	 * Without input it reports on the plugin and lists its 15 abilities.
	 */
	public function test_site_health_runs_without_input() {
		$result = $this->ability()->execute();

		$this->assertIsArray( $result );
		$this->assertTrue( $result['all_ok'], 'Every linted PHP file must parse.' );
		$this->assertSame( GUARDED_MCP_VERSION, $result['plugin_version'] );
		$this->assertCount( 15, $result['abilities'] );

		$exposed = guarded_mcp_server_abilities();
		sort( $exposed );
		$this->assertSame( $exposed, $result['abilities'], 'The custom server exposes exactly the registered abilities.' );
	}

	/**
	 * Lint results name files relative to wp-content or to the plugin's parent
	 * folder, never with a server path.
	 */
	public function test_site_health_output_has_no_server_paths() {
		$result = $this->ability()->execute();
		$json   = (string) wp_json_encode( $result );

		$this->assertNotEmpty( $result['lint'] );
		foreach ( array( ABSPATH, WP_CONTENT_DIR, GUARDED_MCP_DIR ) as $path ) {
			$normalized = wp_normalize_path( $path );
			$this->assertStringNotContainsString( $normalized, $json );
			$this->assertStringNotContainsString( str_replace( '/', '\\/', $normalized ), $json );
		}

		$own = wp_basename( GUARDED_MCP_DIR ) . '/includes/content-guard.php';
		$this->assertNotEmpty(
			array_filter(
				$result['lint'],
				function ( $row ) use ( $own ) {
					return substr( $row['file'], -strlen( $own ) ) === $own;
				}
			),
			'The plugin files are linted and named relatively.'
		);
	}

	/**
	 * Inside wp-content, or in a plugin folder outside it (a symlink, a custom
	 * WP_PLUGIN_DIR), the path is relative; anything else is reduced to a name.
	 */
	public function test_relative_path_is_never_absolute() {
		$relative = guarded_mcp_health_relative_path( wp_normalize_path( GUARDED_MCP_DIR ) . '/includes/support.php' );

		$this->assertStringEndsWith( wp_basename( GUARDED_MCP_DIR ) . '/includes/support.php', $relative );
		$this->assertDoesNotMatchRegularExpression( '#^(/|[A-Za-z]:)#', $relative );
		$this->assertSame( 'secret.php', guarded_mcp_health_relative_path( '/somewhere/else/secret.php' ) );
	}
}
