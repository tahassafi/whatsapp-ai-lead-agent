<?php
/* Chatbot knowledge editor
 * Edits data/chatbot-knowledge.txt - the curated info (bank finance, leasing,
 * policies...) the AI uses to answer customer questions. Plain text, any
 * format - the AI reads it as-is. */
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_role(['admin']);   // only full admins edit what the chatbot is taught

$kbFile = ROOT_PATH . '/data/chatbot-knowledge.txt';
$saved  = false;
$error  = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $content = (string)($_POST['knowledge'] ?? '');
    if (mb_strlen($content) > 60000) {
        $error = 'Too long - keep it under 60,000 characters.';
    } else {
        if (!is_dir(dirname($kbFile))) { @mkdir(dirname($kbFile), 0755, true); }
        if (@file_put_contents($kbFile, $content) === false) {
            $error = 'Could not write the file - check that the data/ folder is writable.';
        } else {
            $saved = true;
        }
    }
}

$current = is_file($kbFile) ? (string)file_get_contents($kbFile) : '';

$admin_page_title = 'Chatbot Knowledge';
$admin_extra_css  = ['/assets/css/crm.css'];
require __DIR__ . '/../_header.php';
?>

<div class="crm crm-kb">

  <div class="crm-head">
    <h1>Chatbot Knowledge Base</h1>
    <span class="crm-head-actions">
      <a href="/admin/chat-leads/" class="crm-btn">&larr; All leads</a>
    </span>
  </div>

  <?php if ($saved): ?>
    <div class="crm-note-ok">Saved. The chatbot is already using the new version.</div>
  <?php elseif ($error !== ''): ?>
    <div class="crm-note-err"><?= e($error) ?></div>
  <?php endif; ?>

  <div class="crm-card crm-panel" style="max-width:960px;">
    <p class="crm-muted" style="margin:0 0 14px;white-space:normal;">
      Whatever you write here, the chatbot knows and uses to answer customers — bank finance
      requirements, leasing, warranties, delivery, opening hours, policies, anything.
      Plain text in any format. Changes apply to the very next message, no restart needed.
    </p>
    <form method="post">
      <textarea name="knowledge" rows="26"><?= e($current) ?></textarea>
      <p style="display:flex;align-items:center;gap:14px;">
        <button type="submit" class="crm-btn gold">Save knowledge</button>
        <span class="crm-muted"><?= number_format(mb_strlen($current)) ?> characters</span>
      </p>
    </form>
  </div>

</div>

<?php require __DIR__ . '/../_footer.php'; ?>
