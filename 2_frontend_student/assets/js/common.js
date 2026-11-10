// Shared helpers for all ExamPro pages: API client (CSRF + JSON), escaping, toast, downloads.
(() => {
  const CFG = window.EXAMPRO || {};
  const $ = s => document.querySelector(s);
  const $$ = s => Array.from(document.querySelectorAll(s));
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const csrf = () => { const m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; };
  const setCsrf = t => { const m = document.querySelector('meta[name="csrf-token"]'); if (m && t) m.content = t; };

  function qs(obj) {
    const p = new URLSearchParams();
    Object.entries(obj || {}).forEach(([k, v]) => { if (v !== undefined && v !== null && v !== '') p.set(k, v); });
    const s = p.toString();
    return s ? '&' + s : '';
  }

  /**
   * api('examiner', 'exams')                          -> GET  api/examiner.php?action=exams
   * api('examiner', 'exam_save', {body:{...}})         -> POST JSON
   * api('examiner', 'preview_upload', {form:FormData}) -> POST multipart
   */
  async function api(file, action, opts = {}) {
    const url = `${CFG.api[file]}?action=${encodeURIComponent(action)}${qs(opts.query)}`;
    const init = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
    if (opts.form) { init.method = 'POST'; init.body = opts.form; }
    else if (opts.body !== undefined) { init.method = 'POST'; init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
    if (init.method === 'POST') init.headers['X-CSRF-Token'] = csrf();
    let res;
    try { res = await fetch(url, init); }
    catch (e) { const err = new Error('Network problem. Check your connection and try again.'); err.network = true; throw err; }
    const data = await res.json().catch(() => ({ error: 'Unexpected server response.' }));
    if (!res.ok) {
      let msg = data.error || 'Something went wrong.';
      if (data.rowErrors) msg += ' ' + data.rowErrors.slice(0, 15).map(x => `Row ${x.row}: ${x.error}`).join(' ');
      const err = new Error(msg);
      err.status = res.status; err.data = data;
      if (res.status === 401 && file !== 'auth' && typeof window.onSessionExpired === 'function') window.onSessionExpired();
      throw err;
    }
    return data;
  }

  /** Authenticated file download (xlsx/csv) with readable errors. */
  async function download(file, action, query, fallbackName) {
    const url = `${CFG.api[file]}?action=${encodeURIComponent(action)}${qs(query)}`;
    try {
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) { const d = await res.json().catch(() => ({})); throw new Error(d.error || 'Download failed.'); }
      const cd = res.headers.get('Content-Disposition') || '';
      const m = /filename="?([^";]+)"?/i.exec(cd);
      const a = document.createElement('a');
      a.href = URL.createObjectURL(await res.blob());
      a.download = m ? m[1] : fallbackName;
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 2000);
    } catch (e) { alert(e.message || 'Download failed.'); }
  }

  function toast(msg) {
    let el = $('#toast');
    if (!el) { el = document.createElement('div'); el.id = 'toast'; el.className = 'toast'; document.body.appendChild(el); }
    el.textContent = msg; el.classList.add('show');
    clearTimeout(toast._t); toast._t = setTimeout(() => el.classList.remove('show'), 3200);
  }

  const fmtDate = iso => iso ? new Date(iso).toLocaleString() : '—';
  const fmtDay = iso => iso ? new Date(iso).toLocaleDateString() : '—';
  const fmtTime = s => { s = Math.max(0, Math.floor(s)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return (h ? String(h).padStart(2, '0') + ':' : '') + String(m).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0'); };
  const initials = s => String(s || '?').trim().split(/\s+/).slice(0, 2).map(x => x[0] || '').join('').toUpperCase();
  const REASONS = { student_submit: 'Normal submission', time_limit: 'Auto-submitted: time limit', fullscreen_exit: 'Auto-submitted: full-screen exit', tab_switch: 'Auto-submitted: tab switch', violation_limit: 'Auto-submitted: violation limit', migrated: 'Migrated from prototype' };

  // Back/Forward cache would show a stale signed-in screen; reload so the server re-checks the session.
  window.addEventListener('pageshow', e => { if (e.persisted) location.reload(); });

  Object.assign(window, { $, $$, esc, api, download, toast, fmtDate, fmtDay, fmtTime, initials, setCsrf, csrf, REASONS });
})();
