<?php
/**
 * HTML inside allowed blocks is filtered before the KSES-free save.
 *
 * ARCHITECTURE.md, invariant 3: the block allowlist decides which blocks may
 * appear, guarded_mcp_kses_blocks() decides what may be inside them.
 *
 * @package guarded-mcp
 */

/**
 * Tests for guarded_mcp_kses_blocks() and guarded_mcp_save_post_raw().
 */
class KsesBlocksTest extends WP_UnitTestCase {

	/**
	 * Script tags, event handlers and javascript: links that must not survive.
	 *
	 * @return array<string,array{string,string}> Markup and a fragment that must be gone.
	 */
	public function data_dangerous_html() {
		return array(
			'script in a paragraph'       => array(
				'<!-- wp:paragraph --><p>Hi<script>alert(1)</script></p><!-- /wp:paragraph -->',
				'<script',
			),
			'event handler on an image'   => array(
				'<!-- wp:paragraph --><p>Hi<img src="x" onerror="alert(2)"></p><!-- /wp:paragraph -->',
				'onerror',
			),
			'javascript: link in heading' => array(
				'<!-- wp:heading --><h2 class="wp-block-heading"><a href="javascript:alert(3)">Go</a></h2><!-- /wp:heading -->',
				'javascript:',
			),
			'iframe in a list item'       => array(
				'<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>One<iframe src="https://evil.example"></iframe></li><!-- /wp:list-item --></ul><!-- /wp:list -->',
				'<iframe',
			),
			'style with expression'       => array(
				'<!-- wp:paragraph --><p><span style="background:url(javascript:alert(4))">x</span></p><!-- /wp:paragraph -->',
				'javascript:',
			),
		);
	}

	/**
	 * Each dangerous fragment is removed; the block and its text stay.
	 *
	 * @dataProvider data_dangerous_html
	 *
	 * @param string $markup   Block markup.
	 * @param string $fragment What must not be in the result.
	 */
	public function test_dangerous_html_inside_an_allowed_block_is_removed( $markup, $fragment ) {
		$filtered = guarded_mcp_kses_blocks( $markup );

		$this->assertStringNotContainsStringIgnoringCase( $fragment, $filtered );
		$this->assertSame( $this->block_names( $markup ), $this->block_names( $filtered ), 'The block structure must stay the same.' );
	}

	/**
	 * HTML inside blocks nested in containers is filtered too.
	 */
	public function test_html_in_nested_blocks_is_filtered() {
		$markup = '<!-- wp:group --><div class="wp-block-group">'
			. '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column --><div class="wp-block-column">'
			. '<!-- wp:paragraph --><p>Deep<script>alert(1)</script><a href="javascript:alert(2)" onclick="alert(3)">link</a></p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->'
			. '</div><!-- /wp:group -->';

		$filtered = guarded_mcp_kses_blocks( $markup );

		$this->assertStringNotContainsString( '<script', $filtered );
		$this->assertStringNotContainsString( 'javascript:', $filtered );
		$this->assertStringNotContainsString( 'onclick', $filtered );
		$this->assertStringContainsString( 'Deep', $filtered );
		$this->assertSame(
			array( 'core/group', 'core/columns', 'core/column', 'core/paragraph' ),
			$this->block_names( $filtered )
		);
	}

	/**
	 * The HTML of a container block comes in an opening and a closing chunk;
	 * a handler on the wrapper is removed from the opening one.
	 */
	public function test_event_handler_on_a_container_wrapper_is_removed() {
		$markup = '<!-- wp:group --><div class="wp-block-group" onmouseover="alert(1)">'
			. '<!-- wp:paragraph --><p>Inside</p><!-- /wp:paragraph -->'
			. '</div><!-- /wp:group -->';

		$filtered = guarded_mcp_kses_blocks( $markup );

		$this->assertStringNotContainsString( 'onmouseover', $filtered );
		$this->assertStringContainsString( '<div class="wp-block-group">', $filtered );
		$this->assertStringContainsString( '<p>Inside</p>', $filtered );
	}

