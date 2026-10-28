<?php
/**
 * Menu writes: dry run by default, a backup before every save, rollback,
 * safe custom links, no preview fallbacks on production.
 *
 * ARCHITECTURE.md, invariant 8. A menu has no drafts or revisions: a saved
 * change is on every page at once, so these rules are the only undo.
 *
 * @package guarded-mcp
 */

/**
 * Tests for the guarded-mcp/update-menu ability and menus.php.
 */
class MenuUpdateTest extends WP_UnitTestCase {

	/**
	 * Menu location registered for the tests in tests/bootstrap.php.
	 */
	const LOCATION = 'guarded_mcp_test';

	/**
	 * Menu assigned to the test location.
	 *
	 * @var int
	 */
	private $menu_id;

	/**
	 * A menu with two items, "About" (a page) and "Docs" (a custom link), in
	 * the test location; the current user is the bot.
	 */
	public function set_up() {
		parent::set_up();

		$this->assertArrayHasKey( self::LOCATION, get_registered_nav_menus(), 'Precondition: tests/bootstrap.php registers the location.' );

		$page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_title'  => 'About',
				'post_status' => 'publish',
			)
		);

		$this->menu_id = (int) wp_create_nav_menu( 'Test menu' );
		set_theme_mod( 'nav_menu_locations', array( self::LOCATION => $this->menu_id ) );

		wp_update_nav_menu_item(
			$this->menu_id,
			0,
			array(
				'menu-item-object-id' => $page,
				'menu-item-object'    => 'page',
				'menu-item-type'      => 'post_type',
				'menu-item-title'     => 'About',
				'menu-item-status'    => 'publish',
			)
		);
		wp_update_nav_menu_item(
			$this->menu_id,
			0,
			array(
				'menu-item-type'   => 'custom',
				'menu-item-title'  => 'Docs',
				'menu-item-url'    => 'https://example.com/docs/',
				'menu-item-status' => 'publish',
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'guarded_mcp_bot' ) ) );
	}

	/**
	 * Omitting dry_run saves nothing: the menu, its items and the backups stay as they were.
	 */
	public function test_update_menu_is_a_dry_run_by_default() {
		$result = $this->update_menu(
			array(
				'operations' => array( $this->add_custom( 'Blog', 'https://example.com/blog/' ) ),
			)
		);

		$this->assertIsArray( $result );
		$this->assertTrue( $result['dry_run'] );
		$this->assertFalse( $result['saved'] );
		$this->assertSame( '', $result['backup_id'] );
		$this->assertContains( 'Blog', wp_list_pluck( $result['items'], 'label' ), 'The dry run shows the resulting tree.' );

		$this->assertSame( array( 'About', 'Docs' ), $this->labels(), 'Nothing is written.' );
		$this->assertSame( array(), guarded_mcp_menu_backups(), 'A dry run takes no backup.' );
	}

	/**
	 * An explicit dry_run true is a dry run too.
	 */
	public function test_explicit_dry_run_saves_nothing() {
		$result = $this->update_menu(
			array(
				'operations' => array( $this->add_custom( 'Blog', 'https://example.com/blog/' ) ),
				'dry_run'    => true,
			)
		);

		$this->assertFalse( $result['saved'] );
		$this->assertSame( array( 'About', 'Docs' ), $this->labels() );
	}

	/**
	 * A save copies the menu as it was into a backup, then writes.
	 */
	public function test_save_backs_up_the_menu_before_writing() {
		$result = $this->update_menu(
			array(
				'operations' => array( $this->add_custom( 'Blog', 'https://example.com/blog/' ) ),
				'dry_run'    => false,
			)
		);

		$this->assertTrue( $result['saved'] );
		$this->assertNotSame( '', $result['backup_id'] );
		$this->assertSame( array( 'About', 'Docs', 'Blog' ), $this->labels() );

		$backup = guarded_mcp_menu_backup_get( $result['backup_id'] );
		$this->assertIsArray( $backup );
		$this->assertSame( self::LOCATION, $backup['location'] );
		$this->assertSame( 2, $backup['items_count'], 'The backup holds the menu before the change.' );
		$this->assertSame( array( 'post_type', 'custom' ), wp_list_pluck( $backup['items'], 'type' ) );
		$this->assertSame( array( '', 'https://example.com/docs/' ), wp_list_pluck( $backup['items'], 'url' ) );
	}

	/**
	 * Only the last 10 backups of a menu are kept; other menus keep theirs.
	 */
	public function test_only_the_last_ten_backups_per_menu_are_kept() {
		$target = guarded_mcp_menu_target( array( 'location' => self::LOCATION ) );
		$other  = array_merge( $target, array( 'location' => 'other_location' ) );
		$model  = guarded_mcp_menu_model( guarded_mcp_menu_load( $this->menu_id ) );

		$other_id = guarded_mcp_menu_backup_save( $other, $model, 'other menu' );
		$ids      = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$ids[] = guarded_mcp_menu_backup_save( $target, $model, 'save ' . $i );
		}

		$kept = wp_list_pluck( guarded_mcp_menu_backups_for( self::LOCATION, '' ), 'id' );
		$this->assertCount( 10, $kept );
		$this->assertSame( array_reverse( array_slice( $ids, 2 ) ), $kept, 'Newest first, the two oldest dropped.' );
		$this->assertNotNull( guarded_mcp_menu_backup_get( $other_id ), 'Another menu keeps its backup.' );
	}

	/**
	 * Restoring a backup puts the menu back as it was before a save.
	 */
	public function test_restore_backup_puts_the_menu_back() {
		$saved = $this->update_menu(
			array(
				'operations' => array(
					array(
						'op'   => 'remove',
						'item' => $this->item_id( 'Docs' ),
					),
					$this->add_custom( 'Blog', 'https://example.com/blog/' ),
				),
				'dry_run'    => false,
			)
		);
		$this->assertSame( array( 'About', 'Blog' ), $this->labels() );

		$restored = $this->update_menu(
			array(
				'restore_backup' => $saved['backup_id'],
				'dry_run'        => false,
			)
		);

		$this->assertTrue( $restored['saved'] );
		$this->assertSame( array( 'About', 'Docs' ), $this->labels() );
		$docs = $this->item( 'Docs' );
		$this->assertSame( 'https://example.com/docs/', $docs['url'] );
	}

	/**
	 * A backup of another menu location cannot be restored here.
	 */
	public function test_backup_of_another_location_is_refused() {
		$target = guarded_mcp_menu_target( array( 'location' => self::LOCATION ) );
		$model  = guarded_mcp_menu_model( guarded_mcp_menu_load( $this->menu_id ) );
		$id     = guarded_mcp_menu_backup_save( array_merge( $target, array( 'location' => 'other_location' ) ), $model, 'other menu' );

		$result = $this->update_menu( array( 'restore_backup' => $id ) );

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_menu_backup_not_found', $result->get_error_code() );
	}

	/**
	 * A write that fails half-way is rolled back from the backup taken before it.
	 *
	 * The rename of "About" is written first; creating "Boom" then fails. The
	 * menu must end up as it was, not half changed.
	 */
	public function test_failed_write_is_rolled_back() {
		$about_id      = $this->item_id( 'About' );
		$about_at_boom = null;

		add_filter(
			'wp_insert_post_empty_content',
			function ( $maybe_empty, $postarr ) use ( $about_id, &$about_at_boom ) {
				$is_boom = isset( $postarr['post_type'], $postarr['post_title'] ) && 'nav_menu_item' === $postarr['post_type'] && 'Boom' === $postarr['post_title'];
				if ( $is_boom ) {
					$about_at_boom = get_post_field( 'post_title', $about_id );
				}
				return $is_boom ? true : $maybe_empty;
			},
			10,
			2
		);

		$result = $this->update_menu(
			array(
				'operations' => array(
					array(
						'op'    => 'update',
						'item'  => $about_id,
						'label' => 'Renamed',
					),
					$this->add_custom( 'Boom', 'https://example.com/boom/' ),
				),
				'dry_run'    => false,
			)
		);

		$this->assertSame( 'Renamed', $about_at_boom, 'Precondition: the rename was written before the failure.' );
		$this->assertWPError( $result );
		$this->assertStringContainsString( 'put back from backup', $result->get_error_message() );
		$this->assertNotEmpty( $result->get_error_data()['backup_id'] );
		$this->assertSame( array( 'About', 'Docs' ), $this->labels(), 'The rename written before the failure is undone.' );
	}

	/**
	 * Custom URLs that must be refused.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_refused_urls() {
		return array(
			'javascript:'                  => array( 'javascript:alert(1)' ),
			'javascript: in capitals'      => array( 'JavaScript:alert(1)' ),
			'javascript: after a space'    => array( ' javascript:alert(1)' ),
			'javascript: split by a tab'   => array( "java\tscript:alert(1)" ),
			'data:'                        => array( 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==' ),
			'vbscript:'                    => array( 'vbscript:msgbox(1)' ),
			'file:'                        => array( 'file:///etc/passwd' ),
			'protocol-relative //host'     => array( '//evil.example/x' ),
			'//host after a tab'           => array( "/\t/evil.example/x" ),
			'//host after a newline'       => array( "/\n/evil.example/x" ),
			'/\\host (a browser reads //)' => array( '/\\evil.example/x' ),
			'\\\\host'                     => array( '\\\\evil.example/x' ),
		);
	}

	/**
	 * A custom link may only be http(s), mailto:, tel:, a root-relative path or #anchor.
	 *
	 * @dataProvider data_refused_urls
	 *
	 * @param string $url URL.
	 */
	public function test_unsafe_custom_url_is_refused( $url ) {
		$result = guarded_mcp_menu_check_url( $url, 'link' );

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_menu_url_invalid', $result->get_error_code() );
	}

	/**
	 * Custom URLs that stay allowed.
	 *
	 * @return array<string,array{string}>
	 */
	public function data_allowed_urls() {
		return array(
			'https'         => array( 'https://example.com/a?b=1&c=2#d' ),
			'http'          => array( 'http://example.com' ),
			'mailto:'       => array( 'mailto:hello@example.com' ),
			'tel:'          => array( 'tel:+302100000000' ),
			'root-relative' => array( '/about/team/' ),
			'front page'    => array( '/' ),
			'anchor'        => array( '#contact' ),
		);
	}

	/**
	 * The allowed forms pass unchanged.
	 *
	 * @dataProvider data_allowed_urls
	 *
	 * @param string $url URL.
	 */
	public function test_safe_custom_url_is_allowed( $url ) {
		$this->assertSame( $url, guarded_mcp_menu_check_url( $url, 'link' ) );
	}

	/**
	 * An unsafe link stops the whole call, even with dry_run false: nothing is saved.
	 */
	public function test_unsafe_link_in_update_menu_saves_nothing() {
		$result = $this->update_menu(
			array(
				'operations' => array(
					$this->add_custom( 'Fine', 'https://example.com/fine/' ),
					$this->add_custom( 'Evil', 'javascript:alert(1)' ),
				),
				'dry_run'    => false,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_menu_url_invalid', $result->get_error_code() );
		$this->assertSame( array( 'About', 'Docs' ), $this->labels() );
		$this->assertSame( array(), guarded_mcp_menu_backups() );
	}

	/**
	 * The preview_fallbacks option is refused on production, even for a dry run.
	 *
	 * The tests run with WP_ENVIRONMENT_TYPE production (.wp-env.json, tests
	 * environment): wp_get_environment_type() keeps its first answer for the
	 * whole process, so it cannot be switched inside one test.
	 */
	public function test_preview_fallbacks_is_refused_on_production() {
		$this->assertSame( 'production', wp_get_environment_type(), 'Precondition: the tests run as production.' );

		$result = $this->update_menu(
			array(
				'replace'           => array(
					array(
						'label' => 'Missing page',
						'link'  => array(
							'type' => 'page',
							'path' => 'does-not-exist',
						),
					),
				),
				'preview_fallbacks' => true,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'guarded_mcp_menu_fallbacks_production', $result->get_error_code() );
		$this->assertSame( array( 'About', 'Docs' ), $this->labels() );
	}

	/**
	 * Run update-menu on the test location.
	 *
	 * @param array $input Input without the location.
	 * @return array|WP_Error
	 */
	private function update_menu( array $input ) {
		$ability = wp_get_ability( 'guarded-mcp/update-menu' );
		$this->assertInstanceOf( WP_Ability::class, $ability );

		return $ability->execute( array_merge( array( 'location' => self::LOCATION ), $input ) );
	}

	/**
	 * An "add" operation for a custom link.
	 *
	 * @param string $label Label.
	 * @param string $url   URL.
	 * @return array
	 */
	private function add_custom( $label, $url ) {
		return array(
			'op'    => 'add',
			'label' => $label,
			'link'  => array(
				'type' => 'custom',
				'url'  => $url,
			),
		);
	}

	/**
	 * Stored items of the test menu, in menu order.
	 *
	 * @return array[]
	 */
	private function items() {
		$items = array_values( guarded_mcp_menu_load( $this->menu_id ) );
		usort(
			$items,
			function ( $a, $b ) {
				return $a['order'] - $b['order'];
			}
		);
		return $items;
	}

	/**
	 * Labels of the stored items as the site shows them, in menu order. A page
	 * link whose label equals the page title is stored with an empty title.
	 *
	 * @return string[]
	 */
	private function labels() {
		return wp_list_pluck( $this->items(), 'shown_title' );
	}

	/**
	 * A stored item by its label.
	 *
	 * @param string $label Label as shown.
	 * @return array
	 */
	private function item( $label ) {
		foreach ( $this->items() as $item ) {
			if ( $item['shown_title'] === $label ) {
				return $item;
			}
		}
		$this->fail( 'No menu item "' . $label . '".' );
	}

	/**
	 * ID of a stored item by its label.
	 *
	 * @param string $label Label.
	 * @return int
	 */
	private function item_id( $label ) {
		return (int) $this->item( $label )['id'];
	}
}
