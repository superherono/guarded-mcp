<?php
/**
 * Shared helpers for the blog post abilities.
 *
 * get-post / create-post / update-post are deliberately separate from the page
 * abilities rather than a `post_type` switch on them:
 *  - a forgotten switch would silently create a page instead of an article;
 *  - articles carry fields a page doesn't (category, cover image, excerpt,
 *    language), so the schemas differ;
 *  - posts have their own capability family (`edit_posts`).
 *
 * What the three share — the post-only field schemas, their validation and the
 * read payload — lives here so it is written once.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Input schemas for the fields only an article has. Shared by create-post and
 * update-post so both describe them identically to the agent.
 *
 * @return array<string,array>
 */
function guarded_mcp_post_field_schemas() {
	return array(
		'excerpt'        => array(
			'type'        => 'string',
			'description' => 'Optional excerpt: the short summary shown on the post card in blog listings.',
		),
		'categories'     => array(
			'type'        => 'array',
			'items'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'minItems'    => 1,
			'description' => 'Existing category IDs. Use categories of the same language as the article; list them with get-terms (taxonomy "category"), create a new one with create-term. On update the list REPLACES the current categories.',
		),
		'featured_media' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'ID of an existing image in the media library, in the language of the article (with Polylang a cover in another language is rejected). It becomes the featured image; themes usually render it with the alt and caption stored in the media library. A new image is uploaded first with prepare-media-upload; this field only takes its id.',
		),
		'language'       => array(
			'type'        => 'string',
			'description' => 'Polylang language slug for the article (e.g. "en"). Set it on create: without it Polylang assigns the site default language, and the article lands in the wrong-language blog. Ignored when Polylang is not active.',
		),
	);
}

/**
 * Validate the post-only fields from ability input and turn them into
 * wp_insert_post() arguments plus the follow-up writes that wp_insert_post()
 * doesn't cover (cover image, language).
 *
 * Only existing categories and existing image attachments are accepted: a new
 * category is made first with create-term and a new image is uploaded first
 * with prepare-media-upload (the article write creates neither as a side
 * effect), and a typo must fail loudly rather than save an article without its
 * category or cover.
 *
 * With Polylang the cover must be in the language of the article: themes render
 * the cover alt and caption from the media library, so a cover in another
 * language puts that language on the page.
 *
 * @param array $input   Ability input.
 * @param int   $post_id Post being updated; 0 on create.
 * @return array{postarr:array,thumbnail:?int,language:?string}|WP_Error
 */
function guarded_mcp_post_fields_from_input( array $input, $post_id = 0 ) {
	$result = array(
		'postarr'   => array(),
		'thumbnail' => null,
		'language'  => null,
	);

	if ( array_key_exists( 'excerpt', $input ) ) {
		$result['postarr']['post_excerpt'] = sanitize_textarea_field( (string) $input['excerpt'] );
	}

	if ( array_key_exists( 'categories', $input ) ) {
		$ids = array_values( array_unique( array_map( 'intval', (array) $input['categories'] ) ) );

		if ( ! $ids ) {
			return new WP_Error(
				'guarded_mcp_category_required',
				__( 'Pass at least one category ID.', 'guarded-mcp' )
			);
		}

		foreach ( $ids as $id ) {
			$term = get_term( $id, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				return new WP_Error(
					'guarded_mcp_category_not_found',
					sprintf(
						/* translators: %d: category ID. */
						__( 'Category %d does not exist. Use the ID of an existing category.', 'guarded-mcp' ),
						$id
					),
					array( 'category' => $id )
				);
			}
		}

		$result['postarr']['post_category'] = $ids;
	}

	if ( array_key_exists( 'featured_media', $input ) ) {
		$media_id = (int) $input['featured_media'];

		if ( ! wp_attachment_is_image( $media_id ) ) {
			return new WP_Error(
				'guarded_mcp_media_not_found',
				sprintf(
					/* translators: %d: attachment ID. */
					__( 'Attachment %d is not an image in the media library.', 'guarded-mcp' ),
					$media_id
				),
				array( 'featured_media' => $media_id )
			);
		}

		$result['thumbnail'] = $media_id;
	}

	if ( array_key_exists( 'language', $input ) && function_exists( 'pll_languages_list' ) ) {
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

		$result['language'] = $language;
	}

	$cover_error = guarded_mcp_post_cover_language_error( $result, (int) $post_id );
	if ( is_wp_error( $cover_error ) ) {
		return $cover_error;
	}

	return $result;
}

