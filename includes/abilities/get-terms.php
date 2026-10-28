<?php
/**
 * Ability: guarded-mcp/get-terms — list the terms of one taxonomy. Read-only.
 *
 * Terms of the allowed taxonomies with their IDs, parents, usage
 * counts, Polylang language and translation group — what the agent needs to
 * pick a category for create-post, or to decide whether a term has to be
 * created first. See terms.php.
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
			'guarded-mcp/get-terms',
			array(
				'label'               => __( 'List taxonomy terms', 'guarded-mcp' ),
				'description'         => __( 'List the terms of one allowed taxonomy (by default blog categories and tags) with id, name, slug, description, parent, usage count, Polylang language and translations. Filter by language, search text or parent. Read-only.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-taxonomies',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'taxonomy' => guarded_mcp_taxonomy_schema(),
						'language' => array(
							'type'        => 'string',
							'description' => 'Optional Polylang language slug (e.g. "en"): only terms of that language. Omit to list all languages. Ignored for taxonomies Polylang does not translate.',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Optional text to match in the term name or slug.',
						),
						'parent'   => array(
							'type'        => 'integer',
							'minimum'     => 0,
							'description' => 'Optional: only direct children of this term ID (0 = top-level terms).',
						),
						'limit'    => array(
							'type'        => 'integer',
							'minimum'     => 1,
							'maximum'     => 500,
							'description' => 'Maximum number of terms to return (default 200).',
						),
					),
					'required'             => array( 'taxonomy' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array( 'type' => 'string' ),
						'total'    => array(
							'type'        => 'integer',
							'description' => 'Terms matching the filters, before the limit.',
						),
						'terms'    => array(
							'type'  => 'array',
							'items' => guarded_mcp_term_output_schema(),
						),
					),
				),
				'execute_callback'    => function ( $input ) {
					$taxonomy = guarded_mcp_term_taxonomy( $input['taxonomy'] ?? '' );
					if ( is_wp_error( $taxonomy ) ) {
						return $taxonomy;
					}

					$query = array(
						'taxonomy'   => $taxonomy->name,
						'hide_empty' => false,
						'orderby'    => 'name',
					);

					// Polylang filters term queries by the current language unless told
					// otherwise; an empty `lang` means every language.
					if ( guarded_mcp_term_is_translated( $taxonomy->name ) ) {
						$query['lang'] = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';

						if ( '' !== $query['lang'] && ! in_array( $query['lang'], (array) pll_languages_list( array( 'fields' => 'slug' ) ), true ) ) {
							return new WP_Error(
								'guarded_mcp_language_not_found',
								sprintf(
									/* translators: %s: language slug. */
									__( 'Language "%s" is not configured in Polylang.', 'guarded-mcp' ),
									$query['lang']
								)
							);
						}
					}

					if ( ! empty( $input['search'] ) ) {
						$query['search'] = sanitize_text_field( (string) $input['search'] );
					}

					if ( array_key_exists( 'parent', $input ) ) {
						$query['parent'] = (int) $input['parent'];
					}

					$terms = get_terms( $query );
					if ( is_wp_error( $terms ) ) {
						return $terms;
					}

					$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 200;

					return array(
						'taxonomy' => $taxonomy->name,
						'total'    => count( $terms ),
						'terms'    => array_map( 'guarded_mcp_term_payload', array_slice( $terms, 0, $limit ) ),
					);
				},
				'permission_callback' => function ( $input ) {
					$taxonomy = guarded_mcp_term_taxonomy( $input['taxonomy'] ?? '' );
					return ! is_wp_error( $taxonomy ) && current_user_can( $taxonomy->cap->assign_terms );
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
		$abilities[] = 'guarded-mcp/get-terms';
		return $abilities;
	}
);
