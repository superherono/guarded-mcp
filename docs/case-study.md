# How I gave AI agents write access to WordPress without giving them the keys

*A security-first MCP server on the WordPress Abilities API*

**In short.** I run a bilingual (English and Greek) WordPress site of my own, where AI agents prepare pages and articles. They needed to write to the live site. I designed an MCP server that lets them do that through 15 narrow tools, inside limits that hold even if the model makes a mistake or reads a malicious instruction: drafts only, no deletes, no raw HTML, no file paths or URLs from the client. Claude Code wrote most of the code against that design; the trust boundary and every decision below are mine. This plugin is that server, extracted from the site's theme so that any WordPress site can use it.

## The problem

The site has around 65 custom Gutenberg blocks and content in two languages. Agents (Claude Code locally, a Claude connector in the cloud) research, write and lay out pages in block markup. The last step, getting that markup into WordPress, was manual.

Letting an agent write to a production site raises obvious risks. A model can be wrong, and text it reads can contain instructions that were never mine. Whatever the agent does, it must not be able to:

- publish or delete anything;
- inject raw HTML or scripts;
- read files on the server;
- make the site fetch an arbitrary URL (SSRF);
- break site-wide navigation.

## Why not an existing option

- **Core REST API with an application password.** A long-lived password with full rights to media and content. Polylang language can't be set through it.
- **The default server in the MCP Adapter.** It needs a logged-in user, while the cloud connector only supports OAuth or no auth.
- **Browser automation.** Slow and fragile; at most a visual check for a human.

## The design

```
AI agent (Claude Code, Claude connector)
   │  JSON-RPC 2.0 over HTTPS
   ▼
MCP server (MCP Adapter)        token check with hash_equals()
   │
   ▼
Ability, one file per tool      permission_callback with a specific capability,
   │                            re-checked on the target object
   ▼
Content guard                   recursive allowlist of allowed and trusted core blocks;
   │                            raw HTML outside blocks is rejected,
   │                            HTML inside blocks is filtered with KSES rules
   ▼
Validated write path            saved as a draft; full-content KSES is bypassed
   │                            only here, and only after validation
   ▼
WordPress, as a low-privilege bot user (no publish, no delete, no unfiltered_html)
```

- **Abilities API as the contract.** Each tool is a WordPress ability with an input schema, an output schema and its own permission check, registered from its own file. Adding a tool means adding a file; the loader doesn't change.
- **Two servers, same checks.** The adapter's default server works for local clients. A second, token-protected server exists because the cloud connector can't send custom headers. Both run every ability's permission check and the content guard: the server is a gate, not a replacement for them.
- **A bot with the minimum rights.** A dedicated role that can edit pages, posts, terms and media but has no publishing or delete capabilities, no `unfiltered_html` and no `edit_theme_options`. Menus get their own capability instead of `edit_theme_options`, which would also open the Customizer and widgets. The role is versioned, so a change to its capabilities re-syncs on the next load, and a bot user that was given more rights by mistake is refused.

## The security model: eight invariants

The architecture notes list eight rules that every change must keep. The main ones:

1. Every tool has a `permission_callback` with a specific capability.
2. All saved content passes a recursive block allowlist first.
3. Full-content KSES is bypassed in one function only, after validation, and that function filters the HTML inside each block itself.
4. Pages and posts are always created as drafts. Publishing is a human action. There is no delete tool for posts, terms or images.
5. Images arrive through a two-step upload. The agent asks for a single-use link; only a hash of the link is stored; the link is invalidated atomically before the file is read. The file type is detected from its content, not its name. SVG is never accepted. Size and pixel limits stop "image bombs", small files that claim huge dimensions and would exhaust memory during resizing.
6. Menus have no drafts or revisions, so `update-menu` runs as a dry run by default. A real write first copies the whole menu to a backup (the last ten per location and language), rolls back if it fails halfway, and only links to published content in the menu's language.

## Options I rejected

The design notes keep 11 rejected options with reasons. Four that show the thinking:

- **Base64 images in tool arguments.** The model writes the arguments; a 1 MB cover becomes 1.3 million characters, more than a model can output in one call, and one wrong character corrupts the file.
- **A file path or a URL for the site to download.** A path gives the model read access to the server's files; a URL turns the site into an SSRF tool.
- **Giving the bot `unfiltered_html`.** Instead, one validated write path bypasses full-content KSES after the allowlist check and filters the HTML inside blocks itself.
- **Using the main MCP token for uploads.** It would end up in `curl` commands and therefore in session logs. A single-use link is harmless there.

## Results

- 15 tools: pages, posts, terms, media, menus and a health check.
- The site's content workflow runs through it: agents save pages and articles as drafts, a human reviews and publishes. The site has 14 pages and 22 articles live in two languages (October 2026).
- Every change goes through a checklist in the architecture notes: PHP lint, validated write path, capability check, backup before menu writes, capability version bump.
- Before publishing, the plugin was checked on a clean WordPress 7.1 site against a live MCP client session: all 15 tools, and each invariant from the outside (bad tokens, blocked blocks, scripts inside blocks, SVG and disguised PHP uploads, image bombs, menu link schemes, a bot user with administrator rights).
- CI runs PHPCS with the WordPress and WordPress VIP standards, PHPStan, PHPUnit and Playwright on every push. The tests were checked by breaking each guarded rule on purpose until a test failed. Writing them found two gaps, both fixed: a menu link written as `/`, a tab, `/host` passed the link check and was saved as `//host`; and `site-health` showed absolute server paths when the plugin folder sat outside `wp-content`.

## What I'd do differently

- **Write the invariant tests before the code, not after.** The rules were written down from the start, but nothing enforced them automatically.
- **Start as a standalone plugin.** The server began as a module inside the theme and had to be extracted.
- **Log calls and rejections from day one.** I'd have numbers on how often the guard stopped something.
- **Get an external security review before production.** A brief for an automated review is written; the review hasn't run yet.
- **Move from a token in the URL to OAuth** once the connector supports it. The URL token is a workaround for a client limitation.
