<?php
/**
 * The token of the custom MCP server and the bot it runs requests as.
 *
 * ARCHITECTURE.md, invariant 5: the token is compared with hash_equals(), and
 * requests run as a dedicated low-privilege bot; a bot user with admin-level,
 * publishing or deleting capabilities is refused instead of trusted.
 *
 * @package guarded-mcp
 */

/**
 * Tests for guarded_mcp_get_token(), guarded_mcp_server_permission(),
 * guarded_mcp_bot_user() and the bot role.
 */
class BotAccessTest extends WP_UnitTestCase {

	/**
	 * Capabilities that make guarded_mcp_bot_user() refuse the bot.
	 *
	 * @var string[]
	 */
	const TOO_STRONG = array(
		'unfiltered_html',
		'manage_options',
		'edit_theme_options',
		'publish_pages',
		'publish_posts',
		'delete_posts',
		'delete_pages',
		'delete_others_posts',
		'delete_others_pages',
	);

	/**
	 * The PHP error_log destination before the test.
	 *
	 * @var string|false
	 */
	private $error_log;

	/**
	 * A fresh token for every test. The adapter logs every refused request with
	 * error_log(); that goes to a file instead of the test output.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( 'guarded_mcp_token' );
		$this->error_log = ini_set( 'error_log', get_temp_dir() . 'guarded-mcp-tests.log' ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Test-only: keeps the adapter's refusal log out of the test output.
	}

	/**
	 * Put the error_log destination back.
	 */
	public function tear_down() {
		ini_set( 'error_log', (string) $this->error_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value saved in set_up().
		parent::tear_down();
	}

	/**
	 * The token is generated once: 48 letters and digits, not autoloaded.
	 */
	public function test_token_is_generated_once_and_not_autoloaded() {
		$token = guarded_mcp_get_token();

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{48}$/', $token );
		$this->assertSame( $token, guarded_mcp_get_token(), 'The second call must return the stored token.' );
		$this->assertArrayNotHasKey( 'guarded_mcp_token', wp_load_alloptions(), 'The token must not be loaded on every request.' );
	}

	/**
	 * A stored value that is not a string is replaced, so hash_equals() always gets a string.
	 */
	public function test_a_broken_token_option_is_replaced() {
		update_option( 'guarded_mcp_token', array( 'not', 'a', 'token' ), false );

		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{48}$/', guarded_mcp_get_token() );
	}

	/**
	 * Tokens that must not open the server.
	 *
	 * @return array<string,array{mixed}>
	 */
	public function data_wrong_tokens() {
		return array(
			'missing'           => array( null ),
			'empty'             => array( '' ),
			'random'            => array( 'x' ),
			'array parameter'   => array( array( 'token' ) ),
			'prefix'            => array( 'prefix' ),
			'one extra char'    => array( 'extra' ),
			'other case'        => array( 'case' ),
			'surrounding space' => array( 'space' ),
		);
	}

	/**
	 * A wrong token is refused with 401 and the request stays anonymous.
	 *
	 * @dataProvider data_wrong_tokens
	 *
	 * @param mixed $kind Token, or the name of a variant of the real one.
	 */
	public function test_wrong_token_is_refused( $kind ) {
		$this->create_bot();
		$token = guarded_mcp_get_token();

		$variants = array(
			'prefix' => substr( $token, 0, -1 ),
			'extra'  => $token . 'a',
			'case'   => strtolower( $token ) === $token ? strtoupper( $token ) : strtolower( $token ),
			'space'  => ' ' . $token . ' ',
		);
		$given    = is_string( $kind ) && isset( $variants[ $kind ] ) ? $variants[ $kind ] : $kind;

		$result = guarded_mcp_server_permission( $this->mcp_request( $given ) );

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_bad_token', $result->get_error_code() );
		$this->assertSame( 401, $result->get_error_data()['status'] );
		$this->assertSame( 0, get_current_user_id(), 'A refused request must not log anyone in.' );
	}

	/**
	 * The right token runs the request as the bot.
	 */
	public function test_valid_token_runs_the_request_as_the_bot() {
		$bot_id = $this->create_bot();

		$result = guarded_mcp_server_permission( $this->mcp_request( guarded_mcp_get_token() ) );

		$this->assertTrue( $result );
		$this->assertSame( $bot_id, get_current_user_id() );
	}

	/**
	 * Without the bot user the server answers with an error, not as someone else.
	 */
	public function test_valid_token_without_a_bot_user_is_refused() {
		$result = guarded_mcp_server_permission( $this->mcp_request( guarded_mcp_get_token() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_no_bot_user', $result->get_error_code() );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Each too-strong capability.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_too_strong_caps() {
		$data = array();
		foreach ( self::TOO_STRONG as $cap ) {
			$data[ $cap ] = array( $cap );
		}
		return $data;
	}

	/**
	 * A bot that was given one too-strong capability by mistake stops the server.
	 *
	 * @dataProvider data_too_strong_caps
	 *
	 * @param string $cap Capability added to the bot user.
	 */
	public function test_bot_with_a_too_strong_capability_is_refused( $cap ) {
		$bot = get_user_by( 'id', $this->create_bot() );
		$bot->add_cap( $cap );

		$result = guarded_mcp_server_permission( $this->mcp_request( guarded_mcp_get_token() ) );

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_bot_too_privileged', $result->get_error_code() );
		$this->assertStringContainsString( $cap, $result->get_error_message() );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Built-in roles added to the bot.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_strong_roles() {
		return array(
			'administrator' => array( 'administrator' ),
			'editor'        => array( 'editor' ),
			'author'        => array( 'author' ),
		);
	}

	/**
	 * A bot that also has a built-in role with publishing rights is refused.
	 *
	 * @dataProvider data_strong_roles
	 *
	 * @param string $role Role added next to the bot role.
	 */
	public function test_bot_with_an_extra_role_is_refused( $role ) {
		$bot = get_user_by( 'id', $this->create_bot() );
		$bot->add_role( $role );

		$this->assertWPError( guarded_mcp_bot_user() );
		$this->assertWPError( guarded_mcp_server_permission( $this->mcp_request( guarded_mcp_get_token() ) ) );
	}

	/**
	 * A capability added to the role itself (not the user) also stops the server.
	 */
	public function test_too_strong_capability_on_the_role_is_refused() {
		$role = get_role( 'guarded_mcp_bot' );
		$role->add_cap( 'publish_pages' );
		$this->create_bot();

		$result = guarded_mcp_bot_user();

		$role->remove_cap( 'publish_pages' );
		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_bot_too_privileged', $result->get_error_code() );
	}

	/**
	 * The role holds exactly the listed capabilities, and none of them is too strong.
	 */
	public function test_bot_role_has_no_publish_delete_or_unfiltered_html() {
		$caps = array_keys( array_filter( get_role( 'guarded_mcp_bot' )->capabilities ) );
		sort( $caps );

		$expected = array_keys( array_filter( guarded_mcp_bot_caps() ) );
		sort( $expected );

		$this->assertSame( $expected, $caps );
		$this->assertSame( array(), array_intersect( self::TOO_STRONG, $caps ) );
		foreach ( $caps as $cap ) {
			$this->assertDoesNotMatchRegularExpression( '/^(publish|delete)_/', $cap );
		}
	}

	/**
	 * The role as shipped passes the bot's own privilege check.
	 */
	public function test_user_with_only_the_bot_role_is_accepted() {
		$bot_id = $this->create_bot();

		$bot = guarded_mcp_bot_user();

		$this->assertInstanceOf( WP_User::class, $bot );
		$this->assertSame( $bot_id, $bot->ID );
	}

	/**
	 * A role sync after a version change takes off capabilities that left the
	 * list and unfiltered_html, and puts back the listed ones.
	 */
	public function test_role_sync_removes_capabilities_that_left_the_list() {
		$role = get_role( 'guarded_mcp_bot' );
		$role->add_cap( 'publish_pages' );
		$role->add_cap( 'unfiltered_html' );
		$role->remove_cap( 'upload_files' );
		update_option( 'guarded_mcp_role_version', '0' );

		guarded_mcp_sync_bot_role();

		$role = get_role( 'guarded_mcp_bot' );
		$this->assertArrayNotHasKey( 'publish_pages', $role->capabilities );
		$this->assertArrayNotHasKey( 'unfiltered_html', $role->capabilities );
		$this->assertTrue( $role->has_cap( 'upload_files' ) );
		$this->assertNotSame( '0', get_option( 'guarded_mcp_role_version' ) );
	}

	/**
	 * Through the REST route: no token → 401 before the adapter sees the request.
	 */
	public function test_mcp_endpoint_refuses_a_request_without_token() {
		$this->create_bot();

		$response = rest_get_server()->dispatch( $this->mcp_request( null, $this->initialize_body() ) );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Through the REST route: the right token opens an MCP session as the bot.
	 */
	public function test_mcp_endpoint_accepts_the_token() {
		$bot_id = $this->create_bot();

		$response = rest_get_server()->dispatch( $this->mcp_request( guarded_mcp_get_token(), $this->initialize_body() ) );
		$data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'result', $data );
		$this->assertArrayHasKey( 'protocolVersion', $data['result'] );
		$this->assertSame( $bot_id, get_current_user_id() );
	}

	/**
	 * Through the REST route: the right token with an over-privileged bot → 401.
	 */
	public function test_mcp_endpoint_refuses_an_administrator_bot() {
		$bot = get_user_by( 'id', $this->create_bot() );
		$bot->set_role( 'administrator' );

		$response = rest_get_server()->dispatch( $this->mcp_request( guarded_mcp_get_token(), $this->initialize_body() ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 0, get_current_user_id() );
	}

	/**
	 * Create the bot user with only the bot role.
	 *
	 * @return int User ID.
	 */
	private function create_bot() {
		return self::factory()->user->create(
			array(
				'user_login' => guarded_mcp_bot_login(),
				'role'       => 'guarded_mcp_bot',
			)
		);
	}

	/**
	 * A POST to the custom MCP endpoint.
	 *
	 * @param mixed      $token Value of the token parameter; null for none.
	 * @param array|null $body  JSON-RPC body.
	 * @return WP_REST_Request
	 */
	private function mcp_request( $token, $body = null ) {
		$request = new WP_REST_Request( 'POST', '/guarded-mcp/v1/mcp' );
		if ( null !== $token ) {
			$request->set_query_params( array( 'token' => $token ) );
		}
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_header( 'Accept', 'application/json, text/event-stream' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return $request;
	}

	/**
	 * JSON-RPC initialize request.
	 *
	 * @return array
	 */
	private function initialize_body() {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-06-18',
				'capabilities'    => new stdClass(),
				'clientInfo'      => array(
					'name'    => 'phpunit',
					'version' => '1',
				),
			),
		);
	}
}
