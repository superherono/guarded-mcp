<?php
/**
 * Ability categories — required by the Abilities API before registering abilities.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_categories_init',
	function () {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'guarded-mcp-pages',
			array(
				'label'       => __( 'Guarded MCP: pages', 'guarded-mcp' ),
				'description' => __( 'Read and write pages built from allowed blocks; site diagnostics.', 'guarded-mcp' ),
			)
		);
		wp_register_ability_category(
			'guarded-mcp-posts',
			array(
				'label'       => __( 'Guarded MCP: blog posts', 'guarded-mcp' ),
				'description' => __( 'Read and write blog posts (articles) built from allowed blocks.', 'guarded-mcp' ),
			)
		);
		wp_register_ability_category(
			'guarded-mcp-taxonomies',
			array(
				'label'       => __( 'Guarded MCP: taxonomies', 'guarded-mcp' ),
				'description' => __( 'List, create and edit terms of the allowed taxonomies (blog categories and tags by default).', 'guarded-mcp' ),
			)
		);
		wp_register_ability_category(
			'guarded-mcp-media',
			array(
				'label'       => __( 'Guarded MCP: media', 'guarded-mcp' ),
				'description' => __( 'Upload images to the media library, read them and edit their alt, caption, title and language.', 'guarded-mcp' ),
			)
		);
		wp_register_ability_category(
			'guarded-mcp-menus',
			array(
				'label'       => __( 'Guarded MCP: menus', 'guarded-mcp' ),
				'description' => __( 'Read and edit the menus of the theme locations (per language with Polylang), with dry run and backups.', 'guarded-mcp' ),
			)
		);
	}
);
