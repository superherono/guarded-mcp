/**
 * Markup saved through the plugin's abilities opens in the block editor
 * without a block validation error ("This block contains unexpected or
 * invalid content").
 *
 * The pages are written over MCP with the bot's token, as an agent writes
 * them, and opened in the editor as the administrator.
 */
const { test, expect } = require( '@playwright/test' );
const { mcpSession, openInEditor, blockReport, watchValidationMessages } = require( './helpers' );

/**
 * Open a new-page editor, where the core blocks are registered, so fixtures
 * can be built with the editor's own serializer: exactly what it would save.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function openBlockLibrary( page ) {
	await page.goto( '/wp-admin/post-new.php?post_type=page' );
	await page.waitForFunction( () => window.wp && window.wp.blocks && window.wp.blocks.getBlockType( 'core/paragraph' ) );
}

/**
 * The stored markup of the open post, as the editor loaded it.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<string>} Markup.
 */
function editorContent( page ) {
	return page.evaluate( () => window.wp.data.select( 'core/editor' ).getEditedPostContent() );
}

/**
 * The parsed blocks of the open post, serialised again by the editor, next to
 * the markup that was sent; both as the browser's HTML serialisation.
 *
 * KSES writes HTML its own way: "/>" becomes " />", a ">" in text becomes
 * "&gt;". Rich text keeps the HTML it was parsed from, so those spellings
 * carry over. The browser writes both spellings the same, while the block
 * delimiters (comments with the attribute JSON) stay byte for byte. Equal
 * results mean every block, attribute and text survived.
 *
 * @param {import('@playwright/test').Page} page   Page with the editor open.
 * @param {string}                          markup Markup that was sent.
 * @return {Promise<{sent: string, opened: string}>} Both, normalised.
 */
function sentAndOpened( page, markup ) {
	return page.evaluate( ( sent ) => {
		const normalise = ( html ) => {
			const template = document.createElement( 'template' );
			template.innerHTML = html;
			return template.innerHTML;
		};
		const opened = window.wp.blocks.serialize( window.wp.data.select( 'core/block-editor' ).getBlocks() );
		return { sent: normalise( sent ), opened: normalise( opened ) };
	}, markup );
}

test( 'nested core blocks saved with create-page open without validation errors', async ( { page, playwright, baseURL } ) => {
	const messages = watchValidationMessages( page );

	await openBlockLibrary( page );
	const markup = await page.evaluate( () => {
		const { createBlock, serialize } = window.wp.blocks;
		return serialize( [
			createBlock( 'core/heading', { level: 2, content: 'Fish &amp; chips' } ),
			createBlock( 'core/group', { layout: { type: 'constrained' } }, [
				createBlock( 'core/columns', {}, [
					createBlock( 'core/column', {}, [
						createBlock( 'core/paragraph', {
							content: 'Say "hello" &amp; <a href="https://example.com/?a=1&amp;b=2">read more</a>, it is <strong>fine</strong>.',
						} ),
						createBlock( 'core/list', {}, [
							createBlock( 'core/list-item', { content: 'One' } ),
							createBlock( 'core/list-item', { content: 'Two' } ),
						] ),
					] ),
					createBlock( 'core/column', {}, [
						createBlock( 'core/quote', { citation: 'Someone' }, [ createBlock( 'core/paragraph', { content: 'A quote' } ) ] ),
						createBlock( 'core/image', { url: 'https://example.com/a.png', alt: 'A "quoted" alt', sizeSlug: 'large' } ),
					] ),
				] ),
			] ),
			createBlock( 'core/buttons', {}, [ createBlock( 'core/button', { text: 'Contact', url: '/contact/' } ) ] ),
			createBlock( 'core/separator' ),
			createBlock( 'core/spacer', { height: '40px' } ),
			createBlock( 'core/table', {
				body: [ { cells: [ { content: 'a', tag: 'td' }, { content: 'b', tag: 'td' } ] } ],
			} ),
		] );
	} );

	const mcp = await mcpSession( playwright, baseURL );
	const created = await mcp.call( 'guarded-mcp-create-page', { title: 'E2E nested core blocks', content: markup } );
	expect( created.status ).toBe( 'draft' );

	await openInEditor( page, created.id );
	const blocks = await blockReport( page );

	expect( blocks.map( ( b ) => b.name ) ).toEqual( [
		'core/heading',
		'core/group',
		'core/columns',
		'core/column',
		'core/paragraph',
		'core/list',
		'core/list-item',
		'core/list-item',
		'core/column',
		'core/quote',
		'core/paragraph',
		'core/image',
		'core/buttons',
		'core/button',
		'core/separator',
		'core/spacer',
		'core/table',
	] );
	expect( blocks.filter( ( b ) => ! b.isValid ) ).toEqual( [] );
	const { sent, opened } = await sentAndOpened( page, markup );
	expect( opened, 'every block, attribute and text survived' ).toBe( sent );
	expect( messages ).toEqual( [] );
} );

