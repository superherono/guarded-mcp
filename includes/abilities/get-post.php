<?php
/**
 * Ability: guarded-mcp/get-post — read a blog post (article) by id OR slug. Read-only.
 *
 * The article counterpart of get-page. Besides the block markup it returns the
 * fields an article has and a page doesn't: category, cover image, excerpt and
 * Polylang language. See posts.php for why posts get their own abilities.
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
			'guarded-mcp/get-post',
			array(
				'label'               => __( 'Get blog post', 'guarded-mcp' ),
				'description'         => __( 'Retrieve a blog post (article) by numeric id OR by slug. Returns title, raw block markup (post_content), status, excerpt, category, cover image id, language, Yoast SEO title and meta description, and edit URL. Read-only. For site pages use get-page instead.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-posts',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id'   => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Post ID. Provide id OR slug; prefer id on a multilingual site.',
						),
						'slug' => array(
							'type'        => 'string',
							'description' => 'Post slug (post_name). Provide id OR slug.',
						),
					),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer' ),
						'title'          => array( 'type' => 'string' ),
						'slug'           => array( 'type' => 'string' ),
						'status'         => array( 'type' => 'string' ),
						'content'        => array(
							'type'        => 'string',
							'description' => 'Raw post_content (serialized block markup).',
						),
						'excerpt'        => array( 'type' => 'string' ),
						'categories'     => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'   => array( 'type' => 'integer' ),
									'name' => array( 'type' => 'string' ),
									'slug' => array( 'type' => 'string' ),
								),
							),
						),
						'featured_media' => array(
							'type'        => 'integer',
							'description' => 'Cover image attachment ID, 0 if none.',
						),
						'language'       => array(
							'type'        => 'string',
							'description' => 'Polylang language slug, empty if none or Polylang inactive.',
						),
						'link'           => array( 'type' => 'string' ),
						'edit_url'       => array( 'type' => 'string' ),
					) + guarded_mcp_seo_output_schemas(),
				),
				'execute_callback'    => function ( $input ) {
					$post = null;

					if ( ! empty( $input['id'] ) ) {
						$post = get_post( (int) $input['id'] );
					} elseif ( ! empty( $input['slug'] ) ) {
						// Direct lookup by post_name — not filtered by Polylang's
						// current-language query vars, unlike WP_Query.
						$post = get_page_by_path( sanitize_title( $input['slug'] ), OBJECT, 'post' );
					} else {
						return new WP_Error(
							'guarded_mcp_missing_identifier',
							__( 'Provide either id or slug.', 'guarded-mcp' )
						);
					}

					if ( ! $post || 'post' !== $post->post_type ) {
						return new WP_Error(
							'guarded_mcp_post_not_found',
							__( 'Blog post not found.', 'guarded-mcp' )
						);
					}

					return guarded_mcp_post_payload( $post );
				},
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
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
		$abilities[] = 'guarded-mcp/get-post';
		return $abilities;
	}
);
