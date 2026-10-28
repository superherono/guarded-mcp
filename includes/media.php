<?php
/**
 * Media library images: upload tickets, file checks, save, payload.
 *
 * Why an upload takes two steps. The arguments of a tool call are written by the
 * model token by token, so the bytes of an image cannot travel inside one: a
 * 1 MB PNG is 1.3 M characters of base64. So:
 *  1. prepare-media-upload (MCP) validates alt / caption / title / language and
 *     issues a single-use upload ticket, valid for 15 minutes;
 *  2. the client POSTs the file with curl to the upload route (media-upload.php),
 *     the ticket in a header. The route checks the file, saves it and answers
 *     with the attachment payload (id, url_large, …).
 * A ticket does one thing only — save one image with the metadata fixed in
 * step 1 — so a leaked one (it lands in the client transcript) is worth little,
 * and the long-lived MCP token never appears in a shell command.
 *
 * guarded_mcp_media_check_file() is the trust boundary of the upload: raster
 * only (PNG, JPEG, WebP — never SVG, it can carry script), the type read from the
 * bytes rather than the name, size and dimension caps, and by default a minimum —
 * the image must be big enough for WordPress to make the `large` size, because
 * `large` is what block markup references (filter `guarded_mcp_media_require_large`).
 *
 * Shared by prepare-media-upload, get-media, update-media, the upload route and
 * the cover language check in posts.php.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Image types accepted for upload: MIME type (as read from the file) → the
 * extension the saved file gets.
 *
 * @return array<string,string>
 */
function guarded_mcp_media_types() {
	return array(
		'image/png'  => 'png',
		'image/jpeg' => 'jpg',
		'image/webp' => 'webp',
	);
}

/**
 * Upload limits.
 *
 * @return array{max_bytes:int,max_side:int,max_pixels:int,ticket_ttl:int,max_tickets:int}
 */
function guarded_mcp_media_limits() {
	return array(
		// Never above what the server itself accepts (upload_max_filesize,
		// post_max_size): on shared hosting that can be less than 10 MB.
		'max_bytes'   => (int) min( 10 * MB_IN_BYTES, wp_max_upload_size() ),
		'max_side'    => 8000,
		// A small file can declare huge dimensions. GD decodes at 4–5 bytes a
		// pixel to make the sub-sizes: 25 Mpx is about 125 MB, inside the 256 MB
		// WordPress allows for image work; 8000×8000 would exhaust it and leave a
		// half-made attachment behind.
		'max_pixels'  => 25000000,
		'ticket_ttl'  => 15 * MINUTE_IN_SECONDS,
		'max_tickets' => 10,
	);
}

/**
 * The `large` image size as configured in Settings → Media.
 *
 * @return array{width:int,height:int}
 */
function guarded_mcp_media_large_size() {
	return array(
		'width'  => (int) get_option( 'large_size_w' ),
		'height' => (int) get_option( 'large_size_h' ),
	);
}

/**
 * Will WordPress make the `large` size for an image of these dimensions?
 *
 * Asks image_resize_dimensions() — the function the image editor itself uses to
 * decide whether a sub-size is made — so the answer matches WordPress exactly,
 * including its 1 px tolerance.
 *
 * @param int $width  Image width.
 * @param int $height Image height.
 * @return bool
 */
function guarded_mcp_media_makes_large( $width, $height ) {
	$large = guarded_mcp_media_large_size();

	return false !== image_resize_dimensions( (int) $width, (int) $height, $large['width'], $large['height'], false );
}

/**
 * Whether uploads must be big enough for WordPress to make the `large` size.
 *
 * On by default: block markup references `large`, and a smaller image would
 * silently fall back to the original. Sites that also upload small images
 * (icons, avatars) can turn it off with the filter.
 *
 * @return bool
 */
function guarded_mcp_media_requires_large() {
	return (bool) apply_filters( 'guarded_mcp_media_require_large', true );
}

/**
 * The smallest image that still gets the `large` size, for messages to the
 * agent. WordPress skips a sub-size that would differ from the original by 1 px
 * or less, so a side has to exceed the large box by 2 px; one side is enough.
 * The check itself is guarded_mcp_media_makes_large().
 *
 * 1 × 1 when the minimum is turned off.
 *
 * @return array{width:int,height:int}
 */
