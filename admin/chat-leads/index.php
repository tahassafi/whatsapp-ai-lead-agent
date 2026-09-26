<?php
/* Leads list / CRM dashboard */
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/_lead.php';

require_role(['admin', 'sales']);

$filter = $_GET['status'] ?? 'all';
$allowed = ['all', 'new', 'contacted', 'qualified', 'closed', 'junk'];
if (!in_array($filter, $allowed, true)) { $filter = 'all'; }

$srcFilter = $_GET['source'] ?? 'all';
if (!in_array($srcFilter, ['all', 'chatbot', 'whatsapp', 'manual'], true)) { $srcFilter = 'all'; }

/* stat-card quick filters */
$qHot   = ($_GET['q'] ?? '') === 'hot';
$range7 = ($_GET['range'] ?? '') === '7d';

$where = [];
$params = [];
if ($filter !== 'all')    { $where[] = 'status = ?'; $params[] = $filter; }
if ($srcFilter !== 'all') { $where[] = 'source = ?'; $params[] = $srcFilter; }
if ($qHot)   { $where[] = "quality = 'hot' AND status NOT IN ('closed','junk')"; }
if ($range7) { $where[] = 'created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)'; }
$sql = "SELECT * FROM chat_leads"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY created_at DESC";
$leads = db_all($sql, $params);

/* counts respect the OTHER filter, so the numbers always match what a click shows */
$statusWhere  = $srcFilter !== 'all' ? " WHERE source = ?" : '';
$statusParams = $srcFilter !== 'all' ? [$srcFilter] : [];
$counts = [];
foreach (db_all("SELECT status, COUNT(*) AS n FROM chat_leads$statusWhere GROUP BY status", $statusParams) as $c) {
    $counts[$c['status']] = (int)$c['n'];
}
$total = array_sum($counts);

$srcWhere  = $filter !== 'all' ? " WHERE status = ?" : '';
$srcParams = $filter !== 'all' ? [$filter] : [];
$srcCounts = ['chatbot' => 0, 'whatsapp' => 0, 'manual' => 0];
foreach (db_all("SELECT source, COUNT(*) AS n FROM chat_leads$srcWhere GROUP BY source", $srcParams) as $c) {
    $srcCounts[$c['source']] = (int)$c['n'];
}

/* stat cards (always overall numbers) */
$stAll  = (int)(db_one("SELECT COUNT(*) n FROM chat_leads")['n'] ?? 0);
$stHot  = (int)(db_one("SELECT COUNT(*) n FROM chat_leads WHERE quality = 'hot' AND status NOT IN ('closed','junk')")['n'] ?? 0);
$stNew  = (int)(db_one("SELECT COUNT(*) n FROM chat_leads WHERE status = 'new'")['n'] ?? 0);
$stWeek = (int)(db_one("SELECT COUNT(*) n FROM chat_leads WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)")['n'] ?? 0);

