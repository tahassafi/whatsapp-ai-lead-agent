<?php
// Included by every admin page after require_role() has run.
// NOTE: in the original project this header belongs to a larger site admin
// (cars, brands, blog...). This standalone version keeps only the sections
// that ship with this repo.
$admin = current_admin();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($admin_page_title ?? 'Admin') ?> | <?= e(SITE_NAME) ?> Admin</title>
<meta name="robots" content="noindex, nofollow">
<?php foreach ($admin_extra_css ?? [] as $cssPath): ?>
<link rel="stylesheet" href="<?= e(asset_v($cssPath)) ?>">
<?php endforeach; ?>
<style>
  /* minimal standalone admin chrome (the full project uses its own admin.css) */
  * { box-sizing: border-box; }
  body.admin-body { margin: 0; font-family: 'Segoe UI', system-ui, sans-serif; background: #f5f6f8; }
  .admin-layout { display: flex; min-height: 100vh; }
  .admin-sidebar { flex: 0 0 210px; background: #16161a; color: #f2efe9; padding: 20px 0; display: flex; flex-direction: column; }
  .admin-sidebar .logo { padding: 0 20px 18px; font-weight: 800; letter-spacing: .04em; }
  .admin-sidebar .logo a { color: #c9a35b; text-decoration: none; font-size: 15px; text-transform: uppercase; }
  .admin-sidebar nav { display: flex; flex-direction: column; }
  .admin-sidebar nav a { color: #cfcbc2; text-decoration: none; padding: 10px 20px; font-size: 14px; }
  .admin-sidebar nav a:hover { background: rgba(255,255,255,.06); color: #fff; }
  .admin-logout { margin-top: auto; padding: 14px 20px 0; }
  .admin-logout button { background: none; border: 1px solid #3a3a42; color: #9b9890; border-radius: 8px; padding: 8px 12px; font-size: 12px; cursor: pointer; width: 100%; }
  .admin-content { flex: 1; padding: 26px 30px; min-width: 0; }
  .inline-form { display: inline; }
  @media (max-width: 760px) {
    .admin-layout { flex-direction: column; }
    .admin-sidebar { flex: none; flex-direction: row; flex-wrap: wrap; align-items: center; padding: 10px; }
    .admin-sidebar nav { flex-direction: row; flex-wrap: wrap; }
    .admin-logout { margin: 0; padding: 0 10px; }
    .admin-content { padding: 16px 12px; }
  }
</style>
</head>
<body class="admin-body">
<div class="admin-layout">
  <aside class="admin-sidebar">
    <div class="logo"><a href="/admin/chat-leads/"><?= e(SITE_NAME) ?></a></div>
    <nav>
      <a href="/admin/chat-leads/">Chat Leads</a>
      <?php if (($admin['role'] ?? '') === 'admin'): ?>
        <a href="/admin/chat-leads/knowledge.php">Chatbot Knowledge</a>
        <a href="/admin/users.php">Users &amp; Security</a>
      <?php endif; ?>
      <a href="/">View Site</a>
    </nav>
    <form method="post" action="/admin/logout.php" class="admin-logout">
      <button type="submit">Sign out (<?= e($admin['email']) ?>)</button>
    </form>
  </aside>
  <div class="admin-content">