function guarded_mcp_media_min_size() {
	if ( ! guarded_mcp_media_requires_large() ) {
		return array(
			'width'  => 1,
			'height' => 1,
		);
	}

	$large = guarded_mcp_media_large_size();

	return array(
		'width'  => $large['width'] + 2,
		'height' => $large['height'] + 2,
	);
}

/**
 * Whether Polylang assigns languages to media (Languages → Settings → Media).
 *
 * @return bool
 */
function guarded_mcp_media_translated() {
	return function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( 'attachment' );
}

/**
 * Polylang language slug of an attachment, empty when none.
 *
 * @param int $attachment_id Attachment ID.
 * @return string
 */
function guarded_mcp_media_language( $attachment_id ) {
	return function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( (int) $attachment_id, 'slug' ) : '';
}

/**
 * Posts that use an image as their cover but are in a language other than
 * $language — the other side of the cover language check in posts.php, for a
 * change of the image's own language.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $language      Language the image is about to get.
 * @return int[] Post IDs.
 */
function guarded_mcp_media_cover_conflicts( $attachment_id, $language ) {
	if ( ! guarded_mcp_media_translated() ) {
		return array();
	}

	$post_ids = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'any',
			'meta_key'       => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => (int) $attachment_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'lang'           => '', // All languages: Polylang would filter by the current one.
		)
	);

	$conflicts = array();
	foreach ( $post_ids as $post_id ) {
		$post_language = (string) pll_get_post_language( $post_id, 'slug' );
		if ( '' !== $post_language && $post_language !== $language ) {
			$conflicts[] = (int) $post_id;
		}
	}

	return $conflicts;
}

/**
 * Input schemas of the image metadata fields. Shared by prepare-media-upload and
 * update-media so both describe them identically to the agent.
 *
 * @return array<string,array>
 */
function guarded_mcp_media_field_schemas() {
	return array(
		'alt'      => array(
			'type'        => 'string',
			'description' => 'Alt text: what the image shows, in the image language. Themes render the featured image with this alt. For an image inside article content the markup carries its own alt: write the same text into <img alt> as well.',
		),
		'caption'  => array(
			'type'        => 'string',
			'description' => 'Optional caption stored in the media library. Themes may print it under the featured image. An image inside article content takes its caption from <figcaption> in the markup, not from here: write the same text there. An empty string clears it.',
		),
		'title'    => array(
			'type'        => 'string',
			'description' => 'Optional attachment title shown in the media library. Defaults to the file name.',
		),
		'language' => array(
			'type'        => 'string',
			'description' => 'Polylang language slug (e.g. "en") of the image — the language of the article it belongs to. Required on upload when Polylang translates media: without it Polylang assigns the site default language. A cover must be in the language of its article. Ignored when Polylang does not translate media.',
		),
	);
}

/**
 * Validate the image metadata fields from ability input.
 *
 * On upload ($is_new) alt is required and non-empty, and so is the language
 * when Polylang translates media. On update every field is optional, and an
 * empty alt is allowed: it marks the image as decorative.
 *
 * @param array $input  Ability input.
 * @param bool  $is_new True for an upload, false for an update.
 * @return array{alt:?string,caption:?string,title:?string,language:?string}|WP_Error Null = field not passed.
 */
function guarded_mcp_media_fields_from_input( array $input, $is_new ) {
	$fields = array(
		'alt'      => null,
		'caption'  => null,
		'title'    => null,
		'language' => null,
	);

	if ( array_key_exists( 'alt', $input ) ) {
		$fields['alt'] = sanitize_text_field( (string) $input['alt'] );
	}

	if ( $is_new && ( null === $fields['alt'] || '' === $fields['alt'] ) ) {
		return new WP_Error(
			'guarded_mcp_media_alt_required',
			__( 'Pass "alt": the text that describes what the image shows.', 'guarded-mcp' )
		);
	}

	if ( array_key_exists( 'caption', $input ) ) {
		$fields['caption'] = sanitize_text_field( (string) $input['caption'] );
	}

	if ( array_key_exists( 'title', $input ) ) {
		$fields['title'] = sanitize_text_field( (string) $input['title'] );

		if ( '' === $fields['title'] ) {
			return new WP_Error(
				'guarded_mcp_media_empty_title',
				__( 'The attachment title cannot be empty.', 'guarded-mcp' )
			);
		}
	}

	if ( ! guarded_mcp_media_translated() ) {
		return $fields;
	}

	if ( array_key_exists( 'language', $input ) ) {
		$language = sanitize_key( (string) $input['language'] );

		if ( ! in_array( $language, (array) pll_languages_list( array( 'fields' => 'slug' ) ), true ) ) {
			return new WP_Error(
				'guarded_mcp_language_not_found',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Language "%s" is not configured in Polylang.', 'guarded-mcp' ),
					$language
				),
				array( 'language' => $language )
			);
		}

		$fields['language'] = $language;
	} elseif ( $is_new ) {
		return new WP_Error(
			'guarded_mcp_media_language_required',
			__( 'Pass "language" — the language of the article the image belongs to: without it Polylang assigns the site default language.', 'guarded-mcp' )
		);
	}

	return $fields;
}

