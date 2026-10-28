<?php
/**
 * The upload route: step 2 of an image upload.
 *
 *   POST /wp-json/guarded-mcp/v1/media-upload
 *   header X-Guarded-Mcp-Upload-Ticket: <ticket from prepare-media-upload>
 *   multipart field `file`
 *
 *   curl -sS -X POST -H "X-Guarded-Mcp-Upload-Ticket: <ticket>" -F "file=@cover.png" <upload_url>
 *
 * The one entry point of the plugin outside the MCP server, so it is kept as
 * narrow as the MCP token path. The ticket is checked and burnt in the
 * permission callback, before the file is looked at; the request then runs as
 * the user the ticket was issued to (the bot), like an MCP call. A ticket carries
 * no rights of its own: it is issued only by prepare-media-upload, to a caller
 * that already has `upload_files` — the bot behind the MCP token, or a logged-in
 * user on the adapter's default server — and saves one image with the metadata
 * fixed at issue time. The request cannot change alt, caption or language.
 *
 * Success: 201 with the image payload (media.php) and `duplicate: false`. The
 * same file uploaded earlier in the same language: 200 with that attachment and
 * `duplicate: true`, nothing saved. Every rejection is a JSON error with a code
 * and a message for the agent.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'rest_api_init',
	function () {
		// Filled by the permission callback, read by the callback of the same request.
		$claimed = null;

		register_rest_route(
			'guarded-mcp/v1',
			'/media-upload',
			array(
				'methods'             => 'POST',
				'permission_callback' => function ( WP_REST_Request $request ) use ( &$claimed ) {
					// Core calls permission callbacks again after the response to
					// build the Allow header; the ticket is already burnt by then.
					if ( null !== $claimed ) {
						return true;
					}

					$data = guarded_mcp_media_claim_ticket( (string) $request->get_header( 'X-Guarded-Mcp-Upload-Ticket' ) );
					if ( is_wp_error( $data ) ) {
						return $data;
					}

					$user = get_user_by( 'id', (int) $data['user'] );
					if ( ! $user ) {
						return new WP_Error(
							'guarded_mcp_upload_user_not_found',
							__( 'The user the upload ticket was issued to no longer exists.', 'guarded-mcp' ),
							array( 'status' => 500 )
						);
					}

					wp_set_current_user( $user->ID );

					if ( ! current_user_can( 'upload_files' ) ) {
						return new WP_Error(
							'guarded_mcp_forbidden',
							__( 'You are not allowed to upload files.', 'guarded-mcp' ),
							array( 'status' => 403 )
						);
					}

					$claimed = $data;
					return true;
				},
				'callback'            => function ( WP_REST_Request $request ) use ( &$claimed ) {
					// Only reachable after the permission callback claimed a ticket.
					if ( ! is_array( $claimed ) ) {
						return new WP_Error(
							'guarded_mcp_upload_ticket_invalid',
							__( 'Upload ticket is missing, invalid or already used. Get a new one with prepare-media-upload.', 'guarded-mcp' ),
							array( 'status' => 401 )
						);
					}

					$files = $request->get_file_params();
					$file  = isset( $files['file'] ) ? $files['file'] : null;

					// A body over post_max_size is dropped by PHP before WordPress
					// runs: no fields, no file. Say so instead of "send a file".
					if ( ! $files && (int) $request->get_header( 'content_length' ) > wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ) ) {
						return new WP_Error(
							'guarded_mcp_media_too_big',
							__( 'The request exceeds the server limit (post_max_size). Not uploaded.', 'guarded-mcp' ),
							array( 'status' => 413 )
						);
					}

					if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'], $file['name'] ) || is_array( $file['tmp_name'] ) ) {
						return new WP_Error(
							'guarded_mcp_upload_no_file',
							__( 'Send one image as the multipart field "file" (curl -F "file=@path").', 'guarded-mcp' ),
							array( 'status' => 400 )
						);
					}

					if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
						$too_big = in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true );

						return new WP_Error(
							$too_big ? 'guarded_mcp_media_too_big' : 'guarded_mcp_upload_failed',
							$too_big
								? __( 'The file exceeds the server upload limit. Not uploaded.', 'guarded-mcp' )
								: sprintf(
									/* translators: %d: PHP upload error code. */
									__( 'The upload failed (PHP upload error %d). Not uploaded.', 'guarded-mcp' ),
									(int) $file['error']
								),
							array( 'status' => $too_big ? 413 : 400 )
						);
					}

					// Only a file PHP itself received in this request — never a path.
					if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
						return new WP_Error(
							'guarded_mcp_upload_no_file',
							__( 'Send one image as the multipart field "file" (curl -F "file=@path").', 'guarded-mcp' ),
							array( 'status' => 400 )
						);
					}

					$checked = guarded_mcp_media_check_file( $file['tmp_name'] );
					if ( is_wp_error( $checked ) ) {
						return $checked;
					}

					$fields   = $claimed['fields'];
					$existing = guarded_mcp_media_find_by_hash( $checked['sha256'] );

					if ( $existing ) {
						$existing_language = guarded_mcp_media_language( $existing );
						$language          = null !== $fields['language'] ? $fields['language'] : '';

						// Articles in different languages never share an image (ARCHITECTURE.md),
						// so the same file in another language is a mistake, not a translation.
						if ( guarded_mcp_media_translated() && $existing_language !== $language ) {
							return new WP_Error(
								'guarded_mcp_media_duplicate_language',
								sprintf(
									/* translators: 1: attachment ID, 2: its language, 3: requested language. */
									__( 'This file is already in the media library as attachment %1$d in "%2$s"; the ticket asks for "%3$s". Not uploaded. If the language of %1$d is wrong, fix it with update-media.', 'guarded-mcp' ),
									$existing,
									$existing_language,
									$language
								),
								array(
									'status' => 409,
									'id'     => $existing,
								)
							);
						}

						return new WP_REST_Response(
							array_merge( guarded_mcp_media_payload( $existing ), array( 'duplicate' => true ) ),
							200
						);
					}

					$attachment_id = guarded_mcp_media_save( $file['tmp_name'], $file['name'], $checked, $fields );
					if ( is_wp_error( $attachment_id ) ) {
						return $attachment_id;
					}

					return new WP_REST_Response(
						array_merge( guarded_mcp_media_payload( $attachment_id ), array( 'duplicate' => false ) ),
						201
					);
				},
			)
		);
	}
);
