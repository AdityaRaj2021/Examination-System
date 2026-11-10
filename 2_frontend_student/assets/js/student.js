// Student portal + exam engine (ported from the student half of the original app.js).
// Timer, deadline, answers and incidents are authoritative on the server (api/student.php);
// this file only displays them and reports browser events.
const content = $('#content');
let currentPage = 'auth', user = null, tests = [];
let exam = null, answers = {}, cur = 0, endAt = 0, violations = 0, submitting = false, fsStarted = false, lastPayload = null;
let tickH = null, hbH = null, pending = new Map(), flushing = false, incQueue = [], incRetry = null, dialogOpen = 0, blurT = null, lastBlurReport = 0;
const fsSupported = !!document.documentElement.requestFullscreen;

window.onSessionExpired = () => {
  if (exam) { toast('Your session expired. Reload the page and sign in again; your saved answers and timer are kept.'); return; }
  user = null; renderAuth('signin', 'Your session expired. Please sign in again.');
};

function setPage(page) {
  currentPage = page;
  $('#pageTitle').textContent = page === 'auth' ? 'Student access' : page === 'exam' ? 'Examination' : page === 'result' ? 'Result' : 'Student portal';
  if (user) { $('#sideName').textContent = user.fullName; $('#sideAvatar').textContent = initials(user.fullName); }
  render();
}
function render() {
  if (currentPage === 'auth') renderAuth();
  else if (currentPage === 'student') renderStudent();
  else if (currentPage === 'exam') renderExam();
}

// ---------------------------------------------------------------- auth
function renderAuth(mode = 'signin', message = '') {
  currentPage = 'auth'; $('#pageTitle').textContent = 'Student access';
  content.innerHTML = `<div class="auth-wrap"><div class="auth-card"><div class="brand auth-brand"><span class="brand-icon">E</span><span>ExamPro</span></div><h2>${mode === 'signup' ? 'Create student account' : 'Student sign in'}</h2><p class="muted">${mode === 'signup' ? 'Submit your profile for verification before taking tests.' : 'Sign in using your verified student account.'}</p>${message ? `<div class="notice">${esc(message)}</div>` : ''}<div id="authMessage"></div>${mode === 'signup' ? `<form id="signupForm"><div class="field"><label>Full name *</label><input name="fullName" required maxlength="120"></div><div class="field"><label>Email *</label><input name="email" type="email" required></div><div class="field"><label>Password * (6+ characters)</label><input name="password" type="password" minlength="6" required></div><div class="field"><label>Student ID / Roll number *</label><input name="studentId" required maxlength="60"></div><div class="form-row"><div class="field"><label>Course / Department *</label><input name="course" required placeholder="B.Tech CSE"></div><div class="field"><label>Year *</label><select name="year" required><option value="">Choose year</option><option>1st year</option><option>2nd year</option><option>3rd year</option><option>4th year</option><option>Other</option></select></div></div><button class="button primary" style="width:100%">Submit profile</button></form><p class="auth-switch">Already registered? <button class="text-button" id="toSignin">Sign in</button></p>` : `<form id="signinForm"><div class="field"><label>Email</label><input name="email" type="email" required autocomplete="username"></div><div class="field"><label>Password</label><input name="password" type="password" required autocomplete="current-password"></div><button class="button primary" style="width:100%">Sign in</button></form><p class="auth-switch">New student? <button class="text-button" id="toSignup">Sign up</button></p>`}<button class="button" id="backExaminer" style="width:100%;margin-top:8px">← Back to examiner dashboard</button></div></div>`;
  $('#toSignup')?.addEventListener('click', () => renderAuth('signup'));
  $('#toSignin')?.addEventListener('click', () => renderAuth('signin'));
  $('#backExaminer').onclick = () => { location.href = EXAMPRO.examinerUrl; };
  $('#signupForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    try { const d = await api('auth', 'student_signup', { body: Object.fromEntries(new FormData(e.target)) }); renderAuth('signin', d.message); }
    catch (err) { $('#authMessage').innerHTML = `<div class="notice error">${esc(err.message)}</div>`; }
  });
  $('#signinForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    try { const d = await api('auth', 'student_login', { body: Object.fromEntries(new FormData(e.target)) }); setCsrf(d.csrf); user = d.user; setPage('student'); }
    catch (err) { $('#authMessage').innerHTML = `<div class="notice error">${esc(err.message)}</div>`; }
  });
}

