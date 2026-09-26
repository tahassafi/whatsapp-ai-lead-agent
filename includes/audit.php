<?php
/**
 * Activity log. Every admin action lands in activity_log so there is a
 * paper trail of who changed what.
 */

/** Field names whose values must never be written to the log. */
const AUDIT_NEVER = ['password', 'password_hash', 'totp_secret', 'backup_codes'];

function audit_scrub(?array $data): ?array
{
    if ($data === null) { return null; }
    foreach (AUDIT_NEVER as $k) {
        if (array_key_exists($k, $data)) { $data[$k] = '[redacted]'; }
    }
    return $data;
}

/**
 * @param string      $action  created / updated / deleted / 2fa_reset ...
 * @param string      $type    user / lead / knowledge ...
 * @param int         $itemId  primary key of the touched record
 * @param string|null $label   human label (email, lead name...)
 * @param array|null  $before  old values (optional)
 * @param array|null  $after   new values (optional)
 */
function log_activity(string $action, string $type, int $itemId, ?string $label = null,
                      ?array $before = null, ?array $after = null): void
{
    try {
        $admin = function_exists('current_admin') ? current_admin() : null;
        db_run(
            "INSERT INTO activity_log (admin_id, admin_email, action, item_type, item_id, item_label, before_json, after_json)
             VALUES (?,?,?,?,?,?,?,?)",
            [
                $admin['id'] ?? null,
                $admin['email'] ?? null,
                $action,
                $type,
                $itemId,
                $label,
                $before !== null ? json_encode(audit_scrub($before), JSON_UNESCAPED_UNICODE) : null,
                $after  !== null ? json_encode(audit_scrub($after),  JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    } catch (Throwable $e) {
        error_log('log_activity failed: ' . $e->getMessage());   // never break the action itself
    }
}
