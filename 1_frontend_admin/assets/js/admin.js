// Admin console (ported from the inline script of the original admin.html).
let examiners = [], students = [], tests = [], results = [], incidents = [];
const statusClass = s => s === 'Verified' ? 'verified' : s === 'Rejected' || s === 'Disabled' ? 'rejected' : s === 'Active' ? 'active' : 'pending';
const examStatusClass = s => s === 'Published' ? 'active' : s === 'Closed' ? 'rejected' : 'pending';
function setNotice(target, message, error = false) { $(target).innerHTML = `<div class="notice-admin ${error ? 'error' : ''}">${esc(message)}</div>`; }
const SECTION_TITLES = { overview: 'Overview', examiners: 'Examiner management', students: 'Student verification', exams: 'Examinations', results: 'Results & activity', settings: 'Settings & help' };

function goSection(name) {
  $$('.section-view').forEach(x => x.hidden = x.id !== 'section-' + name);
  $$('.admin-nav button[data-section]').forEach(b => b.classList.toggle('active', b.dataset.section === name));
  $('#breadcrumb').textContent = SECTION_TITLES[name] || name;
  $('#adminSidebar').classList.remove('open'); $('#mobileOverlay').classList.remove('show');
  if (name === 'overview') loadOverview();
  if (name === 'examiners') loadExaminers();
  if (name === 'students') loadStudents();
  if (name === 'exams') loadTests();
  if (name === 'results') loadResults();
}
$$('.admin-nav button[data-section]').forEach(b => b.addEventListener('click', () => goSection(b.dataset.section)));
$$('[data-go]').forEach(b => b.addEventListener('click', () => goSection(b.dataset.go)));

// ---------------------------------------------------------------- auth
window.onSessionExpired = () => { if (!$('#adminApp').hidden) location.reload(); };
$('#adminLoginForm').addEventListener('submit', async e => {
  e.preventDefault();
  try {
    const d = await api('auth', 'admin_login', { body: Object.fromEntries(new FormData(e.target)) });
    setCsrf(d.csrf);
    location.reload();
  } catch (err) { setNotice('#adminLoginMessage', err.message, true); }
});
$('#logoutBtn').addEventListener('click', async () => {
  try { await api('auth', 'logout', { body: { role: 'admin' } }); } catch (_) { }
  location.reload();
});
$('#menuBtn').addEventListener('click', () => { $('#adminSidebar').classList.add('open'); $('#mobileOverlay').classList.add('show'); });
$('#mobileOverlay').addEventListener('click', () => { $('#adminSidebar').classList.remove('open'); $('#mobileOverlay').classList.remove('show'); });

// ---------------------------------------------------------------- overview
async function loadOverview() {
  try {
    const o = await api('admin', 'overview');
    $('#statExaminers').textContent = o.examiners; $('#statStudents').textContent = o.students;
    $('#statPending').textContent = o.pending; $('#statTests').textContent = o.tests; $('#pendingNavCount').textContent = o.pending;
    $('#attentionList').innerHTML = o.pendingStudents.length
      ? o.pendingStudents.map(s => `<div class="activity-item"><span class="activity-dot" style="background:#e5a33b"></span><div style="flex:1;min-width:0"><b>${esc(s.fullName || 'Unnamed student')}</b><small>${esc(s.studentId || 'No student ID')} · Awaiting verification</small></div><button class="text-link" data-review="1">Review</button></div>`).join('')
      : '<div class="empty-state"><strong>All caught up 🎉</strong>No student profiles are waiting for review.</div>';
    $$('[data-review]').forEach(b => b.addEventListener('click', () => goSection('students')));
  } catch (e) { $('#attentionList').innerHTML = '<div class="empty-state">Could not load dashboard data. ' + esc(e.message) + '</div>'; }
}
$('#refreshOverview').addEventListener('click', loadOverview);
$('#settingsRefresh').addEventListener('click', loadOverview);
$('#adminPasswordForm').addEventListener('submit', async e => {
  e.preventDefault();
  const f = Object.fromEntries(new FormData(e.target));
  if (f.newPassword !== f.confirm) return setNotice('#pwMessage', 'New password and confirmation do not match.', true);
  try {
    const d = await api('admin', 'admin_password', { body: { currentPassword: f.currentPassword, newPassword: f.newPassword } });
    setCsrf(d.csrf); e.target.reset(); setNotice('#pwMessage', 'Password updated. Use the new password next time you sign in.');
  } catch (err) { setNotice('#pwMessage', err.message, true); }
});