test( 'markup cleaned by the HTML filter opens without validation errors', async ( { page, playwright, baseURL } ) => {
	const messages = watchValidationMessages( page );

	// Hand-written like a prompt-injected agent would write it: valid block
	// markup with script, event handlers and javascript: links inside.
	const markup = [
		'<!-- wp:paragraph -->\n<p>Hi<script>alert(1)</script><img src="https://example.com/x.png" onerror="alert(2)"></p>\n<!-- /wp:paragraph -->',
		'<!-- wp:heading -->\n<h2 class="wp-block-heading"><a href="javascript:alert(3)">Go</a></h2>\n<!-- /wp:heading -->',
		'<!-- wp:list -->\n<ul class="wp-block-list"><!-- wp:list-item -->\n<li>One<iframe src="https://evil.example/"></iframe></li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->',
		'<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group" onmouseover="alert(4)"><!-- wp:paragraph -->\n<p>Inside</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->',
	].join( '\n\n' );

	const mcp = await mcpSession( playwright, baseURL );
	const created = await mcp.call( 'guarded-mcp-create-page', { title: 'E2E filtered markup', content: markup } );

	await openInEditor( page, created.id );
	const blocks = await blockReport( page );
	const content = await editorContent( page );

	expect( blocks.map( ( b ) => b.name ) ).toEqual( [
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/group',
		'core/paragraph',
	] );
	expect( blocks.filter( ( b ) => ! b.isValid ) ).toEqual( [] );
	for ( const fragment of [ '<script', 'onerror', 'javascript:', 'onmouseover', '<iframe' ] ) {
		expect( content, `"${ fragment }" is removed` ).not.toContain( fragment );
	}
	expect( content ).toContain( 'Inside' );
	expect( messages ).toEqual( [] );
} );

test( 'update-page keeps entities, quotes and escaped attributes of editor markup', async ( { page, playwright, baseURL } ) => {
	const messages = watchValidationMessages( page );

	// Attribute values in the delimiter JSON go through KSES like any other
	// HTML (as in core for users without unfiltered_html), so the block name
	// holds no "<" or "&": only what a lost backslash would break.
	await openBlockLibrary( page );
	const markup = await page.evaluate( () => {
		const { createBlock, serialize } = window.wp.blocks;
		return serialize( [
			createBlock( 'core/heading', { content: 'A &lt; B &amp;&amp; C &gt; D', anchor: 'section-1' } ),
			createBlock( 'core/paragraph', {
				content: 'Quotes "double" and \'single\', a dash -- and an <em>emphasis</em>.',
				metadata: { name: 'Intro -- "main"' },
			} ),
			createBlock( 'core/buttons', {}, [
				createBlock( 'core/button', { text: 'Go -- now', url: 'https://example.com/?a=1&b=2' } ),
			] ),
			createBlock( 'core/image', { url: 'https://example.com/b.png', alt: 'Alt with "quotes" & ampersand' } ),
		] );
	} );
	// The delimiter JSON writes "--" and quotes as unicode escapes (a
	// backslash, then u002d / u0022); they are what a lost backslash breaks.
	const backslash = String.fromCharCode( 92 );
	expect( markup ).toContain( `${ backslash }u002d${ backslash }u002d` );
	expect( markup ).toContain( `${ backslash }u0022` );

	const mcp = await mcpSession( playwright, baseURL );
	const created = await mcp.call( 'guarded-mcp-create-page', {
		title: 'E2E update-page',
		content: '<!-- wp:paragraph -->\n<p>First version</p>\n<!-- /wp:paragraph -->',
	} );
	await mcp.call( 'guarded-mcp-update-page', { id: created.id, content: markup } );

	await openInEditor( page, created.id );
	const blocks = await blockReport( page );

	expect( blocks.filter( ( b ) => ! b.isValid ) ).toEqual( [] );
	const { sent, opened } = await sentAndOpened( page, markup );
	expect( opened, 'every block, attribute and text survived' ).toBe( sent );
	expect(
		await page.evaluate( () => window.wp.data.select( 'core/block-editor' ).getBlocks()[ 1 ].attributes.metadata.name )
	).toBe( 'Intro -- "main"' );
	expect( messages ).toEqual( [] );
} );
