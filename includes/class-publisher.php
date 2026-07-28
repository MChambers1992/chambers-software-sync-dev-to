<?php
defined( 'ABSPATH' ) || exit;

/**
 * Converts WordPress posts to Dev.to payloads and decides when to publish/update.
 */
class Cross_Post_DevTo_Publisher {

    /** Post meta keys */
    const META_DEVTO_ID      = '_devto_article_id';
    const META_DEVTO_URL     = '_devto_article_url';
    const META_DEVTO_SYNCED  = '_devto_last_synced';
    const META_CROSS_POST    = '_devto_cross_post';
    const META_TITLE         = '_devto_title_override';
    const META_MAIN_IMAGE    = '_devto_main_image_override';
    const META_ORG_ID        = '_devto_organization_id';

    /** Legacy per-post opt-out flag. @deprecated Superseded by META_CROSS_POST. Kept read-only for legacy opt-outs. */
    const META_SKIP          = '_devto_skip';

    public static function init() {
        // Hook into the transition from any status → publish.
        add_action( 'transition_post_status', [ __CLASS__, 'on_status_transition' ], 10, 3 );

        // Handle post updates (already-published posts being edited).
        add_action( 'post_updated', [ __CLASS__, 'on_post_updated' ], 10, 3 );

        // Optional: unpublish the Dev.to article when the WP post is trashed.
        add_action( 'wp_trash_post', [ __CLASS__, 'on_trash' ] );
    }

    // -------------------------------------------------------------------------
    // Hooks
    // -------------------------------------------------------------------------

    /**
     * Fires when a post transitions to "publish".
     * Only handles the initial publish event – updates are handled by on_post_updated.
     */
    public static function on_status_transition( string $new_status, string $old_status, WP_Post $post ) {
        if ( $new_status !== 'publish' || $old_status === 'publish' ) {
            return; // Not a new-publish event.
        }
        self::maybe_sync( $post );
    }

