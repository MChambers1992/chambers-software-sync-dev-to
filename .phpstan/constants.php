<?php
/**
 * PHPStan-only stub: defines this plugin's runtime constants.
 *
 * cross-post-devto.php only defines these when actually loaded inside
 * WordPress (behind `defined('ABSPATH') || exit;`), so PHPStan's static
 * analysis of includes/*.php in isolation has no way to know they exist.
 * This file is loaded via phpstan.neon.dist's bootstrapFiles — real values
 * don't matter, only that the constant names exist so every reference to
 * them doesn't get flagged as "Constant ... not found".
 *
 * Never shipped in the plugin — see .distignore.
 */

define( 'CROSS_POST_DEVTO_VERSION', '1.0.0' );
define( 'CROSS_POST_DEVTO_PATH', __DIR__ . '/' );
define( 'CROSS_POST_DEVTO_URL', 'https://example.com/wp-content/plugins/cross-post-devto/' );
define( 'CROSS_POST_DEVTO_BASENAME', 'cross-post-devto/cross-post-devto.php' );
