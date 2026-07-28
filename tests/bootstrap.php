<?php
/**
 * PHPUnit bootstrap for Cross Post for Dev.to tests.
 *
 * Unit tests (tests/unit/) use Brain\Monkey to mock WordPress functions —
 * they do NOT need a real WP install or database.
 *
 * Integration tests (tests/integration/) use the official WordPress test
 * suite which requires:
 *   1. Run bin/install-wp-tests.sh first (see README).
 *   2. WP_TESTS_DIR env var pointing to the checked-out test lib.
 *   3. A real MySQL database for the test suite.
 *
 * The bootstrap detects which suite is running based on WP_TESTS_DIR.
 */

// ── Composer autoloader ───────────────────────────────────────────────────────
$autoloader = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoloader ) ) {
    echo "Run `composer install` before running tests.\n";
    exit( 1 );
}
require_once $autoloader;

// ── Determine test mode ───────────────────────────────────────────────────────
$wp_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( $wp_tests_dir && is_dir( $wp_tests_dir ) ) {
    // ── Integration mode: full WordPress test suite ───────────────────────────
    define( 'CROSS_POST_DEVTO_TESTS_MODE', 'integration' );

    // WP core's own bootstrap (required below) loads wp-tests-config.php,
    // which defines ABSPATH itself — do not predefine it here, or WP core
    // hits a "Constant ABSPATH already defined" notice trying to redefine it.

    // WP core's test suite bootstrap requires the PHPUnit Polyfills bridge
    // library (github.com/Yoast/PHPUnit-Polyfills) and loads it itself once
    // this constant points at where composer installed it.
    if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
        define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills' );
    }

    // Load WP test functions (gives us WP_UnitTestCase etc.).
    require_once $wp_tests_dir . '/includes/functions.php';

    // Load the plugin before WP sets up so hooks are registered.
    tests_add_filter( 'muplugins_loaded', function () {
        // Define plugin constants manually (normally set by plugin_dir_path).
        if ( ! defined( 'CROSS_POST_DEVTO_VERSION' ) ) {
            define( 'CROSS_POST_DEVTO_VERSION', '1.0.0' );
            define( 'CROSS_POST_DEVTO_PATH', dirname( __DIR__ ) . '/' );
            define( 'CROSS_POST_DEVTO_URL', 'http://example.org/wp-content/plugins/cross-post-devto/' );
        }
        require_once CROSS_POST_DEVTO_PATH . 'includes/class-devto-api.php';
        require_once CROSS_POST_DEVTO_PATH . 'includes/class-publisher.php';
        require_once CROSS_POST_DEVTO_PATH . 'includes/class-settings.php';
        require_once CROSS_POST_DEVTO_PATH . 'includes/class-metabox.php';
    } );

    require_once $wp_tests_dir . '/includes/bootstrap.php';

} else {
    // ── Unit mode: Brain\Monkey mocks all WP functions ────────────────────────
    define( 'CROSS_POST_DEVTO_TESTS_MODE', 'unit' );

    // Stub ABSPATH so the `defined('ABSPATH') || exit` guards pass.
    define( 'ABSPATH', '/fake/abspath/' );

    // Stub plugin constants.
    define( 'CROSS_POST_DEVTO_VERSION', '1.0.0' );
    define( 'CROSS_POST_DEVTO_PATH', dirname( __DIR__ ) . '/' );
    define( 'CROSS_POST_DEVTO_URL', 'http://example.org/wp-content/plugins/cross-post-devto/' );

    // Load plugin classes directly (no WP bootstrap).
    require_once CROSS_POST_DEVTO_PATH . 'includes/class-devto-api.php';
    require_once CROSS_POST_DEVTO_PATH . 'includes/class-publisher.php';
    require_once CROSS_POST_DEVTO_PATH . 'includes/class-settings.php';
    require_once CROSS_POST_DEVTO_PATH . 'includes/class-metabox.php';

    // Stub WP_Error for unit tests — real class not available without WP.
    if ( ! class_exists( 'WP_Error' ) ) {
        class WP_Error {
            public string $code;
            public string $message;
            public array  $data;

            public function __construct( string $code = '', string $message = '', $data = [] ) {
                $this->code    = $code;
                $this->message = $message;
                $this->data    = (array) $data;
            }

            public function get_error_message(): string { return $this->message; }
            public function get_error_code(): string    { return $this->code; }
            public function get_error_data()            { return $this->data; }
        }
    }

    // Stub WP_Post for unit tests.
    if ( ! class_exists( 'WP_Post' ) ) {
        class WP_Post {
            public int    $ID            = 0;
            public string $post_title    = '';
            public string $post_content  = '';
            public string $post_excerpt  = '';
            public string $post_status   = 'publish';
            public string $post_type     = 'post';

            public function __construct( array $data = [] ) {
                foreach ( $data as $key => $value ) {
                    $this->$key = $value;
                }
            }
        }
    }
}
