<?php
/**
 * Ability: guarded-mcp/update-post — update an existing blog post (article).
 *
 * The article counterpart of update-page: title and/or block content, plus the
 * article fields (category, cover image, excerpt, language). WordPress stores a
 * revision for content changes. Status is never changed here.
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
			'guarded-mcp/update-post',
			array(
				'label'               => __( 'Update blog post', 'guarded-mcp' ),
				'description'         => __( 'Update an existing blog post (article): title, block content, category, cover image, excerpt, language, Yoast SEO title and/or meta description — at least one. Content is validated against the block allowlist. The status is never changed. WordPress stores a revision for rollback. For site pages use update-page instead. Returns id and status.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-posts',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'id'      => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Post ID to update.',
							),
							'title'   => array(
								'type'        => 'string',
								'description' => 'New title (optional).',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'New serialized block markup (optional).',
							),
						),
						guarded_mcp_post_field_schemas(),
						guarded_mcp_seo_field_schemas()
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => guarded_mcp_post_write_output_schema(),
				'execute_callback'    => function ( $input ) {
					$id   = (int) $input['id'];
					$post = get_post( $id );

					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error(
							'guarded_mcp_post_not_found',
							__( 'Blog post not found.', 'guarded-mcp' )
						);
					}

					// Re-check rights on the specific object.
					if ( ! current_user_can( 'edit_post', $id ) ) {
						return new WP_Error(
							'guarded_mcp_forbidden',
							__( 'You are not allowed to edit this post.', 'guarded-mcp' )
						);
					}

					$has_title   = array_key_exists( 'title', $input ) && '' !== (string) $input['title'];
					$has_content = array_key_exists( 'content', $input ) && '' !== (string) $input['content'];
					$has_extras  = (bool) array_intersect(
						array_keys( $input ),
						array_merge(
							array_keys( guarded_mcp_post_field_schemas() ),
							array_keys( guarded_mcp_seo_keys() )
						)
					);

					if ( ! $has_title && ! $has_content && ! $has_extras ) {
						return new WP_Error(
							'guarded_mcp_nothing_to_update',
							__( 'Provide at least one of: title, content, excerpt, categories, featured_media, language, seo_title, seo_description.', 'guarded-mcp' )
						);
					}

					if ( $has_content ) {
						$validation = guarded_mcp_validate_blocks( $input['content'] );
						if ( is_wp_error( $validation ) ) {
							return $validation;
						}
					}

					// Validate everything before the first write.
					$fields = guarded_mcp_post_fields_from_input( $input, $id );
					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					$seo = guarded_mcp_seo_from_input( $input );
					if ( is_wp_error( $seo ) ) {
						return $seo;
					}

					$postarr = $fields['postarr'];
					if ( $has_title ) {
						$postarr['post_title'] = sanitize_text_field( $input['title'] );
					}
					if ( $has_content ) {
						$postarr['post_content'] = $input['content'];
					}

					// Cover image / language / SEO fields alone don't touch the post row.
					if ( $postarr ) {
						$postarr['ID'] = $id;
						$result        = guarded_mcp_save_post_raw( $postarr );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}

					guarded_mcp_apply_post_extras( $id, $fields );
					guarded_mcp_apply_seo( $id, $seo );

					$updated = get_post( $id );

					return array(
						'id'       => $id,
						'status'   => $updated->post_status,
						'edit_url' => (string) get_edit_post_link( $id, 'raw' ),
					);
				},
				'permission_callback' => function ( $input ) {
					$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
					return $id > 0 && current_user_can( 'edit_post', $id );
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false, // WP revision allows rollback.
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
		$abilities[] = 'guarded-mcp/update-post';
		return $abilities;
	}
);
