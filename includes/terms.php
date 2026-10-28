<?php
/**
 * Shared helpers for the taxonomy term abilities.
 *
 * get-terms / create-term / update-term work on a closed list of taxonomies
 * (guarded_mcp_term_taxonomies()): blog categories and tags by default, more
 * through the `guarded_mcp_term_taxonomies` filter. One `taxonomy` parameter
 * instead of an ability per taxonomy — they share every rule.
 *
 * Polylang. Categories and tags are translated taxonomies: each language has its
 * own terms, linked into a translation group. A term created without a language
 * would land in the site default language, so for a translated taxonomy
 * create-term requires `language`, and `translation_of` links the new term to
 * its counterpart. For a taxonomy Polylang does not translate, language fields
 * are rejected rather than ignored.
 *
 * There is no delete ability on purpose.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomies the term abilities may touch.
 *
 * @return string[]
 */
function guarded_mcp_term_taxonomies() {
	return (array) apply_filters(
		'guarded_mcp_term_taxonomies',
		array( 'category', 'post_tag' )
	);
}

/**
 * Input schema of the `taxonomy` parameter.
 *
 * @return array
 */
function guarded_mcp_taxonomy_schema() {
	return array(
		'type'        => 'string',
		'enum'        => guarded_mcp_term_taxonomies(),
		'description' => 'Taxonomy slug: "category" (blog categories), "post_tag" (blog tags) or another taxonomy the site allows. With Polylang, translated taxonomies have separate terms per language.',
	);
}

/**
 * Resolve an allowed, registered taxonomy.
 *
 * @param string $taxonomy Taxonomy slug from input.
 * @return WP_Taxonomy|WP_Error
 */
function guarded_mcp_term_taxonomy( $taxonomy ) {
	$taxonomy = (string) $taxonomy;

	if ( ! in_array( $taxonomy, guarded_mcp_term_taxonomies(), true ) || ! taxonomy_exists( $taxonomy ) ) {
		return new WP_Error(
			'guarded_mcp_taxonomy_not_allowed',
			sprintf(
				/* translators: %s: taxonomy slug. */
				__( 'Taxonomy "%s" is not available through MCP.', 'guarded-mcp' ),
				$taxonomy
			),
			array( 'taxonomy' => $taxonomy )
		);
	}

	return get_taxonomy( $taxonomy );
}

/**
 * Whether Polylang translates terms of this taxonomy.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return bool
 */
function guarded_mcp_term_is_translated( $taxonomy ) {
	return function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy );
}

/**
 * A term of the given taxonomy, or an error.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy slug.
 * @param string $field    Input field name, for the error message.
 * @return WP_Term|WP_Error
 */
function guarded_mcp_existing_term( $term_id, $taxonomy, $field = 'id' ) {
	$term = get_term( (int) $term_id, $taxonomy );

	if ( ! $term instanceof WP_Term ) {
		return new WP_Error(
			'guarded_mcp_term_not_found',
			sprintf(
				/* translators: 1: term ID, 2: taxonomy slug. */
				__( 'Term %1$d does not exist in taxonomy "%2$s".', 'guarded-mcp' ),
				(int) $term_id,
				$taxonomy
			),
			array( $field => (int) $term_id )
		);
	}

	return $term;
}

/**
 * Input schemas of the editable term fields (create-term and update-term).
 *
 * @return array<string,array>
 */
function guarded_mcp_term_field_schemas() {
	return array(
		'name'           => array(
			'type'        => 'string',
			'minLength'   => 1,
			'description' => 'Term name as shown on the site, e.g. the category pill in the article header.',
		),
		'slug'           => array(
			'type'        => 'string',
			'description' => 'Optional URL slug. Generated from the name when omitted. Slugs are unique within a taxonomy across all languages, so a translation usually needs its own (e.g. "websites-el").',
		),
		'description'    => array(
			'type'        => 'string',
			'description' => 'Optional plain-text description (shown on the term archive).',
		),
		'parent'         => array(
			'type'        => 'integer',
			'minimum'     => 0,
			'description' => 'Parent term ID for hierarchical taxonomies (e.g. category); 0 = top level. Rejected for flat taxonomies such as tags.',
		),
		'language'       => array(
			'type'        => 'string',
			'description' => 'Polylang language slug (e.g. "en"). Required when creating a term of a translated taxonomy: without it the term lands in the site default language. Rejected for taxonomies Polylang does not translate.',
		),
		'translation_of' => array(
			'type'        => 'integer',
			'minimum'     => 1,
			'description' => 'Optional ID of the same term in another language: links both into one Polylang translation group. Category and tag only.',
		),
	);
}

/**
 * Validate term fields from ability input — everything before the first write.
 *
 * @param array       $input    Ability input.
 * @param WP_Taxonomy $taxonomy Resolved taxonomy.
 * @param WP_Term     $term     Term being updated; null on create.
 * @return array{args:array,language:?string,translation_of:?int}|WP_Error
 */
