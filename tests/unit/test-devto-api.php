<?php
/**
 * Unit tests for Cross_Post_DevTo_API.
 *
 * HTTP requests are intercepted via the `pre_http_request` filter so no
 * real network calls are made. Each test sets up the filter, runs the
 * assertion, then removes it.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_DevTo_API extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Brain\Monkey mocks apply_filters/add_filter etc.
        // We also need wp_json_encode and wp_remote_request stubs.
        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( '__' )->returnArg( 1 );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a fake wp_remote_request() response array.
     */
    private function fake_response( int $code, array $body ): array {
        return [
            'response' => [ 'code' => $code, 'message' => 'OK' ],
            'body'     => json_encode( $body ),
            'headers'  => [],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * Stub wp_remote_request to return a fixed response.
     */
    private function mock_http( int $code, array $body ): void {
        $response = $this->fake_response( $code, $body );

        Functions\when( 'wp_remote_request' )->justReturn( $response );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( $code );
        Functions\when( 'wp_remote_retrieve_body' )->justReturn( json_encode( $body ) );
    }

    private function mock_http_error( string $message ): void {
        $error = new WP_Error( 'http_request_failed', $message );
        Functions\when( 'wp_remote_request' )->justReturn( $error );
        Functions\when( 'is_wp_error' )->justReturn( true );
    }

    // ── No API key ────────────────────────────────────────────────────────────

    public function test_create_article_returns_error_when_no_api_key(): void {
        $api    = new Cross_Post_DevTo_API( '' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'no_api_key', $result->get_error_code() );
    }

    public function test_validate_key_returns_error_when_no_api_key(): void {
        $api    = new Cross_Post_DevTo_API( '' );
        $result = $api->validate_key();

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ── create_article() ─────────────────────────────────────────────────────

    public function test_create_article_returns_article_data_on_success(): void {
        $this->mock_http( 201, [ 'id' => 12345, 'url' => 'https://dev.to/user/my-article' ] );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [
            'title'         => 'My Article',
            'body_markdown' => '# Hello',
            'published'     => true,
            'canonical_url' => 'https://example.com/my-article',
        ] );

        $this->assertIsArray( $result );
        $this->assertSame( 12345, $result['id'] );
        $this->assertSame( 'https://dev.to/user/my-article', $result['url'] );
    }

    public function test_create_article_returns_wp_error_on_4xx(): void {
        $this->mock_http( 422, [ 'error' => 'Title has already been taken', 'status' => 422 ] );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Duplicate Title' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( 'Title has already been taken', $result->get_error_message() );
    }

    public function test_create_article_returns_wp_error_on_401(): void {
        $this->mock_http( 401, [ 'error' => 'Unauthorized' ] );

        $api    = new Cross_Post_DevTo_API( 'invalid-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_create_article_returns_wp_error_on_500(): void {
        $this->mock_http( 500, [] );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( 'HTTP 500', $result->get_error_message() );
    }

    public function test_create_article_returns_wp_error_on_network_failure(): void {
        $this->mock_http_error( 'cURL error 6: Could not resolve host' );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ── update_article() ─────────────────────────────────────────────────────

    public function test_update_article_returns_updated_data_on_success(): void {
        $this->mock_http( 200, [ 'id' => 12345, 'url' => 'https://dev.to/user/my-article' ] );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->update_article( 12345, [ 'title' => 'Updated Title' ] );

        $this->assertIsArray( $result );
        $this->assertSame( 12345, $result['id'] );
    }

    public function test_update_article_returns_wp_error_when_not_found(): void {
        $this->mock_http( 404, [ 'error' => 'Not found' ] );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->update_article( 99999, [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ── validate_key() ───────────────────────────────────────────────────────

    public function test_validate_key_returns_user_data_on_success(): void {
        $this->mock_http( 200, [ 'username' => 'michaelchambers', 'id' => 456 ] );

        $api    = new Cross_Post_DevTo_API( 'valid-key' );
        $result = $api->validate_key();

        $this->assertIsArray( $result );
        $this->assertSame( 'michaelchambers', $result['username'] );
    }

    public function test_validate_key_returns_wp_error_for_bad_key(): void {
        $this->mock_http( 401, [ 'error' => 'Unauthorized' ] );

        $api    = new Cross_Post_DevTo_API( 'bad-key' );
        $result = $api->validate_key();

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    // ── get_article() ────────────────────────────────────────────────────────

    public function test_get_article_returns_article_data(): void {
        $this->mock_http( 200, [ 'id' => 12345, 'title' => 'My Article', 'published' => true ] );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->get_article( 12345 );

        $this->assertIsArray( $result );
        $this->assertSame( 12345, $result['id'] );
        $this->assertTrue( $result['published'] );
    }

    // ── Rate limiting (429) ──────────────────────────────────────────────────

    public function test_create_article_returns_rate_limited_error_on_429(): void {
        $this->mock_http( 429, [ 'error' => 'Too Many Requests' ] );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'devto_rate_limited', $result->get_error_code() );
    }

    public function test_rate_limit_error_includes_retry_after_seconds_in_message(): void {
        $this->mock_http( 429, [] );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '30' );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertStringContainsString( '30', $result->get_error_message() );
    }

    public function test_rate_limit_error_data_carries_retry_after(): void {
        $this->mock_http( 429, [] );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '30' );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertSame( '30', $result->get_error_data()['retry_after'] );
        $this->assertSame( 429, $result->get_error_data()['status'] );
    }

    public function test_rate_limit_error_without_retry_after_header_still_returns_error(): void {
        $this->mock_http( 429, [] );
        Functions\when( 'wp_remote_retrieve_header' )->justReturn( '' );

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertNull( $result->get_error_data()['retry_after'] );
    }

    // ── Error message fallback ────────────────────────────────────────────────

    public function test_error_without_error_key_uses_http_status(): void {
        $this->mock_http( 503, [] ); // Body has no 'error' key.

        $api    = new Cross_Post_DevTo_API( 'test-api-key' );
        $result = $api->create_article( [ 'title' => 'Test' ] );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( 'HTTP 503', $result->get_error_message() );
    }
}
