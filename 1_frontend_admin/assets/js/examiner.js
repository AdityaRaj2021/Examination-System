// Examiner workspace (ported from the examiner half of the original app.js).
// Every request goes to api/examiner.php, which only ever returns this examiner's own data.
const content = $('#content');
let currentPage = 'dashboard';
let pollTimer = null;
const blankDraft = () => ({ id: null, status: 'Draft', title: '', description: '', duration: 30, negativeMarking: false, negativeFraction: 0.25, maxViolations: 1, passPercentage: 40, showResults: true, questions: [], locked: false, readonly: false, fileName: '', attempts: 0 });
let draft = blankDraft();
const totalMarks = qs => qs.reduce((s, q) => s + Number(q.marks || 0), 0);
const blankQuestion = () => ({ question: '', options: ['', '', '', ''], answer: '', marks: 1 });

window.onSessionExpired = () => { location.href = EXAMPRO.loginUrl; };
$('#signOut').addEventListener('click', async () => { try { await api('auth', 'logout', { body: { role: 'examiner' } }); } catch (_) { } location.href = EXAMPRO.loginUrl; });

function setPage(page) {
  clearInterval(pollTimer); pollTimer = null;
  currentPage = page;
  const navPage = ['builder', 'settings', 'preview'].includes(page) ? (draft.id ? 'tests' : 'create') : page === 'monitor' ? 'tests' : page;
  $$('.nav-item').forEach(b => b.classList.toggle('active', b.dataset.page === navPage));
  const titles = { dashboard: 'Dashboard', create: 'Create a test', builder: 'Questions', settings: 'Test settings', preview: 'Preview', tests: 'Test library', students: 'Students & assignments', results: 'Results', monitor: 'Monitor attempts' };
  $('#pageTitle').textContent = titles[page] || 'ExamPro';
  render();
}
$('#nav').addEventListener('click', e => { const b = e.target.closest('[data-page]'); if (b) setPage(b.dataset.page); });
$('#quickCreate').addEventListener('click', () => setPage('create'));

async function render(arg) {
  try {
    if (currentPage === 'dashboard') await renderDashboard();
    else if (currentPage === 'create') renderCreate();
    else if (currentPage === 'builder') renderBuilder();
    else if (currentPage === 'settings') renderSettings();
    else if (currentPage === 'preview') renderPreview();
    else if (currentPage === 'tests') await renderTests();
    else if (currentPage === 'students') await renderStudents();
    else if (currentPage === 'results') await renderResults();
    else if (currentPage === 'monitor') await renderMonitor();
  } catch (e) { content.innerHTML = `<div class="notice error">${esc(e.message)}</div>`; }
}

const stat = (icon, num, label) => `<div class="stat"><div class="stat-icon">${icon}</div><div><strong>${num}</strong><small>${label}</small></div></div>`;
const statusPill = s => `<span class="pill ${s === 'Published' ? '' : s === 'Closed' ? 'blue' : 'draft'}">${esc(s)}</span>`;
function stepper(active) {
  const names = ['Upload questions', 'Test settings', 'Preview', 'Publish'];
  return `<div class="stepper">${names.map((n, i) => `<div class="step ${i + 1 < active ? 'done' : i + 1 === active ? 'active' : ''}"><span class="step-num">${i + 1 < active ? '✓' : i + 1}</span> ${n}</div>`).join('')}</div>`;
}

// ---------------------------------------------------------------- dashboard
async function renderDashboard() {
  const [tests, results] = await Promise.all([api('examiner', 'exams'), api('examiner', 'results')]);
  const published = tests.filter(t => t.status === 'Published').length;
  const attempts = tests.reduce((s, t) => s + t.attempts, 0);
  content.innerHTML = `
 <div class="welcome"><div><h2>Welcome back, ${esc(EXAMPRO.me.fullName)} 👋</h2><div class="muted">Here's what's happening with your assessments today.</div></div><button class="button primary" onclick="setPage('create')">＋ Create new test</button></div>
 <div class="stats">${stat('▤', tests.length, 'Total tests')}${stat('◷', published, 'Published tests')}${stat('♙', attempts, 'Student attempts')}${stat('✓', results.length, 'Submissions received')}</div>
 <div class="two-col"><section class="card"><div class="card-head"><h3>Recent tests</h3><button class="button" onclick="setPage('tests')">View all tests →</button></div>${tests.length ? testTable(tests.slice(0, 5)) : `<div class="empty"><div class="big">▤</div><h3>No tests created yet</h3><p>Create your first test by uploading an Excel question sheet or typing questions.</p><button class="button primary" onclick="setPage('create')">Create your first test</button></div>`}</section>
 <section class="card"><div class="card-head"><h3>Quick start</h3><span class="pill blue">3 steps</span></div><div class="question-preview"><b>01 · Prepare your questions</b><p class="muted">Upload a sheet with Question, Option A–D, Correct Answer and Marks — or type them in.</p></div><div class="question-preview"><b>02 · Configure the test</b><p class="muted">Set a title, description, duration (up to 360 minutes), negative marking and violation limit.</p></div><div class="question-preview"><b>03 · Publish and assign</b><p class="muted">Publish the test, then assign it to verified students.</p></div><a class="button ghost" href="${esc(EXAMPRO.sampleCsv)}" download>↓ Download sample CSV</a></section></div>`;
}

