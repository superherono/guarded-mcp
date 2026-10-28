<?php
/**
 * Ability: guarded-mcp/get-media — read an image from the media library. Read-only.
 *
 * Returns what block markup needs (id, url_large, size_slug) and the metadata
 * to check (alt, caption, language) — for images already on the site, such as
 * the cover id get-post reports.
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
			'guarded-mcp/get-media',
			array(
				'label'               => __( 'Get image', 'guarded-mcp' ),
				'description'         => __( 'Read an image from the media library by attachment id: url_large for <img src> in block markup and the size_slug it points to, alt, caption, language, dimensions, full-size URL and edit URL. Read-only. To upload a new image use prepare-media-upload; to change alt, caption, title or language use update-media.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-media',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'id' => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'description' => 'Attachment ID, e.g. featured_media from get-post or the id of a core/image block.',
						),
					),
					'required'             => array( 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => guarded_mcp_media_output_schema(),
				'execute_callback'    => function ( $input ) {
					$image = guarded_mcp_media_get_image( (int) $input['id'] );
					if ( is_wp_error( $image ) ) {
						return $image;
					}

					return guarded_mcp_media_payload( $image->ID );
				},
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
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
		$abilities[] = 'guarded-mcp/get-media';
		return $abilities;
	}
);