/**
 * Option name that stores a ticket. The ticket itself is not stored, only its
 * hash, so the options table holds nothing a request could present.
 *
 * @param string $ticket Ticket.
 * @return string
 */
function guarded_mcp_media_ticket_key( $ticket ) {
	return 'guarded_mcp_upload_' . hash( 'sha256', (string) $ticket );
}

/**
 * Count the tickets still open, deleting the expired ones on the way.
 *
 * Tickets are plain options (autoload off), not transients: an expired
 * transient under a persistent object cache is invisible to a query, and the
 * single-use claim below needs a real row to delete.
 *
 * @return int
 */
function guarded_mcp_media_open_tickets() {
	global $wpdb;

	$names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( 'guarded_mcp_upload_' ) . '%'
		)
	);

	$open = 0;
	foreach ( $names as $name ) {
		$data = get_option( $name );

		if ( ! is_array( $data ) || empty( $data['expires'] ) || (int) $data['expires'] < time() ) {
			delete_option( $name );
			continue;
		}

		++$open;
	}

	return $open;
}

/**
 * Issue a single-use upload ticket carrying validated metadata.
 *
 * @param array $fields Output of guarded_mcp_media_fields_from_input().
 * @return array{ticket:string,expires:int}|WP_Error
 */
function guarded_mcp_media_issue_ticket( array $fields ) {
	$limits = guarded_mcp_media_limits();

	if ( guarded_mcp_media_open_tickets() >= $limits['max_tickets'] ) {
		return new WP_Error(
			'guarded_mcp_upload_tickets_limit',
			sprintf(
				/* translators: 1: number of tickets, 2: minutes. */
				__( 'There are already %1$d open upload tickets. Use them, or wait: a ticket expires %2$d minutes after it was issued.', 'guarded-mcp' ),
				$limits['max_tickets'],
				(int) ( $limits['ticket_ttl'] / MINUTE_IN_SECONDS )
			)
		);
	}

	$ticket  = wp_generate_password( 43, false, false );
	$expires = time() + $limits['ticket_ttl'];

	add_option(
		guarded_mcp_media_ticket_key( $ticket ),
		array(
			'expires' => $expires,
			'user'    => get_current_user_id(),
			'fields'  => $fields,
		),
		'',
		false
	);

	return array(
		'ticket'  => $ticket,
		'expires' => $expires,
	);
}

/**
 * Claim (burn) an upload ticket.
 *
 * The delete IS the claim: only the request whose DELETE removed the row sees
 * one affected row, so of two concurrent requests with one ticket only one gets
 * through. A ticket is burnt by the first attempt whatever its outcome; a retry
 * takes a new one.
 *
 * Straight to the table rather than through the options API: this runs for
 * unauthenticated requests, and get_option() / delete_option() on an unknown
 * key record the miss in the cached `notoptions` array — under a persistent
 * object cache random tickets would grow it without bound.
 *
 * @param string $ticket Ticket from the request header.
 * @return array{expires:int,user:int,fields:array}|WP_Error
 */
