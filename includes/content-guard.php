<?php
/**
 * Content guardrails.
 *
 * The trust boundary for anything the bot writes: a recursive block allowlist
 * and a KSES-safe save that only runs AFTER validation. Nothing here exposes
 * an ability — these are the checks the create/update abilities call.
 *
 * Extension points:
 *  - `guarded_mcp_block_namespaces`    — block namespaces allowed as a whole
 *    (your theme's or plugin's blocks, e.g. "acme"). Empty by default.
 *  - `guarded_mcp_trusted_core_blocks` — filter the trusted core/* list.
 *  - `guarded_mcp_is_block_allowed`    — final say per block name.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trusted core blocks allowed in content alongside the allowed namespaces.
 * Deliberately narrow, without the dangerous ones (core/html, core/shortcode,
 * core/freeform). Filterable so trusted blocks can be added without editing code.
 *
 * @return string[]
 */
function guarded_mcp_trusted_core_blocks() {
	$blocks = array(
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/image',
		'core/spacer',
		'core/separator',
		'core/group',
		'core/columns',
		'core/column',
		'core/buttons',
		'core/button',
		// Articles: quotes from external sources and comparison tables.
		'core/quote',
		'core/table',
		// Sections built on core containers: a logo strip holds a gallery, a
		// "latest posts" section holds a Query Loop. They only list content the
		// site already has.
		'core/gallery',
		'core/query',
		'core/post-template',
		'core/query-pagination',
		'core/query-pagination-previous',
		'core/query-pagination-numbers',
		'core/query-pagination-next',
		'core/query-no-results',
	);

	return (array) apply_filters( 'guarded_mcp_trusted_core_blocks', $blocks );
}

/**
 * Block namespaces whose blocks are all allowed — the site's own blocks.
 *
 * Empty by default: out of the box only the trusted core blocks pass. A site adds
 * the namespace of its theme or block plugin, e.g.
 *
 *   add_filter( 'guarded_mcp_block_namespaces', fn( $ns ) => array_merge( $ns, array( 'acme' ) ) );
 *
 * "core" is refused here: core blocks go through the trusted list one by one,
 * so allowing the namespace would let core/html and core/shortcode in.
 *
 * @return string[]
 */
function guarded_mcp_block_namespaces() {
	$namespaces = array();
	foreach ( (array) apply_filters( 'guarded_mcp_block_namespaces', array() ) as $namespace ) {
		$namespace = rtrim( strtolower( trim( (string) $namespace ) ), '/' );
		if ( '' !== $namespace && 'core' !== $namespace && preg_match( '/^[a-z][a-z0-9-]*$/', $namespace ) ) {
			$namespaces[] = $namespace;
		}
	}

	return array_values( array_unique( $namespaces ) );
}

/**
 * Is a block name allowed?
 *
 * Blocks of an allowed namespace (guarded_mcp_block_namespaces()) pass as a
 * whole; core/* only if trusted. The final result is filterable for custom
 * rules — but defaults must stay safe (never widen by accident).
 *
 * @param string $block_name Block name (e.g. acme/hero).
 * @return bool
 */
function guarded_mcp_is_block_allowed( $block_name ) {
	$namespace = strstr( (string) $block_name, '/', true );

	if ( false !== $namespace && in_array( $namespace, guarded_mcp_block_namespaces(), true ) ) {
		$allowed = true;
	} else {
		$allowed = in_array( $block_name, guarded_mcp_trusted_core_blocks(), true );
	}

	return (bool) apply_filters( 'guarded_mcp_is_block_allowed', $allowed, $block_name );
}

/**
 * Recursively assert that EVERY block in content is in the allowlist.
 * Nameless chunks (null) are only allowed if empty/whitespace — raw HTML outside
 * blocks is rejected so it can't be "smuggled" in.
 *
 * @param string $content Serialized block markup (post_content).
 * @return true|WP_Error true if ok; otherwise WP_Error naming the offender.
 */
