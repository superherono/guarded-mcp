<?php
/**
 * Ability: guarded-mcp/create-page — create a page, ALWAYS as draft.
 *
 * Content is validated against the block allowlist before saving; the save
 * bypasses KSES only after that validation (see content-guard.php).
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'wp_abilities_api_init',
	function () {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'guarded-mcp/create-page',
			array(
				'label'               => __( 'Create page (draft)', 'guarded-mcp' ),
				'description'         => __( 'Create a new page as DRAFT from block markup. Content is validated against the block allowlist (blocks of the allowed namespaces and trusted core/* only; site-health lists both). Optional Yoast SEO title and meta description. Publishing is done manually by a human. Returns id and edit URL.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-pages',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'title'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Page title.',
							),
							'content' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Serialized block markup for post_content.',
							),
							'parent'  => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Optional parent page ID.',
							),
							'slug'    => array(
								'type'        => 'string',
								'description' => 'Optional slug (post_name).',
							),
						),
						guarded_mcp_seo_field_schemas()
					),
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'       => array( 'type' => 'integer' ),
						'status'   => array( 'type' => 'string' ),
						'edit_url' => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => function ( $input ) {
					$validation = guarded_mcp_validate_blocks( $input['content'] );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}

					// Validate before writing, so a rejected SEO field never leaves a draft behind.
					$seo = guarded_mcp_seo_from_input( $input );
					if ( is_wp_error( $seo ) ) {
						return $seo;
					}

					$postarr = array(
						'post_type'    => 'page',
						'post_status'  => 'draft', // Hard rule: draft only.
						'post_title'   => sanitize_text_field( $input['title'] ),
						'post_content' => $input['content'],
						'post_author'  => get_current_user_id(),
					);

					if ( ! empty( $input['parent'] ) ) {
						$postarr['post_parent'] = (int) $input['parent'];
					}
					if ( ! empty( $input['slug'] ) ) {
						$postarr['post_name'] = sanitize_title( $input['slug'] );
					}

					// Save with a precise KSES bypass (HTML in block attributes); content
					// already passed the allowlist above. Returns WP_Error on failure.
					$post_id = guarded_mcp_save_post_raw( $postarr );
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					guarded_mcp_apply_seo( $post_id, $seo );

					return array(
						'id'       => (int) $post_id,
						'status'   => 'draft',
						'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
					);
				},
				'permission_callback' => function () {
					// The page is always a draft, so creating it needs edit_pages, not
					// publish_pages: the bot role has no publishing rights at all.
					return current_user_can( 'edit_pages' );
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false, // Additive only: a new draft.
						'idempotent'  => false,
					)
				),
			)
		);
	}
);

add_filter(
	'guarded_mcp_server_abilities',
	function ( $abilities ) {
		$abilities[] = 'guarded-mcp/create-page';
		return $abilities;
	}
);
