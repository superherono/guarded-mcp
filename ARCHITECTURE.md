# Architecture

This document is for anyone, a person or an AI agent, who maintains or extends Guarded MCP. The tool list, installation and troubleshooting are in [README.md](README.md).

The plugin exposes a WordPress site to a language model. A model can be wrong, and the text it reads can contain instructions that its user never gave. The rules below exist so that changes to the code do not quietly weaken the trust boundary. If a change needs an exception, it is a deliberate change to the boundary: guard it with a check, describe it here, and keep the default safe.

## Threat model

**What is protected**

- Published content: pages, posts, menus and media that visitors see.
- The site itself: PHP execution, files on the server, other users' accounts.
- The MCP token, which lets a client act as the bot.

**Who can reach the plugin**

| Actor | Entry point | Trust |
|---|---|---|
| MCP client with the token (an AI agent) | `POST /wp-json/guarded-mcp/v1/mcp?token=…` | Partly trusted. It acts for the site owner, but the model can make mistakes or follow instructions planted in text it reads (prompt injection). Its input is treated as hostile. |
| Logged-in user on the MCP Adapter default server | `/wp-json/mcp/mcp-adapter-default-server` | As trusted as that user's own capabilities. Every ability still runs its permission check and the content guard. |
| Holder of an upload ticket | `POST /wp-json/guarded-mcp/v1/media-upload` | Can save one image with metadata fixed when the ticket was issued, once, within 15 minutes. Nothing else. |
| Anyone else on the internet | both routes | Untrusted. Gets 401. |
| A person in wp-admin | WordPress itself | Trusted. Publishes, deletes, creates and assigns menus. |

**What the plugin must prevent, whatever the agent sends**

| Threat | How it is stopped | Invariant |
|---|---|---|
| Publishing or deleting content | Pages and posts are created as drafts; no ability changes status or deletes; the bot role has no `publish_*` or `delete_*` | 4 |
| Scripts or raw HTML in content (stored XSS, for example in a draft an admin previews) | Recursive block allowlist; HTML inside allowed blocks is filtered with the KSES rules for users without `unfiltered_html`; no `core/html` | 2, 3 |
| Acting with more rights than intended | Token compared with `hash_equals()`; requests run as a low-privilege bot; a bot user with admin-level capabilities is refused | 1, 5 |
| Reading files on the server | No ability takes a path; the upload takes bytes only, never a path or URL | 6, 7 |
| Making the server fetch a URL (SSRF) | No ability downloads anything | 7 |
| Uploading executable files or files that crash the server | Type read from the bytes, raster only, no SVG, dots in names replaced, size, side and pixel caps | 7 |
| Breaking site-wide navigation | Menu writes are dry runs by default, backed up before every save, rolled back on failure | 8 |
| Script links in menus | Custom URLs only `http(s)`, `mailto:`, `tel:`, root-relative or `#anchor` | 8 |

**Out of scope**

- A leaked MCP token. Whoever has it can do what the bot can. Rotate it with `wp option delete guarded_mcp_token`.
- What a person publishes. The plugin keeps a human in the loop; it does not review the content for them.
- Theme or plugin code that renders block attributes without escaping. The guard filters HTML, but a dynamic block's `render.php` is the theme's responsibility.
- Rate limiting and abuse of the endpoint itself. Use the web server or a firewall for that.
- PHP execution in `wp-content/uploads`. That is a server setting, and it protects every upload on the site, including manual ones.

## Code layout

