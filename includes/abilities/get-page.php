<?php
/**
 * Ability: guarded-mcp/get-page — read a page by id OR slug. Read-only.
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
			'guarded-mcp/get-page',
			array(
				'label'               => __( 'Get page', 'guarded-mcp' ),
				'description'         => __( 'Retrieve a page by numeric id OR by slug. Returns title, raw block markup (post_content), status, Yoast SEO title and meta description, and edit URL. Read-only.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-pages',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Page ID. Provide id OR slug.',
						),
						'slug' => array(
							'type'        => 'string',
							'description' => 'Page slug (post_name). Provide id OR slug.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array_merge(
						array(
							'id'       => array( 'type' => 'integer' ),
							'title'    => array( 'type' => 'string' ),
							'slug'     => array( 'type' => 'string' ),
							'status'   => array( 'type' => 'string' ),
							'content'  => array(
								'type'        => 'string',
								'description' => 'Raw post_content (serialized block markup).',
							),
							'edit_url' => array( 'type' => 'string' ),
						),
						guarded_mcp_seo_output_schemas()
					),
				),
				'execute_callback'    => function ( $input ) {
					$post = null;

					if ( ! empty( $input['id'] ) ) {
						$post = get_post( (int) $input['id'] );
					} elseif ( ! empty( $input['slug'] ) ) {
						$post = get_page_by_path( sanitize_title( $input['slug'] ), OBJECT, 'page' );
					} else {
						return new WP_Error(
							'guarded_mcp_missing_identifier',
							__( 'Provide either id or slug.', 'guarded-mcp' )
						);
					}

					if ( ! $post || 'page' !== $post->post_type ) {
						return new WP_Error(
							'guarded_mcp_page_not_found',
							__( 'Page not found.', 'guarded-mcp' )
						);
					}

					return array_merge(
						array(
							'id'       => (int) $post->ID,
							'title'    => $post->post_title,
							'slug'     => $post->post_name,
							'status'   => $post->post_status,
							'content'  => $post->post_content,
							'edit_url' => (string) get_edit_post_link( $post->ID, 'raw' ),
						),
						guarded_mcp_seo_payload( $post->ID )
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
		$abilities[] = 'guarded-mcp/get-page';
		return $abilities;
	}
);
