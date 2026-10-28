<?php
/**
 * Ability: guarded-mcp/site-health — read-only diagnostics.
 *
 * Lints the PHP files of this plugin and of the active theme (token_get_all /
 * TOKEN_PARSE, exec-free), reports WordPress, PHP and adapter versions, the
 * registered abilities, which optional integrations are active, and what the
 * guard allows: block namespaces, trusted core blocks, taxonomies, menu
 * locations, registered block styles. Takes no input — never accepts paths from
 * the client (ARCHITECTURE.md, invariant 6).
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * PHP files to lint: this plugin and the active theme (parent and child),
 * without third-party dependencies.
 *
 * @return string[] Absolute paths.
 */
function guarded_mcp_health_files() {
	$roots = array_unique( array( GUARDED_MCP_DIR, get_template_directory(), get_stylesheet_directory() ) );
	$files = array();

	foreach ( $roots as $root ) {
		if ( ! is_dir( $root ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			if ( ! $f->isFile() || 'php' !== strtolower( $f->getExtension() ) ) {
				continue;
			}
			$path = $f->getPathname();
			// Don't lint third-party deps (composer/npm packages etc.).
			if ( false !== strpos( $path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR )
				|| false !== strpos( $path, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR ) ) {
				continue;
			}
			$files[] = $path;
		}
	}

	return $files;
}

/**
 * Path of a linted file as shown to the client: relative, never a server path.
 *
 * Relative to wp-content when the file is inside it (plugins/…, themes/…).
 * A plugin or theme folder that lives elsewhere (a symlink, a custom
 * WP_PLUGIN_DIR) is shown relative to the folder that holds it.
 *
 * @param string $file Absolute path.
 * @return string
 */
function guarded_mcp_health_relative_path( $file ) {
	$file  = wp_normalize_path( $file );
	$roots = array( WP_CONTENT_DIR, dirname( GUARDED_MCP_DIR ), dirname( get_template_directory() ), dirname( get_stylesheet_directory() ) );

	foreach ( $roots as $root ) {
		$root = trailingslashit( wp_normalize_path( $root ) );
		if ( 0 === strpos( $file, $root ) ) {
			return substr( $file, strlen( $root ) );
		}
	}

	return wp_basename( $file );
}

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'guarded-mcp/site-health',
			array(
				'label'               => __( 'Site health (diagnostics)', 'guarded-mcp' ),
				'description'         => __( 'Read-only diagnostics: PHP syntax check (lint) of the plugin and active theme PHP files, WordPress, PHP and MCP adapter versions, the registered guarded-mcp abilities, whether Polylang and Yoast SEO are active, and what the content guard allows — block namespaces, trusted core blocks, taxonomies, menu locations — plus the live registry of block styles (block name → style names and classes). Takes no input. Use before writing content to see which blocks and styles are allowed, and after editing PHP to confirm nothing is broken.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-pages',
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'all_ok'              => array(
							'type'        => 'boolean',
							'description' => 'True if every linted PHP file passed the syntax check.',
						),
						'wp_version'          => array( 'type' => 'string' ),
						'php_version'         => array( 'type' => 'string' ),
						'plugin_version'      => array( 'type' => 'string' ),
						'mcp_adapter_active'  => array( 'type' => 'boolean' ),
						'mcp_adapter_version' => array( 'type' => 'string' ),
						'abilities'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'integrations'        => array(
							'type'        => 'object',
							'description' => 'Optional plugins the abilities use when active: polylang (languages and translations), yoast (seo_title, seo_description).',
							'properties'  => array(
								'polylang' => array( 'type' => 'boolean' ),
								'yoast'    => array( 'type' => 'boolean' ),
							),
						),
						'block_namespaces'    => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'Namespaces whose blocks are all allowed in content (filter guarded_mcp_block_namespaces).',
						),
						'trusted_core_blocks' => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'string' ),
							'description' => 'core/* blocks allowed in content (filter guarded_mcp_trusted_core_blocks).',
						),
						'taxonomies'          => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'menu_locations'      => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'block_styles'        => array(
							'type'                 => 'object',
							'description'          => 'Registered block styles of the allowed blocks, keyed by block name. Write a style as className "is-style-<name>".',
							'additionalProperties' => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'name'       => array( 'type' => 'string' ),
										'class'      => array( 'type' => 'string' ),
										'label'      => array( 'type' => 'string' ),
										'is_default' => array( 'type' => 'boolean' ),
									),
								),
							),
						),
						'lint'                => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'file'    => array( 'type' => 'string' ),
									'ok'      => array( 'type' => 'boolean' ),
									'message' => array( 'type' => 'string' ),
								),
							),
						),
					),
				),
				'execute_callback'    => function () {
					$lint   = array();
					$all_ok = true;
					foreach ( guarded_mcp_health_files() as $file ) {
						$result = guarded_mcp_lint_file( $file );
						$lint[] = array(
							'file'    => guarded_mcp_health_relative_path( $result['file'] ),
							'ok'      => $result['ok'],
							'message' => $result['message'],
						);
						if ( ! $result['ok'] ) {
							$all_ok = false;
						}
					}

					$abilities = array();
					if ( function_exists( 'wp_get_abilities' ) ) {
						foreach ( wp_get_abilities() as $ability ) {
							$name = $ability->get_name();
							if ( 0 === strpos( $name, 'guarded-mcp/' ) ) {
								$abilities[] = $name;
							}
						}
						sort( $abilities );
					}

					return array(
						'all_ok'              => $all_ok,
						'wp_version'          => get_bloginfo( 'version' ),
						'php_version'         => PHP_VERSION,
						'plugin_version'      => GUARDED_MCP_VERSION,
						'mcp_adapter_active'  => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
						'mcp_adapter_version' => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ) && defined( 'WP\\MCP\\Core\\McpAdapter::VERSION' ) ? (string) \WP\MCP\Core\McpAdapter::VERSION : '',
						'abilities'           => $abilities,
						'integrations'        => array(
							'polylang' => function_exists( 'pll_languages_list' ),
							'yoast'    => class_exists( 'WPSEO_Meta' ),
						),
						'block_namespaces'    => guarded_mcp_block_namespaces(),
						'trusted_core_blocks' => array_values( guarded_mcp_trusted_core_blocks() ),
						'taxonomies'          => array_values( array_filter( guarded_mcp_term_taxonomies(), 'taxonomy_exists' ) ),
						'menu_locations'      => array_keys( guarded_mcp_menu_locations() ),
						'block_styles'        => (object) guarded_mcp_registered_block_styles(),
						'lint'                => $lint,
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_pages' );
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					)
				),
			)
		);
	}
);

add_filter(
	'guarded_mcp_server_abilities',
	function ( $abilities ) {
		$abilities[] = 'guarded-mcp/site-health';
		return $abilities;
	}
);
