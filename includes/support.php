<?php
/**
 * Shared support helpers.
 *
 * Cross-cutting helpers used by the ability definitions:
 *  - guarded_mcp_ability_meta(): the standard `meta` block (MCP public flag
 *    + tool annotations) so every ability declares it the same way.
 *  - guarded_mcp_lint_file(): exec-free PHP syntax check (site-health).
 *  - guarded_mcp_registered_block_styles(): the live block-style registry
 *    (site-health), so the content agent can check its reference against code.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the standard ability `meta` block.
 *
 * `mcp.public = true` exposes the ability on the adapter's default-server
 * (the real protection is auth + permission_callback). `annotations` are the
 * MCP tool hints (readonly / destructive / idempotent).
 *
 * @param array $annotations Tool annotations (readonly, destructive, idempotent).
 * @return array
 */
function guarded_mcp_ability_meta( array $annotations ) {
	return array(
		'mcp'         => array(
			'public' => true,
		),
		'annotations' => $annotations,
	);
}

/**
 * Lightweight PHP syntax lint without exec or an external binary:
 * token_get_all(..., TOKEN_PARSE) does a full parse and throws ParseError on a
 * syntax error (PHP >= 7.0). Our exec-free equivalent of `php -l` — catch a
 * parse error before it takes the site down.
 *
 * @param string $file Absolute path to a .php file.
 * @return array{file:string, ok:bool, message:string}
 */
function guarded_mcp_lint_file( $file ) {
	if ( ! is_readable( $file ) ) {
		return array(
			'file'    => $file,
			'ok'      => false,
			'message' => 'File not readable.',
		);
	}

	$code = file_get_contents( $file );

	try {
		token_get_all( $code, TOKEN_PARSE );
		return array(
			'file'    => $file,
			'ok'      => true,
			'message' => 'No syntax errors detected',
		);
	} catch ( \Error $e ) {
		// ParseError and CompileError both extend \Error.
		return array(
			'file'    => $file,
			'ok'      => false,
			'message' => $e->getMessage(),
		);
	}
}

/**
 * Block styles registered server-side for core/* blocks and the allowed namespaces.
 *
 * Styles registered in PHP (register_block_style()) are listed here; styles a
 * theme registers only in JavaScript are not visible to the server. The content
 * agent writes a style as a class on the block — `{"className":"is-style-<name>"}`
 * in the delimiter plus the class on the root element — and uses this list to
 * confirm a style still exists before relying on its reference docs.
 *
 * @return array<string, array<int, array{name:string, class:string, label:string, is_default:bool}>>
 */
function guarded_mcp_registered_block_styles() {
	$out = array();

	if ( ! class_exists( 'WP_Block_Styles_Registry' ) ) {
		return $out;
	}

	foreach ( WP_Block_Styles_Registry::get_instance()->get_all_registered() as $block_name => $styles ) {
		if ( ! guarded_mcp_is_block_allowed( $block_name ) ) {
			continue;
		}

		foreach ( $styles as $style ) {
			$out[ $block_name ][] = array(
				'name'       => (string) $style['name'],
				'class'      => 'is-style-' . $style['name'],
				'label'      => (string) $style['label'],
				'is_default' => ! empty( $style['is_default'] ),
			);
		}
	}

	ksort( $out );
	return $out;
}