// ---------------------------------------------------------------- examiners
async function loadExaminers() {
  try { examiners = await api('admin', 'examiners'); $('#examinerCount').textContent = examiners.length + ' accounts'; renderExaminers(); }
  catch (e) { $('#examinerList').innerHTML = '<div class="empty-state">Could not load examiner accounts. ' + esc(e.message) + '</div>'; }
}
function renderExaminers() {
  const q = ($('#examinerSearch').value || '').toLowerCase();
  const list = examiners.filter(x => `${x.fullName} ${x.email}`.toLowerCase().includes(q));
  $('#examinerList').innerHTML = list.length
    ? `<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Examiner</th><th>Exams</th><th>Created</th><th>Account</th><th>Actions</th></tr></thead><tbody>${list.map(x => `<tr><td><div class="person-cell"><span class="person-avatar">${esc(initials(x.fullName))}</span><div><div class="person-name">${esc(x.fullName)}</div><div class="person-sub">${esc(x.email)}</div></div></div></td><td>${x.examCount}</td><td>${esc(fmtDay(x.createdAt))}</td><td><span class="status-badge ${statusClass(x.status)}">${esc(x.status)}</span></td><td><div class="row-actions"><button class="tiny-btn ${x.status === 'Active' ? 'reject' : 'approve'}" data-ex-toggle="${x.id}">${x.status === 'Active' ? 'Disable' : 'Enable'}</button><button class="tiny-btn" data-ex-pass="${x.id}">Reset password</button>${x.examCount === 0 ? `<button class="tiny-btn reject" data-ex-del="${x.id}">Delete</button>` : ''}</div></td></tr>`).join('')}</tbody></table></div>`
    : '<div class="empty-state"><strong>No examiners found</strong>' + (q ? 'Try another search.' : 'Create the first examiner account using the form.') + '</div>';
  $$('[data-ex-toggle]').forEach(b => b.addEventListener('click', async () => {
    const x = examiners.find(e => e.id == b.dataset.exToggle); const to = x.status === 'Active' ? 'Disabled' : 'Active';
    if (!confirm(`${to === 'Disabled' ? 'Disable' : 'Enable'} ${x.fullName}?`)) return;
    try { await api('admin', 'examiner_update', { body: { id: x.id, status: to } }); loadExaminers(); } catch (e) { alert(e.message); }
  }));
  $$('[data-ex-pass]').forEach(b => b.addEventListener('click', async () => {
    const x = examiners.find(e => e.id == b.dataset.exPass); const p = prompt(`New temporary password for ${x.fullName} (6+ characters):`);
    if (!p) return;
    try { await api('admin', 'examiner_update', { body: { id: x.id, password: p } }); toast('Password updated.'); } catch (e) { alert(e.message); }
  }));
  $$('[data-ex-del]').forEach(b => b.addEventListener('click', async () => {
    const x = examiners.find(e => e.id == b.dataset.exDel);
    if (!confirm(`Delete the account of ${x.fullName}? This cannot be undone.`)) return;
    try { await api('admin', 'examiner_delete', { body: { id: x.id } }); loadExaminers(); loadOverview(); } catch (e) { alert(e.message); }
  }));
}
$('#examinerSearch').addEventListener('input', renderExaminers);
$('#focusAddExaminer').addEventListener('click', () => $('#examinerName').focus());
$('#createExaminer').addEventListener('submit', async e => {
  e.preventDefault();
  const body = Object.fromEntries(new FormData(e.target));
  try {
    await api('admin', 'examiner_create', { body });
    setNotice('#createMessage', 'Examiner account created successfully.'); e.target.reset();
    await loadExaminers(); await loadOverview();
  } catch (err) { setNotice('#createMessage', err.message, true); }
});

