<?php
/**
 * Admin session auth (standalone version).
 *
 * The production deployment this was extracted from adds TOTP two-factor on
 * top of this (the admin_users table already carries the columns for it);
 * this repo ships the password + session core so the CRM runs end to end.
 *
 * Sessions use a private save path so shared-host global session GC (which
 * can be as low as ~24 minutes on cPanel servers) never logs admins out.
 */

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }

    $dir = ROOT_PATH . '/includes/.sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        @file_put_contents($dir . '/index.html', '');
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
        /* our own GC window: 7 days */
        ini_set('session.gc_maxlifetime', (string)(7 * 86400));
    }
    session_set_cookie_params([
        'lifetime' => 7 * 86400,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('crm_session');
    session_start();
}

/** The signed-in admin row, or null. */
function current_admin(): ?array
{
    static $admin = false;
    if ($admin !== false) { return $admin; }
    auth_boot();
    $id = (int)($_SESSION['admin_id'] ?? 0);
    $admin = $id > 0 ? db_one("SELECT * FROM admin_users WHERE id = ?", [$id]) : null;
    return $admin;
}

/** Require a signed-in admin with one of the given roles. Returns the row. */
function require_role(array $roles): array
{
    $admin = current_admin();
    if (!$admin) {
        header('Location: /admin/login.php');
        exit;
    }
    if (!in_array($admin['role'], $roles, true)) {
        http_response_code(403);
        exit('Forbidden - your role does not have access to this page.');
    }
    return $admin;
}

/** Verify credentials and open the session. Returns true on success. */
function auth_login(string $email, string $password): bool
{
    auth_boot();
    $u = db_one("SELECT * FROM admin_users WHERE email = ?", [strtolower(trim($email))]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int)$u['id'];
    db_run("UPDATE admin_users SET last_login_at = NOW() WHERE id = ?", [(int)$u['id']]);
    return true;
}

function auth_logout(): void
{
    auth_boot();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
