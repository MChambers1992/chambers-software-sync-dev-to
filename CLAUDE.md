# CLAUDE.md — Cross Post for Dev.to

AI implementation guide for the `cross-post-devto` WordPress plugin.
Keep this file updated as the architecture evolves.

---

## Project overview

A zero-dependency WordPress plugin that cross-posts content to Dev.to via the Dev.to REST API. Posts are synced on publish and on subsequent edits. Each WordPress post stores the linked Dev.to article ID in post meta so updates go to the correct article rather than creating duplicates.

---

## Architecture

```
cross-post-devto/
├── cross-post-devto.php            # Entry point: constants, require_once, hooks
├── uninstall.php                   # Opt-in data cleanup on plugin deletion
├── includes/
│   ├── class-devto-api.php         # HTTP wrapper — all Dev.to API calls live here
│   ├── class-publisher.php         # Sync logic + HTML→Markdown conversion
│   ├── class-settings.php          # Admin settings page (Settings → Cross Post for Dev.to)
│   ├── class-metabox.php           # Editor sidebar panel + Sync Now AJAX
│   └── class-bulk-sync.php         # Tools → Dev.to Bulk Sync page + batched AJAX
├── assets/
│   ├── admin.css                   # Styles for settings page, metabox, and bulk sync
│   ├── admin.js                    # Settings page JS (test key, tag mapping UI)
│   ├── metabox.js                  # Sync Now AJAX handler
│   └── bulk-sync.js                # Bulk sync page: post list, selection, progress UI
├── .github/workflows/               # CI: tests.yml, lint.yml, deploy.yml, wp-version-check.yml
├── .distignore                      # Files excluded from the release zip / SVN trunk
└── tests/
    ├── bootstrap.php               # PHPUnit bootstrap — loads WP test suite
    ├── unit/
    │   ├── test-html-to-markdown.php   # Pure conversion logic (no WP needed)
    │   ├── test-tag-normalisation.php  # Tag normalise + resolve logic
    │   ├── test-settings.php           # get() defaults + sanitize_organization_id()
    │   └── test-devto-api.php          # API class with mocked wp_remote_request
    └── integration/
        ├── test-publisher.php          # Publisher sync logic against WP test DB
        ├── test-metabox.php            # Metabox::save() persistence against WP test DB
        ├── test-uninstall.php          # uninstall.php against WP test DB
        └── test-bulk-sync.php          # Bulk sync logic against WP test DB
```

### Class responsibilities

| Class | Responsibility |
|---|---|
| `Cross_Post_DevTo_API` | All HTTP to `https://dev.to/api/`. Returns `array\|WP_Error`. Knows nothing about WP posts. |
| `Cross_Post_DevTo_Publisher` | Decides *when* to sync, builds the payload, calls the API, writes post meta. Contains `html_to_markdown()`. |
| `Cross_Post_DevTo_Settings` | Reads/writes `cross_post_devto_settings` option. Renders admin page. Validates API key via AJAX. |
| `Cross_Post_DevTo_Metabox` | Registers the editor sidebar box. Saves the per-post cross-post decision. Handles `cross_post_devto_sync_now` AJAX. |
| `Cross_Post_DevTo_Bulk_Sync` | Tools → Dev.to Bulk Sync page. Fetches post lists via AJAX. Processes posts in batches of 10 via a second AJAX handler. Calls `Cross_Post_DevTo_Publisher::maybe_sync()` internally. |

### Data flow

```
WP publish event (automatic)
  → Cross_Post_DevTo_Publisher::on_status_transition()
    → maybe_sync( $post )              # force=false: respects toggles + reentrancy guard
      → build_payload()
      → Cross_Post_DevTo_API::create_article() or update_article()

Manual "Sync Now" / Bulk Sync
  → maybe_sync( $post, true )          # force=true: bypasses the per-post
                                       # cross-post decision (without mutating it)
```

**Reentrancy guard:** `maybe_sync()` keeps a static per-request map of post IDs
already auto-synced. WordPress fires `transition_post_status` AND `post_updated`
during a single publishing save, which would otherwise double-sync. The guard
only applies to automatic syncs (`force = false`); manual/bulk calls always run.

**Cross-post decision (`Cross_Post_DevTo_Publisher::get_cross_post_state()`):** single
source of truth used by both the metabox toggle's default state and `maybe_sync()`'s
automatic-sync gate, so the UI and the sync logic can never disagree:
1. No API key configured → always `false`, regardless of any stored per-post choice.
2. An explicit per-post choice has been saved (`META_CROSS_POST` is `'1'` or `'0'`) → use it.
3. Nothing saved yet for this post → inherit the global `auto_publish` setting.

