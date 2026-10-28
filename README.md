# Guarded MCP for WordPress

[![CI](https://github.com/superherono/guarded-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/superherono/guarded-mcp/actions/workflows/ci.yml)

A security-first MCP server for WordPress, built on the Abilities API and the [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin. It gives AI agents 15 tools for pages, posts, terms, media and menus, and keeps a human in charge: content is saved as drafts, nothing can be deleted, raw HTML is rejected, uploads use single-use links, and menu changes are dry runs with automatic backups. The security model is documented as eight invariants in [ARCHITECTURE.md](ARCHITECTURE.md).

**Status:** 0.1.0. The plugin was extracted from a module that runs on a live bilingual (English and Greek) site of my own project, where AI agents prepare pages and articles. Parts that only made sense for that site's theme stayed behind; what was hard-wired there (the theme's block namespace, menu locations, taxonomies) is configurable here. Why and how it was built: [docs/case-study.md](docs/case-study.md).

## What an agent can do

| Tool | What it does |
|---|---|
| `guarded-mcp-get-page` | Read a page by `id` or `slug`: title, block markup, status, Yoast SEO title and description, edit URL. |
| `guarded-mcp-create-page` | Create a page **as a draft** from block markup checked against the allowlist. |
| `guarded-mcp-update-page` | Change the title, content or SEO fields of a page. WordPress keeps a revision. |
| `guarded-mcp-get-post` | Read a post: title, content, status, excerpt, categories, featured image, language, SEO fields. |
| `guarded-mcp-create-post` | Create a post **as a draft**, with existing categories and featured image only. |
| `guarded-mcp-update-post` | Change a post. The status never changes. |
| `guarded-mcp-get-terms` | List terms of an allowed taxonomy, with language and translations under Polylang. |
| `guarded-mcp-create-term` | Create a term. Duplicate names and slugs are rejected. |
| `guarded-mcp-update-term` | Change a term's name, slug, description, parent, language or translation link. |
| `guarded-mcp-prepare-media-upload` | Step 1 of an image upload: check alt, caption, title and language, return a single-use link and a ready `curl` command. |
| `guarded-mcp-get-media` | Read an image: URL of the `large` size for markup, alt, caption, language, dimensions. |
| `guarded-mcp-update-media` | Change an image's alt, caption, title or language. The file is never touched. |
| `guarded-mcp-get-menus` | Read the menus of every theme location (and language): the item tree, how the theme renders the location, the backups. |
| `guarded-mcp-update-menu` | Add, change, move or remove menu items, replace a menu with a tree, or restore a backup. **A dry run unless `dry_run: false`.** |
| `guarded-mcp-site-health` | Diagnostics: PHP lint of the plugin and theme, versions, active integrations, the blocks, taxonomies and menu locations the guard allows. |

There is no tool to publish or delete anything. The only removal is a menu item, and it removes the link, not the page.

## Security model

The agent is treated as a partly trusted client: it acts for the site owner, but the model can be wrong, and text it reads can contain instructions the owner never gave. So the limits hold no matter what the agent sends:

1. Every tool checks a specific capability, and writes re-check it on the target object.
2. Content must consist of allowed blocks: your own block namespaces plus a narrow list of core blocks. Raw HTML outside blocks, `core/html` and `core/shortcode` are rejected.
3. HTML inside allowed blocks is filtered with the same rules WordPress applies to editors without `unfiltered_html`. Full-content KSES is bypassed in one function only, after both checks, so block markup is stored intact.
4. Pages and posts are always created as drafts. The bot role cannot publish or delete.
5. The token is compared with `hash_equals()`; requests run as a low-privilege bot user, and a bot user with administrator-level rights is refused.
6. The diagnostics tool takes no input, so it never reads a path from the client.
7. Images arrive through single-use links stored as hashes. The file type is read from the content, SVG is never accepted, and size and pixel limits stop "image bombs".
8. Menu changes are dry runs by default, every save is backed up first, and a failed save rolls back.

The threat model, the full rules and the 11 rejected design options are in [ARCHITECTURE.md](ARCHITECTURE.md).

## Requirements

- WordPress 6.9 or later (tested on 7.1.3).
- PHP 7.4 or later.
- The [MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) plugin, 0.7 or later (tested with 0.7.1). WordPress will not activate Guarded MCP without it.
- Optional: [Polylang](https://wordpress.org/plugins/polylang/) for languages and translations, [Yoast SEO](https://wordpress.org/plugins/wordpress-seo/) for SEO title and description.
- For menu tools: a theme with classic menu locations. Block themes manage navigation in the Site Editor, and the menu tools then report that no location is available.

## Installation

1. Install and activate the MCP Adapter plugin, then this plugin.
2. Create the bot user. The role is created by the plugin; the user is created by you:
   ```bash
   wp user create mcp-bot mcp-bot@example.com --role=guarded_mcp_bot
   wp user list-caps mcp-bot | grep -E 'unfiltered_html|publish_|delete_'   # expect no output
   ```
   To use another login, define `GUARDED_MCP_BOT_LOGIN` in `wp-config.php`.
3. Allow your own blocks. Out of the box only the trusted core blocks pass the guard. Add the namespace of your theme or block plugin:
   ```php
   add_filter( 'guarded_mcp_block_namespaces', function ( $namespaces ) {
       $namespaces[] = 'acme'; // acme/hero, acme/pricing, …
       return $namespaces;
   } );
   ```
4. Read the token (it is generated on the first load):
   ```bash
   wp option get guarded_mcp_token
   ```
5. Add the server to your MCP client. Leave the OAuth fields empty: the token in the URL is the authentication.
   ```
   https://example.com/wp-json/guarded-mcp/v1/mcp?token=<token>
   ```
6. Ask the agent to run `guarded-mcp-site-health`. Expect `all_ok: true`, `mcp_adapter_active: true` and 15 abilities.

Before you put the site on a public host, make sure the web server does not execute PHP in `wp-content/uploads`. That is a server setting, and it protects every upload, not only these.

### Configuration

All settings are filters; there is no settings screen.

| Filter | Default | Use it to |
|---|---|---|
| `guarded_mcp_block_namespaces` | none | allow your theme's or plugin's blocks |
| `guarded_mcp_trusted_core_blocks` | 22 core blocks | add or remove trusted core blocks |
| `guarded_mcp_allowed_html` | `post` KSES rules | allow extra HTML inside blocks, such as inline SVG |
| `guarded_mcp_term_taxonomies` | `category`, `post_tag` | let the agent manage a custom taxonomy |
| `guarded_mcp_menu_locations` | every classic location, 2 levels | hide a location, set its real depth, describe how it renders |
| `guarded_mcp_media_require_large` | `true` | accept images smaller than the `large` size |

Details and the remaining filters: [ARCHITECTURE.md](ARCHITECTURE.md#filters).

## How image upload works

The arguments of a tool call are written by the model token by token, so image bytes cannot travel inside one: a 1 MB PNG is 1.3 million characters of base64. Upload takes two steps instead:

1. `prepare-media-upload` checks alt, caption, title and language and returns a single-use link (valid 15 minutes, at most 10 open) with a `curl` command.
2. The agent runs the command with the local file path. The response is the new attachment: `id`, `url_large`, `size_slug`.

The link is used up by the first attempt, whatever the outcome. PNG, JPEG and WebP are accepted up to 10 MB (or the server limit), 8000 px per side and 25 megapixels. By default the image must be large enough for WordPress to make the `large` size, because block markup references it. Uploading the same file again in the same language returns the existing attachment. On Windows PowerShell the agent must call `curl.exe`, because plain `curl` there is an alias of `Invoke-WebRequest`.

## Menus

A menu has no drafts or revisions: a saved change is live on every page at once. So `update-menu` validates the whole change on a model of the menu and returns the resulting tree without saving, unless the call passes `dry_run: false`. Every save first copies the menu into a backup (the last 10 per location and language), and `restore_backup` puts any of them back. With Polylang each language has its own menu, addressed by location and language. Creating menus and assigning them to locations stays with a person in Appearance → Menus.

## Limitations

- Classic menus only; no support for the Navigation block of block themes.
- No custom fields on menu items (for example a theme's mega-menu fields).
- Languages through Polylang only; WPML is not supported. SEO fields through Yoast SEO only.
- The token is a long random string stored in the options table. Anyone who reads the database or the client configuration can use it. Rotate it with `wp option delete guarded_mcp_token`.
- No rate limiting on the endpoints; use the web server or a firewall.
- Not tested on multisite.
- After a menu change on a site behind a CDN, the cache has to be purged: the header and footer are on every page.

## How AI is involved in development

I develop this plugin with AI coding agents (Claude Code), and most of the code was written by an agent against a design I set. The trust boundary, the eight invariants, the rejected options and every check before a write are my decisions, written down in ARCHITECTURE.md before or alongside the code. Each change is reviewed against the checklist at the end of that file. The tests in `tests/` were written with the agent and checked by breaking each guarded rule on purpose until a test failed; tests for five of the invariants (permission checks, raw HTML refusal, drafts only, upload file checks, single-use upload links) are being written by hand, without the agent.

## Development

Requirements: Docker, Node.js 20, Composer.

```bash
composer install
npm install
npm run env:start     # WordPress at http://localhost:8888 (admin / password)
npm run test:php      # PHPUnit inside wp-env
npx playwright install chromium
npm run test:e2e      # Playwright: markup saved over MCP opens in the block editor without validation errors
composer lint         # PHPCS: WordPress and WordPress VIP coding standards
composer analyse      # PHPStan, level 5
```

The development site runs the latest WordPress with MCP Adapter and the Twenty Twenty-One theme, which has classic menu locations. The PHPUnit site runs as `production` (`.wp-env.json`), so the refusals that apply only in production are tested too. CI runs all of the above and a PHP 7.4–8.4 syntax check on every push and pull request.

## License

GPL-2.0-or-later, the same as WordPress and the MCP Adapter. See [LICENSE](LICENSE).
