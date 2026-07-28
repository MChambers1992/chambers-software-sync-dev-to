<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages the plugin settings page under Settings > Cross Post for Dev.to.
 */
class Cross_Post_DevTo_Settings {

    const OPTION_KEY   = 'cross_post_devto_settings';
    const MENU_SLUG    = 'cross-post-devto';
    const NONCE_ACTION = 'cross_post_devto_settings_save';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
        add_action( 'admin_init', [ __CLASS__, 'handle_save' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'wp_ajax_cross_post_devto_validate_key', [ __CLASS__, 'ajax_validate_key' ] );
        add_filter( 'plugin_action_links_' . CROSS_POST_DEVTO_BASENAME, [ __CLASS__, 'add_settings_link' ] );
    }

    // -------------------------------------------------------------------------
    // Plugins list row action
    // -------------------------------------------------------------------------

    /**
     * Adds a "Settings" link to this plugin's row on the Plugins list page.
     *
     * @param string[] $links Existing action links (Activate/Deactivate, etc.).
     * @return string[]
     */
    public static function add_settings_link( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ),
            esc_html__( 'Settings', 'cross-post-devto' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    // -------------------------------------------------------------------------
    // AJAX: validate API key (Test Connection button)
    // -------------------------------------------------------------------------

    public static function ajax_validate_key() {
        check_ajax_referer( 'cross_post_devto_validate', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'cross-post-devto' ) );
        }

