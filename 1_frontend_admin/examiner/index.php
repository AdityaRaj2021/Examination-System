<?php
declare(strict_types=1);
require __DIR__ . '/../../3_backend_auth_users/includes/bootstrap.php';
require_once __DIR__ . '/../../3_backend_auth_users/includes/layout.php';
if (auth_current('examiner')) { header('Location: ' . url('examiner/workspace.php')); exit; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Examiner Sign In | ExamPro</title>
<?= page_boot(['workspace' => url('examiner/workspace.php')]) ?>
<link rel="stylesheet" href="<?= asset('css/styles.css') ?>"></head><body class="portal-login"><a class="back-home" href="<?= e(url('/')) ?>">← Back to home</a><main class="login-card"><a class="home-brand" href="<?= e(url('/')) ?>"><span class="brand-icon">E</span> ExamPro</a><div class="login-icon">▤</div><div class="portal-label">EXAMINER PORTAL</div><h1>Sign in to your account</h1><p class="muted">Examiner accounts are created by the administrator. Sign in to manage assessments and students.</p><div id="message"></div><form id="loginForm"><div class="field"><label>Email address</label><input name="email" type="email" required autocomplete="username"></div><div class="field"><label>Password</label><input name="password" type="password" required autocomplete="current-password"></div><button class="button primary login-submit">Sign in →</button></form><div class="login-note">Don't have an account? Contact your administrator.</div></main><link rel="stylesheet" href="<?= asset('css/theme.css') ?>"><script src="<?= asset('js/common.js') ?>"></script><script>
document.querySelector('#loginForm').addEventListener('submit',async e=>{e.preventDefault();const box=document.querySelector('#message');
try{const d=await api('auth','examiner_login',{body:Object.fromEntries(new FormData(e.target))});setCsrf(d.csrf);location.href=EXAMPRO.workspace;}
catch(err){box.innerHTML='<div class="notice error">'+esc(err.message)+'</div>';}});
</script></body></html>
