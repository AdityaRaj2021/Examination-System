<?php
declare(strict_types=1);
require __DIR__ . '/../../3_backend_auth_users/includes/bootstrap.php';
require_once __DIR__ . '/../../3_backend_auth_users/includes/layout.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>ExamPro — Student portal</title>
  <?= page_boot(['role' => 'student', 'loginUrl' => url('student/'), 'examinerUrl' => url('examiner/')]) ?>
  <link rel="stylesheet" href="<?= asset('css/styles.css') ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand"><span class="brand-icon">E</span><span>ExamPro</span></div>
    <div class="role-switch"><span class="role-dot"></span><span id="roleLabel">Student portal</span></div>
    <nav id="nav">
      <button class="nav-item active" data-page="student">▷ <span>Student portal</span></button>
    </nav>
    <div class="sidebar-bottom"><div class="avatar" id="sideAvatar">ST</div><div><b id="sideName">Student</b><small>Student portal</small></div><span class="dots">•••</span></div>
  </aside>
  <main class="main">
    <header class="topbar"><div><span class="eyebrow">EXAM MANAGEMENT</span><h1 id="pageTitle">Student sign in</h1></div><div class="top-actions"><span class="secure">● Secure session</span></div></header>
    <div id="content" class="content"></div>
    <footer>ExamPro <span>•</span> Excel-powered assessments <span>•</span> PHP &amp; MySQL</footer>
  </main>
</div>
<div id="toast" class="toast" role="status"></div>
<link rel="stylesheet" href="<?= asset('css/theme.css') ?>">
<script src="<?= asset('js/common.js') ?>"></script>
<script src="<?= asset('js/ux.js') ?>" defer></script>
<script src="<?= asset('js/student.js') ?>"></script>
</body></html>
