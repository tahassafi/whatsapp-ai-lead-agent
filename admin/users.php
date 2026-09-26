<?php
/**
 * User management - admins only.
 * Add/remove users, change roles, and RESET someone's 2FA when they lose their
 * phone (which is the recovery path, instead of emailing codes around).
 */
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/audit.php';

$me = require_role(['admin']);          // only a full admin may manage users

$notice = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'reset_2fa' && $id) {
        // Next time they log in they'll be walked through setup again.
        db_run("UPDATE admin_users SET totp_secret = NULL, totp_enabled = 0, totp_last_slot = NULL, backup_codes = NULL WHERE id = ?", [$id]);
        log_activity('2fa_reset', 'user', $id, db_one("SELECT email FROM admin_users WHERE id = ?", [$id])['email'] ?? null);
        $notice = 'Two-factor reset. That user will set up a new authenticator on their next login.';

    } elseif ($action === 'set_role' && $id) {
        $role = $_POST['role'] ?? 'sales';
        if (!in_array($role, ['admin', 'sales'], true)) {
            $error = 'Unknown role.';
        } elseif ($id === (int) $me['id'] && $role !== 'admin') {
            $error = 'You cannot remove your own admin role.';   // don't lock yourself out
        } else {
            $was = db_one("SELECT email, role FROM admin_users WHERE id = ?", [$id]);
            db_run("UPDATE admin_users SET role = ? WHERE id = ?", [$role, $id]);
            log_activity('updated', 'user', $id, $was['email'] ?? null, ['role' => $was['role'] ?? null], ['role' => $role]);
            $notice = 'Role updated.';
        }

    } elseif ($action === 'delete' && $id) {
        if ($id === (int) $me['id']) {
            $error = 'You cannot delete your own account.';
        } elseif ((int) db_one("SELECT COUNT(*) c FROM admin_users WHERE role = 'admin'")['c'] <= 1
                  && db_one("SELECT role FROM admin_users WHERE id = ?", [$id])['role'] === 'admin') {
            $error = 'That is the last admin account - deleting it would lock everyone out.';
        } else {
            $was = db_one("SELECT email FROM admin_users WHERE id = ?", [$id]);
            db_run("DELETE FROM admin_users WHERE id = ?", [$id]);
            log_activity('deleted', 'user', $id, $was['email'] ?? null);
            $notice = 'User deleted.';
        }

    } elseif ($action === 'add') {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $name  = trim($_POST['name'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $role  = $_POST['role'] ?? 'sales';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address.';
        } elseif (strlen($pass) < 10) {
            $error = 'Password must be at least 10 characters.';
        } elseif (db_one("SELECT id FROM admin_users WHERE email = ?", [$email])) {
            $error = 'That email already has an account.';
        } elseif (!in_array($role, ['admin', 'sales'], true)) {
            $error = 'Unknown role.';
        } else {
            db_run("INSERT INTO admin_users (email, password_hash, name, role) VALUES (?, ?, ?, ?)",
                   [$email, password_hash($pass, PASSWORD_DEFAULT), $name ?: 'Admin', $role]);
            // The password is never logged - see AUDIT_NEVER in includes/audit.php.
            log_activity('created', 'user', (int) db_last_insert_id(), $email, null, ['email' => $email, 'name' => $name, 'role' => $role]);
            $notice = 'User created.';
        }
    }
}

$users = db_all("SELECT * FROM admin_users ORDER BY role, email");

$admin_page_title = 'Users & Security';
require __DIR__ . '/_header.php';
?>