/**
 * Reject a cover whose language differs from the article's.
 *
 * Checked when the write sets a cover, or changes the language of an article
 * that already has one. Skipped when Polylang does not translate media or
 * either side has no language.
 *
 * @param array $fields  Validated fields (thumbnail, language) from the input.
 * @param int   $post_id Post being updated; 0 on create.
 * @return true|WP_Error
 */
function guarded_mcp_post_cover_language_error( array $fields, $post_id ) {
	if ( ! guarded_mcp_media_translated() ) {
		return true;
	}

	$cover = $fields['thumbnail'];
	if ( null === $cover && $post_id && null !== $fields['language'] ) {
		$cover = (int) get_post_thumbnail_id( $post_id );
	}
	if ( ! $cover ) {
		return true;
	}

	if ( null !== $fields['language'] ) {
		$post_language = $fields['language'];
	} elseif ( $post_id ) {
		$post_language = (string) pll_get_post_language( $post_id, 'slug' );
	} else {
		// Created without a language: Polylang will assign the default one.
		$post_language = (string) pll_default_language( 'slug' );
	}

	$cover_language = guarded_mcp_media_language( $cover );

	if ( '' === $post_language || '' === $cover_language || $post_language === $cover_language ) {
		return true;
	}

	return new WP_Error(
		'guarded_mcp_cover_language_mismatch',
		sprintf(
			/* translators: 1: attachment ID, 2: its language, 3: article language. */
			__( 'Cover image %1$d is in "%2$s", the article is in "%3$s". Use a cover in the article language, or fix the image language with update-media if it is wrong.', 'guarded-mcp' ),
			$cover,
			$cover_language,
			$post_language
		),
		array( 'featured_media' => $cover )
	);
}

/**
 * Apply the writes wp_insert_post() doesn't cover, after the post exists.
 *
 * @param int   $post_id Post ID.
 * @param array $fields  Output of guarded_mcp_post_fields_from_input().
 * @return void
 */
function guarded_mcp_apply_post_extras( $post_id, array $fields ) {
	if ( null !== $fields['thumbnail'] ) {
		set_post_thumbnail( $post_id, $fields['thumbnail'] );
	}

	if ( null !== $fields['language'] && function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $post_id, $fields['language'] );
	}
}

/**
 * Full read payload of an article (get-post).
 *
 * @param WP_Post $post Post object.
 * @return array
 */
function guarded_mcp_post_payload( WP_Post $post ) {
	$categories = array();
	foreach ( (array) get_the_category( $post->ID ) as $term ) {
		$categories[] = array(
			'id'   => (int) $term->term_id,
			'name' => $term->name,
			'slug' => $term->slug,
		);
	}

	return array_merge(
		array(
			'id'             => (int) $post->ID,
			'title'          => $post->post_title,
			'slug'           => $post->post_name,
			'status'         => $post->post_status,
			'content'        => $post->post_content,
			'excerpt'        => $post->post_excerpt,
			'categories'     => $categories,
			'featured_media' => (int) get_post_thumbnail_id( $post ),
			'language'       => function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post->ID, 'slug' ) : '',
			'link'           => (string) get_permalink( $post ),
			'edit_url'       => (string) get_edit_post_link( $post->ID, 'raw' ),
		),
		guarded_mcp_seo_payload( $post->ID )
	);
}

/**
 * Output schema shared by create-post and update-post.
 *
 * @return array
 */
function guarded_mcp_post_write_output_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'       => array( 'type' => 'integer' ),
			'status'   => array( 'type' => 'string' ),
			'edit_url' => array( 'type' => 'string' ),
		),
	);
}