`force = true` (manual Sync Now / Bulk Sync) bypasses this decision entirely, but
the API key requirement still applies further down in `maybe_sync()`.

**Per-post sync log:** `Cross_Post_DevTo_Publisher::log()` appends to a capped
(20-entry) option `devto_log_{$post_id}`. Each entry is
`[ 'time', 'level', 'message', 'url' ]` — `url` is the Dev.to article URL on a
successful create/update (empty otherwise, and absent on entries logged before
this field existed) so the metabox can render the entry as a link straight to
the article instead of a bare "ID: 4041463" string.

**Metabox AJAX refresh pattern:** `Cross_Post_DevTo_Metabox::render()` is split into
a thin wrapper (nonce field) and `render_panel( $post )` (the actual
`.devto-mb-wrap` markup). `ajax_sync_now()` re-invokes `render_panel()` via
output buffering and returns the HTML in the AJAX response; `metabox.js`
replaces `.devto-mb-wrap` with it. This keeps the status banner, Dev.to link,
"last synced" time, and log entry list always in sync with what was actually
saved — there is no separate hand-maintained JS template that could drift from
the PHP one. Apply the same pattern to any future metabox state that needs to
update after an AJAX action.

---

## Key constants and post meta keys

```php
// Defined in cross-post-devto.php
CROSS_POST_DEVTO_VERSION   // '1.0.0'
CROSS_POST_DEVTO_PATH      // plugin_dir_path()
CROSS_POST_DEVTO_URL       // plugin_dir_url()
CROSS_POST_DEVTO_BASENAME  // plugin_basename() — used for the Plugins-list "Settings" link

// Defined in Cross_Post_DevTo_Publisher
META_DEVTO_ID     = '_devto_article_id'   // int: Dev.to article ID
META_DEVTO_URL    = '_devto_article_url'  // string: full Dev.to article URL
META_DEVTO_SYNCED = '_devto_last_synced'  // string: MySQL datetime of last sync
META_CROSS_POST   = '_devto_cross_post'   // '1' | '0' | '' : per-post opt-in/opt-out;
                                           // '' (unset) means "inherit auto_publish"
META_TITLE        = '_devto_title_override'      // string: Dev.to-specific title;
                                                   // '' (unset) means "use the WP post title"
META_MAIN_IMAGE   = '_devto_main_image_override'  // string: Dev.to-specific cover image URL;
                                                   // '' (unset) means "use the WP featured image"
META_ORG_ID       = '_devto_organization_id'      // string (numeric): per-post Dev.to
                                                   // Organization ID override; '' (unset)
                                                   // means "use the global default, if any"
META_SKIP         = '_devto_skip'         // deprecated; read-only legacy fallback —
                                           // a stored '1' is treated as META_CROSS_POST '0'
```

### Settings option structure

Stored under `cross_post_devto_settings` (single option, array):

```php
[
    'api_key'          => string,   // Dev.to API key
    'auto_publish'     => bool,     // Global on/off
    'default_status'   => 'published'|'draft',
    'post_types'       => string[], // e.g. ['post', 'page']
    'tag_mappings'     => [ 'WP Term' => 'devtotag' ],
    'exclude_cats'     => int[],    // category term IDs to skip
    'organization_id'  => string,   // numeric string; '' means publish personally.
                                     // Default Dev.to Organization ID; per-post
                                     // META_ORG_ID overrides this when set.
    'unpublish_on_trash'       => bool, // default false. See Publisher::on_trash().
    'delete_data_on_uninstall' => bool, // default false. See uninstall.php.
]
```

**Organization / title / image resolution:** all three follow the same
per-post-override-then-fallback pattern, resolved in `Cross_Post_DevTo_Publisher::build_payload()`
via `resolve_title()`, `resolve_main_image()`, and `resolve_organization_id()`. Non-numeric
organization IDs (per-post or global) are silently ignored rather than sent to the API, since
Dev.to rejects non-integer `organization_id` values.

---

## Dev.to API facts