| File | Role |
|---|---|
| `guarded-mcp.php` | Plugin header and bootstrap. Loads the parts in order and every file in `includes/abilities/`. |
| `includes/support.php` | Shared helpers: `guarded_mcp_ability_meta()` (the standard `meta` block), `guarded_mcp_lint_file()` (PHP lint without `exec`), `guarded_mcp_registered_block_styles()`. |
| `includes/content-guard.php` | The trust boundary for content: block allowlist, `guarded_mcp_validate_blocks()`, and `guarded_mcp_save_post_raw()`, the one place where KSES is bypassed. |
| `includes/posts.php` | Shared by the post abilities: schemas and validation of post-only fields (categories, featured image, excerpt, Polylang language) and the read payload. Validation runs before the first write. |
| `includes/seo.php` | Yoast SEO fields for pages and posts (`seo_title`, `seo_description`). Writes are rejected when Yoast is not active. |
| `includes/terms.php` | Shared by the term abilities: the allowed taxonomies, schemas, validation before writing (parent without cycles, Polylang language and translations, duplicate names and slugs), payload. |
| `includes/media.php` | Shared by the image abilities: field schemas, upload tickets (issue and claim), **file checks** (the trust boundary of the upload), saving to the media library, duplicate detection by SHA-256, payload. |
| `includes/menus.php` | Shared by the menu abilities: allowed locations, menus by location and language, an in-memory menu model, operations, **checks before writing**, the write itself, backups and rollback. |
| `includes/access.php` | The bot role (`guarded_mcp_bot`), the bot user and its privilege check, the token. |
| `includes/server.php` | The custom token-authenticated MCP server at `/wp-json/guarded-mcp/v1/mcp`. Its tool list comes from a filter. |
| `includes/media-upload.php` | `POST /wp-json/guarded-mcp/v1/media-upload`, step 2 of an image upload. **The only entry point that bypasses the MCP server**; it is authorised by a single-use ticket from `prepare-media-upload`. |
| `includes/abilities/*.php` | One file per ability. Each registers itself and adds itself to the server filter. `category.php` registers the ability categories. |
| `uninstall.php` | Removes the role, the menu capability and the plugin's options. Content and the bot user stay. |
| `tests/` | PHPUnit tests (`*Test.php`, run in wp-env), PHPStan stubs for Polylang, Yoast SEO and the MCP Adapter. Not part of the installable plugin. |

## Load order

```
guarded-mcp.php
  ├─ support.php, content-guard.php, posts.php, seo.php,
  │  terms.php, media.php, menus.php        helpers, no side effects
  ├─ access.php       add_action('init')               role sync, token
  ├─ server.php       add_action('mcp_adapter_init')   create_server()
  ├─ media-upload.php add_action('rest_api_init')      upload route
  └─ abilities/*.php  add_action('wp_abilities_api_init') wp_register_ability()
                      add_filter('guarded_mcp_server_abilities') expose on the server
```

Everything after loading runs on hooks, so WordPress decides the timing. The order of `require` only guarantees that helper functions exist before the hooks fire. `includes/abilities/` is read with `glob()`: a new file there is loaded without editing the bootstrap.

The MCP Adapter is a separate plugin (`Requires Plugins: mcp-adapter`). Adapter classes are referenced only inside the `mcp_adapter_init` callback, so without the adapter the plugin does not fatal: the abilities still register through the core Abilities API, they are just not exposed over MCP.

## Two servers

Every ability is reachable through both servers. Both run the ability's permission check and the content guard: a server is a gate, not a replacement for those checks.

1. **The MCP Adapter default server.** It exposes abilities with `meta.mcp.public = true` (set by `guarded_mcp_ability_meta()`) to logged-in users, with their own capabilities. Useful for local clients that can authenticate as a WordPress user.
2. **The custom server** (`server.php`). It exposes the abilities from the `guarded_mcp_server_abilities` filter behind a token in the URL and runs each request as the bot user. It exists because some MCP clients, the Claude connector among them, support only OAuth or no authentication: no Basic auth, no custom headers.

## Endpoint and protocol

`server.php` calls `create_server( 'guarded-mcp', 'guarded-mcp/v1', 'mcp', … )`. The adapter's HTTP transport registers the REST route, so the URL is `/wp-json/guarded-mcp/v1/mcp`. The full connector URL is `<home_url>/wp-json/guarded-mcp/v1/mcp?token=<token>`; only the token is generated at runtime.

