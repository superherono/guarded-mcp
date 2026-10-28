<?php
/**
 * Shared helpers for the nav menu abilities.
 *
 * The get-menus and update-menu abilities work on the classic menus the theme
 * renders: one per theme location (guarded_mcp_menu_locations()) and, with
 * Polylang, per language. A menu is addressed by location + language, never by
 * a free menu ID, so MCP can only touch menus the site actually shows.
 *
 * Menus differ from pages in three ways, and the abilities are built around them:
 *  - no draft: a saved change shows on every page at once. update-menu is a dry
 *    run unless the client passes dry_run=false, and returns the resulting tree;
 *  - no revisions: before every write the whole menu is copied into a backup
 *    (option guarded_mcp_menu_backups, last 10 per menu), and update-menu
 *    restores one by ID. A write that fails half-way is rolled back from it;
 *  - removing an item deletes a nav_menu_item post. That is the one delete MCP
 *    allows — a link, never the page it points to (ARCHITECTURE.md, invariant 4).
 *
 * Every change is validated on an in-memory model of the whole menu before the
 * first write: links resolve to published objects of the menu's language,
 * custom URLs are http(s) / mailto / tel / root-relative / #anchor, nesting stays
 * within what the location renders.
 *
 * Rights: the plugin's own cap `guarded_mcp_edit_menus` instead of core
 * `edit_theme_options`, which would also open the Customizer and widgets.
 *
 * @package guarded-mcp
 */

defined( 'ABSPATH' ) || exit;

/**
 * Theme locations the menu abilities may touch, with their render rules.
 *
 * By default every classic location the theme registers, two levels deep, with
 * the theme's own description as the note. max_depth — levels the location
 * renders (1 = flat); note — how the theme shows it, for the client. Filter
 * `guarded_mcp_menu_locations` to hide a location, set its real depth or
 * describe how it renders; locations the theme has not registered are dropped.
 *
 * @return array<string,array{max_depth:int,note:string}>
 */
function guarded_mcp_menu_locations() {
	$defaults = array();
	foreach ( get_registered_nav_menus() as $location => $description ) {
		$defaults[ $location ] = array(
			'max_depth' => 2,
			'note'      => (string) $description,
		);
	}

	$locations = (array) apply_filters( 'guarded_mcp_menu_locations', $defaults );

	$out = array();
	foreach ( array_intersect_key( $locations, $defaults ) as $location => $config ) {
		$config           = (array) $config;
		$out[ $location ] = array(
			'max_depth' => isset( $config['max_depth'] ) ? max( 1, (int) $config['max_depth'] ) : 2,
			'note'      => isset( $config['note'] ) ? (string) $config['note'] : '',
		);
	}

	return $out;
}

/**
 * Input schema of the `location` parameter.
 *
 * Without registered locations there is no enum: an empty one is not a valid
 * schema, and the abilities then answer with an error naming the problem.
 *
 * @return array
 */
function guarded_mcp_menu_location_schema() {
	$schema = array(
		'type'        => 'string',
		'description' => 'Theme menu location. get-menus lists every available location with a note on how the theme renders it.',
	);

	$locations = array_keys( guarded_mcp_menu_locations() );
	if ( $locations ) {
		$schema['enum'] = $locations;
	}

	return $schema;
}

/**
 * Polylang language slugs; empty when Polylang is off (one menu per location).
 *
 * @return string[]
 */
