<?php
/**
 * Ability: guarded-mcp/update-media — edit the metadata of an image in the media
 * library: alt, caption, title, Polylang language.
 *
 * The file itself is never replaced or removed. Attachments keep no revisions,
 * so the previous values are not stored anywhere — the output returns the new
 * state.
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
			'guarded-mcp/update-media',
			array(
				'label'               => __( 'Update image', 'guarded-mcp' ),
				'description'         => __( 'Edit an image in the media library: alt, caption, title and/or Polylang language — at least one. The file is not changed. An empty alt marks the image as decorative; an empty caption clears it. The language of an image that is the cover of an article in another language cannot be changed. Previous values are not kept (attachments have no revisions): read them with get-media first if they may be needed. Changing the alt or caption here does not change markup that already carries them in <img alt> or <figcaption>. Returns the image as get-media does.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-media',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'id' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Attachment ID to update.',
							),
						),
						guarded_mcp_media_field_schemas()
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

					// Re-check rights on the specific object.
					if ( ! current_user_can( 'edit_post', $image->ID ) ) {
						return new WP_Error(
							'guarded_mcp_forbidden',
							__( 'You are not allowed to edit this image.', 'guarded-mcp' )
						);
					}

					if ( ! array_intersect( array_keys( $input ), array_keys( guarded_mcp_media_field_schemas() ) ) ) {
						return new WP_Error(
							'guarded_mcp_nothing_to_update',
							__( 'Provide at least one of: alt, caption, title, language.', 'guarded-mcp' )
						);
					}

					// Validate everything before the first write.
					$fields = guarded_mcp_media_fields_from_input( $input, false );
					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					// A cover must stay in the language of its article (posts.php
					// checks the same from the article side).
					$conflicts = null !== $fields['language'] ? guarded_mcp_media_cover_conflicts( $image->ID, $fields['language'] ) : array();
					if ( $conflicts ) {
						return new WP_Error(
							'guarded_mcp_cover_language_mismatch',
							sprintf(
								/* translators: 1: attachment ID, 2: post IDs, 3: language slug. */
								__( 'Image %1$d is the cover of post(s) %2$s in another language; changing it to "%3$s" would put that language on them. Not changed.', 'guarded-mcp' ),
								$image->ID,
								implode( ', ', $conflicts ),
								$fields['language']
							),
							array( 'posts' => $conflicts )
						);
					}

					$applied = guarded_mcp_media_apply_fields( $image->ID, $fields );
					if ( is_wp_error( $applied ) ) {
						return $applied;
					}

					return guarded_mcp_media_payload( $image->ID );
				},
				'permission_callback' => function ( $input ) {
					$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
					return $id > 0 && current_user_can( 'edit_post', $id );
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false, // Edits in place; nothing is removed.
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
		$abilities[] = 'guarded-mcp/update-media';
		return $abilities;
	}
);
