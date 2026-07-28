<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds a "Dev.to" sidebar panel to the post editor (both Gutenberg and Classic).
 * Provides:
 *  - Opt-in "Cross-post this to Dev.to" toggle for this specific post, defaulting
 *    from the global Auto-Publish setting and disabled when no API key is set.
 *  - Manual "Sync Now" button for already-published posts.
 *  - Read-only display of the linked Dev.to URL and last-sync time.
 *  - Per-post sync log.
 */
class Cross_Post_DevTo_Metabox {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register' ] );
        add_action( 'save_post',      [ __CLASS__, 'save' ], 10, 2 );
        add_action( 'wp_ajax_cross_post_devto_sync_now', [ __CLASS__, 'ajax_sync_now' ] );
        add_action( 'enqueue_block_editor_assets',  [ __CLASS__, 'enqueue_block_assets' ] );
        add_action( 'admin_enqueue_scripts',        [ __CLASS__, 'enqueue_classic_assets' ] );
    }

    // -------------------------------------------------------------------------
    // Classic editor metabox
    // -------------------------------------------------------------------------

    public static function register() {
        $settings     = Cross_Post_DevTo_Settings::get();
        $allowed_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : [ 'post' ];

        foreach ( $allowed_types as $pt ) {
            add_meta_box(
                'cross-post-devto',
                '<span class="devto-mb-logo">dev</span> Dev.to Sync',
                [ __CLASS__, 'render' ],
                $pt,
                'side',
                'default'
            );
        }
    }

    public static function render( WP_Post $post ) {
        wp_nonce_field( 'cross_post_devto_metabox', 'cross_post_devto_mb_nonce' );
        self::render_panel( $post );
    }

    /**
     * Renders the `.devto-mb-wrap` panel only (no nonce field).
     *
     * Split out from render() so `ajax_sync_now()` can re-render exactly this
     * markup after a manual sync and hand it back to the client — the status
     * banner, Dev.to link, "last synced" time, and log always match saved
     * post meta this way, with no hand-maintained JS DOM patching to drift
     * out of sync with this template.
     */
    private static function render_panel( WP_Post $post ) {
        $settings   = Cross_Post_DevTo_Settings::get();
        $has_key    = ! empty( $settings['api_key'] );
        $cross_post = Cross_Post_DevTo_Publisher::get_cross_post_state( $post->ID, $settings );
        $devto_url  = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_URL, true );
        $devto_id   = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $synced_at  = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_SYNCED, true );
        $log        = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $is_published = ( $post->post_status === 'publish' );
        ?>
        <div class="devto-mb-wrap">

            <!-- Status banner -->
            <?php if ( $devto_id ) : ?>
                <div class="devto-mb-status synced">
                    ✓ <?php esc_html_e( 'Synced to Dev.to', 'cross-post-devto' ); ?>
                </div>
            <?php else : ?>
                <div class="devto-mb-status pending">
                    ○ <?php esc_html_e( 'Not yet synced', 'cross-post-devto' ); ?>
                </div>
            <?php endif; ?>

            <!-- Dev.to link -->
            <?php if ( $devto_url ) : ?>
                <p class="devto-mb-field">
                    <a href="<?php echo esc_url( $devto_url ); ?>" target="_blank" rel="noopener">
                        <?php esc_html_e( 'View on Dev.to ↗', 'cross-post-devto' ); ?>
                    </a>
                </p>
            <?php endif; ?>

            <!-- Last synced -->
            <?php if ( $synced_at ) : ?>
                <p class="devto-mb-field devto-mb-meta">
                    <?php echo esc_html( sprintf(
                        /* translators: %s = datetime */
                        __( 'Last synced: %s', 'cross-post-devto' ),
                        $synced_at
                    ) ); ?>
                </p>
            <?php endif; ?>

            <hr class="devto-mb-hr" />

            <!-- Cross-post toggle -->
            <?php if ( $has_key ) : ?>
                <label class="devto-mb-toggle">
                    <input
                        type="checkbox"
                        name="devto_cross_post"
                        id="devto_cross_post"
                        value="1"
                        <?php checked( $cross_post ); ?>
                    />
                    <?php esc_html_e( 'Cross-post this to Dev.to', 'cross-post-devto' ); ?>
                </label>
            <?php else : ?>
                <label class="devto-mb-toggle devto-mb-toggle-disabled">
                    <input type="checkbox" disabled="disabled" />
                    <?php esc_html_e( 'Cross-post this to Dev.to', 'cross-post-devto' ); ?>
                </label>
                <p class="devto-mb-field devto-mb-meta">
                    <?php
                    printf(
                        /* translators: %s = link to Settings page */
                        esc_html__( 'Add an API key in %s to enable cross-posting.', 'cross-post-devto' ),
                        '<a href="' . esc_url( admin_url( 'options-general.php?page=' . Cross_Post_DevTo_Settings::MENU_SLUG ) ) . '">'
                        . esc_html__( 'Settings', 'cross-post-devto' ) . '</a>'
                    );
                    ?>
                </p>
            <?php endif; ?>

            <!-- Per-post overrides -->
            <?php if ( $has_key ) : ?>
                <hr class="devto-mb-hr" />
                <details class="devto-mb-overrides">
                    <summary><?php esc_html_e( 'Dev.to overrides (optional)', 'cross-post-devto' ); ?></summary>

                    <p class="devto-mb-field">
                        <label for="devto_title_override"><?php esc_html_e( 'Dev.to title', 'cross-post-devto' ); ?></label>
                        <input
                            type="text"
                            id="devto_title_override"
                            name="devto_title_override"
                            value="<?php echo esc_attr( get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_TITLE, true ) ); ?>"
                            class="widefat"
                            placeholder="<?php esc_attr_e( 'Defaults to the WordPress post title', 'cross-post-devto' ); ?>"
                        />
                    </p>

                    <p class="devto-mb-field">
                        <label for="devto_main_image_override"><?php esc_html_e( 'Cover image URL', 'cross-post-devto' ); ?></label>
                        <input
                            type="url"
                            id="devto_main_image_override"
                            name="devto_main_image_override"
                            value="<?php echo esc_attr( get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_MAIN_IMAGE, true ) ); ?>"
                            class="widefat"
                            placeholder="<?php esc_attr_e( 'Defaults to the featured image', 'cross-post-devto' ); ?>"
                        />
                    </p>

                    <p class="devto-mb-field">
                        <label for="devto_organization_id"><?php esc_html_e( 'Organization ID', 'cross-post-devto' ); ?></label>
                        <input
                            type="text"
                            id="devto_organization_id"
                            name="devto_organization_id"
                            value="<?php echo esc_attr( get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_ORG_ID, true ) ); ?>"
                            class="widefat"
                            inputmode="numeric"
                            placeholder="<?php esc_attr_e( 'Defaults to the global setting, if any', 'cross-post-devto' ); ?>"
                        />
                    </p>
                </details>
            <?php endif; ?>

            <!-- Manual sync button (only for published posts) -->
            <?php if ( $is_published ) : ?>
                <p class="devto-mb-field" style="margin-top:10px;">
                    <button
                        type="button"
                        id="devto-sync-now"
                        class="button button-secondary"
                        data-post-id="<?php echo esc_attr( (string) $post->ID ); ?>"
                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'cross_post_devto_sync_now' ) ); ?>"
                    >
                        <?php esc_html_e( '↺ Sync Now', 'cross-post-devto' ); ?>
                    </button>
                    <span id="devto-sync-status" style="margin-left:8px;"></span>
                </p>
            <?php endif; ?>

            <!-- Log section -->
            <?php if ( ! empty( $log ) ) : ?>
                <hr class="devto-mb-hr" />
                <details class="devto-mb-log">
                    <summary><?php esc_html_e( 'Sync Log', 'cross-post-devto' ); ?></summary>
                    <ul>
                        <?php foreach ( array_reverse( $log ) as $entry ) : ?>
                            <li class="devto-log-<?php echo esc_attr( $entry['level'] ); ?>">
                                <span class="devto-log-time"><?php echo esc_html( $entry['time'] ); ?></span>
                                <?php if ( ! empty( $entry['url'] ?? '' ) ) : ?>
                                    <a href="<?php echo esc_url( $entry['url'] ); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html( $entry['message'] ); ?> ↗
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $entry['message'] ); ?>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

        </div>
        <?php
    }

    public static function save( int $post_id, WP_Post $post ) {
        if (
            ! isset( $_POST['cross_post_devto_mb_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cross_post_devto_mb_nonce'] ) ), 'cross_post_devto_metabox' )
        ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // No API key: the toggle is disabled in the UI, so force-persist "off"
        // regardless of what was submitted (defense in depth, not just UI).
        if ( empty( Cross_Post_DevTo_Settings::get()['api_key'] ) ) {
            update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_CROSS_POST, '0' );
            return;
        }

        $enabled = ! empty( $_POST['devto_cross_post'] );
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_CROSS_POST, $enabled ? '1' : '0' );

        $title_override = sanitize_text_field( wp_unslash( $_POST['devto_title_override'] ?? '' ) );
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_TITLE, $title_override );

        $image_override = esc_url_raw( wp_unslash( $_POST['devto_main_image_override'] ?? '' ) );
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_MAIN_IMAGE, $image_override );

        $org_id = Cross_Post_DevTo_Settings::sanitize_organization_id(
            sanitize_text_field( wp_unslash( $_POST['devto_organization_id'] ?? '' ) )
        );
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_ORG_ID, $org_id );
    }

    // -------------------------------------------------------------------------
    // AJAX: manual Sync Now
    // -------------------------------------------------------------------------

    public static function ajax_sync_now() {
        check_ajax_referer( 'cross_post_devto_sync_now', 'nonce' );

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission denied.', 'cross-post-devto' ) ] );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_status !== 'publish' ) {
            wp_send_json_error( [ 'message' => __( 'Post is not published.', 'cross-post-devto' ) ] );
        }

        // Manual Sync Now is an intentional action: force bypasses the per-post
        // cross-post decision (and the global default it falls back to)
        // without mutating it. Still requires an API key.
        Cross_Post_DevTo_Publisher::maybe_sync( $post, true );

        $log  = Cross_Post_DevTo_Publisher::get_log( $post_id );
        $last = end( $log );

        // Re-render the panel from current post meta so the status banner,
        // Dev.to link, "last synced" time, and log are always accurate —
        // whether this sync succeeded or failed.
        ob_start();
        self::render_panel( $post );
        $html = ob_get_clean();

        if ( $last && $last['level'] === 'error' ) {
            wp_send_json_error( [
                'message' => $last['message'],
                'html'    => $html,
            ] );
        }

        wp_send_json_success( [
            'message' => $last['message'] ?? __( 'Synced.', 'cross-post-devto' ),
            'html'    => $html,
        ] );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_classic_assets( string $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        wp_enqueue_script(
            'cross-post-devto-metabox',
            CROSS_POST_DEVTO_URL . 'assets/metabox.js',
            [ 'jquery' ],
            CROSS_POST_DEVTO_VERSION,
            true
        );
        wp_enqueue_style(
            'cross-post-devto-metabox',
            CROSS_POST_DEVTO_URL . 'assets/admin.css',
            [],
            CROSS_POST_DEVTO_VERSION
        );
    }

    /** Gutenberg: no separate panel needed – classic metabox renders in sidebar by default. */
    public static function enqueue_block_assets() {
        // Intentionally empty – the classic metabox is surfaced in the Gutenberg sidebar
        // via WordPress's built-in compatibility layer. No additional JS needed.
    }
}