function guarded_mcp_menu_languages() {
	return function_exists( 'pll_languages_list' ) ? array_values( (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) : array();
}

/**
 * Who may read and edit menus through MCP.
 *
 * @return bool
 */
function guarded_mcp_menu_can_edit() {
	return current_user_can( 'guarded_mcp_edit_menus' ) || current_user_can( 'edit_theme_options' );
}

/**
 * Menu assigned to a location for a language; 0 when none.
 *
 * Read from the stored options, not get_nav_menu_locations(): Polylang filters
 * that by the language of the current request.
 *
 * @param string $location Theme location.
 * @param string $language Polylang slug; '' without Polylang.
 * @return int
 */
function guarded_mcp_menu_assigned_id( $location, $language ) {
	$theme   = get_option( 'stylesheet' );
	$menu_id = 0;

	if ( '' !== $language ) {
		$options = get_option( 'polylang' );
		if ( isset( $options['nav_menus'][ $theme ][ $location ][ $language ] ) ) {
			$menu_id = (int) $options['nav_menus'][ $theme ][ $location ][ $language ];
		}
	}

	// Without Polylang — and for its default language before Polylang has
	// recorded it — the assignment lives in the theme mods.
	if ( ! $menu_id && ( '' === $language || ( function_exists( 'pll_default_language' ) && pll_default_language( 'slug' ) === $language ) ) ) {
		$mods = get_option( 'theme_mods_' . $theme );
		if ( isset( $mods['nav_menu_locations'][ $location ] ) ) {
			$menu_id = (int) $mods['nav_menu_locations'][ $location ];
		}
	}

	return $menu_id && wp_get_nav_menu_object( $menu_id ) ? $menu_id : 0;
}

/**
 * Every place (location, or location/language) a menu is assigned to. One menu
 * can sit in several — then a change to it shows in all of them.
 *
 * @param int $menu_id Menu ID.
 * @return string[]
 */
function guarded_mcp_menu_slots( $menu_id ) {
	$slots     = array();
	$languages = guarded_mcp_menu_languages();

	foreach ( array_keys( guarded_mcp_menu_locations() ) as $location ) {
		foreach ( $languages ? $languages : array( '' ) as $language ) {
			if ( guarded_mcp_menu_assigned_id( $location, $language ) === (int) $menu_id ) {
				$slots[] = '' !== $language ? $location . '/' . $language : $location;
			}
		}
	}

	return $slots;
}

/**
 * Resolve location + language from ability input.
 *
 * @param array $input Ability input.
 * @return array{location:string,language:string,menu_id:int,config:array}|WP_Error
 */
function guarded_mcp_menu_target( array $input ) {
	$location  = isset( $input['location'] ) ? (string) $input['location'] : '';
	$locations = guarded_mcp_menu_locations();

	if ( ! isset( $locations[ $location ] ) ) {
		return new WP_Error(
			'guarded_mcp_menu_location_not_found',
			sprintf(
				/* translators: 1: location, 2: allowed locations. */
				__( 'Menu location "%1$s" is not available through MCP. Use one of: %2$s.', 'guarded-mcp' ),
				$location,
				implode( ', ', array_keys( $locations ) )
			)
		);
	}

	$languages = guarded_mcp_menu_languages();
	$language  = isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '';

	if ( $languages ) {
		if ( '' === $language ) {
			return new WP_Error(
				'guarded_mcp_menu_language_required',
				sprintf(
					/* translators: %s: language slugs. */
					__( 'Pass "language": every language has its own menu (%s).', 'guarded-mcp' ),
					implode( ', ', $languages )
				)
			);
		}
		if ( ! in_array( $language, $languages, true ) ) {
			return new WP_Error(
				'guarded_mcp_language_not_found',
				sprintf(
					/* translators: %s: language slug. */
					__( 'Language "%s" is not configured in Polylang.', 'guarded-mcp' ),
					$language
				)
			);
		}
	} elseif ( '' !== $language ) {
		return new WP_Error( 'guarded_mcp_menu_language_unsupported', __( 'Polylang is not active: omit "language".', 'guarded-mcp' ) );
	}

	return array(
		'location' => $location,
		'language' => $language,
		'menu_id'  => guarded_mcp_menu_assigned_id( $location, $language ),
		'config'   => $locations[ $location ],
	);
}

/**
 * Load the published items of a menu, keyed by ID, in menu order.
 *
 * Read straight from the posts rather than wp_get_nav_menu_items(): outside
 * wp-admin that drops items whose target is gone, and an editor has to see them.
 *
 * @param int $menu_id Menu (nav_menu term) ID.
 * @return array<int,array>
 */
function guarded_mcp_menu_load( $menu_id ) {
	$ids = get_objects_in_term( (int) $menu_id, 'nav_menu' );
	if ( is_wp_error( $ids ) || ! $ids ) {
		return array();
	}

	$posts = get_posts(
		array(
			'post_type'              => 'nav_menu_item',
			'post__in'               => array_map( 'intval', $ids ),
			'post_status'            => 'publish',
			'numberposts'            => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Bounded: items of one menu.
			'orderby'                => 'menu_order',
			'order'                  => 'ASC',
			'suppress_filters'       => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Read items of every language; Polylang would hide some.
			'update_post_term_cache' => false,
		)
	);

	$items = array();
	foreach ( $posts as $post ) {
		$id   = (int) $post->ID;
		$item = wp_setup_nav_menu_item( $post );
		$pll  = get_post_meta( $id, '_pll_menu_item', true );

		$items[ $id ] = array(
			'id'          => $id,
			'parent'      => (int) $item->menu_item_parent,
			'order'       => (int) $post->menu_order,
			'type'        => (string) $item->type,
			'object'      => (string) $item->object,
			'object_id'   => (int) $item->object_id,
			'url'         => 'custom' === $item->type ? (string) $item->url : '',
			'title'       => (string) $post->post_title,
			'target'      => (string) $item->target,
			'attr_title'  => (string) $post->post_excerpt,
			'classes'     => trim( implode( ' ', array_filter( (array) $item->classes ) ) ),
			'xfn'         => (string) $item->xfn,
			'description' => (string) $post->post_content,
			'pll'         => is_array( $pll ) ? $pll : null,
			'invalid'     => ! empty( $item->_invalid ),
			'shown_title' => (string) $item->title,
			'shown_url'   => (string) $item->url,
		);
	}

	return $items;
}

/**
 * In-memory model of a menu: nodes by key + ordered child keys per parent key.
 *
 * Stored items get key "i<ID>", items added in this call "r<ref>"; the top
 * level is "root". Items whose parent is not in the menu sit at the top level —
 * that is where WordPress renders them too.
 *
 * @param array $items Output of guarded_mcp_menu_load().
 * @return array{nodes:array,children:array}
 */
function guarded_mcp_menu_model( array $items ) {
	$model = array(
		'nodes'    => array(),
		'children' => array( 'root' => array() ),
	);

	foreach ( $items as $id => $item ) {
		$item['key']     = 'i' . $id;
		$item['ref']     = '';
		$item['touched'] = false;

		$model['nodes'][ $item['key'] ]    = $item;
		$model['children'][ $item['key'] ] = array();
	}

	foreach ( $items as $id => $item ) {
		$parent_key = ( $item['parent'] && $item['parent'] !== $id && isset( $model['nodes'][ 'i' . $item['parent'] ] ) ) ? 'i' . $item['parent'] : 'root';

		$model['nodes'][ 'i' . $id ]['parent_key'] = $parent_key;
		$model['children'][ $parent_key ][]        = 'i' . $id;
	}

	return $model;
}

/**
 * Depth-first walk: every node with its depth (1 = top level) and parent key.
 *
 * @param array  $model Menu model.
 * @param string $key   Start key.
 * @param int    $depth Depth of the children of $key.
 * @return array<int,array{key:string,depth:int,parent_key:string}>
 */
function guarded_mcp_menu_walk( array $model, $key = 'root', $depth = 1 ) {
	$out = array();

	foreach ( $model['children'][ $key ] as $child ) {
		$out[] = array(
			'key'        => $child,
			'depth'      => $depth,
			'parent_key' => $key,
		);
		$out   = array_merge( $out, guarded_mcp_menu_walk( $model, $child, $depth + 1 ) );
	}

	return $out;
}

/**
 * Keys of a node and all its descendants.
 *
 * @param array  $model Menu model.
 * @param string $key   Node key.
 * @return string[]
 */
function guarded_mcp_menu_subtree( array $model, $key ) {
	$keys = array( $key );
	foreach ( $model['children'][ $key ] as $child ) {
		$keys = array_merge( $keys, guarded_mcp_menu_subtree( $model, $child ) );
	}
	return $keys;
}

/**
 * Put a node under a parent at a 1-based position (null = last).
 *
 * @param array    $model      Menu model.
 * @param string   $key        Node key.
 * @param string   $parent_key Parent key.
 * @param int|null $position   Position among the parent's children.
 * @return void
 */
function guarded_mcp_menu_attach( array &$model, $key, $parent_key, $position ) {
	$siblings = $model['children'][ $parent_key ];
	$index    = null === $position ? count( $siblings ) : max( 0, min( count( $siblings ), (int) $position - 1 ) );

	array_splice( $siblings, $index, 0, array( $key ) );

	$model['children'][ $parent_key ]     = $siblings;
	$model['nodes'][ $key ]['parent_key'] = $parent_key;
}

/**
 * Take a node out of its parent's child list (the node and its subtree stay).
 *
 * @param array  $model Menu model.
 * @param string $key   Node key.
 * @return void
 */
function guarded_mcp_menu_detach( array &$model, $key ) {
	$parent_key = $model['nodes'][ $key ]['parent_key'];

	$model['children'][ $parent_key ] = array_values( array_diff( $model['children'][ $parent_key ], array( $key ) ) );
}

/**
 * Front page of a language; 0 when the site has no static front page.
 *
 * @param string $language Polylang slug or ''.
 * @return int
 */
function guarded_mcp_menu_front_page_id( $language ) {
	$front = (int) get_option( 'page_on_front' );

	if ( $front && '' !== $language && function_exists( 'pll_get_post' ) ) {
		$front = (int) pll_get_post( $front, $language );
	}

	return $front;
}

/**
 * Polylang language of a post or term; '' without Polylang or language.
 *
 * @param string $kind 'post' or 'term'.
 * @param int    $id   Object ID.
 * @return string
 */
function guarded_mcp_menu_object_language( $kind, $id ) {
	if ( 'term' === $kind ) {
		return function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $id, 'slug' ) : '';
	}
	return function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $id, 'slug' ) : '';
}

/**
 * Find a page by its path (as in the URL, without the language prefix).
 *
 * Slugs are shared between languages (polylang-slug), so the path alone is
 * ambiguous: the page must also be in the menu's language.
 *
 * @param string $path     Page path, e.g. "web-development/wordpress-websites".
 * @param string $language Polylang slug or ''.
 * @return WP_Post|WP_Error|null Null when not found.
 */