	/**
	 * HTML in block attributes (the JSON in the delimiter) is filtered.
	 */
	public function test_html_in_block_attributes_is_filtered() {
		// Encoded as the editor does it: "<" and ">" become unicode escapes.
		$attrs  = serialize_block_attributes( array( 'placeholder' => '<img src=x onerror=alert(1)>' ) );
		$markup = '<!-- wp:paragraph ' . $attrs . ' --><p>Text</p><!-- /wp:paragraph -->';

		$blocks = parse_blocks( guarded_mcp_kses_blocks( $markup ) );

		$this->assertSame( 'core/paragraph', $blocks[0]['blockName'] );
		$this->assertStringNotContainsString( 'onerror', $blocks[0]['attrs']['placeholder'] );
		$this->assertStringContainsString( '<img', $blocks[0]['attrs']['placeholder'] );
	}

	/**
	 * Well-formed core markup goes through unchanged: the filter must not cost
	 * an agent its layout, links, entities or quotes.
	 */
	public function test_well_formed_core_markup_is_unchanged() {
		$markup = implode(
			"\n\n",
			array(
				'<!-- wp:heading {"level":1} -->' . "\n" . '<h1 class="wp-block-heading">Fish &amp; chips</h1>' . "\n" . '<!-- /wp:heading -->',
				'<!-- wp:group {"layout":{"type":"constrained"}} -->' . "\n" . '<div class="wp-block-group">'
					. '<!-- wp:columns -->' . "\n" . '<div class="wp-block-columns">'
					. '<!-- wp:column -->' . "\n" . '<div class="wp-block-column">'
					. '<!-- wp:paragraph -->' . "\n" . '<p>Say "hello" &amp; <a href="https://example.com/?a=1&amp;b=2">read more</a>, it\'s <strong>fine</strong>.</p>' . "\n" . '<!-- /wp:paragraph -->'
					. '</div>' . "\n" . '<!-- /wp:column -->'
					. '<!-- wp:column -->' . "\n" . '<div class="wp-block-column">'
					. '<!-- wp:quote {"className":"is-style-plain"} -->' . "\n" . '<blockquote class="wp-block-quote is-style-plain"><!-- wp:paragraph -->' . "\n" . '<p>A quote</p>' . "\n" . '<!-- /wp:paragraph --><cite>Someone</cite></blockquote>' . "\n" . '<!-- /wp:quote -->'
					. '</div>' . "\n" . '<!-- /wp:column -->'
					. '</div>' . "\n" . '<!-- /wp:columns -->'
					. '</div>' . "\n" . '<!-- /wp:group -->',
				'<!-- wp:list -->' . "\n" . '<ul class="wp-block-list"><!-- wp:list-item -->' . "\n" . '<li>One</li>' . "\n" . '<!-- /wp:list-item --></ul>' . "\n" . '<!-- /wp:list -->',
				'<!-- wp:buttons -->' . "\n" . '<div class="wp-block-buttons"><!-- wp:button -->' . "\n" . '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="/contact/">Contact</a></div>' . "\n" . '<!-- /wp:button --></div>' . "\n" . '<!-- /wp:buttons -->',
				'<!-- wp:image {"sizeSlug":"large"} -->' . "\n" . '<figure class="wp-block-image size-large"><img src="https://example.com/a.png" alt="A &quot;quoted&quot; alt"/></figure>' . "\n" . '<!-- /wp:image -->',
			)
		);

		$this->assertSame( $markup, serialize_blocks( parse_blocks( $markup ) ), 'The fixture must already be in serialised form.' );

		// The one change wp_kses() makes: a void tag is written "<img … />", the
		// editor writes "<img …/>". Block validation treats both as the same
		// HTML; the E2E tests open such content in the editor.
		$this->assertSame( str_replace( '"/>', '" />', $markup ), guarded_mcp_kses_blocks( $markup ) );
	}

