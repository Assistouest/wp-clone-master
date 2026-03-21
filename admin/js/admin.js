(function () {
    'use strict';

    const { createElement: h, useState, useEffect, useRef, useCallback, Fragment } = wp.element;

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
                            let m = 'Le serveur a retourné une réponse inattendue. ';
                            if (txt.includes('Fatal error')) m += 'Erreur PHP fatale détectée. ';
                            if (txt.includes('Maximum execution time')) m += 'Délai PHP dépassé. ';
                            if (txt.includes('Allowed memory size')) m += 'Mémoire PHP insuffisante. ';
                            throw new Error(m);
                        }
                    });
                }
                return r.json();
            })
            .then(r => { if (!r.success) throw new Error(r.data?.message || r.data || 'Erreur inconnue'); return r.data; })
            .catch(err => { clearTimeout(timer); if (err.name === 'AbortError') throw new Error('Délai dépassé — le serveur est peut-être encore en cours de traitement.'); throw err; });
    }

    async function apiRetry(action, data, retries = MAX_RETRIES, log = null) {
        for (let i = 0; i <= retries; i++) {
            try { return await api(action, data); }
            catch (err) {
                const retry = err.message.includes('Délai') || err.message.includes('fetch') || err.message.includes('500') || err.message.includes('Network');
                if (i < retries && retry) {
                    const w = (i + 1) * 3;
                    if (log) log(`⚠ ${err.message} — Nouvelle tentative dans ${w}s…`, 'warn');
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
            throw new Error('Vérification de sécurité échouée (session expirée ?). Rechargez la page et réessayez.');
        }
        let json;
        try { json = JSON.parse(text); } catch {
            let msg = 'Réponse non-JSON du serveur (HTTP ' + resp.status + '). ';
            if (text.includes('Fatal error') || text.includes('Parse error')) msg += 'Erreur PHP fatale. ';
            if (text.includes('Maximum execution time')) msg += 'Délai PHP dépassé. ';
            if (text.includes('Allowed memory size')) msg += 'Mémoire insuffisante. ';
            if (resp.status === 413) msg += 'Fichier trop volumineux (413 Request Entity Too Large). ';
            if (!text.trim()) msg += 'Réponse vide. ';
            else msg += 'Extrait : ' + text.substring(0, 120).replace(/<[^>]+>/g, '').trim();
            throw new Error(msg);
        }
        if (!json || typeof json !== 'object') {
            throw new Error('Réponse inattendue : ' + String(json).substring(0, 100));
        }
        if (!json.success) {
            const d = json.data;
            const msg = (d && typeof d === 'object' ? d.message : d) || 'Erreur serveur sans détail';
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
                h('span', { className: 'wpcm-log-card-label' }, h(Ico, { n: 'db', s: 15 }), 'Journal d\'activité'),
                h('button', { className: 'wpcm-log-toggle', onClick: () => setCollapsed(c => !c) },
                    collapsed ? 'Afficher' : 'Réduire'
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
    function ExportTab() {
        const [running, setRunning] = useState(false);
        const [progress, setProgress] = useState(0);
        const [message, setMessage] = useState('');
        const [done, setDone] = useState(false);
        const [result, setResult] = useState(null);
        const [logs, setLogs] = useState([]);
        const [error, setError] = useState(null);

        const log = useCallback((msg, type = '') => {
            setLogs(p => [...p, { time: new Date().toLocaleTimeString(), msg, type }]);
        }, []);

        const run = async () => {
            setRunning(true); setProgress(0); setDone(false); setResult(null); setError(null); setLogs([]);
            let sid = '', next = 'init';
            try {
                while (next) {
                    const d = await apiRetry('wpcm_export', { step: next, session_id: sid }, MAX_RETRIES, log);
                    sid = d.session_id || sid;
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || next + ' terminé', 'success');
                    if (d.download_url) setResult(d);
                    next = d.next_step || null;
                }
                setDone(true); log('Sauvegarde créée avec succès.', 'success');
            } catch (e) { setError(e.message); log('Erreur : ' + e.message, 'error'); }
            finally { setRunning(false); }
        };

        return h(Fragment, null,
            h('div', { className: 'wpcm-intro' },
                h('div', { className: 'wpcm-intro-ico' }, h(Ico, { n: 'export', s: 18 })),
                h('div', null,
                    h('h4', null, 'Sauvegarde complète de votre site'),
                    h('p', null, 'Votre base de données, vos thèmes, extensions, médias et fichiers de configuration sont archivés de façon sécurisée. Vous pouvez ensuite les restaurer sur n\'importe quel hébergement.')
                )
            ),
            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'export' }), 'Exporter votre site'),
                h('p', { className: 'wpcm-card-desc' }, 'Crée une archive complète de votre installation WordPress, prête à migrer ou à conserver en toute sécurité.'),

                !running && !done && h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: run },
                    h(Ico, { n: 'export', s: 16 }), 'Lancer la sauvegarde'
                ),

                running && h(Fragment, null,
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        'Traitement en cours, veuillez patienter…'
                    )
                ),

                done && h(Fragment, null,
                    h(ProgressBar, { progress: 100, message: 'Sauvegarde terminée.', done: true }),
                    result && h('div', { className: 'wpcm-alert wpcm-alert-success', style: { marginTop: '16px' } },
                        h(Ico, { n: 'check', s: 16 }),
                        h('div', null,
                            h('strong', null, result.filename), ' (', result.size, ')',
                            h('div', { style: { marginTop: '8px' } },
                                h('a', { href: result.download_url, className: 'wpcm-btn wpcm-btn-success wpcm-btn-sm', style: { textDecoration: 'none' } },
                                    h(Ico, { n: 'download', s: 13 }), 'Télécharger l\'archive'
                                )
                            )
                        )
                    ),
                    h('button', {
                        className: 'wpcm-btn wpcm-btn-ghost', style: { marginTop: '12px' },
                        onClick: () => { setDone(false); setProgress(0); setResult(null); setLogs([]); }
                    }, 'Nouvelle sauvegarde')
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
            locale:           '',
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
                        if (attempt >= 3) throw new Error('Le serveur rejette les chunks même à ' + Math.round(chunkSize / 1024) + ' Ko (HTTP 413). Vérifiez upload_max_filesize dans php.ini.');
                        chunkSize = Math.max(MIN_CHUNK, Math.floor(chunkSize / 2));
                        log('⚠ HTTP 413 — chunk réduit à ' + Math.round(chunkSize / 1024) + ' Ko', 'warn');
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

                    log('Envoi adaptatif de ' + (fileSize / 1048576).toFixed(1) + ' Mo — taille initiale : ' + Math.round(chunkSize / 1024) + ' Ko');

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
                                if (result === null) throw new Error('Impossible d\'envoyer ce chunk même après réduction.');
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
                        setMessage(sentMo + ' / ' + totalMo + ' Mo envoyés — ' + speedMbps + ' Mbps');

                        // Adapter la taille du prochain chunk
                        if (smoothedBps !== null) {
                            const ideal = Math.floor(smoothedBps * TARGET_MS * 0.8); // 80 % du débit mesuré (marge)
                            const next  = Math.min(SERVER_CAP, Math.max(MIN_CHUNK, ideal));
                            if (Math.abs(next - chunkSize) / chunkSize > 0.15) { // n'ajuster que si >15 % d'écart
                                const prevKo = Math.round(chunkSize / 1024);
                                chunkSize = next;
                                log('  Débit : ' + speedMbps + ' Mbps — chunk ajusté : ' + prevKo + ' Ko → ' + Math.round(next / 1024) + ' Ko', 'success');
                            }
                        }

                        if (r.complete) { fp = r.file_path; sid = r.session_id; log(r.message || 'Envoi terminé', 'success'); }
                    }
                } else {
                    log('Envoi direct de l\'archive (' + (file.size / 1048576).toFixed(1) + ' Mo)…');
                    const r = await api('wpcm_import_upload', { backup_file: file });
                    fp = r.file_path; sid = r.session_id;
                    log('Archive reçue avec succès.', 'success');
                }
                setProgress(10);
                log('Analyse de l\'archive…');
                const ext = await apiRetry('wpcm_import', { step: 'extract', session_id: sid, file_path: fp, new_url: newUrl }, MAX_RETRIES, log);
                setSessionId(ext.session_id); setFilePath(fp);
                setProgress(ext.progress); setMessage(ext.message);
                if (ext.manifest) setManifest(ext.manifest);
                log(ext.message, 'success');
                setPhase('opts');
            } catch (e) { setError(e.message); log('Erreur : ' + e.message, 'error'); setPhase('error'); }
        };

        const runImport = async () => {
            setPhase('importing'); setError(null);
            try {
                log('Préparation de l\'installeur…');
                const prep = await apiRetry('wpcm_import', {
                    step: 'prepare', session_id: sessionId, file_path: filePath,
                    new_url: newUrl, import_opts: JSON.stringify(opts),
                }, MAX_RETRIES, log);
                const { installer_url: url, auth_token: tok } = prep;
                log('Installeur prêt.', 'success');

                let step = 'database';
                let dbIdx = 0, dbOff = 0, dbQ = 0, dbE = 0;
                let srIdx = 0, srOff = 0, srR = 0, srC = 0, srS = 0;
                let lastResult = null; // dernier résultat de l'installeur (utilisé après la boucle)

                while (step) {
                    const fd = new FormData();
                    fd.append('auth_token', tok); fd.append('step', step);
                    if (step === 'database') { fd.append('file_index', String(dbIdx)); fd.append('byte_offset', String(dbOff)); fd.append('queries_total', String(dbQ)); fd.append('errors_total', String(dbE)); }
                    if (step === 'replace_urls') { fd.append('table_index', String(srIdx)); fd.append('row_offset', String(srOff)); fd.append('sr_rows', String(srR)); fd.append('sr_cells', String(srC)); fd.append('sr_serial', String(srS)); }

                    const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), 600000);
                    let resp;
                    try { resp = await fetch(url, { method: 'POST', body: fd, signal: ctrl.signal }); }
                    catch (fe) { clearTimeout(t); throw new Error('Erreur réseau sur l\'étape "' + step + '" : ' + fe.message); }
                    clearTimeout(t);

                    let data;
                    const raw = await resp.text();
                    try { data = JSON.parse(raw.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, '')); }
                    catch {
                        let m = 'Réponse invalide sur l\'étape "' + step + '". ';
                        if (raw.includes('Fatal error')) m += 'Erreur PHP fatale. ';
                        if (raw.includes('Maximum execution time')) m += 'Délai PHP dépassé. ';
                        if (raw.includes('Allowed memory size')) m += 'Mémoire insuffisante. ';
                        if (!raw.trim()) m += 'Réponse vide — le serveur a peut-être expiré. ';
                        throw new Error(m + ' | ' + raw.substring(0, 200).replace(/<[^>]+>/g, '').trim());
                    }
                    if (!data.success) throw new Error(data.data?.message || 'Erreur installeur sur "' + step + '"');

                    const d = data.data;
                    lastResult = d;
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || step + ' terminé', 'success');
                    if (d.errors_log) d.errors_log.forEach(e => log('  SQL : ' + e, 'warn'));

                    if (step === 'database') { dbIdx = d.file_index ?? dbIdx; dbOff = d.byte_offset ?? 0; dbQ = d.queries ?? dbQ; dbE = d.errors ?? dbE; }
                    if (step === 'replace_urls') { srIdx = d.table_index ?? srIdx; srOff = d.row_offset ?? 0; srR = d.rows ?? srR; srC = d.cells ?? srC; srS = d.serial ?? srS; }
                    step = d.next_step || null;
                }
                if (lastResult && lastResult.deactivated_plugins && lastResult.deactivated_plugins.length) {
                    setDeactivated(lastResult.deactivated_plugins);
                    log('Plugins mis en veille (' + lastResult.deactivated_plugins.length + ') : ' + lastResult.deactivated_plugins.join(', '), 'warn');
                }
                setPhase('done'); log('Migration terminée avec succès.', 'success');
            } catch (e) { setError(e.message); log('Erreur : ' + e.message, 'error'); setPhase('error'); }
        };

        const reset = () => { setPhase('idle'); setProgress(0); setFile(null); setManifest(null); setLogs([]); setError(null); setSessionId(''); setFilePath(''); setDeactivated([]); };

        // Rendu : une seule section visible selon la phase courante
        const renderPhase = () => {
            switch (phase) {

                case 'idle': return h(Fragment, null,
                    h('div', { className: 'wpcm-intro' },
                        h('div', { className: 'wpcm-intro-ico' }, h(Ico, { n: 'import', s: 18 })),
                        h('div', null,
                            h('h4', null, 'Restaurer ou migrer votre site'),
                            h('p', null, 'Chargez une archive WP Clone Master pour restaurer votre site sur cet hébergement. Toutes les URLs sont remplacées automatiquement, y compris dans les données sérialisées.')
                        )
                    ),
                    h('div', { className: 'wpcm-card' },
                        h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'import' }), 'Sélectionner une archive'),
                        h('p', { className: 'wpcm-card-desc' }, 'Déposez votre fichier .zip ou cliquez pour le sélectionner. Les fichiers volumineux sont envoyés en plusieurs parties pour éviter les limitations serveur.'),
                        h('div', {
                            className: 'wpcm-upload-zone' + (dragOver ? ' dragover' : '') + (file ? ' has-file' : ''),
                            onClick: () => fileRef.current && fileRef.current.click(),
                            onDragOver: e => { e.preventDefault(); setDragOver(true); },
                            onDragLeave: () => setDragOver(false),
                            onDrop: handleDrop,
                        },
                            h('input', { type: 'file', ref: fileRef, accept: '.zip', style: { display: 'none' }, onChange: e => setFile(e.target.files[0]) }),
                            h('div', { className: 'wpcm-upload-ico' }, h(Ico, { n: 'upload', s: 22 })),
                            h('p', { className: 'wpcm-upload-name' }, file ? file.name + ' — ' + (file.size / 1048576).toFixed(1) + ' Mo' : 'Déposez votre archive ici'),
                            h('p', { className: 'wpcm-upload-hint' }, file ? 'Cliquez pour choisir un autre fichier' : 'Format accepté : .zip — ou cliquez pour parcourir')
                        ),
                        file && h(Fragment, null,
                            h('div', { className: 'wpcm-field', style: { marginTop: '20px' } },
                                h('label', { className: 'wpcm-label' }, 'URL de destination'),
                                h('input', { className: 'wpcm-input', type: 'url', value: newUrl, onChange: e => setNewUrl(e.target.value), placeholder: 'https://votre-domaine.com' }),
                                h('span', { className: 'wpcm-input-hint' }, 'Laissez l\'URL actuelle si vous restaurez sur le même domaine.')
                            ),
                            newUrl !== wpcmData.siteUrl && h('div', { className: 'wpcm-alert wpcm-alert-warning' },
                                h(Ico, { n: 'warn', s: 15 }),
                                'Les URLs seront remplacées dans toute la base de données → ' + newUrl
                            ),
                            h('div', { style: { marginTop: '16px' } },
                                h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runUploadAndExtract },
                                    h(Ico, { n: 'upload', s: 16 }), 'Analyser l\'archive'
                                )
                            )
                        )
                    )
                );

                case 'uploading': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'upload' }), 'Envoi de l\'archive en cours…'),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        'Votre fichier est en cours d\'envoi — veuillez ne pas fermer cette page.'
                    )
                );

                case 'opts': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'settings' }), 'Paramètres de restauration'),
                    h('p', { className: 'wpcm-card-desc' }, 'Vérifiez les informations de l\'archive et configurez les options avant de lancer la restauration.'),
                    manifest && h(Fragment, null,
                        h('div', { className: 'wpcm-opts-section-title', style: { marginBottom: '10px' } }, h(Ico, { n: 'db', s: 14 }), 'Source de l\'archive'),
                        h('div', { className: 'wpcm-manifest-grid' },
                            [
                                ['URL source', manifest.site_url],
                                ['Version WP', manifest.wp_version],
                                ['Créée le', manifest.created_at],
                                ['Thème actif', manifest.active_theme],
                                ['Extensions', (manifest.active_plugins || []).length + ' actives'],
                                ['Tables', manifest.tables_count + ' tables DB'],
                            ].map(([lbl, val]) => h('div', { className: 'wpcm-manifest-item', key: lbl },
                                h('div', { className: 'wpcm-manifest-lbl' }, lbl),
                                h('div', { className: 'wpcm-manifest-val' }, val || 'N/A')
                            ))
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'link', s: 14 }), 'URL de destination'),
                        h('div', { className: 'wpcm-field', style: { marginBottom: 0 } },
                            h('label', { className: 'wpcm-label' }, 'Nouvelle URL du site'),
                            h('input', { className: 'wpcm-input', type: 'url', value: newUrl, onChange: e => setNewUrl(e.target.value), placeholder: 'https://votre-domaine.com' }),
                            h('span', { className: 'wpcm-input-hint' }, 'Toutes les occurrences de l\'ancienne URL seront remplacées dans la base de données.')
                        ),
                        newUrl !== wpcmData.siteUrl && h('div', { className: 'wpcm-alert wpcm-alert-warning', style: { marginTop: '10px' } },
                            h(Ico, { n: 'warn', s: 15 }),
                            (manifest && manifest.site_url ? manifest.site_url : '(source)') + ' → ' + newUrl
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'globe', s: 14 }), 'Langue'),
                        h('div', { className: 'wpcm-field', style: { marginBottom: 0 } },
                            h('label', { className: 'wpcm-label' }, 'Langue WordPress'),
                            h('select', { className: 'wpcm-input', value: opts.locale, onChange: e => setOpts(o => ({ ...o, locale: e.target.value })) },
                                h('option', { value: '' }, 'Conserver la langue de l\'archive' + (manifest && manifest.locale ? ' (' + manifest.locale + ')' : '')),
                                h('option', { value: 'fr_FR' }, 'Français (France)'),
                                h('option', { value: 'fr_BE' }, 'Français (Belgique)'),
                                h('option', { value: 'fr_CA' }, 'Français (Canada)'),
                                h('option', { value: 'en_US' }, 'English (US)'),
                                h('option', { value: 'es_ES' }, 'Español'),
                                h('option', { value: 'de_DE' }, 'Deutsch'),
                                h('option', { value: 'it_IT' }, 'Italiano'),
                                h('option', { value: 'nl_NL' }, 'Nederlands'),
                                h('option', { value: 'pt_PT' }, 'Português'),
                                h('option', { value: 'pt_BR' }, 'Português (Brasil)'),
                                h('option', { value: 'ja' }, '日本語'),
                                h('option', { value: 'zh_CN' }, '中文 (简体)')
                            )
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'settings', s: 14 }), 'Options avancées'),

                        /* Permaliens */
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.reset_permalinks, onChange: e => setOpts(o => ({ ...o, reset_permalinks: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, 'Régénérer les permaliens'),
                                h('span', { className: 'wpcm-toggle-sub' }, 'Supprime les règles de réécriture mises en cache — WordPress les recréera au premier chargement.')
                            )
                        ),

                        /* Visibilité moteurs de recherche */
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.block_indexing, onChange: e => setOpts(o => ({ ...o, block_indexing: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, 'Masquer le site aux moteurs de recherche'),
                                h('span', { className: 'wpcm-toggle-sub' }, 'Demande à Google & Bing de ne pas indexer ce site (option "Décourager les moteurs de recherche" dans WordPress). Recommandé pendant la vérification post-migration — à décocher une fois le site validé.')
                            )
                        ),

                    ),
                    h('div', { style: { display: 'flex', gap: '10px', marginTop: '8px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runImport },
                            h(Ico, { n: 'restore', s: 16 }), 'Lancer la restauration'
                        ),
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, 'Annuler')
                    )
                );

                case 'importing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'restore' }), 'Restauration en cours…'),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        'Traitement en cours — veuillez ne pas fermer cette page.'
                    )
                );

                case 'done': return h('div', { className: 'wpcm-card' },
                    h(ProgressBar, { progress: 100, message: 'Restauration terminée.', done: true }),
                    h('div', { className: 'wpcm-complete-box' },
                        h('div', { className: 'wpcm-complete-icon' }, h(Ico, { n: 'check', s: 26 })),
                        h('h3', { className: 'wpcm-complete-title' }, 'Votre site a été restauré avec succès !'),
                        h('p', { className: 'wpcm-complete-sub' }, 'La migration est terminée. Veuillez vous reconnecter à l\'administration WordPress pour vérifier que tout fonctionne correctement.'),
                        deactivated.length > 0 && h('div', { className: 'wpcm-alert wpcm-alert-warning', style: { textAlign: 'left', marginBottom: '16px' } },
                            h(Ico, { n: 'warn', s: 15 }),
                            h('div', null,
                                h('strong', null, deactivated.length + ' plugin(s) mis en veille pour votre sécurité'),
                                h('p', { style: { margin: '4px 0 8px', fontSize: '12px', lineHeight: '1.5' } },
                                    'Ces extensions de sécurité ou de cache ont été désactivées car elles peuvent bloquer l\'accès après migration (pare-feu WAF, CAPTCHA lié au domaine source, etc.). Réactivez-les manuellement après vous être reconnecté.'
                                ),
                                h('ul', { style: { margin: '0', paddingLeft: '18px', fontSize: '11.5px', fontFamily: 'var(--c-mono)' } },
                                    deactivated.map((p, i) => h('li', { key: i }, p))
                                )
                            )
                        ),
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, 'Nouvelle restauration')
                    )
                );

                case 'error': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'x' }), 'Une erreur est survenue'),
                    h('div', { className: 'wpcm-alert wpcm-alert-error', style: { marginTop: 0 } },
                        h(Ico, { n: 'x', s: 15 }), error || 'Erreur inconnue'
                    ),
                    h('div', { style: { marginTop: '16px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, '← Recommencer')
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
            if (!confirm('Supprimer définitivement cette sauvegarde ?\n\n' + name)) return;
            setDeleting(name);
            try { await api('wpcm_delete_backup', { backup_name: name }); load(); }
            catch (e) { alert('Erreur : ' + e.message); }
            finally { setDeleting(null); }
        };

        const dlUrl = n => wpcmData.ajaxUrl + '?action=wpcm_download_backup&backup_name=' + encodeURIComponent(n) + '&nonce=' + wpcmData.nonce;

        return h('div', { className: 'wpcm-card' },
            h('div', { style: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' } },
                h('h3', { className: 'wpcm-card-title', style: { margin: 0 } }, h(Ico, { n: 'archive' }), 'Vos sauvegardes'),
                h('button', { className: 'wpcm-btn wpcm-btn-ghost wpcm-btn-sm', onClick: load }, 'Actualiser')
            ),
            h('p', { className: 'wpcm-card-desc' }, 'Toutes vos archives sont listées ici. Téléchargez-les pour les conserver ou restaurez-les directement sur ce site.'),

            loading && h('div', { style: { textAlign: 'center', padding: '32px' } }, h('span', { className: 'wpcm-spinner' })),

            !loading && backups.length === 0 && h('div', { className: 'wpcm-empty' },
                h('div', { className: 'wpcm-empty-ico' }, h(Ico, { n: 'archive', s: 22 })),
                h('p', null, 'Aucune sauvegarde disponible. Lancez une exportation pour en créer une.')
            ),

            !loading && backups.length > 0 && h('div', { className: 'wpcm-table-wrap' },
                h('table', { className: 'wpcm-table' },
                    h('thead', null, h('tr', null,
                        h('th', null, 'Fichier'),
                        h('th', null, 'Taille'),
                        h('th', null, 'Date'),
                        h('th', { className: 'col-actions' }, 'Actions')
                    )),
                    h('tbody', null, backups.map(b =>
                        h('tr', { key: b.name },
                            h('td', null, h('span', { className: 'wpcm-filename', title: b.name }, b.name)),
                            h('td', null, h('span', { className: 'wpcm-filesize' }, b.size)),
                            h('td', null, h('span', { className: 'wpcm-filedate' }, b.date)),
                            h('td', { className: 'col-actions' },
                                h('div', { className: 'wpcm-table-actions' },
                                    h('button', {
                                        className: 'wpcm-btn wpcm-btn-blue wpcm-btn-sm',
                                        onClick: () => onRestore && onRestore(b),
                                        title: 'Restaurer cette sauvegarde'
                                    }, h(Ico, { n: 'restore', s: 13 }), 'Restaurer'),
                                    h('a', {
                                        href: dlUrl(b.name), className: 'wpcm-btn wpcm-btn-ghost wpcm-btn-sm',
                                        style: { textDecoration: 'none' }, title: 'Télécharger'
                                    }, h(Ico, { n: 'download', s: 13 }), 'Télécharger'),
                                    h('button', {
                                        className: 'wpcm-btn wpcm-btn-danger wpcm-btn-sm',
                                        onClick: () => del(b.name),
                                        disabled: deleting === b.name,
                                        title: 'Supprimer'
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
        if (!info) return h('div', { className: 'wpcm-alert wpcm-alert-error', style: { margin: 0 } }, 'Impossible de charger les informations serveur.');

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
                    ['Serveur', info.server?.type, ''],
                    ['Base de données', fmt(info.mysql?.total_size), ''],
                    ['Médias', fmt(info.wordpress?.uploads_size), ''],
                    ['Espace libre', info.disk?.free_human, info.disk?.free > 1073741824 ? 'ok' : 'warn'],
                    ['Upload max.', info.limits?.wp_max_upload_human, ''],
                ].map(([lbl, val, cls]) =>
                    h('div', { className: 'wpcm-stat-card', key: lbl },
                        h('p', { className: 'wpcm-stat-lbl' }, lbl),
                        h('p', { className: 'wpcm-stat-val ' + cls }, val || 'N/A')
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'server' }), 'Limites serveur'),
                h('div', { className: 'wpcm-info-grid' },
                    [
                        ['Mémoire PHP', info.limits?.memory_limit_human],
                        ['Temps d\'exécution', info.limits?.max_execution_time + 's'],
                        ['Taille de chunk', fmt(info.limits?.recommended_chunk)],
                        ['Préfixe des tables', info.mysql?.prefix],
                        ['Nombre de tables', info.mysql?.table_count],
                        ['Multisite', info.wordpress?.multisite ? 'Oui' : 'Non'],
                    ].map(([l, v]) =>
                        h('div', { className: 'wpcm-info-row', key: l },
                            h('span', { className: 'wpcm-info-lbl' }, l),
                            h('span', { className: 'wpcm-info-val' }, v || 'N/A')
                        )
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, 'Extensions PHP'),
                h('div', { style: { display: 'flex', flexWrap: 'wrap', gap: '7px' } },
                    Object.entries(info.extensions || {}).map(([name, ext]) =>
                        h('span', { key: name, className: 'wpcm-badge ' + (ext.loaded ? 'wpcm-badge-ok' : (ext.required ? 'wpcm-badge-bad' : 'wpcm-badge-warn')) },
                            h(Ico, { n: ext.loaded ? 'check' : 'x', s: 12 }), name
                        )
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, 'Permissions des répertoires'),
                h('div', { className: 'wpcm-info-grid' },
                    Object.entries(info.writable || {}).map(([dir, ok]) =>
                        h('div', { className: 'wpcm-info-row', key: dir },
                            h('span', { className: 'wpcm-info-lbl' }, dir),
                            h('span', { className: 'wpcm-badge ' + (ok ? 'wpcm-badge-ok' : 'wpcm-badge-bad') },
                                h(Ico, { n: ok ? 'check' : 'x', s: 12 }), ok ? 'Accessible' : 'Non accessible'
                            )
                        )
                    )
                )
            ),

            h('div', { className: 'wpcm-card' },
                h('h3', { className: 'wpcm-card-title' }, 'Détails WordPress'),
                h('div', { className: 'wpcm-info-grid' },
                    [
                        ['URL du site', info.wordpress?.site_url],
                        ['Thème actif', info.wordpress?.theme],
                        ['Extensions actives', (info.wordpress?.plugins_active || 0) + ' / ' + (info.wordpress?.plugins_total || 0)],
                        ['Structure des permaliens', info.wordpress?.permalink || 'Simple'],
                        ['Chemin ABSPATH', info.wordpress?.abspath],
                        ['Dossier wp-content', info.wordpress?.content_dir],
                    ].map(([l, v]) =>
                        h('div', { className: 'wpcm-info-row', key: l },
                            h('span', { className: 'wpcm-info-lbl' }, l),
                            h('span', { className: 'wpcm-info-val mono', style: l.includes('Chemin') || l.includes('Dossier') ? { fontSize: '11px' } : {} }, v || 'N/A')
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
            { id: 'export',  label: 'Exporter',    icon: 'export'  },
            { id: 'import',  label: 'Restaurer',   icon: 'restore' },
            { id: 'backups', label: 'Sauvegardes', icon: 'archive' },
            { id: 'server',  label: 'Serveur',     icon: 'server'  },
        ];

        return h('div', { className: 'wpcm-app' },
            h('div', { className: 'wpcm-header' },
                h('div', { className: 'wpcm-header-logo' }, h(Ico, { n: 'layers', s: 20 })),
                h('div', { className: 'wpcm-header-text' },
                    h('h1', null, 'WP Clone Master'),
                    h('p', null, 'Sauvegarde, migration et restauration de votre site WordPress')
                ),
                h('span', { className: 'wpcm-header-badge' }, 'v1.0.0')
            ),

            h('div', { className: 'wpcm-tabs' },
                tabs.map(t => h('button', {
                    key: t.id,
                    className: 'wpcm-tab' + (tab === t.id ? ' active' : ''),
                    onClick: () => switchTab(t.id)
                }, h(Ico, { n: t.icon, s: 15 }), h('span', { className: 'tab-lbl' }, t.label)))
            ),

            tab === 'export'  && h(ExportTab),
            tab === 'import'  && h(ImportTab, restoreTarget
                ? { key: restoreTarget.name, initialFile: null, initialSessionId: '', initialFilePath: restoreTarget.path, _backupPath: restoreTarget.path }
                : { key: 'manual' }
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

    function ImportTabWithRestore({ _backupPath }) {
        const [phase, setPhase] = useState('analyzing'); // analyzing | opts | importing | done | error
        const [progress, setProgress] = useState(0);
        const [message, setMessage] = useState('');
        const [manifest, setManifest] = useState(null);
        const [sessionId, setSessionId] = useState('');
        const [newUrl, setNewUrl] = useState(wpcmData.siteUrl);
        const [opts, setOpts] = useState({ locale: '', reset_permalinks: true, block_indexing: false });
        const [logs, setLogs] = useState([]);
        const [error, setError] = useState(null);

        const log = useCallback((msg, type = '') => {
            setLogs(p => [...p, { time: new Date().toLocaleTimeString(), msg, type }]);
        }, []);

        // Analyse automatique au montage
        useEffect(() => {
            (async () => {
                try {
                    log('Analyse de la sauvegarde…');
                    const ext = await apiRetry('wpcm_import', { step: 'extract', session_id: '', file_path: _backupPath, new_url: newUrl }, MAX_RETRIES, log);
                    setSessionId(ext.session_id);
                    setProgress(ext.progress); setMessage(ext.message);
                    if (ext.manifest) setManifest(ext.manifest);
                    log(ext.message, 'success');
                    setPhase('opts');
                } catch (e) { setError(e.message); log('Erreur : ' + e.message, 'error'); setPhase('error'); }
            })();
        }, []);

        const runImport = async () => {
            setPhase('importing'); setError(null);
            try {
                log('Préparation de l\'installeur…');
                const prep = await apiRetry('wpcm_import', {
                    step: 'prepare', session_id: sessionId, file_path: _backupPath,
                    new_url: newUrl, import_opts: JSON.stringify(opts),
                }, MAX_RETRIES, log);
                const { installer_url: url, auth_token: tok } = prep;
                log('Installeur prêt.', 'success');

                let step = 'database';
                let dbIdx = 0, dbOff = 0, dbQ = 0, dbE = 0;
                let srIdx = 0, srOff = 0, srR = 0, srC = 0, srS = 0;

                while (step) {
                    const fd = new FormData();
                    fd.append('auth_token', tok); fd.append('step', step);
                    if (step === 'database') { fd.append('file_index', String(dbIdx)); fd.append('byte_offset', String(dbOff)); fd.append('queries_total', String(dbQ)); fd.append('errors_total', String(dbE)); }
                    if (step === 'replace_urls') { fd.append('table_index', String(srIdx)); fd.append('row_offset', String(srOff)); fd.append('sr_rows', String(srR)); fd.append('sr_cells', String(srC)); fd.append('sr_serial', String(srS)); }

                    const ctrl = new AbortController(); const t = setTimeout(() => ctrl.abort(), 600000);
                    let resp;
                    try { resp = await fetch(url, { method: 'POST', body: fd, signal: ctrl.signal }); }
                    catch (fe) { clearTimeout(t); throw new Error('Erreur réseau : ' + fe.message); }
                    clearTimeout(t);

                    let data;
                    const raw = await resp.text();
                    try { data = JSON.parse(raw.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, '')); }
                    catch { throw new Error('Réponse invalide sur "' + step + '" : ' + raw.substring(0, 200).replace(/<[^>]+>/g, '').trim()); }
                    if (!data.success) throw new Error(data.data?.message || 'Erreur sur "' + step + '"');

                    const d = data.data;
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || step + ' terminé', 'success');
                    if (d.errors_log) d.errors_log.forEach(e => log('  SQL : ' + e, 'warn'));
                    if (step === 'database') { dbIdx = d.file_index ?? dbIdx; dbOff = d.byte_offset ?? 0; dbQ = d.queries ?? dbQ; dbE = d.errors ?? dbE; }
                    if (step === 'replace_urls') { srIdx = d.table_index ?? srIdx; srOff = d.row_offset ?? 0; srR = d.rows ?? srR; srC = d.cells ?? srC; srS = d.serial ?? srS; }
                    step = d.next_step || null;
                }
                setPhase('done'); log('Migration terminée avec succès.', 'success');
            } catch (e) { setError(e.message); log('Erreur : ' + e.message, 'error'); setPhase('error'); }
        };

        const renderPhase = () => {
            switch (phase) {
                case 'analyzing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'db' }), 'Lecture de la sauvegarde…'),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' }, h('span', { className: 'wpcm-spinner' }), 'Analyse de l\'archive en cours…')
                );

                case 'opts': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'settings' }), 'Paramètres de restauration'),
                    h('p', { className: 'wpcm-card-desc' }, 'Vérifiez les informations et configurez les options avant de lancer la restauration.'),
                    manifest && h(Fragment, null,
                        h('div', { className: 'wpcm-opts-section-title', style: { marginBottom: '10px' } }, h(Ico, { n: 'db', s: 14 }), 'Source de l\'archive'),
                        h('div', { className: 'wpcm-manifest-grid' },
                            [
                                ['URL source', manifest.site_url],
                                ['Version WP', manifest.wp_version],
                                ['Créée le', manifest.created_at],
                                ['Thème actif', manifest.active_theme],
                                ['Extensions', (manifest.active_plugins || []).length + ' actives'],
                                ['Tables', manifest.tables_count + ' tables DB'],
                            ].map(([lbl, val]) => h('div', { className: 'wpcm-manifest-item', key: lbl },
                                h('div', { className: 'wpcm-manifest-lbl' }, lbl),
                                h('div', { className: 'wpcm-manifest-val' }, val || 'N/A')
                            ))
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'link', s: 14 }), 'URL de destination'),
                        h('div', { className: 'wpcm-field', style: { marginBottom: 0 } },
                            h('label', { className: 'wpcm-label' }, 'Nouvelle URL du site'),
                            h('input', { className: 'wpcm-input', type: 'url', value: newUrl, onChange: e => setNewUrl(e.target.value) }),
                            h('span', { className: 'wpcm-input-hint' }, 'Laissez l\'URL actuelle si vous restaurez sur le même domaine.')
                        )
                    ),
                    h('div', { className: 'wpcm-opts-section' },
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'settings', s: 14 }), 'Options avancées'),
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.reset_permalinks, onChange: e => setOpts(o => ({ ...o, reset_permalinks: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, 'Régénérer les permaliens'),
                                h('span', { className: 'wpcm-toggle-sub' }, 'Recommandé après chaque restauration.')
                            )
                        ),
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.block_indexing, onChange: e => setOpts(o => ({ ...o, block_indexing: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, 'Masquer le site aux moteurs de recherche'),
                                h('span', { className: 'wpcm-toggle-sub' }, 'Demande à Google & Bing de ne pas indexer ce site (option \"Décourager les moteurs de recherche\" dans WordPress). Recommandé pendant la vérification post-migration — à décocher une fois le site validé.')
                            )
                        )
                    ),
                    h('div', { style: { display: 'flex', gap: '10px', marginTop: '8px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runImport },
                            h(Ico, { n: 'restore', s: 16 }), 'Lancer la restauration'
                        )
                    )
                );

                case 'importing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'restore' }), 'Restauration en cours…'),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' }, h('span', { className: 'wpcm-spinner' }), 'Traitement en cours — veuillez ne pas fermer cette page.')
                );

                case 'done': return h('div', { className: 'wpcm-card' },
                    h(ProgressBar, { progress: 100, message: 'Restauration terminée.', done: true }),
                    h('div', { className: 'wpcm-complete-box' },
                        h('div', { className: 'wpcm-complete-icon' }, h(Ico, { n: 'check', s: 26 })),
                        h('h3', { className: 'wpcm-complete-title' }, 'Votre site a été restauré avec succès !'),
                        h('p', { className: 'wpcm-complete-sub' }, 'Reconnectez-vous à WordPress pour vérifier que tout fonctionne correctement.')
                    )
                );

                case 'error': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'x' }), 'Une erreur est survenue'),
                    h('div', { className: 'wpcm-alert wpcm-alert-error', style: { marginTop: 0 } }, h(Ico, { n: 'x', s: 15 }), error || 'Erreur inconnue')
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
            { id: 'export',  label: 'Exporter',    icon: 'export'  },
            { id: 'import',  label: 'Restaurer',   icon: 'restore' },
            { id: 'backups', label: 'Sauvegardes', icon: 'archive' },
            { id: 'server',  label: 'Serveur',     icon: 'server'  },
        ];

        return h('div', { className: 'wpcm-app' },
            h('div', { className: 'wpcm-header' },
                h('div', { className: 'wpcm-header-logo' }, h(Ico, { n: 'layers', s: 20 })),
                h('div', { className: 'wpcm-header-text' },
                    h('h1', null, 'WP Clone Master'),
                    h('p', null, 'Sauvegarde, migration et restauration de votre site WordPress')
                ),
                h('span', { className: 'wpcm-header-badge' }, 'v1.0.0')
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
                ? h(ImportTabWithRestore, { key: restoreTarget.name, _backupPath: restoreTarget.path })
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