function testTable(tests) {
  return `<div class="table-wrap"><table><thead><tr><th>Test name</th><th>Questions</th><th>Duration</th><th>Total marks</th><th>Assigned</th><th>Status</th><th></th></tr></thead><tbody>${tests.map(t => `<tr><td><b>${esc(t.title)}</b><div class="muted">${esc(t.description || 'No description')}</div></td><td>${t.questionCount}</td><td>${t.duration} min</td><td>${t.totalMarks || '—'}</td><td>${t.assigned}${t.inProgress ? ` <span class="pill draft">${t.inProgress} live</span>` : ''}</td><td>${statusPill(t.status)}</td><td><div class="test-actions" style="flex-wrap:wrap" data-id="${t.id}"><button class="button" data-act="preview">Preview</button><button class="button" data-act="edit">Edit</button>${t.status === 'Draft' ? '<button class="button primary" data-act="publish">Publish</button>' : t.status === 'Published' ? '<button class="button" data-act="close">Close</button>' : '<button class="button" data-act="publish">Reopen</button>'}<button class="button" data-act="monitor">Attempts</button><button class="button danger" data-act="delete">Delete</button></div></td></tr>`).join('')}</tbody></table></div>`;
}

// One delegated handler for the buttons inside test tables.
content.addEventListener('click', async e => {
  const btn = e.target.closest('[data-act]'); const box = e.target.closest('[data-id]');
  if (!btn || !box || !btn.closest('.test-actions')) return;
  const id = Number(box.dataset.id), act = btn.dataset.act;
  try {
    if (act === 'preview') { await loadDraft(id, true); setPage('preview'); }
    else if (act === 'edit') { await loadDraft(id, false); setPage(draft.locked ? 'settings' : 'builder'); }
    else if (act === 'monitor') { monitorExam = id; setPage('monitor'); }
    else if (act === 'publish' || act === 'close') {
      await api('examiner', 'exam_status', { body: { id, status: act === 'close' ? 'Closed' : 'Published' } });
      toast(act === 'close' ? 'Test closed. No new attempts can start.' : 'Test published.'); render();
    } else if (act === 'delete') {
      if (!confirm('Delete this test and all its questions and assignments? This cannot be undone.')) return;
      await api('examiner', 'exam_delete', { body: { id } }); toast('Test deleted.'); render();
    }
  } catch (err) { toast(err.message); }
});

async function loadDraft(id, readonly) {
  const d = await api('examiner', 'exam', { query: { id } });
  const e = d.exam;
  draft = { id: e.id, status: e.status, title: e.title, description: e.description, duration: e.duration, negativeMarking: e.negativeMarking, negativeFraction: e.negativeFraction || 0.25, maxViolations: e.maxViolations, passPercentage: e.passPercentage, showResults: e.showResults, questions: d.questions.map(q => ({ question: q.question, options: q.options.slice(), answer: q.answer, marks: q.marks })), locked: e.attempts > 0, readonly, fileName: '', attempts: e.attempts };
}

