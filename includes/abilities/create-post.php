<?php
/**
 * Ability: guarded-mcp/create-post — create a blog post (article), ALWAYS as draft.
 *
 * The article counterpart of create-page. Content goes through the same block
 * allowlist; category, cover image, excerpt and language are validated in
 * posts.php. Publishing stays manual.
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
			'guarded-mcp/create-post',
			array(
				'label'               => __( 'Create blog post (draft)', 'guarded-mcp' ),
				'description'         => __( 'Create a new blog post (article) as DRAFT from block markup. Content is validated against the block allowlist (blocks of the allowed namespaces and trusted core/* only; site-health lists both). Optional: existing categories, an existing cover image, an excerpt, the Polylang language, and the Yoast SEO title and meta description. Publishing is done manually by a human. For site pages use create-page instead. Returns id and edit URL.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-posts',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'title'   => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Article title (the H1 rendered by the article header).',
							),
							'content' => array(
								'type'        => 'string',
								'minLength'   => 1,
								'description' => 'Serialized block markup for post_content.',
							),
							'slug'    => array(
								'type'        => 'string',
								'description' => 'Optional slug (post_name).',
							),
						),
						guarded_mcp_post_field_schemas(),
						guarded_mcp_seo_field_schemas()
					),
					'required'             => array( 'title', 'content' ),
					'additionalProperties' => false,
				),
				'output_schema'       => guarded_mcp_post_write_output_schema(),
				'execute_callback'    => function ( $input ) {
					$validation = guarded_mcp_validate_blocks( $input['content'] );
					if ( is_wp_error( $validation ) ) {
						return $validation;
					}

					// Validate every post-only field BEFORE writing, so a bad category
					// or cover never leaves a half-made draft behind.
					$fields = guarded_mcp_post_fields_from_input( $input );
					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					$seo = guarded_mcp_seo_from_input( $input );
					if ( is_wp_error( $seo ) ) {
						return $seo;
					}

					$postarr = array_merge(
						array(
							'post_type'    => 'post',
							'post_status'  => 'draft', // Hard rule: draft only.
							'post_title'   => sanitize_text_field( $input['title'] ),
							'post_content' => $input['content'],
							'post_author'  => get_current_user_id(),
						),
						$fields['postarr']
					);

					if ( ! empty( $input['slug'] ) ) {
						$postarr['post_name'] = sanitize_title( $input['slug'] );
					}

					// Precise KSES bypass (HTML in block attributes); content already
					// passed the allowlist above. Returns WP_Error on failure.
					$post_id = guarded_mcp_save_post_raw( $postarr );
					if ( is_wp_error( $post_id ) ) {
						return $post_id;
					}

					guarded_mcp_apply_post_extras( $post_id, $fields );
					guarded_mcp_apply_seo( $post_id, $seo );

					return array(
						'id'       => (int) $post_id,
						'status'   => 'draft',
						'edit_url' => (string) get_edit_post_link( $post_id, 'raw' ),
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
		$abilities[] = 'guarded-mcp/create-post';
		return $abilities;
	}
);
