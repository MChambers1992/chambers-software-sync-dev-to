<?php
/**
 * Plugin Name: Chambers Software Sync for Dev.to
 * Plugin URI:  https://github.com/MChambers1992/cross-post-devto
 * Description: Automatically cross-posts WordPress content to Dev.to with canonical URL support, tag mapping, and edit sync.
 * Version:     1.0.1
 * Author:      Michael Chambers
 * License:     GPL-2.0+
 * Text Domain: chambers-software-sync-dev-to
 */

defined( 'ABSPATH' ) || exit;

define( 'CROSS_POST_DEVTO_VERSION', '1.0.1' );
define( 'CROSS_POST_DEVTO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CROSS_POST_DEVTO_URL', plugin_dir_url( __FILE__ ) );
define( 'CROSS_POST_DEVTO_BASENAME', plugin_basename( __FILE__ ) );

require_once CROSS_POST_DEVTO_PATH . 'includes/class-devto-api.php';
require_once CROSS_POST_DEVTO_PATH . 'includes/class-publisher.php';
require_once CROSS_POST_DEVTO_PATH . 'includes/class-settings.php';
require_once CROSS_POST_DEVTO_PATH . 'includes/class-metabox.php';
require_once CROSS_POST_DEVTO_PATH . 'includes/class-bulk-sync.php';

/**
 * Initialise the plugin.
 */
function cross_post_devto_init() {
    Cross_Post_DevTo_Settings::init();
    Cross_Post_DevTo_Metabox::init();
    Cross_Post_DevTo_Publisher::init();
    Cross_Post_DevTo_Bulk_Sync::init();
}
add_action( 'plugins_loaded', 'cross_post_devto_init' );

/**
 * Create options table row on activation.
 */
function cross_post_devto_activate() {
    add_option( 'cross_post_devto_settings', [
        'api_key'          => '',
        'auto_publish'     => true,
        'default_status'   => 'published',
        'post_types'       => [ 'post' ],
        'tag_mappings'     => [],
        'exclude_cats'     => [],
    ] );
}
register_activation_hook( __FILE__, 'cross_post_devto_activate' );
