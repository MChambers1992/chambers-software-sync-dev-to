<?php
/**
 * Unit tests for Cross_Post_DevTo_Settings::get().
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_Settings extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_returns_defaults_when_nothing_stored(): void {
        Functions\when( 'get_option' )->justReturn( [] );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );

        $settings = Cross_Post_DevTo_Settings::get();

        $this->assertSame( '', $settings['api_key'] );
        $this->assertTrue( $settings['auto_publish'] );
        $this->assertSame( 'published', $settings['default_status'] );
        $this->assertSame( [ 'post' ], $settings['post_types'] );
        $this->assertSame( [], $settings['tag_mappings'] );
        $this->assertSame( [], $settings['exclude_cats'] );
    }

    public function test_get_merges_stored_values_over_defaults(): void {
        Functions\when( 'get_option' )->justReturn( [
            'api_key'      => 'stored-key',
            'auto_publish' => false,
        ] );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );

        $settings = Cross_Post_DevTo_Settings::get();

        $this->assertSame( 'stored-key', $settings['api_key'] );
        $this->assertFalse( $settings['auto_publish'] );
        // Untouched keys still fall back to defaults.
        $this->assertSame( 'published', $settings['default_status'] );
    }

    public function test_option_key_matches_activation_hook_option_name(): void {
        // Guards against the two names drifting apart again after a rename.
        $this->assertSame( 'cross_post_devto_settings', Cross_Post_DevTo_Settings::OPTION_KEY );
    }

    // ── sanitize_organization_id() ───────────────────────────────────────────

    public function test_sanitize_organization_id_accepts_numeric_string(): void {
        $this->assertSame( '42', Cross_Post_DevTo_Settings::sanitize_organization_id( '42' ) );
    }

    public function test_sanitize_organization_id_rejects_non_numeric_string(): void {
        $this->assertSame( '', Cross_Post_DevTo_Settings::sanitize_organization_id( 'not-a-number' ) );
    }

    public function test_sanitize_organization_id_rejects_empty_string(): void {
        $this->assertSame( '', Cross_Post_DevTo_Settings::sanitize_organization_id( '' ) );
    }

    public function test_sanitize_organization_id_rejects_mixed_alphanumeric(): void {
        $this->assertSame( '', Cross_Post_DevTo_Settings::sanitize_organization_id( '42abc' ) );
    }

    public function test_sanitize_organization_id_accepts_numeric_string_with_leading_zero(): void {
        // is_numeric() accepts this; Dev.to's API will interpret it as an int
        // either way, so there's no reason to reject it here.
        $this->assertSame( '007', Cross_Post_DevTo_Settings::sanitize_organization_id( '007' ) );
    }
}
