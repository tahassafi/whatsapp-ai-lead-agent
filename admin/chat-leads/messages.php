<?php
/* Live-chat polling endpoint for the lead conversation view.
   Returns messages newer than ?after= for ?conv= as JSON. */
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_role(['admin', 'sales']);

header('Content-Type: application/json');

$convId = (int)($_GET['conv'] ?? 0);
$after  = (int)($_GET['after'] ?? 0);

$rows = db_all(
    "SELECT id, role, content, created_at FROM chat_messages
      WHERE conversation_id = ? AND id > ? ORDER BY id ASC LIMIT 100",
    [$convId, $after]
);

$messages = array_map(fn($r) => [
    'id'      => (int)$r['id'],
    'role'    => $r['role'],
    'content' => $r['content'],
    'time'    => date('g:i A', strtotime($r['created_at'])),
], $rows);

echo json_encode([
    'last'     => $messages ? end($messages)['id'] : $after,
    'messages' => $messages,
], JSON_UNESCAPED_UNICODE);