// ---------------------------------------------------------------- students
async function loadStudents() {
  try { students = await api('admin', 'students'); renderStudents(); }
  catch (e) { $('#studentList').innerHTML = '<div class="empty-state">Could not load student profiles. ' + esc(e.message) + '</div>'; }
}
function renderStudents() {
  const pending = students.filter(s => s.status === 'Pending verification').length;
  $('#studentPendingCount').textContent = pending;
  $('#studentVerifiedCount').textContent = students.filter(s => s.status === 'Verified').length;
  $('#studentRejectedCount').textContent = students.filter(s => s.status === 'Rejected').length;
  $('#pendingNavCount').textContent = pending;
  const q = ($('#studentSearch').value || '').toLowerCase(), f = $('#studentStatusFilter').value;
  const list = students.filter(s => `${s.fullName} ${s.email} ${s.studentId} ${s.course}`.toLowerCase().includes(q) && (f === 'all' || s.status === f));
  $('#studentList').innerHTML = list.length
    ? `<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Student</th><th>Academic details</th><th>Status</th><th>Review action</th></tr></thead><tbody>${list.map(s => `<tr><td><div class="person-cell"><span class="person-avatar" style="background:#e8f6f0;color:#16845b">${esc(initials(s.fullName))}</span><div><div class="person-name">${esc(s.fullName)}</div><div class="person-sub">${esc(s.email)}</div><div class="person-sub">ID: ${esc(s.studentId || '—')}</div></div></div></td><td><div class="person-name">${esc(s.course || 'Course not provided')}</div><div class="person-sub">${esc(s.year || 'Year not provided')}</div></td><td><span class="status-badge ${statusClass(s.status)}">${esc(s.status)}</span></td><td><div class="row-actions">${s.status !== 'Verified' ? `<button class="tiny-btn approve" data-status="Verified" data-id="${s.id}">✓ Verify</button>` : ''}${s.status !== 'Rejected' ? `<button class="tiny-btn reject" data-status="Rejected" data-id="${s.id}">× Reject</button>` : ''}${s.status !== 'Pending verification' ? `<button class="tiny-btn" data-status="Pending verification" data-id="${s.id}">Reset</button>` : ''}<button class="tiny-btn" data-st-pass="${s.id}">Set password</button></div></td></tr>`).join('')}</tbody></table></div>`
    : '<div class="empty-state"><strong>No matching student profiles</strong>New student registrations will appear here.</div>';
  $$('[data-status][data-id]').forEach(b => b.addEventListener('click', () => updateStudentStatus(b.dataset.id, b.dataset.status)));
  $$('[data-st-pass]').forEach(b => b.addEventListener('click', async () => {
    const s = students.find(x => x.id == b.dataset.stPass); const p = prompt(`New password for ${s.fullName} (6+ characters):`);
    if (!p) return;
    try { await api('admin', 'student_password', { body: { id: s.id, password: p } }); toast('Password updated.'); } catch (e) { alert(e.message); }
  }));
}
async function updateStudentStatus(id, status) {
  const s = students.find(x => x.id == id);
  const action = status === 'Verified' ? 'verify' : status === 'Rejected' ? 'reject' : 'reset';
  if (!confirm(`Are you sure you want to ${action} ${s?.fullName || 'this student'}?`)) return;
  try { await api('admin', 'student_status', { body: { id: Number(id), status } }); await loadStudents(); await loadOverview(); }
  catch (e) { alert(e.message); }
}
$('#studentSearch').addEventListener('input', renderStudents);
$('#studentStatusFilter').addEventListener('change', renderStudents);
$('#refreshStudents').addEventListener('click', loadStudents);