    /**
     * Fires after a published post is updated.
     */
    public static function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ) {
        if ( $post_after->post_status !== 'publish' ) {
            return;
        }
        // Only sync updates when the content actually changed.
        if (
            $post_after->post_content === $post_before->post_content &&
            $post_after->post_title === $post_before->post_title &&
            $post_after->post_excerpt === $post_before->post_excerpt
        ) {
            return;
        }
        self::maybe_sync( $post_after );
    }

    /**
     * Fires when a WordPress post is trashed.
     *
     * Opt-in via the "Unpublish on trash" setting (off by default — trashing
     * is often temporary, and silently touching the live Dev.to article on
     * every trash action would surprise more people than it'd help). Dev.to's
     * API has no delete endpoint, so "unpublish" here means flipping the
     * article's `published` flag to false via PUT, not removing it.
     */
    public static function on_trash( int $post_id ) {
        $settings = Cross_Post_DevTo_Settings::get();

        if ( empty( $settings['unpublish_on_trash'] ) || empty( $settings['api_key'] ) ) {
            return;
        }

        $devto_id = (int) get_post_meta( $post_id, self::META_DEVTO_ID, true );
        if ( $devto_id <= 0 ) {
            return;
        }

        $api    = new Cross_Post_DevTo_API( $settings['api_key'] );
        $result = $api->update_article( $devto_id, [ 'published' => false ] );

        if ( is_wp_error( $result ) ) {
            self::log(
                $post_id,
                'error',
                sprintf( 'Failed to unpublish Dev.to article on trash: %s', $result->get_error_message() )
            );
            return;
        }

        self::log(
            $post_id,
            'success',
            'Article unpublished on Dev.to (WordPress post trashed).',
            $result['url'] ?? ''
        );
    }

    // -------------------------------------------------------------------------
    // Core sync logic
    // -------------------------------------------------------------------------

    /**
     * Determine whether to create or update a Dev.to article and execute.
     *
     * @param WP_Post $post
     * @param bool    $force  When true (manual/bulk sync), bypasses the per-post
     *                        cross-post decision (and the global auto_publish
     *                        default it falls back to). Still requires an API
     *                        key. Automatic hooks always pass false.
     */
    public static function maybe_sync( WP_Post $post, bool $force = false ) {
        // Reentrancy guard: on first publish, WP fires transition_post_status
        // AND post_updated in the same request. Without this, a post whose
        // content changed in the publishing save would sync twice (POST + PUT).
        // Only applies to automatic syncs — manual/bulk ($force) always run.
        static $synced_this_request = [];
        if ( ! $force && isset( $synced_this_request[ $post->ID ] ) ) {
            return;
        }
        if ( ! $force ) {
            $synced_this_request[ $post->ID ] = true;
        }

        $settings = Cross_Post_DevTo_Settings::get();

        // Respect the per-post cross-post decision (unless forced) — this already
        // folds in the global auto_publish default and the "no API key" veto.
        if ( ! $force && ! self::get_cross_post_state( $post->ID, $settings ) ) {
            return;
        }

        // Check post type is in the allowed list.
        $allowed_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : [ 'post' ];
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return;
        }

        // Check excluded categories.
        if ( ! empty( $settings['exclude_cats'] ) ) {
            $post_cats = wp_get_post_categories( $post->ID, [ 'fields' => 'ids' ] );
            if ( array_intersect( $post_cats, (array) $settings['exclude_cats'] ) ) {
                return;
            }
        }

        if ( empty( $settings['api_key'] ) ) {
            self::log( $post->ID, 'error', 'No API key configured.' );
            return;
        }

        $api     = new Cross_Post_DevTo_API( $settings['api_key'] );
        $payload = self::build_payload( $post, $settings );
        $devto_id = (int) get_post_meta( $post->ID, self::META_DEVTO_ID, true );

        if ( $devto_id > 0 ) {
            $result = $api->update_article( $devto_id, $payload );
            $action = 'updated';
        } else {
            $result = $api->create_article( $payload );
            $action = 'created';
        }

        if ( is_wp_error( $result ) ) {
            self::log( $post->ID, 'error', $result->get_error_message() );
            return;
        }

        // Persist the Dev.to article ID and URL for future updates.
        if ( ! empty( $result['id'] ) ) {
            update_post_meta( $post->ID, self::META_DEVTO_ID, (int) $result['id'] );
        }
        if ( ! empty( $result['url'] ) ) {
            update_post_meta( $post->ID, self::META_DEVTO_URL, esc_url_raw( $result['url'] ) );
        }
        update_post_meta( $post->ID, self::META_DEVTO_SYNCED, current_time( 'mysql' ) );

        self::log(
            $post->ID,
            'success',
            sprintf( 'Article %s on Dev.to (#%d)', $action, $result['id'] ?? 0 ),
            $result['url'] ?? ''
        );
    }

    // -------------------------------------------------------------------------
    // Payload builder
    // -------------------------------------------------------------------------

    /**
     * Build the Dev.to article payload from a WP_Post.
     *
     * @param WP_Post $post
     * @param array   $settings  Plugin settings.
     * @return array
     */
    public static function build_payload( WP_Post $post, array $settings ): array {
        $content       = self::convert_content( $post );
        $tags          = self::resolve_tags( $post, $settings );
        $canonical_url = get_permalink( $post );
        $description   = self::get_description( $post );
        $main_image    = self::resolve_main_image( $post );
        $title         = self::resolve_title( $post );
        $org_id        = self::resolve_organization_id( $post, $settings );
        $published     = ( $settings['default_status'] ?? 'published' ) === 'published';

        $payload = [
            'title'         => $title,
            'body_markdown' => $content,
            'published'     => $published,
            'canonical_url' => $canonical_url,
            'description'   => $description,
            'tags'          => $tags,
        ];

        if ( ! empty( $main_image ) ) {
            $payload['main_image'] = $main_image;
        }

        if ( $org_id ) {
            $payload['organization_id'] = $org_id;
        }

        return $payload;
    }

    /**
     * Dev.to-specific title, falling back to the WordPress post title.
     *
     * Lets a post keep an SEO-tuned WordPress title while using a punchier
     * or differently-phrased title on Dev.to.
     */
    private static function resolve_title( WP_Post $post ): string {
        $override = get_post_meta( $post->ID, self::META_TITLE, true );
        if ( $override !== '' ) {
            return $override;
        }
        return html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' );
    }

    /**
     * Dev.to-specific cover image, falling back to the WordPress featured image.
     */
    private static function resolve_main_image( WP_Post $post ): string {
        $override = get_post_meta( $post->ID, self::META_MAIN_IMAGE, true );
        if ( $override !== '' ) {
            return esc_url_raw( $override );
        }
        return self::get_featured_image_url( $post );
    }

    /**
     * Resolve the Dev.to organization ID to publish under, if any.
     *
     * Resolution order: per-post override → global default → none (personal
     * account). Dev.to's API expects an int; anything non-numeric is ignored
     * rather than sent and rejected by the API.
     *
     * @param WP_Post $post
     * @param array   $settings  Plugin settings.
     * @return int  0 when no organization should be set.
     */
    private static function resolve_organization_id( WP_Post $post, array $settings ): int {
        $override = get_post_meta( $post->ID, self::META_ORG_ID, true );
        if ( $override !== '' && is_numeric( $override ) ) {
            return (int) $override;
        }

        $default = $settings['organization_id'] ?? '';
        if ( $default !== '' && is_numeric( $default ) ) {
            return (int) $default;
        }

        return 0;
    }

    // -------------------------------------------------------------------------
    // Content conversion helpers
    // -------------------------------------------------------------------------

    /**
     * Convert WordPress post content to Markdown-friendly text.
     *
     * Strategy: apply the_content filters (shortcodes, blocks) then convert
     * common HTML patterns to Markdown. A full HTML→MD library is not bundled
     * to keep the plugin zero-dependency, but the most important constructs
     * are handled. Extend self::html_to_markdown() for edge cases.
     */
    private static function convert_content( WP_Post $post ): string {
        // Run through WP's content filters to expand blocks/shortcodes.
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WP core's own existing hook, not registering a new one.
        $html = apply_filters( 'the_content', $post->post_content );

        // Strip <script> and <style> tags entirely.
        $html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html );

        return self::html_to_markdown( $html );
    }

    /**
     * Lightweight HTML to Markdown converter.
     * Handles: headings, bold, italic, links, images, code blocks,
     * inline code, blockquotes, ordered/unordered lists, paragraphs, hr.
     *
     * Order matters:
     *   1. Extract <pre><code> blocks to placeholders (protects code content
     *      from all subsequent passes).
     *   2. Convert inline elements (img, strong, em, code, a) — so that bold,
     *      links, etc. INSIDE list items and blockquotes survive the
     *      strip_tags pass in step 3.
     *   3. Convert block elements (headings, blockquote, lists, hr, p, br).
     *   4. Restore code block placeholders.
     */
    // phpcs:disable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- wp_strip_all_tags() requires WP loaded; this method is deliberately pure PHP so tests/unit/test-html-to-markdown.php can exercise it via Brain\Monkey without a full WP bootstrap.
    private static function html_to_markdown( string $html ): string {
        // Normalise line endings.
        $html = str_replace( [ "\r\n", "\r" ], "\n", $html );

        // ---- Step 1: extract fenced code blocks to placeholders ----

        $code_blocks = [];
        $html = preg_replace_callback(
            '#<pre[^>]*>\s*<code([^>]*)>(.*?)</code>\s*</pre>#is',
            function ( $m ) use ( &$code_blocks ) {
                $lang = '';
                if ( preg_match( '/class=["\'][^"\']*language-([\w-]+)["\']/', $m[1], $lm ) ) {
                    $lang = $lm[1];
                }
                $code        = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
                $placeholder = "\x1A" . 'CODEBLOCK' . count( $code_blocks ) . "\x1A";
                $code_blocks[ $placeholder ] = "\n\n```{$lang}\n{$code}\n```\n\n";
                return $placeholder;
            },
            $html
        );

        // ---- Step 2: inline elements (before blocks, so formatting survives
        //      the strip_tags inside list/blockquote handling) ----

        // Images — parse the tag once, extract src/alt regardless of attribute order.
        $html = preg_replace_callback(
            '#<img\b[^>]*>#i',
            function ( $m ) {
                $tag = $m[0];
                $src = '';
                $alt = '';
                if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $sm ) ) {
                    $src = $sm[1];
                }
                if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag, $am ) ) {
                    $alt = $am[1];
                }
                return $src ? "![{$alt}]({$src})" : '';
            },
            $html
        );

        // Bold.
        $html = preg_replace( '#<strong[^>]*>(.*?)</strong>#is', '**$1**', $html );
        $html = preg_replace( '#<b[^>]*>(.*?)</b>#is', '**$1**', $html );

        // Italic.
        $html = preg_replace( '#<em[^>]*>(.*?)</em>#is', '_$1_', $html );
        $html = preg_replace( '#<i[^>]*>(.*?)</i>#is', '_$1_', $html );

        // Inline code (standalone <code>, fenced blocks already extracted).
        $html = preg_replace_callback(
            '#<code[^>]*>(.*?)</code>#is',
            fn( $m ) => '`' . html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) . '`',
            $html
        );

        // Links.
        $html = preg_replace( '#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', '[$2]($1)', $html );

        // ---- Step 3: block-level elements ----

        // Blockquotes.
        $html = preg_replace_callback(
            '#<blockquote[^>]*>(.*?)</blockquote>#is',
            function ( $m ) {
                $inner = trim( strip_tags( $m[1] ) );
                $lines = explode( "\n", $inner );
                return "\n\n" . implode( "\n", array_map( fn( $l ) => '> ' . trim( $l ), $lines ) ) . "\n\n";
            },
            $html
        );

        // Headings h1–h6.
        for ( $i = 6; $i >= 1; $i-- ) {
            $hashes = str_repeat( '#', $i );
            $html   = preg_replace( "#<h{$i}[^>]*>(.*?)</h{$i}>#is", "\n\n{$hashes} $1\n\n", $html );
        }

        // Horizontal rules.
        $html = preg_replace( '#<hr[^>]*/?>|<hr>#i', "\n\n---\n\n", $html );

        // Unordered lists.
        $html = preg_replace_callback(
            '#<ul[^>]*>(.*?)</ul>#is',
            function ( $m ) {
                $items = [];
                preg_match_all( '#<li[^>]*>(.*?)</li>#is', $m[1], $li );
                foreach ( $li[1] as $item ) {
                    $items[] = '- ' . trim( strip_tags( $item ) );
                }
                return "\n\n" . implode( "\n", $items ) . "\n\n";
            },
            $html
        );

        // Ordered lists.
        $html = preg_replace_callback(
            '#<ol[^>]*>(.*?)</ol>#is',
            function ( $m ) {
                $items = [];
                $n     = 1;
                preg_match_all( '#<li[^>]*>(.*?)</li>#is', $m[1], $li );
                foreach ( $li[1] as $item ) {
                    $items[] = "{$n}. " . trim( strip_tags( $item ) );
                    $n++;
                }
                return "\n\n" . implode( "\n", $items ) . "\n\n";
            },
            $html
        );

        // Tables → GitHub-Flavored Markdown. Runs after inline conversions
        // (step 2), so bold/italic/links/code inside cells already carry
        // their Markdown syntax by the time cells are strip_tags()'d here —
        // same pattern as the list/blockquote handlers above. Dev.to renders
        // GFM tables, so no fallback plain-text rendering is needed.
        $html = preg_replace_callback(
            '#<table[^>]*>(.*?)</table>#is',
            function ( $m ) {
                preg_match_all( '#<tr[^>]*>(.*?)</tr>#is', $m[1], $row_matches );

                $rows = [];
                foreach ( $row_matches[1] as $row_html ) {
                    preg_match_all( '#<t[hd][^>]*>(.*?)</t[hd]>#is', $row_html, $cell_matches );
                    if ( ! $cell_matches[1] ) {
                        continue;
                    }
                    $rows[] = array_map(
                        function ( $cell ) {
                            $cell = trim( preg_replace( '/\s+/', ' ', strip_tags( $cell ) ) );
                            return str_replace( '|', '\\|', $cell );
                        },
                        $cell_matches[1]
                    );
                }

                if ( ! $rows ) {
                    return '';
                }

                // GFM requires a header row. Tables with no <thead>/<th> (some
                // editors emit flat <tr><td> markup throughout) use their
                // first row as the header — a common, harmless simplification.
                $header = array_shift( $rows );
                $lines  = [ '| ' . implode( ' | ', $header ) . ' |' ];
                $lines[] = '| ' . implode( ' | ', array_fill( 0, count( $header ), '---' ) ) . ' |';
                foreach ( $rows as $row ) {
                    $lines[] = '| ' . implode( ' | ', $row ) . ' |';
                }

                return "\n\n" . implode( "\n", $lines ) . "\n\n";
            },
            $html
        );

        // Paragraphs & divs.
        $html = preg_replace( '#</?p[^>]*>#i', "\n\n", $html );
        $html = preg_replace( '#</?div[^>]*>#i', "\n", $html );

        // Breaks.
        $html = preg_replace( '#<br\s*/?>|<br>#i', "\n", $html );

        // Strip remaining HTML tags.
        $html = strip_tags( $html );

        // Decode HTML entities (code blocks are safe — still placeholders).
        $html = html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );

        // ---- Step 4: restore code blocks ----
        if ( $code_blocks ) {
            $html = strtr( $html, $code_blocks );
        }

        // Collapse excessive blank lines (max 2 consecutive).
        $html = preg_replace( '/\n{3,}/', "\n\n", $html );

        return trim( $html );
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions.strip_tags_strip_tags

    // -------------------------------------------------------------------------
    // Tag resolution
    // -------------------------------------------------------------------------

    /**
     * Build Dev.to tag array (max 4, lowercase, no spaces, alphanumeric + digits).
     */
    private static function resolve_tags( WP_Post $post, array $settings ): array {
        $mappings  = ! empty( $settings['tag_mappings'] ) ? (array) $settings['tag_mappings'] : [];
        $devto_tags = [];

        // Get WordPress tags and categories.
        $wp_tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
        $wp_cats = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
        $wp_terms = array_merge( (array) $wp_tags, (array) $wp_cats );

        foreach ( $wp_terms as $term ) {
            $normalised = self::normalise_tag( $term );
            // Check if there's a manual mapping override.
            if ( isset( $mappings[ $term ] ) && ! empty( $mappings[ $term ] ) ) {
                $normalised = self::normalise_tag( $mappings[ $term ] );
            }
            if ( $normalised && ! in_array( $normalised, $devto_tags, true ) ) {
                $devto_tags[] = $normalised;
            }
            if ( count( $devto_tags ) >= 4 ) {
                break;
            }
        }

        return $devto_tags;
    }

    /**
     * Convert a term name to a valid Dev.to tag (lowercase, no spaces, alphanumeric).
     */
    private static function normalise_tag( string $tag ): string {
        $tag = strtolower( $tag );
        // Spell out '#' so tags like "C#" become "csharp" — the tag Dev.to
        // actually uses — instead of silently losing the character entirely
        // under the generic alphanumeric strip below.
        $tag = str_replace( '#', 'sharp', $tag );
        $tag = preg_replace( '/[^a-z0-9]/', '', $tag );
        return $tag;
    }

    // -------------------------------------------------------------------------
    // Cross-post decision (per-post opt-in, defaulting from settings)
    // -------------------------------------------------------------------------

    /**
     * Whether a post should be cross-posted right now.
     *
     * Resolution order:
     *   1. No API key configured → always false, regardless of any stored choice.
     *   2. An explicit per-post decision has been saved via the metabox → use it.
     *   3. Nothing saved yet → inherit the global "Auto-Publish" setting.
     *
     * Used both to decide automatic syncs and to pre-check the metabox toggle,
     * so the UI and the sync logic can never disagree.
     *
     * @param int        $post_id
     * @param array|null $settings  Pass the already-loaded settings array to avoid
     *                              refetching the option in a loop (e.g. bulk sync).
     */
    public static function get_cross_post_state( int $post_id, ?array $settings = null ): bool {
        $settings = $settings ?? Cross_Post_DevTo_Settings::get();

        if ( empty( $settings['api_key'] ) ) {
            return false;
        }

        $decision = self::get_stored_decision( $post_id );
        if ( $decision === '1' ) {
            return true;
        }
        if ( $decision === '0' ) {
            return false;
        }

        return ! empty( $settings['auto_publish'] );
    }

    /**
     * Reads the explicit per-post decision, if any: '1', '0', or '' (undecided).
     *
     * Falls back to the legacy `_devto_skip` flag for posts saved before the
     * opt-in toggle existed, so upgrading the plugin doesn't silently start
     * cross-posting things a user had previously marked to skip.
     */
    private static function get_stored_decision( int $post_id ): string {
        $value = get_post_meta( $post_id, self::META_CROSS_POST, true );
        if ( $value === '1' || $value === '0' ) {
            return $value;
        }

        if ( get_post_meta( $post_id, self::META_SKIP, true ) ) {
            return '0';
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Meta helpers
    // -------------------------------------------------------------------------

    private static function get_description( WP_Post $post ): string {
        if ( ! empty( $post->post_excerpt ) ) {
            return wp_strip_all_tags( $post->post_excerpt );
        }
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- invoking WP core's own existing hook, not registering a new one.
        $content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        return wp_trim_words( $content, 30, '' );
    }

    private static function get_featured_image_url( WP_Post $post ): string {
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( ! $thumb_id ) {
            return '';
        }
        $src = wp_get_attachment_image_src( $thumb_id, 'full' );
        return $src ? esc_url_raw( $src[0] ) : '';
    }

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------

    /**
     * Append a log entry to a simple capped transient-based log per post.
     *
     * @param string $url  Optional Dev.to article URL, so the log entry can be
     *                      rendered as a link straight to the article.
     */
    private static function log( int $post_id, string $level, string $message, string $url = '' ) {
        $key  = "devto_log_{$post_id}";
        $log  = get_option( $key, [] );
        $log[] = [
            'time'    => current_time( 'mysql' ),
            'level'   => $level,
            'message' => $message,
            'url'     => $url,
        ];
        // Keep only the last 20 entries.
        if ( count( $log ) > 20 ) {
            $log = array_slice( $log, -20 );
        }
        update_option( $key, $log, false );
    }

    /**
     * Retrieve log entries for a post (used in metabox).
     */
    public static function get_log( int $post_id ): array {
        return get_option( "devto_log_{$post_id}", [] );
    }
}
