<?php
/**
 * Ability: guarded-mcp/prepare-media-upload — step 1 of an image upload.
 *
 * Validates the metadata (alt, caption, title, language) and issues a
 * single-use upload ticket. The file itself goes in step 2, a curl POST to the
 * upload route (media-upload.php): tool-call arguments are written by the model,
 * so image bytes cannot travel through them. Why two steps and what the ticket
 * can and cannot do — media.php.
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
			'guarded-mcp/prepare-media-upload',
			array(
				'label'               => __( 'Prepare image upload', 'guarded-mcp' ),
				'description'         => __( 'Step 1 of uploading an image to the media library. Validates alt, caption, title and language, and returns a single-use upload ticket valid for 15 minutes. Step 2: run the returned "curl" command with the local file path — the file cannot be sent through this tool. The curl response is the attachment: id, url_large (for <img src> in block markup), size_slug, alt, language. Run it in bash; in Windows PowerShell call curl.exe, since plain curl there is Invoke-WebRequest. Accepted: PNG, JPEG or WebP up to max_bytes (10 MB or the server limit, whichever is lower), at least min_size on one side. A smaller image is rejected — then stop and tell the user; never upscale it to pass. The same file uploaded again in the same language returns the existing attachment unchanged ("duplicate": true); to change its alt or caption use update-media. Images cannot be deleted through MCP.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-media',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => guarded_mcp_media_field_schemas(),
					'required'             => array( 'alt' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'ticket'        => array(
							'type'        => 'string',
							'description' => 'Single-use upload ticket; send it in the header named by "header".',
						),
						'upload_url'    => array( 'type' => 'string' ),
						'header'        => array( 'type' => 'string' ),
						'curl'          => array(
							'type'        => 'string',
							'description' => 'Ready command: replace <path-to-image> with the local file path. Run it in bash; in Windows PowerShell use curl.exe.',
						),
						'expires_at'    => array(
							'type'        => 'string',
							'description' => 'Ticket expiry, ISO 8601 UTC.',
						),
						'max_bytes'     => array( 'type' => 'integer' ),
						'allowed_types' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'min_size'      => array(
							'type'        => 'object',
							'description' => 'The image must be at least this wide OR at least this tall; otherwise WordPress makes no "large" size and the upload is rejected.',
							'properties'  => array(
								'width'  => array( 'type' => 'integer' ),
								'height' => array( 'type' => 'integer' ),
							),
						),
						'max_side'      => array(
							'type'        => 'integer',
							'description' => 'Maximum width and height in px.',
						),
						'max_pixels'    => array(
							'type'        => 'integer',
							'description' => 'Maximum width × height.',
						),
					),
				),
				'execute_callback'    => function ( $input ) {
					$fields = guarded_mcp_media_fields_from_input( $input, true );
					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					$issued = guarded_mcp_media_issue_ticket( $fields );
					if ( is_wp_error( $issued ) ) {
						return $issued;
					}

					$limits = guarded_mcp_media_limits();
					$header = 'X-Guarded-Mcp-Upload-Ticket';
					$url    = rest_url( 'guarded-mcp/v1/media-upload' );

					return array(
						'ticket'        => $issued['ticket'],
						'upload_url'    => $url,
						'header'        => $header,
						'curl'          => sprintf( 'curl -sS -X POST -H "%1$s: %2$s" -F "file=@<path-to-image>" "%3$s"', $header, $issued['ticket'], $url ),
						'expires_at'    => gmdate( 'c', $issued['expires'] ),
						'max_bytes'     => $limits['max_bytes'],
						'allowed_types' => array_keys( guarded_mcp_media_types() ),
						'min_size'      => guarded_mcp_media_min_size(),
						'max_side'      => $limits['max_side'],
						'max_pixels'    => $limits['max_pixels'],
					);
				},
				'permission_callback' => function () {
					return current_user_can( 'upload_files' );
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false, // Issues a ticket; the upload itself is additive.
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
		$abilities[] = 'guarded-mcp/prepare-media-upload';
		return $abilities;
	}
);