The transport speaks JSON-RPC 2.0 over `POST` and requires a session: call `initialize` first and send the returned `Mcp-Session-Id` header with every later request.

MCP tool names cannot contain `/`, so the adapter turns ability names into tool names with `-`:

| Ability | MCP tool |
|---|---|
| `guarded-mcp/get-page` | `guarded-mcp-get-page` |
| `guarded-mcp/update-menu` | `guarded-mcp-update-menu` |

## Security invariants: do not weaken

Every change must keep all eight.

1. **Every ability has a `permission_callback` with a specific capability.** Writes to an existing object check the capability on that object (`edit_page`, `$id`).
2. **All saved content first passes `guarded_mcp_validate_blocks()`.** It walks the block tree recursively: a block is allowed if its namespace is in `guarded_mcp_block_namespaces()` (empty by default) or it is one of the trusted core blocks. `core/html`, `core/shortcode` and `core/freeform` are never trusted, and the `core` namespace cannot be allowed as a whole. Raw HTML outside blocks is rejected.
3. **KSES is bypassed only in `guarded_mcp_save_post_raw()`, only after rule 2, and HTML inside blocks is filtered there first.** The bot has no `unfiltered_html`. The allowlist decides which blocks may appear but does not look inside them, so before saving, `guarded_mcp_save_post_raw()` runs the content through `guarded_mcp_kses_blocks()`: it parses the blocks, filters the HTML of every block with `wp_kses()` and its attribute values with core's `filter_block_kses_value()`, both with the `post` rules (filter `guarded_mcp_allowed_html`), and serialises the blocks again. `<script>`, event handler attributes and `javascript:` URLs are removed from block HTML and block attributes, and the delimiters stay intact, which is why full-content KSES is switched off for this one save. (Core's `filter_block_content()` filters only the attributes.) Never save block content with a bare `wp_insert_post()` / `wp_update_post()`.
4. **`create-page` and `create-post` always create drafts. Publishing is a human action.** No ability changes a status. There is no delete ability for posts, terms or images, and the bot role has no `publish_*` or `delete_*` capabilities (`manage_categories` would let WordPress delete terms, but MCP does not expose it). Two exceptions, each closed by its own rule: an uploaded image is reachable by its URL at once (rule 7), and a saved menu is live at once (rule 8).
5. **The token is compared with `hash_equals()`, and requests run as a dedicated low-privilege bot, never as an administrator.** `guarded_mcp_bot_user()` refuses a bot user that has `unfiltered_html`, `manage_options`, `edit_theme_options`, publishing or deleting capabilities, so a role added to the bot by mistake stops the server instead of widening it.
6. **`site-health` takes no input.** It never accepts a path from the client, and its output names files relative to `wp-content` (or to the folder that holds the plugin), never with a server path.
7. **Files reach the site only through `media-upload.php` and only after `guarded_mcp_media_check_file()`.**
   - The route accepts a request only with a single-use ticket that `prepare-media-upload` issues to a caller who already has `upload_files`. The ticket grants nothing beyond that: it saves one image with the metadata fixed at issue time.
   - The ticket is claimed in the `permission_callback`, before the file is read. Claiming is atomic: the option row is deleted, and only one request sees one affected row. It goes straight to the table, bypassing the options API, so random tickets do not bloat the `notoptions` cache. Only a hash of the ticket is stored.
   - File checks: PNG, JPEG or WebP only, type read from the content with `wp_getimagesize()`, not from the name. **Never SVG**: it can carry script. Dots in the file name become dashes and the extension comes from the real type (`shell.php.png` → `shell-php.png`). Caps on file size (never above the server limit), on each side and on the pixel count stop "image bombs": a small file that declares huge dimensions would make GD allocate 4 to 5 bytes per pixel while resizing, and 8000 × 8000 would exhaust 256 MB and leave a half-made attachment. A type the server's image editor cannot process (WebP on some hosts) is rejected, because WordPress would make no sizes.
   - Never accept a path on disk or a URL to download from the client. The first means reading arbitrary files, the second means SSRF.
