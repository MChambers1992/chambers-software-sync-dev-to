=== Cross Post for Dev.to ===
Contributors: michaelchambers
Tags: dev.to, cross-post, syndication, markdown, publishing
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically cross-posts your WordPress content to Dev.to when you publish or update a post, with canonical URL support and tag mapping.

== Description ==

Cross Post for Dev.to keeps your Dev.to articles in sync with your WordPress site. Publish a post on WordPress and it's automatically created on Dev.to; edit it later and the Dev.to article updates to match. No manual copy-pasting, no Markdown wrangling.

**Features**

* Instant publish — fires on `publish_post`, no cron delay
* Edit sync — updates the Dev.to article when you edit a published WordPress post
* Canonical URL — always sets `canonical_url` back to your WordPress permalink so your site stays the SEO source of truth
* Tag mapping — map WordPress categories/tags to Dev.to tags (max 4, auto-normalised)
* Per-post cross-post toggle — opt individual posts in or out, overriding the global default
* Manual "Sync Now" button in the editor sidebar for already-published posts
* Bulk Sync tool to retrospectively publish existing posts to Dev.to
* Draft mode — optionally send posts as Dev.to drafts for review before going live
* Post type filter and category exclusions
* Per-post sync log (last 20 events) visible in the editor sidebar
* API key validation via a "Test Connection" button
* Dev.to Organization publishing — set a default organization or override it per post
* Per-post Dev.to title and cover image overrides, independent of the WordPress title and featured image
* GitHub-Flavored Markdown table conversion
* Optional unpublish-on-trash — sets the Dev.to article back to a draft when you trash the WordPress post
* Optional data cleanup on uninstall — leaves your settings and sync history alone by default

Built-in HTML-to-Markdown conversion means no external dependencies — the plugin works out of the box.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/cross-post-devto`, or install it directly through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings → Cross Post for Dev.to** and enter your Dev.to API key.
4. Click **Test Connection** to verify, then **Save Settings**.

Get your Dev.to API key from your Dev.to account under **Settings → Extensions → DEV API Keys**.

== Frequently Asked Questions ==

= Will this affect my SEO? =

No. The `canonical_url` is always set to your WordPress post URL, so search engines treat your WordPress site as the original source.

= Can I review the article on Dev.to before it goes live? =

Yes. Set **Default Publish Status** to **Draft** in the plugin settings. Posts will appear in your Dev.to drafts for manual review.

= Can I prevent a single post from syncing? =

Yes — uncheck **Cross-post this to Dev.to** in the editor sidebar. You can also enable it on a specific post even when the global Auto-Publish setting is off.

= Does it sync pages? =

Only if you add `page` to the Allowed Post Types list in settings.

= What happens if the sync fails? =

The error is logged to the per-post sync log, visible in the editor sidebar. The WordPress post publishes normally regardless.

= What happens to the Dev.to article if I trash the WordPress post? =

By default, nothing — the Dev.to article stays exactly as it is. If you enable **Unpublish on Trash** in settings, trashing a synced post sets its Dev.to article back to a draft instead (Dev.to has no delete API, so this can't remove the article entirely).

= Does uninstalling the plugin delete my data? =

Not by default. Your settings and sync history stay in the database so a reinstall picks up where you left off. Enable **Delete Data on Uninstall** in settings if you want a clean removal instead. Either way, nothing on Dev.to itself is ever touched by uninstalling.

== Screenshots ==

1. Settings page — API connection, publishing behaviour, and tag mappings.
2. Editor sidebar panel — cross-post toggle, sync status, and sync log.
3. Bulk Sync tool — retrospectively sync existing posts.

== Changelog ==

= 1.0.0 =
* Initial public release: auto-publish and edit sync, Bulk Sync tool, per-post cross-post toggle, Dev.to Organization publishing, per-post title/cover image overrides, GFM table conversion, optional unpublish-on-trash, optional delete-data-on-uninstall, and Dev.to API rate-limit handling.

== Upgrade Notice ==

= 1.0.0 =
Initial public release.