// ---------------------------------------------------------------- dashboard
const stateBtn = t => t.state === 'in_progress' ? `<button class="button primary" onclick="startTest(${t.id})">Resume test →</button>`
  : t.state === 'submitted' ? (t.resultVisible ? `<button class="button" onclick="viewResult(${t.attemptId})">View result</button>` : '')
  : `<button class="button primary" onclick="startTest(${t.id})">Start test →</button>`;
async function renderStudent() {
  try { tests = await api('student', 'tests'); } catch (e) { content.innerHTML = `<div class="notice error">${esc(e.message)}</div>`; return; }
  const live = tests.find(t => t.state === 'in_progress');
  content.innerHTML = `<div class="welcome"><div><h2>Hello, ${esc(user.fullName)}</h2><div class="muted">${esc(user.studentId)} · ${esc(user.course)} · Verified</div></div><button class="button" id="signOutBtn">Sign out</button></div>
 <div class="notice">Only examinations assigned to your account by the examiner are shown here.</div>
 ${live ? `<div class="notice error"><b>You have an exam in progress: ${esc(live.title)}.</b> The timer kept running on the server (about ${fmtTime(live.remainingSeconds)} left). Choose “Resume test” to continue.</div>` : ''}
 ${tests.length ? tests.map(t => `<div class="test-card"><div><span class="pill ${t.state === 'submitted' ? 'blue' : t.state === 'in_progress' ? 'draft' : ''}">${t.state === 'submitted' ? 'Submitted' : t.state === 'in_progress' ? 'In progress' : 'Assigned to you'}</span><h3 style="margin-top:10px">${esc(t.title)}</h3><div class="muted">${esc(t.description || 'No additional instructions.')}</div><div class="test-meta"><span>◷ ${t.duration} minutes</span><span>▤ ${t.questionCount} questions</span><span>★ ${t.totalMarks} marks</span>${t.resultVisible ? `<span>✓ Score ${t.score}/${t.totalMarksResult} (${t.percentage}%) · ${esc(t.resultStatus)}</span>` : t.state === 'submitted' ? '<span>Score not released yet</span>' : ''}</div></div>${stateBtn(t)}</div>`).join('') : `<div class="card empty"><div class="big">▤</div><h3>No tests assigned yet</h3><p>Your examiner has not allocated an examination to your account.</p></div>`}`;
  $('#signOutBtn').onclick = async () => { try { await api('auth', 'logout', { body: { role: 'student' } }); } catch (_) { } user = null; location.reload(); };
}
async function viewResult(attemptId) {
  try { lastPayload = await api('student', 'result', { query: { attemptId } }); setPage('result'); renderResult(lastPayload); } catch (e) { toast(e.message); }
}

// ---------------------------------------------------------------- starting / resuming
async function requestFs() {
  try { if (!document.fullscreenElement && fsSupported) await document.documentElement.requestFullscreen(); } catch (_) { }
  return !!document.fullscreenElement;
}
async function startTest(id) {
  const t = tests.find(x => x.id === id); if (!t) return;
  const resume = t.state === 'in_progress';
  dialogOpen++;
  const ok = confirm(resume
    ? `Resume "${t.title}"? The server timer kept running (about ${fmtTime(t.remainingSeconds)} left). Full-screen mode is required.`
    : `Start "${t.title}"? The ${t.duration}-minute timer starts immediately and keeps running on the server even if you refresh. Full-screen mode is required. Leaving full screen or switching tabs is recorded as an incident and may automatically submit your answers.`);
  dialogOpen--;
  if (!ok) return;
  const fs = requestFs();   // request right after the click so the browser accepts it
  try {
    const data = await api('student', 'start', { body: { examId: id } });
    fsStarted = await fs;
    beginExam(data);
  } catch (e) { toast(e.message); if (document.fullscreenElement) document.exitFullscreen?.().catch(() => { }); renderStudent(); }
}
function beginExam(data) {
  exam = data; answers = { ...(data.answers || {}) }; cur = 0; violations = data.violations || 0;
  submitting = false; pending = new Map(); incQueue = []; sync(data.remainingSeconds);
  setPage('exam'); startTimers();
  if (!fsSupported) report('fullscreen_unavailable', 'This browser does not support full-screen mode.');
  else if (!document.fullscreenElement) needFullscreen(data.resumed ? 'Your exam was resumed. Return to full-screen mode to continue; the timer is still running.' : 'Full-screen mode is required for this exam.');
}