// ---------------------------------------------------------------- create wizard: step 1 (upload or type)
function renderCreate() {
  draft = blankDraft();
  content.innerHTML = `
 <div class="card">${stepper(1)}
 <div class="two-col"><section><h3 style="font:700 16px Manrope;margin:0 0 6px">Upload your question bank</h3><p class="muted" style="margin:0 0 18px">Upload an Excel (.xlsx) or CSV file. We'll validate the rows and build your test automatically.</p>
 <form id="uploadForm"><label class="dropzone" id="dropzone" for="questionsFile"><div class="upload-icon">↑</div><strong>Drag your spreadsheet here</strong><p>or choose a file from your device</p><span class="button">Choose file</span><div class="file-name" id="fileName"></div><input id="questionsFile" name="questionsFile" type="file" accept=".xlsx,.csv" hidden></label><div class="hint"><b>Required columns</b><br>Question · Option A · Option B · Option C · Option D · Correct Answer<br><span style="color:#7e8da4">Marks is optional (defaults to 1). Correct Answer must be A, B, C, or D.</span></div><div id="uploadMessage"></div><div class="actions"><a class="button" href="${esc(EXAMPRO.sampleCsv)}" download>↓ Download sample CSV</a><button class="button primary" type="submit">Validate &amp; continue →</button></div></form>
 <div class="hint" style="margin-top:18px"><b>Prefer to type?</b> <button class="text-button" id="manualBtn" type="button">Add questions manually →</button></div></section>
 <aside><div class="card" style="background:#f9fbff;box-shadow:none"><h3>Spreadsheet checklist</h3><div class="question-preview">✓ First row contains column headings</div><div class="question-preview">✓ Each row contains one MCQ</div><div class="question-preview">✓ Four options per question</div><div class="question-preview">✓ Correct answer uses A / B / C / D</div><p class="muted">Files are validated in memory and never stored. Maximum file size: 10 MB. Legacy .xls files must be saved as .xlsx or .csv first.</p></div></aside></div></div>`;
  const input = $('#questionsFile'), zone = $('#dropzone');
  input.addEventListener('change', () => { $('#fileName').textContent = input.files[0]?.name || ''; });
  ['dragenter', 'dragover'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.add('dragover'); }));
  ['dragleave', 'drop'].forEach(ev => zone.addEventListener(ev, e => { e.preventDefault(); zone.classList.remove('dragover'); if (ev === 'drop' && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; $('#fileName').textContent = input.files[0].name; } }));
  $('#manualBtn').onclick = () => { draft.questions = [blankQuestion()]; setPage('builder'); };
  $('#uploadForm').addEventListener('submit', async e => {
    e.preventDefault();
    const file = input.files[0];
    if (!file) { $('#uploadMessage').innerHTML = '<div class="notice error">Choose a spreadsheet first.</div>'; return; }
    const fd = new FormData(); fd.append('questionsFile', file);
    $('#uploadMessage').innerHTML = '<div class="notice">Validating spreadsheet…</div>';
    try { const data = await api('examiner', 'preview_upload', { form: fd }); draft.questions = data.questions; draft.fileName = data.fileName; setPage('settings'); }
    catch (err) { $('#uploadMessage').innerHTML = `<div class="notice error">${esc(err.message)}</div>`; }
  });
}

// ---------------------------------------------------------------- question builder (manual add / edit / append upload)
function qCard(q, i) {
  const n = draft.questions.length;
  return `<div class="card" style="box-shadow:none" data-q="${i}"><div class="card-head"><h3>Question ${i + 1}</h3><div class="test-actions"><button class="button" type="button" data-qa="up" ${i === 0 ? 'disabled' : ''}>↑</button><button class="button" type="button" data-qa="down" ${i === n - 1 ? 'disabled' : ''}>↓</button><button class="button danger" type="button" data-qa="del">Remove</button></div></div>
 <div class="field"><label>Question text *</label><textarea data-f="question" maxlength="2000">${esc(q.question)}</textarea></div>
 <div class="form-row">${['A', 'B', 'C', 'D'].map((L, j) => `<div class="field"><label>Option ${L} * <span style="font-weight:400;margin-left:8px"><input type="radio" name="ans${i}" data-f="answer" value="${L}" ${q.answer === L ? 'checked' : ''}> correct answer</span></label><input data-f="opt${j}" maxlength="500" value="${esc(q.options[j])}"></div>`).join('')}</div>
 <div class="field" style="max-width:160px"><label>Marks *</label><input type="number" data-f="marks" min="0.01" step="0.01" value="${esc(q.marks)}"></div></div>`;
}
function renderBuilder() {
  const ro = draft.locked;
  content.innerHTML = `<div class="card">${stepper(1)}
 <div class="welcome"><div><h2>${draft.id ? 'Edit questions' : 'Add questions'}</h2><div class="muted">${draft.questions.length} question${draft.questions.length === 1 ? '' : 's'} · ${totalMarks(draft.questions)} marks. Mark the correct option for every question.</div></div>
 <label class="button ghost" for="appendFile" style="cursor:pointer">↑ Append from spreadsheet</label><input id="appendFile" type="file" accept=".xlsx,.csv" hidden></div>
 <div id="builderMsg"></div><div id="qList">${draft.questions.map(qCard).join('')}</div>
 <div class="actions"><button class="button" type="button" id="addQ">＋ Add question</button><span><button class="button" type="button" id="bBack">← Back</button> <button class="button primary" type="button" id="bNext">Continue to settings →</button></span></div></div>`;
  $('#addQ').onclick = () => { draft.questions.push(blankQuestion()); renderBuilder(); $$('[data-q]').pop()?.scrollIntoView({ behavior: 'smooth' }); };
  $('#bBack').onclick = () => draft.id ? setPage('tests') : setPage('create');
  $('#bNext').onclick = () => {
    for (let i = 0; i < draft.questions.length; i++) {
      const q = draft.questions[i];
      if (!q.question.trim() || q.options.some(o => !o.trim()) || !q.answer || !(Number(q.marks) > 0)) { $('#builderMsg').innerHTML = `<div class="notice error">Question ${i + 1} is incomplete: it needs text, four options, a correct answer and positive marks.</div>`; $$('[data-q]')[i].scrollIntoView({ behavior: 'smooth' }); return; }
    }
    if (!draft.questions.length) { $('#builderMsg').innerHTML = '<div class="notice error">Add at least one question.</div>'; return; }
    draft.questions.forEach(q => { q.marks = Number(q.marks); });
    setPage('settings');
  };
  $('#appendFile').addEventListener('change', async ev => {
    const f = ev.target.files[0]; if (!f) return;
    const fd = new FormData(); fd.append('questionsFile', f);
    try { const d = await api('examiner', 'preview_upload', { form: fd }); draft.questions = draft.questions.filter(q => q.question.trim() || q.options.some(o => o.trim())).concat(d.questions); toast(`${d.questions.length} questions added.`); renderBuilder(); }
    catch (err) { $('#builderMsg').innerHTML = `<div class="notice error">${esc(err.message)}</div>`; }
  });
}
// Builder inputs write straight into `draft` (no re-render, so typing keeps focus).
content.addEventListener('input', onBuilderEdit);
content.addEventListener('change', onBuilderEdit);
function onBuilderEdit(e) {
  if (currentPage !== 'builder') return;
  const card = e.target.closest('[data-q]'), f = e.target.dataset.f; if (!card || !f) return;
  const q = draft.questions[Number(card.dataset.q)];
  if (f === 'question') q.question = e.target.value;
  else if (f === 'answer') q.answer = e.target.value;
  else if (f === 'marks') q.marks = e.target.value;
  else if (f.startsWith('opt')) q.options[Number(f.slice(3))] = e.target.value;
}
content.addEventListener('click', e => {
  if (currentPage !== 'builder') return;
  const b = e.target.closest('[data-qa]'); if (!b) return;
  const i = Number(b.closest('[data-q]').dataset.q), qs = draft.questions;
  if (b.dataset.qa === 'del') { if (qs.length === 1 || confirm('Remove this question?')) qs.splice(i, 1); }
  else { const j = b.dataset.qa === 'up' ? i - 1 : i + 1; [qs[i], qs[j]] = [qs[j], qs[i]]; }
  renderBuilder();
});

