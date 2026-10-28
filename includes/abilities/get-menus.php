<?php
/**
 * Ability: guarded-mcp/get-menus — read the site menus. Read-only.
 *
 * For every theme location (and Polylang language): the assigned menu and its
 * item tree (labels, link targets, nesting), how the theme renders the location, and the backups update-menu took before past
 * changes. The item format is the one update-menu accepts in `replace`, so a
 * menu read on one site can be rebuilt on another. See menus.php.
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
			'guarded-mcp/get-menus',
			array(
				'label'               => __( 'Read menus', 'guarded-mcp' ),
				'description'         => __( 'Read the site menus: for each theme location (and language, with Polylang), the assigned menu and its item tree — id, label, link (page path, blog post, category or custom URL), new tab, nesting. Also how the theme renders each location and the backups taken before past changes. Read-only. Use before update-menu: item ids come from here.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-menus',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'location' => array_merge(
							guarded_mcp_menu_location_schema(),
							array( 'description' => 'Optional: only this theme location. Omit for all.' )
						),
						'language' => array(
							'type'        => 'string',
							'description' => 'Optional Polylang language slug (e.g. "en"): only this language. Omit for all. Ignored without Polylang.',
						),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'languages' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'menus'     => array(
							'type'        => 'array',
							'description' => 'One entry per location and language: location, language, note (how the theme renders it), max_depth, menu_id (0 = nothing assigned), menu_name, shown_in (every location/language this same menu sits in — more than one means a change applies to all), items_count, items (tree: id, label, label_follows_link, link, new_tab, title_attr?, rel?, hidden?, children), backups (newest first: id, created, reason, items_count).',
							'items'       => array( 'type' => 'object' ),
						),
					),
				),
				'execute_callback'    => function ( $input ) {
					$input     = is_array( $input ) ? $input : array();
					$locations = guarded_mcp_menu_locations();
					$languages = guarded_mcp_menu_languages();

					$want_locations = isset( $input['location'] ) ? array( (string) $input['location'] ) : array_keys( $locations );
					$want_languages = $languages ? ( isset( $input['language'] ) ? array( sanitize_key( (string) $input['language'] ) ) : $languages ) : array( '' );

					$menus = array();

					foreach ( $want_locations as $location ) {
						foreach ( $want_languages as $language ) {
							$target = guarded_mcp_menu_target(
								array(
									'location' => $location,
									'language' => $language,
								)
							);
							if ( is_wp_error( $target ) ) {
								return $target;
							}

							$entry = array(
								'location'    => $target['location'],
								'language'    => $target['language'],
								'note'        => $target['config']['note'],
								'max_depth'   => (int) $target['config']['max_depth'],
								'menu_id'     => (int) $target['menu_id'],
								'menu_name'   => '',
								'items_count' => 0,
								'items'       => array(),
								'backups'     => guarded_mcp_menu_backups_for( $target['location'], $target['language'] ),
							);

							if ( $target['menu_id'] ) {
								$items = guarded_mcp_menu_load( $target['menu_id'] );
								$menu  = wp_get_nav_menu_object( $target['menu_id'] );

								$entry['menu_name']   = $menu ? $menu->name : '';
								$entry['items_count'] = count( $items );
								$entry['shown_in']    = guarded_mcp_menu_slots( $target['menu_id'] );
								$entry['items']       = guarded_mcp_menu_tree_payload( guarded_mcp_menu_model( $items ), $target );
							} else {
								$entry['note'] .= ' No menu is assigned here for this language: the site shows nothing in this place. Creating and assigning menus is done by a person in Appearance → Menus.';
							}

							$menus[] = $entry;
						}
					}

					return array(
						'languages' => $languages,
						'menus'     => $menus,
					);
				},
				'permission_callback' => function () {
					return guarded_mcp_menu_can_edit();
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
		$abilities[] = 'guarded-mcp/get-menus';
		return $abilities;
	}
);
