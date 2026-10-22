---
name: most-important-details-for-remotearticles
description: Project knowledge for the WordPress plugin KornSW RemoteArticles (WP-RemoteArticles) - embedding posts of a category from a foreign WordPress site via its official REST API using a shortcode, keeping embedded shortcodes and galleries working, link handling rules for links pointing to the source site, and styling. Load for ANY work in this repository: requirements, epics, architecture decisions, implementation, tests.
metadata:
  version: "2026-10-22"
---

# KornSW RemoteArticles - Project Skill

## Status

MVP implemented 2026-10-22: source admin page, shortcode `[remote_articles alias category count order display image links]`, pipeline stage 1 (sanitizing without foreign scripts, own lightbox). Structure: `doc/architecture.md`; next steps: `doc/work/backlog.md`. Nothing from an epic is implemented without the user's explicit commitment (see `smart-solution-and-project-structure`).

Implementation notes:

- Minimum versions (checked 2026-10-22): PHP 8.5, WordPress 7.1. PHP >= 8.4 is required anyway for `\Dom\HTMLDocument` (HTML5 parser) used by the sanitizer; never replace it by `DOMDocument`/libxml (HTML4 parser, mutation-XSS prone) or regex.
- Namespace `KornSW\RemoteArticles\Embedding` must stay free of WordPress calls so `tests/run-tests.php` can test it standalone. Run tests after every change of the pipeline: `node tests/php-wasm/run.mjs tests/run-tests.php` (no native PHP on the dev machine; `cd tests/php-wasm && npm install` once).
- End-to-end check with real WordPress: `@wp-playground/cli run-blueprint --php=8.5 --wp=7.1` with `--mount-dir <plugin dir> /wordpress/wp-content/plugins/kornsw-remotearticles` (in Git Bash set `MSYS_NO_PATHCONV=1`), runPHP steps calling `do_shortcode()`; `https://wordpress.org/news` (category `releases`) works as public read-only test source.
- Sodium is missing in the php-wasm CLI build but always available in WordPress (bundled `sodium_compat`).

## Fixed Decisions

- Project language: **German** for plugin description, all UI texts, `README.md` and everything in `/doc` (user decision 2026-10-22). This skill and all other AI skills stay English; code identifiers and code comments are English.
- Plugin conventions: `wordpress-plugin-development-guidelines` (AI-SKILLS-SMART) applies in full - this is a new plugin.
- Identity: repository `WP-RemoteArticles`, slug / plugin folder / text domain `kornsw-remotearticles`, entry file `kornsw-remotearticles/kornsw-remotearticles.php`, namespace `KornSW\RemoteArticles`, short prefix `kornsw_ra_`, constants `KORNSW_RA_*`, REST namespace (if ever needed) `kornsw-remotearticles/v1`.
- License: GPL v3 (`LICENSE`).
- Sources (user decision 2026-10-22): an admin page manages several source WordPress installations, each with an alias, base URL and optional credentials; the shortcode references installation alias + category. URLs and credentials never appear in post content.
- Release: `.kvu/` + `.github/workflows/release.yml` are unchanged copies of the template `WP-AutoUpdate-G2`; never edit them or the generated files (see the WordPress skill, section "Never Edit by Hand").

## Technical Findings (verified 2026-10-22)

Central concept: the embedding pipeline (`doc/epics/epic-einbettungs-pipeline.md`, decided in `doc/decisions/decision-einbettung-ueber-umschreiben-und-vertrauensstufen.md`). **`doc/security.md` is binding for every implementation step**: safe-by-default trust levels per source, allowlist sanitizing with a real HTML parser (never `wp_kses_post`, never regex), the narrow relay route (no cookies, never stored credentials, path allowlist, `CSP: sandbox` on responses), no foreign scripts for users with `edit_posts`. Any weakening requires updating `security.md` first.

Findings behind the concept:

- The WordPress REST API (`/wp-json/wp/v2/posts`) returns `content.rendered`: the post content after the source site's `the_content` filters, i.e. **shortcodes and blocks are already executed on the source site**. `content.raw` (unexecuted shortcodes) is only available with `context=edit`, which requires an authenticated user with edit rights on the source.
- The user's own gallery plugin `WP-AutoGallery` (shortcode `[tk_auto_gallery gallery="..."]`) reads the image files via `scandir()` from the **source server's file system** and emits its CSS (`<style>`) and lightbox JavaScript (`<script>`) **inline** into the rendered content. Consequences:
  - executing such shortcodes locally (raw content + `do_shortcode`) cannot work, because the files only exist on the source server;
  - the rendered HTML is self-contained, but only if inline `<style>` / `<script>` survive. Standard sanitizing (`wp_kses_post`) removes `<script>`, which breaks the lightbox.
- AutoGallery does not need its script to display: every tile is a plain `<a class="tkag-tile" data-type="image|video" href="<full-size URL>">` with the layout in `style` attributes; the inline script only drives the lightbox and loads nothing. `wp_kses_post` would damage those `style` attributes, so sanitizing must be a dedicated step (remove scripts, `on*` attributes, `javascript:` URLs; keep layout).
- Content rendered via the REST API is rendered for an anonymous request: member-only or role-dependent shortcode output (e.g. protected content of membership plugins) appears as seen by a visitor who is not logged in.
- Other shortcode plugins may enqueue their assets via `wp_enqueue_*` instead of inline output; those assets are missing in `content.rendered` and must be provided otherwise (see the epic).

## Open Questions

Tracked in the epics (section "Offene Fragen"); answers become requirements or `doc/decisions/decision-<title>.md`.