// ---------------------------------------------------------------- settings
function renderSettings() {
  const d = draft, tm = totalMarks(d.questions), lock = d.locked;
  content.innerHTML = `<div class="card">${stepper(2)}
 ${d.fileName ? `<div class="notice">✓ ${d.questions.length} questions validated from <b>${esc(d.fileName)}</b>. Total marks: <b>${tm}</b>.</div>` : ''}
 ${lock ? `<div class="notice error">Students have already attempted this test (${d.attempts}). Questions, duration and scoring are locked so existing results stay valid — you can still change the title, description and whether students see their score.</div>` : ''}
 <div class="two-col"><section><h3 style="font:700 16px Manrope;margin:0 0 18px">Test details</h3><form id="settingsForm"><div class="field"><label for="title">Test title *</label><input id="title" required maxlength="100" placeholder="e.g. DBMS Midterm Examination" value="${esc(d.title)}"></div><div class="field"><label for="description">Description / instructions (optional)</label><textarea id="description" maxlength="500" placeholder="Add instructions or a short description">${esc(d.description)}</textarea></div>
 <div class="form-row"><div class="field"><label for="duration">Duration (minutes) *</label><input id="duration" type="number" min="1" max="360" value="${d.duration}" required ${lock ? 'disabled' : ''}></div><div class="field"><label>Total marks</label><input value="${tm}" disabled></div></div>
 <div class="section-label">Scoring &amp; integrity</div>
 <div class="form-row"><div class="field"><label for="negOn">Negative marking</label><select id="negOn" ${lock ? 'disabled' : ''}><option value="0" ${d.negativeMarking ? '' : 'selected'}>Off — wrong answers score 0</option><option value="1" ${d.negativeMarking ? 'selected' : ''}>On — deduct for wrong answers</option></select></div><div class="field"><label for="negFrac">Deduction per wrong answer (× its marks)</label><input id="negFrac" type="number" min="0.01" max="1" step="0.01" value="${d.negativeFraction}" ${lock || !d.negativeMarking ? 'disabled' : ''}></div></div>
 <div class="form-row"><div class="field"><label for="maxV">Violation limit (auto-submit at)</label><input id="maxV" type="number" min="1" max="20" value="${d.maxViolations}" ${lock ? 'disabled' : ''}></div><div class="field"><label for="passPct">Pass percentage</label><input id="passPct" type="number" min="0" max="100" step="0.01" value="${d.passPercentage}" ${lock ? 'disabled' : ''}></div></div>
 <div class="field"><label><input type="checkbox" id="showRes" ${d.showResults ? 'checked' : ''}> Show score and answer review to students after they submit</label></div>
 <div class="actions"><button class="button" type="button" id="sBack">← Back</button><span>${d.id && !lock ? '<button class="button" type="button" id="sEditQ">Edit questions</button> ' : ''}<button class="button primary" type="submit">${lock ? 'Save changes' : 'Preview test →'}</button></span></div></form></section>
 <section class="card" style="background:#f9fbff;box-shadow:none"><h3>Question bank summary</h3><div class="result-grid"><div><strong>${d.questions.length}</strong><small>Questions</small></div><div><strong>${tm}</strong><small>Total marks</small></div><div><strong>4</strong><small>Options each</small></div></div><p class="muted">The answer key stays on the server and is never sent to a student's browser during an exam.</p><p class="muted"><b>Violation limit 1</b> submits the exam on the first full-screen exit or tab switch (the original behaviour). A higher limit warns the student first.</p></section></div></div>`;
  $('#negOn').addEventListener('change', e => { $('#negFrac').disabled = e.target.value !== '1'; });
  $('#sBack').onclick = () => { collectSettings(); draft.id ? (lock ? setPage('tests') : setPage('builder')) : (draft.fileName ? setPage('create') : setPage('builder')); };
  $('#sEditQ')?.addEventListener('click', () => { collectSettings(); setPage('builder'); });
  $('#settingsForm').addEventListener('submit', async e => {
    e.preventDefault();
    if (!collectSettings()) return;
    if (lock) { try { await saveExam(draft.status); toast('Changes saved.'); setPage('tests'); } catch (err) { toast(err.message); } return; }
    setPage('preview');
  });
}
function collectSettings() {
  draft.title = $('#title').value.trim(); draft.description = $('#description').value.trim(); draft.showResults = $('#showRes').checked;
  if (draft.locked) return true;
  const duration = Number($('#duration').value);
  if (!Number.isInteger(duration) || duration < 1 || duration > 360) { toast('Duration must be between 1 and 360 minutes.'); return false; }
  draft.duration = duration; draft.negativeMarking = $('#negOn').value === '1';
  if (draft.negativeMarking) draft.negativeFraction = Number($('#negFrac').value) || 0.25;
  draft.maxViolations = Math.max(1, Math.min(20, Number($('#maxV').value) || 1)); draft.passPercentage = Number($('#passPct').value);
  return true;
}
async function saveExam(status) {
  const d = draft;
  const body = { id: d.id, status, title: d.title, description: d.description, duration: d.duration, negativeMarking: d.negativeMarking, negativeFraction: d.negativeFraction, maxViolations: d.maxViolations, passPercentage: d.passPercentage, showResults: d.showResults, questions: d.locked ? undefined : d.questions };
  return api('examiner', 'exam_save', { body });
}

