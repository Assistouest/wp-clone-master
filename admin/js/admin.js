(function () {
    'use strict';

    const { createElement: h, useState, useEffect, useRef, useCallback, Fragment, Component } = wp.element;
    const { __ } = wp.i18n;

    /* =========================================================
       Helpers API
       ========================================================= */
    const MAX_RETRIES = 2;

    async function persistClientError(event, message, details) {
        try {
            const fd = new FormData();
            fd.append('action', 'wpcm_debug_client');
            fd.append('nonce', wpcmData.nonce);
            fd.append('event', event || 'client_error');
            fd.append('message', String(message || '').slice(0, 4000));
            fd.append('details', String(details || '').slice(0, 12000));
            fd.append('url', window.location.href);
            await fetch(wpcmData.ajaxUrl, {
                method: 'POST',
                body: fd,
                cache: 'no-store',
                credentials: 'same-origin',
                keepalive: true
            });
        } catch (ignored) {
            // Diagnostics must never hide the original failure.
        }
    }

    function makeApiError(message, meta = {}) {
        const suffix = meta.eventId ? ' [' + meta.eventId + ']' : '';
        const error = new Error(String(message || __( 'Unknown error', 'clone-master' )) + suffix);
        error.eventId = meta.eventId || '';
        error.requestId = meta.requestId || '';
        error.httpStatus = meta.httpStatus || 0;
        error.details = meta.details || '';
        return error;
    }

    async function api(action, data = {}) {
        const requestId = 'req_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 14);
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', wpcmData.nonce);
        fd.append('request_id', requestId);
        Object.entries(data).forEach(([k, v]) => {
            if (v instanceof File) fd.append(k, v);
            else if (v !== undefined && v !== null) fd.append(k, String(v));
        });

        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 600000);
        const ajaxUrl = new URL(wpcmData.ajaxUrl, window.location.href);
        ajaxUrl.searchParams.set('_wpcm_request', requestId);
        ajaxUrl.searchParams.set('_wpcm_time', Date.now().toString());

        let response;
        try {
            response = await fetch(ajaxUrl.toString(), {
                method: 'POST',
                body: fd,
                signal: ctrl.signal,
                cache: 'reload',
                credentials: 'same-origin',
                headers: {
                    'Cache-Control': 'no-cache, no-store, max-age=0',
                    'Pragma': 'no-cache',
                    'X-WPCM-Request-ID': requestId
                }
            });
        } catch (error) {
            clearTimeout(timer);
            if (error.name === 'AbortError') {
                const timeoutError = makeApiError(__( 'Timeout: the server may still be processing.', 'clone-master' ), { requestId });
                persistClientError('client_timeout', timeoutError.message, 'Action: ' + action + '\nRequest ID: ' + requestId);
                throw timeoutError;
            }
            persistClientError('client_network_error', error.message, 'Action: ' + action + '\nRequest ID: ' + requestId);
            throw makeApiError(error.message || __( 'Network error.', 'clone-master' ), { requestId });
        }
        clearTimeout(timer);

        const text = await response.text();
        let payload;
        try {
            payload = JSON.parse(text.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, ''));
        } catch (error) {
            const excerpt = text.substring(0, 3000).replace(/<script[\s\S]*?<\/script>/gi, '[script removed]');
            let message = __( 'The server returned an unexpected response.', 'clone-master' );
            if (text === '-1' || text === '0') message = __( 'The WordPress security session expired. Reload the page and try again.', 'clone-master' );
            else if (text.includes('Fatal error') || text.includes('Parse error')) message += ' ' + __( 'A PHP fatal error was detected.', 'clone-master' );
            else if (text.includes('Maximum execution time')) message += ' ' + __( 'The PHP execution time was exceeded.', 'clone-master' );
            else if (text.includes('Allowed memory size')) message += ' ' + __( 'The PHP memory limit was exceeded.', 'clone-master' );
            else if (!text.trim()) message += ' ' + __( 'The response body was empty.', 'clone-master' );
            const details = [
                'Action: ' + action,
                'HTTP status: ' + response.status,
                'Content-Type: ' + (response.headers.get('content-type') || ''),
                'Request ID: ' + requestId,
                'Response excerpt:',
                excerpt
            ].join('\n');
            persistClientError('client_non_json_response', message, details);
            throw makeApiError(message + ' ' + __( 'Open Diagnostics to view the captured response.', 'clone-master' ), {
                requestId,
                httpStatus: response.status,
                details
            });
        }

        if (!payload || typeof payload !== 'object') {
            const message = __( 'The server returned an invalid JSON payload.', 'clone-master' );
            persistClientError('client_invalid_json_payload', message, 'Action: ' + action + '\nRequest ID: ' + requestId);
            throw makeApiError(message, { requestId, httpStatus: response.status });
        }
        if (!payload.success) {
            const dataPayload = payload.data;
            const message = (dataPayload && typeof dataPayload === 'object' ? dataPayload.message : dataPayload) || __( 'Server error without detail.', 'clone-master' );
            const eventId = dataPayload && typeof dataPayload === 'object' ? (dataPayload.event_id || '') : '';
            throw makeApiError(message, { eventId, requestId, httpStatus: response.status });
        }
        if (!payload.data) {
            throw makeApiError(__( 'The server returned an empty success payload.', 'clone-master' ), { requestId, httpStatus: response.status });
        }
        if (payload.data.request_id && payload.data.request_id !== requestId && window.console) {
            console.warn('Clone Master received a mismatched diagnostic request ID. Durable state remains authoritative.');
        }
        return payload.data;
    }

    window.WPCM_API = api;

    /**
     * Format a byte count for every React panel. This helper must remain in the
     * application scope because diagnostics and server information both use it.
     */
    function formatBytes(value) {
        const bytes = Number(value);
        if (!Number.isFinite(bytes) || bytes < 0) return 'N/A';
        if (bytes === 0) return '0 B';

        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const unit = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        const amount = bytes / Math.pow(1024, unit);
        const precision = unit === 0 || amount >= 100 ? 0 : (amount >= 10 ? 1 : 2);
        return amount.toFixed(precision) + ' ' + units[unit];
    }

    async function apiRetry(action, data, retries = MAX_RETRIES, log = null) {
        for (let i = 0; i <= retries; i++) {
            try { return await api(action, data); }
            catch (err) {
                const retry = err.message.includes('Timeout') || err.message.includes('fetch') || err.message.includes('500') || err.message.includes('Network');
                if (i < retries && retry) {
                    const w = (i + 1) * 3;
                    if (log) log('⚠ ' + err.message + ' : ' + __( 'Retrying in ', 'clone-master' ) + w + 's…', 'warn');
                    await new Promise(r => setTimeout(r, w * 1000));
                    continue;
                }
                throw err;
            }
        }
    }

    async function installerApi(url, formData, step) {
        const requestId = 'inst_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 14);
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 600000);
        let response;
        const requestUrl = new URL(url, window.location.href);
        requestUrl.searchParams.set('_wpcm_request', requestId);
        requestUrl.searchParams.set('_wpcm_time', Date.now().toString());
        formData.set('request_id', requestId);
        try {
            response = await fetch(requestUrl.toString(), {
                method: 'POST',
                body: formData,
                signal: ctrl.signal,
                cache: 'reload',
                credentials: 'same-origin',
                referrerPolicy: 'same-origin',
                headers: {
                    'Cache-Control': 'no-cache, no-store, max-age=0',
                    'Pragma': 'no-cache',
                    'X-WPCM-Request-ID': requestId
                }
            });
        } catch (error) {
            clearTimeout(timer);
            const message = error.name === 'AbortError'
                ? __( 'The restore step timed out. The signed journal can resume the same step.', 'clone-master' )
                : __( 'Network error during restore: ', 'clone-master' ) + error.message;
            persistClientError('installer_network_error', message, 'Step: ' + step + '\nRequest ID: ' + requestId);
            throw makeApiError(message, { requestId });
        }
        clearTimeout(timer);
        const raw = await response.text();
        let payload;
        try {
            payload = JSON.parse(raw.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, ''));
        } catch (error) {
            let message = __( 'The installer returned an invalid response during step ', 'clone-master' ) + '"' + step + '".';
            if (raw.includes('Fatal error') || raw.includes('Parse error')) message += ' ' + __( 'A PHP fatal error was detected.', 'clone-master' );
            if (raw.includes('Maximum execution time')) message += ' ' + __( 'The PHP execution time was exceeded.', 'clone-master' );
            if (raw.includes('Allowed memory size')) message += ' ' + __( 'The PHP memory limit was exceeded.', 'clone-master' );
            if (!raw.trim()) message += ' ' + __( 'The response body was empty.', 'clone-master' );
            const details = [
                'Step: ' + step,
                'HTTP status: ' + response.status,
                'Content-Type: ' + (response.headers.get('content-type') || ''),
                'Request ID: ' + requestId,
                'Response excerpt:',
                raw.substring(0, 3000)
            ].join('\n');
            persistClientError('installer_non_json_response', message, details);
            throw makeApiError(message + ' ' + __( 'Open Diagnostics to inspect the captured response.', 'clone-master' ), {
                requestId,
                httpStatus: response.status,
                details
            });
        }
        if (!payload || !payload.success) {
            const data = payload && payload.data;
            const message = data && typeof data === 'object' ? (data.message || __( 'Installer error.', 'clone-master' )) : (data || __( 'Installer error.', 'clone-master' ));
            const eventId = data && typeof data === 'object' ? (data.event_id || '') : '';
            throw makeApiError(message, { eventId, requestId, httpStatus: response.status });
        }
        return payload.data || {};
    }

    // Parse fetch responses robustly: JSON, -1 (wp_die), HTML, or an empty body.
    async function parseWpResponse(resp) {
        const text = await resp.text();
        if (text === '-1' || text === '0') {
            const message = __( 'The WordPress security session expired. Reload the page and try again.', 'clone-master' );
            persistClientError('upload_security_response', message, 'HTTP status: ' + resp.status);
            throw makeApiError(message, { httpStatus: resp.status });
        }
        let json;
        try {
            json = JSON.parse(text.replace(/^\uFEFF|^[\s\xEF\xBB\xBF]+/, ''));
        } catch (error) {
            let message = __( 'The upload endpoint returned an invalid response.', 'clone-master' );
            if (text.includes('Fatal error') || text.includes('Parse error')) message += ' ' + __( 'A PHP fatal error was detected.', 'clone-master' );
            if (text.includes('Maximum execution time')) message += ' ' + __( 'The PHP execution time was exceeded.', 'clone-master' );
            if (text.includes('Allowed memory size')) message += ' ' + __( 'The PHP memory limit was exceeded.', 'clone-master' );
            if (resp.status === 413) message += ' ' + __( 'The server rejected the upload size.', 'clone-master' );
            if (!text.trim()) message += ' ' + __( 'The response body was empty.', 'clone-master' );
            const details = 'HTTP status: ' + resp.status + '\nResponse excerpt:\n' + text.substring(0, 3000);
            persistClientError('upload_non_json_response', message, details);
            throw makeApiError(message + ' ' + __( 'Open Diagnostics to inspect the captured response.', 'clone-master' ), { httpStatus: resp.status, details });
        }
        if (!json || typeof json !== 'object') {
            throw makeApiError(__( 'The upload endpoint returned an invalid JSON payload.', 'clone-master' ), { httpStatus: resp.status });
        }
        if (!json.success) {
            const data = json.data;
            const message = (data && typeof data === 'object' ? data.message : data) || __( 'Server error without detail.', 'clone-master' );
            const eventId = data && typeof data === 'object' ? (data.event_id || '') : '';
            throw makeApiError(message, { eventId, httpStatus: resp.status });
        }
        return json.data;
    }

    /* =========================================================
       Shared operation lifecycle
       ========================================================= */
    const operationState = {
        busy: false,
        label: '',
        listeners: new Set(),
    };

    function notifyOperationState() {
        const snapshot = { busy: operationState.busy, label: operationState.label };
        operationState.listeners.forEach(listener => {
            try { listener(snapshot); } catch (ignored) { /* A view listener must never stop an operation. */ }
        });
    }

    function setOperationBusy(busy, label = '') {
        operationState.busy = Boolean(busy);
        operationState.label = operationState.busy ? String(label || __( 'Operation in progress', 'clone-master' )) : '';
        document.documentElement.classList.toggle('wpcm-operation-running', operationState.busy);
        notifyOperationState();
    }

    function useOperationState() {
        const [state, setState] = useState({ busy: operationState.busy, label: operationState.label });
        useEffect(() => {
            const listener = next => setState(next);
            operationState.listeners.add(listener);
            return () => operationState.listeners.delete(listener);
        }, []);
        return state;
    }

    window.addEventListener('beforeunload', event => {
        if (!operationState.busy) return;
        event.preventDefault();
        event.returnValue = '';
    });

    /* =========================================================
       Inline SVG icons
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

    function ErrorNotice({ message }) {
        if (!message) return null;
        return h('div', { className: 'wpcm-alert wpcm-alert-error wpcm-error-notice' },
            h(Ico, { n: 'x', s: 15 }),
            h('div', { className: 'wpcm-error-notice-content' },
                h('div', null, message),
                h('button', {
                    type: 'button',
                    className: 'wpcm-error-diagnostics-link',
                    onClick: () => window.dispatchEvent(new CustomEvent('wpcm:open-diagnostics'))
                }, h(Ico, { n: 'info', s: 13 }), __( 'View diagnostics', 'clone-master' ))
            )
        );
    }

    /**
     * Keep one broken React panel from unmounting the complete administration
     * workspace. The caught failure is persisted through the existing redacted
     * diagnostic endpoint and the administrator retains a usable interface.
     */
    class WPCMErrorBoundary extends Component {
        constructor(props) {
            super(props);
            this.state = { error: null };
            this.retry = this.retry.bind(this);
        }

        static getDerivedStateFromError(error) {
            return { error };
        }

        componentDidCatch(error, info) {
            const details = [
                'Panel: ' + String(this.props.panelId || 'application'),
                'Component stack:',
                info && info.componentStack ? info.componentStack : 'Unavailable',
                'JavaScript stack:',
                error && error.stack ? error.stack : 'Unavailable'
            ].join('\n');
            persistClientError('react_render_error', error && error.message ? error.message : String(error), details);
        }

        retry() {
            this.setState({ error: null });
        }

        render() {
            if (!this.state.error) return this.props.children;

            const message = this.state.error && this.state.error.message
                ? this.state.error.message
                : __( 'A React interface component failed to render.', 'clone-master' );

            return h('div', { className: 'wpcm-card wpcm-runtime-error', role: 'alert' },
                h('div', { className: 'wpcm-runtime-error-icon' }, h(Ico, { n: 'warn', s: 20 })),
                h('div', { className: 'wpcm-runtime-error-content' },
                    h('h3', { className: 'wpcm-card-title' }, __( 'This section encountered an interface error', 'clone-master' )),
                    h('p', { className: 'wpcm-card-desc' }, message),
                    h('div', { className: 'wpcm-runtime-error-actions' },
                        h('button', { type: 'button', className: 'wpcm-btn wpcm-btn-primary', onClick: this.retry }, __( 'Retry this section', 'clone-master' )),
                        h('button', {
                            type: 'button',
                            className: 'wpcm-btn wpcm-btn-ghost',
                            onClick: () => window.dispatchEvent(new CustomEvent('wpcm:open-diagnostics'))
                        }, __( 'Open diagnostics', 'clone-master' )),
                        h('button', { type: 'button', className: 'wpcm-btn wpcm-btn-ghost', onClick: () => window.location.reload() }, __( 'Reload interface', 'clone-master' ))
                    )
                )
            );
        }
    }

    /* =========================================================
       EXPORT TAB
       ========================================================= */
    /* =========================================================
       DbPhaseDetail : panel displayed during chunked export
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
            // Row 1: current table
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
        // Details for the chunked database phase.
        const [dbInfo, setDbInfo] = useState(null);
        const startTimeRef = useRef(null);
        const dbCallsRef = useRef(0);

        const log = useCallback((msg, type = '') => {
            setLogs(p => [...p, { time: new Date().toLocaleTimeString(), msg, type }]);
        }, []);

        // Extract database progress details from the server message.
        // Format: "Database: table 3/12 : exporting wp_posts…"
        const parseDbMessage = useCallback((msg) => {
            if (!msg) return null;
            // Accept both translated and English message formats.
            const m = msg.match(/(\d+)\/(\d+)[^a-zA-Z_]*([a-zA-Z0-9_]+[^\s…]*)/);
            if (!m) return null;
            return { tableIdx: parseInt(m[1], 10), totalTables: parseInt(m[2], 10), tableName: m[3] };
        }, []);

        const run = async () => {
            if (operationState.busy) { window.alert(__( 'Another Clone Master operation is already running.', 'clone-master' )); return; }
            setOperationBusy(true, __( 'Backup in progress', 'clone-master' ));
            setRunning(true); setProgress(0); setDone(false); setResult(null);
            setError(null); setLogs([]); setDbInfo(null);
            startTimeRef.current = Date.now();
            dbCallsRef.current = 0;
            let sid = '', next = 'init';
            let inDbPhase = false;
            let lastCursorToken = '';
            let unchangedCursorResponses = 0;
            try {
                while (next) {
                    const d = await apiRetry('wpcm_export', { step: next, session_id: sid }, MAX_RETRIES, log);
                    sid = d.session_id || sid;
                    setProgress(d.progress || 0);
                    setMessage(d.message || '');

                    // Update the database panel without adding a new log entry.
                    if (next === 'database' && d.next_step === 'database') {
                        inDbPhase = true;
                        dbCallsRef.current++;
                        if (d.cursor_token) {
                            if (d.cursor_token === lastCursorToken) unchangedCursorResponses++;
                            else unchangedCursorResponses = 0;
                            lastCursorToken = d.cursor_token;
                            if (unchangedCursorResponses >= 3) {
                                throw new Error(__( 'The authenticated database cursor did not advance after several requests. The export was stopped safely. Start a new backup; if the problem persists, inspect the diagnostic details rather than repeatedly purging caches.', 'clone-master' ));
                            }
                        }
                        const parsed = parseDbMessage(d.message);
                        const elapsed = Math.round((Date.now() - startTimeRef.current) / 1000);
                        if (parsed) {
                            setDbInfo({ ...parsed, elapsedSec: elapsed, calls: dbCallsRef.current, rowsPerCall: d.rows_per_call || null });
                        }
                        // Update the latest database entry instead of flooding the log.
                        setLogs(prev => {
                            const last = prev[prev.length - 1];
                            const isDbEntry = last && last.isDb;
                            const entry = { time: new Date().toLocaleTimeString(), msg: d.message || '', type: '', isDb: true };
                            return isDbEntry ? [...prev.slice(0, -1), entry] : [...prev, entry];
                        });
                    } else {
                        // Completed phase or another step: use a normal log entry
                        if (inDbPhase && next === 'database') {
                            // Last database call transitions to files_scan
                            inDbPhase = false;
                            setDbInfo(null);
                            log(d.message || __( 'Database export complete.', 'clone-master' ), 'success');
                        } else {
                            log(d.message || next + ' ' + __( 'complete', 'clone-master' ), 'success');
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
            } finally { setRunning(false); setOperationBusy(false); }
        };

        // Active database phase uses an indeterminate progress bar
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
                    // Main bar: indeterminate during database work, determinate otherwise
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
                    // Database detail panel
                    h(DbPhaseDetail, { dbInfo }),
                    // Spinner row
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

                error && h(ErrorNotice, { message: error })
            ),
            h(LogViewer, { entries: logs })
        );
    }

    /* =========================================================
       IMPORT TAB : shared restore workflow
       ========================================================= */
    // phases : 'idle' | 'uploading' | 'opts' | 'importing' | 'done' | 'error'
    function ImportTab({ initialFile = null, initialSessionId = '', initialFilePath = '' }) {
        const [file, setFile] = useState(initialFile);
        const [dragOver, setDragOver] = useState(false);
        const [newUrl, setNewUrl] = useState(wpcmData.siteUrl);
        const [phase, setPhase] = useState('idle'); // Single state; only one card is visible.
        const [progress, setProgress] = useState(0);
        const [message, setMessage] = useState('');
        const [manifest, setManifest] = useState(null);
        const [logs, setLogs] = useState([]);
        const [error, setError] = useState(null);
        const [sessionId, setSessionId] = useState(initialSessionId);
        const [filePath, setFilePath] = useState(initialFilePath);
        const [opts, setOpts] = useState({
            reset_permalinks: true,
            block_indexing:   false,  // Disabled by default; the administrator must opt in.
        });
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
            if (operationState.busy) { window.alert(__( 'Another Clone Master operation is already running.', 'clone-master' )); return; }
            setOperationBusy(true, __( 'Archive upload and validation in progress', 'clone-master' ));
            setPhase('uploading'); setProgress(0); setError(null); setLogs([]);
            try {
                // Use one immutable chunk size for the entire upload session.
                // The server authenticates total_chunks in durable state, so changing
                // boundaries after chunk zero would make retries ambiguous. HTTP 413
                // can reduce the size only before the first chunk is accepted.
                const MIN_CHUNK     = 256 * 1024;
                const SERVER_CAP    = Math.floor((wpcmData.maxUpload || 32 * 1024 * 1024) * 0.85);
                let chunkSize       = Math.max(MIN_CHUNK, Math.min(4 * 1024 * 1024, SERVER_CAP));
                let fp = '', sid = '';

                const sendChunk = async (blob, index, total, uid) => {
                    const fd = new FormData();
                    fd.append('action',       'wpcm_import_upload');
                    fd.append('nonce',        wpcmData.nonce);
                    fd.append('backup_chunk', blob, file.name);
                    fd.append('chunk_index',  String(index));
                    fd.append('total_chunks', String(total));
                    fd.append('file_name',    file.name);
                    if (uid) fd.append('upload_id', uid);

                    const started = performance.now();
                    const requestId = 'upload_' + Date.now().toString(36) + '_' + index.toString(36) + '_' + Math.random().toString(36).slice(2, 10);
                    fd.append('request_id', requestId);
                    const uploadUrl = new URL(wpcmData.ajaxUrl, window.location.href);
                    uploadUrl.searchParams.set('_wpcm_request', requestId);
                    uploadUrl.searchParams.set('_wpcm_time', Date.now().toString());
                    const resp = await fetch(uploadUrl.toString(), {
                        method: 'POST',
                        body: fd,
                        cache: 'reload',
                        credentials: 'same-origin',
                        headers: {
                            'Cache-Control': 'no-cache, no-store, max-age=0',
                            'Pragma': 'no-cache',
                            'X-WPCM-Request-ID': requestId
                        }
                    });
                    if (resp.status === 413) {
                        return { tooLarge: true };
                    }
                    return {
                        data: await parseWpResponse(resp),
                        elapsed: Math.max(1, performance.now() - started),
                    };
                };

                if (file.size > chunkSize) {
                    let uid = '';
                    let offset = 0;
                    let chunkIndex = 0;
                    let totalSent = 0;
                    const fileSize = file.size;
                    let totalChunks = Math.ceil(fileSize / chunkSize);

                    log( __( 'Chunked upload of ', 'clone-master' ) + (fileSize / 1048576).toFixed(1) + ' MB : chunk size: ' + Math.round(chunkSize / 1024) + ' KB');

                    while (offset < fileSize) {
                        const blob = file.slice(offset, Math.min(offset + chunkSize, fileSize));
                        const result = await sendChunk(blob, chunkIndex, totalChunks, uid);

                        if (result.tooLarge) {
                            if (uid || chunkIndex > 0 || chunkSize <= MIN_CHUNK) {
                                throw new Error(__( 'The server rejected a chunk after the upload session started. Restart the upload after increasing the server upload limit.', 'clone-master' ));
                            }
                            chunkSize = Math.max(MIN_CHUNK, Math.floor(chunkSize / 2));
                            totalChunks = Math.ceil(fileSize / chunkSize);
                            log(__( 'HTTP 413 : initial chunk reduced to ', 'clone-master' ) + Math.round(chunkSize / 1024) + ' KB', 'warn');
                            continue;
                        }

                        const r = result.data;
                        uid = r.upload_id || uid;
                        offset += blob.size;
                        totalSent += blob.size;
                        chunkIndex++;

                        const pct = Math.round((totalSent / fileSize) * 10);
                        const sentMiB = (totalSent / 1048576).toFixed(1);
                        const totalMiB = (fileSize / 1048576).toFixed(1);
                        const speedMbps = ((blob.size / result.elapsed) * 8 / 1000).toFixed(1);
                        setProgress(pct);
                        setMessage(sentMiB + ' / ' + totalMiB + ' MB sent : ' + speedMbps + ' Mbps');

                        if (r.complete) {
                            fp = r.file_path;
                            sid = r.session_id;
                            log(r.message || __( 'Upload complete', 'clone-master' ), 'success');
                        }
                    }
                } else {
                    log( __( 'Sending archive directly (', 'clone-master' ) + (file.size / 1048576).toFixed(1) + ' MB)…');
                    const r = await api('wpcm_import_upload', { backup_file: file });
                    fp = r.file_path; sid = r.session_id;
                    log(__( 'Archive received successfully.', 'clone-master' ), 'success');
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
            finally { setOperationBusy(false); }
        };

        const runImport = async () => {
            if (operationState.busy) { window.alert(__( 'Another Clone Master operation is already running.', 'clone-master' )); return; }
            setOperationBusy(true, __( 'Transactional restore in progress', 'clone-master' ));
            setPhase('importing'); setError(null);
            try {
                // ── Generate a cryptographic token client-side ──────────────────────
                // crypto.getRandomValues() is CSPRNG : never Math.random().
                // 32 bytes = 256 bits of entropy, encoded as a 64-char hex string.
                // This token is generated here, kept in memory only, and sent once in
                // the *request* to step_prepare : never read back from any response.
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
                // auth_token is intentionally absent from the response : the server never returns it.
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

                    const d = await installerApi(url, fd, step);
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || step + ' ' + __( 'complete', 'clone-master' ), 'success');
                    if (d.errors_log) d.errors_log.forEach(e => log('SQL: ' + e, 'warn'));

                    if (step === 'database') { dbIdx = d.file_index ?? dbIdx; dbOff = d.byte_offset ?? 0; dbQ = d.queries ?? dbQ; dbE = d.errors ?? dbE; }
                    if (step === 'replace_urls') { srIdx = d.table_index ?? srIdx; srOff = d.row_offset ?? 0; srR = d.rows ?? srR; srC = d.cells ?? srC; srS = d.serial ?? srS; }
                    step = d.next_step || null;
                }
                setPhase('done'); log( __( 'Migration completed successfully.', 'clone-master' ), 'success');
            } catch (e) { setError(e.message); log( __( 'Error: ', 'clone-master' ) + e.message, 'error'); setPhase('error'); }
            finally { setOperationBusy(false); }
        };

        const reset = () => { setPhase('idle'); setProgress(0); setFile(null); setManifest(null); setLogs([]); setError(null); setSessionId(''); setFilePath(''); };

        // Render only the section for the current phase.
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
                        h('p', { className: 'wpcm-card-desc' }, __( 'Drop your .wpcm file or click to select it. Large files are sent in parts to work around server limits.', 'clone-master' )),
                        h('div', {
                            className: 'wpcm-upload-zone' + (dragOver ? ' dragover' : '') + (file ? ' has-file' : ''),
                            onClick: () => fileRef.current && fileRef.current.click(),
                            onDragOver: e => { e.preventDefault(); setDragOver(true); },
                            onDragLeave: () => setDragOver(false),
                            onDrop: handleDrop,
                        },
                            h('input', { type: 'file', ref: fileRef, accept: '.wpcm', style: { display: 'none' }, onChange: e => setFile(e.target.files[0]) }),
                            h('div', { className: 'wpcm-upload-ico' }, h(Ico, { n: 'upload', s: 22 })),
                            h('p', { className: 'wpcm-upload-name' }, file ? file.name + ' : ' + (file.size / 1048576).toFixed(1) + ' MB' : __( 'Drop your archive here', 'clone-master' )),
                            h('p', { className: 'wpcm-upload-hint' }, file ? __( 'Click to choose a different file', 'clone-master' ) : __( 'Accepted format: .wpcm : or click to browse', 'clone-master' ))
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
                                    h(Ico, { n: 'upload', s: 16 }), __( 'Analyze archive', 'clone-master' )
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
                        __( 'Uploading : please do not close this page.', 'clone-master' )
                    )
                );

                case 'opts': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'settings' }), __( 'Restore settings', 'clone-master' )),
                    h('p', { className: 'wpcm-card-desc' }, __( 'Check the information and configure the options before starting the restore.', 'clone-master' )),
                    manifest && h(Fragment, null,
                        h('div', { className: 'wpcm-opts-section-title', style: { marginBottom: '10px' } }, h(Ico, { n: 'db', s: 14 }), __( 'Archive source', 'clone-master' )),
                        h('div', { className: 'wpcm-manifest-grid' },
                            [
                                [__( 'Source URL', 'clone-master' ), manifest.site_url],
                                [__( 'WordPress version', 'clone-master' ), manifest.wp_version],
                                [__( 'Created on', 'clone-master' ), manifest.created_at],
                                [__( 'Active theme', 'clone-master' ), manifest.active_theme],
                                [__( 'Active plugins', 'clone-master' ), (manifest.active_plugins || []).length],
                                [__( 'Database tables', 'clone-master' ), manifest.tables_count],
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
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'settings', s: 14 }), __( 'Advanced options', 'clone-master' )),

                        /* Permalinks */
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.reset_permalinks, onChange: e => setOpts(o => ({ ...o, reset_permalinks: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Regenerate permalinks', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Deletes cached rewrite rules : WordPress will regenerate them on first load.', 'clone-master' ))
                            )
                        ),

                        /* Search engine visibility */
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.block_indexing, onChange: e => setOpts(o => ({ ...o, block_indexing: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Hide site from search engines', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Asks Google & Bing not to index this site (WordPress "Discourage search engines" option). Recommended during post-migration validation : uncheck once verified.', 'clone-master' ))
                            )
                        ),

                    ),
                    h('div', { style: { display: 'flex', gap: '10px', marginTop: '8px' } },
                        h('button', { className: 'wpcm-btn wpcm-btn-primary wpcm-btn-lg', onClick: runImport },
                            h(Ico, { n: 'restore', s: 16 }), __( 'Start restore', 'clone-master' )
                        ),
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, __( 'Cancel', 'clone-master' ))
                    )
                );

                case 'importing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'restore' }), __( 'Restoring…', 'clone-master' )),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' },
                        h('span', { className: 'wpcm-spinner' }),
                        __( 'Processing : please do not close this page.', 'clone-master' )
                    )
                );

                case 'done': return h('div', { className: 'wpcm-card' },
                    h(ProgressBar, { progress: 100, message: __( 'Restore complete.', 'clone-master' ), done: true }),
                    h('div', { className: 'wpcm-complete-box' },
                        h('div', { className: 'wpcm-complete-icon' }, h(Ico, { n: 'check', s: 26 })),
                        h('h3', { className: 'wpcm-complete-title' }, __( 'Your site has been restored successfully!', 'clone-master' )),
                        h('p', { className: 'wpcm-complete-sub' }, __( 'The migration is complete. Please log back into the WordPress admin to verify everything is working correctly.', 'clone-master' )),
                        h('button', { className: 'wpcm-btn wpcm-btn-ghost', onClick: reset }, __( 'New restore', 'clone-master' ))
                    )
                );

                case 'error': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'x' }), __( 'An error occurred', 'clone-master' )),
                    h(ErrorNotice, { message: error || __( 'Unknown error', 'clone-master' ) }),
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
       BACKUPS TAB
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
       SERVER TAB
       ========================================================= */
    function ServerTab() {
        const [info, setInfo] = useState(null);
        const [loading, setLoading] = useState(true);
        const [error, setError] = useState('');

        useEffect(() => {
            let mounted = true;
            api('wpcm_server_info')
                .then(data => { if (mounted) setInfo(data); })
                .catch(reason => { if (mounted) setError(reason && reason.message ? reason.message : String(reason)); })
                .finally(() => { if (mounted) setLoading(false); });
            return () => { mounted = false; };
        }, []);

        if (loading) return h('div', { style: { textAlign: 'center', padding: '48px' } }, h('span', { className: 'wpcm-spinner' }));
        if (error) return h(ErrorNotice, { message: error });
        if (!info) return h('div', { className: 'wpcm-alert wpcm-alert-error', style: { margin: 0 } }, __( 'Failed to load server information.', 'clone-master' ));

        return h(Fragment, null,
            h('div', { className: 'wpcm-stats-grid' },
                [
                    ['PHP', info.php?.version, 'ok'],
                    ['MySQL', info.mysql?.version, 'ok'],
                    ['WordPress', info.wordpress?.version, 'ok'],
                    [__( 'Server', 'clone-master' ), info.server?.type, ''],
                    [__( 'Database', 'clone-master' ), formatBytes(info.mysql?.total_size), ''],
                    [__( 'Media', 'clone-master' ), formatBytes(info.wordpress?.uploads_size), ''],
                    [__( 'Free disk space', 'clone-master' ), info.disk?.free_human, info.disk?.free > 1073741824 ? 'ok' : 'warn'],
                    [__( 'Maximum upload', 'clone-master' ), info.limits?.wp_max_upload_human, ''],
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
                        [__( 'Chunk size', 'clone-master' ), formatBytes(info.limits?.recommended_chunk)],
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
       MAIN APPLICATION
       ========================================================= */
    /* =========================================================
       ImportTab for restoring from a server-side backup
       (no upload is required because the archive is already on disk)
       ========================================================= */
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

        // Analyze the selected server-side backup on mount.
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
            if (operationState.busy) { window.alert(__( 'Another Clone Master operation is already running.', 'clone-master' )); return; }
            setOperationBusy(true, __( 'Transactional restore in progress', 'clone-master' ));
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

                    const d = await installerApi(url, fd, step);
                    setProgress(d.progress || 0); setMessage(d.message || '');
                    log(d.message || step + ' ' + __( 'complete', 'clone-master' ), 'success');
                    if (d.errors_log) d.errors_log.forEach(e => log('SQL: ' + e, 'warn'));
                    if (step === 'database') { dbIdx = d.file_index ?? dbIdx; dbOff = d.byte_offset ?? 0; dbQ = d.queries ?? dbQ; dbE = d.errors ?? dbE; }
                    if (step === 'replace_urls') { srIdx = d.table_index ?? srIdx; srOff = d.row_offset ?? 0; srR = d.rows ?? srR; srC = d.cells ?? srC; srS = d.serial ?? srS; }
                    step = d.next_step || null;
                }
                setPhase('done'); log( __( 'Migration completed successfully.', 'clone-master' ), 'success');
            } catch (e) { setError(e.message); log( __( 'Error: ', 'clone-master' ) + e.message, 'error'); setPhase('error'); }
            finally { setOperationBusy(false); }
        };

        const renderPhase = () => {
            switch (phase) {
                case 'analyzing': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'db' }), __( 'Reading backup…', 'clone-master' )),
                    h(ProgressBar, { progress, message }),
                    h('div', { className: 'wpcm-running-row' }, h('span', { className: 'wpcm-spinner' }), __( 'Analysing archive…', 'clone-master' ))
                );

                case 'opts': return h('div', { className: 'wpcm-card' },
                    h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'settings' }), __( 'Restore settings', 'clone-master' )),
                    h('p', { className: 'wpcm-card-desc' }, __( 'Check the settings and configure the options before starting the restore.', 'clone-master' )),
                    manifest && h(Fragment, null,
                        h('div', { className: 'wpcm-opts-section-title', style: { marginBottom: '10px' } }, h(Ico, { n: 'db', s: 14 }), __( 'Archive source', 'clone-master' )),
                        h('div', { className: 'wpcm-manifest-grid' },
                            [
                                [__( 'Source URL', 'clone-master' ), manifest.site_url],
                                [__( 'WordPress version', 'clone-master' ), manifest.wp_version],
                                [__( 'Created on', 'clone-master' ), manifest.created_at],
                                [__( 'Active theme', 'clone-master' ), manifest.active_theme],
                                [__( 'Active plugins', 'clone-master' ), (manifest.active_plugins || []).length],
                                [__( 'Database tables', 'clone-master' ), manifest.tables_count],
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
                        h('div', { className: 'wpcm-opts-section-title' }, h(Ico, { n: 'settings', s: 14 }), __( 'Advanced options', 'clone-master' )),
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.reset_permalinks, onChange: e => setOpts(o => ({ ...o, reset_permalinks: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Regenerate permalinks', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Recommended after every restore.', 'clone-master' ))
                            )
                        ),
                        h('label', { className: 'wpcm-toggle-row' },
                            h('input', { type: 'checkbox', checked: opts.block_indexing, onChange: e => setOpts(o => ({ ...o, block_indexing: e.target.checked })) }),
                            h('span', null,
                                h('span', { className: 'wpcm-toggle-strong' }, __( 'Hide site from search engines', 'clone-master' )),
                                h('span', { className: 'wpcm-toggle-sub' }, __( 'Asks Google & Bing not to index this site (WordPress "Discourage search engines" option). Recommended during post-migration validation : uncheck once verified.', 'clone-master' ))
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
                    h('div', { className: 'wpcm-running-row' }, h('span', { className: 'wpcm-spinner' }), __( 'Processing : please do not close this page.', 'clone-master' ))
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
                    h(ErrorNotice, { message: error || __( 'Unknown error', 'clone-master' ) })
                );

                default: return null;
            }
        };

        return h(Fragment, null, renderPhase(), h(LogViewer, { entries: logs }));
    }

    function SchedulePanel() {
        const [Component, setComponent] = useState(() => window.WPCM_ScheduleTab || null);
        useEffect(() => {
            const ready = () => setComponent(() => window.WPCM_ScheduleTab || null);
            window.addEventListener('wpcm:schedule-ready', ready);
            ready();
            return () => window.removeEventListener('wpcm:schedule-ready', ready);
        }, []);
        if (!Component) {
            return h('div', { className: 'wpcm-card' },
                h('div', { className: 'wpcm-loading-row' }, h('span', { className: 'wpcm-spinner' }), __( 'Loading schedule settings...', 'clone-master' ))
            );
        }
        return h(Component);
    }

    function DebugTab() {
        const [events, setEvents] = useState([]);
        const [loading, setLoading] = useState(true);
        const [error, setError] = useState('');
        const [downloadUrl, setDownloadUrl] = useState('');
        const [logPath, setLogPath] = useState('');
        const [filter, setFilter] = useState('all');

        const load = useCallback(async (silent = false) => {
            if (!silent) setLoading(true);
            setError('');
            try {
                const data = await api('wpcm_debug_get');
                const nextEvents = Array.isArray(data.events) ? data.events : [];
                setEvents(nextEvents);
                setDownloadUrl(data.download_url || '');
                setLogPath(data.log_path || '');
                window.dispatchEvent(new CustomEvent('wpcm:diagnostics-count', {
                    detail: {
                        issues: nextEvents.filter(event => ['warning', 'error', 'critical'].includes(event.level)).length
                    }
                }));
            } catch (e) {
                setError(e.message);
            } finally {
                if (!silent) setLoading(false);
            }
        }, []);

        useEffect(() => {
            load();
            const timer = window.setInterval(() => load(true), 10000);
            return () => window.clearInterval(timer);
        }, [load]);

        const clear = async () => {
            if (!window.confirm(__( 'Clear the persistent diagnostic log?', 'clone-master' ))) return;
            try {
                await api('wpcm_debug_clear');
                await load();
            } catch (e) {
                setError(e.message);
            }
        };

        const filtered = events.filter(event => filter === 'all' || event.level === filter);
        const counts = events.reduce((acc, event) => {
            const level = event.level || 'info';
            acc[level] = (acc[level] || 0) + 1;
            return acc;
        }, {});

        return h(Fragment, null,
            h('div', { className: 'wpcm-intro wpcm-intro-debug' },
                h('div', { className: 'wpcm-intro-ico' }, h(Ico, { n: 'info', s: 18 })),
                h('div', null,
                    h('h4', null, __( 'Persistent production diagnostics', 'clone-master' )),
                    h('p', null, __( 'Clone Master records each critical transition, caught PHP error, fatal shutdown and unexpected browser response. Secrets are redacted automatically.', 'clone-master' ))
                )
            ),
            h('div', { className: 'wpcm-card wpcm-debug-card' },
                h('div', { className: 'wpcm-debug-toolbar' },
                    h('div', null,
                        h('h3', { className: 'wpcm-card-title' }, h(Ico, { n: 'db', s: 16 }), __( 'Diagnostic journal', 'clone-master' )),
                        h('p', { className: 'wpcm-card-desc' }, logPath || 'wp-content/wpcm-logs/clone-master.jsonl')
                    ),
                    h('div', { className: 'wpcm-debug-actions' },
                        h('button', { type: 'button', className: 'wpcm-btn wpcm-btn-ghost', onClick: () => load() }, __( 'Refresh', 'clone-master' )),
                        downloadUrl && h('a', { className: 'wpcm-btn wpcm-btn-primary', href: downloadUrl }, h(Ico, { n: 'download', s: 14 }), __( 'Download report', 'clone-master' )),
                        h('button', { type: 'button', className: 'wpcm-btn wpcm-btn-danger', onClick: clear }, h(Ico, { n: 'trash', s: 14 }), __( 'Clear log', 'clone-master' ))
                    )
                ),
                h('div', { className: 'wpcm-debug-summary' },
                    ['all', 'critical', 'error', 'warning', 'info'].map(level => h('button', {
                        type: 'button',
                        key: level,
                        className: 'wpcm-debug-filter' + (filter === level ? ' active' : ''),
                        onClick: () => setFilter(level)
                    },
                        level === 'all' ? __( 'All', 'clone-master' ) : level,
                        h('span', null, level === 'all' ? events.length : (counts[level] || 0))
                    ))
                ),
                error && h('div', { className: 'wpcm-alert wpcm-alert-error' }, h(Ico, { n: 'x', s: 15 }), error),
                loading && h('div', { className: 'wpcm-loading-row' }, h('span', { className: 'wpcm-spinner' }), __( 'Loading diagnostics...', 'clone-master' )),
                !loading && filtered.length === 0 && h('div', { className: 'wpcm-empty-state' }, __( 'No diagnostic event matches this filter.', 'clone-master' )),
                !loading && filtered.length > 0 && h('div', { className: 'wpcm-debug-list' },
                    filtered.map((event, index) => h('details', { className: 'wpcm-debug-event level-' + (event.level || 'info'), key: (event.event_id || index) + ':' + index },
                        h('summary', null,
                            h('span', { className: 'wpcm-debug-level' }, event.level || 'info'),
                            h('strong', null, event.event || __( 'Diagnostic event', 'clone-master' )),
                            h('span', { className: 'wpcm-debug-event-id' }, event.event_id || ''),
                            h('time', null, event.time || '')
                        ),
                        h('div', { className: 'wpcm-debug-meta' },
                            h('span', null, __( 'Request', 'clone-master' ) + ': ' + (event.request_id || 'n/a')),
                            h('span', null, __( 'Action', 'clone-master' ) + ': ' + (event.action || 'n/a')),
                            h('span', null, __( 'Peak memory', 'clone-master' ) + ': ' + formatBytes(event.peak || 0))
                        ),
                        h('pre', null, JSON.stringify(event.context || {}, null, 2))
                    ))
                )
            )
        );
    }

        /* =========================================================
       MAIN APPLICATION
       ========================================================= */
    function AppFinal() {
        const allowedTabs = ['export', 'import', 'backups', 'schedule', 'server', 'debug'];
        const initialHash = window.location.hash.replace('#wpcm-', '');
        const storedTab = window.sessionStorage.getItem('wpcm-active-tab') || '';
        const initialTab = allowedTabs.includes(initialHash) ? initialHash : (allowedTabs.includes(storedTab) ? storedTab : 'export');
        const [tab, setTab] = useState(initialTab);
        const [restoreTarget, setRestoreTarget] = useState(null);
        const [diagnosticIssues, setDiagnosticIssues] = useState(0);
        const operation = useOperationState();

        const switchTab = useCallback(id => {
            if (!allowedTabs.includes(id)) return;
            setTab(id);
            window.sessionStorage.setItem('wpcm-active-tab', id);
            window.history.replaceState(null, '', window.location.pathname + window.location.search + '#wpcm-' + id);
        }, []);

        useEffect(() => {
            const update = event => setDiagnosticIssues(Math.max(0, Number(event.detail && event.detail.issues) || 0));
            window.addEventListener('wpcm:diagnostics-count', update);
            return () => window.removeEventListener('wpcm:diagnostics-count', update);
        }, []);

        useEffect(() => {
            const openDiagnostics = () => switchTab('debug');
            window.addEventListener('wpcm:open-diagnostics', openDiagnostics);
            return () => window.removeEventListener('wpcm:open-diagnostics', openDiagnostics);
        }, [switchTab]);

        const handleRestore = backup => {
            if (operation.busy) { window.alert(__( 'Finish the current operation before starting another restore.', 'clone-master' )); return; }
            setRestoreTarget(backup);
            switchTab('import');
        };

        const tabs = [
            { id: 'export',  label: __( 'Export', 'clone-master' ),      icon: 'export'  },
            { id: 'import',  label: __( 'Restore', 'clone-master' ),     icon: 'restore' },
            { id: 'backups', label: __( 'Backups', 'clone-master' ),     icon: 'archive' },
            { id: 'schedule',label: __( 'Schedule', 'clone-master' ),    icon: 'clock'   },
            { id: 'server',  label: __( 'Server', 'clone-master' ),      icon: 'server'  },
            { id: 'debug',   label: __( 'Diagnostics', 'clone-master' ), icon: 'info'    },
        ];

        const handleTabKey = (event, index) => {
            let target = index;
            if (event.key === 'ArrowRight') target = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') target = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') target = 0;
            else if (event.key === 'End') target = tabs.length - 1;
            else return;
            event.preventDefault();
            switchTab(tabs[target].id);
            window.requestAnimationFrame(() => document.getElementById('wpcm-tab-' + tabs[target].id)?.focus());
        };

        const panel = (id, child) => h('section', {
            className: 'wpcm-tab-panel' + (tab === id ? ' active' : ''),
            hidden: tab !== id,
            'aria-hidden': tab !== id ? 'true' : 'false',
            'data-wpcm-panel': id,
            id: 'wpcm-panel-' + id,
            role: 'tabpanel',
            'aria-labelledby': 'wpcm-tab-' + id
        }, h(WPCMErrorBoundary, { panelId: id }, child));

        return h('div', { className: 'wpcm-app' },
            h('div', { className: 'wpcm-header' },
                h('div', { className: 'wpcm-header-logo' }, h(Ico, { n: 'layers', s: 20 })),
                h('div', { className: 'wpcm-header-text' },
                    h('div', { className: 'wpcm-title-row' },
                        h('h1', null, 'Clone Master'),
                        h('span', { className: 'wpcm-version-badge' }, 'v' + (wpcmData.version || '3.1.6'))
                    ),
                    h('p', null, __( 'Reliable WPCM backups, staged restore and production diagnostics', 'clone-master' ))
                ),
                h('div', { className: 'wpcm-header-actions' },
                    operation.busy && h('div', { className: 'wpcm-operation-pill' }, h('span', { className: 'wpcm-spinner' }), operation.label),
                    h('button', { type: 'button', className: 'wpcm-diagnostics-shortcut', onClick: () => switchTab('debug') },
                        h(Ico, { n: 'info', s: 15 }),
                        __( 'Diagnostics', 'clone-master' ),
                        diagnosticIssues > 0 && h('span', { className: 'wpcm-diagnostics-count', 'aria-label': String(diagnosticIssues) }, diagnosticIssues > 99 ? '99+' : diagnosticIssues)
                    ),
                    h('a', {
                        href: 'https://buymeacoffee.com/assistouest',
                        target: '_blank',
                        rel: 'noopener noreferrer',
                        className: 'wpcm-coffee-btn',
                        title: (wpcmData.i18n && wpcmData.i18n.supportUs) || __( 'Support open-source', 'clone-master' ),
                    }, h(Ico, { n: 'coffee', s: 15 }), h('span', { className: 'wpcm-coffee-label' }, (wpcmData.i18n && wpcmData.i18n.supportUs) || __( 'Support open-source', 'clone-master' )))
                )
            ),
            h('nav', { className: 'wpcm-tabs', role: 'tablist', 'aria-label': __( 'Clone Master sections', 'clone-master' ) },
                tabs.map((t, index) => h('button', {
                    key: t.id,
                    type: 'button',
                    className: 'wpcm-tab' + (tab === t.id ? ' active' : ''),
                    onClick: () => switchTab(t.id),
                    onKeyDown: event => handleTabKey(event, index),
                    'aria-selected': tab === t.id ? 'true' : 'false',
                    'aria-controls': 'wpcm-panel-' + t.id,
                    id: 'wpcm-tab-' + t.id,
                    role: 'tab',
                    tabIndex: tab === t.id ? 0 : -1
                }, h(Ico, { n: t.icon, s: 15 }), h('span', { className: 'tab-lbl' }, t.label)))
            ),
            operation.busy && h('div', { className: 'wpcm-operation-banner' },
                h(Ico, { n: 'info', s: 15 }),
                h('span', null, __( 'The current operation keeps running when you change tabs. Do not reload or close this page until it finishes.', 'clone-master' ))
            ),
            panel('export', h(ExportTab)),
            panel('import', restoreTarget
                ? h(ImportTabWithRestore, { key: restoreTarget.name, _backupName: restoreTarget.name })
                : h(ImportTab, { key: 'manual' })
            ),
            panel('backups', h(BackupsTab, { onRestore: handleRestore })),
            panel('schedule', h(SchedulePanel)),
            panel('server', h(ServerTab)),
            panel('debug', h(DebugTab))
        );
    }

    /* Mount */
    const root = document.getElementById('wpcm-admin-root');
    if (root) {
        try {
            const application = h(WPCMErrorBoundary, { panelId: 'application' }, h(AppFinal));
            if (wp.element.createRoot) wp.element.createRoot(root).render(application);
            else wp.element.render(application, root);
        } catch (error) {
            persistClientError(
                'react_mount_error',
                error && error.message ? error.message : String(error),
                error && error.stack ? error.stack : 'No JavaScript stack was available.'
            );
            root.innerHTML = '<div class="wpcm-app"><div class="wpcm-card wpcm-runtime-error" role="alert"><h2>Clone Master</h2><p>The React interface could not start. Reload this page and open the WordPress browser console if the error persists.</p></div></div>';
        }
    }

})();
