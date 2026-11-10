<?php
declare(strict_types=1);
require __DIR__ . '/../3_backend_auth_users/includes/bootstrap.php';
// Visiting the home page ends all portal sessions, so returning to a portal requires signing in again.
auth_logout_all();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ExamPro | Online examinations from spreadsheet to scorecard</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:wght@600;800&family=Hanken+Grotesk:wght@400;500;700&display=swap" rel="stylesheet">
<style>
:root{--ink:#0e1a33;--blue:#2455e6;--paper:#f4f6fa;--line:#dde3ee;--mute:#5d6b85;--amber:#d98a0b}
*{box-sizing:border-box}body{margin:0;background:var(--paper);color:var(--ink);font:16px/1.55 'Hanken Grotesk',system-ui,sans-serif}
a{color:inherit}:focus-visible{outline:3px solid var(--blue);outline-offset:3px}
h1,h2,h3,.brand{font-family:'Bricolage Grotesque',system-ui,sans-serif;letter-spacing:-.02em}
.wrap{max-width:1120px;margin:0 auto;padding:0 24px}
nav{display:flex;align-items:center;justify-content:space-between;padding:22px 0}
.brand{display:flex;gap:10px;align-items:center;font-weight:800;font-size:22px;text-decoration:none}
.mark{width:34px;height:34px;border-radius:50%;border:3px solid var(--ink);display:grid;place-items:center}.mark i{width:14px;height:14px;background:var(--ink);border-radius:50%}
nav .links{display:flex;gap:22px;font-weight:500;font-size:15px}nav .links a{text-decoration:none;color:var(--mute)}nav .links a:hover{color:var(--ink)}
.hero{display:grid;grid-template-columns:1.05fr .95fr;gap:56px;align-items:center;padding:56px 0 72px}
h1{font-size:clamp(38px,5.4vw,64px);line-height:1.02;margin:0 0 20px;font-weight:800}
.lead{font-size:18px;color:var(--mute);max-width:46ch;margin:0 0 28px}
.btn{display:inline-block;padding:13px 22px;border-radius:10px;font-weight:700;text-decoration:none;background:var(--blue);color:#fff}
.btn.alt{background:transparent;color:var(--ink);border:1.5px solid var(--ink);margin-left:10px}
.sheet{background:#fff;border:1.5px solid var(--ink);border-radius:16px;padding:26px;box-shadow:8px 8px 0 var(--ink)}
.sheet-top{display:flex;justify-content:space-between;font-size:13px;color:var(--mute);margin-bottom:18px;font-weight:500}
.timer{font-variant-numeric:tabular-nums;color:var(--amber);font-weight:700}
.sheet h3{font-size:22px;margin:0 0 18px;line-height:1.25}
.opt{display:flex;align-items:center;gap:14px;width:100%;text-align:left;font:inherit;background:none;border:1px solid var(--line);border-radius:10px;padding:11px 14px;margin-bottom:10px;cursor:pointer}
.opt:hover{border-color:var(--ink)}
.bub{flex:none;width:30px;height:30px;border:2px solid var(--ink);border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;transition:.15s}
.opt[aria-pressed=true]{border-color:var(--blue);background:#eef2fe}.opt[aria-pressed=true] .bub{background:var(--ink);color:#fff}
.pal{display:flex;gap:7px;margin-top:16px}.pal span{width:22px;height:22px;border-radius:50%;border:1.5px solid var(--line)}.pal .on{background:var(--ink);border-color:var(--ink)}.pal .cur{border-color:var(--blue);border-width:3px}
.portals{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;padding-bottom:64px}
.portal{background:#fff;border:1px solid var(--line);border-radius:14px;padding:26px;text-decoration:none;display:flex;flex-direction:column;gap:10px}
.portal:hover{border-color:var(--ink)}.portal .bub{margin-bottom:6px}.portal h2{font-size:22px;margin:0}.portal p{margin:0;color:var(--mute);font-size:15px;flex:1}.portal b{color:var(--blue)}
.flow{background:var(--ink);color:#fff;border-radius:18px;padding:36px;margin-bottom:56px}
.flow h2{margin:0 0 22px;font-size:26px}.flow ol{display:grid;grid-template-columns:repeat(3,1fr);gap:24px;margin:0;padding:0;list-style:none;counter-reset:s}
.flow li{counter-increment:s;color:#c4cde0;font-size:15px}.flow li:before{content:counter(s);display:grid;place-items:center;width:30px;height:30px;border:2px solid #fff;border-radius:50%;color:#fff;font-weight:700;margin-bottom:10px}.flow li b{display:block;color:#fff;font-size:17px;margin-bottom:4px}
footer{padding:0 0 36px;color:var(--mute);font-size:13px}
@media(max-width:860px){.hero{grid-template-columns:1fr;gap:36px;padding-top:24px}.portals,.flow ol{grid-template-columns:1fr}nav .links{display:none}.btn.alt{margin:10px 0 0}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
</style></head><body><div class="wrap">
<nav><a class="brand" href="<?= e(url('/')) ?>"><span class="mark"><i></i></span>ExamPro</a><div class="links"><a href="#portals">Sign in</a><a href="#how">How it works</a></div></nav>
<header class="hero"><div><h1>Online exams, from spreadsheet to scorecard.</h1><p class="lead">Upload your question bank as Excel or CSV, assign the test to verified students, and get graded results with integrity records the moment they submit.</p><a class="btn" href="#portals">Choose your portal</a><a class="btn alt" href="<?= e(url('samples/sample-questions.csv')) ?>" download>Download sample sheet</a></div>
<div class="sheet" aria-label="Sample question you can try"><div class="sheet-top"><span>Question 2 of 5</span><span class="timer" id="t">29:59</span></div><h3>Which language is used to structure web pages?</h3>
<div id="opts"></div><div class="pal" aria-hidden="true"><span class="on"></span><span class="on cur"></span><span></span><span></span><span></span></div></div></header>
<section class="portals" id="portals"><a class="portal" href="<?= e(url('admin/')) ?>"><span class="bub">A</span><h2>Administrator</h2><p>Create examiner accounts, verify students and oversee examinations and results.</p><b>Sign in as administrator</b></a>
<a class="portal" href="<?= e(url('examiner/')) ?>"><span class="bub">B</span><h2>Examiner</h2><p>Upload or write questions, publish tests and assign exams to verified students.</p><b>Sign in as examiner</b></a>
<a class="portal" href="<?= e(url('student/')) ?>"><span class="bub">C</span><h2>Student</h2><p>Register your profile, take assigned tests and see your score right away.</p><b>Sign in or register</b></a></section>
<section class="flow" id="how"><h2>How a test runs</h2><ol><li><b>Administrator adds examiners</b>Only accounts created by the administrator can publish tests.</li><li><b>Students are verified, then assigned</b>The administrator approves students; examiners give them specific tests.</li><li><b>Student attempts once</b>A server-side timer applies, and leaving full screen submits automatically.</li></ol></section>
<footer>ExamPro online examination system</footer></div>
<script>
const o=['Python','Java','HTML','C++'],box=document.getElementById('opts');
o.forEach((t,i)=>{const b=document.createElement('button');b.className='opt';b.setAttribute('aria-pressed','false');b.innerHTML='<span class="bub">'+'ABCD'[i]+'</span>'+t;b.onclick=()=>{[...box.children].forEach(x=>x.setAttribute('aria-pressed','false'));b.setAttribute('aria-pressed','true')};box.appendChild(b)});
let s=1799;setInterval(()=>{s=s>0?s-1:1799;document.getElementById('t').textContent=String(Math.floor(s/60)).padStart(2,'0')+':'+String(s%60).padStart(2,'0')},1000);
</script></body></html>