// ---------------------------------------------------------------- preview / publish
function renderPreview() {
  const d = draft, tm = totalMarks(d.questions), ro = d.readonly;
  content.innerHTML = `<div class="card">${ro ? '' : stepper(3)}<div class="notice">${ro ? 'Examiner preview — correct answers are highlighted here only; students never receive them during the exam.' : 'Review the test before publishing. Correct answers are highlighted only in this examiner preview.'}</div><div class="two-col"><section class="card" style="box-shadow:none"><h3 style="font:700 18px Manrope">${esc(d.title)} ${statusPill(d.status)}</h3><p class="muted">${esc(d.description || 'No description provided')} · ${d.duration} minutes · ${tm} marks${d.negativeMarking ? ' · negative marking ' + d.negativeFraction + '×' : ''} · violation limit ${d.maxViolations}</p>${d.questions.map((q, i) => `<div class="question-preview"><div class="preview-header"><b>Question ${i + 1}</b><span>${q.marks} mark${Number(q.marks) === 1 ? '' : 's'}</span></div><div class="question-title">${esc(q.question)}</div><div class="option-list">${q.options.map((o, j) => `<div class="option-chip ${q.answer === 'ABCD'[j] ? 'correct' : ''}">${'ABCD'[j]}. ${esc(o)} ${q.answer === 'ABCD'[j] ? '✓' : ''}</div>`).join('')}</div></div>`).join('')}</section>
 <aside class="card" style="box-shadow:none;background:#f9fbff">${ro ? '<h3>Test summary</h3>' : '<h3>Ready to publish?</h3><p class="muted">Publish to make the test available to the students you assign it to, or save it as a draft.</p>'}<div class="question-preview"><b>${d.questions.length}</b><p class="muted">Questions</p></div><div class="question-preview"><b>${d.duration} minutes</b><p class="muted">Time limit</p></div><div class="question-preview"><b>${tm} marks</b><p class="muted">Maximum score</p></div>
 ${ro ? '<button class="button" style="width:100%" id="pvBack">← Back to library</button>' : `<button id="publishBtn" class="button primary" style="width:100%">${d.id && d.status !== 'Draft' ? '✓ Save changes' : '✓ Publish test'}</button>${d.status === 'Published' ? '' : '<button class="button" style="width:100%;margin-top:8px" id="draftBtn">Save as draft</button>'}<button class="button" style="width:100%;margin-top:8px" id="editBtn">← Edit settings</button>`}</aside></div></div>`;
  if (ro) { $('#pvBack').onclick = () => setPage('tests'); return; }
  $('#editBtn').onclick = () => setPage('settings');
  const go = async (status, btn, label) => {
    btn.disabled = true; btn.textContent = 'Saving…';
    try { const r = await saveExam(status); const published = r.exam.status === 'Published'; toast(published ? 'Test published successfully.' : 'Test saved as draft.'); draft = blankDraft(); setPage(published ? 'students' : 'tests'); }
    catch (e) { btn.disabled = false; btn.textContent = label; toast(e.message); }
  };
  $('#publishBtn').onclick = e => go(d.id && d.status !== 'Draft' ? d.status : 'Published', e.target, e.target.textContent);
  $('#draftBtn')?.addEventListener('click', e => go('Draft', e.target, 'Save as draft'));
}

