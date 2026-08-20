=== Chambers Software Sync for Dev.to ===
Contributors: mchambers92
Tags: dev.to, cross-post, syndication, markdown, publishing
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically syncs your WordPress content to Dev.to when you publish or update a post, with canonical URL support and tag mapping.

== Description ==

Chambers Software Sync for Dev.to keeps your Dev.to articles in sync with your WordPress site. Publish a post on WordPress and it's automatically created on Dev.to; edit it later and the Dev.to article updates to match. No manual copy-pasting, no Markdown wrangling.

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

This plugin is an independent project by Chambers Software. It is not affiliated with, endorsed by, or sponsored by DEV Community, Forem, or dev.to. "Dev.to" and "DEV" are used only to identify the third-party service this plugin connects to.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/chambers-software-sync-dev-to`, or install it directly through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to **Settings → Sync for Dev.to** and enter your Dev.to API key.
4. Click **Test Connection** to verify, then **Save Settings**.

Get your Dev.to API key from your Dev.to account under **Settings → Extensions → DEV API Keys**.

== External services ==

This plugin connects to the Dev.to API (DEV Community, operated by Forem) at https://dev.to/api/. Dev.to is a third-party publishing platform, and this connection is the plugin's core purpose: it is how your WordPress content is published to and kept in sync with your Dev.to account.

No data is sent anywhere until you enter a Dev.to API key on the plugin's settings screen. With no key configured, the plugin makes no external requests at all.

**What is sent, and when:**

* **When you click "Test Connection" on the settings screen** — your Dev.to API key only, to confirm it belongs to a real Dev.to account.
* **When you publish a post, or edit an already-synced post** (if cross-posting is enabled for that post) — the post's title, its content converted to Markdown, a short description (its excerpt, or the first 30 words of its content if it has no excerpt), its tags, its canonical URL (your WordPress permalink), its cover image URL, its publish/draft status, and the Dev.to Organization ID if you have set one. Your Dev.to API key is sent with the request to authenticate it.
* **When you click "Sync Now" in the editor sidebar, or run the Bulk Sync tool** — the same post data as above, for each post you sync.
* **When you trash a synced post** and the optional "Unpublish on Trash" setting is enabled — a request to set that Dev.to article back to draft.

Nothing else is transmitted. No visitor data, site analytics, or personal information about your site's users is ever sent.

This service is provided by DEV Community. By using this plugin you are subject to their terms and policies:

* Terms of Use: https://dev.to/terms
* Privacy Policy: https://dev.to/privacy

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

= 1.0.1 =
* Renamed the plugin to "Chambers Software Sync for Dev.to" and updated the text domain to match the new slug.
* Documented the plugin's use of the Dev.to API as an external service, including what data is sent and when.
* Added an explicit note that this plugin is not affiliated with DEV Community, Forem, or dev.to.

= 1.0.0 =
* Initial public release: auto-publish and edit sync, Bulk Sync tool, per-post cross-post toggle, Dev.to Organization publishing, per-post title/cover image overrides, GFM table conversion, optional unpublish-on-trash, optional delete-data-on-uninstall, and Dev.to API rate-limit handling.

== Upgrade Notice ==

= 1.0.1 =
Plugin renamed and external service usage documented.

= 1.0.0 =
Initial public release.
