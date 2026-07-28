<?php
/**
 * Unit tests for tag normalisation and resolution logic in Cross_Post_DevTo_Publisher.
 *
 * Tests both normalise_tag() and resolve_tags() via Reflection since they're private.
 * WP taxonomy functions are mocked with Brain\Monkey.
 */

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

class Test_Tag_Normalisation extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function normalise( string $tag ): string {
        $method = new ReflectionMethod( Cross_Post_DevTo_Publisher::class, 'normalise_tag' );
        $method->setAccessible( true );
        return $method->invoke( null, $tag );
    }

    private function resolve( WP_Post $post, array $settings ): array {
        $method = new ReflectionMethod( Cross_Post_DevTo_Publisher::class, 'resolve_tags' );
        $method->setAccessible( true );
        return $method->invoke( null, $post, $settings );
    }

    private function make_post( array $data = [] ): WP_Post {
        return new WP_Post( array_merge( [ 'ID' => 1, 'post_type' => 'post' ], $data ) );
    }

    // ── normalise_tag() ───────────────────────────────────────────────────────

    public function test_normalise_lowercases(): void {
        $this->assertSame( 'php', $this->normalise( 'PHP' ) );
        $this->assertSame( 'javascript', $this->normalise( 'JavaScript' ) );
    }

    public function test_normalise_strips_spaces(): void {
        $this->assertSame( 'webdevelopment', $this->normalise( 'web development' ) );
    }

    public function test_normalise_strips_hyphens(): void {
        $this->assertSame( 'openai', $this->normalise( 'open-ai' ) );
    }

    public function test_normalise_strips_special_characters(): void {
        $this->assertSame( 'csharp', $this->normalise( 'C#' ) );
        $this->assertSame( 'aspnet', $this->normalise( 'ASP.NET' ) );
        $this->assertSame( 'nodejs', $this->normalise( 'Node.js' ) );
    }

    public function test_normalise_preserves_numbers(): void {
        $this->assertSame( 'php8', $this->normalise( 'php8' ) );
        $this->assertSame( 'es2022', $this->normalise( 'ES2022' ) );
    }

    public function test_normalise_empty_string_returns_empty(): void {
        $this->assertSame( '', $this->normalise( '' ) );
    }

    public function test_normalise_all_special_chars_returns_empty(): void {
        $this->assertSame( '', $this->normalise( '!@#$%^&*()' ) );
    }

    // ── resolve_tags() ────────────────────────────────────────────────────────

    public function test_resolve_returns_normalised_wp_tags(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [ 'PHP', 'WordPress' ] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [] );

        $tags = $this->resolve( $post, [ 'tag_mappings' => [] ] );

        $this->assertContains( 'php', $tags );
        $this->assertContains( 'wordpress', $tags );
    }

    public function test_resolve_includes_categories(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [ 'Tutorials' ] );

        $tags = $this->resolve( $post, [ 'tag_mappings' => [] ] );

        $this->assertContains( 'tutorials', $tags );
    }

    public function test_resolve_respects_mapping_override(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [ 'C#' ] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [] );

        $tags = $this->resolve( $post, [ 'tag_mappings' => [ 'C#' => 'csharp' ] ] );

        $this->assertContains( 'csharp', $tags );
        $this->assertNotContains( 'c', $tags ); // Without mapping, C# → 'c' (just 'c' after strip)
    }

    public function test_resolve_caps_at_four_tags(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [ 'Tag1', 'Tag2', 'Tag3', 'Tag4', 'Tag5' ] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [] );

        $tags = $this->resolve( $post, [ 'tag_mappings' => [] ] );

        $this->assertCount( 4, $tags );
    }

    public function test_resolve_deduplicates_tags(): void {
        $post = $this->make_post();

        // 'php' appears as both a tag and a category name.
        Functions\when( 'wp_get_post_tags' )->justReturn( [ 'PHP' ] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [ 'php' ] );

        $tags = $this->resolve( $post, [ 'tag_mappings' => [] ] );

        $this->assertCount( 1, $tags );
        $this->assertSame( [ 'php' ], $tags );
    }

    public function test_resolve_returns_empty_array_for_untagged_post(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [] );

        $tags = $this->resolve( $post, [ 'tag_mappings' => [] ] );

        $this->assertSame( [], $tags );
    }

    public function test_resolve_skips_empty_mapped_tags(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [ 'Tutorials' ] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [] );

        // Empty string mapping means "use the normalised term name" (mapping is ignored).
        $tags = $this->resolve( $post, [ 'tag_mappings' => [ 'Tutorials' => '' ] ] );

        // When mapping value is empty, the normalised original is used.
        $this->assertContains( 'tutorials', $tags );
    }

    public function test_resolve_handles_unicode_term_names(): void {
        $post = $this->make_post();

        Functions\when( 'wp_get_post_tags' )->justReturn( [ 'Développement' ] );
        Functions\when( 'wp_get_post_categories' )->justReturn( [] );

        // Non-ASCII chars should be stripped; result may be empty or partial.
        $tags = $this->resolve( $post, [ 'tag_mappings' => [] ] );

        // Whatever comes back must be valid Dev.to tag format.
        foreach ( $tags as $tag ) {
            $this->assertMatchesRegularExpression( '/^[a-z0-9]*$/', $tag );
        }
    }
}