function guarded_mcp_menu_find_page( $path, $language ) {
	$segments = array_values(
		array_filter(
			explode( '/', trim( (string) $path, '/' ) ),
			function ( $segment ) {
				return '' !== $segment;
			}
		)
	);

	// "/en/services/" — a language prefix in front of the page path.
	if ( $segments && in_array( $segments[0], guarded_mcp_menu_languages(), true ) ) {
		if ( $segments[0] !== $language ) {
			return new WP_Error(
				'guarded_mcp_menu_link_language',
				sprintf(
					/* translators: 1: path, 2: language slug. */
					__( 'Path "%1$s" is an address in another language; this menu is "%2$s".', 'guarded-mcp' ),
					$path,
					$language
				)
			);
		}
		array_shift( $segments );
	}

	if ( ! $segments ) {
		$front = guarded_mcp_menu_front_page_id( $language );
		return $front ? get_post( $front ) : null;
	}

	$uri        = implode( '/', array_map( 'sanitize_title', $segments ) );
	$candidates = get_posts(
		array(
			'post_type'        => 'page',
			'name'             => sanitize_title( end( $segments ) ),
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Bounded: pages with one slug across languages.
			'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Search every language; the menu language is checked explicitly below.
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( get_page_uri( $candidate ) !== $uri ) {
			continue;
		}
		if ( '' !== $language && guarded_mcp_menu_object_language( 'post', $candidate->ID ) !== $language ) {
			continue;
		}
		return $candidate;
	}

	return null;
}

/**
 * Find a blog post by slug in a language.
 *
 * @param string $slug     Post slug.
 * @param string $language Polylang slug or ''.
 * @return WP_Post|null
 */
function guarded_mcp_menu_find_post( $slug, $language ) {
	$candidates = get_posts(
		array(
			'post_type'        => 'post',
			'name'             => sanitize_title( trim( (string) $slug, '/' ) ),
			'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'      => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- Bounded: posts with one slug across languages.
			'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- Search every language; the menu language is checked explicitly below.
		)
	);

	foreach ( $candidates as $candidate ) {
		if ( '' === $language || guarded_mcp_menu_object_language( 'post', $candidate->ID ) === $language ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Check a custom link URL.
 *
 * Allowed: http(s), mailto, tel, a root-relative path, an #anchor. Anything else
 * (javascript:, data:, protocol-relative //host) is rejected.
 *
 * Control characters and backslashes are rejected anywhere in the URL.
 * Browsers drop tabs and newlines from a URL and read "\" as "/", and
 * esc_url() on save drops them too, so "/<tab>/host" would be stored and
 * followed as "//host".
 *
 * @param string $url   URL.
 * @param string $where Input path for the message.
 * @return string|WP_Error The trimmed URL.
 */
function guarded_mcp_menu_check_url( $url, $where ) {
	$url = trim( (string) $url );

	if ( '' === $url ) {
		return new WP_Error(
			'guarded_mcp_menu_url_empty',
			sprintf(
				/* translators: %s: input path. */
				__( '%s: a custom link needs "url".', 'guarded-mcp' ),
				$where
			)
		);
	}

	$plain = ! preg_match( '/[[:cntrl:]]/', $url ) && false === strpos( $url, '\\' );

	if ( $plain && ( '#' === $url[0] || ( '/' === $url[0] && ( 1 === strlen( $url ) || '/' !== $url[1] ) ) ) ) {
		return $url;
	}

	$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
	if ( $plain && in_array( $scheme, array( 'http', 'https', 'mailto', 'tel' ), true ) ) {
		return $url;
	}

	return new WP_Error(
		'guarded_mcp_menu_url_invalid',
		sprintf(
			/* translators: 1: input path, 2: URL. */
			__( '%1$s: "%2$s" is not an allowed link. Use http(s)://, mailto:, tel:, a path starting with / or an #anchor.', 'guarded-mcp' ),
			$where,
			$url
		)
	);
}

/**
 * Turn a link spec from input into the stored link fields.
 *
 * Spec: {type: page, id | path} · {type: post, id | slug} · {type: category,
 * id | slug} · {type: custom, url}. With both an ID and a path/slug the path
 * wins when they disagree — IDs differ between the local and the live site,
 * paths do not. The target must exist, be published and be in the menu's
 * language. With $fallbacks (replace on a non-production site) a link that does
 * not resolve becomes a custom link to its `url`, with a warning.
 *
 * @param mixed  $link      Link spec.
 * @param string $language  Menu language.
 * @param bool   $fallbacks Preview fallbacks allowed.
 * @param array  $warnings  Collected warnings.
 * @param string $where     Input path for messages.
 * @return array{type:string,object:string,object_id:int,url:string,object_title:string}|WP_Error
 */
function guarded_mcp_menu_resolve_link( $link, $language, $fallbacks, array &$warnings, $where ) {
	if ( ! is_array( $link ) || empty( $link['type'] ) ) {
		return new WP_Error(
			'guarded_mcp_menu_link_missing',
			sprintf(
				/* translators: %s: input path. */
				__( '%s: "link" needs a "type" (page, post, category or custom).', 'guarded-mcp' ),
				$where
			)
		);
	}

	$type = (string) $link['type'];

	if ( 'custom' === $type ) {
		$url = guarded_mcp_menu_check_url( isset( $link['url'] ) ? $link['url'] : '', $where );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ( $host && wp_parse_url( home_url(), PHP_URL_HOST ) === $host ) || '/' === $url[0] ) {
			$warnings[] = sprintf( '%s: "%s" is an address on this site. A page link (type "page") follows the page when its slug changes; a custom link does not.', $where, $url );
		}

		return array(
			'type'         => 'custom',
			'object'       => 'custom',
			'object_id'    => 0,
			'url'          => $url,
			'object_title' => '',
		);
	}

	$by_id   = isset( $link['id'] ) ? (int) $link['id'] : 0;
	$by_path = null;
	if ( 'page' === $type && array_key_exists( 'path', $link ) ) {
		$by_path = (string) $link['path'];
	} elseif ( 'page' !== $type && isset( $link['slug'] ) ) {
		$by_path = (string) $link['slug'];
	}

	$object = null;
	$error  = null;

	if ( ! in_array( $type, array( 'page', 'post', 'category' ), true ) ) {
		$error = new WP_Error(
			'guarded_mcp_menu_link_type',
			sprintf(
				/* translators: 1: input path, 2: link type. */
				__( '%1$s: link type "%2$s" cannot be written through MCP. Use page, post, category or custom.', 'guarded-mcp' ),
				$where,
				$type
			)
		);
	} elseif ( ! $by_id && null === $by_path ) {
		return new WP_Error(
			'guarded_mcp_menu_link_target',
			sprintf(
				/* translators: 1: input path, 2: field name. */
				__( '%1$s: pass the link target as "id" or "%2$s".', 'guarded-mcp' ),
				$where,
				'page' === $type ? 'path' : 'slug'
			)
		);
	} elseif ( 'category' === $type ) {
		$term = null;
		if ( null !== $by_path ) {
			$term = get_term_by( 'slug', sanitize_title( $by_path ), 'category' );
			if ( $term && $by_id && (int) $term->term_id !== $by_id ) {
				$warnings[] = sprintf( '%s: category %d does not have slug "%s" here; used the one that has (%d).', $where, $by_id, $by_path, (int) $term->term_id );
			}
		} else {
			$term = get_term( $by_id, 'category' );
		}

		if ( $term instanceof WP_Term ) {
			$term_language = guarded_mcp_menu_object_language( 'term', $term->term_id );
			if ( '' !== $language && $term_language !== $language ) {
				$error = new WP_Error(
					'guarded_mcp_menu_link_language',
					sprintf(
						/* translators: 1: input path, 2: category name, 3: category language, 4: menu language. */
						__( '%1$s: category "%2$s" is in language "%3$s", the menu is "%4$s".', 'guarded-mcp' ),
						$where,
						$term->name,
						$term_language,
						$language
					)
				);
			} else {
				return array(
					'type'         => 'taxonomy',
					'object'       => 'category',
					'object_id'    => (int) $term->term_id,
					'url'          => '',
					'object_title' => (string) $term->name,
				);
			}
		}
	} else {
		if ( null !== $by_path ) {
			$object = 'page' === $type ? guarded_mcp_menu_find_page( $by_path, $language ) : guarded_mcp_menu_find_post( $by_path, $language );
			if ( is_wp_error( $object ) ) {
				return $object;
			}
			if ( $object && $by_id && (int) $object->ID !== $by_id ) {
				$warnings[] = sprintf( '%s: %s %d is not "%s" here; used the one that is (%d).', $where, $type, $by_id, $by_path, (int) $object->ID );
			}
		} else {
			$object = get_post( $by_id );
			if ( $object && $object->post_type !== $type ) {
				$object = null;
			}
		}

		if ( $object instanceof WP_Post ) {
			$object_language = guarded_mcp_menu_object_language( 'post', $object->ID );

			if ( 'publish' !== $object->post_status ) {
				$error = new WP_Error(
					'guarded_mcp_menu_link_unpublished',
					sprintf(
						/* translators: 1: input path, 2: object type, 3: object ID, 4: title, 5: post status. */
						__( '%1$s: %2$s %3$d "%4$s" is not published (%5$s) — visitors would get a 404. Publish it first.', 'guarded-mcp' ),
						$where,
						$type,
						(int) $object->ID,
						$object->post_title,
						$object->post_status
					)
				);
			} elseif ( '' !== $language && $object_language !== $language ) {
				$error = new WP_Error(
					'guarded_mcp_menu_link_language',
					sprintf(
						/* translators: 1: input path, 2: object type, 3: object ID, 4: title, 5: object language, 6: menu language. */
						__( '%1$s: %2$s %3$d "%4$s" is in language "%5$s", the menu is "%6$s".', 'guarded-mcp' ),
						$where,
						$type,
						(int) $object->ID,
						$object->post_title,
						$object_language,
						$language
					)
				);
			} else {
				return array(
					'type'         => 'post_type',
					'object'       => $type,
					'object_id'    => (int) $object->ID,
					'url'          => '',
					'object_title' => (string) $object->post_title,
				);
			}
		}
	}

	if ( null === $error ) {
		$error = new WP_Error(
			'guarded_mcp_menu_link_not_found',
			sprintf(
				/* translators: 1: input path, 2: link type, 3: reference. */
				__( '%1$s: %2$s "%3$s" was not found in this language.', 'guarded-mcp' ),
				$where,
				$type,
				null !== $by_path ? $by_path : (string) $by_id
			)
		);
	}

	if ( $fallbacks ) {
		$fallback_url = isset( $link['url'] ) && ! is_wp_error( guarded_mcp_menu_check_url( $link['url'], $where ) ) ? trim( (string) $link['url'] ) : '#';
		$warnings[]   = $error->get_error_message() . sprintf( ' Preview fallback: custom link to "%s".', $fallback_url );

		return array(
			'type'         => 'custom',
			'object'       => 'custom',
			'object_id'    => 0,
			'url'          => $fallback_url,
			'object_title' => '',
		);
	}

	return $error;
}

/**
 * Apply the item fields of an input object (add, update, replace node) to a node.
 *
 * Fields: label, link, new_tab, title_attr, rel. Labels and attributes are
 * plain text. An empty label on a page / post / category link means "follow the
 * title of the target"; a custom link needs a label.
 *
 * @param array  $node      Node (modified).
 * @param array  $fields    Input object.
 * @param array  $target    Resolved menu target.
 * @param bool   $fallbacks Preview fallbacks allowed.
 * @param array  $warnings  Collected warnings.
 * @param string $where     Input path for messages.
 * @return true|WP_Error
 */
function guarded_mcp_menu_fill_node( array &$node, array $fields, array $target, $fallbacks, array &$warnings, $where ) {
	if ( array_key_exists( 'link', $fields ) ) {
		$link = guarded_mcp_menu_resolve_link( $fields['link'], $target['language'], $fallbacks, $warnings, $where . '.link' );
		if ( is_wp_error( $link ) ) {
			return $link;
		}

		$node['type']         = $link['type'];
		$node['object']       = $link['object'];
		$node['object_id']    = $link['object_id'];
		$node['url']          = $link['url'];
		$node['object_title'] = $link['object_title'];
		$node['invalid']      = false;
	}

	if ( array_key_exists( 'label', $fields ) ) {
		$node['title'] = trim( sanitize_text_field( (string) $fields['label'] ) );
	}

	// A label equal to the target's title is stored empty: it then follows the
	// title, the same as WordPress does in the menu editor.
	if ( 'custom' !== $node['type'] && isset( $node['object_title'] ) && $node['title'] === $node['object_title'] ) {
		$node['title'] = '';
	}

	if ( 'custom' === $node['type'] && '' === $node['title'] ) {
		return new WP_Error(
			'guarded_mcp_menu_label_required',
			sprintf(
				/* translators: %s: input path. */
				__( '%s: a custom link needs a "label".', 'guarded-mcp' ),
				$where
			)
		);
	}

	if ( array_key_exists( 'new_tab', $fields ) ) {
		$node['target'] = ! empty( $fields['new_tab'] ) ? '_blank' : '';
	}

	if ( array_key_exists( 'title_attr', $fields ) ) {
		$node['attr_title'] = sanitize_text_field( (string) $fields['title_attr'] );
	}

	if ( array_key_exists( 'rel', $fields ) ) {
		$node['xfn'] = implode( ' ', array_filter( array_map( 'sanitize_html_class', preg_split( '/\s+/', (string) $fields['rel'] ) ) ) );
	}

	$node['touched'] = true;

	return true;
}

/**
 * A fresh node for an item that does not exist yet.
 *
 * @param string $key Model key.
 * @param string $ref Client reference.
 * @return array
 */
function guarded_mcp_menu_new_node( $key, $ref ) {
	return array(
		'key'          => $key,
		'ref'          => $ref,
		'id'           => 0,
		'parent_key'   => 'root',
		'type'         => 'custom',
		'object'       => 'custom',
		'object_id'    => 0,
		'url'          => '',
		'title'        => '',
		'target'       => '',
		'attr_title'   => '',
		'classes'      => '',
		'xfn'          => '',
		'description'  => '',
		'pll'          => null,
		'invalid'      => false,
		'touched'      => true,
		'object_title' => '',
	);
}

/**
 * Key of the node an input reference points to.
 *
 * An integer (or digit string) is a stored item ID; any other string is the
 * `ref` an `add` gave a new item earlier in the same call. 0 as a parent is the
 * top level.
 *
 * @param array  $model      Menu model.
 * @param mixed  $reference  Reference.
 * @param string $where      Input path for messages.
 * @param bool   $allow_root Accept 0 (top level).
 * @return string|WP_Error
 */
function guarded_mcp_menu_ref_key( array $model, $reference, $where, $allow_root = false ) {
	if ( is_int( $reference ) || ( is_string( $reference ) && ctype_digit( $reference ) ) ) {
		$id = (int) $reference;
		if ( 0 === $id && $allow_root ) {
			return 'root';
		}
		$key = 'i' . $id;
	} else {
		$key = 'r' . (string) $reference;
	}

	if ( ! isset( $model['nodes'][ $key ] ) ) {
		return new WP_Error(
			'guarded_mcp_menu_item_not_found',
			sprintf(
				/* translators: 1: input path, 2: reference. */
				__( '%1$s: item "%2$s" is not in this menu.', 'guarded-mcp' ),
				$where,
				is_scalar( $reference ) ? (string) $reference : ''
			)
		);
	}

	return $key;
}

/**
 * Human label of a node for change lines.
 *
 * @param array $node Node.
 * @return string
 */
function guarded_mcp_menu_node_label( array $node ) {
	$label = '' !== $node['title'] ? $node['title'] : ( isset( $node['object_title'] ) && '' !== $node['object_title'] ? $node['object_title'] : ( isset( $node['shown_title'] ) ? $node['shown_title'] : '' ) );
	$id    = $node['id'] ? ' (' . $node['id'] . ')' : ( '' !== $node['ref'] ? ' (new "' . $node['ref'] . '")' : ' (new)' );

	return '«' . html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) . '»' . $id;
}

/**
 * Apply a list of operations to the model. All-or-nothing: the first invalid
 * operation returns an error and nothing is written.
 *
 * Operations and their fields:
 * add    — {op, link, label?, parent?, position?, ref?, new_tab?, title_attr?, rel?}
 * update — {op, item, label? link? new_tab? title_attr? rel? — at least one}
 * move   — {op, item, parent?, position?} — parent defaults to the current one
 * remove — {op, item, with_children?} — an item with children needs with_children
 *
 * @param array $model      Menu model (modified).
 * @param array $operations Operations.
 * @param array $target     Resolved menu target.
 * @param array $changes    Collected change lines.
 * @param array $warnings   Collected warnings.
 * @return true|WP_Error
 */
function guarded_mcp_menu_apply_operations( array &$model, array $operations, array $target, array &$changes, array &$warnings ) {
	$auto = 0;

	foreach ( array_values( $operations ) as $i => $op ) {
		$where = 'operations[' . $i . ']';

		if ( ! is_array( $op ) || empty( $op['op'] ) ) {
			return new WP_Error(
				'guarded_mcp_menu_op_invalid',
				sprintf(
					/* translators: %s: input path. */
					__( '%s: every operation needs "op" (add, update, move, remove).', 'guarded-mcp' ),
					$where
				)
			);
		}

		$position = isset( $op['position'] ) ? max( 1, (int) $op['position'] ) : null;

		switch ( $op['op'] ) {
			case 'add':
				if ( empty( $op['link'] ) ) {
					return new WP_Error(
						'guarded_mcp_menu_link_missing',
						sprintf(
							/* translators: %s: input path. */
							__( '%s: "add" needs a "link".', 'guarded-mcp' ),
							$where
						)
					);
				}

				$ref = isset( $op['ref'] ) ? (string) $op['ref'] : '';
				if ( '' !== $ref && ! preg_match( '/^[a-z][a-z0-9_-]{0,40}$/i', $ref ) ) {
					return new WP_Error(
						'guarded_mcp_menu_ref_invalid',
						sprintf(
							/* translators: %s: input path. */
							__( '%s: "ref" starts with a letter and holds letters, digits, "-" and "_".', 'guarded-mcp' ),
							$where
						)
					);
				}
				if ( '' === $ref ) {
					$ref = 'new-' . ( ++$auto );
				}
				if ( isset( $model['nodes'][ 'r' . $ref ] ) ) {
					return new WP_Error(
						'guarded_mcp_menu_ref_taken',
						sprintf(
							/* translators: 1: input path, 2: ref. */
							__( '%1$s: ref "%2$s" is already used in this call.', 'guarded-mcp' ),
							$where,
							$ref
						)
					);
				}

				$parent_key = 'root';
				if ( isset( $op['parent'] ) ) {
					$parent_key = guarded_mcp_menu_ref_key( $model, $op['parent'], $where . '.parent', true );
					if ( is_wp_error( $parent_key ) ) {
						return $parent_key;
					}
				}

				$key  = 'r' . $ref;
				$node = guarded_mcp_menu_new_node( $key, $ref );

				$filled = guarded_mcp_menu_fill_node( $node, $op, $target, false, $warnings, $where );
				if ( is_wp_error( $filled ) ) {
					return $filled;
				}

				$model['nodes'][ $key ]    = $node;
				$model['children'][ $key ] = array();
				guarded_mcp_menu_attach( $model, $key, $parent_key, $position );

				$changes[] = sprintf(
					'add %s under %s, position %d',
					guarded_mcp_menu_node_label( $model['nodes'][ $key ] ),
					'root' === $parent_key ? 'the top level' : guarded_mcp_menu_node_label( $model['nodes'][ $parent_key ] ),
					array_search( $key, $model['children'][ $parent_key ], true ) + 1
				);
				break;

			case 'update':
				$key = guarded_mcp_menu_ref_key( $model, isset( $op['item'] ) ? $op['item'] : null, $where . '.item' );
				if ( is_wp_error( $key ) ) {
					return $key;
				}

				$editable = array( 'label', 'link', 'new_tab', 'title_attr', 'rel' );
				if ( ! array_intersect( array_keys( $op ), $editable ) ) {
					return new WP_Error(
						'guarded_mcp_nothing_to_update',
						sprintf(
							/* translators: %s: input path. */
							__( '%s: "update" needs at least one of: label, link, new_tab, title_attr, rel.', 'guarded-mcp' ),
							$where
						)
					);
				}

				$before = guarded_mcp_menu_node_label( $model['nodes'][ $key ] );
				$node   = $model['nodes'][ $key ];

				$filled = guarded_mcp_menu_fill_node( $node, $op, $target, false, $warnings, $where );
				if ( is_wp_error( $filled ) ) {
					return $filled;
				}

				$model['nodes'][ $key ] = $node;
				$changes[]              = sprintf( 'update %s: %s', $before, implode( ', ', array_intersect( $editable, array_keys( $op ) ) ) );
				break;

			case 'move':
				$key = guarded_mcp_menu_ref_key( $model, isset( $op['item'] ) ? $op['item'] : null, $where . '.item' );
				if ( is_wp_error( $key ) ) {
					return $key;
				}
				if ( ! array_key_exists( 'parent', $op ) && null === $position ) {
					return new WP_Error(
						'guarded_mcp_nothing_to_update',
						sprintf(
							/* translators: %s: input path. */
							__( '%s: "move" needs "parent", "position" or both.', 'guarded-mcp' ),
							$where
						)
					);
				}

				$parent_key = $model['nodes'][ $key ]['parent_key'];
				if ( array_key_exists( 'parent', $op ) ) {
					$parent_key = guarded_mcp_menu_ref_key( $model, $op['parent'], $where . '.parent', true );
					if ( is_wp_error( $parent_key ) ) {
						return $parent_key;
					}
				}

				if ( in_array( $parent_key, guarded_mcp_menu_subtree( $model, $key ), true ) ) {
					return new WP_Error(
						'guarded_mcp_menu_parent_loop',
						sprintf(
							/* translators: %s: input path. */
							__( '%s: an item cannot move under itself or under one of its own children.', 'guarded-mcp' ),
							$where
						)
					);
				}

				guarded_mcp_menu_detach( $model, $key );
				guarded_mcp_menu_attach( $model, $key, $parent_key, $position );
				$model['nodes'][ $key ]['touched'] = true;

				$changes[] = sprintf(
					'move %s under %s, position %d',
					guarded_mcp_menu_node_label( $model['nodes'][ $key ] ),
					'root' === $parent_key ? 'the top level' : guarded_mcp_menu_node_label( $model['nodes'][ $parent_key ] ),
					array_search( $key, $model['children'][ $parent_key ], true ) + 1
				);
				break;

			case 'remove':
				$key = guarded_mcp_menu_ref_key( $model, isset( $op['item'] ) ? $op['item'] : null, $where . '.item' );
				if ( is_wp_error( $key ) ) {
					return $key;
				}

				$subtree = guarded_mcp_menu_subtree( $model, $key );
				if ( count( $subtree ) > 1 && empty( $op['with_children'] ) ) {
					return new WP_Error(
						'guarded_mcp_menu_remove_children',
						sprintf(
							/* translators: 1: input path, 2: item label, 3: number of children. */
							__( '%1$s: %2$s has %3$d nested item(s). Pass "with_children": true to remove them too, or move them out first.', 'guarded-mcp' ),
							$where,
							guarded_mcp_menu_node_label( $model['nodes'][ $key ] ),
							count( $subtree ) - 1
						)
					);
				}

				$labels = array();
				foreach ( $subtree as $gone ) {
					$labels[] = guarded_mcp_menu_node_label( $model['nodes'][ $gone ] );
				}

				guarded_mcp_menu_detach( $model, $key );
				foreach ( $subtree as $gone ) {
					unset( $model['nodes'][ $gone ], $model['children'][ $gone ] );
				}

				$changes[] = 'remove ' . implode( ', ', $labels );
				break;

			default:
				return new WP_Error(
					'guarded_mcp_menu_op_invalid',
					sprintf(
						/* translators: 1: input path, 2: op. */
						__( '%1$s: unknown op "%2$s" (add, update, move, remove).', 'guarded-mcp' ),
						$where,
						(string) $op['op']
					)
				);
		}
	}

	return true;
}

/**
 * Build a model from a tree of input nodes (replace) — all items new.
 *
 * Nodes are what get-menus returns: {label, link, new_tab?, title_attr?, rel?,
 * children?}; read-only fields (id, hidden, label_follows_link) are
 * ignored. The label is written as given, so a copy shows the same text even
 * where the target's title differs on this site.
 *
 * @param array  $nodes      Input nodes.
 * @param array  $target     Resolved menu target.
 * @param bool   $fallbacks  Preview fallbacks allowed.
 * @param array  $warnings   Collected warnings.
 * @param array  $model      Model being built (modified).
 * @param string $parent_key Parent key.
 * @param string $where      Input path for messages.
 * @return true|WP_Error
 */
function guarded_mcp_menu_model_from_input( array $nodes, array $target, $fallbacks, array &$warnings, array &$model, $parent_key = 'root', $where = 'replace' ) {
	foreach ( array_values( $nodes ) as $i => $in ) {
		$path = $where . '[' . $i . ']';

		if ( ! is_array( $in ) || empty( $in['link'] ) ) {
			return new WP_Error(
				'guarded_mcp_menu_link_missing',
				sprintf(
					/* translators: %s: input path. */
					__( '%s: every item needs a "link".', 'guarded-mcp' ),
					$path
				)
			);
		}

		$key  = 'r' . $path;
		$node = guarded_mcp_menu_new_node( $key, '' );

		$fields = array_intersect_key( $in, array_flip( array( 'label', 'link', 'new_tab', 'title_attr', 'rel' ) ) );

		$filled = guarded_mcp_menu_fill_node( $node, $fields, $target, $fallbacks, $warnings, $path );
		if ( is_wp_error( $filled ) ) {
			return $filled;
		}

		$model['nodes'][ $key ]    = $node;
		$model['children'][ $key ] = array();
		guarded_mcp_menu_attach( $model, $key, $parent_key, null );

		if ( ! empty( $in['children'] ) ) {
			if ( ! is_array( $in['children'] ) ) {
				return new WP_Error(
					'guarded_mcp_menu_children_invalid',
					sprintf(
						/* translators: %s: input path. */
						__( '%s: "children" must be a list.', 'guarded-mcp' ),
						$path
					)
				);
			}
			$result = guarded_mcp_menu_model_from_input( $in['children'], $target, $fallbacks, $warnings, $model, $key, $path . '.children' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
	}

	return true;
}

/**
 * Build a model from a backup tree. Items that still exist in the menu keep
 * their IDs; the rest are created again. Targets must still exist.
 *
 * @param array  $nodes      Backup nodes.
 * @param array  $items      Current items (by ID).
 * @param array  $model      Model being built (modified).
 * @param string $parent_key Parent key.
 * @return true|WP_Error
 */
function guarded_mcp_menu_model_from_backup( array $nodes, array $items, array &$model, $parent_key = 'root' ) {
	foreach ( $nodes as $node ) {
		$id = isset( $items[ (int) $node['id'] ] ) ? (int) $node['id'] : 0;

		if ( 'post_type' === $node['type'] ) {
			$object = get_post( (int) $node['object_id'] );
			if ( ! $object || 'trash' === $object->post_status ) {
				return new WP_Error(
					'guarded_mcp_menu_backup_target_gone',
					sprintf(
						/* translators: 1: object type, 2: object ID. */
						__( 'The backup links to %1$s %2$d, which no longer exists. Restore cannot rebuild that item.', 'guarded-mcp' ),
						$node['object'],
						(int) $node['object_id']
					)
				);
			}
		} elseif ( 'taxonomy' === $node['type'] && ! get_term( (int) $node['object_id'], $node['object'] ) instanceof WP_Term ) {
			return new WP_Error(
				'guarded_mcp_menu_backup_target_gone',
				sprintf(
					/* translators: 1: taxonomy, 2: term ID. */
					__( 'The backup links to %1$s %2$d, which no longer exists. Restore cannot rebuild that item.', 'guarded-mcp' ),
					$node['object'],
					(int) $node['object_id']
				)
			);
		}

		$key = $id ? 'i' . $id : 'r' . uniqid( 'b', true );

		$model['nodes'][ $key ]    = array_merge(
			array_diff_key( $node, array( 'children' => true ) ),
			array(
				'key'          => $key,
				'ref'          => '',
				'id'           => $id,
				'invalid'      => false,
				'touched'      => true,
				'object_title' => '',
			)
		);
		$model['children'][ $key ] = array();
		guarded_mcp_menu_attach( $model, $key, $parent_key, null );

		$result = guarded_mcp_menu_model_from_backup( $node['children'], $items, $model, $key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	return true;
}

/**
 * Check the finished model against the location's rules.
 *
 * Errors: nesting deeper than the location renders.
 *
 * @param array $model  Menu model.
 * @param array $target Resolved menu target.
 * @return true|WP_Error
 */
function guarded_mcp_menu_check_model( array $model, array $target ) {
	$max = (int) $target['config']['max_depth'];

	foreach ( guarded_mcp_menu_walk( $model ) as $row ) {
		$node = $model['nodes'][ $row['key'] ];

		if ( $row['depth'] > $max ) {
			return new WP_Error(
				'guarded_mcp_menu_too_deep',
				sprintf(
					/* translators: 1: item label, 2: level, 3: location, 4: max levels. */
					__( '%1$s would sit at level %2$d; "%3$s" shows %4$d level(s).', 'guarded-mcp' ),
					guarded_mcp_menu_node_label( $node ),
					$row['depth'],
					$target['location'],
					$max
				)
			);
		}
	}

	return true;
}

/**
 * URL a node links to, as the site renders it.
 *
 * @param array $node Node.
 * @return string
 */
function guarded_mcp_menu_node_url( array $node ) {
	if ( 'custom' === $node['type'] ) {
		return (string) $node['url'];
	}
	if ( 'post_type' === $node['type'] && in_array( $node['object'], array( 'page', 'post' ), true ) ) {
		$url = get_permalink( (int) $node['object_id'] );
		return $url ? (string) $url : '';
	}
	if ( 'taxonomy' === $node['type'] && 'category' === $node['object'] ) {
		$url = get_term_link( (int) $node['object_id'], 'category' );
		return is_wp_error( $url ) ? '' : (string) $url;
	}
	return isset( $node['shown_url'] ) ? (string) $node['shown_url'] : '';
}

/**
 * Read payload of a node's link.
 *
 * @param array  $node     Node.
 * @param string $language Menu language.
 * @return array
 */
function guarded_mcp_menu_link_payload( array $node, $language ) {
	$url = guarded_mcp_menu_node_url( $node );
	$id  = (int) $node['object_id'];

	if ( 'custom' === $node['type'] ) {
		return array(
			'type' => 'custom',
			'url'  => $url,
		);
	}

	if ( 'post_type' === $node['type'] && 'page' === $node['object'] ) {
		return array(
			'type'     => 'page',
			'id'       => $id,
			'path'     => guarded_mcp_menu_front_page_id( $language ) === $id ? '' : (string) get_page_uri( $id ),
			'url'      => $url,
			'status'   => (string) get_post_status( $id ),
			'language' => guarded_mcp_menu_object_language( 'post', $id ),
		);
	}

	if ( 'post_type' === $node['type'] && 'post' === $node['object'] ) {
		return array(
			'type'     => 'post',
			'id'       => $id,
			'slug'     => (string) get_post_field( 'post_name', $id ),
			'url'      => $url,
			'status'   => (string) get_post_status( $id ),
			'language' => guarded_mcp_menu_object_language( 'post', $id ),
		);
	}

	if ( 'taxonomy' === $node['type'] && 'category' === $node['object'] ) {
		$term = get_term( $id, 'category' );
		return array(
			'type'     => 'category',
			'id'       => $id,
			'slug'     => $term instanceof WP_Term ? $term->slug : '',
			'url'      => $url,
			'language' => guarded_mcp_menu_object_language( 'term', $id ),
		);
	}

	return array(
		'type'      => 'other',
		'kind'      => (string) $node['type'],
		'object'    => (string) $node['object'],
		'object_id' => $id,
		'url'       => $url,
	);
}

/**
 * Read payload of the menu tree (or of the children of $key).
 *
 * @param array  $model  Menu model.
 * @param array  $target Resolved menu target.
 * @param string $key    Parent key.
 * @return array
 */
function guarded_mcp_menu_tree_payload( array $model, array $target, $key = 'root' ) {
	$out = array();

	foreach ( $model['children'][ $key ] as $child ) {
		$node = $model['nodes'][ $child ];

		if ( 'custom' === $node['type'] || ! in_array( $node['object'], array( 'page', 'post', 'category' ), true ) ) {
			$object_title = isset( $node['shown_title'] ) ? $node['shown_title'] : '';
		} elseif ( isset( $node['object_title'] ) && '' !== $node['object_title'] ) {
			$object_title = $node['object_title'];
		} elseif ( 'taxonomy' === $node['type'] ) {
			$term         = get_term( (int) $node['object_id'], 'category' );
			$object_title = $term instanceof WP_Term ? $term->name : '';
		} else {
			$object_title = (string) get_post_field( 'post_title', (int) $node['object_id'] );
		}
		if ( '' === $object_title && isset( $node['shown_title'] ) ) {
			$object_title = $node['shown_title'];
		}

		// WordPress stores "&" in titles as an entity ("&#038;"); the client gets text.
		$item = array(
			'id'                 => (int) $node['id'],
			'label'              => html_entity_decode( '' !== $node['title'] ? $node['title'] : $object_title, ENT_QUOTES, 'UTF-8' ),
			'label_follows_link' => 'custom' !== $node['type'] && '' === $node['title'],
			'link'               => guarded_mcp_menu_link_payload( $node, $target['language'] ),
			'new_tab'            => '_blank' === $node['target'],
		);

		if ( ! $node['id'] && '' !== $node['ref'] ) {
			$item['ref'] = $node['ref'];
		}
		if ( '' !== $node['attr_title'] ) {
			$item['title_attr'] = $node['attr_title'];
		}
		if ( '' !== $node['xfn'] ) {
			$item['rel'] = $node['xfn'];
		}
		if ( ! empty( $node['invalid'] ) ) {
			$item['hidden'] = 'The link target is missing or in the trash: the site does not show this item.';
		}
		$item['children'] = guarded_mcp_menu_tree_payload( $model, $target, $child );

		$out[] = $item;
	}

	return $out;
}

/**
 * Write the model into the menu: update changed items, create new ones, delete
 * the ones that are gone. Validation is done before this; a WP error mid-way is
 * returned and the caller rolls back from the backup.
 *
 * @param int   $menu_id Menu ID.
 * @param array $items   Items as loaded before the change (by ID).
 * @param array $model   Target model.
 * @return array{created:int,updated:int,removed:int}|WP_Error
 */
function guarded_mcp_menu_write( $menu_id, array $items, array $model ) {
	$db       = array( 'root' => 0 );
	$kept     = array();
	$position = 0;
	$counts   = array(
		'created' => 0,
		'updated' => 0,
		'removed' => 0,
	);

	foreach ( guarded_mcp_menu_walk( $model ) as $row ) {
		$node      = $model['nodes'][ $row['key'] ];
		$parent_id = $db[ $row['parent_key'] ];
		$current   = $node['id'] && isset( $items[ $node['id'] ] ) ? $items[ $node['id'] ] : null;
		++$position;

		$args = array(
			'menu-item-object-id'   => (int) $node['object_id'],
			'menu-item-object'      => (string) $node['object'],
			'menu-item-parent-id'   => (int) $parent_id,
			'menu-item-position'    => $position,
			'menu-item-type'        => (string) $node['type'],
			'menu-item-title'       => (string) $node['title'],
			'menu-item-url'         => (string) $node['url'],
			'menu-item-description' => (string) $node['description'],
			'menu-item-attr-title'  => (string) $node['attr_title'],
			'menu-item-target'      => (string) $node['target'],
			'menu-item-classes'     => (string) $node['classes'],
			'menu-item-xfn'         => (string) $node['xfn'],
			'menu-item-status'      => 'publish',
		);

		$same = $current
			&& $current['parent'] === (int) $parent_id
			&& $current['order'] === $position
			&& $current['type'] === $node['type']
			&& $current['object'] === $node['object']
			&& $current['object_id'] === (int) $node['object_id']
			&& $current['url'] === $node['url']
			&& html_entity_decode( $current['title'], ENT_QUOTES, 'UTF-8' ) === html_entity_decode( $node['title'], ENT_QUOTES, 'UTF-8' )
			&& $current['target'] === $node['target']
			&& $current['attr_title'] === $node['attr_title']
			&& $current['classes'] === $node['classes']
			&& $current['xfn'] === $node['xfn'];

		if ( $same ) {
			$item_id = (int) $node['id'];
		} else {
			// Slashed like the menu editor's $_POST: wp_insert_post() and the meta
			// API unslash, and a label with a quote or backslash must survive.
			$item_id = wp_update_nav_menu_item( $menu_id, $current ? (int) $node['id'] : 0, wp_slash( $args ) );
			if ( is_wp_error( $item_id ) ) {
				return $item_id;
			}
			$item_id = (int) $item_id;
			++$counts[ $current ? 'updated' : 'created' ];
		}

		$db[ $row['key'] ] = $item_id;
		$kept[ $item_id ]  = true;

		if ( ! $current && is_array( $node['pll'] ) ) {
			update_post_meta( $item_id, '_pll_menu_item', $node['pll'] );
		}
	}

	foreach ( array_keys( $items ) as $id ) {
		if ( isset( $kept[ $id ] ) ) {
			continue;
		}
		if ( ! wp_delete_post( $id, true ) ) {
			return new WP_Error(
				'guarded_mcp_menu_delete_failed',
				sprintf(
					/* translators: %d: menu item ID. */
					__( 'Could not remove menu item %d.', 'guarded-mcp' ),
					$id
				)
			);
		}
		++$counts['removed'];
	}

	if ( array_sum( $counts ) ) {
		$menu = wp_get_nav_menu_object( $menu_id );

		/** This action is documented in wp-includes/nav-menu.php — cache plugins purge on it. */
		do_action( 'wp_update_nav_menu', $menu_id, array( 'menu-name' => $menu ? $menu->name : '' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook, fired on purpose so cache plugins purge menus.
	}

	return $counts;
}

/**
 * Put a menu back to a backup tree (restore, and rollback of a failed write).
 *
 * @param array $target Resolved menu target.
 * @param array $tree   Backup tree.
 * @return array{created:int,updated:int,removed:int}|WP_Error
 */
function guarded_mcp_menu_write_backup( array $target, array $tree ) {
	$items = guarded_mcp_menu_load( $target['menu_id'] );
	$model = array(
		'nodes'    => array(),
		'children' => array( 'root' => array() ),
	);

	$built = guarded_mcp_menu_model_from_backup( $tree, $items, $model );
	if ( is_wp_error( $built ) ) {
		return $built;
	}

	return guarded_mcp_menu_write( $target['menu_id'], $items, $model );
}

/**
 * Backup tree of a model: every stored field, nested.
 *
 * @param array  $model Menu model.
 * @param string $key   Parent key.
 * @return array
 */
function guarded_mcp_menu_backup_tree( array $model, $key = 'root' ) {
	$out    = array();
	$fields = array( 'id', 'type', 'object', 'object_id', 'url', 'title', 'target', 'attr_title', 'classes', 'xfn', 'description', 'pll' );

	foreach ( $model['children'][ $key ] as $child ) {
		$node             = array_intersect_key( $model['nodes'][ $child ], array_flip( $fields ) );
		$node['children'] = guarded_mcp_menu_backup_tree( $model, $child );
		$out[]            = $node;
	}

	return $out;
}

/**
 * Count the nodes of a nested tree.
 *
 * @param array $tree Tree (nodes with `children`).
 * @return int
 */
function guarded_mcp_menu_tree_count( array $tree ) {
	$count = 0;
	foreach ( $tree as $node ) {
		$count += 1 + guarded_mcp_menu_tree_count( isset( $node['children'] ) ? (array) $node['children'] : array() );
	}
	return $count;
}

/**
 * Stored backups, oldest first.
 *
 * @return array
 */
function guarded_mcp_menu_backups() {
	$backups = get_option( 'guarded_mcp_menu_backups', array() );
	return is_array( $backups ) ? $backups : array();
}

/**
 * Save a backup of a menu; keeps the last 10 per location + language.
 *
 * @param array  $target Resolved menu target.
 * @param array  $model  Model of the menu as it is now.
 * @param string $reason What is about to happen.
 * @return string Backup ID.
 */
function guarded_mcp_menu_backup_save( array $target, array $model, $reason ) {
	$menu = wp_get_nav_menu_object( $target['menu_id'] );
	$tree = guarded_mcp_menu_backup_tree( $model );
	$id   = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 4, false ) );

	$backups   = guarded_mcp_menu_backups();
	$backups[] = array(
		'id'          => $id,
		'location'    => $target['location'],
		'language'    => $target['language'],
		'menu_id'     => (int) $target['menu_id'],
		'menu_name'   => $menu ? $menu->name : '',
		'created'     => gmdate( 'c' ),
		'reason'      => (string) $reason,
		'items_count' => guarded_mcp_menu_tree_count( $tree ),
		'items'       => $tree,
	);

	// Last 10 per menu slot.
	$per_slot = array();
	foreach ( array_reverse( array_keys( $backups ) ) as $index ) {
		$slot = $backups[ $index ]['location'] . '|' . $backups[ $index ]['language'];
		if ( ! isset( $per_slot[ $slot ] ) ) {
			$per_slot[ $slot ] = 0;
		}
		if ( ++$per_slot[ $slot ] > 10 ) {
			unset( $backups[ $index ] );
		}
	}

	// Not autoloaded: read only by the menu abilities.
	update_option( 'guarded_mcp_menu_backups', array_values( $backups ), false );

	return $id;
}

/**
 * Backups of one menu slot, newest first, without the item trees.
 *
 * @param string $location Theme location.
 * @param string $language Polylang slug or ''.
 * @return array
 */
function guarded_mcp_menu_backups_for( $location, $language ) {
	$out = array();

	foreach ( array_reverse( guarded_mcp_menu_backups() ) as $backup ) {
		if ( $backup['location'] === $location && $backup['language'] === $language ) {
			$out[] = array_intersect_key( $backup, array_flip( array( 'id', 'created', 'reason', 'items_count', 'menu_name' ) ) );
		}
	}

	return $out;
}

/**
 * One backup by ID.
 *
 * @param string $id Backup ID.
 * @return array|null
 */
function guarded_mcp_menu_backup_get( $id ) {
	foreach ( guarded_mcp_menu_backups() as $backup ) {
		if ( $backup['id'] === $id ) {
			return $backup;
		}
	}
	return null;
}
