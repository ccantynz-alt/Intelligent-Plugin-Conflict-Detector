/* global jQuery, ipcdData */
( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Tab switching
	// -------------------------------------------------------------------------
	$( '.ipcd-tab' ).on( 'click', function ( e ) {
		var href = $( this ).attr( 'href' );
		if ( ! href || href.charAt( 0 ) !== '#' ) {
			return; // External link (settings page) – let it through.
		}
		e.preventDefault();
		$( '.ipcd-tab' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		$( '.ipcd-tab-content' ).removeClass( 'active' );
		$( href ).addClass( 'active' );
	} );

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Show a notice in the notice area.
	 *
	 * @param {string} message  HTML message.
	 * @param {string} type     'success' | 'error' | 'warning' | 'info'.
	 */
	function showNotice( message, type ) {
		type = type || 'info';
		var $notice = $( '<div class="notice notice-' + type + ' is-dismissible ipcd-notice"><p>' + message + '</p></div>' );
		var $area   = $( '#ipcd-notice-area' );
		$area.empty().append( $notice );
		// Scroll to the notice.
		$( 'html, body' ).animate( { scrollTop: $area.offset().top - 40 }, 300 );
		// Auto-dismiss after 5 s.
		setTimeout( function () { $notice.fadeOut( 300, function () { $( this ).remove(); } ); }, 5000 );
	}

	/**
	 * Standard AJAX call wrapper.
	 *
	 * @param {string}   action    WP AJAX action.
	 * @param {Object}   data      Additional POST data.
	 * @param {Function} onSuccess Success callback (response.data).
	 * @param {Function} onError   Error callback (response.data.message or WP_Error).
	 */
	function ipcdAjax( action, data, onSuccess, onError ) {
		var payload = $.extend( {}, data, {
			action: action,
			nonce:  ipcdData.nonce,
		} );

		$.post( ipcdData.ajaxUrl, payload )
			.done( function ( response ) {
				if ( response && response.success ) {
					if ( typeof onSuccess === 'function' ) {
						onSuccess( response.data );
					}
				} else {
					var msg = ( response && response.data && response.data.message )
						? response.data.message
						: ipcdData.strings.error;
					if ( typeof onError === 'function' ) {
						onError( msg );
					} else {
						showNotice( msg, 'error' );
					}
				}
			} )
			.fail( function () {
				var msg = ipcdData.strings.error;
				if ( typeof onError === 'function' ) {
					onError( msg );
				} else {
					showNotice( msg, 'error' );
				}
			} );
	}

	// -------------------------------------------------------------------------
	// Rollback
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.ipcd-rollback-btn', function () {
		var snapshotId = $( this ).data( 'snapshot-id' );
		if ( ! window.confirm( ipcdData.strings.confirmRollback ) ) {
			return;
		}

		var $btn = $( this );
		$btn.prop( 'disabled', true ).html( '<span class="ipcd-spinner"></span>' + ipcdData.strings.rollingBack );

		ipcdAjax(
			'ipcd_rollback',
			{ snapshot_id: snapshotId },
			function ( data ) {
				showNotice( data.message, 'success' );
				$btn.prop( 'disabled', false ).text( '⏪ Rollback' );
			},
			function ( msg ) {
				showNotice( msg, 'error' );
				$btn.prop( 'disabled', false ).text( '⏪ Rollback' );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Resolve conflict
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.ipcd-resolve-btn', function () {
		var conflictId = $( this ).data( 'conflict-id' );
		var $row       = $( this ).closest( 'tr' );
		var $btn       = $( this );

		$btn.prop( 'disabled', true );

		ipcdAjax(
			'ipcd_resolve_conflict',
			{ conflict_id: conflictId },
			function ( data ) {
				showNotice( data.message, 'success' );
				$row.fadeOut( 300, function () { $( this ).remove(); } );
				// Update badge count.
				var $badge = $( '.ipcd-tab[href="#tab-conflicts"] .ipcd-badge--error' );
				var count  = parseInt( $badge.text(), 10 ) - 1;
				if ( count > 0 ) {
					$badge.text( count );
				} else {
					$badge.remove();
				}
			},
			function ( msg ) {
				showNotice( msg, 'error' );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Run test now
	// -------------------------------------------------------------------------
	$( '#ipcd-run-test-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).html( '<span class="ipcd-spinner"></span>' + ipcdData.strings.runningTest );

		ipcdAjax(
			'ipcd_run_test',
			{ plugin: '', event: 'manual' },
			function ( data ) {
				showNotice( data.message, 'success' );
				$btn.prop( 'disabled', false ).text( 'Run Test Now' );
			},
			function ( msg ) {
				showNotice( msg, 'error' );
				$btn.prop( 'disabled', false ).text( 'Run Test Now' );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Create snapshot
	// -------------------------------------------------------------------------
	$( '#ipcd-create-snapshot-btn' ).on( 'click', function () {
		var label = window.prompt( 'Enter a label for this snapshot (optional):', 'manual_snapshot' );
		if ( null === label ) {
			return; // Cancelled.
		}

		var $btn = $( this );
		$btn.prop( 'disabled', true );

		ipcdAjax(
			'ipcd_create_snapshot',
			{ label: label || 'manual_snapshot' },
			function ( data ) {
				showNotice( data.message + ' ID: ' + data.snapshot_id, 'success' );
				$btn.prop( 'disabled', false );
				// Reload to show the new snapshot.
				setTimeout( function () { window.location.reload(); }, 1500 );
			},
			function ( msg ) {
				showNotice( msg, 'error' );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Delete snapshot
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.ipcd-delete-snapshot-btn', function () {
		if ( ! window.confirm( 'Delete this snapshot? This cannot be undone.' ) ) {
			return;
		}

		var snapshotId = $( this ).data( 'snapshot-id' );
		var $row       = $( this ).closest( 'tr' );

		ipcdAjax(
			'ipcd_delete_snapshot',
			{ snapshot_id: snapshotId },
			function ( data ) {
				showNotice( data.message, 'success' );
				$row.fadeOut( 300, function () { $( this ).remove(); } );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Clear all conflicts
	// -------------------------------------------------------------------------
	$( '#ipcd-clear-conflicts-btn, #ipcd-clear-all-conflicts-btn' ).on( 'click', function () {
		if ( ! window.confirm( ipcdData.strings.confirmClear ) ) {
			return;
		}

		ipcdAjax(
			'ipcd_clear_conflicts',
			{},
			function ( data ) {
				showNotice( data.message, 'success' );
				setTimeout( function () { window.location.reload(); }, 1500 );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Save settings
	// -------------------------------------------------------------------------
	$( '#ipcd-settings-form' ).on( 'submit', function ( e ) {
		e.preventDefault();

		var $btn  = $( '#ipcd-save-settings-btn' );
		var data  = {};

		$( this ).serializeArray().forEach( function ( item ) {
			data[ item.name ] = item.value;
		} );

		// Include unchecked checkboxes as 0.
		if ( ! data.email_notifications ) { data.email_notifications = 0; }
		if ( ! data.auto_rollback )       { data.auto_rollback       = 0; }

		$btn.prop( 'disabled', true ).text( ipcdData.strings.saving );

		ipcdAjax(
			'ipcd_save_settings',
			data,
			function ( resp ) {
				showNotice( resp.message, 'success' );
				$btn.prop( 'disabled', false ).text( 'Save Settings' );
			},
			function ( msg ) {
				showNotice( msg, 'error' );
				$btn.prop( 'disabled', false ).text( 'Save Settings' );
			}
		);
	} );

} )( jQuery );