// ---------------------------------------------------------------- server-driven clock
const sync = sec => { endAt = performance.now() + Math.max(0, sec) * 1000; };
const remaining = () => Math.max(0, Math.ceil((endAt - performance.now()) / 1000));
function startTimers() {
  stopTimers();
  tickH = setInterval(() => {
    const el = $('#timerText'); if (el) el.textContent = fmtTime(remaining());
    if (exam && !submitting && remaining() <= 0) submitExam(true, 'time_limit');
  }, 500);
  hbH = setInterval(async () => {   // resync with the server clock and detect expiry
    if (!exam || submitting) return;
    try { const d = await api('student', 'heartbeat', { body: { attemptId: exam.attemptId } }); if (d.submitted) finishWithPayload(d); else { sync(d.remainingSeconds); violations = d.violations; } } catch (_) { }
  }, 20000);
}
function stopTimers() { clearInterval(tickH); clearInterval(hbH); tickH = hbH = null; }

// ---------------------------------------------------------------- exam screen
function renderExam() {
  if (!exam) { currentPage = 'student'; renderStudent(); return; }
  const qs = exam.questions, q = qs[cur], mark = answers[q.id];
  const answered = qs.filter(x => answers[x.id]).length;
  content.innerHTML = `<div class="exam-top"><div><b>${esc(exam.title)}</b><div class="muted" style="margin-top:5px">Question ${cur + 1} of ${qs.length}</div><div class="muted" style="margin-top:5px">${document.fullscreenElement ? '● Full-screen monitoring active' : fsSupported ? '⚠ Not in full screen' : '⚠ Full-screen unavailable — avoid leaving this page'} · Violations: <b>${violations}</b>/${exam.maxViolations} · <span id="saveState">${saveLabel}</span></div></div><div class="timer">◷ <span id="timerText">${fmtTime(remaining())}</span> remaining</div><button class="button danger" onclick="submitExam(false,'student_submit')">Submit test</button></div>
 <div class="notice" style="margin-bottom:16px">Exam integrity: leaving full-screen mode or switching tabs is recorded with a timestamp for examiner review${exam.maxViolations === 1 ? ' and automatically submits your answers' : `; after ${exam.maxViolations} violations your answers are submitted automatically`}. Refreshing the page does not reset the timer. Browser controls cannot prevent every form of misconduct.${exam.negativeMarking ? ` Negative marking applies: each wrong answer deducts ${exam.negativeFraction}× the question's marks.` : ''}</div>
 <div class="exam-layout"><section class="card exam-question"><div class="q-number">QUESTION ${cur + 1} <span style="float:right">${q.marks} mark${q.marks === 1 ? '' : 's'}</span></div><h2>${esc(q.question)}</h2>${q.options.map((o, j) => `<label class="answer-option ${mark === 'ABCD'[j] ? 'selected' : ''}"><input type="radio" name="answer" value="${'ABCD'[j]}" ${mark === 'ABCD'[j] ? 'checked' : ''}><span><b>${'ABCD'[j]}.</b> &nbsp;${esc(o)}</span></label>`).join('')}<div class="actions"><button class="button" ${cur === 0 ? 'disabled' : ''} onclick="moveQuestion(-1)">← Previous</button><button class="button primary" onclick="moveQuestion(1)">${cur === qs.length - 1 ? 'Review answers' : 'Next question →'}</button></div></section>
 <aside class="card"><div class="card-head"><h3>Question palette</h3><span class="muted">${answered}/${qs.length}</span></div><div class="palette">${qs.map((x, i) => `<button class="${i === cur ? 'current' : answers[x.id] ? 'answered' : ''}" onclick="jumpQuestion(${i})">${i + 1}</button>`).join('')}</div><div class="legend"><span><i style="background:#eaf1ff;border-color:#a8c2ff"></i> Current question</span><span><i></i> Answered</span><span><i style="background:white;border-color:#dfe5ee"></i> Not answered</span></div><div class="hint" style="margin-top:22px">Tip: Answers are saved automatically. You can move between questions and change answers before submitting.</div><button class="button" style="width:100%;margin-top:14px" onclick="reportExamIncident()">⚑ Report an incident</button></aside></div>`;
  content.querySelectorAll('input[name="answer"]').forEach(el => el.addEventListener('change', () => {
    answers[q.id] = el.value; saveAnswer(q.id, el.value); renderExam();
  }));
}
function moveQuestion(d) { if (!exam) return; cur = Math.max(0, Math.min(exam.questions.length - 1, cur + d)); renderExam(); }
function jumpQuestion(i) { cur = i; renderExam(); }

// ---------------------------------------------------------------- autosave (survives short network drops)
let saveLabel = 'All answers saved';
function setSaveState(s) {
  saveLabel = s === 'saving' ? 'Saving…' : s === 'offline' ? '⚠ Offline — retrying, answers are kept on this page' : 'All answers saved ✓';
  const el = $('#saveState'); if (el) el.textContent = saveLabel;
}
function saveAnswer(qid, label) { pending.set(String(qid), label); setSaveState('saving'); flush(); }
async function flush() {
  if (flushing || !exam) return;
  flushing = true;
  try {
    while (pending.size && exam && !submitting) {
      const [qid, label] = pending.entries().next().value;
      try {
        const d = await api('student', 'answer', { body: { attemptId: exam.attemptId, questionId: Number(qid), answer: label } });
        if (pending.get(qid) === label) pending.delete(qid);
        if (d.remainingSeconds != null) sync(d.remainingSeconds);
      } catch (e) {
        if (e.data && (e.data.expired || e.data.submitted)) { finishWithPayload(e.data); return; }
        if (e.network || e.status >= 500) { setSaveState('offline'); setTimeout(flush, 4000); return; }
        pending.delete(qid); toast(e.message);
      }
    }
    setSaveState('saved');
  } finally { flushing = false; }
}

// ---------------------------------------------------------------- incidents (frontend detection -> backend record)
const overlay = html => { hideOverlay(); const d = document.createElement('div'); d.id = 'examOverlay'; d.style.cssText = 'position:fixed;inset:0;z-index:999;background:rgba(15,23,42,.92);display:flex;align-items:center;justify-content:center;padding:24px'; d.innerHTML = `<div class="card" style="max-width:480px;text-align:center">${html}</div>`; document.body.appendChild(d); };
const hideOverlay = () => document.getElementById('examOverlay')?.remove();
function needFullscreen(msg) {
  overlay(`<div class="upload-icon" style="margin:0 auto 12px">⛶</div><h2 style="font:800 20px Manrope;margin:0 0 8px">Full-screen required</h2><p class="muted">${esc(msg)}</p><button class="button primary" id="fsBtn">Enter full-screen mode</button>`);
  $('#fsBtn').onclick = async () => { if (await requestFs()) { fsStarted = true; hideOverlay(); renderExam(); } else toast('Your browser blocked full-screen mode. Allow it and try again.'); };
}
async function report(type, detail) {
  if (!exam || submitting) return;
  await sendIncident({ type, detail, clientTime: new Date().toISOString() });
}
async function sendIncident(item) {
  const attemptId = exam?.attemptId; if (!attemptId) return;
  try {
    const d = await api('student', 'incident', { body: { attemptId, ...item } });
    if (d.submitted) { finishWithPayload(d); return; }
    if (d.remainingSeconds != null) sync(d.remainingSeconds);
    if (d.counted) { violations = d.violations; toast(`Violation ${d.violations} of ${d.maxViolations} recorded for examiner review.`); if (currentPage === 'exam') renderExam(); }
  } catch (e) {
    if (e.network || e.status >= 500) { incQueue.push(item); clearTimeout(incRetry); incRetry = setTimeout(async () => { const q = incQueue; incQueue = []; for (const i of q) await sendIncident(i); }, 4000); }
  }
}
document.addEventListener('fullscreenchange', () => {
  if (!exam || submitting) return;
  if (document.fullscreenElement) { fsStarted = true; hideOverlay(); if (currentPage === 'exam') renderExam(); return; }
  if (!fsStarted) return;   // never counted before the student has actually entered full screen once
  report('fullscreen_exit', 'Full-screen mode was exited during the exam.');
  if (exam && !submitting) needFullscreen('You left full-screen mode. This was recorded for your examiner. Return to full screen to keep working; the timer is still running.');
});
document.addEventListener('visibilitychange', () => {
  if (document.hidden && exam && !submitting && !dialogOpen) report('tab_switch', 'The exam tab or window was hidden (tab switch or minimised window).');
});
window.addEventListener('blur', () => {
  clearTimeout(blurT);
  blurT = setTimeout(() => {   // informational only: ignore quick focus flickers and anything the visibility check already covers
    if (exam && !submitting && !dialogOpen && !document.hidden && !document.hasFocus() && Date.now() - lastBlurReport > 30000) { lastBlurReport = Date.now(); report('window_blur', 'The exam window lost focus for more than 3 seconds.'); }
  }, 3000);
});
window.addEventListener('focus', () => clearTimeout(blurT));
window.addEventListener('beforeunload', e => { if (exam && !submitting) { e.preventDefault(); e.returnValue = ''; } });   // warn on accidental reload; reloading is NOT counted as cheating

function reportExamIncident() {
  if (!exam || submitting) return;
  dialogOpen++; const reason = prompt('Describe the incident or technical issue for the examiner (for example, accidental full-screen exit).'); dialogOpen--;
  if (reason === null) return;
  report('student_report', reason.trim() || 'Student reported an incident.'); toast('Incident report recorded for examiner review.');
}

// ---------------------------------------------------------------- submit
async function submitExam(auto = false, reason = 'student_submit') {
  if (!exam || submitting) return;
  if (!auto) { dialogOpen++; const ok = confirm('Submit your test now? You cannot change answers after submission.'); dialogOpen--; if (!ok) return; }
  submitting = true; stopTimers();
  const attemptId = exam.attemptId, snapshot = { ...answers };
  const attempt = async () => {
    try { finishWithPayload(await api('student', 'submit', { body: { attemptId, answers: snapshot, reason } })); }
    catch (e) {
      if (e.network || e.status >= 500) {
        if (auto) { toast('Connection problem — retrying submission…'); setTimeout(attempt, 3000); }
        else { submitting = false; startTimers(); toast('Submission failed: ' + e.message + ' Your answers are still on this screen; please try again.'); }
      } else { submitting = false; if (exam) startTimers(); toast(e.message); }
    }
  };
  await attempt();
}
function finishWithPayload(p) {
  if (!exam && currentPage === 'result') return;
  submitting = true; stopTimers(); exam = null; hideOverlay(); pending = new Map();
  if (document.fullscreenElement) document.exitFullscreen?.().catch(() => { });
  lastPayload = p; setPage('result'); renderResult(p);
}

// ---------------------------------------------------------------- result
function renderResult(p) {
  const why = { fullscreen_exit: 'Full-screen mode was exited. Your answers were auto-submitted and an incident was logged for examiner review.', tab_switch: 'You switched away from the exam. Your answers were auto-submitted and an incident was logged for examiner review.', violation_limit: 'The violation limit was reached. Your answers were auto-submitted.', time_limit: 'Time ran out, so your answers were submitted automatically.' }[p.reason];
  if (p.hidden) {
    content.innerHTML = `<div class="card result-box"><div class="upload-icon" style="background:#e8f8ef;color:#17845b;font-size:25px">✓</div><h2 style="font:800 22px Manrope;margin:0">Test submitted successfully</h2><p class="muted">${esc(why || p.message)}</p><p class="muted">${esc(p.message)}</p><button class="button" onclick="setPage('student')">Back to tests</button></div>`; return;
  }
  const r = p.result;
  content.innerHTML = `<div class="card result-box"><div class="upload-icon" style="background:#e8f8ef;color:#17845b;font-size:25px">✓</div><h2 style="font:800 22px Manrope;margin:0">Test submitted successfully</h2><p class="muted">${esc(why || 'Your answers have been saved and scored.')}</p><div class="result-score">${r.score} / ${r.totalMarks}</div><div class="result-sub">${r.percentage}% score · <b>${esc(r.status)}</b></div><div class="result-grid"><div><strong>${r.correct ?? '—'}</strong><small>Correct</small></div><div><strong>${r.incorrect ?? '—'}</strong><small>Incorrect</small></div><div><strong>${r.unattempted ?? '—'}</strong><small>Unattempted</small></div></div><button class="button primary" onclick="showReview()">Review answers</button> <button class="button" onclick="setPage('student')">Back to tests</button></div><div id="reviewArea"></div>`;
}
function showReview() {
  const area = $('#reviewArea'); if (!area || !lastPayload?.review) return;
  area.innerHTML = `<div class="card"><div class="card-head"><h3>Answer review</h3><span class="pill blue">Score details</span></div>${lastPayload.review.map((q, i) => `<div class="question-preview"><div class="preview-header"><b>Q${i + 1}. ${esc(q.question)}</b><span class="pill ${q.correct ? '' : 'draft'}">${q.correct ? 'Correct' : q.chosen ? 'Incorrect' : 'Skipped'} · ${q.awarded}/${q.marks}</span></div><p class="muted">Your answer: <b>${esc(q.chosen || 'Not answered')}</b> · Correct answer: <b>${esc(q.correctAnswer)}</b></p></div>`).join('')}</div>`;
}

// ---------------------------------------------------------------- boot: restore the session after a refresh
(async () => {
  try { user = await api('student', 'me'); setPage('student'); }
  catch (_) { renderAuth('signin'); }
})();
