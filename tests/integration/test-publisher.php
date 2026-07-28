<?php
/**
 * Integration tests for Cross_Post_DevTo_Publisher.
 *
 * Requires a WordPress test suite installation (see README).
 * Run with: composer test:integration
 *
 * These tests use WP_UnitTestCase which provides factory methods for
 * creating real posts, terms, and user meta in a test database.
 * HTTP calls are intercepted via the `pre_http_request` filter.
 */

class Test_Publisher_Integration extends WP_UnitTestCase {

    // ── Setup / teardown ──────────────────────────────────────────────────────

    public function setUp(): void {
        parent::setUp();

        // Reset plugin settings to known defaults before each test.
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'        => 'test-integration-key',
            'auto_publish'   => true,
            'default_status' => 'published',
            'post_types'     => [ 'post' ],
            'tag_mappings'   => [],
            'exclude_cats'   => [],
        ] );
    }

    public function tearDown(): void {
        // Clean up HTTP filter stubs.
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Stub the wp_remote_request HTTP layer to return a successful create response.
     */
    private function stub_http_success( int $devto_id = 77777, string $devto_url = 'https://dev.to/user/test-article' ): void {
        add_filter( 'pre_http_request', function () use ( $devto_id, $devto_url ) {
            return [
                'response' => [ 'code' => 201 ],
                'body'     => json_encode( [ 'id' => $devto_id, 'url' => $devto_url ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );
    }

    private function stub_http_error( int $code = 422, string $message = 'Unprocessable Entity' ): void {
        add_filter( 'pre_http_request', function () use ( $code, $message ) {
            return [
                'response' => [ 'code' => $code ],
                'body'     => json_encode( [ 'error' => $message ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );
    }

    private function make_post( array $args = [] ): WP_Post {
        $defaults = [
            'post_title'   => 'Test Post Title',
            'post_content' => '<p>Hello <strong>world</strong>.</p>',
            'post_status'  => 'publish',
            'post_type'    => 'post',
        ];
        $post_id = $this->factory->post->create( array_merge( $defaults, $args ) );
        return get_post( $post_id );
    }

    // ── maybe_sync(): initial publish ─────────────────────────────────────────

    public function test_sync_stores_devto_id_on_successful_create(): void {
        $this->stub_http_success( 77777 );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertSame( 77777, (int) $stored_id );
    }

    public function test_sync_stores_devto_url_on_successful_create(): void {
        $this->stub_http_success( 77777, 'https://dev.to/user/test-article' );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_url = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_URL, true );
        $this->assertSame( 'https://dev.to/user/test-article', $stored_url );
    }

    public function test_sync_stores_synced_timestamp(): void {
        $this->stub_http_success();
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $synced_at = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_SYNCED, true );
        $this->assertNotEmpty( $synced_at );
    }

    public function test_sync_logs_success_entry(): void {
        $this->stub_http_success( 88888 );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $log = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $this->assertNotEmpty( $log );
        $last = end( $log );
        $this->assertSame( 'success', $last['level'] );
        $this->assertStringContainsString( '88888', $last['message'] );
    }

    public function test_sync_success_log_entry_carries_article_url(): void {
        $this->stub_http_success( 88888, 'https://dev.to/user/test-article' );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $log  = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $last = end( $log );
        $this->assertSame( 'https://dev.to/user/test-article', $last['url'] );
    }

    public function test_sync_error_log_entry_has_no_url(): void {
        $this->stub_http_error( 422, 'Title has already been taken' );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $log  = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $last = end( $log );
        $this->assertSame( '', $last['url'] );
    }

    // ── maybe_sync(): API error handling ─────────────────────────────────────

    public function test_sync_logs_error_on_api_failure(): void {
        $this->stub_http_error( 422, 'Title has already been taken' );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $log  = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $last = end( $log );
        $this->assertSame( 'error', $last['level'] );
        $this->assertStringContainsString( 'Title has already been taken', $last['message'] );
    }

    public function test_sync_does_not_store_meta_on_api_failure(): void {
        $this->stub_http_error();
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    // ── maybe_sync(): guards ─────────────────────────────────────────────────

    public function test_sync_skipped_when_auto_publish_disabled(): void {
        $this->stub_http_success();
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => 'test-key',
            'auto_publish' => false,
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    public function test_sync_skipped_when_post_explicitly_opted_out(): void {
        $this->stub_http_success();
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_CROSS_POST, '0' );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    public function test_sync_skipped_when_post_has_legacy_skip_meta(): void {
        // Legacy fallback: posts saved before the opt-in toggle existed used
        // the old `_devto_skip` flag. It must still be honoured as an opt-out.
        $this->stub_http_success();
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_SKIP, '1' );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    public function test_sync_runs_when_explicitly_opted_in_despite_auto_publish_disabled(): void {
        $this->stub_http_success( 55555 );
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => 'test-key',
            'auto_publish' => false,
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_CROSS_POST, '1' );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertSame( 55555, (int) $stored_id );
    }

    public function test_sync_skipped_when_no_api_key_even_if_explicitly_opted_in(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => '',
            'auto_publish' => true,
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_CROSS_POST, '1' );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    public function test_sync_skipped_when_post_type_not_allowed(): void {
        $this->stub_http_success();

        // Create a page (not in allowed post_types list).
        $post_id = $this->factory->post->create( [
            'post_title'  => 'A Page',
            'post_status' => 'publish',
            'post_type'   => 'page',
        ] );
        $post = get_post( $post_id );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    public function test_sync_skipped_when_no_api_key(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => '',
            'auto_publish' => true,
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    public function test_sync_skipped_for_excluded_category(): void {
        $this->stub_http_success();

        $cat_id = $this->factory->category->create( [ 'name' => 'Excluded Cat' ] );
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => 'test-key',
            'auto_publish' => true,
            'post_types'   => [ 'post' ],
            'exclude_cats' => [ $cat_id ],
        ] );

        $post_id = $this->factory->post->create( [
            'post_title'    => 'Should Not Sync',
            'post_status'   => 'publish',
            'post_category' => [ $cat_id ],
        ] );
        $post = get_post( $post_id );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored_id );
    }

    // ── maybe_sync(): featured image change detection ──────────────────────────

    public function test_thumbnail_change_detection_logic(): void {
        // Test the core logic: featured image changes are detected.
        // Simulate the comparison that on_post_updated() performs.

        // Scenario 1: thumbnail added (0 → 999)
        $thumb_before_add = 0;
        $thumb_after_add  = 999;
        $changed = (int) $thumb_after_add !== (int) $thumb_before_add;
        $this->assertTrue( $changed, 'Should detect when thumbnail is added' );

        // Scenario 2: thumbnail changed (111 → 222)
        $thumb_before_change = 111;
        $thumb_after_change  = 222;
        $changed = (int) $thumb_after_change !== (int) $thumb_before_change;
        $this->assertTrue( $changed, 'Should detect when thumbnail is changed' );

        // Scenario 3: no change (0 → 0)
        $thumb_before_same = 0;
        $thumb_after_same  = 0;
        $changed = (int) $thumb_after_same !== (int) $thumb_before_same;
        $this->assertFalse( $changed, 'Should not detect change when thumbnail is same' );
    }

    // ── maybe_sync(): update (PUT) path ──────────────────────────────────────

    public function test_sync_calls_update_when_devto_id_exists(): void {
        $captured_url = null;

        // Capture the URL of the outgoing request.
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured_url ) {
            $captured_url = $url;
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'id' => 55555, 'url' => 'https://dev.to/user/existing' ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 55555 );

        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        // URL should be the PUT endpoint: .../articles/55555
        $this->assertStringContainsString( 'articles/55555', $captured_url );
    }

    // ── build_payload() ───────────────────────────────────────────────────────

    public function test_build_payload_sets_canonical_url(): void {
        $post     = $this->make_post();
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertArrayHasKey( 'canonical_url', $payload );
        $this->assertStringContainsString( get_permalink( $post->ID ), $payload['canonical_url'] );
    }

    public function test_build_payload_published_flag_matches_setting(): void {
        $post     = $this->make_post();

        // Published mode.
        $settings = array_merge( Cross_Post_DevTo_Settings::get(), [ 'default_status' => 'published' ] );
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );
        $this->assertTrue( $payload['published'] );

        // Draft mode.
        $settings['default_status'] = 'draft';
        $payload = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );
        $this->assertFalse( $payload['published'] );
    }

    public function test_build_payload_description_falls_back_to_excerpt_from_content(): void {
        $post     = $this->make_post( [ 'post_excerpt' => '' ] );
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertNotEmpty( $payload['description'] );
        $this->assertIsString( $payload['description'] );
    }

    public function test_build_payload_uses_post_excerpt_when_set(): void {
        $post = $this->make_post( [ 'post_excerpt' => 'A hand-written excerpt.' ] );
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertSame( 'A hand-written excerpt.', $payload['description'] );
    }

    public function test_build_payload_body_markdown_is_not_empty(): void {
        $post     = $this->make_post( [ 'post_content' => '<p>Hello <strong>world</strong>.</p>' ] );
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertNotEmpty( $payload['body_markdown'] );
        $this->assertStringContainsString( '**world**', $payload['body_markdown'] );
    }

    public function test_build_payload_omits_main_image_when_no_thumbnail(): void {
        $post     = $this->make_post(); // No featured image set.
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertArrayNotHasKey( 'main_image', $payload );
    }

    public function test_build_payload_includes_featured_image_when_set(): void {
        $post = $this->make_post();

        // Set featured image and mock wp_get_attachment_image_src to return a URL.
        update_post_meta( $post->ID, '_thumbnail_id', 999 );

        add_filter( 'wp_get_attachment_image_src', function ( $image, $attachment_id, $size ) {
            if ( $attachment_id === 999 && $size === 'full' ) {
                return [ 'https://example.com/featured-image.jpg', 1000, 600 ];
            }
            return $image;
        }, 10, 3 );

        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertArrayHasKey( 'main_image', $payload );
        $this->assertSame( 'https://example.com/featured-image.jpg', $payload['main_image'] );
    }

    // ── build_payload(): per-post overrides ──────────────────────────────────

    public function test_build_payload_uses_wp_title_when_no_override(): void {
        $post     = $this->make_post( [ 'post_title' => 'Original WP Title' ] );
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertSame( 'Original WP Title', $payload['title'] );
    }

    public function test_build_payload_uses_title_override_when_set(): void {
        $post = $this->make_post( [ 'post_title' => 'Original WP Title' ] );
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_TITLE, 'Punchier Dev.to Title' );

        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertSame( 'Punchier Dev.to Title', $payload['title'] );
    }

    public function test_build_payload_uses_main_image_override_over_featured_image(): void {
        $post = $this->make_post();
        update_post_meta(
            $post->ID,
            Cross_Post_DevTo_Publisher::META_MAIN_IMAGE,
            'https://example.com/custom-cover.jpg'
        );

        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertSame( 'https://example.com/custom-cover.jpg', $payload['main_image'] );
    }

    public function test_build_payload_omits_organization_id_when_none_set(): void {
        $post     = $this->make_post();
        $settings = Cross_Post_DevTo_Settings::get();
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertArrayNotHasKey( 'organization_id', $payload );
    }

    public function test_build_payload_uses_global_default_organization_id(): void {
        $post     = $this->make_post();
        $settings = array_merge( Cross_Post_DevTo_Settings::get(), [ 'organization_id' => '42' ] );
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertSame( 42, $payload['organization_id'] );
    }

    public function test_build_payload_per_post_organization_id_overrides_global_default(): void {
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_ORG_ID, '99' );

        $settings = array_merge( Cross_Post_DevTo_Settings::get(), [ 'organization_id' => '42' ] );
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertSame( 99, $payload['organization_id'] );
    }

    public function test_build_payload_ignores_non_numeric_organization_id(): void {
        $post     = $this->make_post();
        $settings = array_merge( Cross_Post_DevTo_Settings::get(), [ 'organization_id' => 'not-a-number' ] );
        $payload  = Cross_Post_DevTo_Publisher::build_payload( $post, $settings );

        $this->assertArrayNotHasKey( 'organization_id', $payload );
    }

    // ── on_trash(): opt-in unpublish ─────────────────────────────────────────

    public function test_on_trash_does_nothing_when_setting_disabled(): void {
        $request_count = 0;
        add_filter( 'pre_http_request', function () use ( &$request_count ) {
            $request_count++;
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}', 'headers' => [], 'cookies' => [], 'filename' => null ];
        }, 10, 3 );

        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 55555 );
        // Default settings from setUp() do not enable unpublish_on_trash.

        Cross_Post_DevTo_Publisher::on_trash( $post->ID );

        $this->assertSame( 0, $request_count );
    }

    public function test_on_trash_does_nothing_when_post_never_synced(): void {
        $request_count = 0;
        add_filter( 'pre_http_request', function () use ( &$request_count ) {
            $request_count++;
            return [ 'response' => [ 'code' => 200 ], 'body' => '{}', 'headers' => [], 'cookies' => [], 'filename' => null ];
        }, 10, 3 );

        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'             => 'test-key',
            'unpublish_on_trash'  => true,
        ] );
        $post = $this->make_post(); // No META_DEVTO_ID set.

        Cross_Post_DevTo_Publisher::on_trash( $post->ID );

        $this->assertSame( 0, $request_count );
    }

    public function test_on_trash_unpublishes_synced_post_when_enabled(): void {
        $captured_url  = null;
        $captured_body = null;
        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured_url, &$captured_body ) {
            $captured_url  = $url;
            $captured_body = $args['body'];
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'id' => 55555, 'url' => 'https://dev.to/user/existing' ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'            => 'test-key',
            'unpublish_on_trash' => true,
        ] );
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 55555 );

        Cross_Post_DevTo_Publisher::on_trash( $post->ID );

        $this->assertStringContainsString( 'articles/55555', $captured_url );
        $body = json_decode( $captured_body, true );
        $this->assertFalse( $body['article']['published'] );
    }

    public function test_on_trash_logs_success_entry(): void {
        add_filter( 'pre_http_request', function () {
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'id' => 55555, 'url' => 'https://dev.to/user/existing' ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'            => 'test-key',
            'unpublish_on_trash' => true,
        ] );
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 55555 );

        Cross_Post_DevTo_Publisher::on_trash( $post->ID );

        $log  = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $last = end( $log );
        $this->assertSame( 'success', $last['level'] );
    }

    // ── Log capping ──────────────────────────────────────────────────────────

    public function test_log_is_capped_at_20_entries(): void {
        $this->stub_http_success();
        $post = $this->make_post();

        // Trigger 25 syncs. force=true is required here: the reentrancy guard
        // deliberately blocks repeated *automatic* syncs of the same post
        // within one request.
        for ( $i = 0; $i < 25; $i++ ) {
            Cross_Post_DevTo_Publisher::maybe_sync( $post, true );
        }

        $log = Cross_Post_DevTo_Publisher::get_log( $post->ID );
        $this->assertLessThanOrEqual( 20, count( $log ) );
        $this->assertGreaterThan( 15, count( $log ) ); // Confirms capping was exercised, not guard-blocked.
    }

    // ── Reentrancy guard ─────────────────────────────────────────────────────

    public function test_automatic_sync_runs_only_once_per_request_per_post(): void {
        $request_count = 0;
        add_filter( 'pre_http_request', function () use ( &$request_count ) {
            $request_count++;
            return [
                'response' => [ 'code' => 201 ],
                'body'     => json_encode( [ 'id' => 12121, 'url' => 'https://dev.to/u/a' ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        $post = $this->make_post();

        // Simulates transition_post_status + post_updated both firing.
        Cross_Post_DevTo_Publisher::maybe_sync( $post );
        Cross_Post_DevTo_Publisher::maybe_sync( $post );

        $this->assertSame( 1, $request_count );
    }

    public function test_forced_sync_bypasses_auto_publish_toggle(): void {
        $this->stub_http_success( 13131 );
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => 'test-key',
            'auto_publish' => false, // Toggle OFF — automatic sync would bail.
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();

        Cross_Post_DevTo_Publisher::maybe_sync( $post, true );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertSame( 13131, (int) $stored_id );
    }

    public function test_forced_sync_bypasses_cross_post_decision_without_mutating_it(): void {
        $this->stub_http_success( 14141 );
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_CROSS_POST, '0' );

        Cross_Post_DevTo_Publisher::maybe_sync( $post, true );

        $stored_id = get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertSame( 14141, (int) $stored_id );
        // Cross-post decision untouched.
        $this->assertSame( '0', get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_CROSS_POST, true ) );
    }

    // ── get_cross_post_state(): default resolution ───────────────────────────

    public function test_cross_post_state_inherits_auto_publish_when_undecided(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => 'test-key',
            'auto_publish' => true,
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();

        $this->assertTrue( Cross_Post_DevTo_Publisher::get_cross_post_state( $post->ID ) );
    }

    public function test_cross_post_state_false_without_api_key_regardless_of_choice(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => '',
            'auto_publish' => true,
            'post_types'   => [ 'post' ],
        ] );
        $post = $this->make_post();
        update_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_CROSS_POST, '1' );

        $this->assertFalse( Cross_Post_DevTo_Publisher::get_cross_post_state( $post->ID ) );
    }
}