- Base URL: `https://dev.to/api/`
- Auth: `api-key` request header (not Bearer)
- Create article: `POST /articles` — body `{ "article": { ... } }`
- Update article: `PUT /articles/{id}` — same body shape
- Success: HTTP 2xx, returns article object with `id` and `url`
- Error: HTTP 4xx/5xx, body has `error` key with message string
- Rate limit: HTTP 429, `Retry-After` response header (seconds). Handled distinctly — see below.
- No delete endpoint. "Deletion sync" (`on_trash()`) unpublishes via `PUT` (`published: false`), it cannot remove an article.
- Tags: array of strings, max 4, lowercase alphanumeric only
- `canonical_url`: set to the WordPress permalink — always
- `organization_id`: optional int on the payload; omit the key entirely to publish personally (sending `0` or `null` is not the same as omitting it)

**Rate limiting:** `Cross_Post_DevTo_API::request()` returns a distinct
`devto_rate_limited` WP_Error (not the generic `devto_api_error`) for HTTP 429,
with `Retry-After` (if sent) in both the error message and
`get_error_data()['retry_after']`. There is no automatic retry — sync calls run
synchronously inside a WordPress publish/save request, so blocking on a
rate-limit backoff would stall that request rather than help it. The next
edit or manual "Sync Now" is the retry mechanism.

---

## HTML → Markdown converter

Lives in `Cross_Post_DevTo_Publisher::html_to_markdown()` (private static).
It is intentionally zero-dependency — no Composer library.

**Handles:** h1–h6, `<strong>`/`<b>`, `<em>`/`<i>`, `<a>`, `<img>`, `<code>` (inline), `<pre><code>` (fenced with language detection), `<blockquote>`, `<ul>/<li>`, `<ol>/<li>`, `<table>` (→ GFM pipe tables), `<p>`, `<div>`, `<br>`, `<hr>`.

**Tables:** converted as a block-level pass (step 3, after inline conversions —
same reasoning as lists/blockquotes: cell content already carries Markdown
syntax by the time cells are `strip_tags()`'d). Tables without `<thead>`/`<th>`
use their first `<tr>` as the header, since GFM requires one. Pipe characters
inside cell text are escaped (`\|`) so they don't break the table structure.

**Ordering contract (do not break):**
1. `<pre><code>` blocks are extracted to placeholders FIRST — their content is
   protected from every later pass and restored at the end.
2. Inline conversions (img, bold, italic, inline code, links) run BEFORE block
   conversions, so that formatting inside `<li>` and `<blockquote>` survives
   the `strip_tags()` those handlers apply.
3. Block conversions (headings, blockquote, lists, hr, p/div/br) run last.

**Does not handle:** nested lists, definition lists, `<figure>`, `<details>`. Add these to `html_to_markdown()` if needed — the pattern is a `preg_replace_callback`.

**Language detection** for fenced code blocks reads `class="language-{lang}"` on the `<code>` element (standard Prism/highlight.js convention).

---

## Testing

### Stack

- **PHPUnit 9** (compatible with WP test suite)
- **yoast/phpunit-polyfills** — required by WP core's test suite bootstrap itself (not optional). `tests/bootstrap.php` points WP core at it via the `WP_TESTS_PHPUNIT_POLYFILLS_PATH` constant before requiring `$wp_tests_dir/includes/bootstrap.php`; without it WP core's bootstrap fails outright with "PHPUnit Polyfills library is a requirement". Do not `define( 'ABSPATH', ... )` yourself in the integration branch — WP core's bootstrap does that via the `wp-tests-config.php` that `bin/install-wp-tests.sh` generates, and predefining it a second time throws a "Constant ABSPATH already defined" notice.
- **WordPress test suite** (loaded via `tests/bootstrap.php`)
- Unit tests in `tests/unit/` — cover pure logic (Markdown conversion, tag normalisation, API class with mocked HTTP)
- Integration tests in `tests/integration/` — cover Publisher sync flow against a real WP test DB

### Running tests

```bash
# Install WP test suite (first time only)
bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

# Run all tests
composer test

# Run only unit tests
composer test:unit

# Run only integration tests
composer test:integration

# With coverage (requires Xdebug or PCOV)
composer test:coverage
```

### Mocking WP HTTP in unit tests

`Cross_Post_DevTo_API` calls `wp_remote_request()`. In unit tests we use the
`pre_http_request` filter to intercept and return fake responses:

```php
add_filter( 'pre_http_request', function( $pre, $args, $url ) {
    return [
        'response' => [ 'code' => 201, 'message' => 'Created' ],
        'body'     => json_encode( [ 'id' => 99999, 'url' => 'https://dev.to/test/article' ] ),
        'headers'  => [],
        'cookies'  => [],
        'filename' => null,
    ];
}, 10, 3 );
```

Always remove the filter after the assertion to avoid test pollution.

---

## Continuous integration

Four GitHub Actions workflows live in `.github/workflows/`:

| Workflow | Trigger | Does |
|---|---|---|
| `tests.yml` | push/PR to `main` | Unit tests across PHP 7.4–8.3; integration tests (real WP test suite via `bin/install-wp-tests.sh`) across a PHP × WP version matrix, always including `latest` plus the `Requires at least` floor from readme.txt |
| `lint.yml` | push/PR to `main` | `php -l` syntax check across PHP 7.4–8.3, PHPCS against the `WordPress` ruleset (`phpcs.xml.dist`), PHPStan level 5 with WordPress stubs (`phpstan.neon.dist`) |
| `deploy.yml` | push of tag `vX.Y.Z` | Verifies the tag matches the plugin header's `Version:` (fails loudly on a forgotten bump), builds a clean zip via `.distignore`, attaches it to a GitHub Release, and — only if the `WPORG_DEPLOY_ENABLED` repo variable is `true` and `SVN_USERNAME`/`SVN_PASSWORD` repo secrets are set — pushes to the WP.org plugin SVN repo via `10up/action-wordpress-plugin-deploy`. Skips the SVN job cleanly (not a failure) until those are configured. |
| `wp-version-check.yml` | weekly cron (Mon 09:00 UTC) + manual dispatch | Compares readme.txt's `Tested up to` against the latest WordPress release via the `api.wordpress.org/core/version-check` endpoint; opens a PR bumping the header if it's behind. **The PR itself is unverified until `tests.yml` runs on it and goes green** — the version-check workflow only detects drift, it doesn't prove compatibility. Needs `contents: write` + `pull-requests: write` permissions (already set in the workflow) since many repos now default `GITHUB_TOKEN` to read-only. |

When bumping the plugin's minimum-supported WordPress version (readme.txt's
`Requires at least`), update the pinned floor entry in `tests.yml`'s
integration matrix (the `include:` entry) to match — otherwise CI keeps
testing a floor the plugin no longer claims to support.