function guarded_mcp_media_claim_ticket( $ticket ) {
	global $wpdb;

	$ticket  = (string) $ticket;
	$invalid = new WP_Error(
		'guarded_mcp_upload_ticket_invalid',
		__( 'Upload ticket is missing, invalid or already used. Get a new one with prepare-media-upload.', 'guarded-mcp' ),
		array( 'status' => 401 )
	);

	// Shape check first: a malformed header never reaches the database.
	if ( 43 !== strlen( $ticket ) || ! ctype_alnum( $ticket ) ) {
		return $invalid;
	}

	$key = guarded_mcp_media_ticket_key( $ticket );
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );

	if ( null === $raw || 1 !== (int) $wpdb->delete( $wpdb->options, array( 'option_name' => $key ) ) ) {
		return $invalid;
	}

	// add_option() cached the value; drop it so the cache matches the table.
	wp_cache_delete( $key, 'options' );

	$data = maybe_unserialize( $raw );
	if ( ! is_array( $data ) ) {
		return $invalid;
	}

	if ( (int) $data['expires'] < time() ) {
		return new WP_Error(
			'guarded_mcp_upload_ticket_expired',
			__( 'Upload ticket has expired. Get a new one with prepare-media-upload.', 'guarded-mcp' ),
			array( 'status' => 401 )
		);
	}

	return $data;
}

/**
 * Check an uploaded file before anything is saved.
 *
 * @param string $path Path of the uploaded temporary file.
 * @return array{mime:string,ext:string,width:int,height:int,bytes:int,sha256:string}|WP_Error
 */
function guarded_mcp_media_check_file( $path ) {
	$limits = guarded_mcp_media_limits();
	$bytes  = (int) filesize( $path );

	if ( $bytes <= 0 ) {
		return new WP_Error(
			'guarded_mcp_media_empty_file',
			__( 'The uploaded file is empty.', 'guarded-mcp' ),
			array( 'status' => 400 )
		);
	}

	if ( $bytes > $limits['max_bytes'] ) {
		return new WP_Error(
			'guarded_mcp_media_too_big',
			sprintf(
				/* translators: 1: file size in MB, 2: limit in MB. */
				__( 'The file is %1$s MB, the limit is %2$d MB. Not uploaded.', 'guarded-mcp' ),
				number_format( $bytes / MB_IN_BYTES, 1 ),
				(int) ( $limits['max_bytes'] / MB_IN_BYTES )
			),
			array( 'status' => 413 )
		);
	}

	// The type comes from the bytes, never from the name or the client: a PHP
	// script renamed to .png does not parse as an image and stops here.
	$info  = wp_getimagesize( $path );
	$types = guarded_mcp_media_types();

	if ( ! $info || empty( $info['mime'] ) || ! isset( $types[ $info['mime'] ] ) ) {
		return new WP_Error(
			'guarded_mcp_media_type_not_allowed',
			__( 'Only PNG, JPEG and WebP images are accepted (the type is read from the file content, not its name). Not uploaded.', 'guarded-mcp' ),
			array( 'status' => 415 )
		);
	}

	$width  = (int) $info[0];
	$height = (int) $info[1];

	if ( $width < 1 || $height < 1 || max( $width, $height ) > $limits['max_side'] ) {
		return new WP_Error(
			'guarded_mcp_media_bad_dimensions',
			sprintf(
				/* translators: 1: width, 2: height, 3: limit. */
				__( 'The image is %1$d×%2$d px; each side must be at most %3$d px. Not uploaded.', 'guarded-mcp' ),
				$width,
				$height,
				$limits['max_side']
			),
			array( 'status' => 422 )
		);
	}

	if ( $width * $height > $limits['max_pixels'] ) {
		return new WP_Error(
			'guarded_mcp_media_bad_dimensions',
			sprintf(
				/* translators: 1: width, 2: height, 3: limit in megapixels. */
				__( 'The image is %1$d×%2$d px; the limit is %3$d megapixels — making its sizes would run out of memory. Not uploaded.', 'guarded-mcp' ),
				$width,
				$height,
				(int) ( $limits['max_pixels'] / 1000000 )
			),
			array( 'status' => 422 )
		);
	}

	if ( guarded_mcp_media_requires_large() && ! guarded_mcp_media_makes_large( $width, $height ) ) {
		$min = guarded_mcp_media_min_size();

		return new WP_Error(
			'guarded_mcp_media_too_small',
			sprintf(
				/* translators: 1: width, 2: height, 3: minimum width, 4: minimum height. */
				__( 'The image is %1$d×%2$d px. WordPress makes the "large" size only from an image at least %3$d px wide or %4$d px tall, so it is not uploaded. Stop and tell the user; do not upscale the image to pass this check.', 'guarded-mcp' ),
				$width,
				$height,
				$min['width'],
				$min['height']
			),
			array( 'status' => 422 )
		);
	}

	// Without editor support for the type (WebP on some hosts) WordPress would
	// save the file but make no sizes, `large` included.
	if ( ! wp_image_editor_supports( array( 'mime_type' => $info['mime'] ) ) ) {
		return new WP_Error(
			'guarded_mcp_media_type_unsupported',
			sprintf(
				/* translators: %s: MIME type. */
				__( 'This server cannot process %s images, so WordPress would make no sizes. Not uploaded. Send PNG or JPEG.', 'guarded-mcp' ),
				$info['mime']
			),
			array( 'status' => 415 )
		);
	}

	return array(
		'mime'   => $info['mime'],
		'ext'    => $types[ $info['mime'] ],
		'width'  => $width,
		'height' => $height,
		'bytes'  => $bytes,
		'sha256' => hash_file( 'sha256', $path ),
	);
}

