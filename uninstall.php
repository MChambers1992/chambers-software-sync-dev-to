<?php
/**
 * Uninstall handler for Cross Post for Dev.to.
 *
 * WordPress runs this file when the plugin is deleted through the Plugins
 * screen (not on deactivation). Data is only removed when the user has
 * explicitly opted in via the "Delete plugin data on uninstall" setting —
 * silently wiping sync history and settings on every uninstall would be
 * worse than leaving a few options behind for a reinstall.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-publisher.php';

if ( ! function_exists( 'cross_post_devto_run_uninstall' ) ) {
    /**
     * Wrapped in a function so its working variables ($settings, $meta_keys,
     * $meta_key) are properly function-scoped rather than sitting in the
     * global scope, which is what a plain top-level script would do.
     * Guarded against redeclaration since tests deliberately `require` (not
     * `require_once`) this file fresh for each test case.
     */
    function cross_post_devto_run_uninstall() {
        $settings = get_option( Cross_Post_DevTo_Settings::OPTION_KEY, [] );

        if ( empty( $settings['delete_data_on_uninstall'] ) ) {
            return;
        }

        global $wpdb;

        // Plugin settings.
        delete_option( Cross_Post_DevTo_Settings::OPTION_KEY );

        // Per-post sync logs are dynamically-named options
        // (devto_log_{post_id}), so they have to be found by pattern rather
        // than a known key — there's no delete_option() equivalent for
        // pattern matching. Deleted one at a time via delete_option() rather
        // than a single raw DELETE query, since a raw query bypasses the
        // object cache entirely and leaves get_option() serving stale
        // cached values for the rest of the request.
        $log_option_names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'devto_log_' ) . '%'
            )
        );
        foreach ( $log_option_names as $option_name ) {
            delete_option( $option_name );
        }

        // Post meta written by the plugin, including the deprecated legacy key.
        $meta_keys = [
            Cross_Post_DevTo_Publisher::META_DEVTO_ID,
            Cross_Post_DevTo_Publisher::META_DEVTO_URL,
            Cross_Post_DevTo_Publisher::META_DEVTO_SYNCED,
            Cross_Post_DevTo_Publisher::META_CROSS_POST,
            Cross_Post_DevTo_Publisher::META_TITLE,
            Cross_Post_DevTo_Publisher::META_MAIN_IMAGE,
            Cross_Post_DevTo_Publisher::META_ORG_ID,
            Cross_Post_DevTo_Publisher::META_SKIP,
        ];

        foreach ( $meta_keys as $meta_key ) {
            $wpdb->delete( $wpdb->postmeta, [ 'meta_key' => $meta_key ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
        }
    }
}

cross_post_devto_run_uninstall();