Any new top-level file that shouldn't ship in the WP.org/GitHub Release zip
(dev tooling, docs, CI config) needs an entry in `.distignore`, or `deploy.yml`
will happily package it.

**WP.org listing assets vs. plugin assets — do not conflate these:** the
plugin's own `/assets/` directory is runtime CSS/JS and ships in every
release. WP.org's *listing* images (banner, icon, screenshot-N.png) are a
completely separate concept — they live in the SVN repo's top-level `assets/`
directory, a sibling of `trunk/`, and are never part of the installed plugin.
When these get added, put them in `.wordpress-org/` at the repo root (already
excluded in `.distignore`) and point `10up/action-wordpress-plugin-deploy`'s
`ASSETS_DIR` env var at it in `deploy.yml` — don't put marketing images in
`/assets/`, they'd ship inside the plugin zip by mistake.

---

## Adding new features — checklist

- [ ] New API endpoint → add method to `Cross_Post_DevTo_API`, add unit test with mock
- [ ] New setting → add to `Cross_Post_DevTo_Settings::get()` defaults, add field to `render_page()`, sanitise in `handle_save()`
- [ ] New post meta key → define as a `const` in the relevant class, and add it to `uninstall.php`'s `$meta_keys` list
- [ ] New sync trigger → add action hook in `Cross_Post_DevTo_Publisher::init()`
- [ ] Any change to `html_to_markdown()` → add or update a test case in `tests/unit/test-html-to-markdown.php`

---

## Known limitations / future work

- **Nested lists**: stripped to flat text. Acceptable for most content.
- **Image hosting**: images reference your WordPress CDN URL. Works fine as long as WP images are publicly accessible.
- **Deletion sync is unpublish-only, and opt-in**: trashing a WP post does NOT touch the Dev.to article unless "Unpublish on Trash" is enabled in settings — and even then, Dev.to has no delete API, so it can only be set back to a draft, never removed. See `Cross_Post_DevTo_Publisher::on_trash()`.
- **Series support**: Dev.to supports series (like a multi-part sequence). Could be added as a custom field or mapped from a WP taxonomy.
- **Retry on failure**: failed syncs (including rate-limited ones) are logged but not retried. A WP-Cron retry queue would improve reliability on network errors and let rate-limited syncs actually recover automatically.