/**
 * File name to save under: the client's name without its extension, dots turned
 * into dashes (no `shell.php.png`), and the extension of the real type.
 *
 * @param string $name Client file name.
 * @param string $ext  Extension of the real type.
 * @return string
 */
function guarded_mcp_media_filename( $name, $ext ) {
	$base = sanitize_file_name( pathinfo( wp_basename( (string) $name ), PATHINFO_FILENAME ) );
	$base = trim( str_replace( '.', '-', $base ), '-' );

	return ( '' !== $base ? $base : 'image' ) . '.' . $ext;
}

/**
 * Attachment previously uploaded through MCP with the same file content.
 *
 * Only MCP uploads carry the hash; images added by hand are not matched.
 *
 * @param string $sha256 SHA-256 of the file.
 * @return int Attachment ID, 0 if none.
 */
function guarded_mcp_media_find_by_hash( $sha256 ) {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'meta_key'       => '_guarded_mcp_sha256', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $sha256, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'lang'           => '', // All languages: Polylang would filter by the current one.
		)
	);

	return $ids ? (int) $ids[0] : 0;
}

/**
 * Save a checked file to the media library with its metadata.
 *
 * @param string $tmp_path Path of the uploaded temporary file.
 * @param string $name     Client file name.
 * @param array  $checked  Output of guarded_mcp_media_check_file().
 * @param array  $fields   Output of guarded_mcp_media_fields_from_input().
 * @return int|WP_Error Attachment ID.
 */
function guarded_mcp_media_save( $tmp_path, $name, array $checked, array $fields ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$filename = guarded_mcp_media_filename( $name, $checked['ext'] );
	$title    = null !== $fields['title'] ? $fields['title'] : pathinfo( $filename, PATHINFO_FILENAME );

	// wp_insert_post() unslashes what it gets, so the values go in slashed. The
	// description is set empty on purpose: WordPress would otherwise fill it
	// from the file's embedded IPTC caption.
	$attachment_id = media_handle_sideload(
		array(
			'name'     => $filename,
			'tmp_name' => $tmp_path,
		),
		0,
		wp_slash( $title ),
		wp_slash(
			array(
				'post_excerpt' => null !== $fields['caption'] ? $fields['caption'] : '',
				'post_content' => '',
			)
		)
	);

	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	update_post_meta( $attachment_id, '_guarded_mcp_sha256', $checked['sha256'] );

	// Title and caption went in with the insert above.
	$applied = guarded_mcp_media_apply_fields(
		$attachment_id,
		array_merge(
			$fields,
			array(
				'title'   => null,
				'caption' => null,
			)
		)
	);

	return is_wp_error( $applied ) ? $applied : (int) $attachment_id;
}

/**
 * Write validated metadata fields to an attachment. Null fields are left alone.
 *
 * @param int   $attachment_id Attachment ID.
 * @param array $fields        Output of guarded_mcp_media_fields_from_input().
 * @return true|WP_Error
 */
