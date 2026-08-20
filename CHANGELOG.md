# 1.0.0 (2026-07-28)

# Changelog

All notable changes to Chambers Software Sync for Dev.to are documented here.
Follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) format.

---

## [Unreleased]

### Planned
- WP-Cron retry queue for failed syncs.
- Dev.to series support via custom field or WP taxonomy.

---

## [1.0.1] — 2026-08-20

### Changed
- **Renamed the plugin** from "Cross Post for Dev.to" to **"Chambers Software Sync for Dev.to"**, with the slug and text domain changing from `cross-post-devto` to `chambers-software-sync-dev-to`, to satisfy the WordPress.org Plugin Review Team's naming and trademark guidelines (a distinctive vendor term now leads the name, with the third-party service named only after "for"). The main plugin file, admin page slugs, and all build/CI slug references were updated to match.
- Internal PHP identifiers (`Cross_Post_DevTo_*` classes, `CROSS_POST_DEVTO_*` constants, the `cross_post_devto_settings` option key, and the `_devto_*` post meta keys) are deliberately **unchanged** — WordPress.org only requires the text domain to match the slug, so renaming them would mean a data migration for no benefit.
- Admin menu label is now **Settings → Sync for Dev.to**.

### Added
- **`== External services ==` section in readme.txt**, documenting the plugin's use of the Dev.to API: what data is sent, on which actions, that nothing is transmitted before an API key is configured, and links to DEV Community's Terms of Use and Privacy Policy.
- Explicit statement that this plugin is not affiliated with, endorsed by, or sponsored by DEV Community, Forem, or dev.to.

### Fixed
- `Contributors` in readme.txt corrected to the plugin owner's WordPress.org username (`mchambers92`).

---

## [1.0.0] — 2026-07-28

Initial public release. Everything below was built and iterated on pre-release
(internal versions 1.1.0–1.4.0 were never published anywhere), so it's
presented here as one release rather than a fabricated upgrade history.

### Added
- Auto-publish to Dev.to when a WordPress post transitions to `publish`; edit sync updates the linked article when a published post's title, content, or excerpt changes.
- `canonical_url` always set to the WordPress permalink to protect SEO.
- **Bulk Sync** page under **Tools → Dev.to Bulk Sync**: lists published posts with current sync status, filterable by post type/sync status/title keyword, batched AJAX processing (10 posts/batch), live progress bar, per-post results, and a "Re-sync" option to force-update already-synced articles.
- **Per-post "Cross-post this to Dev.to" opt-in toggle** in the editor sidebar. Defaults to the global **Auto-Publish** setting until explicitly saved one way or the other for that post; the same resolution (`Cross_Post_DevTo_Publisher::get_cross_post_state()`) drives both the metabox default and the automatic-sync gate, so they can't disagree.
- **Dev.to Organization publishing**: a global default Organization ID setting, with a per-post override. Resolution order is per-post override → global default → personal account; non-numeric values are ignored rather than sent to the API.
- **Per-post "Dev.to title" and "Cover image URL" overrides** — use a different title/cover image on Dev.to than on WordPress, independent of the WP post title and featured image.
- **GitHub-Flavored Markdown table conversion**: `<table>` → GFM pipe tables, including tables without `<thead>`/`<th>` (first row used as header, since GFM requires one).
- **Optional "Unpublish on Trash"** setting (default off): trashing a synced post sets its Dev.to article back to a draft. Dev.to's API has no delete endpoint, so this is an unpublish, never a removal.
- **`uninstall.php`** with an optional "Delete Data on Uninstall" setting (default off): when enabled, removes the settings option, all per-post sync-log options, and all plugin-owned post meta on plugin deletion. Off by default so a normal uninstall/reinstall keeps history intact; never touches anything on Dev.to itself.
- **HTTP 429 (rate limit) handling** in `Cross_Post_DevTo_API`: a distinct `devto_rate_limited` WP_Error instead of the generic `devto_api_error`, surfacing the `Retry-After` header (if present) in both the error message and `get_error_data()['retry_after']`. No automatic retry — this runs synchronously inside a WP publish/save request, so blocking on backoff would be worse than logging it and letting the next edit or manual sync try again.
- Sync log entries (last 20 per post, visible in the editor sidebar) link straight to the Dev.to article on success.
- API key validation via a "Test Connection" button on the settings page.
- Zero external PHP runtime dependencies — built-in HTML-to-Markdown converter handles headings, bold/italic, links, images, code blocks (with language detection), blockquotes, ordered/unordered lists, tables, and horizontal rules.
- PHPUnit test suite: unit tests (Brain\Monkey mocks) and integration tests (real WP test suite via `bin/install-wp-tests.sh`).
- GitHub Actions CI: `tests.yml` (PHP × WP version matrix), `lint.yml` (syntax, PHPCS, PHPStan), `deploy.yml` (tag → GitHub Release zip + optional WP.org SVN push), `wp-version-check.yml` (weekly check that readme.txt's "Tested up to" isn't stale).
