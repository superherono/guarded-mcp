<?php
/**
 * Yoast SEO fields shared by the page and post abilities.
 *
 * Two fields only: the SEO title and the meta description Yoast stores per
 * post (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`). They are read and
 * written through Yoast's own `WPSEO_Meta` API rather than raw post meta, so
 * Yoast's sanitising and default handling apply; Yoast's post-meta watcher
 * refreshes its indexable (the stored <title>/description) on the meta write.
 *
 * Without Yoast the read payload returns empty strings, and a write that
 * carries either field fails loudly instead of silently dropping it.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Yoast SEO's meta API is available.
 *
 * @return bool
 */
function guarded_mcp_seo_available() {
	return class_exists( 'WPSEO_Meta' );
}

/**
 * Map of ability field → Yoast meta key (without the `_yoast_wpseo_` prefix).
 *
 * @return array<string,string>
 */
function guarded_mcp_seo_keys() {
	return array(
		'seo_title'       => 'title',
		'seo_description' => 'metadesc',
	);
}

/**
 * Input schemas for the SEO fields — merged into create/update of pages and posts.
 *
 * @return array<string,array>
 */
function guarded_mcp_seo_field_schemas() {
	return array(
		'seo_title'       => array(
			'type'        => 'string',
			'description' => 'Yoast SEO title: the <title> tag and the headline in search results. Plain text; Yoast variables such as %%sitename%% or %%sep%% are allowed. An empty string clears it, and Yoast falls back to its title template.',
		),
		'seo_description' => array(
			'type'        => 'string',
			'description' => 'Yoast meta description: the snippet under the headline in search results. Plain text, about 120–156 characters. An empty string clears it.',
		),
	);
}

/**
 * Output schemas for the SEO fields in read payloads.
 *
 * @return array<string,array>
 */
function guarded_mcp_seo_output_schemas() {
	return array(
		'seo_title'       => array(
			'type'        => 'string',
			'description' => 'Yoast SEO title as stored; empty when the Yoast title template applies (or Yoast is inactive).',
		),
		'seo_description' => array(
			'type'        => 'string',
			'description' => 'Yoast meta description as stored; empty when none is set (or Yoast is inactive).',
		),
	);
}

/**
 * Validate the SEO fields present in ability input.
 *
 * Tags are stripped and whitespace collapsed, but `%` is left alone:
 * sanitize_text_field() would eat percent sequences and break Yoast variables
 * such as %%category%%.
 *
 * @param array $input Ability input.
 * @return array<string,string>|WP_Error Yoast meta key => value, only for fields that were passed.
 */
function guarded_mcp_seo_from_input( array $input ) {
	$seo = array();

	foreach ( guarded_mcp_seo_keys() as $field => $key ) {
		if ( array_key_exists( $field, $input ) ) {
			$seo[ $key ] = trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $input[ $field ] ) ) );
		}
	}

	if ( $seo && ! guarded_mcp_seo_available() ) {
		return new WP_Error(
			'guarded_mcp_seo_unavailable',
			__( 'Yoast SEO is not active, so seo_title and seo_description cannot be saved.', 'guarded-mcp' )
		);
	}

	return $seo;
}

/**
 * Write validated SEO fields to a post. An empty value removes the meta, so
 * Yoast's template takes over again.
 *
 * @param int                  $post_id Post ID.
 * @param array<string,string> $seo     Output of guarded_mcp_seo_from_input().
 * @return void
 */
function guarded_mcp_apply_seo( $post_id, array $seo ) {
	foreach ( $seo as $key => $value ) {
		if ( '' === $value ) {
			WPSEO_Meta::delete( $key, $post_id );
		} else {
			// set_value() slashes the value itself — pass it unslashed.
			WPSEO_Meta::set_value( $key, $value, $post_id );
		}
	}
}

/**
 * SEO fields of a post for read payloads.
 *
 * @param int $post_id Post ID.
 * @return array{seo_title:string,seo_description:string}
 */
function guarded_mcp_seo_payload( $post_id ) {
	$payload = array();

	foreach ( guarded_mcp_seo_keys() as $field => $key ) {
		$payload[ $field ] = guarded_mcp_seo_available()
			? (string) WPSEO_Meta::get_value( $key, (int) $post_id )
			: '';
	}

	return $payload;
}
