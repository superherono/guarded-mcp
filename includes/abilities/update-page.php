<?php
/**
 * Ability: guarded-mcp/update-page — update title and/or block content.
 *
 * WordPress stores a revision for rollback. Content (when provided) is validated
 * against the block allowlist before saving.
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
			'guarded-mcp/update-page',
			array(
				'label'               => __( 'Update page', 'guarded-mcp' ),
				'description'         => __( 'Update the title, block content and/or Yoast SEO title and meta description of an existing page — at least one. If content is provided it is validated against the block allowlist. WordPress stores a revision for rollback. Returns id and status.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-pages',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'id'      => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Page ID to update.',
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
						guarded_mcp_seo_field_schemas()
					),
					'required'             => array( 'id' ),
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
					$id   = (int) $input['id'];
					$post = get_post( $id );

					if ( ! $post || 'page' !== $post->post_type ) {
						return new WP_Error(
							'guarded_mcp_page_not_found',
							__( 'Page not found.', 'guarded-mcp' )
						);
					}

					// Re-check rights on the specific object.
					if ( ! current_user_can( 'edit_page', $id ) ) {
						return new WP_Error(
							'guarded_mcp_forbidden',
							__( 'You are not allowed to edit this page.', 'guarded-mcp' )
						);
					}

					$has_title   = array_key_exists( 'title', $input ) && '' !== (string) $input['title'];
					$has_content = array_key_exists( 'content', $input ) && '' !== (string) $input['content'];
					$has_seo     = (bool) array_intersect( array_keys( $input ), array_keys( guarded_mcp_seo_keys() ) );

					if ( ! $has_title && ! $has_content && ! $has_seo ) {
						return new WP_Error(
							'guarded_mcp_nothing_to_update',
							__( 'Provide at least one of: title, content, seo_title, seo_description.', 'guarded-mcp' )
						);
					}

					// Validate everything before the first write.
					$seo = guarded_mcp_seo_from_input( $input );
					if ( is_wp_error( $seo ) ) {
						return $seo;
					}

					$postarr = array( 'ID' => $id );

					if ( $has_title ) {
						$postarr['post_title'] = sanitize_text_field( $input['title'] );
					}

					if ( $has_content ) {
						$validation = guarded_mcp_validate_blocks( $input['content'] );
						if ( is_wp_error( $validation ) ) {
							return $validation;
						}
						$postarr['post_content'] = $input['content'];
					}

					// SEO fields alone don't touch the post row.
					if ( $has_title || $has_content ) {
						$result = guarded_mcp_save_post_raw( $postarr );
						if ( is_wp_error( $result ) ) {
							return $result;
						}
					}

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
					return $id > 0 && current_user_can( 'edit_page', $id );
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
		$abilities[] = 'guarded-mcp/update-page';
		return $abilities;
	}
);
