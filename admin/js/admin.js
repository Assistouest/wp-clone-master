(function () {
    'use strict';

    const { createElement: h, useState, useEffect, useRef, useCallback, Fragment } = wp.element;
    const { __ } = wp.i18n;

    /* =========================================================
       Helpers API
       ========================================================= */
    const MAX_RETRIES = 2;

    function api(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', wpcmData.nonce);
        Object.entries(data).forEach(([k, v]) => {
            if (v instanceof File) fd.append(k, v);
            else if (v !== undefined && v !== null) fd.append(k, String(v));
        });
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 600000);
        return fetch(wpcmData.ajaxUrl, { method: 'POST', body: fd, signal: ctrl.signal })
            .then(r => {
                clearTimeout(timer);
                const ct = r.headers.get('content-type') || '';
                if (!ct.includes('json')) {
                    return r.text().then(txt => {
                        try { return JSON.parse(txt); } catch {
                            let m = 'The server returned an unexpected response. ';
                            if (txt.includes('Fatal error')) m += 'Fatal PHP error detected. ';
                            if (txt.includes('Maximum execution time')) m += 'PHP execution time exceeded. ';
                            if (txt.includes('Allowed memory size')) m += 'Insufficient PHP memory. ';
                            throw new Error(m);
                        }
                    });
                }
                return r.json();
            })
            .then(r => { if (!r.success) throw new Error(r.data?.message || r.data || __( 'Unknown error', 'clone-master' )); return r.data; })
            .catch(err => { clearTimeout(timer); if (err.name === 'AbortError') throw new Error('Timeout — the server may still be processing.'); throw err; });
    }

    async function apiRetry(action, data, retries = MAX_RETRIES, log = null) {
        for (let i = 0; i <= retries; i++) {
            try { return await api(action, data); }
            catch (err) {
                const retry = err.message.includes('Timeout') || err.message.includes('fetch') || err.message.includes('500') || err.message.includes('Network');
                if (i < retries && retry) {
                    const w = (i + 1) * 3;
                    if (log) log(`⚠ ${err.message} — Retrying in ${w}s…`, 'warn');
                    await new Promise(r => setTimeout(r, w * 1000));
                    continue;
                }
                throw err;
            }
        }
    }

    // Parse robuste d'une réponse fetch : gère JSON, -1 (wp_die), HTML, corps vide
    async function parseWpResponse(resp) {
        const text = await resp.text();
        // WordPress retourne "-1" quand check_ajax_referer échoue (nonce invalide / session expirée)
        if (text === '-1' || text === '0') {
            throw new Error('Security check failed (session expired?). Reload the page and try again.');
        }
        let json;
        try { json = JSON.parse(text); } catch {
            let msg = 'Non-JSON response from server (HTTP ' + resp.status + '). ';
            if (text.includes('Fatal error') || text.includes('Parse error')) msg += __( 'Fatal PHP error detected. ', 'clone-master' );
            if (text.includes('Maximum execution time')) msg += 'PHP execution time exceeded. ';
            if (text.includes('Allowed memory size')) msg += 'Insufficient memory. ';
            if (resp.status === 413) msg += 'File too large (413 Request Entity Too Large). ';
            if (!text.trim()) msg += 'Empty response. ';
            else msg += 'Extract: ' + text.substring(0, 120).replace(/<[^>]+>/g, '').trim();
            throw new Error(msg);
        }
        if (!json || typeof json !== 'object') {
            throw new Error('Unexpected response: ' + String(json).substring(0, 100));
        }
        if (!json.success) {
            const d = json.data;
            const msg = (d && typeof d === 'object' ? d.message : d) || __( 'Server error without detail', 'clone-master' );
            throw new Error(String(msg));
        }
        return json.data;
    }

    /* =========================================================
       Icônes SVG inline
       ========================================================= */
    const Ico = ({ n, s = 18 }) => {
        const paths = {
            export:   ['path|d=M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4', 'polyline|points=17 8 12 3 7 8', 'line|x1=12|y1=3|x2=12|y2=15'],
            import:   ['path|d=M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4', 'polyline|points=7 10 12 15 17 10', 'line|x1=12|y1=15|x2=12|y2=3'],
            archive:  ['polyline|points=21 8 21 21 3 21 3 8', 'rect|x=1|y=3|width=22|height=5', 'line|x1=10|y1=12|x2=14|y2=12'],
            server:   ['rect|x=2|y=2|width=20|height=8|rx=2', 'rect|x=2|y=14|width=20|height=8|rx=2', 'line|x1=6|y1=6|x2=6.01|y2=6', 'line|x1=6|y1=18|x2=6.01|y2=18'],
            download: ['path|d=M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4', 'polyline|points=7 10 12 15 17 10', 'line|x1=12|y1=15|x2=12|y2=3'],
            trash:    ['polyline|points=3 6 5 6 21 6', 'path|d=M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2'],
            check:    ['polyline|points=20 6 9 17 4 12'],
            x:        ['line|x1=18|y1=6|x2=6|y2=18', 'line|x1=6|y1=6|x2=18|y2=18'],
            warn:     ['path|d=M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z', 'line|x1=12|y1=9|x2=12|y2=13', 'line|x1=12|y1=17|x2=12.01|y2=17'],
            db:       ['ellipse|cx=12|cy=5|rx=9|ry=3', 'path|d=M21 12c0 1.66-4 3-9 3s-9-1.34-9-3', 'path|d=M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5'],
            layers:   ['polygon|points=12 2 2 7 12 12 22 7 12 2', 'polyline|points=2 17 12 22 22 17', 'polyline|points=2 12 12 17 22 12'],
            upload:   ['path|d=M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4', 'polyline|points=17 8 12 3 7 8', 'line|x1=12|y1=3|x2=12|y2=15'],
            globe:    ['circle|cx=12|cy=12|r=10', 'line|x1=2|y1=12|x2=22|y2=12', 'path|d=M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z'],
            link:     ['path|d=M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71', 'path|d=M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71'],
            settings: ['circle|cx=12|cy=12|r=3', 'path|d=M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z'],
            info:     ['circle|cx=12|cy=12|r=10', 'line|x1=12|y1=8|x2=12|y2=12', 'line|x1=12|y1=16|x2=12.01|y2=16'],
            restore:  ['polyline|points=1 4 1 10 7 10', 'path|d=M3.51 15a9 9 0 102.13-9.36L1 10'],
            coffee:   ['path|d=M18 8h1a4 4 0 010 8h-1', 'path|d=M2 8h16v9a4 4 0 01-4 4H6a4 4 0 01-4-4V8z', 'line|x1=6|y1=1|x2=6|y2=4', 'line|x1=10|y1=1|x2=10|y2=4', 'line|x1=14|y1=1|x2=14|y2=4'],
            clock:    ['circle|cx=12|cy=12|r=10', 'polyline|points=12 6 12 12 16 14'],
        };
        const elems = (paths[n] || []).map((spec, i) => {
            const parts = spec.split('|');
            const tag = parts[0];
            const attrs = {};
            parts.slice(1).forEach(p => { const [k, v] = p.split('='); attrs[k] = v; });
            return h(tag, { key: i, ...attrs });
        });
        return h('svg', { width: s, height: s, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', style: { display: 'inline-block', verticalAlign: 'middle', flexShrink: 0 } }, ...elems);
    };

    /* =========================================================
       ProgressBar
       ========================================================= */
    function ProgressBar({ progress, message, done }) {
        return h('div', { className: 'wpcm-progress-wrap' },
            h('div', { className: 'wpcm-progress-track' },
                h('div', { className: 'wpcm-progress-fill' + (done ? ' done' : ''), style: { width: Math.min(progress, 100) + '%' } })
            ),
            h('div', { className: 'wpcm-progress-meta' },
                h('span', { className: 'wpcm-progress-msg' }, message || ''),
                h('span', { className: 'wpcm-progress-pct' + (done ? ' done' : '') }, Math.round(progress) + ' %')
            )
        );
    }

    /* =========================================================
       LogViewer
       ========================================================= */
    function LogViewer({ entries }) {
        const ref = useRef(null);
        const [collapsed, setCollapsed] = useState(false);
        useEffect(() => { if (ref.current && !collapsed) ref.current.scrollTop = ref.current.scrollHeight; }, [entries, collapsed]);
        if (!entries || entries.length === 0) return null;
        return h('div', { className: 'wpcm-card' },
            h('div', { className: 'wpcm-log-card-title' },
                h('span', { className: 'wpcm-log-card-label' }, h(Ico, { n: 'db', s: 15 }), __( 'Activity log', 'clone-master' )),
                h('button', { className: 'wpcm-log-toggle', onClick: () => setCollapsed(c => !c) },
                    collapsed ? __( 'Show', 'clone-master' ) : __( 'Collapse', 'clone-master' )
                )
            ),
            !collapsed && h('div', { className: 'wpcm-log', ref },
                entries.map((e, i) => h('div', { className: 'wpcm-log-entry', key: i },
                    h('span', { className: 'wpcm-log-time' }, e.time),
                    h('span', { className: 'wpcm-log-msg ' + (e.type || '') }, e.msg)
                ))
            )
        );
    }

    /* =========================================================
       ONGLET EXPORT
       ========================================================= */
    /* =========================================================
       DbPhaseDetail — panneau affiché pendant l'export chunked
       ========================================================= */
    function DbPhaseDetail({ dbInfo }) {
        if (!dbInfo) return null;
        const { tableIdx, totalTables, tableName, elapsedSec, calls, rowsPerCall } = dbInfo;
        const pct = totalTables > 0 ? Math.round(100 * tableIdx / totalTables) : 0;
        const mins = Math.floor(elapsedSec / 60);
        const secs = elapsedSec % 60;
        const elapsed = mins > 0
            ? mins + 'min ' + secs + 's'
            : secs + 's';

        return h('div', { className: 'wpcm-db-detail' },
            // Ligne 1 : table courante
            h('div', { className: 'wpcm-db-detail-row' },
                h(Ico, { n: 'db', s: 13 }),
                h('span', { className: 'wpcm-db-detail-label' },
                    __( 'Current table:', 'clone-master' ), ' ',
                    h('strong', null, tableName || '…')
                ),
                h('span', { className: 'wpcm-db-detail-value' },
                    tableIdx, ' / ', totalTables
                )
            ),
            // Row 2: per-table progress bar
            h('div', { className: 'wpcm-db-detail-row', style: { gap: '8px' } },
                h('span', { style: { fontSize: '11px', color: 'var(--c-text-dim)', flexShrink: 0, width: '100px' } },
                    __( 'Tables exported', 'clone-master' )
                ),
                h('div', { className: 'wpcm-db-table-bar' },
                    h('div', { className: 'wpcm-db-table-bar-fill', style: { width: pct + '%' } })
                ),
                h('span', { className: 'wpcm-db-detail-value' }, pct + '%')
            ),
            // Row 3: elapsed time / call counter
            h('div', { className: 'wpcm-db-detail-row' },
                h(Ico, { n: 'clock', s: 13 }),
                h('span', { className: 'wpcm-db-detail-label' },
                    __( 'Elapsed time:', 'clone-master' ), ' ',
                    h('strong', null, elapsed)
                ),
                h('span', { className: 'wpcm-db-detail-value', style: { color: 'var(--c-text-dim)' } },
                    calls, ' ', __( 'calls', 'clone-master' )
                )
            ),
            // Row 4: adaptive batch size
            rowsPerCall && h('div', { className: 'wpcm-db-detail-row' },
                h(Ico, { n: 'layers', s: 13 }),
                h('span', { className: 'wpcm-db-detail-label' },
                    __( 'Adaptive batch:', 'clone-master' ), ' ',
                    h('strong', null, rowsPerCall + ' ' + __( 'rows/call', 'clone-master' ))
                )
            )
        );
    }

    function ExportTab() {
        const [running, setRunning] = useState(false);
        const [progress, setProgress] = useState(0);
        const [message, setMessage] = useState('');
        const [done, setDone] = useState(false);
        const [result, setResult] = useState(null);
        const [logs, setLogs] = useState([]);
        const [error, setError] = useState(null);
        // Détail de la phase database chunked
        const [dbInfo, setDbInfo] = useState(null);
        const startTimeRef = useRef(null);
        const dbCallsRef = useRef(0);

        const log = useCallback((msg, type = '') => {
            setLogs(p => [...p, { time: new Date().toLocaleTimeString(), msg, type }]);
        }, []);

        // Extrait les infos de progression DB depuis le message serveur
        // Format: "Database: table 3/12 — exporting wp_posts…"
        const parseDbMessage = useCallback((msg) => {
            if (!msg) return null;
            // Tente le format i18n FR et EN
            const m = msg.match(/(\d+)\/(\d+)[^a-zA-Z_]*([a-zA-Z0-9_]+[^\s…]*)/);
            if (!m) return null;
            return { tableIdx: parseInt(m[1], 10), totalTables: parseInt(m[2], 10), tableName: m[3] };
        }, []);

        const run = async () => {
            setRunning(true); setProgress(0); setDone(false); setResult(null);
            setError(null); setLogs([]); setDbInfo(null);
            startTimeRef.current = Date.now();
            dbCallsRef.current = 0;
            let sid = '', next = 'init';
            let inDbPhase = false;
            try {
                while (next) {
                    const d = await apiRetry('wpcm_export', { step: next, session_id: sid }, MAX_RETRIES, log);
                    sid = d.session_id || sid;
                    setProgress(d.progress || 0);
                    setMessage(d.message || '');

                    // Phase database chunked : mise à jour silencieuse du panneau
                    if (next === 'database' && d.next_step === 'database') {
                        inDbPhase = true;
                        dbCallsRef.current++;
                        const parsed = parseDbMessage(d.message);
                        const elapsed = Math.round((Date.now() - startTimeRef.current) / 1000);
                        if (parsed) {
                            setDbInfo({ ...parsed, elapsedSec: elapsed, calls: dbCallsRef.current, rowsPerCall: d.rows_per_call || null });
                        }
                        // Ne pas spammer le log — mise à jour de la dernière entrée DB
                        setLogs(prev => {
                            const last = prev[prev.length - 1];
                            const isDbEntry = last && last.isDb;
                            const entry = { time: new Date().toLocaleTimeString(), msg: d.message || '', type: '', isDb: true };
                            return isDbEntry ? [...prev.slice(0, -1), entry] : [...prev, entry];
                        });
                    } else {
                        // Phase terminée ou autre step : log normal
                        if (inDbPhase && next === 'database') {
                            // Dernier appel database → files_scan
                            inDbPhase = false;
                            setDbInfo(null);
                            log(d.message || 'database done', 'success');
                        } else {
                            log(d.message || next + ' done', 'success');
                        }
                    }

                    if (d.download_url) setResult(d);
                    next = d.next_step || null;
                }
                setDone(true); setDbInfo(null);
                log(__( 'Backup created successfully.', 'clone-master' ), 'success');
            } catch (e) {
                setError(e.message);
                setDbInfo(null);
                log(__( 'Error: ', 'clone-master' ) + e.message, 'error');
            } finally { setRunning(false); }
        };

        // Phase DB active = barre indeterminate
        const isDbPhase = running && dbInfo !== null;

        return h(Fragment, null,
            h('div', { className: 'wpcm-intro' },
                h('div', { className: 'wpcm-intro-ico' }, h(Ico, { n: 'export', s: 18 })),
                h('div', null,
                    h('h4', null, __( 'Complete backup of your site', 'clone-master' )),
                    h('p', null, __( 'Your database, themes, plugins, media and configuration files are securely archived. You can then restore them on any host.', 'clone-master' ))
                )
            ),
            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'export' }), __( 'Export your site', 'clone-master' )),
                h('p', { className: 'wpcm-card-desc' }, __( 'Creates a complete archive of your WordPress installation, ready to migrate or store safely.', 'clone-master' )),

                !running && !done && h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: run },
                    h(Ico, { n: 'export', s: 16 }), __( 'Start full export', 'clone-master' )
                ),

                running && h(Fragment, null,
                    // Barre principale : indeterminate pendant DB, normale sinon
                    h('div', { className: 'wpcm-progress-wrap' },
                        h('div', { className: 'wpcm-progress-track' },
                            h('div', {
                                className: 'wpcm-progress-fill' + (isDbPhase ? ' indeterminate' : ''),
                                style: isDbPhase ? {} : { width: Math.min(progress, 100) + '%' }
                            })
                        ),
                        h('div', { className: 'wpcm-progress-meta' },
                            h('span', { className: 'wpcm-progress-msg' }, message || ''),
                            !isDbPhase && h('span', { className: 'wpcm-progress-pct' }, Math.round(progress) + ' %')
                        )
                    ),
                    // Panneau détail DB
                    h(DbPhaseDetail, { dbInfo }),
                    // Ligne spinner
                    !isDbPhase && h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        __( 'Processing, please wait…', 'clone-master' )
                    )
                ),

                done && h(Fragment, null,
                    h(ProgressBar, { progress: 100, message: __( 'Backup complete.', 'clone-master' ), done: true }),
                    result && h('div', { className: 'wpcm-alert wpcm-alert-success', style: { marginTop: '16px' } },
                        h(Ico, { n: 'check', s: 16 }),
                        h('div', null,
                            h('strong', null, result.filename), ' (', result.size, ')',
                            h('div', { style: { marginTop: '8px' } },
                                h('a', { href: result.download_url, className: 'wpcm-btn wpcm-btn-success wpcm-btn-sm', style: { textDecoration: 'none' } },
                                    h(Ico, { n: 'download', s: 13 }), __( 'Download archive', 'clone-master' )
                                )
                            )
                        )
                    ),
                    h('button', {
                        className: 'wpcm-btn wpcm-btn-ghost', style: { marginTop: '12px' },
                        onClick: () => { setDone(false); setProgress(0); setResult(null); setLogs([]); }
                    }, __( 'New backup', 'clone-master' ))
                ),

                error && h('div', { className: 'wpcm-alert wpcm-alert-error', style: { marginTop: '12px' } },
                    h(Ico, { n: 'x', s: 15 }), error
                )
            ),
            h(LogViewer, { entries: logs })
        );
    }

    /* =========================================================
       ONGLET IMPORT — logique identique, textes FR
       ========================================================= */
    // phases : 'idle' | 'uploading' | 'opts' | 'importing' | 'done' | 'error'
    function ImportTab({ initialFile = null, initialSessionId = '', initialFilePath = '' }) {
        const [file, setFile] = useState(initialFile);
        const [dragOver, setDragOver] = useState(false);
        const [newUrl, setNewUrl] = useState(wpcmData.siteUrl);
        const [phase, setPhase] = useState('idle'); // ← état unique, une seule carte visible
        const [progress, setProgress] = useState(0);
        const [message, setMessage] = useState('');
        const [manifest, setManifest] = useState(null);
        const [logs, setLogs] = useState([]);
        const [error, setError] = useState(null);
        const [sessionId, setSessionId] = useState(initialSessionId);
        const [filePath, setFilePath] = useState(initialFilePath);
        const [opts, setOpts] = useState({
            reset_permalinks: true,
            block_indexing:   false,  // désactivé par défaut — à cocher intentionnellement
        });
        const [deactivated, setDeactivated] = useState([]);
        const fileRef = useRef(null);

        useEffect(() => {
            if (initialSessionId && initialFilePath) setPhase('opts');
        }, []);

        const log = useCallback((msg, type = '') => {
            setLogs(p => [...p, { time: new Date().toLocaleTimeString(), msg, type }]);
        }, []);

        const handleDrop = e => { e.preventDefault(); setDragOver(false); if (e.dataTransfer.files[0]) setFile(e.dataTransfer.files[0]); };

        const runUploadAndExtract = async () => {
            if (!file) return;
            setPhase('uploading'); setProgress(0); setError(null); setLogs([]);
            try {
                // ── Algorithme de chunk adaptatif ─────────────────────────────────────────
                // Objectif : maximiser la vitesse d'envoi sans dépasser les limites serveur.
                //
                // Contraintes prises en compte :
                //   • wp_max_upload_size  — limite PHP/WordPress (connue via wpcmData.maxUpload)
                //   • Vitesse de connexion mesurée chunk par chunk (bytes/ms → Mo/s)
                //   • Temps PHP par requête : on vise 8–15s par chunk (safe sous max_execution_time)
                //   • Overhead FormData : ~800 octets par requête (headers multipart)
                //   • Marges de sécurité doubles : serveur (85 % du max) + temps (80 % du budget)
                //
                // Stratégie :
                //   1. Chunk initial = min(4 Mo, 85 % du max upload) — conservateur, mais pas trop petit
                //   2. Après chaque chunk, on mesure le débit réel (octets/ms)
                //   3. On calcule le chunk idéal pour atteindre TARGET_MS (10 000 ms = 10s)
                //      → chunkIdéal = débit × TARGET_MS
                //   4. On plafonne à SERVER_CAP et on impose un plancher MIN_CHUNK
                //   5. Les ajustements sont lissés (moyenne mobile α=0.4) pour éviter les
                //      oscillations sur une connexion instable
                //   6. Si un chunk échoue avec HTTP 413 (too large), on divise par 2 et on réessaie
                //
                // Résultat attendu :
                //   • Connexion rapide (100 Mbps) : chunks de 10–16 Mo, upload rapide
                //   • Connexion lente (2 Mbps)    : chunks de 512 Ko–2 Mo, pas de timeout
                //   • Serveur restrictif (2 Mo max): plafonnement automatique
                // ─────────────────────────────────────────────────────────────────────────────

                const MIN_CHUNK    = 256 * 1024;          // 256 Ko — plancher absolu
                const TARGET_MS    = 10000;                // cible 10s par chunk
                const SERVER_CAP   = Math.floor((wpcmData.maxUpload || 32 * 1024 * 1024) * 0.85);
                const INITIAL_CHUNK = Math.max(MIN_CHUNK, Math.min(4 * 1024 * 1024, SERVER_CAP));

                let fp = '', sid = '';
                let chunkSize = INITIAL_CHUNK;  // taille courante, adaptée dynamiquement
                let smoothedBps = null;          // débit lissé (octets/ms), null = pas encore mesuré
                const ALPHA = 0.4;              // facteur de lissage exponentiel (0=inerte, 1=réactif)

                // Envoi d'un seul chunk avec retry automatique sur 413
                const sendChunk = async (blob, index, totalHint, uid, attempt = 0) => {
                    const fd = new FormData();
                    fd.append('action',       'wpcm_import_upload');
                    fd.append('nonce',        wpcmData.nonce);
                    fd.append('backup_chunk', blob, file.name);
                    fd.append('chunk_index',  String(index));
                    fd.append('total_chunks', String(totalHint));
                    fd.append('file_name',    file.name);
                    if (uid) fd.append('upload_id', uid);

                    const t0 = performance.now();
                    const resp = await fetch(wpcmData.ajaxUrl, { method: 'POST', body: fd });

                    // 413 = trop gros → diviser par 2 et réessayer (max 3 fois)
                    if (resp.status === 413) {
                        if (attempt >= 3) throw new Error('The server rejects chunks even at ' + Math.round(chunkSize / 1024) + ' KB (HTTP 413). Check upload_max_filesize in php.ini.');
                        chunkSize = Math.max(MIN_CHUNK, Math.floor(chunkSize / 2));
                        log('⚠ HTTP 413 — chunk reduced to ' + Math.round(chunkSize / 1024) + ' KB', 'warn');
                        // On re-découpe et renvoie
                        return null; // signal pour redémarrer la boucle sur ce chunk
                    }

                    const elapsed = performance.now() - t0; // ms
                    const r = await parseWpResponse(resp);

                    // Mise à jour du débit lissé
                    const instantBps = blob.size / Math.max(elapsed, 1);
                    smoothedBps = smoothedBps === null
                        ? instantBps
                        : ALPHA * instantBps + (1 - ALPHA) * smoothedBps;

                    return { r, elapsed };
                };

                if (file.size > chunkSize) {
                    let uid = '';
                    let offset = 0;    // position dans le fichier
                    let chunkIndex = 0;
                    let totalSent = 0;
                    const fileSize = file.size;

                    log( __( 'Adaptive upload of ', 'clone-master' ) + (fileSize / 1048576).toFixed(1) + ' MB — initial size: ' + Math.round(chunkSize / 1024) + ' KB');

                    while (offset < fileSize) {
                        const blob = file.slice(offset, Math.min(offset + chunkSize, fileSize));

                        // totalHint = estimation (peut changer car chunkSize évolue)
                        const remaining = fileSize - offset;
                        const estimatedRemaining = Math.ceil(remaining / chunkSize);
                        const totalHint = chunkIndex + estimatedRemaining;

                        // Envoi avec retry 413
                        let result = null;
                        while (result === null) {
                            result = await sendChunk(blob, chunkIndex, totalHint, uid);
                            if (result === null) {
                                // 413 : chunkSize a été réduit, on recrée le blob et réessaie
                                const newBlob = file.slice(offset, Math.min(offset + chunkSize, fileSize));
                                result = await sendChunk(newBlob, chunkIndex, Math.ceil(fileSize / chunkSize), uid);
                                if (result === null) throw new Error('Failed to send this chunk even after size reduction.');
                            }
                        }

                        const { r, elapsed } = result;
                        uid = r.upload_id || uid;
                        offset += blob.size;
                        totalSent += blob.size;
                        chunkIndex++;

                        const pct = Math.round((totalSent / fileSize) * 10); // 0–10 % pour la phase upload
                        const sentMo = (totalSent / 1048576).toFixed(1);
                        const totalMo = (fileSize / 1048576).toFixed(1);
                        const speedMbps = smoothedBps ? (smoothedBps * 8 / 1000).toFixed(1) : '…';
                        setProgress(pct);
                        setMessage(sentMo + ' / ' + totalMo + ' MB sent — ' + speedMbps + ' Mbps');

                        // Adapter la taille du prochain chunk
                        if (smoothedBps !== null) {
                            const ideal = Math.floor(smoothedBps * TARGET_MS * 0.8); // 80 % du débit mesuré (marge)
                            const next  = Math.min(SERVER_CAP, Math.max(MIN_CHUNK, ideal));
                            if (Math.abs(next - chunkSize) / chunkSize > 0.15) { // n'ajuster que si >15 % d'écart
                                const prevKo = Math.round(chunkSize / 1024);
                                chunkSize = next;
                                log('  Speed: ' + speedMbps + ' Mbps — chunk adjusted: ' + prevKo + ' KB → ' + Math.round(next / 1024) + ' KB', 'success');
                            }
                        }

                        if (r.complete) { fp = r.file_path; sid = r.session_id; log(r.message || 'Upload complete', 'success'); }
                    }
                } else {
                    log( __( 'Sending archive directly (', 'clone-master' ) + (file.size / 1048576).toFixed(1) + ' MB)…');
                    const r = await api('wpcm_import_upload', { backup_file: file });
                    fp = r.file_path; sid = r.session_id;
                    log('Archive received successfully.', 'success');
                }
                setProgress(10);
                log( __( 'Analysing archive…', 'clone-master' ));
                const ext = await apiRetry('wpcm_import', { step: 'extract', session_id: sid, file_path: fp, new_url: newUrl }, MAX_RETRIES, log);
                setSessionId(ext.session_id); setFilePath(fp);
                setProgress(ext.progress); setMessage(ext.message);
                if (ext.manifest) setManifest(ext.manifest);
                log(ext.message, 'success');
                setPhase('opts');
            } catch (e) { setError(e.message); log( __( 'Error: ', 'clone-master' ) + e.message, 'error'); setPhase('error'); }
        };

        const runImport = async () => {
            setPhase('importing'); setError(null);
            try {
                // ── Generate a cryptographic token client-side ──────────────────────
                // crypto.getRandomValues() is CSPRNG — never Math.random().
                // 32 bytes = 256 bits of entropy, encoded as a 64-char hex string.
                // This token is generated here, kept in memory only, and sent once in
                // the *request* to step_prepare — never read back from any response.
                // The server bcrypt-hashes it and bakes the hash into the installer PHP.
                // The token also serves as the AES-256 key for DB credential encryption.
                const _rawBytes = new Uint8Array(32);
                crypto.getRandomValues(_rawBytes);
                const clientToken = Array.from(_rawBytes).map(b => b.toString(16).padStart(2, '0')).join('');

                log( __( 'Preparing installer…', 'clone-master' ));
                const prep = await apiRetry('wpcm_import', {
                    step: 'prepare', session_id: sessionId, file_path: filePath,
                    new_url: newUrl, import_opts: JSON.stringify(opts),
                    installer_token: clientToken, // sent in request body, never in a response
                }, MAX_RETRIES, log);
                // auth_token is intentionally absent from the response — the server never returns it.
                const { installer_url: url } = prep;
                log( __( 'Installer ready.', 'clone-master' ), 'success');

                let step = 'database';
                let dbIdx = 0, dbOff = 0, dbQ = 0, dbE = 0;
                let srIdx = 0, srOff = 0, srR = 0, srC = 0, srS = 0;
                let lastResult = null; // dernier résultat de l'installeur (utilisé après la boucle)

                while (step) {
                    const fd = new FormData();
                    fd.append('installer_token', clientToken); fd.append('step', step);
                    if (step === 'database') { fd.append('file_index', String(dbIdx)); fd.append('byte_offset', String(dbOff)); fd.append('queries_total', String(dbQ)); fd.append('errors_total', String(dbE)); }
                    if (step === 'replace_urls') { fd.append('table_index', String(srIdx)); fd.append('row_offset', String(srOff)); fd.append('sr_rows', String(srR)); fd.append('sr_cells', String(srC)); fd.append('sr_serial', String(srS)); }

                    const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), 600000);
                    let resp;
                    try { resp = await fetch(url, { method: 'POST', body: fd, signal: ctrl.signal }); }
                    catch (fe) { clearTimeout(t); throw new Error('Network error on step "' + step + '": ' + fe.message); }
                    clearTimeout(t);

                    let data;
                    const raw = await resp.text();
                    try { data = JSON.parse(raw.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, '')); }
                    catch {
                        let m = 'Invalid response on step "' + step + '". ';
                        if (raw.includes('Fatal error')) m += 'Fatal PHP error detected. ';
                        if (raw.includes('Maximum execution time')) m += 'PHP execution time exceeded. ';
                        if (raw.includes('Allowed memory size')) m += 'Insufficient memory. ';
                        if (!raw.trim()) m += 'Empty response — server may have timed out. ';
                        throw new Error(m + ' | ' + raw.substring(0, 200).replace(/<[^>]+>/g, '').trim());
                    }
                    if (!data.success) throw new Error(data.data?.message || 'Installer error on step "' + step + '"');

                    const d = data.data;
                    lastResult = d;
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || step + ' done', 'success');
                    if (d.errors_log) d.errors_log.forEach(e => log('  SQL : ' + e, 'warn'));

                    if (step === 'database') { dbIdx = d.file_index ?? dbIdx; dbOff = d.byte_offset ?? 0; dbQ = d.queries ?? dbQ; dbE = d.errors ?? dbE; }
                    if (step === 'replace_urls') { srIdx = d.table_index ?? srIdx; srOff = d.row_offset ?? 0; srR = d.rows ?? srR; srC = d.cells ?? srC; srS = d.serial ?? srS; }
                    step = d.next_step || null;
                }
                if (lastResult && lastResult.deactivated_plugins && lastResult.deactivated_plugins.length) {
                    setDeactivated(lastResult.deactivated_plugins);
                    log('Plugins put on standby (' + lastResult.deactivated_plugins.length + '): ' + lastResult.deactivated_plugins.join(', '), 'warn');
                }
                setPhase('done'); log( __( 'Migration completed successfully.', 'clone-master' ), 'success');
            } catch (e) { setError(e.message); log( __( 'Error: ', 'clone-master' ) + e.message, 'error'); setPhase('error'); }
        };

        const reset = () => { setPhase('idle'); setProgress(0); setFile(null); setManifest(null); setLogs([]); setError(null); setSessionId(''); setFilePath(''); setDeactivated([]); };

        // Rendu : une seule section visible selon la phase courante
        const renderPhase = () => {
            switch (phase) {

                case 'idle': return h(Fragment, null,
                    h('div', { className: 'wpcm-intro' },
                        h('div', { className: 'wpcm-intro-ico' }, h(Ico, { n: 'import', s: 18 })),
                        h('div', null,
                            h('h4', null, __( 'Restore or migrate your site', 'clone-master' )),
                            h('p', null, __( 'Upload a Clone Master archive to restore your site on this host. All URLs are replaced automatically, including in serialised data.', 'clone-master' ))
                        )
                    ),
                    h('div', { className: 'wpcm-card' },
                        h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'import' }), __( 'Select an archive', 'clone-master' )),
                        h('p', { className: 'wpcm-card-desc' }, __( 'Drop your .zip file or click to select it. Large files are sent in parts to work around server limits.', 'clone-master' )),
                        h('div', {
                            className: 'wpcm-upload-zone' + (dragOver ? ' dragover' : '') + (file ? ' has-file' : ''),
                            onClick: () => fileRef.current && fileRef.current.click(),
                            onDragOver: e => { e.preventDefault(); setDragOver(true); },
                            onDragLeave: () => setDragOver(false),
                            onDrop: handleDrop,
                        },
                            h('input', { type: 'file', ref: fileRef, accept: '.zip', style: { display: 'none' }, onChange: e => setFile(e.target.files[0]) }),
                            h('div', { className: 'wpcm-upload-ico' }, h(Ico, { n: 'upload', s: 22 })),
                            h('p', { className: 'wpcm-upload-name' }, file ? file.name + ' — ' + (file.size / 1048576).toFixed(1) + ' MB' : __( 'Drop your archive here', 'clone-master' )),
                            h('p', { className: 'wpcm-upload-hint' }, file ? __( 'Click to choose a different file', 'clone-master' ) : __( 'Accepted format: .zip — or click to browse', 'clone-master' ))
                        ),
                        file && h(Fragment, null,
                            h('div', { className: 'wpcm-field', style: { marginTop: '20px' } },
                                h('label', { className: 'wpcm-label' }, __( 'Destination URL', 'clone-master' )),
                                h('input', { className: 'wpcm-input', type: 'url', value: newUrl, onChange: e => setNewUrl(e.target.value), placeholder: 'https://your-domain.com' }),
                                h('span', { className: 'wpcm-input-hint' }, __( 'Leave as-is if restoring on the same domain.', 'clone-master' ))
                            ),
                            newUrl !== wpcmData.siteUrl && h('div', { className: 'wpcm-alert wpcm-alert-warning' },
                                h(Ico, { n: 'warn', s: 15 }),
                                'URLs will be replaced throughout the database → ' + newUrl
                            ),
                            h('div', { style: { marginTop: '16px' } },
                                h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runUploadAndExtract },
                                    h(Ico, { n: 'upload', s: 16 }), __( 'Analyse archive', 'clone-master' )
                                )
                            )
                        )
                    )
                );

                case 'uploading': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'upload' }), __( 'Uploading archive…', 'clone-master' )),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        __( 'Uploading — please do not close this page.', 'clone-master' )
                    )
                );

                case 'opts': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'settings' }), __( 'Restore settings', 'clone-master' )),
                    h('p', { className: 'wpcm-card-desc' }, __( 'Check the information and configure the options before starting the restore.', 'clone-master' )),
                    manifest && h(Fragment, null,
                        h('div', { className: 'wpcm-opts-section-title', style: { marginBottom: '10px' } }, h(Ico, { n: 'db', s: 14 }), 'Archive source'),
                        h('div', { className: 'wpcm-manifest-grid' },
                            [
                                ['URL source', manifest.site_url],
                                ['Version WP', manifest.wp_version],
                                ['Created on', manifest.created_at],
                                ['Active theme', manifest.active_theme],
                                ['Extensions', (manifest.active_plugins || []).length + ' actives'],
                                ['Tables', manifest.tables_count + ' tables DB'],
                            ].map(([lbl, val]) => h('div', { className: 'wpcm-manifest-item', key: lbl },
                                h('div', { className: 'wpcm-manifest-lbl' }, lbl),
                                h('div', { className: 'wpcm-manifest-val' }, val || 'N/A')
                            ))
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'link', s: 14 }), __( 'Destination URL', 'clone-master' )),
                        h('div', { className: 'wpcm-field', style: { marginBottom: 0 } },
                            h('label', { className: 'wpcm-label' }, __( 'New site URL', 'clone-master' )),
                            h('input', { className: 'wpcm-input', type: 'url', value: newUrl, onChange: e => setNewUrl(e.target.value), placeholder: 'https://your-domain.com' }),
                            h('span', { className: 'wpcm-input-hint' }, __( 'All occurrences of the old URL will be replaced in the database.', 'clone-master' ))
                        ),
                        newUrl !== wpcmData.siteUrl && h('div', { className: 'wpcm-alert wpcm-alert-warning', style: { marginTop: '10px' } },
                            h(Ico, { n: 'warn', s: 15 }),
                            (manifest && manifest.site_url ? manifest.site_url : '(source)') + ' → ' + newUrl
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'settings', s: 14 }), 'Advanced options'),

                        /* Permaliens */
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.reset_permalinks, onChange: e => setOpts(o => ({ ...o, reset_permalinks: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Regenerate permalinks', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Deletes cached rewrite rules — WordPress will regenerate them on first load.', 'clone-master' ))
                            )
                        ),

                        /* Visibilité moteurs de recherche */
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.block_indexing, onChange: e => setOpts(o => ({ ...o, block_indexing: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Hide site from search engines', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Asks Google & Bing not to index this site (WordPress "Discourage search engines" option). Recommended during post-migration validation — uncheck once verified.', 'clone-master' ))
                            )
                        ),

                    ),
                    h('div', { style: { display: 'flex', gap: '10px', marginTop: '8px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runImport },
                            h(Ico, { n: 'restore', s: 16 }), __( 'Start restore', 'clone-master' )
                        ),
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, 'Annuler')
                    )
                );

                case 'importing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'restore' }), __( 'Restoring…', 'clone-master' )),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        __( 'Processing — please do not close this page.', 'clone-master' )
                    )
                );

                case 'done': return h('div', { className: 'wpcm-card' },
                    h(ProgressBar, { progress: 100, message: __( 'Restore complete.', 'clone-master' ), done: true }),
                    h('div', { className: 'wpcm-complete-box' },
                        h('div', { className: 'wpcm-complete-icon' }, h(Ico, { n: 'check', s: 26 })),
                        h('h3', { className: 'wpcm-complete-title' }, __( 'Your site has been restored successfully!', 'clone-master' )),
                        h('p', { className: 'wpcm-complete-sub' }, __( 'The migration is complete. Please log back into the WordPress admin to verify everything is working correctly.', 'clone-master' )),
                        deactivated.length > 0 && h('div', { className: 'wpcm-alert wpcm-alert-warning', style: { textAlign: 'left', marginBottom: '16px' } },
                            h(Ico, { n: 'warn', s: 15 }),
                            h('div', null,
                                h('strong', null, deactivated.length + __( ' plugin(s) put on standby for your security', 'clone-master' )),
                                h('p', { style: { margin: '4px 0 8px', fontSize: '12px', lineHeight: '1.5' } },
                                    __( 'These security or cache plugins have been deactivated because they can block access after migration (WAF firewall, CAPTCHA tied to source domain, etc.). Re-enable them manually after logging back in.', 'clone-master' )
                                ),
                                h('ul', { style: { margin: '0', paddingLeft: '18px', fontSize: '11.5px', fontFamily: 'var(--c-mono)' } },
                                    deactivated.map((p, i) => h('li', { key: i }, p))
                                )
                            )
                        ),
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, __( 'New restore', 'clone-master' ))
                    )
                );

                case 'error': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'x' }), __( 'An error occurred', 'clone-master' )),
                    h('div', { className: 'wpcm-alert wpcm-alert-error', style: { marginTop: 0 } },
                        h(Ico, { n: 'x', s: 15 }), error || __( 'Unknown error', 'clone-master' )
                    ),
                    h('div', { style: { marginTop: '16px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, __( '← Start over', 'clone-master' ))
                    )
                );

                default: return null;
            }
        };

        return h(Fragment, null, renderPhase(), h(LogViewer, { entries: logs }));
    }

    /* =========================================================
       ONGLET SAUVEGARDES
       ========================================================= */
    function BackupsTab({ onRestore }) {
        const [backups, setBackups] = useState([]);
        const [loading, setLoading] = useState(true);
        const [deleting, setDeleting] = useState(null);

        const load = useCallback(async () => {
            setLoading(true);
            try { setBackups(await api('wpcm_get_backups')); }
            catch (e) { console.error(e); }
            finally { setLoading(false); }
        }, []);

        useEffect(() => { load(); }, [load]);

        const del = async name => {
            if (!confirm( __( 'Permanently delete this backup?', 'clone-master' ) + '\n\n' + name)) return;
            setDeleting(name);
            try { await api('wpcm_delete_backup', { backup_name: name }); load(); }
            catch (e) { alert( __( 'Error: ', 'clone-master' ) + e.message); }
            finally { setDeleting(null); }
        };

        const dlUrl = n => wpcmData.ajaxUrl + '?action=wpcm_download_backup&backup_name=' + encodeURIComponent(n) + '&nonce=' + wpcmData.nonce;

        return h('div', { className: 'wpcm-card' },
            h('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' } },
                h('h3', { className: 'wpcm-card-title', style: { margin: 0 } }, h(Ico, { n: 'archive' }), __( 'Your backups', 'clone-master' )),
                h('button', { className: 'wpcm-btn wpcm-btn-ghost wpcm-btn-sm', onClick: load }, __( 'Refresh', 'clone-master' ))
            ),
            h('p', { className: 'wpcm-card-desc' }, __( 'All your archives are listed here. Download them for safekeeping or restore them directly on this site.', 'clone-master' )),

            loading && h('div', { style: { textAlign: 'center', padding: '32px' } }, h('span', { className: 'wpcm-spinner' })),

            !loading && backups.length === 0 && h('div', { className: 'wpcm-empty' },
                h('div', { className: 'wpcm-empty-ico' }, h(Ico, { n: 'archive', s: 22 })),
                h('p', null, __( 'No backups available. Run an export to create one.', 'clone-master' ))
            ),

            !loading && backups.length > 0 && h('div', { className: 'wpcm-table-wrap' },
                h('table', { className: 'wpcm-table' },
                    h('thead', null, h('tr', null,
                        h('th', null, __( 'File', 'clone-master' )),
                        h('th', null, __( 'Size', 'clone-master' )),
                        h('th', null, __( 'Date', 'clone-master' )),
                        h('th', null, __( 'Origin', 'clone-master' )),
                        h('th', { className: 'col-actions' }, __( 'Actions', 'clone-master' ))
                    )),
                    h('tbody', null, backups.map(b =>
                        h('tr', { key: b.name },
                            h('td', null, h('span', { className: 'wpcm-filename', title: b.name }, b.name)),
                            h('td', null, h('span', { className: 'wpcm-filesize' }, b.size)),
                            h('td', null, h('span', { className: 'wpcm-filedate' }, b.date)),
                            h('td', null,
                                h('span', {
                                    className: 'wpcm-badge ' + (b.origin === 'Nextcloud' ? 'wpcm-badge-nc' : 'wpcm-badge-local'),
                                    title: b.origin
                                }, b.origin || __( 'Local storage', 'clone-master' ))
                            ),
                            h('td', { className: 'col-actions' },
                                h('div', { className: 'wpcm-table-actions' },
                                    h('button', {
                                        className: 'wpcm-btn wpcm-btn-blue wpcm-btn-sm',
                                        onClick: () => onRestore && onRestore(b),
                                        title: __( 'Restore this backup', 'clone-master' )
                                    }, h(Ico, { n: 'restore', s: 13 }), __( 'Restore', 'clone-master' )),
                                    h('a', {
                                        href: dlUrl(b.name), className: 'wpcm-btn wpcm-btn-ghost wpcm-btn-sm',
                                        style: { textDecoration: 'none' }, title: 'Download'
                                    }, h(Ico, { n: 'download', s: 13 }), __( 'Download', 'clone-master' )),
                                    h('button', {
                                        className: 'wpcm-btn wpcm-btn-danger wpcm-btn-sm',
                                        onClick: () => del(b.name),
                                        disabled: deleting === b.name,
                                        title: __( 'Delete', 'clone-master' )
                                    }, deleting === b.name ? h('span', { className: 'wpcm-spinner' }) : h(Ico, { n: 'trash', s: 13 }))
                                )
                            )
                        )
                    ))
                )
            )
        );
    }

    /* =========================================================
       ONGLET SERVEUR
       ========================================================= */
    function ServerTab() {
        const [info, setInfo] = useState(null);
        const [loading, setLoading] = useState(true);
        useEffect(() => { api('wpcm_server_info').then(setInfo).catch(console.error).finally(() => setLoading(false)); }, []);

        if (loading) return h('div', { style: { textAlign: 'center', padding: '48px' } }, h('span', { className: 'wpcm-spinner' }));
        if (!info) return h('div', { className: 'wpcm-alert wpcm-alert-error', style: { margin: 0 } }, __( 'Failed to load server information.', 'clone-master' ));

        const fmt = b => {
            if (!b) return 'N/A';
            if (b > 1073741824) return (b / 1073741824).toFixed(1) + ' Go';
            if (b > 1048576) return (b / 1048576).toFixed(1) + ' Mo';
            return (b / 1024).toFixed(0) + ' Ko';
        };

        return h(Fragment, null,
            h('div', { className: 'wpcm-stats-grid' },
                [
                    ['PHP', info.php?.version, 'ok'],
                    ['MySQL', info.mysql?.version, 'ok'],
                    ['WordPress', info.wordpress?.version, 'ok'],
                    [__( 'Server', 'clone-master' ), info.server?.type, ''],
                    ['Database', fmt(info.mysql?.total_size), ''],
                    ['Media', fmt(info.wordpress?.uploads_size), ''],
                    [__( 'Free disk space', 'clone-master' ), info.disk?.free_human, info.disk?.free > 1073741824 ? 'ok' : 'warn'],
                    ['Upload max.', info.limits?.wp_max_upload_human, ''],
                ].map(([lbl, val, cls]) =>
                    h('div', { className: 'wpcm-stat-card', key: lbl },
                        h('p', { className: 'wpcm-stat-lbl' }, lbl),
                        h('p', { className: 'wpcm-stat-val ' + cls }, val || 'N/A')
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'server' }), __( 'Server limits', 'clone-master' )),
                h('div', { className: 'wpcm-info-grid' },
                    [
                        [__( 'PHP memory', 'clone-master' ), info.limits?.memory_limit_human],
                        [__( 'Execution time', 'clone-master' ), info.limits?.max_execution_time + 's'],
                        [__( 'Chunk size', 'clone-master' ), fmt(info.limits?.recommended_chunk)],
                        [__( 'Table prefix', 'clone-master' ), info.mysql?.prefix],
                        [__( 'Table count', 'clone-master' ), info.mysql?.table_count],
                        ['Multisite', info.wordpress?.multisite ? __( 'Yes', 'clone-master' ) : __( 'No', 'clone-master' )],
                    ].map(([l, v]) =>
                        h('div', { className: 'wpcm-info-row', key: l },
                            h('span', { className: 'wpcm-info-lbl' }, l),
                            h('span', { className: 'wpcm-info-val' }, v || 'N/A')
                        )
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, __( 'PHP Extensions', 'clone-master' )),
                h('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '7px' } },
                    Object.entries(info.extensions || {}).map(([name, ext]) =>
                        h('span', { key: name, className: 'wpcm-badge ' + (ext.loaded ? 'wpcm-badge-ok' : (ext.required ? 'wpcm-badge-bad' : 'wpcm-badge-warn')) },
                            h(Ico, { n: ext.loaded ? 'check' : 'x', s: 12 }), name
                        )
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, __( 'Directory permissions', 'clone-master' )),
                h('div', { className: 'wpcm-info-grid' },
                    Object.entries(info.writable || {}).map(([dir, ok]) =>
                        h('div', { className: 'wpcm-info-row', key: dir },
                            h('span', { className: 'wpcm-info-lbl' }, dir),
                            h('span', { className: 'wpcm-badge ' + (ok ? 'wpcm-badge-ok' : 'wpcm-badge-bad') },
                                h(Ico, { n: ok ? 'check' : 'x', s: 12 }), ok ? __( 'Writable', 'clone-master' ) : __( 'Not writable', 'clone-master' )
                            )
                        )
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, __( 'WordPress details', 'clone-master' )),
                h('div', { className: 'wpcm-info-grid' },
                    [
                        [__( 'Site URL', 'clone-master' ), info.wordpress?.site_url],
                        [__( 'Active theme', 'clone-master' ), info.wordpress?.theme],
                        [__( 'Active plugins', 'clone-master' ), (info.wordpress?.plugins_active || 0) + ' / ' + (info.wordpress?.plugins_total || 0)],
                        [__( 'Permalink structure', 'clone-master' ), info.wordpress?.permalink || __( 'Default', 'clone-master' )],
                        [__( 'ABSPATH', 'clone-master' ), info.wordpress?.abspath],
                        [__( 'wp-content dir', 'clone-master' ), info.wordpress?.content_dir],
                    ].map(([l, v]) =>
                        h('div', { className: 'wpcm-info-row', key: l },
                            h('span', { className: 'wpcm-info-lbl' }, l),
                            h('span', { className: 'wpcm-info-val mono', style: l.includes('ABSPATH') || l.includes('wp-content') ? { fontSize: '11px' } : {} }, v || 'N/A')
                        )
                    )
                )
            )
        );
    }

    /* =========================================================
       APP PRINCIPALE
       ========================================================= */
    function App() {
        const [tab, setTab] = useState('export');
        // Quand on clique "Restaurer" depuis l'onglet Sauvegardes, on passe à l'onglet Import
        // en pré-chargeant le fichier via AJAX (la sauvegarde est déjà sur le serveur, on
        // simule un extract direct depuis le path serveur)
        const [restoreTarget, setRestoreTarget] = useState(null);

        const handleRestore = backup => {
            // On redirige vers Import avec les infos de la sauvegarde locale
            setRestoreTarget(backup);
            setTab('import');
        };

        // Quand on change d'onglet manuellement, on réinitialise la cible de restauration
        const switchTab = id => { if (id !== 'import') setRestoreTarget(null); setTab(id); };

        const tabs = [
            { id: 'export',  label: __( 'Export', 'clone-master' ),  icon: 'export'  },
            { id: 'import',  label: __( 'Restore', 'clone-master' ), icon: 'restore' },
            { id: 'backups', label: __( 'Backups', 'clone-master' ), icon: 'archive' },
            { id: 'server',  label: __( 'Server', 'clone-master' ),  icon: 'server'  },
        ];

        return h('div', { className: 'wpcm-app' },
            h('div', { className: 'wpcm-header' },
                h('div', { className: 'wpcm-header-logo' }, h(Ico, { n: 'layers', s: 20 })),
                h('div', { className: 'wpcm-header-text' },
                    h('h1', null, 'Clone Master'),
                    h('p', null, __( 'Backup, migration and restore for your WordPress site', 'clone-master' ))
                ),
                h('a', {
                    href: 'https://buymeacoffee.com/assistouest',
                    target: '_blank',
                    rel: 'noopener noreferrer',
                    className: 'wpcm-coffee-btn',
                    title: (wpcmData.i18n && wpcmData.i18n.supportUs) || __( 'Support open-source', 'clone-master' ),
                }, h(Ico, { n: 'coffee', s: 15 }), h('span', { className: 'wpcm-coffee-label' }, (wpcmData.i18n && wpcmData.i18n.supportUs) || __( 'Support open-source', 'clone-master' )))
            ),

            h('div', { className: 'wpcm-tabs' },
                tabs.map(t => h('button', {
                    key: t.id,
                    className: 'wpcm-tab' + (tab === t.id ? ' active' : ''),
                    onClick: () => switchTab(t.id)
                }, h(Ico, { n: t.icon, s: 15 }), h('span', { className: 'tab-lbl' }, t.label)))
            ),

            tab === 'export'  && h(ExportTab),
            tab === 'import'  && (restoreTarget
                ? h(ImportTabWithRestore, { key: restoreTarget.name, _backupName: restoreTarget.name })
                : h(ImportTab,            { key: 'manual' })
            ),
            tab === 'backups' && h(BackupsTab, { onRestore: handleRestore }),
            tab === 'server'  && h(ServerTab)
        );
    }

    /* =========================================================
       ImportTab pour restauration depuis une sauvegarde serveur
       (pas de téléversement — l'archive est déjà sur le disque)
       ========================================================= */
    const ImportTabOrig = ImportTab;

    function ImportTabWithRestore({ _backupName }) {
        const [phase, setPhase] = useState('analyzing'); // analyzing | opts | importing | done | error
        const [progress, setProgress] = useState(0);
        const [message, setMessage] = useState('');
        const [manifest, setManifest] = useState(null);
        const [sessionId, setSessionId] = useState('');
        const [newUrl, setNewUrl] = useState(wpcmData.siteUrl);
        const [opts, setOpts] = useState({ reset_permalinks: true, block_indexing: false });
        const [logs, setLogs] = useState([]);
        const [error, setError] = useState(null);

        const log = useCallback((msg, type = '') => {
            setLogs(p => [...p, { time: new Date().toLocaleTimeString(), msg, type }]);
        }, []);

        // Analyse automatique au montage
        useEffect(() => {
            (async () => {
                try {
                    log( __( 'Analysing backup…', 'clone-master' ));
                    const ext = await apiRetry('wpcm_import', { step: 'extract', session_id: '', backup_name: _backupName, new_url: newUrl }, MAX_RETRIES, log);
                    setSessionId(ext.session_id);
                    setProgress(ext.progress); setMessage(ext.message);
                    if (ext.manifest) setManifest(ext.manifest);
                    log(ext.message, 'success');
                    setPhase('opts');
                } catch (e) { setError(e.message); log( __( 'Error: ', 'clone-master' ) + e.message, 'error'); setPhase('error'); }
            })();
        }, []);

        const runImport = async () => {
            setPhase('importing'); setError(null);
            try {
                // ── Generate a cryptographic token client-side (same pattern as import tab) ──
                const _rawBytes = new Uint8Array(32);
                crypto.getRandomValues(_rawBytes);
                const clientToken = Array.from(_rawBytes).map(b => b.toString(16).padStart(2, '0')).join('');

                log( __( 'Preparing installer…', 'clone-master' ));
                const prep = await apiRetry('wpcm_import', {
                    step: 'prepare', session_id: sessionId, backup_name: _backupName,
                    new_url: newUrl, import_opts: JSON.stringify(opts),
                    installer_token: clientToken,
                }, MAX_RETRIES, log);
                const { installer_url: url } = prep;
                log( __( 'Installer ready.', 'clone-master' ), 'success');

                let step = 'database';
                let dbIdx = 0, dbOff = 0, dbQ = 0, dbE = 0;
                let srIdx = 0, srOff = 0, srR = 0, srC = 0, srS = 0;

                while (step) {
                    const fd = new FormData();
                    fd.append('installer_token', clientToken); fd.append('step', step);
                    if (step === 'database') { fd.append('file_index', String(dbIdx)); fd.append('byte_offset', String(dbOff)); fd.append('queries_total', String(dbQ)); fd.append('errors_total', String(dbE)); }
                    if (step === 'replace_urls') { fd.append('table_index', String(srIdx)); fd.append('row_offset', String(srOff)); fd.append('sr_rows', String(srR)); fd.append('sr_cells', String(srC)); fd.append('sr_serial', String(srS)); }

                    const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), 600000);
                    let resp;
                    try { resp = await fetch(url, { method: 'POST', body: fd, signal: ctrl.signal }); }
                    catch (fe) { clearTimeout(t); throw new Error('Network error: ' + fe.message); }
                    clearTimeout(t);

                    let data;
                    const raw = await resp.text();
                    try { data = JSON.parse(raw.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, '')); }
                    catch { throw new Error('Invalid response on "' + step + '" : ' + raw.substring(0, 200).replace(/<[^>]+>/g, '').trim()); }
                    if (!data.success) throw new Error(data.data?.message || 'Installer error on step "' + step + '"');

                    const d = data.data;
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || step + ' done', 'success');
                    if (d.errors_log) d.errors_log.forEach(e => log('  SQL : ' + e, 'warn'));
                    if (step === 'database') { dbIdx = d.file_index ?? dbIdx; dbOff = d.byte_offset ?? 0; dbQ = d.queries ?? dbQ; dbE = d.errors ?? dbE; }
                    if (step === 'replace_urls') { srIdx = d.table_index ?? srIdx; srOff = d.row_offset ?? 0; srR = d.rows ?? srR; srC = d.cells ?? srC; srS = d.serial ?? srS; }
                    step = d.next_step || null;
                }
                setPhase('done'); log( __( 'Migration completed successfully.', 'clone-master' ), 'success');
            } catch (e) { setError(e.message); log( __( 'Error: ', 'clone-master' ) + e.message, 'error'); setPhase('error'); }
        };

        const renderPhase = () => {
            switch (phase) {
                case 'analyzing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'db' }), 'Reading backup…'),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' }, h('span', { className: 'wpcm-spinner' }), 'Analysing archive…')
                );

                case 'opts': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'settings' }), __( 'Restore settings', 'clone-master' )),
                    h('p', { className: 'wpcm-card-desc' }, 'Check the settings and configure the options before starting the restore.'),
                    manifest && h(Fragment, null,
                        h('div', { className: 'wpcm-opts-section-title', style: { marginBottom: '10px' } }, h(Ico, { n: 'db', s: 14 }), 'Archive source'),
                        h('div', { className: 'wpcm-manifest-grid' },
                            [
                                ['URL source', manifest.site_url],
                                ['Version WP', manifest.wp_version],
                                ['Created on', manifest.created_at],
                                ['Active theme', manifest.active_theme],
                                ['Extensions', (manifest.active_plugins || []).length + ' actives'],
                                ['Tables', manifest.tables_count + ' tables DB'],
                            ].map(([lbl, val]) => h('div', { className: 'wpcm-manifest-item', key: lbl },
                                h('div', { className: 'wpcm-manifest-lbl' }, lbl),
                                h('div', { className: 'wpcm-manifest-val' }, val || 'N/A')
                            ))
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'link', s: 14 }), __( 'Destination URL', 'clone-master' )),
                        h('div', { className: 'wpcm-field', style: { marginBottom: 0 } },
                            h('label', { className: 'wpcm-label' }, __( 'New site URL', 'clone-master' )),
                            h('input', { className: 'wpcm-input', type: 'url', value: newUrl, onChange: e => setNewUrl(e.target.value) }),
                            h('span', { className: 'wpcm-input-hint' }, __( 'Leave as-is if restoring on the same domain.', 'clone-master' ))
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'settings', s: 14 }), 'Advanced options'),
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.reset_permalinks, onChange: e => setOpts(o => ({ ...o, reset_permalinks: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Regenerate permalinks', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, 'Recommended after every restore.')
                            )
                        ),
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.block_indexing, onChange: e => setOpts(o => ({ ...o, block_indexing: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Hide site from search engines', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Asks Google & Bing not to index this site (WordPress "Discourage search engines" option). Recommended during post-migration validation — uncheck once verified.', 'clone-master' ))
                            )
                        )
                    ),
                    h('div', { style: { display: 'flex', gap: '10px', marginTop: '8px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runImport },
                            h(Ico, { n: 'restore', s: 16 }), __( 'Start restore', 'clone-master' )
                        )
                    )
                );

                case 'importing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'restore' }), __( 'Restoring…', 'clone-master' )),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' }, h('span', { className: 'wpcm-spinner' }), __( 'Processing — please do not close this page.', 'clone-master' ))
                );

                case 'done': return h('div', { className: 'wpcm-card' },
                    h(ProgressBar, { progress: 100, message: __( 'Restore complete.', 'clone-master' ), done: true }),
                    h('div', { className: 'wpcm-complete-box' },
                        h('div', { className: 'wpcm-complete-icon' }, h(Ico, { n: 'check', s: 26 })),
                        h('h3', { className: 'wpcm-complete-title' }, __( 'Your site has been restored successfully!', 'clone-master' )),
                        h('p', { className: 'wpcm-complete-sub' }, __( 'Log back into WordPress to verify everything is working correctly.', 'clone-master' ))
                    )
                );

                case 'error': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'x' }), __( 'An error occurred', 'clone-master' )),
                    h('div', { className: 'wpcm-alert wpcm-alert-error', style: { marginTop: 0 } }, h(Ico, { n: 'x', s: 15 }), error || __( 'Unknown error', 'clone-master' ))
                );

                default: return null;
            }
        };

        return h(Fragment, null, renderPhase(), h(LogViewer, { entries: logs }));
    }

        /* =========================================================
       APP PRINCIPALE (version finale)
       ========================================================= */
    function AppFinal() {
        const [tab, setTab] = useState('export');
        const [restoreTarget, setRestoreTarget] = useState(null);

        const switchTab = id => { if (id !== 'import') setRestoreTarget(null); setTab(id); };

        const handleRestore = backup => { setRestoreTarget(backup); setTab('import'); };

        const tabs = [
            { id: 'export',  label: __( 'Export', 'clone-master' ),  icon: 'export'  },
            { id: 'import',  label: __( 'Restore', 'clone-master' ), icon: 'restore' },
            { id: 'backups', label: __( 'Backups', 'clone-master' ), icon: 'archive' },
            { id: 'server',  label: __( 'Server', 'clone-master' ),  icon: 'server'  },
        ];

        return h('div', { className: 'wpcm-app' },
            h('div', { className: 'wpcm-header' },
                h('div', { className: 'wpcm-header-logo' }, h(Ico, { n: 'layers', s: 20 })),
                h('div', { className: 'wpcm-header-text' },
                    h('h1', null, 'Clone Master'),
                    h('p', null, __( 'Backup, migration and restore for your WordPress site', 'clone-master' ))
                ),
                h('a', {
                    href: 'https://buymeacoffee.com/assistouest',
                    target: '_blank',
                    rel: 'noopener noreferrer',
                    className: 'wpcm-coffee-btn',
                    title: (wpcmData.i18n && wpcmData.i18n.supportUs) || __( 'Support open-source', 'clone-master' ),
                }, h(Ico, { n: 'coffee', s: 15 }), h('span', { className: 'wpcm-coffee-label' }, (wpcmData.i18n && wpcmData.i18n.supportUs) || __( 'Support open-source', 'clone-master' )))
            ),
            h('div', { className: 'wpcm-tabs' },
                tabs.map(t => h('button', {
                    key: t.id,
                    className: 'wpcm-tab' + (tab === t.id ? ' active' : ''),
                    onClick: () => switchTab(t.id)
                }, h(Ico, { n: t.icon, s: 15 }), h('span', { className: 'tab-lbl' }, t.label)))
            ),
            tab === 'export'  && h(ExportTab),
            tab === 'import'  && (restoreTarget
                ? h(ImportTabWithRestore, { key: restoreTarget.name, _backupName: restoreTarget.name })
                : h(ImportTab,            { key: 'manual' })
            ),
            tab === 'backups' && h(BackupsTab, { onRestore: handleRestore }),
            tab === 'server'  && h(ServerTab)
        );
    }

    /* Mount */
    const root = document.getElementById('wpcm-admin-root');
    if (root) {
        if (wp.element.createRoot) wp.element.createRoot(root).render(h(AppFinal));
        else wp.element.render(h(AppFinal), root);
    }

})();