// ---------------------------------------------------------------- library
async function renderTests() {
  const tests = await api('examiner', 'exams');
  content.innerHTML = `<div class="welcome"><div><h2>Test library</h2><div class="muted">Create, edit, publish and monitor your assessments. You only see tests you created.</div></div><button class="button primary" onclick="setPage('create')">＋ Create test</button></div>${tests.length ? testTable(tests) : `<div class="card empty"><div class="big">▤</div><h3>Your test library is empty</h3><p>Upload an Excel sheet to create your first examination.</p><button class="button primary" onclick="setPage('create')">Create a test</button></div>`}`;
}

// ---------------------------------------------------------------- students & assignments
async function renderStudents() {
  const [students, tests] = await Promise.all([api('examiner', 'students'), api('examiner', 'exams')]);
  const eligible = students.filter(s => s.status === 'Verified');
  content.innerHTML = `<div class="welcome"><div><h2>Student directory & test allocation</h2><div class="muted">Student verification is handled by the administrator. Assign tests only to verified students.</div></div><span class="pill blue">${eligible.length} eligible</span></div>
 <div class="card"><div class="card-head"><h3>Student directory</h3><span class="pill">Read-only profiles</span></div>${students.length ? `<div class="table-wrap"><table><thead><tr><th>Student</th><th>ID</th><th>Course / Year</th><th>Eligibility</th><th>Your tests assigned</th></tr></thead><tbody>${students.map(s => `<tr><td><b>${esc(s.fullName)}</b><div class="muted">${esc(s.email)}</div></td><td>${esc(s.studentId)}</td><td>${esc(s.course)} · ${esc(s.year)}</td><td><span class="pill ${s.status === 'Verified' ? '' : 'draft'}">${s.status === 'Verified' ? 'Verified — eligible' : 'Not verified'}</span></td><td>${s.assignedTestIds.length}</td></tr>`).join('')}</tbody></table></div>` : `<div class="empty"><div class="big">♙</div><h3>No student profiles yet</h3><p>Students appear here after registering; the administrator handles verification.</p></div>`}</div>
 <div class="card"><div class="card-head"><h3>Assign a test</h3><span class="pill blue">Verified students only</span></div>${tests.length ? `<form id="assignForm"><div class="field"><label>Test</label><select name="testId" id="assignTest" required>${tests.map(t => `<option value="${t.id}">${esc(t.title)} (${esc(t.status)})</option>`).join('')}</select></div><div class="student-checks">${eligible.map(s => `<label class="student-check"><input type="checkbox" name="studentIds" value="${s.id}"><span><b>${esc(s.fullName)}</b><small>${esc(s.studentId)} · ${esc(s.course)}</small></span></label>`).join('') || '<p class="muted">No verified students available yet. Ask the administrator to verify students.</p>'}</div><div class="actions"><span class="muted">Only selected verified students will see this test (once it is published).</span><button class="button primary" ${eligible.length ? '' : 'disabled'}>Assign test</button></div></form>` : `<div class="empty"><h3>Create a test before assigning it</h3><button class="button primary" onclick="setPage('create')">Create test</button></div>`}</div>`;
  const mark = () => {
    const tid = Number($('#assignTest')?.value);
    $$('input[name=studentIds]').forEach(cb => {
      const s = students.find(x => x.id == cb.value), done = s && s.assignedTestIds.includes(tid);
      cb.checked = done; cb.disabled = done; cb.closest('label').querySelector('small').dataset.t ||= cb.closest('label').querySelector('small').textContent;
      const sm = cb.closest('label').querySelector('small'); sm.textContent = sm.dataset.t + (done ? ' · already assigned' : '');
    });
  };
  $('#assignTest')?.addEventListener('change', mark); mark();
  $('#assignForm')?.addEventListener('submit', async e => {
    e.preventDefault(); const f = new FormData(e.target);
    try { const d = await api('examiner', 'assign', { body: { testId: Number(f.get('testId')), studentIds: f.getAll('studentIds').map(Number) } }); toast(d.message); renderStudents(); }
    catch (err) { toast(err.message); }
  });
}

