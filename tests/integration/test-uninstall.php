<?php
/**
 * Integration tests for uninstall.php.
 *
 * Requires a WordPress test suite installation (see README).
 * Run with: composer test:integration
 */

class Test_Uninstall_Integration extends WP_UnitTestCase {

    public function tearDown(): void {
        if ( defined( 'WP_UNINSTALL_PLUGIN' ) === false ) {
            // Nothing to undefine — constants can't be undefined in PHP anyway,
            // but guard the intent here so a future refactor notices if that changes.
            null;
        }
        parent::tearDown();
    }

    private function run_uninstall(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            define( 'WP_UNINSTALL_PLUGIN', true );
        }
        require CROSS_POST_DEVTO_PATH . 'uninstall.php';
    }

    public function test_uninstall_leaves_data_when_not_opted_in(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'                  => 'test-key',
            'delete_data_on_uninstall' => false,
        ] );
        $post_id = $this->factory->post->create();
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 12345 );
        update_option( 'devto_log_' . $post_id, [ [ 'time' => 'x', 'level' => 'success', 'message' => 'ok', 'url' => '' ] ] );

        $this->run_uninstall();

        $this->assertNotEmpty( get_option( Cross_Post_DevTo_Settings::OPTION_KEY ) );
        $this->assertSame( '12345', get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true ) );
        $this->assertNotEmpty( get_option( 'devto_log_' . $post_id ) );
    }

    public function test_uninstall_deletes_settings_option_when_opted_in(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'api_key'                  => 'test-key',
            'delete_data_on_uninstall' => true,
        ] );

        $this->run_uninstall();

        $this->assertFalse( get_option( Cross_Post_DevTo_Settings::OPTION_KEY ) );
    }

    public function test_uninstall_deletes_post_meta_when_opted_in(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'delete_data_on_uninstall' => true,
        ] );
        $post_id = $this->factory->post->create();
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, 12345 );
        update_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_TITLE, 'Custom Title' );

        $this->run_uninstall();

        $this->assertSame( '', get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_DEVTO_ID, true ) );
        $this->assertSame( '', get_post_meta( $post_id, Cross_Post_DevTo_Publisher::META_TITLE, true ) );
    }

    public function test_uninstall_deletes_sync_log_options_when_opted_in(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'delete_data_on_uninstall' => true,
        ] );
        $post_id = $this->factory->post->create();
        update_option( 'devto_log_' . $post_id, [ [ 'time' => 'x', 'level' => 'success', 'message' => 'ok', 'url' => '' ] ] );

        $this->run_uninstall();

        $this->assertFalse( get_option( 'devto_log_' . $post_id ) );
    }

    public function test_uninstall_does_not_touch_unrelated_options(): void {
        update_option( Cross_Post_DevTo_Settings::OPTION_KEY, [
            'delete_data_on_uninstall' => true,
        ] );
        update_option( 'some_unrelated_plugin_option', 'keep-me' );

        $this->run_uninstall();

        $this->assertSame( 'keep-me', get_option( 'some_unrelated_plugin_option' ) );
    }
}
