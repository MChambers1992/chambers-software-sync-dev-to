/* global jQuery, crossPostDevToBulk */
( function ( $ ) {
    'use strict';

    // ── State ─────────────────────────────────────────────────────────────────
    var allPosts       = [];   // Full list returned by fetch
    var selectedIds    = [];   // IDs currently checked
    var inProgress     = false;
    var counts         = { synced: 0, skipped: 0, failed: 0 };

    // ── DOM refs ──────────────────────────────────────────────────────────────
    var $tableWrap     = $( '#devto-bulk-table-wrap' );
    var $tbody         = $( '#devto-bulk-tbody' );
    var $loadBtn       = $( '#devto-bulk-load' );
    var $syncBtn       = $( '#devto-sync-selected' );
    var $selectAll     = $( '#devto-select-all' );
    var $progressWrap  = $( '#devto-progress-wrap' );
    var $progressBar   = $( '#devto-progress-bar' );
    var $progressLabel = $( '#devto-progress-label' );
    var $loadingEl     = $( '#devto-bulk-loading' );
    var $errorEl       = $( '#devto-bulk-error' );
    var $errorMsg      = $( '#devto-bulk-error-msg' );
    var $bulkCount     = $( '#devto-bulk-count' );

    // ── Load posts ────────────────────────────────────────────────────────────
    $loadBtn.on( 'click', function () {
        if ( inProgress ) { return; }
        loadPosts();
    } );

    // Pressing Enter in the search field triggers a load.
    $( '#bulk-search' ).on( 'keydown', function ( e ) {
        if ( e.key === 'Enter' ) { loadPosts(); }
    } );

    function loadPosts() {
        $tableWrap.hide();
        $progressWrap.hide();
        $errorEl.hide();
        $loadingEl.show();
        $loadBtn.prop( 'disabled', true );

        $.post( crossPostDevToBulk.ajaxUrl, {
            action:      crossPostDevToBulk.ajaxFetch || 'cross_post_devto_bulk_fetch',
            nonce:       crossPostDevToBulk.nonceFetch,
            post_type:   $( '#bulk-post-type' ).val(),
            sync_status: $( '#bulk-sync-status' ).val(),
            search:      $( '#bulk-search' ).val().trim(),
        } )
        .done( function ( response ) {
            if ( ! response.success ) {
                showError( response.data || crossPostDevToBulk.strings.loadError );
                return;
            }
            allPosts = response.data.posts;
            renderTable( allPosts );
        } )
        .fail( function () {
            showError( crossPostDevToBulk.strings.loadError );
        } )
        .always( function () {
            $loadingEl.hide();
            $loadBtn.prop( 'disabled', false );
        } );
    }

    // ── Render table ──────────────────────────────────────────────────────────
    function renderTable( posts ) {
        $tbody.empty();
        selectedIds = [];
        $selectAll.prop( 'checked', false );
        updateSyncBtn();

        if ( ! posts.length ) {
            $tbody.append(
                '<tr><td colspan="6" style="text-align:center;padding:20px;color:#787c82;">'
                + escHtml( crossPostDevToBulk.strings.noResults || 'No posts found matching your filters.' )
                + '</td></tr>'
            );
            $bulkCount.text( '' );
            $tableWrap.show();
            return;
        }

        $.each( posts, function ( i, post ) {
            $tbody.append( buildRow( post ) );
        } );

        $bulkCount.text(
            posts.length === 1
                ? '1 post'
                : posts.length + ' posts'
        );

        $tableWrap.show();
        counts = { synced: 0, skipped: 0, failed: 0 };
        resetProgressDisplay();
    }

    function buildRow( post ) {
        var isSynced          = !! post.devto_id;
        var isCrossPostEnabled = post.cross_post;

        var statusHtml = isSynced
            ? '<span class="devto-row-status synced">✓ Synced'
              + ( post.devto_url
                  ? ' <a href="' + escAttr( post.devto_url ) + '" target="_blank" rel="noopener">↗</a>'
                  : '' )
              + '</span>'
            : '<span class="devto-row-status unsynced">○ Not synced</span>';

        if ( ! isCrossPostEnabled ) {
            statusHtml += ' <span class="devto-row-skipped-flag">(cross-posting disabled)</span>';
        }

        var $row = $(
            '<tr data-post-id="' + post.id + '">'
            + '<td class="col-check"><input type="checkbox" class="devto-row-check" value="' + post.id + '" /></td>'
            + '<td class="col-title">'
              + '<a href="' + escAttr( post.edit_url || '#' ) + '">' + escHtml( post.title || '(no title)' ) + '</a>'
            + '</td>'
            + '<td class="col-type">' + escHtml( post.post_type ) + '</td>'
            + '<td class="col-date">' + escHtml( post.date ) + '</td>'
            + '<td class="col-status">' + statusHtml + '</td>'
            + '<td class="col-result" id="result-' + post.id + '"></td>'
            + '</tr>'
        );

        return $row;
    }

    // ── Row checkbox handling ─────────────────────────────────────────────────
    $tbody.on( 'change', '.devto-row-check', function () {
        var id = parseInt( $( this ).val(), 10 );
        if ( $( this ).is( ':checked' ) ) {
            if ( selectedIds.indexOf( id ) === -1 ) { selectedIds.push( id ); }
        } else {
            selectedIds = selectedIds.filter( function ( v ) { return v !== id; } );
        }
        $selectAll.prop( 'checked', selectedIds.length === allPosts.length && allPosts.length > 0 );
        updateSyncBtn();
    } );

    $selectAll.on( 'change', function () {
        var checked = $( this ).is( ':checked' );
        $tbody.find( '.devto-row-check' ).prop( 'checked', checked );
        selectedIds = checked ? allPosts.map( function ( p ) { return p.id; } ) : [];
        updateSyncBtn();
    } );

    function updateSyncBtn() {
        $syncBtn.prop( 'disabled', selectedIds.length === 0 || inProgress );
        if ( selectedIds.length > 0 ) {
            $syncBtn.text( '↑ Sync ' + selectedIds.length + ' Post' + ( selectedIds.length !== 1 ? 's' : '' ) );
        } else {
            $syncBtn.text( '↑ Sync Selected' );
        }
    }

    // ── Sync selected ─────────────────────────────────────────────────────────
    $syncBtn.on( 'click', function () {
        if ( inProgress || ! selectedIds.length ) { return; }

        if ( ! window.confirm(
            crossPostDevToBulk.strings.confirmStart.replace( '%d', selectedIds.length )
        ) ) { return; }

        startSync( selectedIds.slice() );
    } );

    function startSync( ids ) {
        inProgress = true;
        counts     = { synced: 0, skipped: 0, failed: 0 };
        var total  = ids.length;
        var done   = 0;
        var resync = $( '#bulk-resync' ).is( ':checked' ) ? '1' : '0';

        $loadBtn.prop( 'disabled', true );
        $syncBtn.prop( 'disabled', true );
        $progressWrap.show();
        $( '#devto-progress-bar' ).css( 'width', '0%' );
        updateCountDisplay();

        var batchSize = parseInt( crossPostDevToBulk.batchSize, 10 ) || 10;
        var batches   = [];
        for ( var i = 0; i < ids.length; i += batchSize ) {
            batches.push( ids.slice( i, i + batchSize ) );
        }

        function processBatch( batchIndex ) {
            if ( batchIndex >= batches.length ) {
                finishSync( total );
                return;
            }

            var batchIds = batches[ batchIndex ];
            $progressLabel.text(
                crossPostDevToBulk.strings.syncing
                    .replace( '%1$d', Math.min( done + batchSize, total ) )
                    .replace( '%2$d', total )
            );

            $.post( crossPostDevToBulk.ajaxUrl, {
                action:   'cross_post_devto_bulk_process',
                nonce:    crossPostDevToBulk.nonceProcess,
                post_ids: JSON.stringify( batchIds ),
                resync:   resync,
            } )
            .done( function ( response ) {
                if ( ! response.success ) {
                    // Non-fatal: mark all in this batch as failed, continue.
                    $.each( batchIds, function ( i, id ) {
                        setRowResult( id, 'error', response.data || 'Batch error' );
                        counts.failed++;
                    } );
                } else {
                    $.each( response.data.results, function ( postId, result ) {
                        setRowResult( parseInt( postId, 10 ), result.status, result.message, result.devto_url );
                        if ( result.status === 'synced' )   { counts.synced++; }
                        if ( result.status === 'skipped' )  { counts.skipped++; }
                        if ( result.status === 'error' )    { counts.failed++; }
                    } );
                }

                done += batchIds.length;
                var pct = Math.round( ( done / total ) * 100 );
                $progressBar.css( 'width', pct + '%' );
                updateCountDisplay();

                // Throttle slightly between batches to be kind to the server.
                setTimeout( function () {
                    processBatch( batchIndex + 1 );
                }, 300 );
            } )
            .fail( function () {
                // Network failure on this batch — mark as failed, continue.
                $.each( batchIds, function ( i, id ) {
                    setRowResult( id, 'error', 'Network error' );
                    counts.failed++;
                } );
                done += batchIds.length;
                updateCountDisplay();
                setTimeout( function () {
                    processBatch( batchIndex + 1 );
                }, 500 );
            } );
        }

        processBatch( 0 );
    }

    function finishSync( total ) {
        inProgress = false;
        $loadBtn.prop( 'disabled', false );
        updateSyncBtn();
        $progressBar.css( 'width', '100%' );
        $progressLabel.html(
            '<strong>'
            + crossPostDevToBulk.strings.done
                .replace( '%1$d', counts.synced )
                .replace( '%2$d', counts.skipped )
                .replace( '%3$d', counts.failed )
            + '</strong>'
        );
    }

    // ── Row result update ─────────────────────────────────────────────────────
    function setRowResult( postId, status, message, devtoUrl ) {
        var $cell = $( '#result-' + postId );
        var html;

        if ( status === 'synced' ) {
            html = '<span class="devto-result synced">✓ '
                + escHtml( message )
                + ( devtoUrl
                    ? ' <a href="' + escAttr( devtoUrl ) + '" target="_blank" rel="noopener">View ↗</a>'
                    : '' )
                + '</span>';
        } else if ( status === 'skipped' ) {
            html = '<span class="devto-result skipped">– ' + escHtml( message ) + '</span>';
        } else {
            html = '<span class="devto-result error">✗ ' + escHtml( message ) + '</span>';
        }

        $cell.html( html );

        // Highlight the row briefly.
        var $row = $cell.closest( 'tr' );
        $row.addClass( 'devto-row-flash-' + status );
        setTimeout( function () { $row.removeClass( 'devto-row-flash-' + status ); }, 1500 );
    }

    // ── Progress display ──────────────────────────────────────────────────────
    function updateCountDisplay() {
        $( '#count-synced' ).text( counts.synced );
        $( '#count-skipped' ).text( counts.skipped );
        $( '#count-failed' ).text( counts.failed );
    }

    function resetProgressDisplay() {
        $progressWrap.hide();
        $progressBar.css( 'width', '0%' );
        $progressLabel.text( '' );
        updateCountDisplay();
    }

    // ── Error display ─────────────────────────────────────────────────────────
    function showError( msg ) {
        $errorMsg.text( msg );
        $errorEl.show();
    }

    // ── Utilities ─────────────────────────────────────────────────────────────
    function escHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' );
    }

    function escAttr( str ) {
        return escHtml( str );
    }

} )( jQuery );