// ---------------------------------------------------------------- monitoring one test
let monitorExam = null;
async function renderMonitor() {
  const draw = async () => {
    const [exam, attempts, incidents] = await Promise.all([api('examiner', 'exam', { query: { id: monitorExam } }), api('examiner', 'attempts', { query: { exam_id: monitorExam } }), api('examiner', 'incidents', { query: { exam_id: monitorExam } })]);
    const e = exam.exam;
    content.innerHTML = `<div class="welcome"><div><h2>${esc(e.title)} — attempts</h2><div class="muted">${statusPill(e.status)} · ${e.assigned} assigned · ${e.attempts} started · live view refreshes every 15 seconds</div></div><button class="button" onclick="setPage('tests')">← Back to library</button></div>
 <div class="card"><div class="card-head"><h3>Attempts</h3><span class="pill ${e.inProgress ? 'draft' : ''}">${e.inProgress} in progress</span></div>${attempts.length ? `<div class="table-wrap"><table><thead><tr><th>Student</th><th>Status</th><th>Started</th><th>Ends / submitted</th><th>Violations</th><th>Score</th><th></th></tr></thead><tbody>${attempts.map(a => `<tr><td><b>${esc(a.studentName)}</b><div class="muted">${esc(a.studentCode)}</div></td><td><span class="pill ${a.status === 'submitted' ? '' : 'draft'}">${a.status === 'submitted' ? 'Submitted' : 'In progress'}</span>${a.submissionReason && a.submissionReason !== 'student_submit' ? `<div class="muted">${esc(REASONS[a.submissionReason] || a.submissionReason)}</div>` : ''}</td><td>${esc(fmtDate(a.startedAt))}</td><td>${esc(fmtDate(a.submittedAt || a.deadlineAt))}</td><td>${a.violations}</td><td>${a.score !== null ? `${a.score} / ${a.totalMarks} (${a.percentage}%) ${esc(a.resultStatus)}` : '—'}</td><td>${a.status === 'submitted' ? `<button class="button" data-review="${a.attemptId}">Details</button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : '<div class="empty"><h3>No attempts yet</h3><p>Attempts appear here as soon as an assigned student starts the test.</p></div>'}</div>
 <div id="reviewArea"></div>${incidentCard(incidents)}`;
  };
  await draw();
  pollTimer = setInterval(() => { if (currentPage === 'monitor' && !$('#reviewArea')?.innerHTML) draw().catch(() => { }); }, 15000);
}
content.addEventListener('click', async e => {
  const b = e.target.closest('[data-review]'); if (!b) return;
  try {
    const d = await api('examiner', 'attempt_review', { query: { attempt_id: b.dataset.review } });
    const r = d.result;
    $('#reviewArea').innerHTML = `<div class="card"><div class="card-head"><h3>${esc(d.studentName)} — ${esc(d.examTitle)}</h3><span class="pill ${r.status === 'Pass' ? '' : 'draft'}">${esc(r.score)} / ${esc(r.totalMarks)} · ${esc(r.percentage)}% · ${esc(r.status)}</span></div><p class="muted">${esc(REASONS[r.submissionReason] || r.submissionReason)} · ${esc(fmtDate(r.submittedAt))} · ${r.correct ?? '—'} correct, ${r.incorrect ?? '—'} incorrect, ${r.unattempted ?? '—'} unattempted</p>${d.review.length ? d.review.map((q, i) => `<div class="question-preview"><div class="preview-header"><b>Q${i + 1}. ${esc(q.question)}</b><span class="pill ${q.correct ? '' : 'draft'}">${q.correct ? 'Correct' : q.chosen ? 'Incorrect' : 'Skipped'} · ${q.awarded}/${q.marks}</span></div><p class="muted">Student answer: <b>${esc(q.chosen || 'Not answered')}</b> · Correct answer: <b>${esc(q.correctAnswer)}</b></p></div>`).join('') : ''}${d.incidents.length ? `<h3 style="margin-top:16px">Incidents</h3>${d.incidents.map(i => `<div class="question-preview"><b>${esc(i.typeLabel)}</b> ${i.counted ? '<span class="pill draft">counted</span>' : ''}<div class="muted">${esc(i.reason)} · ${esc(fmtDate(i.occurredAt))}</div></div>`).join('')}` : ''}</div>`;
    $('#reviewArea').scrollIntoView({ behavior: 'smooth' });
  } catch (err) { toast(err.message); }
});

function incidentCard(incidents) {
  return `<div class="card"><div class="card-head"><h3>Incident register</h3><span class="pill ${incidents.length ? 'draft' : ''}">${incidents.length} recorded</span></div>${incidents.length ? `<div class="table-wrap"><table><thead><tr><th>Student</th><th>Test</th><th>Incident</th><th>Counts toward limit</th><th>Time</th></tr></thead><tbody>${incidents.slice(0, 300).map(i => `<tr><td><b>${esc(i.studentName)}</b></td><td>${esc(i.examTitle)}</td><td><b>${esc(i.typeLabel)}</b><div class="muted">${esc(i.reason)}</div></td><td>${i.counted ? 'Yes' : 'No'}</td><td>${esc(fmtDate(i.occurredAt))}</td></tr>`).join('')}</tbody></table></div>` : `<div class="empty"><h3>No incidents recorded</h3><p>Full-screen exits, tab switches and student reports appear here with timestamps.</p></div>`}</div>`;
}

// ---------------------------------------------------------------- results
let resultFilter = { exam_id: '', q: '' };
function exportResults(format) { download('examiner', 'export', { ...resultFilter, format }, 'exampro-results.' + format); }
async function renderResults() {
  const [tests, results, incidents] = await Promise.all([api('examiner', 'exams'), api('examiner', 'results', { query: resultFilter }), api('examiner', 'incidents', { query: { exam_id: resultFilter.exam_id } })]);
  content.innerHTML = `<div class="welcome"><div><h2>Exam results & integrity review</h2><div class="muted">Review submissions and investigate recorded exam incidents for your tests.</div></div><div class="actions"><button class="button" onclick="exportResults('xlsx')">↓ Export to Excel</button><button class="button" onclick="exportResults('csv')">↓ CSV</button><span class="pill blue">${results.length} submissions</span></div></div>
 <div class="card"><div class="form-row"><div class="field" style="margin:0"><label>Test</label><select id="fExam"><option value="">All my tests</option>${tests.map(t => `<option value="${t.id}" ${String(t.id) === String(resultFilter.exam_id) ? 'selected' : ''}>${esc(t.title)}</option>`).join('')}</select></div><div class="field" style="margin:0"><label>Search student / ID</label><input id="fQ" value="${esc(resultFilter.q)}" placeholder="Name or student ID"></div></div></div>
 <div class="card"><div class="card-head"><h3>Submissions</h3><span class="pill">${results.filter(r => r.incidentCount).length} flagged attempts</span></div>${results.length ? `<div class="table-wrap"><table><thead><tr><th>Student</th><th>Test</th><th>Submitted</th><th>Score</th><th>Result</th><th>Integrity</th><th></th></tr></thead><tbody>${results.map(r => `<tr><td><b>${esc(r.studentName)}</b><div class="muted">${esc(r.studentCode)}</div></td><td>${esc(r.examTitle)}</td><td>${esc(fmtDate(r.submittedAt))}</td><td>${r.score} / ${r.totalMarks} (${r.percentage}%)</td><td><span class="pill ${r.status === 'Pass' ? '' : 'draft'}">${esc(r.status)}</span></td><td>${r.incidentCount ? `<span class="pill draft">⚑ ${r.incidentCount} event(s)</span>` : '<span class="pill">No incident recorded</span>'}${r.submissionReason !== 'student_submit' ? `<div class="muted">${esc(REASONS[r.submissionReason] || r.submissionReason)}</div>` : ''}</td><td><button class="button" data-review="${r.attemptId}">Details</button></td></tr>`).join('')}</tbody></table></div>` : `<div class="empty"><div class="big">▥</div><h3>No submissions yet</h3><p>Completed tests and their integrity records will appear here.</p></div>`}</div><div id="reviewArea"></div>${incidentCard(incidents)}`;
  $('#fExam').onchange = e => { resultFilter.exam_id = e.target.value; render(); };
  let t; $('#fQ').oninput = e => { clearTimeout(t); t = setTimeout(() => { resultFilter.q = e.target.value.trim(); render().then(() => { const q = $('#fQ'); q.focus(); q.setSelectionRange(q.value.length, q.value.length); }); }, 400); };
}

setPage('dashboard');