// ---------------------------------------------------------------- examinations + monitoring
async function loadTests() {
  try {
    if (!examiners.length) examiners = await api('admin', 'examiners');
    const sel = $('#testExaminerFilter'), cur = sel.value;
    sel.innerHTML = '<option value="">All examiners</option>' + examiners.map(x => `<option value="${x.id}">${esc(x.fullName)}</option>`).join('');
    sel.value = cur;
    tests = await api('admin', 'exams', { query: { examiner_id: sel.value } });
    renderTests();
  } catch (e) { $('#testList').innerHTML = '<div class="empty-state">Could not load examinations. ' + esc(e.message) + '</div>'; }
}
function renderTests() {
  const q = ($('#testSearch').value || '').toLowerCase(), list = tests.filter(t => String(t.title || '').toLowerCase().includes(q));
  $('#examCount').textContent = tests.length;
  $('#questionVolume').textContent = tests.reduce((n, t) => n + Number(t.questionCount || 0), 0);
  $('#testList').innerHTML = list.length
    ? `<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Examination</th><th>Examiner</th><th>Questions</th><th>Duration</th><th>Assigned / attempts</th><th>Created</th><th>Status</th><th></th></tr></thead><tbody>${list.map(t => `<tr><td><div class="person-name">${esc(t.title)}</div><div class="person-sub">${esc(t.description || 'Examination')}</div></td><td>${esc(t.examinerName)}</td><td>${t.questionCount}</td><td>${esc(t.duration)} min</td><td>${t.assigned} / ${t.attempts}${t.inProgress ? ` <span class="status-badge pending">${t.inProgress} live</span>` : ''}</td><td>${esc(fmtDay(t.createdAt))}</td><td><span class="status-badge ${examStatusClass(t.status)}">${esc(t.status)}</span></td><td><button class="tiny-btn" data-monitor="${t.id}">Monitor</button></td></tr>`).join('')}</tbody></table></div>`
    : '<div class="empty-state"><strong>No examinations found</strong>Tests created by examiners will appear here.</div>';
  $$('[data-monitor]').forEach(b => b.addEventListener('click', () => openMonitor(b.dataset.monitor)));
}
let monitorTimer = null;
async function openMonitor(id) {
  const panel = $('#monitorPanel'); panel.hidden = false;
  clearInterval(monitorTimer);
  const draw = async () => {
    try {
      const d = await api('admin', 'exam_monitor', { query: { id } });
      const e = d.exam;
      panel.innerHTML = `<div class="panel-head"><h2>Monitoring: ${esc(e.title)}</h2><button class="text-link" id="closeMonitor">Close ✕</button></div><div class="panel-body"><p class="muted-admin" style="margin:0 0 14px">${esc(e.examinerName)} · ${e.duration} min · ${e.questionCount} questions · violation limit ${e.maxViolations}${e.negativeMarking ? ' · negative marking ' + e.negativeFraction + '×' : ''}</p>${d.attempts.length ? `<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Student</th><th>Status</th><th>Started</th><th>Ends / submitted</th><th>Violations</th><th>Score</th></tr></thead><tbody>${d.attempts.map(a => `<tr><td><div class="person-name">${esc(a.studentName)}</div><div class="person-sub">${esc(a.studentCode)}</div></td><td><span class="status-badge ${a.status === 'submitted' ? 'active' : 'pending'}">${a.status === 'submitted' ? 'Submitted' : 'In progress'}</span>${a.submissionReason && a.submissionReason !== 'student_submit' ? `<div class="person-sub">${esc(REASONS[a.submissionReason] || a.submissionReason)}</div>` : ''}</td><td>${esc(fmtDate(a.startedAt))}</td><td>${esc(fmtDate(a.submittedAt || a.deadlineAt))}</td><td>${a.violations}${a.incidentCount ? ` (${a.incidentCount} events)` : ''}</td><td>${a.score !== null ? `<b>${a.score} / ${a.totalMarks}</b> (${a.percentage}%)` : '—'}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty-state">No student has started this examination yet.</div>'}</div>`;
      $('#closeMonitor').onclick = () => { clearInterval(monitorTimer); panel.hidden = true; };
    } catch (err) { panel.innerHTML = `<div class="empty-state">${esc(err.message)}</div>`; clearInterval(monitorTimer); }
  };
  await draw();
  monitorTimer = setInterval(() => { if (panel.hidden || $('#section-exams').hidden) return clearInterval(monitorTimer); draw(); }, 15000);
  panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
$('#testSearch').addEventListener('input', renderTests);
$('#testExaminerFilter').addEventListener('change', loadTests);
$('#refreshTests').addEventListener('click', loadTests);

// ---------------------------------------------------------------- results + incidents
const resultFilters = () => ({ examiner_id: $('#resultExaminer').value, exam_id: $('#resultExam').value, student_id: $('#resultStudent').value, q: $('#resultSearch').value.trim() });
async function fillResultFilters() {
  const [ex, ts, st] = await Promise.all([examiners.length ? examiners : api('admin', 'examiners'), api('admin', 'exams'), students.length ? students : api('admin', 'students')]);
  examiners = ex; students = st;
  const keep = ['#resultExaminer', '#resultExam', '#resultStudent'].map(s => $(s).value);
  $('#resultExaminer').innerHTML = '<option value="">All examiners</option>' + ex.map(x => `<option value="${x.id}">${esc(x.fullName)}</option>`).join('');
  $('#resultExam').innerHTML = '<option value="">All examinations</option>' + ts.map(t => `<option value="${t.id}">${esc(t.title)} — ${esc(t.examinerName)}</option>`).join('');
  $('#resultStudent').innerHTML = '<option value="">All students</option>' + st.map(s => `<option value="${s.id}">${esc(s.fullName)} (${esc(s.studentId)})</option>`).join('');
  $('#resultExaminer').value = keep[0]; $('#resultExam').value = keep[1]; $('#resultStudent').value = keep[2];
}
async function loadResults() {
  try {
    if (!$('#resultExam').dataset.ready) { await fillResultFilters(); $('#resultExam').dataset.ready = '1'; }
    const f = resultFilters();
    [results, incidents] = await Promise.all([api('admin', 'results', { query: f }), api('admin', 'incidents', { query: f })]);
    renderResults();
  } catch (e) { $('#resultsList').innerHTML = '<div class="empty-state">Could not load results. ' + esc(e.message) + '</div>'; }
}
function renderResults() {
  $('#resultsCount').textContent = results.length + ' records';
  $('#resultsList').innerHTML = results.length
    ? `<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Student</th><th>Examination</th><th>Examiner</th><th>Score</th><th>Result</th><th>Integrity</th><th>Submitted</th></tr></thead><tbody>${results.map(r => `<tr><td><div class="person-name">${esc(r.studentName)}</div><div class="person-sub">${esc(r.studentCode)}</div></td><td>${esc(r.examTitle)}</td><td>${esc(r.examinerName)}</td><td><b>${r.score} / ${r.totalMarks}</b> <span class="person-sub">(${r.percentage}%)</span></td><td><span class="status-badge ${r.status === 'Pass' ? 'active' : 'rejected'}">${esc(r.status)}</span></td><td>${r.incidentCount ? `<span class="status-badge pending">${r.incidentCount} event(s)</span>` : '<span class="muted-admin">None</span>'}${r.submissionReason !== 'student_submit' ? `<div class="person-sub">${esc(REASONS[r.submissionReason] || r.submissionReason)}</div>` : ''}</td><td>${esc(fmtDate(r.submittedAt))}</td></tr>`).join('')}</tbody></table></div>`
    : '<div class="empty-state"><strong>No results found</strong>Submitted examination results will appear here when available.</div>';
  $('#incidentCount').textContent = incidents.length + ' recorded';
  $('#incidentList').innerHTML = incidents.length
    ? `<div class="data-table-wrap"><table class="data-table"><thead><tr><th>Student</th><th>Examination</th><th>Incident</th><th>Counts</th><th>Time</th></tr></thead><tbody>${incidents.slice(0, 300).map(i => `<tr><td><div class="person-name">${esc(i.studentName)}</div><div class="person-sub">${esc(i.studentCode)}</div></td><td>${esc(i.examTitle)}<div class="person-sub">${esc(i.examinerName)}</div></td><td><b>${esc(i.typeLabel)}</b><div class="person-sub">${esc(i.reason)}</div></td><td>${i.counted ? 'Yes' : 'No'}</td><td>${esc(fmtDate(i.occurredAt))}</td></tr>`).join('')}</tbody></table></div>`
    : '<div class="empty-state">No incidents recorded for the current filters.</div>';
}
['#resultExaminer', '#resultExam', '#resultStudent'].forEach(s => $(s).addEventListener('change', loadResults));
let resultSearchT; $('#resultSearch').addEventListener('input', () => { clearTimeout(resultSearchT); resultSearchT = setTimeout(loadResults, 300); });
$('#refreshResults').addEventListener('click', loadResults);
$('#exportResultsExcel').addEventListener('click', () => download('admin', 'export', { ...resultFilters(), format: 'xlsx' }, 'exampro-results.xlsx'));
$('#exportResultsCsv').addEventListener('click', () => download('admin', 'export', { ...resultFilters(), format: 'csv' }, 'exampro-results.csv'));

// ---------------------------------------------------------------- boot
if (!$('#adminApp').hidden) {
  $('#currentDate').textContent = new Date().toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  loadOverview();
}
