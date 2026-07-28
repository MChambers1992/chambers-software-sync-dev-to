# Cross Post for Dev.to

[![Tests](https://github.com/MChambers1992/cross-post-devto/actions/workflows/tests.yml/badge.svg)](https://github.com/MChambers1992/cross-post-devto/actions/workflows/tests.yml)
[![Lint](https://github.com/MChambers1992/cross-post-devto/actions/workflows/lint.yml/badge.svg)](https://github.com/MChambers1992/cross-post-devto/actions/workflows/lint.yml)

A WordPress plugin that automatically cross-posts your content to [Dev.to](https://dev.to) whenever you publish or update a post — with canonical URL support, tag mapping, per-post controls, and edit sync.

---

## Features

- **Instant publish** — fires on `publish_post`; no cron delay
- **Edit sync** — updates the Dev.to article when you edit a published WP post
- **Canonical URL** — always sets `canonical_url` back to your WordPress permalink (zero SEO impact)
- **Tag mapping** — map WP categories/tags to Dev.to tags (max 4, auto-normalised)
- **Per-post cross-post toggle** — opt individual posts in or out, overriding the global default
- **Manual sync** — "Sync Now" button in the metabox for already-published posts
- **Draft mode** — optionally send posts as Dev.to drafts for review before going live
- **Post type filter** — choose which post types to sync
- **Category exclusions** — block entire categories from syncing
- **Sync log** — last 20 sync events stored per post, visible in the metabox
- **API key validation** — "Test Connection" button on the settings page
- **Organization publishing** — publish under a Dev.to Organization, set globally or overridden per post
- **Per-post title/cover image overrides** — use a different title or cover image on Dev.to than on WordPress
- **GFM table conversion** — `<table>` markup converts to GitHub-Flavored Markdown pipe tables
- **Unpublish on Trash** (opt-in) — trashing a synced post can set its Dev.to article back to a draft
- **Delete Data on Uninstall** (opt-in) — off by default; deleting the plugin normally leaves your settings and sync history intact

---

## Installation

1. Upload the `cross-post-devto` folder to `/wp-content/plugins/`
2. Activate the plugin via **Plugins → Installed Plugins**
3. Go to **Settings → Cross Post for Dev.to**
4. Enter your Dev.to API key (get it from [dev.to/settings/extensions](https://dev.to/settings/extensions))
5. Click **Test Connection** to verify, then **Save Settings**

---

## Getting Your Dev.to API Key

1. Log in to Dev.to
2. Go to **Settings → Extensions**
3. Scroll to **DEV API Keys**
4. Enter a description (e.g. "WordPress") and click **Generate API Key**
5. Copy the key — it's only shown once

---

## Configuration

### Settings Page (Settings → Cross Post for Dev.to)

| Setting | Description |
|---|---|
| **API Key** | Your Dev.to API key |
| **Auto-Publish** | Global on/off toggle |
| **Default Publish Status** | Published immediately or as a draft on Dev.to |
| **Allowed Post Types** | Which post types trigger sync |
| **Exclude Categories** | Posts in these categories are skipped |
| **Tag Mappings** | Map WP term names to specific Dev.to tags |
| **Default Dev.to Organization ID** | Publish articles under a Dev.to organization instead of your personal account. Optional; leave blank to publish personally. |
| **Unpublish on Trash** | Off by default. When on, trashing a synced post sets its Dev.to article back to a draft (Dev.to has no delete API). |
| **Delete Data on Uninstall** | Off by default. When on, deleting the plugin removes its settings, sync logs, and post meta. Never touches Dev.to itself. |

### Per-Post Controls (Editor Sidebar)

- **Cross-post this to Dev.to** — opt-in toggle for this specific post. Defaults to the global **Auto-Publish** setting until you explicitly check or uncheck it for that post; once saved, your choice sticks regardless of the global setting. Disabled (and forced off) whenever no API key is configured.
- **Sync Now** — manually trigger a sync for an already-published post
- **View on Dev.to** — direct link to the article once synced
- **Sync Log** — collapsible log of recent sync events for that post
- **Dev.to overrides (optional)** — collapsible section with three per-post overrides, each falling back to its WordPress equivalent (or the global setting) when left blank:
  - **Dev.to title** — a different title than the WordPress post title
  - **Cover image URL** — a different cover image than the WordPress featured image
  - **Organization ID** — overrides the global default Organization ID for this post only

---

## How Content is Converted

WordPress stores post content as HTML (or block markup). The plugin:

1. Runs the content through `apply_filters('the_content', ...)` to expand blocks and shortcodes
2. Converts the resulting HTML to Markdown using a built-in converter

The converter handles: headings, bold/italic, links, images, code blocks, inline code, blockquotes, ordered/unordered lists, horizontal rules, and paragraphs.

For complex content (custom blocks, shortcodes that render to unusual HTML), review the Dev.to draft before publishing.

---

## Tag Handling

- WordPress tags and categories are both considered as sources
- Tag names are normalised to lowercase alphanumeric (Dev.to requirement)
- Use the Tag Mappings table to override the normalised name with a specific Dev.to tag
- Maximum 4 tags per Dev.to article (Dev.to platform limit)

---

## Sync Behaviour

| WordPress action | Dev.to action |
|---|---|
| Post transitions to `publish` | Create new Dev.to article |
| Published post is edited (title/content/excerpt changes) | Update existing Dev.to article |
| Post is trashed/deleted | No action (Dev.to article remains) |

The Dev.to article ID is stored in post meta (`_devto_article_id`) so updates go to the correct article.

---

## Frequently Asked Questions

**Will this affect my SEO?**
No. The `canonical_url` is always set to your WordPress post URL, so search engines treat your WordPress site as the original source.

**What if I want to review on Dev.to before going live?**
Set **Default Publish Status** to **Draft** in settings. Posts will appear in your Dev.to drafts for manual review.

**Can I prevent a single post from syncing?**
Yes — uncheck **Cross-post this to Dev.to** in the editor sidebar before publishing. Conversely, you can check it on a specific post to cross-post it even when the global **Auto-Publish** setting is off.

**Does it sync pages?**
Only if you add `page` to the **Allowed Post Types** list in settings.

**What happens if the API call fails?**
The error is logged to the per-post sync log (visible in the metabox). The post publishes normally on WordPress regardless.

---

## Requirements

- WordPress 5.8+
- PHP 7.4+
- A Dev.to account with an API key

---

## License

GPL-2.0+
