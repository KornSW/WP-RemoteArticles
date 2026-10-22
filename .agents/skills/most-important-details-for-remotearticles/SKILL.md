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

- Minimum versions: WordPress 7.1, PHP 8.4 (user decision 2026-10-22: one below the newest stable 8.5, deliberate deviation from the "newest PHP" rule). PHP >= 8.4 is required anyway for `\Dom\HTMLDocument` (HTML5 parser) used by the sanitizer; never replace it by `DOMDocument`/libxml (HTML4 parser, mutation-XSS prone) or regex.
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

## Import ("capsules"), implemented 2026-10-22

- Decisions and rationale: `doc/epics/epic-import-als-lokale-beitraege.md`; security: `doc/security.md` section 7; structure: `doc/architecture.md`.
- Invariants that must never be broken: raw foreign HTML only in meta `_kornsw_ra_raw_content` and only output through the pipeline; `post_content` = marker + sanitized fallback with `&#91;`/`&#93;`; content replaced via placeholder at `the_content` priority `PHP_INT_MIN` (before every other content filter: shortcodes, Markdown plugins that escape HTML - seen on steinewunder.de) and rendered at `PHP_INT_MAX` (foreign text never reaches `do_shortcode`); titles, excerpts, author names stored/output escaped; capsules and image capsules locked via `map_meta_cap`; deletion of capsules only after a complete remote listing.
- Sync limits: 300 details per run, batches of 20, 15 s time budget, follow-up single cron event after 60 s while posts are pending (status `pending` in `kornsw_ra_sync_status`).
- Verified in WordPress Playground (WP 7.1.3, PHP 8.5) against wordpress.org/news (484 posts): mixing in category archive, local tag, thumbnails with srcset from source sizes, pipeline output, canonical, original author, edit lock, idempotent re-run, removal, shortcode/title/author injection attempts. Playground runs are slow (about 25 s per 50 posts) and can hang; always wrap them in `timeout`. Not verified there: admin page with the new import fields.

## Gallery adapter: Share-one-Drive (implemented 2026-10-22)

- Real case: koernchen.net posts embed OneDrive galleries of "Share-one-Drive" (WP Cloud Plugins 3.10.x); markup `.wpcp-module.ShareoneDrive[data-list=gallery]` with data-token/account/drive/path; images only via AJAX `shareonedrive-get-gallery` (POST admin-ajax.php, `_ajax_nonce` = `gallery_nonce` from `ShareoneDrive_vars` on the original page; **requires a Referer of the source page**, otherwise empty response).
- Media URLs on `*.svc.ms` / `my.microsoftpersonalcontent.com` carry Microsoft tokens expiring after about 4 h: cache galleries 1 h only, never store them in capsules. Video streams (`action=shareonedrive-stream` on the source) are blocked for foreign referers but redirect without referer: resolved server-side to the Microsoft URL.
- Design: placeholder with HMAC-signed URL of `/wp-json/kornsw-remotearticles/v1/gallery`, filled by `assets/gallery.js` (DOM only); see `doc/architecture.md` and `doc/security.md` section 8. New adapters for other script-loaded galleries should follow the same pattern (data only, host allow list, signed endpoint, own rendering).
- Not verified against WordPress yet (only standalone tests and manual requests against koernchen.net): REST route, nonce reading and rendering on steinewunder.de.
- Debugging 2026-10-22 (502 on steinewunder.de): the gallery AJAX of koernchen.net took 8 to >20 s (Share-one-Drive rebuilding its cache), our 10 s timeout failed. Now: 25 s timeout, endpoint `set_time_limit(60)`, fresh 1 h / stale fallback 3 h, script loads 2 galleries in parallel and retries once.
- NextGEN Gallery (2026-10-22): REST content contains only `ngg_shortcode_N_placeholder` (NextGEN substitutes at the end of page output, never in REST). Placeholders are matched by order to `[data-nextgen-gallery-id]` blocks inside the post content of the original page (verified on koernchen.net "Papa, wir bauen die AIDA": 5 placeholders = 5 galleries in order).
- Capsule template (2026-10-22): general setting `capsuleTemplate` (theme post templates, e.g. "No Sidebar"), applied via `get_post_metadata` filter on `_wp_page_template` for capsules only.
