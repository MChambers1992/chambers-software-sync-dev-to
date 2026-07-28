/* global jQuery */
( function ( $ ) {
    'use strict';

    // Delegated: the click target is replaced with fresh server-rendered
    // markup after every sync, so a plain .on( 'click' ) binding on the
    // original element would only ever fire once.
    $( document ).on( 'click', '#devto-sync-now', function () {
        var $btn    = $( this );
        var $wrap   = $btn.closest( '.devto-mb-wrap' );
        var $status = $wrap.find( '#devto-sync-status' );
        var postId  = $btn.data( 'post-id' );
        var nonce   = $btn.data( 'nonce' );

        // Preserve any not-yet-saved change to the cross-post toggle —
        // re-rendering the panel below would otherwise silently revert it
        // to the last-saved value.
        var $checkbox      = $wrap.find( '#devto_cross_post' );
        var checkboxTouched = $checkbox.length > 0;
        var wasChecked      = checkboxTouched && $checkbox.prop( 'checked' );

        $btn.prop( 'disabled', true );
        $status.text( 'Syncing…' ).removeClass( 'success error' ).addClass( 'syncing' );

        $.post( ajaxurl, {
            action:  'cross_post_devto_sync_now',
            nonce:   nonce,
            post_id: postId,
        } )
        .done( function ( response ) {
            var data = response.data || {};

            if ( data.html ) {
                $wrap.replaceWith( data.html );

                if ( checkboxTouched ) {
                    $( '#devto_cross_post' ).prop( 'checked', wasChecked );
                }

                var $newStatus = $( '#devto-sync-status' );
                if ( response.success ) {
                    $newStatus.text( '✓ ' + ( data.message || 'Synced.' ) ).addClass( 'success' );
                } else {
                    $newStatus.text( '✗ ' + ( data.message || 'Sync failed.' ) ).addClass( 'error' );
                }
            } else {
                // No panel to swap in (e.g. permission denied) — just report and re-enable.
                $status.text( '✗ ' + ( data.message || 'Sync failed.' ) ).removeClass( 'syncing success' ).addClass( 'error' );
                $btn.prop( 'disabled', false );
            }
        } )
        .fail( function () {
            $status.text( '✗ Request failed.' ).removeClass( 'syncing success' ).addClass( 'error' );
            $btn.prop( 'disabled', false );
        } );
    } );

} )( jQuery );