<style>
  .admin-table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; font-size: 14px; }
  .admin-table th, .admin-table td { text-align: left; padding: 11px 14px; border-bottom: 1px solid #e8eaef; }
  .admin-table th { background: #fafbfc; font-size: 11.5px; text-transform: uppercase; color: #7c8494; }
  .muted { color: #7c8494; }
  .badge-ok { background: #e8f6ee; color: #17693a; border-radius: 999px; padding: 3px 10px; font-size: 11.5px; font-weight: 700; }
  .badge-warn { background: #fdf3e0; color: #8a5f13; border-radius: 999px; padding: 3px 10px; font-size: 11.5px; font-weight: 700; }
  .link-danger { background: none; border: none; color: #d92d20; cursor: pointer; font: inherit; padding: 0; }
  .admin-form .form-grid { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 12px; }
  .admin-form .form-row { flex: 1; min-width: 220px; }
  .admin-form label { display: block; font-size: 12.5px; font-weight: 700; color: #7c8494; margin-bottom: 5px; }
  .admin-form input, .admin-form select { width: 100%; border: 1px solid #e8eaef; border-radius: 9px; padding: 9px 12px; font-size: 14px; }
  .btn.btn-gold { background: linear-gradient(135deg, #c9a35b, #a8834a); border: none; color: #fff; border-radius: 999px; padding: 10px 20px; font-weight: 700; cursor: pointer; }
  .field-hint { color: #98a2b3; font-size: 12px; }
  .admin-notice.ok { background: #e8f6ee; color: #17693a; border-radius: 10px; padding: 10px 15px; }
  .admin-notice.bad { background: #fdeeec; color: #a03225; border-radius: 10px; padding: 10px 15px; }
  .text-right { text-align: right; }
</style>

<div class="admin-page-head"><h1>Users &amp; Security</h1></div>

<?php if ($notice): ?><p class="admin-notice ok"><?= e($notice) ?></p><?php endif; ?>
<?php if ($error):  ?><p class="admin-notice bad"><?= e($error) ?></p><?php endif; ?>

<table class="admin-table">
  <thead><tr><th>Email</th><th>Name</th><th>Role</th><th>2FA</th><th>Last login</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['email']) ?><?= (int)$u['id'] === (int)$me['id'] ? ' <span class="muted">(you)</span>' : '' ?></td>
        <td><?= e($u['name']) ?></td>
        <td>
          <form method="post" class="inline-form">
            <input type="hidden" name="action" value="set_role">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <select name="role" onchange="this.form.submit()">
              <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
              <option value="sales" <?= $u['role'] === 'sales' ? 'selected' : '' ?>>Sales</option>
            </select>
          </form>
        </td>
        <td>
          <?php if ((int) ($u['totp_enabled'] ?? 0) === 1): ?>
            <span class="badge-ok">Enabled</span>
          <?php else: ?>
            <span class="badge-warn">Not set up</span>
          <?php endif; ?>
        </td>
        <td class="muted"><?= $u['last_login_at'] ? e(date('j M Y, g:ia', strtotime($u['last_login_at']))) : 'never' ?></td>
        <td class="text-right">
          <form method="post" class="inline-form" onsubmit="return confirm('Reset two-factor for this user? They will re-enrol on their next login.');">
            <input type="hidden" name="action" value="reset_2fa">
            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button type="submit" class="link-danger">Reset 2FA</button>
          </form>
          <?php if ((int) $u['id'] !== (int) $me['id']): ?>
            &nbsp;
            <form method="post" class="inline-form" onsubmit="return confirm('Delete this user permanently?');">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button type="submit" class="link-danger">Delete</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<h2 style="margin-top:2.5rem;font-size:1.05rem;">Add a user</h2>
<form method="post" class="admin-form" style="max-width:640px;">
  <input type="hidden" name="action" value="add">
  <div class="form-grid">
    <div class="form-row"><label>Email *</label><input type="email" name="email" required></div>
    <div class="form-row"><label>Name</label><input type="text" name="name"></div>
  </div>
  <div class="form-grid">
    <div class="form-row">
      <label>Password * <span class="field-hint" style="display:inline;">(min 10 characters)</span></label>
      <input type="password" name="password" required minlength="10">
    </div>
    <div class="form-row">
      <label>Role</label>
      <select name="role">
        <option value="sales">Sales</option>
        <option value="admin">Admin</option>
      </select>
    </div>
  </div>
  <button type="submit" class="btn btn-gold">Create user</button>
</form>

<?php require __DIR__ . '/_footer.php'; ?>