function guarded_mcp_term_fields_from_input( array $input, WP_Taxonomy $taxonomy, $term = null ) {
	$result = array(
		'args'           => array(),
		'language'       => null,
		'translation_of' => null,
	);

	if ( array_key_exists( 'name', $input ) ) {
		$name = trim( sanitize_text_field( (string) $input['name'] ) );
		if ( '' === $name ) {
			return new WP_Error( 'guarded_mcp_term_name_empty', __( 'The term name cannot be empty.', 'guarded-mcp' ) );
		}
		$result['args']['name'] = $name;
	}

	if ( array_key_exists( 'slug', $input ) && '' !== (string) $input['slug'] ) {
		$result['args']['slug'] = sanitize_title( (string) $input['slug'] );
	}

	if ( array_key_exists( 'description', $input ) ) {
		$result['args']['description'] = sanitize_textarea_field( (string) $input['description'] );
	}

	if ( array_key_exists( 'parent', $input ) ) {
		$parent = (int) $input['parent'];

		if ( ! $taxonomy->hierarchical ) {
			return new WP_Error(
				'guarded_mcp_term_parent_not_supported',
				sprintf(
					/* translators: %s: taxonomy slug. */
					__( 'Taxonomy "%s" is not hierarchical: parent is not supported.', 'guarded-mcp' ),
					$taxonomy->name
				)
			);
		}

		if ( $parent > 0 ) {
			$parent_term = guarded_mcp_existing_term( $parent, $taxonomy->name, 'parent' );
			if ( is_wp_error( $parent_term ) ) {
				return $parent_term;
			}

			// A term cannot sit under itself or under one of its own descendants.
			if ( $term && ( $parent === (int) $term->term_id || in_array( (int) $term->term_id, get_ancestors( $parent, $taxonomy->name, 'taxonomy' ), true ) ) ) {
				return new WP_Error(
					'guarded_mcp_term_parent_loop',
					__( 'A term cannot be its own parent or the child of its own descendant.', 'guarded-mcp' )
				);
			}
		}

		$result['args']['parent'] = $parent;
	}

	$translated = guarded_mcp_term_is_translated( $taxonomy->name );

	foreach ( array( 'language', 'translation_of' ) as $field ) {
		if ( array_key_exists( $field, $input ) && ! $translated ) {
			return new WP_Error(
				'guarded_mcp_term_not_translated',
				sprintf(
					/* translators: 1: field name, 2: taxonomy slug. */
					__( '"%1$s" is not supported: taxonomy "%2$s" is not translated.', 'guarded-mcp' ),
					$field,
					$taxonomy->name
				)
			);
		}
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

		$result['language'] = $language;
	} elseif ( $translated && ! $term ) {
		return new WP_Error(
			'guarded_mcp_term_language_required',
			sprintf(
				/* translators: %s: taxonomy slug. */
				__( 'Pass "language" when creating a term in "%s": without it Polylang assigns the site default language.', 'guarded-mcp' ),
				$taxonomy->name
			)
		);
	}

	// The language the term will have once saved.
	$target_language = $result['language'];
	if ( null === $target_language && $term && $translated ) {
		$target_language = (string) pll_get_term_language( $term->term_id, 'slug' );
	}

	// Moving a term to a language its translation group already covers would
	// leave two terms of one language in the group.
	if ( $term && $translated && null !== $result['language'] && ! array_key_exists( 'translation_of', $input ) ) {
		$group = (array) pll_get_term_translations( $term->term_id );
		if ( isset( $group[ $target_language ] ) && (int) $group[ $target_language ] !== (int) $term->term_id ) {
			return new WP_Error(
				'guarded_mcp_term_translation_conflict',
				sprintf(
					/* translators: 1: language slug, 2: term ID. */
					__( 'The translation group already has a "%1$s" term (%2$d).', 'guarded-mcp' ),
					$target_language,
					(int) $group[ $target_language ]
				)
			);
		}
	}

	if ( array_key_exists( 'translation_of', $input ) ) {
		$source = guarded_mcp_existing_term( (int) $input['translation_of'], $taxonomy->name, 'translation_of' );
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$source_language = (string) pll_get_term_language( $source->term_id, 'slug' );
		$group           = (array) pll_get_term_translations( $source->term_id );
		$own_id          = $term ? (int) $term->term_id : 0;

		if ( '' === $source_language || $source_language === $target_language ) {
			return new WP_Error(
				'guarded_mcp_term_translation_language',
				__( 'translation_of must point to a term in another language that has a language set.', 'guarded-mcp' )
			);
		}

		if ( isset( $group[ $target_language ] ) && (int) $group[ $target_language ] !== $own_id ) {
			return new WP_Error(
				'guarded_mcp_term_translation_conflict',
				sprintf(
					/* translators: 1: language slug, 2: term ID. */
					__( 'The translation group already has a "%1$s" term (%2$d).', 'guarded-mcp' ),
					$target_language,
					(int) $group[ $target_language ]
				)
			);
		}

		$result['translation_of'] = (int) $source->term_id;
	}

	// Duplicates are checked here rather than left to wp_insert_term(): with
	// Polylang active, inserting an existing name returns the EXISTING term
	// instead of an error, and the agent would report a term it never made.
	$own_id = $term ? (int) $term->term_id : 0;

	if ( isset( $result['args']['name'] ) ) {
		$query = array(
			'taxonomy'   => $taxonomy->name,
			'name'       => $result['args']['name'],
			'parent'     => array_key_exists( 'parent', $result['args'] ) ? $result['args']['parent'] : ( $term ? (int) $term->parent : 0 ),
			'hide_empty' => false,
			'exclude'    => $own_id ? array( $own_id ) : array(),
			'fields'     => 'ids',
		);
		if ( $translated ) {
			$query['lang'] = (string) $target_language;
		}

		$same_name = get_terms( $query );
		if ( $same_name && ! is_wp_error( $same_name ) ) {
			return new WP_Error(
				'guarded_mcp_term_exists',
				sprintf(
					/* translators: 1: term name, 2: term ID. */
					__( 'A term named "%1$s" already exists here (ID %2$d). Use it, or pick another name.', 'guarded-mcp' ),
					$result['args']['name'],
					(int) $same_name[0]
				),
				array( 'id' => (int) $same_name[0] )
			);
		}
	}

	if ( isset( $result['args']['slug'] ) ) {
		$query = array(
			'taxonomy'   => $taxonomy->name,
			'slug'       => $result['args']['slug'],
			'hide_empty' => false,
			'exclude'    => $own_id ? array( $own_id ) : array(),
			'fields'     => 'ids',
		);
		if ( $translated ) {
			$query['lang'] = ''; // Slugs are unique across all languages.
		}

		$same_slug = get_terms( $query );
		if ( $same_slug && ! is_wp_error( $same_slug ) ) {
			return new WP_Error(
				'guarded_mcp_term_slug_exists',
				sprintf(
					/* translators: 1: slug, 2: term ID. */
					__( 'The slug "%1$s" is already used by term %2$d.', 'guarded-mcp' ),
					$result['args']['slug'],
					(int) $same_slug[0]
				),
				array( 'id' => (int) $same_slug[0] )
			);
		}
	}

	return $result;
}

