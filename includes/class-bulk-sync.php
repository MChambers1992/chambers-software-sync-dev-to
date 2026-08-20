<?php
defined( 'ABSPATH' ) || exit;

/**
 * Provides a Bulk Sync admin page under Tools > Dev.to Bulk Sync.
 *
 * Lets site owners retrospectively publish already-published WordPress posts
 * to Dev.to. Posts are listed with their current sync status and can be
 * selected individually or in bulk. Processing happens via batched AJAX
 * calls (10 posts per batch) so large post counts don't time out.
 *
 * Skips posts that already have a Dev.to article ID unless the user
 * explicitly checks "Re-sync already synced posts".
 */
class Cross_Post_DevTo_Bulk_Sync {

    const MENU_SLUG       = 'chambers-software-sync-dev-to-bulk';
    const BATCH_SIZE      = 10;
    const AJAX_FETCH      = 'cross_post_devto_bulk_fetch';
    const AJAX_PROCESS    = 'cross_post_devto_bulk_process';

    public static function init() {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_' . self::AJAX_FETCH,   [ __CLASS__, 'ajax_fetch_posts' ] );
        add_action( 'wp_ajax_' . self::AJAX_PROCESS, [ __CLASS__, 'ajax_process_batch' ] );
    }

    // -------------------------------------------------------------------------
    // Menu
    // -------------------------------------------------------------------------

