<?php
declare(strict_types=1);
require __DIR__ . '/../../3_backend_auth_users/includes/bootstrap.php';
require_once __DIR__ . '/../../3_backend_auth_users/includes/layout.php';
$me = require_page_role('examiner', 'examiner/');
$initials = strtoupper(implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(preg_split('/\s+/', trim($me['name'])) ?: ['E'], 0, 2))));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ExamPro — Examiner workspace</title>
  <?= page_boot(['role' => 'examiner', 'loginUrl' => url('examiner/'), 'studentUrl' => url('student/'),
                 'sampleCsv' => url('samples/sample-questions.csv'),
                 'me' => ['id' => $me['id'], 'fullName' => $me['name'], 'email' => $me['email']]]) ?>
  <link rel="stylesheet" href="<?= asset('css/styles.css') ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand"><span class="brand-icon">E</span><span>ExamPro</span></div>
    <div class="role-switch"><span class="role-dot"></span><span id="roleLabel">Examiner workspace</span></div>
    <nav id="nav">
      <button class="nav-item active" data-page="dashboard">▦ <span>Dashboard</span></button>
      <button class="nav-item" data-page="create">＋ <span>Create test</span></button>
      <button class="nav-item" data-page="tests">▤ <span>Test library</span></button>
      <button class="nav-item" data-page="students">♙ <span>Students &amp; assignments</span></button>
      <button class="nav-item" data-page="results">▥ <span>Results</span></button>
    </nav>
    <div class="sidebar-bottom"><div class="avatar"><?= e($initials) ?></div><div><b><?= e($me['name']) ?></b><small>Examiner</small></div><button class="dots" id="signOut" title="Sign out" style="background:none;border:0;cursor:pointer;font:inherit;font-size:11px">Sign out</button></div>
  </aside>
  <main class="main">
    <header class="topbar"><div><span class="eyebrow">EXAM MANAGEMENT</span><h1 id="pageTitle">Dashboard</h1></div><div class="top-actions"><span class="secure">● Signed in</span><a class="button" id="studentPortalLink" href="<?= e(url('student/')) ?>">↗ Student portal</a><button class="button primary" id="quickCreate">＋ Create new test</button></div></header>
    <div id="content" class="content"></div>
    <footer>ExamPro <span>•</span> Excel-powered assessments <span>•</span> PHP &amp; MySQL</footer>
  </main>
</div>
<div id="toast" class="toast" role="status"></div>
<link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
<script src="<?= asset('js/common.js') ?>"></script>
<script src="<?= asset('js/examiner.js') ?>"></script>
</body></html>
