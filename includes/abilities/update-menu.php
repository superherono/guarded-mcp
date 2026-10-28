<?php
/**
 * Ability: guarded-mcp/update-menu — change one site menu.
 *
 * Three modes, exactly one per call:
 *  - operations — add / update / move / remove items of the current menu;
 *  - replace — rebuild the whole menu from a tree in the get-menus format
 *    (copying a menu from another site for a local preview);
 *  - restore_backup — put the menu back to a backup.
 *
 * A menu has no draft: a saved change is live on every page at once. So the
 * call is a dry run unless dry_run=false — it returns the resulting tree and
 * saves nothing. Before a real write the menu is backed up; a write that fails
 * half-way is rolled back from that backup. See menus.php.
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

		$link_schema = array(
			'type'        => 'object',
			'description' => 'Link target. {"type":"page","path":"about/team"} — path as in the URL without the language prefix, "" = front page — or {"type":"page","id":123}; {"type":"post","slug":"…"} or id; {"type":"category","slug":"…"} or id; {"type":"custom","url":"https://…" | "/path/" | "#anchor" | "mailto:…" | "tel:…"}. Page, post and category must be published and in the menu language. With both id and path the path wins.',
			'properties'  => array(
				'type' => array(
					'type' => 'string',
					'enum' => array( 'page', 'post', 'category', 'custom' ),
				),
				'id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'path' => array( 'type' => 'string' ),
				'slug' => array( 'type' => 'string' ),
				'url'  => array( 'type' => 'string' ),
			),
			'required'    => array( 'type' ),
		);

		$operation_schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'op'            => array(
					'type'        => 'string',
					'enum'        => array( 'add', 'update', 'move', 'remove' ),
					'description' => 'add — new item (needs link); update — change fields of an item; move — new parent and/or position; remove — delete the item (the link, not the page).',
				),
				'item'          => array(
					'type'        => array( 'integer', 'string' ),
					'description' => 'update / move / remove: item id from get-menus, or the ref of an item added earlier in this call.',
				),
				'ref'           => array(
					'type'        => 'string',
					'description' => 'add: optional name of the new item (letters, digits, - and _, starts with a letter) to use as item or parent in later operations of this call.',
				),
				'parent'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => 'add / move: parent item id or ref; 0 = top level. add defaults to the top level, move keeps the current parent.',
				),
				'position'      => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => '1-based position among the parent\'s items; omitted = last.',
				),
				'label'         => array(
					'type'        => 'string',
					'description' => 'Navigation label, plain text. On a page / post / category link "" means "follow the target\'s title". Required on a custom link.',
				),
				'link'          => $link_schema,
				'new_tab'       => array(
					'type'        => 'boolean',
					'description' => 'Open the link in a new tab.',
				),
				'title_attr'    => array(
					'type'        => 'string',
					'description' => 'Optional title attribute (tooltip) of the link.',
				),
				'rel'           => array(
					'type'        => 'string',
					'description' => 'Optional rel values of the link, space-separated (e.g. "nofollow").',
				),
				'with_children' => array(
					'type'        => 'boolean',
					'description' => 'remove: also remove the nested items. Without it an item with children is not removed.',
				),
			),
			'required'             => array( 'op' ),
			'additionalProperties' => false,
		);

		wp_register_ability(
			'guarded-mcp/update-menu',
			array(
				'label'               => __( 'Update menu', 'guarded-mcp' ),
				'description'         => __( 'Change one site menu (location + language). Exactly one of: operations (add / update / move / remove items — label, link target, nesting, order, new tab), replace (rebuild the whole menu from a tree in the get-menus format — for copying a menu from another site), restore_backup (put the menu back to a backup listed by get-menus). DRY RUN BY DEFAULT: returns the resulting tree, changes and warnings and saves nothing. A saved menu is live on every page at once — save (dry_run=false) only after a person has seen the dry run. Every save backs the menu up first and returns backup_id for undo. Removing an item deletes the link, never the page.', 'guarded-mcp' ),
				'category'            => 'guarded-mcp-menus',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'location'          => guarded_mcp_menu_location_schema(),
						'language'          => array(
							'type'        => 'string',
							'description' => 'Polylang language slug (e.g. "en"): with Polylang every language has its own menu. Required while Polylang is active; omit it otherwise.',
						),
						'operations'        => array(
							'type'        => 'array',
							'minItems'    => 1,
							'items'       => $operation_schema,
							'description' => 'Edits applied in order to the current menu; each sees the result of the previous ones. All or nothing: one invalid operation and nothing is saved.',
						),
						'replace'           => array(
							'type'        => 'array',
							'items'       => array( 'type' => 'object' ),
							'description' => 'The whole new menu as a tree in the get-menus item format: {label, link, new_tab?, title_attr?, rel?, children?}; ids are ignored. Every current item is removed and the tree is created anew. An empty list empties the menu.',
						),
						'restore_backup'    => array(
							'type'        => 'string',
							'description' => 'ID of a backup of this location and language (get-menus → backups): the menu goes back to how it was then.',
						),
						'dry_run'           => array(
							'type'        => 'boolean',
							'default'     => true,
							'description' => 'Default true: validate and return the resulting tree, save nothing. Pass false to save.',
						),
						'preview_fallbacks' => array(
							'type'        => 'boolean',
							'description' => 'replace only; refused on the production site. A page, post or category that does not exist here becomes a custom link to its url, with a warning, instead of an error. For a local preview copy of the live menu.',
						),
					),
					'required'             => array( 'location' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'location'  => array( 'type' => 'string' ),
						'language'  => array( 'type' => 'string' ),
						'menu_id'   => array( 'type' => 'integer' ),
						'menu_name' => array( 'type' => 'string' ),
						'dry_run'   => array( 'type' => 'boolean' ),
						'saved'     => array( 'type' => 'boolean' ),
						'backup_id' => array(
							'type'        => 'string',
							'description' => 'Backup taken before the save; "" on a dry run. Undo: restore_backup with this ID.',
						),
						'written'   => array(
							'type'        => 'object',
							'description' => 'Items created, updated and removed by the save (zeros on a dry run).',
						),
						'changes'   => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'warnings'  => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'items'     => array(
							'type'        => 'array',
							'description' => 'The menu tree after the change, in the get-menus format. On a dry run new items have id 0 and their ref.',
							'items'       => array( 'type' => 'object' ),
						),
						'next'      => array( 'type' => 'string' ),
					),
				),
				'execute_callback'    => function ( $input ) {
					$input  = is_array( $input ) ? $input : array();
					$target = guarded_mcp_menu_target( $input );
					if ( is_wp_error( $target ) ) {
						return $target;
					}

					if ( ! $target['menu_id'] ) {
						return new WP_Error(
							'guarded_mcp_menu_not_assigned',
							sprintf(
								/* translators: 1: location, 2: language. */
								__( 'No menu is assigned to "%1$s" for language "%2$s". Creating and assigning menus is not available through MCP: a person does it in Appearance → Menus.', 'guarded-mcp' ),
								$target['location'],
								$target['language']
							)
						);
					}

					$modes = array_values( array_intersect( array( 'operations', 'replace', 'restore_backup' ), array_keys( $input ) ) );
					if ( 1 !== count( $modes ) ) {
						return new WP_Error( 'guarded_mcp_menu_mode', __( 'Pass exactly one of: operations, replace, restore_backup.', 'guarded-mcp' ) );
					}
					$mode = $modes[0];

					$dry_run   = ! array_key_exists( 'dry_run', $input ) || ! empty( $input['dry_run'] );
					$fallbacks = ! empty( $input['preview_fallbacks'] );

					if ( $fallbacks && 'replace' !== $mode ) {
						return new WP_Error( 'guarded_mcp_menu_fallbacks_mode', __( 'preview_fallbacks works with replace only.', 'guarded-mcp' ) );
					}
					if ( $fallbacks && 'production' === wp_get_environment_type() ) {
						return new WP_Error( 'guarded_mcp_menu_fallbacks_production', __( 'preview_fallbacks is refused on the production site: a copy with placeholder links must never reach visitors.', 'guarded-mcp' ) );
					}

					$items     = guarded_mcp_menu_load( $target['menu_id'] );
					$current   = guarded_mcp_menu_model( $items );
					$changes   = array();
					$warnings  = array();
					$model     = array(
						'nodes'    => array(),
						'children' => array( 'root' => array() ),
					);

					if ( 'operations' === $mode ) {
						$model  = $current;
						$result = guarded_mcp_menu_apply_operations( $model, (array) $input['operations'], $target, $changes, $warnings );
					} elseif ( 'replace' === $mode ) {
						$result    = guarded_mcp_menu_model_from_input( (array) $input['replace'], $target, $fallbacks, $warnings, $model );
						$changes[] = sprintf( 'replace: the menu is rebuilt — %d item(s) instead of %d', count( $model['nodes'] ), count( $items ) );
					} else {
						$backup = guarded_mcp_menu_backup_get( (string) $input['restore_backup'] );
						if ( ! $backup || $backup['location'] !== $target['location'] || $backup['language'] !== $target['language'] ) {
							return new WP_Error(
								'guarded_mcp_menu_backup_not_found',
								sprintf(
									/* translators: 1: backup ID, 2: location, 3: language. */
									__( 'Backup "%1$s" does not exist for "%2$s" / "%3$s". get-menus lists the backups of each menu.', 'guarded-mcp' ),
									(string) $input['restore_backup'],
									$target['location'],
									$target['language']
								)
							);
						}
						$result    = guarded_mcp_menu_model_from_backup( $backup['items'], $items, $model );
						$changes[] = sprintf( 'restore backup %s (%s, %s): %d item(s) instead of %d', $backup['id'], $backup['created'], $backup['reason'], (int) $backup['items_count'], count( $items ) );
					}

					if ( is_wp_error( $result ) ) {
						return $result;
					}

					$checked = guarded_mcp_menu_check_model( $model, $target );
					if ( is_wp_error( $checked ) ) {
						return $checked;
					}

					$menu  = wp_get_nav_menu_object( $target['menu_id'] );
					$slots = guarded_mcp_menu_slots( $target['menu_id'] );
					if ( count( $slots ) > 1 ) {
						$warnings[] = sprintf( 'Menu "%s" is shown in %d places (%s): the change applies to all of them.', $menu ? $menu->name : '', count( $slots ), implode( ', ', $slots ) );
					}

					$output = array(
						'location'  => $target['location'],
						'language'  => $target['language'],
						'menu_id'   => (int) $target['menu_id'],
						'menu_name' => $menu ? $menu->name : '',
						'dry_run'   => $dry_run,
						'saved'     => false,
						'backup_id' => '',
						'written'   => array(
							'created' => 0,
							'updated' => 0,
							'removed' => 0,
						),
						'changes'   => $changes,
						'warnings'  => array_values( array_unique( $warnings ) ),
						'items'     => guarded_mcp_menu_tree_payload( $model, $target ),
						'next'      => 'Dry run: nothing saved. Show the resulting tree to a person; to save, repeat the call with the same input and dry_run=false.',
					);

					if ( $dry_run ) {
						return $output;
					}

					$backup_id = guarded_mcp_menu_backup_save( $target, $current, 'before update-menu: ' . $mode );
					$written   = guarded_mcp_menu_write( $target['menu_id'], $items, $model );

					if ( is_wp_error( $written ) ) {
						$backup   = guarded_mcp_menu_backup_get( $backup_id );
						$rollback = $backup ? guarded_mcp_menu_write_backup( $target, $backup['items'] ) : new WP_Error( 'guarded_mcp_menu_backup_not_found', 'backup missing' );

						return new WP_Error(
							$written->get_error_code(),
							$written->get_error_message() . ( is_wp_error( $rollback )
								? sprintf( ' Rolling back from backup %1$s failed too: %2$s', $backup_id, $rollback->get_error_message() )
								: sprintf( ' Nothing changed: the menu was put back from backup %s.', $backup_id ) ),
							array( 'backup_id' => $backup_id )
						);
					}

					$output['saved']     = true;
					$output['backup_id'] = $backup_id;
					$output['written']   = $written;
					$output['items']     = guarded_mcp_menu_tree_payload( guarded_mcp_menu_model( guarded_mcp_menu_load( $target['menu_id'] ) ), $target );
					$output['next']      = sprintf(
						'Saved: the menu is live on every page in this language.%s Undo: update-menu with restore_backup "%s".',
						'production' === wp_get_environment_type() ? ' Ask the owner to clear the CDN cache.' : '',
						$backup_id
					);

					return $output;
				},
				'permission_callback' => function () {
					return guarded_mcp_menu_can_edit();
				},
				'meta'                => guarded_mcp_ability_meta(
					array(
						'readonly'    => false,
						'destructive' => true, // Removing items deletes nav_menu_item posts.
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
		$abilities[] = 'guarded-mcp/update-menu';
		return $abilities;
	}
);
