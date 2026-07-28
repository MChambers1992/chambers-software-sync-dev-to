<?php
/**
 * Integration tests for Cross_Post_DevTo_Bulk_Sync.
 *
 * Requires the WordPress test suite (see README).
 * Run with: composer test:integration
 */

class Test_Bulk_Sync_Integration extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();

        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'        => 'test-bulk-key',
            'auto_publish'   => true,
            'default_status' => 'published',
            'post_types'     => [ 'post' ],
            'tag_mappings'   => [],
            'exclude_cats'   => [],
        ] );
    }

    public function tearDown(): void {
        remove_all_filters( 'pre_http_request' );
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function stub_http_success( int $devto_id = 11111 ): void {
        add_filter( 'pre_http_request', function () use ( $devto_id ) {
            return [
                'response' => [ 'code' => 201 ],
                'body'     => json_encode( [
                    'id'  => $devto_id,
                    'url' => 'https://dev.to/user/test-' . $devto_id,
                ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );
    }

    private function stub_http_error( string $message = 'Unprocessable' ): void {
        add_filter( 'pre_http_request', function () use ( $message ) {
            return [
                'response' => [ 'code' => 422 ],
                'body'     => json_encode( [ 'error' => $message ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );
    }

    private function make_published_post( array $args = [] ): int {
        return $this->factory->post->create( array_merge( [
            'post_title'   => 'Bulk Test Post',
            'post_content' => '<p>Content here.</p>',
            'post_status'  => 'publish',
        ], $args ) );
    }

    /**
     * Call the private sync_single method via Reflection.
     */
    private function sync_single( int $post_id, array $settings, bool $resync ): array {
        $method = new ReflectionMethod( Cross_Post_DevTo_Bulk_Sync::class, 'sync_single' );
        $method->setAccessible( true );
        return $method->invoke( null, $post_id, $settings, $resync );
    }

    // ── sync_single(): unsynced post ─────────────────────────────────────────

    public function test_unsynced_post_is_synced_successfully(): void {
        $this->stub_http_success( 22222 );
        $post_id  = $this->make_published_post();
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, false );

        $this->assertSame( 'synced', $result['status'] );
        $this->assertNotEmpty( $result['message'] );
    }

    public function test_unsynced_post_stores_devto_id_after_sync(): void {
        $this->stub_http_success( 33333 );
        $post_id  = $this->make_published_post();
        $settings = Cross_Post_DevTo_Settings::get();

        $this->sync_single( $post_id, $settings, false );

        $stored = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertSame( 33333, (int) $stored );
    }

    public function test_unsynced_post_stores_devto_url_after_sync(): void {
        $this->stub_http_success( 44444 );
        $post_id  = $this->make_published_post();
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, false );

        $this->assertNotNull( $result['devto_url'] );
        $this->assertStringContainsString( 'dev.to', $result['devto_url'] );
    }

    // ── sync_single(): already-synced post, resync=false ─────────────────────

    public function test_already_synced_post_is_skipped_when_resync_false(): void {
        $post_id  = $this->make_published_post();
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 55555 );
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, false );

        $this->assertSame( 'skipped', $result['status'] );
        $this->assertStringContainsString( '55555', $result['message'] );
    }

    public function test_already_synced_post_skip_message_suggests_resync_option(): void {
        $post_id  = $this->make_published_post();
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 66666 );
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, false );

        $this->assertStringContainsString( 'Re-sync', $result['message'] );
    }

    // ── sync_single(): already-synced post, resync=true ──────────────────────

    public function test_already_synced_post_is_updated_when_resync_true(): void {
        $captured_method = null;

        add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$captured_method ) {
            $captured_method = $args['method'];
            return [
                'response' => [ 'code' => 200 ],
                'body'     => json_encode( [ 'id' => 77777, 'url' => 'https://dev.to/user/resync' ] ),
                'headers'  => [],
                'cookies'  => [],
                'filename' => null,
            ];
        }, 10, 3 );

        $post_id  = $this->make_published_post();
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 77777 );
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, true );

        $this->assertSame( 'synced', $result['status'] );
        // Should have issued a PUT (update), not a POST.
        $this->assertSame( 'PUT', $captured_method );
    }

    // ── sync_single(): API error ──────────────────────────────────────────────

    public function test_api_error_returns_error_status(): void {
        $this->stub_http_error( 'Title has already been taken' );
        $post_id  = $this->make_published_post();
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, false );

        $this->assertSame( 'error', $result['status'] );
        $this->assertStringContainsString( 'Title has already been taken', $result['message'] );
    }

    public function test_api_error_does_not_store_devto_id(): void {
        $this->stub_http_error();
        $post_id  = $this->make_published_post();
        $settings = Cross_Post_DevTo_Settings::get();

        $this->sync_single( $post_id, $settings, false );

        $stored = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true );
        $this->assertEmpty( $stored );
    }

    // ── sync_single(): non-published post ────────────────────────────────────

    public function test_draft_post_returns_skipped(): void {
        $post_id = $this->factory->post->create( [
            'post_status' => 'draft',
            'post_title'  => 'Unpublished',
        ] );
        $settings = Cross_Post_DevTo_Settings::get();

        $result = $this->sync_single( $post_id, $settings, false );

        $this->assertSame( 'skipped', $result['status'] );
    }

    public function test_nonexistent_post_id_returns_skipped(): void {
        $settings = Cross_Post_DevTo_Settings::get();
        $result   = $this->sync_single( 9999999, $settings, false );

        $this->assertSame( 'skipped', $result['status'] );
    }

    // ── sync_single(): cross-post decision is preserved ──────────────────────

    public function test_cross_post_decision_is_restored_after_bulk_sync(): void {
        $this->stub_http_success();
        $post_id  = $this->make_published_post();
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_CROSS_POST, '0' );
        $settings = Cross_Post_DevTo_Settings::get();

        $this->sync_single( $post_id, $settings, false );

        // Explicit opt-out should still be '0' after bulk sync completes
        // (bulk sync is a forced, intentional override — it doesn't mutate it).
        $decision = get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_CROSS_POST, true );
        $this->assertSame( '0', $decision );
    }

    // ── ajax_fetch_posts(): post list filtering ───────────────────────────────

    public function test_fetch_returns_only_published_posts(): void {
        $published_id = $this->make_published_post( [ 'post_title' => 'Published' ] );
        $draft_id     = $this->factory->post->create( [
            'post_status' => 'draft',
            'post_title'  => 'Draft',
        ] );

        // Simulate what ajax_fetch_posts queries.
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        $this->assertContains( $published_id, $posts );
        $this->assertNotContains( $draft_id, $posts );
    }

    public function test_fetch_unsynced_filter_excludes_synced_posts(): void {
        $synced_id   = $this->make_published_post( [ 'post_title' => 'Synced' ] );
        $unsynced_id = $this->make_published_post( [ 'post_title' => 'Unsynced' ] );
        update_post_meta( $synced_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 88888 );

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ [ 'relation' => 'OR',
                [ 'key' => Cross_Post_DevTo_Publisher::META_DEVTO_ID, 'compare' => 'NOT EXISTS' ],
                [ 'key' => Cross_Post_DevTo_Publisher::META_DEVTO_ID, 'value' => '', 'compare' => '=' ],
            ] ],
        ] );

        $this->assertNotContains( $synced_id, $posts );
        $this->assertContains( $unsynced_id, $posts );
    }

    public function test_fetch_synced_filter_includes_only_synced_posts(): void {
        $synced_id   = $this->make_published_post( [ 'post_title' => 'Synced' ] );
        $unsynced_id = $this->make_published_post( [ 'post_title' => 'Unsynced' ] );
        update_post_meta( $synced_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 99999 );

        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [ [
                'key'     => Cross_Post_DevTo_Publisher::META_DEVTO_ID,
                'compare' => 'EXISTS',
            ] ],
        ] );

        $this->assertContains( $synced_id, $posts );
        $this->assertNotContains( $unsynced_id, $posts );
    }
}
