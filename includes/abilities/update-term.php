<?php
/**
 * Ability: guarded-mcp/update-term — edit a blog category, blog tag or case
 * study category.
 *
 * Name, slug, description, parent, and for categories and tags the Polylang
 * language and translation link. Everything is validated before the first
 * write (terms.php). Posts keep their terms: renaming a category renames the
 * pill on every article in it.
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
			'guarded-mcp/update-term',
			array(
				'label'               => __( 'Update taxonomy term', 'guarded-mcp' ),
				'description'         => __( 'Edit a term of an allowed taxonomy (by default blog categories and tags): name, slug, description, parent, and for taxonomies Polylang translates the language and translation link — at least one. Changes show on every post that uses the term; changing a slug changes its archive URL. Returns the updated term.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-taxonomies',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'taxonomy' => guarded_mcp_taxonomy_schema(),
							'id'       => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'ID of the term to update.',
							),
						),
						guarded_mcp_term_field_schemas()
					),
					'required'             => array( 'taxonomy', 'id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => guarded_mcp_term_output_schema(),
				'execute_callback'    => function ( $input ) {
					$taxonomy = guarded_mcp_term_taxonomy( $input['taxonomy'] ?? '' );
					if ( is_wp_error( $taxonomy ) ) {
						return $taxonomy;
					}

					$term = guarded_mcp_existing_term( $input['id'] ?? 0, $taxonomy->name );
					if ( is_wp_error( $term ) ) {
						return $term;
					}

					if ( ! array_intersect( array_keys( $input ), array_keys( guarded_mcp_term_field_schemas() ) ) ) {
						return new WP_Error(
							'guarded_mcp_nothing_to_update',
							__( 'Provide at least one of: name, slug, description, parent, language, translation_of.', 'guarded-mcp' )
						);
					}

					$fields = guarded_mcp_term_fields_from_input( $input, $taxonomy, $term );
					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					// Language / translation link alone don't touch the term row.
					if ( $fields['args'] ) {
						$updated = wp_update_term( $term->term_id, $taxonomy->name, $fields['args'] );
						if ( is_wp_error( $updated ) ) {
							return $updated;
						}
					}

					guarded_mcp_apply_term_language( $term->term_id, $fields );

					clean_term_cache( $term->term_id, $taxonomy->name );

					return guarded_mcp_term_payload( get_term( $term->term_id, $taxonomy->name ) );
				},
				'permission_callback' => function ( $input ) {
					$id = isset( $input['id'] ) ? (int) $input['id'] : 0;
					return $id > 0 && current_user_can( 'edit_term', $id );
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
		$abilities[] = 'guarded-mcp/update-term';
		return $abilities;
	}
);