8. **Menus change only through `menus.php`, and every write has a dry run and a backup.** A menu has no drafts or revisions: a saved change is on every page at once.
   - `update-menu` is a dry run unless the client passes `dry_run: false`.
   - Before each write the whole menu is copied into the `guarded_mcp_menu_backups` option (the last 10 per location and language). A write that fails half-way is rolled back from that copy, and `restore_backup` returns to any of them.
   - The whole change is validated on an in-memory model before the first write.
   - A menu is addressed by theme location and language, never by a free menu ID, so only menus the site shows can be touched. Creating menus and assigning them to locations is not possible through MCP.
   - Removing an item deletes a `nav_menu_item` post. This is the only delete in the plugin, and it removes a link, never the page. An item with children is removed only with `with_children`.
   - A link target must be a published page, post or category in the menu's language. A custom URL may only be `http(s)`, `mailto:`, `tel:`, a root-relative path or `#anchor`: no `javascript:`, no `//host`. Control characters and backslashes are refused anywhere in the URL: browsers and `esc_url()` drop tabs and newlines, so `/<tab>/host` would become `//host`. Labels and attributes are plain text.
   - The capability is the plugin's own `guarded_mcp_edit_menus`, not `edit_theme_options`, which would also open the Customizer and widgets.
   - `preview_fallbacks` (a missing target becomes a placeholder link) is refused when `wp_get_environment_type()` is `production`: a copy with placeholders must never reach visitors.

## Extending

### Add an ability

1. Copy an existing file in `includes/abilities/` to `includes/abilities/<your-ability>.php`.
2. Register it:
   ```php
   add_action( 'wp_abilities_api_init', function () {
       if ( ! function_exists( 'wp_register_ability' ) ) {
           return;
       }
       wp_register_ability( 'guarded-mcp/<your-ability>', array(
           'label'               => __( '…', 'guarded-mcp' ),
           'description'         => __( '…', 'guarded-mcp' ),
           'category'            => 'guarded-mcp-pages',
           'input_schema'        => array( /* … */ ),
           'output_schema'       => array( /* … */ ),
           'execute_callback'    => function ( $input ) { /* … */ },
           'permission_callback' => function () { return current_user_can( '<cap>' ); },
           'meta'                => guarded_mcp_ability_meta( array(
               'readonly' => false, 'destructive' => false, 'idempotent' => false,
           ) ),
       ) );
   } );
   ```
3. Expose it on the custom server:
   ```php
   add_filter( 'guarded_mcp_server_abilities', function ( $abilities ) {
       $abilities[] = 'guarded-mcp/<your-ability>';
       return $abilities;
   } );
   ```
4. If it writes content: `guarded_mcp_validate_blocks()`, then `guarded_mcp_save_post_raw()`.
5. Nothing else to wire: the bootstrap loads the file.

### Filters

| Filter | Purpose | Default |
|---|---|---|
| `guarded_mcp_block_namespaces` | Namespaces whose blocks are all allowed in content (your theme's or block plugin's) | none |
| `guarded_mcp_trusted_core_blocks` | Trusted `core/*` blocks | 22 blocks: text, media, layout, quote, table, gallery, Query Loop |
| `guarded_mcp_is_block_allowed` | Final allow or deny per block name | namespaces + trusted core |
| `guarded_mcp_allowed_html` | HTML allowed inside blocks (`wp_kses()` rules) | `wp_kses_allowed_html( 'post' )` |
| `guarded_mcp_term_taxonomies` | Taxonomies the term abilities may touch | `category`, `post_tag` |
| `guarded_mcp_menu_locations` | Menu locations, their depth and a note on how the theme renders them | every classic location the theme registers, depth 2 |
| `guarded_mcp_media_require_large` | Reject images too small for the `large` size | `true` |
| `guarded_mcp_server_abilities` | Abilities on the custom server | all abilities of the plugin |

### Other changes

