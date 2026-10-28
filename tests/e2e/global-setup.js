/**
 * Before the E2E tests: log in as the administrator (the editor is opened as
 * a person would open it), and make sure the bot user exists so the MCP
 * token works.
 *
 * With wp-env both happen here through `npx wp-env run cli`. Against another
 * site, set GUARDED_MCP_TOKEN and create the bot user yourself:
 *   wp user create mcp-bot mcp-bot@example.com --role=guarded_mcp_bot
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );
const { request } = require( '@playwright/test' );

const ADMIN_USER = process.env.WP_USERNAME || 'admin';
const ADMIN_PASSWORD = process.env.WP_PASSWORD || 'password';

/**
 * Run wp-cli in the wp-env cli container and return its output.
 *
 * @param {string[]} args wp-cli arguments.
 * @return {string} Output without wp-env's status lines.
 */
function wpEnvCli( args ) {
	const out = execFileSync( 'npx', [ 'wp-env', 'run', 'cli', '--', 'wp', ...args ], {
		cwd: path.resolve( __dirname, '..', '..' ),
		encoding: 'utf8',
		shell: process.platform === 'win32',
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	return out
		.split( /\r?\n/ )
		.filter( ( line ) => line.trim() && ! /^[ℹ✔✖]/.test( line ) )
		.join( '\n' )
		.trim();
}

module.exports = async ( config ) => {
	const { baseURL, storageState } = config.projects[ 0 ].use;

	if ( ! process.env.GUARDED_MCP_TOKEN ) {
		const login = 'mcp-bot';
		try {
			wpEnvCli( [ 'user', 'get', login, '--field=ID' ] );
		} catch ( e ) {
			wpEnvCli( [ 'user', 'create', login, `${ login }@example.com`, '--role=guarded_mcp_bot' ] );
		}
		process.env.GUARDED_MCP_TOKEN = wpEnvCli( [ 'option', 'get', 'guarded_mcp_token' ] ).split( '\n' ).pop();
	}
	if ( ! /^[A-Za-z0-9]{48}$/.test( process.env.GUARDED_MCP_TOKEN ) ) {
		throw new Error( 'GUARDED_MCP_TOKEN is not a Guarded MCP token (48 letters and digits).' );
	}

	// Log in through the form and keep the cookies for every test.
	const context = await request.newContext( { baseURL } );
	await context.get( '/wp-login.php' );
	const response = await context.post( '/wp-login.php', {
		form: {
			log: ADMIN_USER,
			pwd: ADMIN_PASSWORD,
			'wp-submit': 'Log In',
			redirect_to: `${ baseURL }/wp-admin/`,
			testcookie: '1',
		},
	} );
	const cookies = ( await context.storageState() ).cookies.map( ( c ) => c.name );
	if ( ! response.ok() || ! cookies.some( ( name ) => name.startsWith( 'wordpress_logged_in_' ) ) ) {
		throw new Error( `Login as ${ ADMIN_USER } failed (HTTP ${ response.status() }).` );
	}

	fs.mkdirSync( path.dirname( storageState ), { recursive: true } );
	await context.storageState( { path: storageState } );
	await context.dispose();
};
