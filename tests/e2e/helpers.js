/**
 * Helpers for the E2E tests: a minimal MCP client for the plugin's
 * token-protected server, and a block validation report from the editor.
 */
const { expect } = require( '@playwright/test' );

/**
 * Open an MCP session on /wp-json/guarded-mcp/v1/mcp as the bot, the way an
 * MCP client does: initialize, then tools/call with the session header.
 *
 * The route is addressed with ?rest_route= so it works without pretty
 * permalinks. The requests carry no cookies: the token alone authorises them.
 *
 * @param {Object} playwright Playwright fixture.
 * @param {string} baseURL    Site URL.
 * @return {Promise<{call: Function}>} Session with call( tool, args ).
 */
async function mcpSession( playwright, baseURL ) {
	const request = await playwright.request.newContext( {
		baseURL,
		storageState: { cookies: [], origins: [] },
	} );
	const url = `/?rest_route=${ encodeURIComponent( '/guarded-mcp/v1/mcp' ) }&token=${ process.env.GUARDED_MCP_TOKEN }`;
	const protocol = '2025-06-18';
	let id = 0;
	let session = '';

	const post = async ( body ) => {
		const headers = {
			'Content-Type': 'application/json',
			Accept: 'application/json, text/event-stream',
		};
		if ( session ) {
			headers[ 'Mcp-Session-Id' ] = session;
			headers[ 'MCP-Protocol-Version' ] = protocol;
		}
		const response = await request.post( url, { headers, data: body } );
		const text = await response.text();
		const type = response.headers()[ 'content-type' ] || '';
		let json = null;
		if ( type.includes( 'text/event-stream' ) ) {
			const line = text.split( /\r?\n/ ).find( ( l ) => l.startsWith( 'data:' ) );
			json = line ? JSON.parse( line.slice( 5 ) ) : null;
		} else if ( text.trim() ) {
			json = JSON.parse( text );
		}
		return { response, json };
	};

	const init = await post( {
		jsonrpc: '2.0',
		id: ++id,
		method: 'initialize',
		params: {
			protocolVersion: protocol,
			capabilities: {},
			clientInfo: { name: 'guarded-mcp-e2e', version: '1' },
		},
	} );
	expect( init.response.status(), 'initialize with the token' ).toBe( 200 );
	session = init.response.headers()[ 'mcp-session-id' ] || '';
	expect( session, 'MCP session ID' ).not.toBe( '' );
	await post( { jsonrpc: '2.0', method: 'notifications/initialized' } );

	return {
		/**
		 * Call a tool; returns its structured result, throws on an error.
		 *
		 * @param {string} tool MCP tool name, e.g. guarded-mcp-create-page.
		 * @param {Object} args Arguments.
		 * @return {Promise<Object>} Tool result.
		 */
		async call( tool, args ) {
			const { response, json } = await post( {
				jsonrpc: '2.0',
				id: ++id,
				method: 'tools/call',
				params: { name: tool, arguments: args },
			} );
			expect( response.status(), `${ tool } HTTP status` ).toBe( 200 );
			expect( json.error, `${ tool } JSON-RPC error` ).toBeUndefined();
			const result = json.result || {};
			const text = ( result.content || [] ).map( ( c ) => c.text || '' ).join( '' );
			expect( result.isError, `${ tool }: ${ text }` ).toBeFalsy();
			return result.structuredContent || JSON.parse( text );
		},
	};
}

/**
 * Open a post in the block editor and wait until its blocks are parsed.
 *
 * @param {import('@playwright/test').Page} page   Page.
 * @param {number}                          postId Post ID.
 */
async function openInEditor( page, postId ) {
	await page.goto( `/wp-admin/post.php?post=${ postId }&action=edit` );
	await page.waitForFunction(
		( id ) =>
			window.wp &&
			window.wp.data &&
			window.wp.data.select( 'core/editor' ).getCurrentPostId() === id &&
			window.wp.data.select( 'core/block-editor' ).getBlocks().length > 0,
		postId,
		{ timeout: 60 * 1000 }
	);
}

/**
 * Every block in the editor with its validation state, depth first.
 *
 * @param {import('@playwright/test').Page} page Page with the editor open.
 * @return {Promise<Array<{name: string, isValid: boolean}>>} Blocks.
 */
async function blockReport( page ) {
	return page.evaluate( () => {
		const walk = ( blocks ) =>
			blocks.flatMap( ( block ) => [
				{ name: block.name, isValid: block.isValid },
				...walk( block.innerBlocks ),
			] );
		return walk( window.wp.data.select( 'core/block-editor' ).getBlocks() );
	} );
}

/**
 * Collect the editor's block validation messages from the console.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {string[]} Messages, filled while the page runs.
 */
function watchValidationMessages( page ) {
	const messages = [];
	page.on( 'console', ( message ) => {
		if ( /block validation/i.test( message.text() ) ) {
			messages.push( message.text() );
		}
	} );
	return messages;
}

module.exports = { mcpSession, openInEditor, blockReport, watchValidationMessages };