function lead_initials(?string $name): string
{
    $parts = preg_split('/\s+/', trim((string)$name)) ?: [];
    $ini = '';
    foreach (array_slice($parts, 0, 2) as $p) { $ini .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $ini !== '' ? $ini : '?';
}

$admin_page_title = 'Leads';
$admin_extra_css  = ['/assets/css/crm.css'];
require __DIR__ . '/../_header.php';
?>

<div class="crm">

  <div class="crm-head">
    <h1>Leads <span class="crm-count-chip"><?= $stAll ?></span></h1>
    <span class="crm-head-actions">
      <a href="/admin/chat-leads/add.php" class="crm-btn gold">+ Add Lead</a>
      <?php if (current_admin()['role'] === 'admin'): ?>
        <a href="/admin/chat-leads/knowledge.php" class="crm-btn">Chatbot Knowledge</a>
      <?php endif; ?>
    </span>
  </div>

  <div class="crm-stats">
    <a class="crm-stat" href="/admin/chat-leads/"><b><?= $stAll ?></b><span>Total leads</span></a>
    <a class="crm-stat hot <?= $qHot ? 'on' : '' ?>" href="/admin/chat-leads/?q=hot"><b><?= $stHot ?></b><span>Hot &amp; open</span></a>
    <a class="crm-stat new <?= $filter === 'new' ? 'on' : '' ?>" href="/admin/chat-leads/?status=new"><b><?= $stNew ?></b><span>New / untouched</span></a>
    <a class="crm-stat week <?= $range7 ? 'on' : '' ?>" href="/admin/chat-leads/?range=7d"><b><?= $stWeek ?></b><span>Last 7 days</span></a>
  </div>

  <div class="crm-filters">
    <?php foreach (['all' => 'All', 'new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'closed' => 'Closed', 'junk' => 'Junk'] as $key => $label): ?>
      <a class="crm-pill <?= $filter === $key ? 'on' : '' ?>"
         href="/admin/chat-leads/?status=<?= e($key) ?>&amp;source=<?= e($srcFilter) ?>">
         <?= e($label) ?><i><?= $key === 'all' ? $total : ($counts[$key] ?? 0) ?></i></a>
    <?php endforeach; ?>
  </div>

  <div class="crm-filters">
    <span class="lbl">SOURCE</span>
    <?php foreach (['all' => 'All', 'chatbot' => 'Website chat', 'whatsapp' => 'WhatsApp', 'manual' => 'Manual entry'] as $key => $label): ?>
      <a class="crm-pill <?= $srcFilter === $key ? 'on' : '' ?>"
         href="/admin/chat-leads/?status=<?= e($filter) ?>&amp;source=<?= e($key) ?>">
         <?= e($label) ?><i><?= $key === 'all' ? array_sum($srcCounts) : $srcCounts[$key] ?></i></a>
    <?php endforeach; ?>
  </div>

  <div class="crm-card" style="margin-top:8px;">
    <div class="crm-card-head">
      <div class="crm-card-title">
        <b>Leads List</b>
        <span><?= count($leads) ?> in this view &middot; <?= $stAll ?> total</span>
      </div>
      <span class="crm-search-wrap">
        <input type="search" id="crm-search" placeholder="Search name, phone, car..." autocomplete="off">
      </span>
    </div>
  <?php if (empty($leads)): ?>
    <div class="crm-empty">No leads here yet. The chatbot adds them automatically, or use + Add Lead.</div>
  <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="crm-table" id="crm-leads-table">
      <thead>
        <tr>
          <th class="sortable" data-key="name">Lead</th>
          <th class="sortable" data-key="score">Quality</th>
          <th>Contact</th>
          <th class="sortable" data-key="interest">Interest</th>
          <th>Budget / When</th>
          <th class="sortable" data-key="status">Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($leads as $l): ?>
          <tr class="lead-row is-<?= e($l['status']) ?>"
              data-name="<?= e(mb_strtolower($l['name'] ?? '')) ?>"
              data-score="<?= (int)$l['score'] ?>"
              data-interest="<?= e(mb_strtolower($l['car_interest'] ?? '')) ?>"
              data-status="<?= e($l['status']) ?>"
              data-ts="<?= (int)strtotime($l['created_at']) ?>">
            <td>
              <span class="crm-who">
                <span class="crm-avatar q-<?= e($l['quality']) ?>"><?= e(lead_initials($l['name'])) ?></span>
                <span>
                  <b><a class="crm-link" style="color:inherit;" href="/admin/chat-leads/view.php?id=<?= (int)$l['id'] ?>"><?= e($l['name'] ?: 'No name') ?></a></b>
                  <small><?= e(date('j M, g:ia', strtotime($l['created_at']))) ?></small>
                  <?php if (($l['source'] ?? 'chatbot') === 'manual'): ?>
                    <small><span class="crm-tag channel"><?= e(strtoupper($l['channel'] ?: 'MANUAL')) ?></span></small>
                  <?php endif; ?>
                </span>
              </span>
            </td>
            <td><span class="crm-q <?= e($l['quality']) ?>"><?= e($l['quality']) ?><i><?= (int)$l['score'] ?></i></span></td>
            <td>
              <?php if (!empty($l['phone'])):
                  $wa = preg_replace('/\D/', '', $l['phone']); ?>
                <a class="crm-wa-link" target="_blank" rel="noopener"
                   href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hello ' . ($l['name'] ?? '') . ', this is ' . SITE_NAME . '. Thank you for your interest in ' . ($l['car_interest'] ?: 'our cars') . '.') ?>">
                   <?= e($l['phone']) ?></a><br>
              <?php endif; ?>
              <?php if (!empty($l['email'])): ?>
                <a class="crm-link" href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a>
              <?php endif; ?>
              <?php if (empty($l['phone']) && empty($l['email'])): ?><span class="crm-muted">—</span><?php endif; ?>
            </td>
            <td style="max-width:230px;white-space:normal;">
              <?= e($l['car_interest'] ?: '—') ?>
              <?php if (!empty($l['intent'])): ?> <span class="crm-tag"><?= e($l['intent']) ?></span><?php endif; ?>
            </td>
            <td class="crm-muted"><?= e($l['budget'] ?: '—') ?><br><?= e(str_replace('_', ' ', $l['timeframe'] ?? '')) ?></td>
            <td>
              <form method="post" action="/admin/chat-leads/update.php" class="inline-form">
                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <select name="status" class="crm-status st-<?= e($l['status']) ?>" onchange="this.form.submit()">
                  <?php foreach (LEAD_STATUSES as $s): ?>
                    <option value="<?= e($s) ?>" <?= $l['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td style="white-space:nowrap;text-align:right;">
              <a class="crm-open" href="/admin/chat-leads/view.php?id=<?= (int)$l['id'] ?>">Open</a> &nbsp;
              <form method="post" action="/admin/chat-leads/update.php" class="inline-form"
                    onsubmit="return confirm('Delete this lead?');">
                <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                <input type="hidden" name="delete" value="1">
                <button type="submit" class="crm-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  </div>

</div>

<script>
// Live search: filters visible rows by anything in them (name, phone, car,
// status). Instant, client-side, plays fine with the status/source filters.
(function () {
  var box = document.getElementById('crm-search');
  if (!box) return;
  var rows = Array.prototype.slice.call(document.querySelectorAll('.lead-row'));
  box.addEventListener('input', function () {
    var q = box.value.trim().toLowerCase();
    rows.forEach(function (r) {
      r.style.display = !q || r.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
    });
  });
})();

// Sortable columns: click a header to sort, click again to flip direction.
// Everything is client-side over the already-rendered rows, so it combines
// freely with the search box and the page filters.
(function () {
  var table = document.getElementById('crm-leads-table');
  if (!table) return;
  var tbody = table.querySelector('tbody');
  var heads = table.querySelectorAll('th.sortable');
  var current = null, dir = 1;
  heads.forEach(function (th) {
    th.addEventListener('click', function () {
      var key = th.dataset.key;
      dir = (current === key) ? -dir : (key === 'score' ? -1 : 1);  // scores: high first
      current = key;
      heads.forEach(function (h) { h.classList.remove('asc', 'desc'); });
      th.classList.add(dir === 1 ? 'asc' : 'desc');
      var rows = Array.prototype.slice.call(tbody.querySelectorAll('.lead-row'));
      rows.sort(function (a, b) {
        // Junk always sinks to the bottom, whatever column/direction is active.
        var aj = a.dataset.status === 'junk' ? 1 : 0;
        var bj = b.dataset.status === 'junk' ? 1 : 0;
        if (aj !== bj) { return aj - bj; }
        var av = a.dataset[key] || '', bv = b.dataset[key] || '';
        if (key === 'score' || key === 'ts') { return (Number(av) - Number(bv)) * dir; }
        return av.localeCompare(bv) * dir;
      });
      rows.forEach(function (r) { tbody.appendChild(r); });
    });
  });
})();
</script>

<?php require __DIR__ . '/../_footer.php'; ?>