	/**
	 * The filter guarded_mcp_allowed_html widens the rules, and only when added.
	 */
	public function test_allowed_html_filter_decides_what_stays() {
		$markup = '<!-- wp:paragraph --><p>Map <iframe src="https://maps.example/embed"></iframe></p><!-- /wp:paragraph -->';

		$this->assertStringNotContainsString( '<iframe', guarded_mcp_kses_blocks( $markup ), 'No iframe by default.' );

		add_filter(
			'guarded_mcp_allowed_html',
			function ( $allowed ) {
				$allowed['iframe'] = array( 'src' => true );
				return $allowed;
			}
		);

		$this->assertStringContainsString( '<iframe src="https://maps.example/embed">', guarded_mcp_kses_blocks( $markup ) );
	}

	/**
	 * A post saved by the bot holds the filtered HTML, with its block delimiters intact.
	 */
	public function test_save_post_raw_stores_filtered_html() {
		wp_set_current_user( $this->bot_user_id() );

		$post_id = guarded_mcp_save_post_raw(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Filtered',
				'post_content' => '<!-- wp:paragraph --><p>Hi<script>alert(1)</script><img src="x" onerror="alert(2)"></p><!-- /wp:paragraph -->',
			)
		);

		$this->assertIsInt( $post_id );
		$stored = get_post( $post_id )->post_content;
		$this->assertStringNotContainsString( '<script', $stored );
		$this->assertStringNotContainsString( 'onerror', $stored );
		$this->assertSame( array( 'core/paragraph' ), $this->block_names( $stored ) );
	}

	/**
	 * Backslashes in block attributes survive the save (wp_slash() before
	 * wp_insert_post()). The delimiter JSON writes "--" and quotes as unicode
	 * escapes (a backslash, "u002d"); a lost backslash leaves a plain "u002d"
	 * in the attribute (found in testing before 0.1.0).
	 */
	public function test_save_post_raw_keeps_unicode_escapes_in_attributes() {
		wp_set_current_user( $this->bot_user_id() );

		$attrs  = serialize_block_attributes( array( 'metadata' => array( 'name' => 'A -- "B"' ) ) );
		$markup = '<!-- wp:paragraph ' . $attrs . ' --><p>Text</p><!-- /wp:paragraph -->';

		$this->assertStringNotContainsString( '--', $attrs, 'Precondition: the fixture holds escapes, not "--".' );

		$post_id = guarded_mcp_save_post_raw(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Escapes',
				'post_content' => $markup,
			)
		);

		$stored = get_post( $post_id )->post_content;
		$blocks = parse_blocks( $stored );

		$this->assertStringContainsString( $attrs, $stored );
		$this->assertSame( 'A -- "B"', $blocks[0]['attrs']['metadata']['name'] );
	}

	/**
	 * The KSES bypass is for one save only: the filters are back afterwards.
	 */
	public function test_kses_filters_are_restored_after_the_save() {
		wp_set_current_user( $this->bot_user_id() );
		kses_init();
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Precondition: KSES is on for the bot.' );

		guarded_mcp_save_post_raw(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => 'Restore',
				'post_content' => '<!-- wp:paragraph --><p>Text</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	/**
	 * Names of every block in the content, depth first.
	 *
	 * @param string $content Block markup.
	 * @return string[]
	 */
	private function block_names( $content ) {
		$names = array();
		$walk  = function ( array $blocks ) use ( &$walk, &$names ) {
			foreach ( $blocks as $block ) {
				if ( null !== $block['blockName'] ) {
					$names[] = $block['blockName'];
				}
				$walk( $block['innerBlocks'] );
			}
		};
		$walk( parse_blocks( $content ) );

		return $names;
	}

	/**
	 * A user with only the bot role: no unfiltered_html.
	 *
	 * @return int
	 */
	private function bot_user_id() {
		return self::factory()->user->create( array( 'role' => 'guarded_mcp_bot' ) );
	}
}
