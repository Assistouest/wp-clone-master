/**
 * schedule-tab.js — Automatic backup tab for Clone Master.
 *
 * Loaded AFTER admin.js. Patches window.__wpcmApp (a sentinel the modified
 * admin.js exposes) to inject the ScheduleTab into the running React app.
 *
 * Strategy: we re-render the root element with a new AppWithSchedule wrapper
 * that mirrors AppFinal but includes the extra tab.
 */
(function () {
    'use strict';

    if ( ! wp || ! wp.element ) return;

    const { createElement: h, useState, useEffect, useCallback, Fragment } = wp.element;
    const { __ } = wp.i18n;

    /* =========================================================
       Server capabilities — resolved once from wpcmData
       ========================================================= */
    var HAS_OPENSSL  = wpcmData.hasOpenssl  !== false;   // false only when PHP openssl ext absent
    var SERVER_TYPE  = wpcmData.serverType  || 'unknown'; // 'nginx' | 'apache' | 'litespeed' | 'unknown'

    /* =========================================================
       API helper — robust, reads text first like admin.js
       ========================================================= */
    function api( action, data ) {
        data = data || {};
        var fd = new FormData();
        fd.append( 'action', action );
        fd.append( 'nonce', wpcmData.nonce );
        Object.keys( data ).forEach( function ( k ) {
            if ( data[ k ] !== undefined && data[ k ] !== null ) {
                fd.append( k, String( data[ k ] ) );
            }
        } );
        return fetch( wpcmData.ajaxUrl, { method: 'POST', body: fd } )
            .then( function ( r ) {
                return r.text().then( function ( text ) {
                    if ( text === '-1' || text === '0' ) {
                        throw new Error( __( 'Security check failed — reload the page.', 'clone-master' ) );
                    }
                    var json;
                    try { json = JSON.parse( text ); } catch ( e ) {
                        var msg = 'Non-JSON response from server (HTTP ' + r.status + '). ';
                        if ( text.indexOf( 'Fatal error' )  !== -1 ) msg += __( 'Fatal PHP error detected. ', 'clone-master' );
                        if ( text.indexOf( 'Parse error' )  !== -1 ) msg += __( 'PHP parse error. ', 'clone-master' );
                        if ( text.indexOf( 'Maximum execution time' ) !== -1 ) msg += 'PHP execution time exceeded. ';
                        if ( text.indexOf( 'Allowed memory size' )    !== -1 ) msg += 'Insufficient PHP memory. ';
                        if ( ! text.trim() ) { msg += 'Empty response.'; }
                        else { msg += 'Extrait : ' + text.substring( 0, 200 ).replace( /<[^>]+>/g, '' ).trim(); }
                        throw new Error( msg );
                    }
                    if ( ! json || ! json.success ) {
                        var d = json && json.data;
                        throw new Error( String( ( d && typeof d === 'object' ? d.message : d ) || __( 'Server error', 'clone-master' ) ) );
                    }
                    return json.data;
                } );
            } );
    }

    /* =========================================================
       Tiny icon helper (inline SVG paths reused from admin.js)
       ========================================================= */
    var ICO_PATHS = {
        clock:   [ 'circle|cx=12|cy=12|r=10', 'polyline|points=12 6 12 12 16 14' ],
        check:   [ 'polyline|points=20 6 9 17 4 12' ],
        x:       [ 'line|x1=18|y1=6|x2=6|y2=18', 'line|x1=6|y1=6|x2=18|y2=18' ],
        warn:    [ 'path|d=M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z', 'line|x1=12|y1=9|x2=12|y2=13', 'line|x1=12|y1=17|x2=12.01|y2=17' ],
        play:    [ 'polygon|points=5 3 19 12 5 21 5 3' ],
        trash:   [ 'polyline|points=3 6 5 6 21 6', 'path|d=M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2' ],
        refresh: [ 'polyline|points=23 4 23 10 17 10', 'path|d=M20.49 15a9 9 0 11-2.12-9.36L23 10' ],
        info:    [ 'circle|cx=12|cy=12|r=10', 'line|x1=12|y1=8|x2=12|y2=12', 'line|x1=12|y1=16|x2=12.01|y2=16' ],
        save:    [ 'path|d=M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z', 'polyline|points=17 21 17 13 7 13 7 21', 'polyline|points=7 3 7 8 15 8' ],
    };

    function Ico( props ) {
        var n = props.n; var s = props.s || 16;
        var elems = ( ICO_PATHS[ n ] || [] ).map( function ( spec, i ) {
            var parts = spec.split( '|' );
            var tag   = parts[ 0 ];
            var attrs = { key: i };
            parts.slice( 1 ).forEach( function ( p ) {
                var kv = p.split( '=' ); attrs[ kv[ 0 ] ] = kv.slice( 1 ).join( '=' );
            } );
            return h( tag, attrs );
        } );
        return h( 'svg', {
            width: s, height: s, viewBox: '0 0 24 24',
            fill: 'none', stroke: 'currentColor', strokeWidth: 2,
            strokeLinecap: 'round', strokeLinejoin: 'round',
            style: { display: 'inline-block', verticalAlign: 'middle', flexShrink: 0 }
        }, elems );
    }

    /* =========================================================
       Helpers
       ========================================================= */
    function formatDuration( sec ) {
        sec = parseInt( sec, 10 );
        if ( isNaN( sec ) || sec < 0 ) return '—';
        if ( sec < 60 ) return sec + 's';
        var m = Math.floor( sec / 60 );
        var s = sec % 60;
        return m + 'min ' + ( s > 0 ? s + 's' : '' );
    }

    function formatDate( str ) {
        if ( ! str ) return '—';
        return str;
    }

    var FREQ_LABELS = {
        hourly:     __( 'Every hour', 'clone-master' ),
        twicedaily: __( 'Twice daily', 'clone-master' ),
        daily:      __( 'Every day', 'clone-master' ),
        weekly:     __( 'Every week', 'clone-master' ),
        monthly:    __( 'Every month', 'clone-master' ),
    };

    /* =========================================================
       RunningTimer — live elapsed seconds counter shown in the button
       ========================================================= */
    function RunningTimer() {
        var [ elapsed, setElapsed ] = useState( 0 );
        useEffect( function () {
            var t = setInterval( function () {
                setElapsed( function ( s ) { return s + 1; } );
            }, 1000 );
            return function () { clearInterval( t ); };
        }, [] );
        return h( 'span', null, __( ' Backup in progress… ', 'clone-master' ) + formatDuration( elapsed ) );
    }

    /* =========================================================
       ScheduleTab
       ========================================================= */
    function ScheduleTab() {
        var init = wpcmData.schedule || {};

        var [ settings, setSettings ] = useState( {
            enabled:              !! init.enabled,
            frequency:            init.frequency            || 'daily',
            retention_mode:       init.retention_mode       || 'count',
            retention_count:      init.retention_count      || 7,
            retention_days:       init.retention_days       || 30,
            notify_email:         init.notify_email          || '',
            notify_on:            init.notify_on             || 'error',
            storage_driver:       init.storage_driver        || 'local',
            nextcloud_path:       init.nextcloud_path        || 'Backups/WordPress',
            nextcloud_keep_local: init.nextcloud_keep_local !== false,
        } );

        // Nextcloud connection state (managed separately — set by Login Flow, not form)
        var [ ncStatus,  setNcStatus  ] = useState(
            init.nextcloud_connected ? 'connected' : 'disconnected'
        );
        var [ ncUser,    setNcUser    ] = useState( init.nextcloud_user    || '' );
        var [ ncServer,  setNcServer  ] = useState( init.nextcloud_url     || '' );
        var [ ncUrlDraft, setNcUrlDraft ] = useState( init.nextcloud_url   || '' );
        var [ ncFlowError, setNcFlowError ] = useState( null );
        var ncPollRef = wp.element.useRef ? wp.element.useRef( null ) : { current: null };

        var [ nextRun,        setNextRun        ] = useState( init.next_run_human || null );
        var [ cronDisabled,   setCronDisabled   ] = useState( !! init.cron_disabled );
        var [ history,        setHistory        ] = useState( [] );
        var [ histLoading,    setHistLoading    ] = useState( true );
        var [ saving,         setSaving         ] = useState( false );
        var [ running,        setRunning        ] = useState( false );
        var [ runStatus,      setRunStatus      ] = useState( null );
        var [ saveMsg,        setSaveMsg        ] = useState( null );
        var [ runMsg,         setRunMsg         ] = useState( null );
        var [ testMsg,        setTestMsg        ] = useState( null );
        var [ testing,        setTesting        ] = useState( false );
        var pollRef = wp.element.useRef ? wp.element.useRef( null ) : { current: null };

        // ── Load history on mount ─────────────────────────────────────────
        useEffect( function () {
            loadHistory();
            return function () {
                stopPoll();
                stopNcPoll();
            };
        }, [] );

        function loadHistory( silent ) {
            if ( ! silent ) setHistLoading( true );
            api( 'wpcm_get_backup_history' )
                .then( function ( data ) { setHistory( data ); } )
                .catch( function () { setHistory( [] ); } )
                .finally( function () { setHistLoading( false ); } );
        }

        // ── Field updater ─────────────────────────────────────────────────
        function set( key, val ) {
            setSettings( function ( prev ) {
                var next = Object.assign( {}, prev );
                next[ key ] = val;
                return next;
            } );
        }

        // ── Save settings ─────────────────────────────────────────────────
        function saveSettings() {
            setSaving( true );
            setSaveMsg( null );
            api( 'wpcm_save_schedule', settings )
                .then( function ( data ) {
                    setSaveMsg( { type: 'success', text: data.message || 'Settings saved.' } );
                    if ( data.next_run_human ) setNextRun( data.next_run_human );
                } )
                .catch( function ( err ) {
                    setSaveMsg( { type: 'error', text: err.message } );
                } )
                .finally( function () {
                    setSaving( false );
                    setTimeout( function () { setSaveMsg( null ); }, 5000 );
                } );
        }

        // ── Polling helpers ───────────────────────────────────────────────

        function stopPoll() {
            if ( pollRef.current ) {
                clearInterval( pollRef.current );
                pollRef.current = null;
            }
        }

        /**
         * Poll wpcm_get_backup_status every 5 s.
         * Stops when the lock is gone AND the latest history entry is newer
         * than `startedAfter` (ISO string of when we clicked "Lancer").
         */
        function startPoll( startedAfter ) {
            stopPoll();
            pollRef.current = setInterval( function () {
                api( 'wpcm_get_backup_status' )
                    .then( function ( data ) {
                        var latest  = data.latest;
                        var running = data.running;

                        // Check if a new completed entry has appeared
                        if ( latest && latest.started_at >= startedAfter && ! running ) {
                            stopPoll();
                            setRunning( false );

                            if ( latest.status === 'success' ) {
                                setRunMsg( {
                                    type: 'success',
                                    text: __( 'Backup complete — ', 'clone-master' ) + ( latest.filename || '' ) +
                                          ' (' + ( latest.size_human || '' ) + ', ' + formatDuration( latest.duration_sec ) + ')',
                                } );
                            } else {
                                setRunMsg( {
                                    type: 'error',
                                    text: __( 'Failure: ', 'clone-master' ) + ( latest.error || __( 'Unknown error', 'clone-master' ) ),
                                } );
                            }
                            loadHistory( true );
                        }
                        // Still running — update the animated label
                        // (no state change needed, spinner is already visible)
                    } )
                    .catch( function () {
                        // Network hiccup — keep polling silently
                    } );
            }, 5000 );
        }

        // ── Run now — fire & forget + poll ───────────────────────────────
        function runNow() {
            if ( running ) return;
            setRunning( true );
            setRunMsg( null );

            // Snapshot "now" so we can detect when a new entry appears
            var startedAfter = new Date().toISOString().slice( 0, 19 ).replace( 'T', ' ' );

            api( 'wpcm_run_backup_now' )
                .then( function ( data ) {
                    // Server responded instantly (fire & forget) — start polling
                    if ( data && data.status === 'queued' ) {
                        startPoll( startedAfter );
                    }
                } )
                .catch( function ( err ) {
                    // The only real error here is "already running" or auth failure
                    setRunning( false );
                    setRunMsg( { type: 'error', text: err.message } );
                } );
        }

        // ── Clear history ─────────────────────────────────────────────────
        function clearHistory() {
            if ( ! confirm( 'Effacer tout l\'historique ?' ) ) return;
            api( 'wpcm_clear_history' ).then( function () { setHistory( [] ); } );
        }

        // ── Nextcloud Login Flow v2 ───────────────────────────────────────

        function stopNcPoll() {
            if ( ncPollRef.current ) {
                clearInterval( ncPollRef.current );
                ncPollRef.current = null;
            }
        }

        function startNcConnect() {
            var url = ncUrlDraft.trim();
            if ( ! url ) { setNcFlowError( __( 'Please enter your Nextcloud server URL.', 'clone-master' ) ); return; }
            if ( ! /^https?:\/\//i.test( url ) ) { setNcFlowError( 'L\'URL doit commencer par https://' ); return; }

            setNcStatus( 'waiting' );
            setNcFlowError( null );

            api( 'wpcm_nc_init_flow', { nextcloud_url: url } )
                .then( function ( data ) {
                    // Open the Nextcloud login page in a popup
                    var popup = window.open(
                        data.login_url,
                        'wpcm_nc_auth',
                        'width=600,height=700,resizable=yes,scrollbars=yes'
                    );

                    // Poll for the result every 2 seconds
                    var sessionId = data.session_id;
                    ncPollRef.current = setInterval( function () {
                        // If user closed the popup manually without authorizing
                        if ( popup && popup.closed ) {
                            stopNcPoll();
                            setNcStatus( 'disconnected' );
                            setNcFlowError( __( 'Connection cancelled — the window was closed.', 'clone-master' ) );
                            return;
                        }
                        api( 'wpcm_nc_poll_flow', { session_id: sessionId } )
                            .then( function ( d ) {
                                if ( d.connected ) {
                                    stopNcPoll();
                                    if ( popup && ! popup.closed ) popup.close();
                                    setNcStatus( 'connected' );
                                    setNcUser( d.user );
                                    setNcServer( d.nextcloud_url );
                                    setNcFlowError( null );
                                    // Auto-switch driver to nextcloud
                                    set( 'storage_driver', 'nextcloud' );
                                }
                                // d.pending === true → keep polling
                            } )
                            .catch( function ( err ) {
                                stopNcPoll();
                                if ( popup && ! popup.closed ) popup.close();
                                setNcStatus( 'disconnected' );
                                setNcFlowError( err.message );
                            } );
                    }, 2000 );
                } )
                .catch( function ( err ) {
                    setNcStatus( 'disconnected' );
                    setNcFlowError( err.message );
                } );
        }

        function disconnectNc() {
            stopNcPoll();
            api( 'wpcm_nc_disconnect' ).then( function () {
                setNcStatus( 'disconnected' );
                setNcUser( '' );
                setNcServer( '' );
                setNcUrlDraft( '' );
                set( 'storage_driver', 'local' );
            } );
        }

        function cancelNcFlow() {
            stopNcPoll();
            setNcStatus( 'disconnected' );
            setNcFlowError( null );
        }

        // ── Render ────────────────────────────────────────────────────────
        return h( Fragment, null,

            /* ── Settings card ── */
            h( 'div', { className: 'wpcm-card' },
                h( 'h3', { className: 'wpcm-card-title' },
                    h( Ico, { n: 'clock' } ), __( ' Automatic backup', 'clone-master' )
                ),

                /* WP-Cron warning */
                cronDisabled && h( 'div', { className: 'wpcm-alert wpcm-alert-warn', style: { marginBottom: 16 } },
                    h( Ico, { n: 'warn', s: 15 } ),
                    __( ' DISABLE_WP_CRON is active — automatic backups will not fire via WP-Cron.', 'clone-master' ),
                    h( 'br' ),
                    h( 'small', null, 'Set up a real system cron: ',
                        h( 'code', null, 'curl https://' + window.location.hostname + '/wp-cron.php?doing_wp_cron' )
                    )
                ),

                /* Enabled toggle */
                h( 'div', { className: 'wpcm-form-row' },
                    h( 'label', { className: 'wpcm-toggle-label' },
                        h( 'input', {
                            type:    'checkbox',
                            checked: settings.enabled,
                            onChange: function ( e ) { set( 'enabled', e.target.checked ); }
                        } ),
                        h( 'span', { className: 'wpcm-toggle-slider' } ),
                        __( ' Enable automatic backup', 'clone-master' )
                    )
                ),

                /* Frequency */
                h( 'div', { className: 'wpcm-form-row' },
                    h( 'label', { className: 'wpcm-form-label' }, 'Frequency' ),
                    h( 'select', {
                        className: 'wpcm-select',
                        disabled:  ! settings.enabled,
                        value:     settings.frequency,
                        onChange:  function ( e ) { set( 'frequency', e.target.value ); }
                    },
                        Object.keys( FREQ_LABELS ).map( function ( k ) {
                            return h( 'option', { key: k, value: k }, FREQ_LABELS[ k ] );
                        } )
                    ),
                    settings.enabled && nextRun && h( 'span', { className: 'wpcm-next-run' },
                        h( Ico, { n: 'clock', s: 13 } ), __( 'Next run: ', 'clone-master' ), nextRun
                    )
                ),

                /* Retention */
                h( 'div', { className: 'wpcm-form-row' },
                    h( 'label', { className: 'wpcm-form-label' }, 'Retention' ),
                    h( 'div', { className: 'wpcm-retention-row' },
                        h( 'label', null,
                            h( 'input', {
                                type: 'radio', name: 'ret_mode', value: 'count',
                                disabled: ! settings.enabled,
                                checked:  settings.retention_mode === 'count',
                                onChange: function () { set( 'retention_mode', 'count' ); }
                            } ), __( ' Keep the ', 'clone-master' )
                        ),
                        h( 'input', {
                            type:     'number', min: 1, max: 365,
                            className: 'wpcm-input-number',
                            disabled: ! settings.enabled || settings.retention_mode !== 'count',
                            value:    settings.retention_count,
                            onChange: function ( e ) { set( 'retention_count', parseInt( e.target.value, 10 ) || 1 ); }
                        } ),
                        h( 'span', null, __( ' latest backups  ', 'clone-master' ) ),

                        h( 'label', null,
                            h( 'input', {
                                type: 'radio', name: 'ret_mode', value: 'days',
                                disabled: ! settings.enabled,
                                checked:  settings.retention_mode === 'days',
                                onChange: function () { set( 'retention_mode', 'days' ); }
                            } ), __( ' or for ', 'clone-master' )
                        ),
                        h( 'input', {
                            type:     'number', min: 1, max: 3650,
                            className: 'wpcm-input-number',
                            disabled: ! settings.enabled || settings.retention_mode !== 'days',
                            value:    settings.retention_days,
                            onChange: function ( e ) { set( 'retention_days', parseInt( e.target.value, 10 ) || 1 ); }
                        } ),
                        h( 'span', null, __( ' days', 'clone-master' ) )
                    ),
                    h( 'small', { className: 'wpcm-hint' },
                        __( 'Retention applies only to automatic backups (auto_ prefix). Manual backups are never deleted.', 'clone-master' )
                    )
                ),

                /* Notification */
                h( 'div', { className: 'wpcm-form-row' },
                    h( 'label', { className: 'wpcm-form-label' }, __( 'Email notifications', 'clone-master' ) ),
                    h( 'div', { className: 'wpcm-notif-row' },
                        h( 'input', {
                            type:        'email',
                            className:   'wpcm-input-text',
                            placeholder: 'admin@example.com',
                            disabled:    ! settings.enabled,
                            value:       settings.notify_email,
                            onChange:    function ( e ) { set( 'notify_email', e.target.value ); }
                        } ),
                        h( 'select', {
                            className: 'wpcm-select wpcm-select-small',
                            disabled:  ! settings.enabled,
                            value:     settings.notify_on,
                            onChange:  function ( e ) { set( 'notify_on', e.target.value ); }
                        },
                            h( 'option', { value: 'always' }, __( 'Always', 'clone-master' ) ),
                            h( 'option', { value: 'error'  }, __( 'On failure only', 'clone-master' ) ),
                            h( 'option', { value: 'never'  }, __( 'Never', 'clone-master' ) )
                        )
                    )
                ),

                /* ── Storage destination ── */
                h( 'div', { className: 'wpcm-form-row wpcm-storage-section' },
                    h( 'label', { className: 'wpcm-form-label' }, __( 'Backup destination', 'clone-master' ) ),

                    /* Driver selector tabs */
                    h( 'div', { className: 'wpcm-driver-tabs' },
                        h( 'button', {
                            type:      'button',
                            className: 'wpcm-driver-btn' + ( settings.storage_driver === 'local' ? ' active' : '' ),
                            onClick:   function () { set( 'storage_driver', 'local' ); }
                        },
                            h( 'svg', { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', style: { verticalAlign: 'middle', marginRight: 5 } },
                                h( 'rect', { x: 2, y: 2, width: 20, height: 8, rx: 2 } ),
                                h( 'rect', { x: 2, y: 14, width: 20, height: 8, rx: 2 } ),
                                h( 'line', { x1: 6, y1: 6, x2: '6.01', y2: 6 } ),
                                h( 'line', { x1: 6, y1: 18, x2: '6.01', y2: 18 } )
                            ),
                            __( 'Local storage', 'clone-master' )
                        ),
                        /* ── Nextcloud tab button — disabled when OpenSSL is absent ── */
                        h( 'button', {
                            type:      'button',
                            className: 'wpcm-driver-btn'
                                + ( settings.storage_driver === 'nextcloud' ? ' active' : '' )
                                + ( ! HAS_OPENSSL ? ' wpcm-driver-btn-disabled' : '' ),
                            disabled:  ! HAS_OPENSSL,
                            title:     ! HAS_OPENSSL
                                ? __( 'Nextcloud unavailable: PHP OpenSSL extension is missing on this server.', 'clone-master' )
                                : '',
                            onClick:   ! HAS_OPENSSL ? undefined : function () { set( 'storage_driver', 'nextcloud' ); }
                        },
                            h( 'svg', { width: 14, height: 14, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', style: { verticalAlign: 'middle', marginRight: 5 } },
                                h( 'path', { d: 'M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z' } )
                            ),
                            'Nextcloud'
                        )
                    ),

                    /* ── Alerte OpenSSL absent ── */
                    ! HAS_OPENSSL && h( 'div', { className: 'wpcm-notice wpcm-notice-error', style: { marginTop: 10 } },
                        h( 'svg', { width: 15, height: 15, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', style: { verticalAlign: 'middle', marginRight: 6, flexShrink: 0 } },
                            h( 'circle', { cx: 12, cy: 12, r: 10 } ),
                            h( 'line', { x1: 12, y1: 8, x2: 12, y2: 12 } ),
                            h( 'line', { x1: 12, y1: 16, x2: '12.01', y2: 16 } )
                        ),
                        h( 'div', null,
                            h( 'strong', null, 'Nextcloud unavailable — OpenSSL extension missing' ),
                            h( 'br' ),
                            'The PHP ',
                            h( 'code', null, 'openssl' ),
                            __( ' extension is missing on this server. Clone Master needs it to encrypt your Nextcloud credentials before storing them. ', 'clone-master' ),
                            __( 'To enable it, add ', 'clone-master' ),
                            h( 'code', null, 'extension=openssl' ),
                            ' dans votre ',
                            h( 'code', null, 'php.ini' ),
                            ' or contact your host. In the meantime, only local storage is available.'
                        )
                    ),

                    /* ── Local storage hint + alerte Nginx ── */
                    settings.storage_driver === 'local' && h( 'div', null,
                        h( 'small', { className: 'wpcm-hint' },
                            __( 'Backups are stored in ', 'clone-master' ), h( 'code', null, 'wp-content/wpcm-backups/' ), ' ' + __( 'on this server.', 'clone-master' )
                        ),
                        /* Nginx warning — .htaccess est ignoré, le dossier peut être public */
                        SERVER_TYPE === 'nginx' && h( 'div', { className: 'wpcm-notice wpcm-notice-warning', style: { marginTop: 10 } },
                            h( 'svg', { width: 15, height: 15, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', style: { verticalAlign: 'middle', marginRight: 6, flexShrink: 0 } },
                                h( 'path', { d: 'M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z' } ),
                                h( 'line', { x1: 12, y1: 9, x2: 12, y2: 13 } ),
                                h( 'line', { x1: 12, y1: 17, x2: '12.01', y2: 17 } )
                            ),
                            h( 'div', null,
                                h( 'strong', null, 'Nginx detected — backup folder potentially public' ),
                                h( 'br' ),
                                __( 'Nginx ignores ', 'clone-master' ),
                                h( 'code', null, '.htaccess' ),
                                __( ' files. The directory ', 'clone-master' ),
                                h( 'code', null, 'wp-content/wpcm-backups/' ),
                                ' may be publicly accessible, allowing anyone to download your archives. ',
                                h( 'strong', null, __( 'Add this block to your Nginx configuration and reload it:', 'clone-master' ) ),
                                h( 'pre', { className: 'wpcm-code-block', style: { marginTop: 8 } },
                                    'location ~* ^/wp-content/wpcm-backups/ {\n    deny all;\n    return 403;\n}'
                                )
                            )
                        )
                    ),

                    /* ── Nextcloud panel ── */
                    settings.storage_driver === 'nextcloud' && h( 'div', { className: 'wpcm-nextcloud-panel' },

                        /* STATE 1 — Disconnected: URL input + connect button */
                        ncStatus === 'disconnected' && h( 'div', null,
                            h( 'p', { className: 'wpcm-nc-intro' },
                                __( 'Connect to your Nextcloud in one click. A window will open for you to authorise access — no password is entered here.', 'clone-master' )
                            ),
                            h( 'div', { className: 'wpcm-nc-connect-row' },
                                h( 'input', {
                                    type:        'url',
                                    className:   'wpcm-input-text wpcm-input-wide',
                                    placeholder: 'https://cloud.exemple.com',
                                    value:       ncUrlDraft,
                                    onChange:    function ( e ) { setNcUrlDraft( e.target.value ); setNcFlowError( null ); }
                                } ),
                                h( 'button', {
                                    type:      'button',
                                    className: 'wpcm-btn wpcm-btn-nc',
                                    onClick:   startNcConnect
                                },
                                    h( 'svg', { width: 15, height: 15, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', style: { verticalAlign: 'middle', marginRight: 5 } },
                                        h( 'path', { d: 'M18 10h-1.26A8 8 0 109 20h9a5 5 0 000-10z' } )
                                    ),
                                    __( 'Connect to Nextcloud', 'clone-master' )
                                )
                            ),
                            ncFlowError && h( 'div', { className: 'wpcm-nc-error' },
                                h( Ico, { n: 'x', s: 13 } ), ' ', ncFlowError
                            )
                        ),

                        /* STATE 2 — Waiting for user authorization in popup */
                        ncStatus === 'waiting' && h( 'div', { className: 'wpcm-nc-waiting' },
                            h( 'div', { className: 'wpcm-nc-waiting-inner' },
                                h( 'span', { className: 'wpcm-spinner wpcm-spinner-lg' } ),
                                h( 'div', null,
                                    h( 'strong', null, __( 'Waiting for authorisation…', 'clone-master' ) ),
                                    h( 'br' ),
                                    h( 'span', { style: { fontSize: 13, color: 'var(--color-text-secondary)' } },
                                        __( 'Log in to Nextcloud in the window that just opened, then click \'Authorise\'.', 'clone-master' )
                                    )
                                )
                            ),
                            h( 'button', {
                                type:      'button',
                                className: 'wpcm-btn wpcm-btn-ghost',
                                style:     { marginTop: 12 },
                                onClick:   cancelNcFlow
                            }, 'Annuler' )
                        ),

                        /* STATE 3 — Connected */
                        ncStatus === 'connected' && h( 'div', { className: 'wpcm-nc-connected' },
                            h( 'div', { className: 'wpcm-nc-connected-badge' },
                                h( 'span', { className: 'wpcm-nc-check' }, h( Ico, { n: 'check', s: 16 } ) ),
                                h( 'div', null,
                                    h( 'strong', null, __( 'Connected to Nextcloud', 'clone-master' ) ),
                                    h( 'br' ),
                                    h( 'span', { style: { fontSize: 12, color: 'var(--color-text-secondary)' } },
                                        ncUser, ' — ', ncServer
                                    )
                                ),
                                h( 'button', {
                                    type:      'button',
                                    className: 'wpcm-btn wpcm-btn-ghost wpcm-btn-danger',
                                    style:     { marginLeft: 'auto' },
                                    onClick:   disconnectNc
                                }, __( 'Disconnect', 'clone-master' ) )
                            ),

                            /* Remote path */
                            h( 'div', { className: 'wpcm-nc-field', style: { marginTop: 14 } },
                                h( 'label', { className: 'wpcm-nc-label' }, 'Dossier sur Nextcloud' ),
                                h( 'input', {
                                    type:        'text',
                                    className:   'wpcm-input-text wpcm-input-wide',
                                    placeholder: 'Backups/WordPress',
                                    value:       settings.nextcloud_path,
                                    onChange:    function ( e ) { set( 'nextcloud_path', e.target.value ); }
                                } ),
                                h( 'small', { className: 'wpcm-hint' }, __( 'The folder will be created automatically if it does not exist.', 'clone-master' ) )
                            ),

                            /* Keep-local toggle */
                            h( 'label', { className: 'wpcm-toggle-label', style: { marginTop: 12 } },
                                h( 'input', {
                                    type:     'checkbox',
                                    checked:  settings.nextcloud_keep_local,
                                    onChange: function ( e ) { set( 'nextcloud_keep_local', e.target.checked ); }
                                } ),
                                h( 'span', { className: 'wpcm-toggle-slider' } ),
                                ' ' + __( 'Also keep a local copy after upload', 'clone-master' )
                            )
                        )
                    )
                ),

                /* Action row */
                h( 'div', { className: 'wpcm-form-actions' },
                    h( 'button', {
                        className: 'wpcm-btn wpcm-btn-primary',
                        disabled:  saving,
                        onClick:   saveSettings
                    }, h( Ico, { n: 'save', s: 15 } ), saving ? ' ' + __( 'Saving…', 'clone-master' ) : ' ' + __( 'Save settings', 'clone-master' ) ),

                    h( 'button', {
                        className: 'wpcm-btn wpcm-btn-secondary',
                        disabled:  running,
                        style:     { marginLeft: 10 },
                        onClick:   runNow
                    }, running
                        ? h( Fragment, null,
                            h( 'span', { className: 'wpcm-spinner' } ),
                            h( RunningTimer )
                          )
                        : h( Fragment, null, h( Ico, { n: 'play', s: 15 } ), __( ' Run now', 'clone-master' ) )
                    )
                ),

                /* Feedback messages */
                saveMsg && h( 'div', {
                    className: 'wpcm-alert wpcm-alert-' + saveMsg.type,
                    style:     { marginTop: 12 }
                }, h( Ico, { n: saveMsg.type === 'success' ? 'check' : 'x', s: 15 } ), ' ', saveMsg.text ),

                runMsg && h( 'div', {
                    className: 'wpcm-alert wpcm-alert-' + runMsg.type,
                    style:     { marginTop: 12 }
                }, h( Ico, { n: runMsg.type === 'success' ? 'check' : 'x', s: 15 } ), ' ', runMsg.text )
            ),

            /* ── History card ── */
            h( 'div', { className: 'wpcm-card', style: { marginTop: 20 } },
                h( 'div', { className: 'wpcm-card-header-row' },
                    h( 'h3', { className: 'wpcm-card-title', style: { margin: 0 } },
                        h( Ico, { n: 'info' } ), __( ' Backup history', 'clone-master' )
                    ),
                    h( 'div', { style: { display: 'flex', gap: 8 } },
                        h( 'button', {
                            className: 'wpcm-btn wpcm-btn-ghost',
                            onClick:   loadHistory,
                            title:     __( 'Refresh', 'clone-master' )
                        }, h( Ico, { n: 'refresh', s: 15 } ) ),
                        history.length > 0 && h( 'button', {
                            className: 'wpcm-btn wpcm-btn-ghost wpcm-btn-danger',
                            onClick:   clearHistory,
                            title:     __( 'Clear history', 'clone-master' )
                        }, h( Ico, { n: 'trash', s: 15 } ) )
                    )
                ),

                histLoading
                    ? h( 'div', { className: 'wpcm-loading-row' }, h( 'span', { className: 'wpcm-spinner' } ), ' Loading…' )
                    : history.length === 0
                        ? h( 'div', { className: 'wpcm-empty-state' }, __( 'No backups in history.', 'clone-master' ) )
                        : h( 'div', { className: 'wpcm-table-wrap' },
                            h( 'table', { className: 'wpcm-table wpcm-history-table' },
                                h( 'thead', null,
                                    h( 'tr', null,
                                        h( 'th', null, __( 'Date', 'clone-master' ) ),
                                        h( 'th', null, __( 'Trigger', 'clone-master' ) ),
                                        h( 'th', null, __( 'Status', 'clone-master' ) ),
                                        h( 'th', null, __( 'Duration', 'clone-master' ) ),
                                        h( 'th', null, __( 'Size', 'clone-master' ) ),
                                        h( 'th', null, __( 'Storage', 'clone-master' ) ),
                                        h( 'th', null, __( 'File / Error', 'clone-master' ) )
                                    )
                                ),
                                h( 'tbody', null,
                                    history.map( function ( e, i ) {
                                        var isOk        = e.status === 'success';
                                        var storageOk   = e.storage_ok !== false;
                                        var storageLabel = e.storage_driver || 'Local';
                                        return h( 'tr', { key: e.id || i, className: isOk ? '' : 'wpcm-row-error' },
                                            h( 'td', null, h( 'span', { className: 'wpcm-mono' }, formatDate( e.started_at ) ) ),
                                            h( 'td', null,
                                                h( 'span', { className: 'wpcm-badge wpcm-badge-' + ( e.trigger === 'auto' ? 'auto' : 'manual' ) },
                                                    e.trigger === 'auto' ? 'Auto' : 'Manuel'
                                                )
                                            ),
                                            h( 'td', null,
                                                isOk
                                                    ? h( 'span', { className: 'wpcm-status-ok' }, h( Ico, { n: 'check', s: 14 } ), __( ' Success', 'clone-master' ) )
                                                    : h( 'span', { className: 'wpcm-status-err' }, h( Ico, { n: 'x', s: 14 } ), ' Failure' )
                                            ),
                                            h( 'td', null, formatDuration( e.duration_sec ) ),
                                            h( 'td', null, e.size_human || '—' ),
                                            h( 'td', null,
                                                e.storage_driver
                                                    ? h( 'span', {
                                                        className: 'wpcm-storage-badge wpcm-storage-' + ( storageOk ? 'ok' : 'err' ),
                                                        title: e.storage_message || ''
                                                      },
                                                        h( Ico, { n: storageOk ? 'check' : 'warn', s: 12 } ),
                                                        ' ', storageLabel
                                                      )
                                                    : h( 'span', { style: { color: 'var(--color-text-secondary)', fontSize: 12 } }, '—' )
                                            ),
                                            h( 'td', { className: 'wpcm-td-detail' },
                                                isOk
                                                    ? h( 'span', { className: 'wpcm-filename', title: e.filename }, e.filename || '—' )
                                                    : h( 'span', { className: 'wpcm-error-msg', title: e.error || '' }, e.error || __( 'Unknown error', 'clone-master' ) )
                                            )
                                        );
                                    } )
                                )
                            )
                        )
            )
        );
    }

    /* =========================================================
       Patch the mounted React app — inject the Schedule tab
       ========================================================= */
    function patchApp() {
        var root = document.getElementById( 'wpcm-admin-root' );
        if ( ! root ) return;

        // Recreate the full app with the extra tab added.
        // We reference the globally-visible components via the React internals
        // already in the DOM — instead, we simply re-render a thin wrapper that
        // delegates everything to the existing AppFinal but adds our tab.
        //
        // Since AppFinal lives inside a closure we can't access it, we render
        // a brand-new application that re-implements the tab shell and embeds
        // ScheduleTab. The other tabs (Export, Import, Backups, Server) remain
        // rendered by the original admin.js components that are still accessible
        // via the global scope of the IIFE... except they aren't exported.
        //
        // Solution: use a custom element approach — render ScheduleTab inside a
        // dedicated <div id="wpcm-schedule-panel"> that admin.js toggles via CSS,
        // and inject a tab button via DOM after mount.
        //
        // We wait for admin.js to finish mounting, then:
        //   1. Inject a "Planification" tab button into .wpcm-tabs
        //   2. Inject a <div id="wpcm-schedule-panel"> after the last tab panel
        //   3. Mount ScheduleTab into that div
        //   4. Wire click handlers to show/hide panels

        var observer = new MutationObserver( function ( _mutations, obs ) {
            var tabsBar = root.querySelector( '.wpcm-tabs' );
            if ( ! tabsBar ) return;
            obs.disconnect();
            injectScheduleTab( root, tabsBar );
        } );
        observer.observe( root, { childList: true, subtree: true } );
    }

    function injectScheduleTab( root, tabsBar ) {
        // Avoid double-injection
        if ( document.getElementById( 'wpcm-schedule-panel' ) ) return;

        // ── Inject tab button ─────────────────────────────────────────────
        var btn = document.createElement( 'button' );
        btn.className  = 'wpcm-tab';
        btn.id         = 'wpcm-tab-schedule';
        btn.innerHTML  =
            '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:inline-block;vertical-align:middle;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' +
            '<span class="tab-lbl"> ' + __( 'Schedule', 'clone-master' ) + '</span>';
        tabsBar.appendChild( btn );

        // ── Inject panel inside .wpcm-app (same container as React panels) ─
        // Must be INSIDE .wpcm-app so the CSS child selector can target it
        var app   = root.querySelector( '.wpcm-app' ) || root;
        var panel = document.createElement( 'div' );
        panel.id           = 'wpcm-schedule-panel';
        panel.style.display = 'none';
        app.appendChild( panel );

        // ── Mount ScheduleTab into the panel ─────────────────────────────
        if ( wp.element.createRoot ) {
            wp.element.createRoot( panel ).render( h( ScheduleTab ) );
        } else {
            wp.element.render( h( ScheduleTab ), panel );
        }

        // ── Activate when our button is clicked ──────────────────────────
        btn.addEventListener( 'click', function () {
            activateScheduleTab( app, tabsBar, panel, btn );
        } );

        // ── Deactivate when any original tab is clicked ───────────────────
        // Also removes wpcm-schedule-active so React panels reappear
        tabsBar.querySelectorAll( '.wpcm-tab' ).forEach( function ( b ) {
            if ( b === btn ) return;
            b.addEventListener( 'click', function () {
                btn.classList.remove( 'active' );
                panel.style.display = 'none';
                app.classList.remove( 'wpcm-schedule-active' ); // ← KEY FIX
            } );
        } );
    }

    function activateScheduleTab( app, tabsBar, panel, btn ) {
        // Mark the app container so CSS can hide the React-rendered sibling panel
        app.classList.add( 'wpcm-schedule-active' );

        tabsBar.querySelectorAll( '.wpcm-tab' ).forEach( function ( b ) {
            b.classList.remove( 'active' );
        } );
        btn.classList.add( 'active' );
        panel.style.display = 'block';
    }

    /* =========================================================
       CSS for the schedule panel and history table
       (injected into <head> to avoid a separate CSS file)
       ========================================================= */
    function injectCSS() {
        var style = document.createElement( 'style' );
        style.textContent = [
            /* Hide React-rendered tab panels while our tab is active.
               Panel is a direct child of .wpcm-app alongside the React panels.
               We hide all siblings except: the tab bar, the header, and our own panel. */
            '.wpcm-schedule-active > *:not(.wpcm-tabs):not(.wpcm-header):not(#wpcm-schedule-panel) { display: none !important; }',

            /* Form layout */
            '.wpcm-form-row { margin-bottom: 18px; }',
            '.wpcm-form-label { display: block; font-size: 13px; font-weight: 500; margin-bottom: 6px; color: var(--color-text-primary); }',
            '.wpcm-toggle-label { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; font-size: 14px; user-select: none; }',
            '.wpcm-toggle-label input[type=checkbox] { display: none; }',
            '.wpcm-toggle-slider { position: relative; width: 44px; height: 24px; background: #d1d5e0; border-radius: 12px; transition: background .25s ease, box-shadow .25s ease; flex-shrink: 0; box-sizing: border-box; box-shadow: inset 0 1px 3px rgba(0,0,0,0.15); }',
            '.wpcm-toggle-slider::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; background: #fff; border-radius: 50%; transition: transform .25s cubic-bezier(.4,0,.2,1), box-shadow .25s ease; box-shadow: 0 1px 4px rgba(0,0,0,0.25); }',
            '.wpcm-toggle-label:hover .wpcm-toggle-slider { background: #b8bdd0; }',
            '.wpcm-toggle-label input:checked + .wpcm-toggle-slider { background: #2c5ff6; box-shadow: inset 0 1px 3px rgba(44,95,246,0.3), 0 0 0 3px rgba(44,95,246,0.12); }',
            '.wpcm-toggle-label input:checked + .wpcm-toggle-slider::after { transform: translateX(20px); box-shadow: 0 1px 4px rgba(0,0,0,0.2); }',
            '.wpcm-toggle-label:hover input:checked + .wpcm-toggle-slider { background: #1e4de0; }',
            '.wpcm-select { padding: 7px 10px; border: 1px solid var(--color-border-secondary); border-radius: 6px; font-size: 13px; background: var(--color-background-primary); color: var(--color-text-primary); }',
            '.wpcm-select-small { padding: 7px 8px; font-size: 12px; }',
            '.wpcm-input-number { width: 64px; padding: 7px 8px; border: 1px solid var(--color-border-secondary); border-radius: 6px; font-size: 13px; text-align: center; background: var(--color-background-primary); color: var(--color-text-primary); }',
            '.wpcm-input-text { padding: 7px 10px; border: 1px solid var(--color-border-secondary); border-radius: 6px; font-size: 13px; width: 260px; background: var(--color-background-primary); color: var(--color-text-primary); }',
            '.wpcm-retention-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13px; }',
            '.wpcm-notif-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }',
            '.wpcm-hint { color: var(--color-text-secondary); font-size: 12px; display: block; margin-top: 6px; }',
            '.wpcm-next-run { margin-left: 14px; font-size: 12px; color: var(--color-text-secondary); }',
            '.wpcm-form-actions { display: flex; align-items: center; gap: 0; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--color-border-tertiary); }',

            /* Alert */
            '.wpcm-alert-warn { background: #fff8e5; border-left: 3px solid #dba617; color: #7a5900; padding: 10px 14px; border-radius: 6px; font-size: 13px; }',

            /* Card header */
            '.wpcm-card-header-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }',
            '.wpcm-btn-ghost { background: transparent; border: 1px solid var(--color-border-secondary); padding: 6px 10px; border-radius: 6px; cursor: pointer; color: var(--color-text-secondary); }',
            '.wpcm-btn-ghost:hover { background: var(--color-background-secondary); }',
            '.wpcm-btn-danger { color: #c0392b !important; border-color: #c0392b !important; }',

            /* History table */
            '.wpcm-history-table { width: 100%; border-collapse: collapse; font-size: 13px; }',
            '.wpcm-history-table th { text-align: left; padding: 8px 12px; font-weight: 500; color: var(--color-text-secondary); border-bottom: 1px solid var(--color-border-tertiary); white-space: nowrap; }',
            '.wpcm-history-table td { padding: 9px 12px; border-bottom: 1px solid var(--color-border-tertiary); vertical-align: middle; }',
            '.wpcm-row-error td { background: rgba(192,57,43,0.04); }',
            '.wpcm-mono { font-family: monospace; font-size: 12px; white-space: nowrap; }',
            '.wpcm-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 500; }',
            '.wpcm-badge-auto   { background: #e8f4fd; color: #1a6fa3; }',
            '.wpcm-badge-manual { background: #f0f0f0; color: #555; }',
            '.wpcm-status-ok  { color: #1e7e34; display: flex; align-items: center; gap: 4px; white-space: nowrap; }',
            '.wpcm-status-err { color: #c0392b; display: flex; align-items: center; gap: 4px; white-space: nowrap; }',
            '.wpcm-td-detail { max-width: 300px; }',
            '.wpcm-filename  { font-family: monospace; font-size: 11px; color: var(--color-text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }',
            '.wpcm-error-msg { font-size: 12px; color: #c0392b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }',
            '.wpcm-loading-row { padding: 20px; display: flex; align-items: center; gap: 10px; color: var(--color-text-secondary); }',
            '.wpcm-empty-state { padding: 30px; text-align: center; color: var(--color-text-secondary); font-size: 14px; }',

            /* Storage driver selector */
            '.wpcm-storage-section { border-top: 1px solid var(--color-border-tertiary); padding-top: 18px; margin-top: 4px; }',
            '.wpcm-driver-tabs { display: flex; gap: 8px; margin-bottom: 16px; }',
            '.wpcm-driver-btn { display: flex; align-items: center; padding: 8px 16px; border: 1px solid var(--color-border-secondary); border-radius: 8px; background: var(--color-background-primary); color: var(--color-text-secondary); font-size: 13px; cursor: pointer; transition: all .15s; }',
            '.wpcm-driver-btn:hover { border-color: var(--color-border-primary); color: var(--color-text-primary); }',
            '.wpcm-driver-btn.active { border-color: #0082c9; color: #0082c9; background: #e8f4fb; font-weight: 500; }',

            /* Nextcloud panel shell */
            '.wpcm-nextcloud-panel { background: var(--color-background-secondary); border: 1px solid var(--color-border-tertiary); border-radius: 10px; padding: 18px; }',

            /* State 1 — disconnected */
            '.wpcm-nc-intro { font-size: 13px; color: var(--color-text-secondary); margin: 0 0 14px; line-height: 1.5; }',
            '.wpcm-nc-connect-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }',
            '.wpcm-btn-nc { display: inline-flex; align-items: center; padding: 8px 16px; background: #0082c9; color: #fff; border: none; border-radius: 7px; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap; transition: background .15s; }',
            '.wpcm-btn-nc:hover { background: #006aa3; }',
            '.wpcm-nc-error { margin-top: 10px; font-size: 13px; color: #c0392b; display: flex; align-items: center; gap: 5px; }',

            /* State 2 — waiting */
            '.wpcm-nc-waiting { text-align: center; padding: 8px 0; }',
            '.wpcm-nc-waiting-inner { display: flex; align-items: center; gap: 16px; justify-content: center; }',
            '.wpcm-spinner-lg { width: 22px; height: 22px; border-width: 3px; flex-shrink: 0; }',

            /* State 3 — connected */
            '.wpcm-nc-connected-badge { display: flex; align-items: center; gap: 12px; padding: 12px 14px; background: #edfaf1; border: 1px solid #82d996; border-radius: 8px; }',
            '.wpcm-nc-check { display: flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #27ae60; border-radius: 50%; color: #fff; flex-shrink: 0; }',

            /* Shared NC fields */
            '.wpcm-nc-field { display: flex; flex-direction: column; gap: 4px; }',
            '.wpcm-nc-label { font-size: 12px; font-weight: 500; color: var(--color-text-secondary); }',
            '.wpcm-input-wide { width: 100%; max-width: 400px; box-sizing: border-box; }',

            /* Storage badge in history */
            '.wpcm-storage-badge { display: inline-flex; align-items: center; gap: 3px; font-size: 11px; padding: 2px 7px; border-radius: 10px; font-weight: 500; white-space: nowrap; }',
            '.wpcm-storage-ok  { background: #e8f4fd; color: #1a6fa3; }',
            '.wpcm-storage-err { background: #fdecea; color: #c0392b; }',

            /* Disabled driver button (OpenSSL absent → Nextcloud unavailable) */
            '.wpcm-driver-btn-disabled { opacity: .42; cursor: not-allowed !important; filter: grayscale(1); }',
            '.wpcm-driver-btn-disabled:hover { border-color: var(--color-border-secondary) !important; color: var(--color-text-secondary) !important; background: var(--color-background-primary) !important; }',

            /* Notice banners — error (red) and warning (orange) */
            '.wpcm-notice { display: flex; align-items: flex-start; gap: 10px; padding: 12px 14px; border-radius: 8px; font-size: 12.5px; line-height: 1.55; margin-top: 10px; }',
            '.wpcm-notice code { font-size: 11.5px; background: rgba(0,0,0,.07); padding: 1px 4px; border-radius: 3px; }',
            '.wpcm-notice strong { display: block; margin-bottom: 3px; font-size: 13px; }',
            '.wpcm-notice svg { margin-top: 1px; flex-shrink: 0; }',
            '.wpcm-notice-error   { background: #fef2f2; border: 1px solid #fca5a5; color: #7f1d1d; }',
            '.wpcm-notice-error   svg { color: #dc2626; }',
            '.wpcm-notice-warning { background: #fffbeb; border: 1px solid #fcd34d; color: #78350f; }',
            '.wpcm-notice-warning svg { color: #d97706; }',

            /* Nginx config snippet inside the warning banner */
            '.wpcm-code-block { font-family: monospace; font-size: 11.5px; background: rgba(0,0,0,.06); border: 1px solid rgba(0,0,0,.1); border-radius: 5px; padding: 8px 12px; white-space: pre; overflow-x: auto; line-height: 1.6; color: #1c1917; }',
        ].join( '\n' );
        document.head.appendChild( style );
    }

    /* =========================================================
       Init
       ========================================================= */
    injectCSS();
    patchApp();

})();