function guarded_mcp_media_apply_fields( $attachment_id, array $fields ) {
	$postarr = array();

	if ( null !== $fields['title'] ) {
		$postarr['post_title'] = $fields['title'];
	}
	if ( null !== $fields['caption'] ) {
		$postarr['post_excerpt'] = $fields['caption'];
	}

	if ( $postarr ) {
		$postarr['ID'] = (int) $attachment_id;
		$result        = wp_update_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	if ( null !== $fields['alt'] ) {
		// update_post_meta() unslashes its value.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( $fields['alt'] ) );
	}

	if ( null !== $fields['language'] && guarded_mcp_media_translated() ) {
		pll_set_post_language( (int) $attachment_id, $fields['language'] );
	}

	return true;
}

/**
 * An image attachment by ID, or an error.
 *
 * @param int $attachment_id Attachment ID.
 * @return WP_Post|WP_Error
 */
function guarded_mcp_media_get_image( $attachment_id ) {
	$post = get_post( (int) $attachment_id );

	if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $post ) ) {
		return new WP_Error(
			'guarded_mcp_media_not_found',
			sprintf(
				/* translators: %d: attachment ID. */
				__( 'Attachment %d is not an image in the media library.', 'guarded-mcp' ),
				(int) $attachment_id
			),
			array( 'status' => 404 )
		);
	}

	return $post;
}

/**
 * Read payload of an image attachment — what block markup needs.
 *
 * When an image has no `large` size (only possible for images added by hand:
 * uploads through MCP are checked for it) `url_large` falls back to the
 * original and `size_slug` says "full", so the markup can name the size it
 * really uses.
 *
 * @param int $attachment_id Attachment ID.
 * @return array
 */
function guarded_mcp_media_payload( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$post          = get_post( $attachment_id );
	$meta          = wp_get_attachment_metadata( $attachment_id );
	$large         = wp_get_attachment_image_src( $attachment_id, 'large' );
	$is_large      = $large && ! empty( $large[3] );

	return array(
		'id'           => $attachment_id,
		'title'        => $post->post_title,
		'file'         => wp_basename( (string) wp_get_original_image_path( $attachment_id ) ),
		'mime_type'    => $post->post_mime_type,
		'language'     => guarded_mcp_media_language( $attachment_id ),
		'alt'          => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		'caption'      => $post->post_excerpt,
		'width'        => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
		'height'       => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
		'url_large'    => $large ? (string) $large[0] : '',
		'large_width'  => $large ? (int) $large[1] : 0,
		'large_height' => $large ? (int) $large[2] : 0,
		'size_slug'    => $is_large ? 'large' : 'full',
		'url_full'     => (string) wp_get_attachment_url( $attachment_id ),
		'edit_url'     => (string) get_edit_post_link( $attachment_id, 'raw' ),
	);
}

/**
 * Output schema of the image payload. The upload route adds `duplicate`.
 *
 * @return array
 */
function guarded_mcp_media_output_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'           => array(
				'type'        => 'integer',
				'description' => 'Attachment ID: {"id":…} in the core/image delimiter and the wp-image-… class; featured_media of an article.',
			),
			'title'        => array( 'type' => 'string' ),
			'file'         => array(
				'type'        => 'string',
				'description' => 'File name of the original.',
			),
			'mime_type'    => array( 'type' => 'string' ),
			'language'     => array(
				'type'        => 'string',
				'description' => 'Polylang language slug, empty if none.',
			),
			'alt'          => array(
				'type'        => 'string',
				'description' => 'Alt text stored in the media library.',
			),
			'caption'      => array(
				'type'        => 'string',
				'description' => 'Caption stored in the media library.',
			),
			'width'        => array( 'type' => 'integer' ),
			'height'       => array( 'type' => 'integer' ),
			'url_large'    => array(
				'type'        => 'string',
				'description' => 'URL for <img src> in block markup: the "large" size, or the original when size_slug is "full".',
			),
			'large_width'  => array( 'type' => 'integer' ),
			'large_height' => array( 'type' => 'integer' ),
			'size_slug'    => array(
				'type'        => 'string',
				'description' => 'The size url_large points to — "large", or "full" when the image has no large size. Use it as sizeSlug and in the size-… class.',
			),
			'url_full'     => array(
				'type'        => 'string',
				'description' => 'URL of the full-size image.',
			),
			'edit_url'     => array( 'type' => 'string' ),
		),
	);
}
