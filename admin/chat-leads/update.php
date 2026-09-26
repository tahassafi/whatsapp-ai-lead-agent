<?php
/* Chat lead status change / delete */
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_role(['admin', 'sales']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin/chat-leads/');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

if (!empty($_POST['delete'])) {
    db_run("DELETE FROM chat_leads WHERE id = ?", [$id]);
} else {
    $status = (string)($_POST['status'] ?? '');
    if (in_array($status, ['new', 'contacted', 'qualified', 'closed', 'junk'], true)) {
        db_run("UPDATE chat_leads SET status = ? WHERE id = ?", [$status, $id]);
    }
}

header('Location: /admin/chat-leads/');
exit;