    public static function add_menu_page() {
        add_management_page(
            __( 'Dev.to Bulk Sync', 'chambers-software-sync-dev-to' ),
            __( 'Dev.to Bulk Sync', 'chambers-software-sync-dev-to' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Assets
    // -------------------------------------------------------------------------

    public static function enqueue_assets( string $hook ) {
        if ( strpos( $hook, self::MENU_SLUG ) === false ) {
            return;
        }
        wp_enqueue_style(
            'cross-post-devto-admin',
            CROSS_POST_DEVTO_URL . 'assets/admin.css',
            [],
            CROSS_POST_DEVTO_VERSION
        );
        wp_enqueue_script(
            'cross-post-devto-bulk',
            CROSS_POST_DEVTO_URL . 'assets/bulk-sync.js',
            [ 'jquery' ],
            CROSS_POST_DEVTO_VERSION,
            true
        );
        wp_localize_script( 'cross-post-devto-bulk', 'crossPostDevToBulk', [
            'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
            'nonceFetch'    => wp_create_nonce( self::AJAX_FETCH ),
            'nonceProcess'  => wp_create_nonce( self::AJAX_PROCESS ),
            'batchSize'     => self::BATCH_SIZE,
            'strings'       => [
                'processing'    => __( 'Processing…', 'chambers-software-sync-dev-to' ),
                /* translators: 1: current post number being synced, 2: total post count in this run */
                'syncing'       => __( 'Syncing post %1$d of %2$d…', 'chambers-software-sync-dev-to' ),
                /* translators: 1: number of posts synced, 2: number skipped, 3: number failed */
                'done'          => __( 'Done! %1$d synced, %2$d skipped, %3$d failed.', 'chambers-software-sync-dev-to' ),
                /* translators: %d: number of posts that will be synced */
                'confirmStart'  => __( 'This will sync %d posts to Dev.to. Continue?', 'chambers-software-sync-dev-to' ),
                'noSelection'   => __( 'Please select at least one post.', 'chambers-software-sync-dev-to' ),
                'loadError'     => __( 'Failed to load posts. Please reload the page.', 'chambers-software-sync-dev-to' ),
                'batchError'    => __( 'Batch request failed. Retrying…', 'chambers-software-sync-dev-to' ),
            ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings  = Cross_Post_DevTo_Settings::get();
        $has_key   = ! empty( $settings['api_key'] );
        $post_types = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : [ 'post' ];
        ?>
        <div class="wrap devto-settings-wrap">
            <h1>
                <span class="devto-logo">dev</span>
                <?php esc_html_e( 'Dev.to Bulk Sync', 'chambers-software-sync-dev-to' ); ?>
            </h1>
            <p class="devto-bulk-intro">
                <?php esc_html_e( 'Retrospectively publish existing WordPress posts to Dev.to. Posts already synced show their Dev.to link. Use the filters to narrow the list, then select posts and click Sync Selected.', 'chambers-software-sync-dev-to' ); ?>
            </p>

            <?php if ( ! $has_key ) : ?>
                <div class="notice notice-error">
                    <p>
                        <?php
                        printf(
                            /* translators: %s = link */
                            esc_html__( 'No Dev.to API key configured. %s first.', 'chambers-software-sync-dev-to' ),
                            '<a href="' . esc_url( admin_url( 'options-general.php?page=' . Cross_Post_DevTo_Settings::MENU_SLUG ) ) . '">'
                            . esc_html__( 'Add your API key', 'chambers-software-sync-dev-to' ) . '</a>'
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <!-- ── Filters ────────────────────────────────────────────── -->
            <div class="devto-card devto-bulk-filters">
                <h2><?php esc_html_e( 'Filters', 'chambers-software-sync-dev-to' ); ?></h2>
                <div class="devto-filter-row">
                    <div class="devto-filter-group">
                        <label for="bulk-post-type"><?php esc_html_e( 'Post Type', 'chambers-software-sync-dev-to' ); ?></label>
                        <select id="bulk-post-type">
                            <?php foreach ( $post_types as $pt ) :
                                $obj = get_post_type_object( $pt );
                                $label = $obj ? $obj->label : $pt;
                                ?>
                                <option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="devto-filter-group">
                        <label for="bulk-sync-status"><?php esc_html_e( 'Sync Status', 'chambers-software-sync-dev-to' ); ?></label>
                        <select id="bulk-sync-status">
                            <option value="all"><?php esc_html_e( 'All posts', 'chambers-software-sync-dev-to' ); ?></option>
                            <option value="unsynced" selected><?php esc_html_e( 'Not yet synced', 'chambers-software-sync-dev-to' ); ?></option>
                            <option value="synced"><?php esc_html_e( 'Already synced', 'chambers-software-sync-dev-to' ); ?></option>
                        </select>
                    </div>
                    <div class="devto-filter-group">
                        <label for="bulk-search"><?php esc_html_e( 'Search', 'chambers-software-sync-dev-to' ); ?></label>
                        <input type="text" id="bulk-search" placeholder="<?php esc_attr_e( 'Title keyword…', 'chambers-software-sync-dev-to' ); ?>" />
                    </div>
                    <div class="devto-filter-group devto-filter-action">
                        <button id="devto-bulk-load" class="button button-secondary" <?php disabled( ! $has_key ); ?>>
                            <?php esc_html_e( 'Load Posts', 'chambers-software-sync-dev-to' ); ?>
                        </button>
                    </div>
                </div>
                <div class="devto-filter-row devto-resync-row">
                    <label class="devto-mb-toggle">
                        <input type="checkbox" id="bulk-resync" value="1" />
                        <?php esc_html_e( 'Re-sync posts that are already on Dev.to (will update the existing article)', 'chambers-software-sync-dev-to' ); ?>
                    </label>
                </div>
            </div>

            <!-- ── Post list ──────────────────────────────────────────── -->
            <div class="devto-card devto-bulk-table-wrap" id="devto-bulk-table-wrap" style="display:none;">
                <div class="devto-bulk-table-header">
                    <div class="devto-bulk-count" id="devto-bulk-count"></div>
                    <div class="devto-bulk-actions">
                        <label class="devto-mb-toggle" style="margin-right:16px;">
                            <input type="checkbox" id="devto-select-all" />
                            <?php esc_html_e( 'Select all', 'chambers-software-sync-dev-to' ); ?>
                        </label>
                        <button id="devto-sync-selected" class="button button-primary" disabled>
                            <?php esc_html_e( '↑ Sync Selected', 'chambers-software-sync-dev-to' ); ?>
                        </button>
                    </div>
                </div>

                <table class="widefat devto-bulk-table" id="devto-bulk-table">
                    <thead>
                        <tr>
                            <th class="col-check"></th>
                            <th class="col-title"><?php esc_html_e( 'Post Title', 'chambers-software-sync-dev-to' ); ?></th>
                            <th class="col-type"><?php esc_html_e( 'Type', 'chambers-software-sync-dev-to' ); ?></th>
                            <th class="col-date"><?php esc_html_e( 'Published', 'chambers-software-sync-dev-to' ); ?></th>
                            <th class="col-status"><?php esc_html_e( 'Dev.to Status', 'chambers-software-sync-dev-to' ); ?></th>
                            <th class="col-result"><?php esc_html_e( 'Result', 'chambers-software-sync-dev-to' ); ?></th>
                        </tr>
                    </thead>
                    <tbody id="devto-bulk-tbody">
                        <!-- Populated by JS -->
                    </tbody>
                </table>
            </div>

            <!-- ── Progress bar ───────────────────────────────────────── -->
            <div class="devto-card devto-progress-wrap" id="devto-progress-wrap" style="display:none;">
                <h2><?php esc_html_e( 'Sync Progress', 'chambers-software-sync-dev-to' ); ?></h2>
                <div class="devto-progress-bar-outer">
                    <div class="devto-progress-bar-inner" id="devto-progress-bar"></div>
                </div>
                <p id="devto-progress-label" class="devto-progress-label"></p>
                <div class="devto-progress-counts">
                    <span class="devto-count-synced">✓ <strong id="count-synced">0</strong> <?php esc_html_e( 'synced', 'chambers-software-sync-dev-to' ); ?></span>
                    <span class="devto-count-skipped">– <strong id="count-skipped">0</strong> <?php esc_html_e( 'skipped', 'chambers-software-sync-dev-to' ); ?></span>
                    <span class="devto-count-failed">✗ <strong id="count-failed">0</strong> <?php esc_html_e( 'failed', 'chambers-software-sync-dev-to' ); ?></span>
                </div>
            </div>

            <!-- ── Loading state ──────────────────────────────────────── -->
            <div id="devto-bulk-loading" style="display:none;">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                <?php esc_html_e( 'Loading posts…', 'chambers-software-sync-dev-to' ); ?>
            </div>
            <div id="devto-bulk-error" class="notice notice-error" style="display:none;max-width:900px;">
                <p id="devto-bulk-error-msg"></p>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: fetch post list
    // -------------------------------------------------------------------------

    /**
     * Returns a JSON list of published posts matching the requested filters.
     * Includes sync status (has Dev.to ID or not) for each post.
     */
    public static function ajax_fetch_posts() {
        check_ajax_referer( self::AJAX_FETCH, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $settings    = Cross_Post_DevTo_Settings::get();
        $post_type   = sanitize_key( $_POST['post_type'] ?? 'post' );
        $sync_status = sanitize_key( $_POST['sync_status'] ?? 'all' );
        $search      = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );

        // Validate requested post type is in the allowed list.
        $allowed = ! empty( $settings['post_types'] ) ? (array) $settings['post_types'] : [ 'post' ];
        if ( ! in_array( $post_type, $allowed, true ) ) {
            wp_send_json_error( 'Post type not allowed.' );
        }

        $args = [
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            // -1 is acceptable here: 'fields' => 'ids' keeps memory minimal
            // (an int array, not hydrated WP_Post objects). Fine up to several
            // thousand posts. If targeting 10k+ archives, switch to paged
            // fetching in the JS layer.
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        if ( $search ) {
            $args['s'] = $search;
        }

        // Filter by sync status using meta_query. This page is an infrequent
        // manual admin action (not a hot path), and there's no non-meta way
        // to ask "which posts have/don't have this meta key" — the slow-query
        // warning is a known, accepted tradeoff here.
        if ( $sync_status === 'synced' ) {
            $args['meta_query'] = [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'key'     => Cross_Post_DevTo_Publisher::META_DEVTO_ID,
                'compare' => 'EXISTS',
            ] ];
        } elseif ( $sync_status === 'unsynced' ) {
            $args['meta_query'] = [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
                'relation' => 'OR',
                [
                    'key'     => Cross_Post_DevTo_Publisher::META_DEVTO_ID,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key'     => Cross_Post_DevTo_Publisher::META_DEVTO_ID,
                    'value'   => '',
                    'compare' => '=',
                ],
            ] ];
        }

        $post_ids = get_posts( $args );
        $posts    = [];

        foreach ( $post_ids as $post_id ) {
            $devto_id   = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
            $devto_url  = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_URL, true );
            $synced_at  = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_SYNCED, true );
            $cross_post = Cross_Post_DevTo_Publisher::get_cross_post_state( $post_id, $settings );

            $posts[] = [
                'id'         => $post_id,
                'title'      => html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' ),
                'edit_url'   => get_edit_post_link( $post_id, 'raw' ),
                'post_type'  => get_post_type( $post_id ),
                'date'       => get_the_date( 'Y-m-d', $post_id ),
                'devto_id'   => $devto_id ? (int) $devto_id : null,
                'devto_url'  => $devto_url ?: null,
                'synced_at'  => $synced_at ?: null,
                'cross_post' => $cross_post,
            ];
        }

        wp_send_json_success( [
            'posts' => $posts,
            'total' => count( $posts ),
        ] );
    }

    // -------------------------------------------------------------------------
    // AJAX: process a batch
    // -------------------------------------------------------------------------

    /**
     * Processes a single batch of post IDs.
     *
     * Expects:
     *   post_ids  — JSON-encoded array of int post IDs
     *   resync    — '1' to force re-sync of already-synced posts
     *
     * Returns per-post results so the JS can update the table live.
     */
    public static function ajax_process_batch() {
        check_ajax_referer( self::AJAX_PROCESS, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $settings = Cross_Post_DevTo_Settings::get();

        if ( empty( $settings['api_key'] ) ) {
            wp_send_json_error( 'No API key configured.' );
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- decoded array elements are sanitized via array_map( 'absint', ... ) below; PHPCS can't trace sanitization through json_decode().
        $raw_ids = json_decode( wp_unslash( $_POST['post_ids'] ?? '[]' ), true );
        if ( ! is_array( $raw_ids ) ) {
            wp_send_json_error( 'Invalid post_ids payload.' );
        }

        $post_ids = array_map( 'absint', $raw_ids );
        $resync   = ! empty( $_POST['resync'] ) && $_POST['resync'] === '1';
        $results  = [];

        foreach ( $post_ids as $post_id ) {
            $results[ $post_id ] = self::sync_single( $post_id, $settings, $resync );
        }

        wp_send_json_success( [ 'results' => $results ] );
    }

    // -------------------------------------------------------------------------
    // Internal: sync one post
    // -------------------------------------------------------------------------

    /**
     * Sync a single post ID. Returns a result array for the JS to consume.
     *
     * @param int   $post_id
     * @param array $settings  Plugin settings.
     * @param bool  $resync    Force sync even if already synced.
     * @return array { status: 'synced'|'skipped'|'error', message: string, devto_url: string|null }
     */
    private static function sync_single( int $post_id, array $settings, bool $resync ): array {
        $post = get_post( $post_id );

        if ( ! $post || $post->post_status !== 'publish' ) {
            return self::result( 'skipped', __( 'Post not found or not published.', 'chambers-software-sync-dev-to' ) );
        }

        $existing_id = (int) get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );

        // Skip already-synced posts unless the caller requested a re-sync.
        if ( $existing_id > 0 && ! $resync ) {
            return self::result(
                'skipped',
                sprintf(
                    /* translators: %d = Dev.to article ID */
                    __( 'Already synced (Dev.to #%d). Check "Re-sync" to update.', 'chambers-software-sync-dev-to' ),
                    $existing_id
                ),
                get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_URL, true ) ?: null
            );
        }

        // Bulk sync is an intentional manual action: force bypasses the
        // per-post cross-post decision (and the global default it falls
        // back to), without mutating it. Still requires an API key.
        Cross_Post_DevTo_Publisher::maybe_sync( $post, true );

        $log     = Cross_Post_DevTo_Publisher::get_log( $post_id );
        $last    = end( $log );
        $devto_url = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_URL, true ) ?: null;

        if ( $last && $last['level'] === 'error' ) {
            return self::result( 'error', $last['message'] );
        }

        return self::result(
            'synced',
            $last['message'] ?? __( 'Synced.', 'chambers-software-sync-dev-to' ),
            $devto_url
        );
    }

    private static function result( string $status, string $message, ?string $devto_url = null ): array {
        return [
            'status'    => $status,
            'message'   => $message,
            'devto_url' => $devto_url,
        ];
    }
}