        $key    = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );
        $api    = new Cross_Post_DevTo_API( $key );
        $result = $api->validate_key();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }
        wp_send_json_success( sprintf( 'Connected as @%s', $result['username'] ?? 'unknown' ) );
    }

    // -------------------------------------------------------------------------
    // Menu registration
    // -------------------------------------------------------------------------

    public static function add_menu_page() {
        add_options_page(
            __( 'Cross Post for Dev.to', 'cross-post-devto' ),
            __( 'Cross Post for Dev.to', 'cross-post-devto' ),
            'manage_options',
            self::MENU_SLUG,
            [ __CLASS__, 'render_page' ]
        );
    }

    // -------------------------------------------------------------------------
    // Save handler
    // -------------------------------------------------------------------------

    public static function handle_save() {
        if (
            ! isset( $_POST['cross_post_devto_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cross_post_devto_nonce'] ) ), self::NONCE_ACTION )
        ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Gather post types.
        $raw_types = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : [];
        $post_types = array_map( 'sanitize_key', $raw_types );

        // Gather excluded categories.
        $raw_cats   = isset( $_POST['exclude_cats'] ) ? (array) $_POST['exclude_cats'] : [];
        $excl_cats  = array_map( 'absint', $raw_cats );

        // Tag mappings: wp_term_name => devto_tag.
        $raw_mappings = isset( $_POST['tag_mappings'] ) ? (array) $_POST['tag_mappings'] : [];
        $tag_mappings = [];
        foreach ( $raw_mappings as $wp_term => $devto_tag ) {
            $wp_term  = sanitize_text_field( $wp_term );
            $devto_tag = sanitize_text_field( $devto_tag );
            if ( $wp_term !== '' ) {
                $tag_mappings[ $wp_term ] = $devto_tag;
            }
        }

        $organization_id = self::sanitize_organization_id(
            sanitize_text_field( wp_unslash( $_POST['organization_id'] ?? '' ) )
        );

        $settings = [
            'api_key'                   => sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) ),
            'auto_publish'              => ! empty( $_POST['auto_publish'] ),
            'default_status'            => in_array( sanitize_key( wp_unslash( $_POST['default_status'] ?? '' ) ), [ 'published', 'draft' ], true )
                                    ? sanitize_key( wp_unslash( $_POST['default_status'] ) ) : 'published',
            'post_types'                => $post_types,
            'tag_mappings'              => $tag_mappings,
            'exclude_cats'              => $excl_cats,
            'organization_id'           => $organization_id,
            'unpublish_on_trash'        => ! empty( $_POST['unpublish_on_trash'] ),
            'delete_data_on_uninstall'  => ! empty( $_POST['delete_data_on_uninstall'] ),
        ];

        update_option( self::OPTION_KEY, $settings );
        wp_safe_redirect( add_query_arg( [ 'page' => self::MENU_SLUG, 'saved' => '1' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Sanitization helpers
    // -------------------------------------------------------------------------

    /**
     * Reduce a raw organization ID input to a numeric string, or ''.
     *
     * Dev.to's API rejects non-integer `organization_id` values, so anything
     * that isn't numeric is dropped here rather than stored and sent later.
     * Shared by the global setting (this class) and the per-post override
     * (Cross_Post_DevTo_Metabox::save()) so both apply the same rule.
     *
     * @param string $raw  Already unslashed/sanitize_text_field'd input.
     */
    public static function sanitize_organization_id( string $raw ): string {
        return ( $raw !== '' && is_numeric( $raw ) ) ? $raw : '';
    }

    // -------------------------------------------------------------------------
    // Asset loading
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
            'cross-post-devto-admin',
            CROSS_POST_DEVTO_URL . 'assets/admin.js',
            [ 'jquery' ],
            CROSS_POST_DEVTO_VERSION,
            true
        );
        wp_localize_script( 'cross-post-devto-admin', 'crossPostDevTo', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'cross_post_devto_validate' ),
        ] );
    }

    // -------------------------------------------------------------------------
    // Getter
    // -------------------------------------------------------------------------

    public static function get(): array {
        $defaults = [
            'api_key'         => '',
            'auto_publish'    => true,
            'default_status'  => 'published',
            'post_types'      => [ 'post' ],
            'tag_mappings'    => [],
            'exclude_cats'    => [],
            'organization_id' => '',
            'unpublish_on_trash'       => false,
            'delete_data_on_uninstall' => false,
        ];
        $stored = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $stored, $defaults );
    }

    // -------------------------------------------------------------------------
    // Admin page render
    // -------------------------------------------------------------------------

    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $s          = self::get();
        $post_types = get_post_types( [ 'public' => true ], 'objects' );
        $categories = get_categories( [ 'hide_empty' => false ] );
        $saved      = isset( $_GET['saved'] );
        ?>
        <div class="wrap devto-settings-wrap">
            <h1>
                <span class="devto-logo">dev</span>
                <?php esc_html_e( 'Cross Post for Dev.to Settings', 'cross-post-devto' ); ?>
            </h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings saved.', 'cross-post-devto' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ); ?>">
                <?php wp_nonce_field( self::NONCE_ACTION, 'cross_post_devto_nonce' ); ?>

                <!-- ── API Key ─────────────────────────────────────────── -->
                <div class="devto-card">
                    <h2><?php esc_html_e( 'API Connection', 'cross-post-devto' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><label for="api_key"><?php esc_html_e( 'Dev.to API Key', 'cross-post-devto' ); ?></label></th>
                            <td>
                                <input
                                    type="password"
                                    id="api_key"
                                    name="api_key"
                                    value="<?php echo esc_attr( $s['api_key'] ); ?>"
                                    class="regular-text"
                                    autocomplete="new-password"
                                />
                                <button type="button" id="devto-test-key" class="button button-secondary">
                                    <?php esc_html_e( 'Test Connection', 'cross-post-devto' ); ?>
                                </button>
                                <span id="devto-test-result"></span>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %s = link */
                                        esc_html__( 'Generate your key at %s → Settings → Extensions → DEV API Keys.', 'cross-post-devto' ),
                                        '<a href="https://dev.to/settings/extensions" target="_blank" rel="noopener">dev.to</a>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Publishing Behaviour ────────────────────────────── -->
                <div class="devto-card">
                    <h2><?php esc_html_e( 'Publishing Behaviour', 'cross-post-devto' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Auto-Publish', 'cross-post-devto' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="auto_publish" value="1" <?php checked( $s['auto_publish'] ); ?> />
                                    <?php esc_html_e( 'Automatically sync posts to Dev.to when published or updated', 'cross-post-devto' ); ?>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Unpublish on Trash', 'cross-post-devto' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="unpublish_on_trash" value="1" <?php checked( $s['unpublish_on_trash'] ); ?> />
                                    <?php esc_html_e( 'When a synced post is trashed in WordPress, unpublish its Dev.to article too', 'cross-post-devto' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Off by default. Dev.to has no delete API, so this sets the article back to a draft rather than removing it. Restoring the WordPress post from Trash does not automatically re-publish it on Dev.to.', 'cross-post-devto' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="default_status"><?php esc_html_e( 'Default Publish Status', 'cross-post-devto' ); ?></label></th>
                            <td>
                                <select id="default_status" name="default_status">
                                    <option value="published" <?php selected( $s['default_status'], 'published' ); ?>>
                                        <?php esc_html_e( 'Published (live immediately)', 'cross-post-devto' ); ?>
                                    </option>
                                    <option value="draft" <?php selected( $s['default_status'], 'draft' ); ?>>
                                        <?php esc_html_e( 'Draft (review on Dev.to first)', 'cross-post-devto' ); ?>
                                    </option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Allowed Post Types', 'cross-post-devto' ); ?></th>
                            <td>
                                <?php foreach ( $post_types as $pt ) : ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input
                                            type="checkbox"
                                            name="post_types[]"
                                            value="<?php echo esc_attr( $pt->name ); ?>"
                                            <?php checked( in_array( $pt->name, (array) $s['post_types'], true ) ); ?>
                                        />
                                        <?php echo esc_html( $pt->label ); ?>
                                        <code><?php echo esc_html( $pt->name ); ?></code>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Exclude Categories', 'cross-post-devto' ); ?></th>
                            <td>
                                <?php foreach ( $categories as $cat ) : ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input
                                            type="checkbox"
                                            name="exclude_cats[]"
                                            value="<?php echo esc_attr( $cat->term_id ); ?>"
                                            <?php checked( in_array( (int) $cat->term_id, (array) $s['exclude_cats'], true ) ); ?>
                                        />
                                        <?php echo esc_html( $cat->name ); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description"><?php esc_html_e( 'Posts in these categories will not be synced.', 'cross-post-devto' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="organization_id"><?php esc_html_e( 'Default Dev.to Organization ID', 'cross-post-devto' ); ?></label></th>
                            <td>
                                <input
                                    type="text"
                                    id="organization_id"
                                    name="organization_id"
                                    value="<?php echo esc_attr( $s['organization_id'] ); ?>"
                                    class="regular-text"
                                    inputmode="numeric"
                                    placeholder="e.g. 42"
                                />
                                <p class="description">
                                    <?php esc_html_e( 'Optional. Publish articles under a Dev.to organization instead of your personal account. Find the numeric ID in your organization\'s Dev.to dashboard URL. Leave blank to publish personally. Individual posts can override this in the editor sidebar.', 'cross-post-devto' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ── Tag Mappings ────────────────────────────────────── -->
                <div class="devto-card">
                    <h2><?php esc_html_e( 'Tag Mappings', 'cross-post-devto' ); ?></h2>
                    <p class="description">
                        <?php esc_html_e( 'Map WordPress category/tag names to specific Dev.to tags. Dev.to tags must be lowercase, alphanumeric, no spaces. Max 4 tags per post.', 'cross-post-devto' ); ?>
                    </p>
                    <table class="widefat devto-tag-table" id="devto-tag-mappings">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'WordPress Term', 'cross-post-devto' ); ?></th>
                                <th><?php esc_html_e( 'Dev.to Tag', 'cross-post-devto' ); ?></th>
                                <th><?php esc_html_e( 'Remove', 'cross-post-devto' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( ! empty( $s['tag_mappings'] ) ) : ?>
                                <?php foreach ( $s['tag_mappings'] as $wp_term => $devto_tag ) : ?>
                                    <tr class="devto-mapping-row">
                                        <td><input type="text" name="tag_mappings[<?php echo esc_attr( $wp_term ); ?>]" value="<?php echo esc_attr( $devto_tag ); ?>" class="regular-text" placeholder="wp-term" /></td>
                                        <td><!-- key is editable via JS add row --><?php echo esc_html( $wp_term ); ?></td>
                                        <td><button type="button" class="button devto-remove-row">✕</button></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div style="margin-top:12px;" id="devto-add-mapping-area">
                        <input type="text" id="new-wp-term" placeholder="<?php esc_attr_e( 'WordPress term', 'cross-post-devto' ); ?>" class="regular-text" />
                        <input type="text" id="new-devto-tag" placeholder="<?php esc_attr_e( 'dev.to tag', 'cross-post-devto' ); ?>" class="regular-text" />
                        <button type="button" id="devto-add-mapping" class="button"><?php esc_html_e( '+ Add Mapping', 'cross-post-devto' ); ?></button>
                    </div>
                </div>

                <!-- ── Danger Zone ─────────────────────────────────────── -->
                <div class="devto-card devto-card-danger">
                    <h2><?php esc_html_e( 'Uninstall', 'cross-post-devto' ); ?></h2>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e( 'Delete Data on Uninstall', 'cross-post-devto' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( $s['delete_data_on_uninstall'] ); ?> />
                                    <?php esc_html_e( 'Permanently delete all plugin settings, sync logs, and post meta when this plugin is deleted', 'cross-post-devto' ); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e( 'Off by default. When off, deleting the plugin leaves your settings and sync history in the database so a reinstall picks up where you left off. This never deletes anything on Dev.to itself.', 'cross-post-devto' ); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( __( 'Save Settings', 'cross-post-devto' ) ); ?>
            </form>
        </div>
        <?php
    }
}
