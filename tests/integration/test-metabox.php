<?php
/**
 * Integration tests for Cross_Post_DevTo_Metabox::save().
 *
 * Requires a WordPress test suite installation (see README).
 * Run with: composer test:integration
 */

class Test_Metabox_Integration extends WP_UnitTestCase {

    public function setUp(): void {
        parent::setUp();

        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'      => 'test-integration-key',
            'auto_publish' => true,
            'post_types'   => [ 'post' ],
        ] );
    }

    public function tearDown(): void {
        unset( $_POST );
        parent::tearDown();
    }

    private function make_post(): WP_Post {
        $post_id = $this->factory->post->create( [
            'post_title'  => 'Metabox Test Post',
            'post_status' => 'publish',
        ] );
        return get_post( $post_id );
    }

    private function submit_nonce(): void {
        $_POST['cross_post_devto_mb_nonce'] = wp_create_nonce( 'cross_post_devto_metabox' );
    }

    // ── Per-post overrides persistence ───────────────────────────────────────

    public function test_save_persists_title_override(): void {
        $post = $this->make_post();
        $this->submit_nonce();
        $_POST['devto_title_override'] = 'Custom Dev.to Title';

        Cross_Post_DevTo_Metabox::save( $post->ID, $post );

        $this->assertSame(
            'Custom Dev.to Title',
            get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_TITLE, true )
        );
    }

    public function test_save_persists_main_image_override(): void {
        $post = $this->make_post();
        $this->submit_nonce();
        $_POST['devto_main_image_override'] = 'https://example.com/cover.jpg';

        Cross_Post_DevTo_Metabox::save( $post->ID, $post );

        $this->assertSame(
            'https://example.com/cover.jpg',
            get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_MAIN_IMAGE, true )
        );
    }

    public function test_save_persists_numeric_organization_id(): void {
        $post = $this->make_post();
        $this->submit_nonce();
        $_POST['devto_organization_id'] = '123';

        Cross_Post_DevTo_Metabox::save( $post->ID, $post );

        $this->assertSame(
            '123',
            get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_ORG_ID, true )
        );
    }

    public function test_save_discards_non_numeric_organization_id(): void {
        $post = $this->make_post();
        $this->submit_nonce();
        $_POST['devto_organization_id'] = 'not-a-number';

        Cross_Post_DevTo_Metabox::save( $post->ID, $post );

        $this->assertSame(
            '',
            get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_ORG_ID, true )
        );
    }

    public function test_save_without_nonce_does_not_persist_overrides(): void {
        $post = $this->make_post();
        // No nonce submitted.
        $_POST['devto_title_override'] = 'Should Not Save';

        Cross_Post_DevTo_Metabox::save( $post->ID, $post );

        $this->assertSame(
            '',
            get_post_meta( $post->ID, Cross_Post_DevTo_Publisher::META_TITLE, true )
        );
    }
}