/**
 * Apply the Polylang writes after the term exists.
 *
 * @param int   $term_id Term ID.
 * @param array $fields  Output of guarded_mcp_term_fields_from_input().
 * @return void
 */
function guarded_mcp_apply_term_language( $term_id, array $fields ) {
	if ( null !== $fields['language'] && function_exists( 'pll_set_term_language' ) ) {
		pll_set_term_language( $term_id, $fields['language'] );
	}

	if ( null !== $fields['translation_of'] && function_exists( 'pll_save_term_translations' ) ) {
		$group = (array) pll_get_term_translations( $fields['translation_of'] );

		$group[ (string) pll_get_term_language( $term_id, 'slug' ) ] = (int) $term_id;

		pll_save_term_translations( $group );
	}
}

/**
 * Read payload of a term.
 *
 * @param WP_Term $term Term.
 * @return array
 */
function guarded_mcp_term_payload( WP_Term $term ) {
	$translated   = guarded_mcp_term_is_translated( $term->taxonomy );
	$translations = $translated ? array_map( 'intval', (array) pll_get_term_translations( $term->term_id ) ) : array();
	$link         = get_term_link( $term );

	return array(
		'id'           => (int) $term->term_id,
		'taxonomy'     => $term->taxonomy,
		'name'         => $term->name,
		'slug'         => $term->slug,
		'description'  => $term->description,
		'parent'       => (int) $term->parent,
		'count'        => (int) $term->count,
		'language'     => $translated ? (string) pll_get_term_language( $term->term_id, 'slug' ) : '',
		// A JSON object even when empty: language slug => term ID.
		'translations' => (object) $translations,
		'link'         => is_wp_error( $link ) ? '' : (string) $link,
	);
}

/**
 * Output schema of one term.
 *
 * @return array
 */
function guarded_mcp_term_output_schema() {
	return array(
		'type'       => 'object',
		'properties' => array(
			'id'           => array( 'type' => 'integer' ),
			'taxonomy'     => array( 'type' => 'string' ),
			'name'         => array( 'type' => 'string' ),
			'slug'         => array( 'type' => 'string' ),
			'description'  => array( 'type' => 'string' ),
			'parent'       => array(
				'type'        => 'integer',
				'description' => 'Parent term ID, 0 = top level.',
			),
			'count'        => array(
				'type'        => 'integer',
				'description' => 'Published items using the term.',
			),
			'language'     => array(
				'type'        => 'string',
				'description' => 'Polylang language slug; empty for taxonomies that are not translated.',
			),
			'translations' => array(
				'type'                 => 'object',
				'description'          => 'Translation group: language slug => term ID (includes this term).',
				'additionalProperties' => array( 'type' => 'integer' ),
			),
			'link'         => array( 'type' => 'string' ),
		),
	);
}
