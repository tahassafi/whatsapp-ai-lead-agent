<?php
/* Admin sign-in. */
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

auth_boot();
if (current_admin()) {
    header('Location: /admin/chat-leads/');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    /* small brute-force brake */
    $_SESSION['login_tries'] = (int)($_SESSION['login_tries'] ?? 0) + 1;
    if ($_SESSION['login_tries'] > 8) {
        $error = 'Too many attempts - wait a few minutes and try again.';
        sleep(2);
    } elseif (auth_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''))) {
        unset($_SESSION['login_tries']);
        header('Location: /admin/chat-leads/');
        exit;
    } else {
        $error = 'Wrong email or password.';
        sleep(1);
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | <?= e(SITE_NAME) ?> Admin</title>
<meta name="robots" content="noindex, nofollow">
<style>
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
         background: #16161a; font-family: 'Segoe UI', system-ui, sans-serif; }
  .card { background: #1f1f25; border: 1px solid rgba(201,163,91,.25); border-radius: 16px;
          padding: 34px 32px; width: 340px; color: #f2efe9; }
  h1 { margin: 0 0 4px; font-size: 19px; }
  p.sub { margin: 0 0 22px; color: #9b9890; font-size: 13px; }
  label { display: block; font-size: 12.5px; font-weight: 700; color: #9b9890; margin: 14px 0 6px; }
  input { width: 100%; box-sizing: border-box; background: #16161a; border: 1px solid #2a2a32;
          color: #f2efe9; border-radius: 9px; padding: 11px 13px; font-size: 14px; outline: none; }
  input:focus { border-color: #c9a35b; }
  button { margin-top: 20px; width: 100%; border: none; cursor: pointer; border-radius: 999px;
           padding: 12px; font-weight: 700; font-size: 14px; color: #171310;
           background: linear-gradient(135deg, #c9a35b, #a8834a); }
  .err { background: rgba(224,85,69,.12); border: 1px solid rgba(224,85,69,.4); color: #f2b3ab;
         border-radius: 9px; padding: 9px 13px; font-size: 13px; margin-bottom: 6px; }
</style>
</head>
<body>
  <form method="post" class="card">
    <h1><?= e(SITE_NAME) ?></h1>
    <p class="sub">Lead CRM &middot; sign in</p>
    <?php if ($error !== ''): ?><div class="err"><?= e($error) ?></div><?php endif; ?>
    <label>Email</label>
    <input type="email" name="email" required autofocus autocomplete="username">
    <label>Password</label>
    <input type="password" name="password" required autocomplete="current-password">
    <button type="submit">Sign in</button>
  </form>
</body>
</html>
