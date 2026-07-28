/* global jQuery, crossPostDevTo */
( function ( $ ) {
    'use strict';

    // ── API key test ─────────────────────────────────────────────────────────
    $( '#devto-test-key' ).on( 'click', function () {
        var $btn    = $( this );
        var $result = $( '#devto-test-result' );
        var apiKey  = $( '#api_key' ).val().trim();

        if ( ! apiKey ) {
            $result.text( 'Please enter an API key first.' ).removeClass( 'success error' ).addClass( 'error' );
            return;
        }

        $btn.prop( 'disabled', true ).text( 'Testing…' );
        $result.text( '' ).removeClass( 'success error' );

        $.post( crossPostDevTo.ajaxUrl, {
            action:  'cross_post_devto_validate_key',
            nonce:   crossPostDevTo.nonce,
            api_key: apiKey,
        } )
        .done( function ( response ) {
            if ( response.success ) {
                $result.text( '✓ ' + response.data ).addClass( 'success' );
            } else {
                $result.text( '✗ ' + response.data ).addClass( 'error' );
            }
        } )
        .fail( function () {
            $result.text( '✗ Request failed.' ).addClass( 'error' );
        } )
        .always( function () {
            $btn.prop( 'disabled', false ).text( 'Test Connection' );
        } );
    } );

    // ── Tag mapping table: add row ───────────────────────────────────────────
    $( '#devto-add-mapping' ).on( 'click', function () {
        var wpTerm  = $( '#new-wp-term' ).val().trim();
        var devtoTag = $( '#new-devto-tag' ).val().trim().toLowerCase().replace( /[^a-z0-9]/g, '' );

        if ( ! wpTerm || ! devtoTag ) {
            alert( 'Please fill in both the WordPress term and the Dev.to tag.' );
            return;
        }

        var row = '<tr class="devto-mapping-row">'
            + '<td><input type="text" name="tag_mappings[' + wpTerm + ']" value="' + devtoTag + '" class="regular-text" /></td>'
            + '<td>' + wpTerm + '</td>'
            + '<td><button type="button" class="button devto-remove-row">✕</button></td>'
            + '</tr>';

        $( '#devto-tag-mappings tbody' ).append( row );
        $( '#new-wp-term' ).val( '' );
        $( '#new-devto-tag' ).val( '' );
    } );

    // ── Tag mapping table: remove row ────────────────────────────────────────
    $( document ).on( 'click', '.devto-remove-row', function () {
        $( this ).closest( 'tr' ).remove();
    } );

    // ── Dev.to tag input: normalise in real-time ─────────────────────────────
    $( '#new-devto-tag' ).on( 'input', function () {
        var val = $( this ).val().toLowerCase().replace( /[^a-z0-9]/g, '' );
        $( this ).val( val );
    } );

} )( jQuery );
