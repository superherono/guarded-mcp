<?php
/**
 * Ability: guarded-mcp/create-term — create a blog category, blog tag or case
 * study category.
 *
 * All fields are validated before the first write (terms.php). For categories
 * and tags the language is required and a translation can be linked in the
 * same call. Terms are never deleted through MCP.
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
			'guarded-mcp/create-term',
			array(
				'label'               => __( 'Create taxonomy term', 'guarded-mcp' ),
				'description'         => __( 'Create a term in one of the allowed taxonomies (by default blog categories and tags). With Polylang, "language" is required for a translated taxonomy, and "translation_of" links the new term to the same term in another language. Fails if a term with that name or slug already exists — check with get-terms first. Returns the created term.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-taxonomies',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array( 'taxonomy' => guarded_mcp_taxonomy_schema() ),
						guarded_mcp_term_field_schemas()
					),
					'required'             => array( 'taxonomy', 'name' ),
					'additionalProperties' => false,
				),
				'output_schema'       => guarded_mcp_term_output_schema(),
				'execute_callback'    => function ( $input ) {
					$taxonomy = guarded_mcp_term_taxonomy( $input['taxonomy'] ?? '' );
					if ( is_wp_error( $taxonomy ) ) {
						return $taxonomy;
					}

					$fields = guarded_mcp_term_fields_from_input( $input, $taxonomy );
					if ( is_wp_error( $fields ) ) {
						return $fields;
					}

					$args = $fields['args'];
					$name = $args['name'];
					unset( $args['name'] );

					$created = wp_insert_term( $name, $taxonomy->name, $args );
					if ( is_wp_error( $created ) ) {
						return $created;
					}

					$term_id = (int) $created['term_id'];

					guarded_mcp_apply_term_language( $term_id, $fields );

					clean_term_cache( $term_id, $taxonomy->name );

					return guarded_mcp_term_payload( get_term( $term_id, $taxonomy->name ) );
				},
				'permission_callback' => function ( $input ) {
					$taxonomy = guarded_mcp_term_taxonomy( $input['taxonomy'] ?? '' );
					return ! is_wp_error( $taxonomy ) && current_user_can( $taxonomy->cap->edit_terms );
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => false,
						'destructive' => false, // Additive only: a new term.
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
		$abilities[] = 'guarded-mcp/create-term';
		return $abilities;
	}
);