| Goal | Where |
|---|---|
| Change the bot's capabilities | `guarded_mcp_bot_caps()` in `access.php`, **and bump `$version`** so the role re-syncs. The sync also removes capabilities that left the list. |
| Use another bot login | `define( 'GUARDED_MCP_BOT_LOGIN', '…' );` in `wp-config.php` |
| Rotate the token | `wp option delete guarded_mcp_token` (a new one is generated) |
| Change the endpoint path | the 2nd and 3rd arguments of `create_server()` in `server.php` |

## Rejected options

1. **OAuth or an application password for the connector.** The Claude connector supports only OAuth or no authentication. The adapter's default server needs a logged-in user (Basic auth with an application password), which the connector cannot send, and an OAuth flow fails because the site has no authorisation server. Hence the custom server with a token in the URL.
2. **Giving the bot `unfiltered_html`.** That would let every write carry any HTML. Instead, one write path bypasses full-content KSES after the allowlist check and filters the HTML inside blocks itself (rule 3).
3. **Browser automation** (Chrome DevTools MCP, Claude in Chrome). Slow and fragile; at most a visual check for a person.
4. **WordPress AI connectors.** They work in the opposite direction, from the site to an external model with an API key. Here an agent needs to reach the site.
5. **Delete abilities.** Only get, create (as draft) and update. Edits are undone with WordPress revisions.
6. **How an image file reaches the site.** Chosen: a single-use link plus `curl`. Rejected:
   - base64 in a tool argument: the model writes the arguments, a 1 MB cover is 1.3 million characters, more than a model outputs in one call, and one wrong character corrupts the file;
   - a path on disk: works only while the site and the files share a machine, and gives the model read access to arbitrary files;
   - a URL the site downloads: the images are on the agent's disk, not online, and a server that downloads any address is an SSRF surface;
   - the core REST `/wp/v2/media` route with an application password: a second long-lived password with full media rights, and the Polylang language cannot be set through it;
   - a custom route with the main MCP token: the token would appear in the `curl` command and so in the client's session logs. A used single-use link there is harmless.
7. **An image smaller than `large` is rejected, not uploaded with a fallback to `full`.** Block markup references `large`. Small images (avatars, icons) are uploaded by a person, or the check is turned off with `guarded_mcp_media_require_large`.
8. **Creating menus and assigning them to locations.** It happens once when a site is set up, by a person in Appearance → Menus. MCP edits only menus already assigned.
9. **The core REST `/wp/v2/menu-items` route.** It needs `edit_theme_options`, does not check the language of a link target, and has neither a dry run nor a backup.
10. **Polylang translations of attachments (`translation_of`).** Articles in different languages do not share images, so the same file in another language is treated as a mistake (409), not as a translation.
11. **Bundling the MCP Adapter as a Composer library.** Since adapter 0.7.0 bundling is deprecated in favour of the canonical MCP Adapter plugin, and two copies on one site log `_doing_it_wrong()`. The plugin declares `Requires Plugins: mcp-adapter` instead.

## Checklist after a change

- [ ] `php -l` on every changed file, or `site-health` returns `all_ok: true`.
- [ ] Every new write path goes `guarded_mcp_validate_blocks()` → `guarded_mcp_save_post_raw()` (rules 2 and 3).
- [ ] A new ability has a `permission_callback` with a specific capability (rule 1).
- [ ] No new way to publish, delete or store raw HTML without an explicit, documented gate.
- [ ] Menu writes go only through `guarded_mcp_menu_write()` after the model checks, with a backup first; `update-menu` stays a dry run by default (rule 8).
- [ ] Files reach the site only through `media-upload.php` → `guarded_mcp_media_check_file()`; the type list has no SVG and nothing executable (rule 7).
- [ ] `$version` in `access.php` is bumped if `guarded_mcp_bot_caps()` changed.
- [ ] The site loads without a fatal error and `site-health` lists 15 abilities.
- [ ] `npm run test:php`, `composer lint` and `composer analyse` pass. A changed rule has a test that fails when the rule is broken.