function guarded_mcp_validate_blocks( $content ) {
	if ( '' === trim( (string) $content ) ) {
		return new WP_Error(
			'guarded_mcp_empty_content',
			__( 'Content is empty: nothing to save.', 'guarded-mcp' )
		);
	}

	$blocks = parse_blocks( $content );

	$walker = function ( $blocks ) use ( &$walker ) {
		foreach ( $blocks as $block ) {
			$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

			if ( null === $name ) {
				// Nameless chunk: allowed only as empty/whitespace.
				if ( '' !== trim( (string) $block['innerHTML'] ) ) {
					return new WP_Error(
						'guarded_mcp_raw_html_not_allowed',
						__( 'Raw HTML outside of blocks is not allowed.', 'guarded-mcp' )
					);
				}
				continue;
			}

			if ( ! guarded_mcp_is_block_allowed( $name ) ) {
				return new WP_Error(
					'guarded_mcp_block_not_allowed',
					sprintf(
						/* translators: %s: block name. */
						__( 'Block "%s" is not in the allowlist (blocks of the allowed namespaces and trusted core/* only; site-health lists both).', 'guarded-mcp' ),
						$name
					),
					array( 'block' => $name )
				);
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$result = $walker( $block['innerBlocks'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}
		return true;
	};

	return $walker( $blocks );
}

/**
 * HTML allowed inside blocks.
 *
 * The KSES rules for post content, the same an Author or Editor without
 * `unfiltered_html` gets. Filter `guarded_mcp_allowed_html` to allow more, for
 * example inline SVG for icon blocks; every addition widens the trust boundary.
 *
 * @return array|string Allowed HTML for wp_kses(): an array of tags, or a context name.
 */
function guarded_mcp_allowed_html() {
	return apply_filters( 'guarded_mcp_allowed_html', wp_kses_allowed_html( 'post' ) );
}

/**
 * Run KSES over one parsed block and its inner blocks.
 *
 * filter_block_kses() in core filters only block attributes, so the HTML of
 * the block (innerContent: the markup around and between inner blocks) is
 * filtered here with wp_kses(). Container blocks split their wrapper into an
 * opening and a closing chunk; wp_kses() handles each chunk on its own.
 *
 * @param array        $block        Parsed block (parse_blocks()).
 * @param array|string $allowed_html Allowed HTML for wp_kses().
 * @return array
 */
function guarded_mcp_kses_block( array $block, $allowed_html ) {
	$block['attrs'] = filter_block_kses_value( is_array( $block['attrs'] ) ? $block['attrs'] : array(), $allowed_html, array(), $block );

	foreach ( $block['innerContent'] as $i => $chunk ) {
		if ( is_string( $chunk ) ) {
			$block['innerContent'][ $i ] = wp_kses( $chunk, $allowed_html );
		}
	}
	$block['innerHTML'] = wp_kses( (string) $block['innerHTML'], $allowed_html );

	foreach ( $block['innerBlocks'] as $i => $inner ) {
		$block['innerBlocks'][ $i ] = guarded_mcp_kses_block( $inner, $allowed_html );
	}

	return $block;
}

/**
 * Filter the HTML of every block in serialised content and serialise it again.
 *
 * @param string $content Serialized block markup.
 * @return string
 */
function guarded_mcp_kses_blocks( $content ) {
	$allowed_html = guarded_mcp_allowed_html();
	$blocks       = parse_blocks( (string) $content );

	foreach ( $blocks as $i => $block ) {
		$blocks[ $i ] = guarded_mcp_kses_block( $block, $allowed_html );
	}

	return serialize_blocks( $blocks );
}

/**
 * Save a post with KSES filters momentarily removed, after filtering the HTML
 * inside its blocks.
 *
 * Why the bypass: WordPress stores block attributes as JSON right inside the
 * delimiter comment (`<!-- wp:... {"title":"...<em>...</em>..."} -->`). Running
 * KSES over the whole post content treats that comment as text and can break the
 * block, so full-content KSES is switched off for this one save. The bot role
 * guarded_mcp_bot is intentionally WITHOUT `unfiltered_html` (everywhere on the
 * site), so the bypass happens only here, and only after the content passed the
 * guarded_mcp_validate_blocks() allowlist.
 *
 * Why the filter before it: the allowlist decides which blocks may appear, not
 * what is inside them. A paragraph can still carry `<script>`, an `onerror`
 * attribute or a `javascript:` link. So the content goes through
 * guarded_mcp_kses_blocks() first: it parses the blocks, runs KSES on the HTML
 * of each block and on its attribute values, and serialises them again, so the
 * delimiters stay intact (ARCHITECTURE.md, invariant 3).
 *
 * $postarr comes unslashed (decoded MCP JSON), while wp_insert_post/wp_update_post
 * expect slashed data and run wp_unslash() on it. Without wp_slash() every backslash
 * is lost: the editor writes `&`, `<`, `"` and `--` inside block delimiters as
 * backslash + `u0026`, `u003c`, `u0022`, `u002d`, and they were stored without the
 * backslash, as plain `u0026` etc. (found in testing).
 *
 * @param array $postarr Args for wp_insert_post/wp_update_post (with ID to update), unslashed.
 * @return int|WP_Error
 */
function guarded_mcp_save_post_raw( $postarr ) {
	if ( isset( $postarr['post_content'] ) ) {
		$postarr['post_content'] = guarded_mcp_kses_blocks( $postarr['post_content'] );
	}

	$postarr = wp_slash( $postarr );
	kses_remove_filters();
	$result = empty( $postarr['ID'] )
		? wp_insert_post( $postarr, true )
		: wp_update_post( $postarr, true );
	kses_init(); // restore KSES filters to the correct state for the current user
	return $result;
}
