<?php
/* Manual lead entry - for leads from calls, walk-ins, portals, Instagram etc. */
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../../includes/country-codes.php';
require __DIR__ . '/_lead.php';

require_role(['admin', 'sales']);

$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    /* combine the country code select + national number into one phone string */
    $cc  = (string)($_POST['phone_cc'] ?? '');
    $num = preg_replace('/\D/', '', (string)($_POST['phone_num'] ?? ''));
    $num = ltrim($num, '0');
    $_POST['phone'] = ($num !== '' && preg_match('/^\+\d{1,4}$/', $cc)) ? $cc . $num : '';

    $m = lead_from_post();
    if ($m['name'] === '' || ($m['phone'] === '' && $m['email'] === '')) {
        $error = 'A name plus a phone number or email is required.';
    } else {
        [$score, $quality] = lead_score($m);
        db_run("INSERT INTO chat_leads (source, channel, name, phone, email, car_interest, intent,
                       budget, timeframe, notes, language, score, quality, status)
                VALUES ('manual',?,?,?,?,?,?,?,?,?,?,?,?,'new')",
               [$m['channel'] ?: null, $m['name'], $m['phone'] ?: null, $m['email'] ?: null,
                $m['car_interest'] ?: null, $m['intent'] ?: null, $m['budget'] ?: null,
                $m['timeframe'] ?: null, $m['notes'] ?: null, $m['language'] ?: null,
                $score, $quality]);
        header('Location: /admin/chat-leads/view.php?id=' . (int)db_last_insert_id());
        exit;
    }
}

$admin_page_title = 'Add Lead';
$admin_extra_css  = ['/assets/css/crm.css'];
require __DIR__ . '/../_header.php';
$v = $_POST;   // keep what was typed if validation failed
$selCc = (string)($v['phone_cc'] ?? DEFAULT_DIAL_CODE);
?>

<div class="crm">

  <div class="crm-head">
    <h1>Add Lead <span class="crm-tag channel">MANUAL</span></h1>
    <span class="crm-head-actions">
      <a href="/admin/chat-leads/" class="crm-btn">&larr; All leads</a>
    </span>
  </div>

  <?php if ($error !== ''): ?><div class="crm-note-err"><?= e($error) ?></div><?php endif; ?>

  <form method="post" class="crm-card crm-panel" style="max-width:760px;">
    <div class="crm-form">
      <div><label>Name *</label>
        <input class="crm-in" type="text" name="name" value="<?= e($v['name'] ?? '') ?>" required></div>
      <div><label>Phone</label>
        <span class="crm-phone-row">
          <select name="phone_cc">
            <?php foreach (country_dial_codes() as $c): ?>
              <option value="<?= e($c['code']) ?>" <?= $c['code'] === $selCc ? 'selected' : '' ?>>
                <?= e($c['flag'] . ' ' . $c['name'] . ' ' . $c['code']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <input class="crm-in" type="text" name="phone_num" value="<?= e($v['phone_num'] ?? '') ?>"
                 placeholder="50 123 4567" inputmode="tel">
        </span></div>
      <div><label>Email</label>
        <input class="crm-in" type="email" name="email" value="<?= e($v['email'] ?? '') ?>"></div>
      <div><label>Lead source</label>
        <select name="channel" id="lead-channel">
          <option value="">—</option>
          <?php foreach (LEAD_CHANNELS as $ch): ?>
            <option value="<?= e($ch) ?>" <?= ($v['channel'] ?? '') === $ch ? 'selected' : '' ?>><?= e(ucwords($ch)) ?></option>
          <?php endforeach; ?>
        </select>
        <input class="crm-in" type="text" name="channel_other" id="lead-channel-other" value="<?= e($v['channel_other'] ?? '') ?>"
               placeholder="Where did this lead come from?" maxlength="50" style="margin-top:.4rem;display:none;"></div>
      <div class="full"><label>Cars of interest <span style="font-weight:400;">(add as many as the customer wants)</span></label>
        <?php lead_cars_picker((string)($v['car_interest'] ?? '')) ?></div>
      <div><label>Intent</label>
        <select name="intent">
          <option value="">—</option>
          <?php foreach (LEAD_INTENTS as $i): ?>
            <option value="<?= e($i) ?>" <?= ($v['intent'] ?? '') === $i ? 'selected' : '' ?>><?= e(ucfirst($i)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Budget</label>
        <input class="crm-in" type="text" name="budget" value="<?= e($v['budget'] ?? '') ?>" placeholder="e.g. 500,000"></div>
      <div><label>Timeframe</label>
        <select name="timeframe">
          <option value="">—</option>
          <?php foreach (LEAD_TIMEFRAMES as $t): ?>
            <option value="<?= e($t) ?>" <?= ($v['timeframe'] ?? '') === $t ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $t)) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Language</label>
        <input class="crm-in" type="text" name="language" value="<?= e($v['language'] ?? '') ?>" placeholder="e.g. English, Arabic, Russian"></div>
      <div class="full"><label>Notes for the sales team</label>
        <textarea name="notes" rows="5"><?= e($v['notes'] ?? '') ?></textarea></div>
      <div class="full">
        <button type="submit" class="crm-btn gold">Save lead</button>
      </div>
    </div>
  </form>
  <?php lead_cars_datalist(); ?>

</div>

<script>
(function () {
  var sel = document.getElementById('lead-channel');
  var other = document.getElementById('lead-channel-other');
  function toggle() { other.style.display = sel.value === 'other' ? 'block' : 'none'; }
  sel.addEventListener('change', toggle);
  toggle();
})();
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
