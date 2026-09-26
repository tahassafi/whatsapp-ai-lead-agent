<?php
/* Lead record - edit everything in one place, CRM style, with transcript
   translation. */
require __DIR__ . '/../../config.php';
require __DIR__ . '/../../includes/db.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/_lead.php';

require_role(['admin', 'sales']);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$lead = db_one("SELECT * FROM chat_leads WHERE id = ?", [$id]);
if (!$lead) {
    http_response_code(404);
    exit('Lead not found.');
}

$conv = !empty($lead['conversation_id'])
    ? db_one("SELECT * FROM chat_conversations WHERE id = ?", [(int)$lead['conversation_id']])
    : null;
$isWa = $conv && !empty($conv['wa_phone']);

/* ---- WhatsApp conversation controls: reply as the bot number, pause/resume AI ---- */
$waError = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !empty($_POST['wa_action']) && $isWa) {
    $act = (string)$_POST['wa_action'];
    try {
    if ($act === 'reply') {
        $txt = trim((string)($_POST['reply_text'] ?? ''));
        if ($txt === '') {
            $waError = 'Type a message first.';
        } elseif (!lead_wa_configured()) {
            $waError = 'WhatsApp API is not configured on this site.';
        } else {
            $r = lead_wa_send_text($conv['wa_phone'], $txt);
            if ($r === true) {
                db_run("INSERT INTO chat_messages (conversation_id, role, content) VALUES (?, 'agent', ?)",
                       [(int)$conv['id'], $txt]);
                /* sales started chatting - AI stays off until manually re-enabled */
                db_run("UPDATE chat_conversations SET human_owned = 1 WHERE id = ?", [(int)$conv['id']]);
                if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
                header('Location: /admin/chat-leads/view.php?id=' . $id . '&sent=1#reply-box');
                exit;
            }
            $waError = 'Send failed: ' . $r;
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $waError]);
            exit;
        }
    } elseif ($act === 'voice') {
        if (empty($_FILES['voice']['tmp_name']) || !is_uploaded_file($_FILES['voice']['tmp_name'])) {
            $waError = 'No recording received.';
        } elseif (!lead_wa_configured()) {
            $waError = 'WhatsApp API is not configured on this site.';
        } else {
            $r = lead_wa_send_voice($conv['wa_phone'], $_FILES['voice']['tmp_name'], (string)($_FILES['voice']['type'] ?? ''));
            if (is_array($r)) {
                db_run("INSERT INTO chat_messages (conversation_id, role, content) VALUES (?, 'agent', ?)",
                       [(int)$conv['id'], '[voice] ' . $r[1]]);
                db_run("UPDATE chat_conversations SET human_owned = 1 WHERE id = ?", [(int)$conv['id']]);
                if (!empty($_POST['ajax'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
                header('Location: /admin/chat-leads/view.php?id=' . $id . '&sent=1#reply-box');
                exit;
            }
            $waError = $r;
        }
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $waError]);
            exit;
        }
    } elseif ($act === 'pause') {
        $dur = (string)($_POST['duration'] ?? '6');
        if ($dur === 'forever') {
            db_run("UPDATE chat_conversations SET human_owned = 1 WHERE id = ?", [(int)$conv['id']]);
        } else {
            $h = in_array((int)$dur, [1, 6, 24, 48, 168], true) ? (int)$dur : 6;
            db_run("UPDATE chat_conversations SET bot_paused_until = DATE_ADD(NOW(), INTERVAL $h HOUR), human_owned = 0
                     WHERE id = ?", [(int)$conv['id']]);
        }
        header('Location: /admin/chat-leads/view.php?id=' . $id . '#ai-card');
        exit;
    } elseif ($act === 'resume') {
        db_run("UPDATE chat_conversations SET bot_paused_until = NULL, human_owned = 0, off_topic_count = 0
                 WHERE id = ?", [(int)$conv['id']]);
        header('Location: /admin/chat-leads/view.php?id=' . $id . '#ai-card');
        exit;
    }
    } catch (Throwable $e) {
        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'PHP error: ' . $e->getMessage()
                . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')']);
            exit;
        }
        $waError = 'Error: ' . $e->getMessage();
    }
    $conv = db_one("SELECT * FROM chat_conversations WHERE id = ?", [(int)$lead['conversation_id']]);
}

$saved = false;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && empty($_POST['translate']) && empty($_POST['wa_action'])) {
    $m = lead_from_post();
    $status = (string)($_POST['status'] ?? $lead['status']);
    if (!in_array($status, LEAD_STATUSES, true)) { $status = $lead['status']; }
    [$score, $quality] = lead_score($m);
    db_run("UPDATE chat_leads SET name=?, phone=?, email=?, channel=?, car_interest=?, intent=?, budget=?,
                   timeframe=?, notes=?, language=?, score=?, quality=?, status=? WHERE id = ?",
           [$m['name'] ?: null, $m['phone'] ?: null, $m['email'] ?: null, $m['channel'] ?: null,
            $m['car_interest'] ?: null, $m['intent'] ?: null, $m['budget'] ?: null,
            $m['timeframe'] ?: null, $m['notes'] ?: null, $m['language'] ?: null,
            $score, $quality, $status, $id]);
    $lead = db_one("SELECT * FROM chat_leads WHERE id = ?", [$id]);
    $saved = true;
}

$transcript = [];
if (!empty($lead['conversation_id'])) {
    $transcript = db_all(
        "SELECT id, role, content, created_at FROM chat_messages WHERE conversation_id = ? ORDER BY id ASC",
        [(int)$lead['conversation_id']]
    );
}

/* on-demand English translation of the whole conversation */
$translations = null;
$translateErr = '';
if (!empty($_POST['translate']) && $transcript) {
    $translations = lead_translate_transcript($transcript);
    if ($translations === null) {
        $translateErr = 'Translation failed - check the API key / credit and try again.';
    }
}

$wa = !empty($lead['phone']) ? preg_replace('/\D/', '', $lead['phone']) : '';

$curChannel = (string)($lead['channel'] ?? '');
$isListed   = in_array($curChannel, LEAD_CHANNELS, true);
$selChannel = $curChannel === '' ? '' : ($isListed ? $curChannel : 'other');

$admin_page_title = 'Lead #' . $id;
$admin_extra_css  = ['/assets/css/crm.css'];
require __DIR__ . '/../_header.php';
?>

<div class="crm">

  <div class="crm-head">
    <h1><?= e($lead['name'] ?: 'No name') ?>
      <span class="crm-q <?= e($lead['quality']) ?>"><?= e($lead['quality']) ?></span>
    </h1>
    <span class="crm-head-actions">
      <a href="/admin/chat-leads/" class="crm-btn">&larr; All leads</a>
    </span>
  </div>

  <?php if ($saved): ?><div class="crm-note-ok">Saved.</div><?php endif; ?>
  <?php if (isset($_GET['sent'])): ?><div class="crm-note-ok">Message sent to the customer on WhatsApp.</div><?php endif; ?>
  <?php if ($waError !== ''): ?><div class="crm-note-err"><?= e($waError) ?></div><?php endif; ?>
  <?php if ($translateErr !== ''): ?><div class="crm-note-err"><?= e($translateErr) ?></div><?php endif; ?>

  <div class="crm-grid">

    <div>
      <form method="post" class="crm-card crm-panel">
        <h2>Lead details</h2>
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="crm-form">
          <div><label>Name</label>
            <input class="crm-in" type="text" name="name" value="<?= e($lead['name'] ?? '') ?>"></div>
          <div><label>Phone</label>
            <input class="crm-in" type="text" name="phone" value="<?= e($lead['phone'] ?? '') ?>"></div>
          <div><label>Email</label>
            <input class="crm-in" type="email" name="email" value="<?= e($lead['email'] ?? '') ?>"></div>
          <div><label>Lead source</label>
            <select name="channel" id="lead-channel">
              <option value="">—</option>
              <?php foreach (LEAD_CHANNELS as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $selChannel === $ch ? 'selected' : '' ?>><?= e(ucwords($ch)) ?></option>
              <?php endforeach; ?>
            </select>
            <input class="crm-in" type="text" name="channel_other" id="lead-channel-other"
                   value="<?= e($isListed ? '' : $curChannel) ?>" placeholder="Where did this lead come from?"
                   maxlength="50" style="margin-top:.4rem;display:none;"></div>
          <div class="full"><label>Cars of interest <span style="font-weight:400;">(add as many as the customer wants)</span></label>
            <?php lead_cars_picker((string)($lead['car_interest'] ?? '')) ?></div>
          <div><label>Intent</label>
            <select name="intent">
              <option value="">—</option>
              <?php foreach (LEAD_INTENTS as $i): ?>
                <option value="<?= e($i) ?>" <?= ($lead['intent'] ?? '') === $i ? 'selected' : '' ?>><?= e(ucfirst($i)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Budget</label>
            <input class="crm-in" type="text" name="budget" value="<?= e($lead['budget'] ?? '') ?>"></div>
          <div><label>Timeframe</label>
            <select name="timeframe">
              <option value="">—</option>
              <?php foreach (LEAD_TIMEFRAMES as $t): ?>
                <option value="<?= e($t) ?>" <?= ($lead['timeframe'] ?? '') === $t ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $t)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div><label>Language</label>
            <input class="crm-in" type="text" name="language" value="<?= e($lead['language'] ?? '') ?>"></div>
          <div><label>Status</label>
            <select name="status">
              <?php foreach (LEAD_STATUSES as $s): ?>
                <option value="<?= e($s) ?>" <?= $lead['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="full"><label>Notes (sales team + AI both write here)</label>
            <textarea name="notes" rows="5"><?= e($lead['notes'] ?? '') ?></textarea></div>
          <div class="full">
            <button type="submit" class="crm-btn gold">Save changes</button>
          </div>
        </div>
      </form>
      <?php lead_cars_datalist(); ?>

      <?php if (!empty($transcript)): ?>
        <?php $lastMsgId = (int)end($transcript)['id']; ?>
        <div class="crm-card wa-card" style="margin-top:18px;">
          <div class="crm-panel" style="padding-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <h2 style="margin:0;">Conversation</h2>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
              <?php if ($translations === null): ?>
                <form method="post" style="margin:0;">
                  <input type="hidden" name="id" value="<?= (int)$id ?>">
                  <input type="hidden" name="translate" value="1">
                  <button type="submit" class="crm-btn">🌐 Translate to English</button>
                </form>
              <?php else: ?>
                <span class="crm-tag channel">SHOWING ORIGINAL + ENGLISH</span>
              <?php endif; ?>
              <button type="button" class="crm-btn" id="wa-full-btn" title="Full screen">⛶ Full screen</button>
            </div>
          </div>
          <div class="wa-chat" id="wa-chat" data-conv="<?= (int)$lead['conversation_id'] ?>" data-last="<?= $lastMsgId ?>">
            <?php foreach ($transcript as $ti => $mrow): ?>
              <?php $dir = $mrow['role'] === 'user' ? 'in' : 'out'; ?>
              <div class="wa-msg <?= $dir ?>"><div class="wa-bubble"><?php
                if ($dir === 'out') {
                    echo '<span class="wa-who">' . ($mrow['role'] === 'agent' ? 'Sales team' : 'AI assistant') . '</span>';
                }
                echo '<span class="wa-text">';
                if (preg_match('#^\\[photo\\] (/assets/cache/wa-img/[A-Za-z0-9._-]+\\.(jpe?g|png|webp))(\\n(.*))?$#s', $mrow['content'], $pm)) {
                    echo '<a href="' . e($pm[1]) . '" target="_blank" rel="noopener"><img src="' . e($pm[1]) . '" alt="Customer photo" style="max-width:220px;width:100%;border-radius:6px;display:block;"></a>';
                    if (!empty($pm[4])) { echo '<span style="display:block;margin-top:4px;">' . e($pm[4]) . '</span>'; }
                } elseif (preg_match('#^\\[voice\\] (/assets/cache/wa-voice/[A-Za-z0-9._-]+\\.(ogg|mp3|m4a|webm))$#', $mrow['content'], $vm)) {
                    echo '<audio controls preload="none" src="' . e($vm[1]) . '"></audio>';
                } else {
                    echo e($mrow['content']);
                }
                echo '</span>';
                if (is_array($translations) && isset($translations[$ti])
                        && trim($translations[$ti]) !== trim($mrow['content'])) {
                    echo '<span class="en"><b>English</b><br>' . e($translations[$ti]) . '</span>';
                }
                echo '<span class="wa-time">' . e(date('g:i A', strtotime($mrow['created_at']))) . '</span>';
              ?></div></div>
            <?php endforeach; ?>
          </div>
          <script>
          /* Full-screen conversation: works for WhatsApp AND website chats. */
          (function () {
            var btn = document.getElementById('wa-full-btn');
            if (!btn) return;
            var card = btn.closest('.wa-card');
            var chatEl = document.getElementById('wa-chat');
            function set(on) {
              card.classList.toggle('wa-full', on);
              document.body.classList.toggle('wa-full-open', on);
              btn.textContent = on ? '✕ Close' : '⛶ Full screen';
              if (chatEl) chatEl.scrollTop = chatEl.scrollHeight;
            }
            btn.addEventListener('click', function () { set(!card.classList.contains('wa-full')); });
            document.addEventListener('keydown', function (e) {
              if (e.key === 'Escape' && card.classList.contains('wa-full')) set(false);
            });
          })();
          </script>
          <?php if ($isWa): ?>
            <div class="wa-reply" id="reply-box">
              <button type="button" class="wa-icon-btn" id="vn-file-btn" title="Send an audio file">📎</button>
              <textarea id="wa-text" rows="1" placeholder="Type a message"></textarea>
              <button type="button" class="wa-icon-btn" id="vn-rec" title="Record a voice note">🎤</button>
              <button type="button" class="wa-send" id="wa-send-btn" title="Send">➤</button>
            </div>
            <p class="crm-muted" id="vn-status" style="padding:6px 18px 14px;margin:0;font-size:12.5px;"></p>
            <input type="file" id="vn-file" accept="audio/*" style="display:none;">
            <script>
            (function () {
              var chat = document.getElementById('wa-chat');
              var lastId = parseInt(chat.dataset.last, 10) || 0;
              var convId = chat.dataset.conv;
              var textEl = document.getElementById('wa-text');
              var sendBtn = document.getElementById('wa-send-btn');
              var recBtn = document.getElementById('vn-rec');
              var fileBtn = document.getElementById('vn-file-btn');
              var fileInp = document.getElementById('vn-file');
              var status = document.getElementById('vn-status');
              var leadId = <?= (int)$id ?>;

              function esc(t) {
                var d = document.createElement('div');
                d.textContent = t;
                return d.innerHTML;
              }
              function nearBottom() {
                return chat.scrollHeight - chat.scrollTop - chat.clientHeight < 140;
              }
              function scrollDown() { chat.scrollTop = chat.scrollHeight; }

              function addMsg(m) {
                var dir = m.role === 'user' ? 'in' : 'out';
                var who = dir === 'out'
                  ? '<span class="wa-who">' + (m.role === 'agent' ? 'Sales team' : 'AI assistant') + '</span>' : '';
                var body;
                var pm = m.content.match(/^\[photo\] (\/assets\/cache\/wa-img\/[A-Za-z0-9._-]+\.(jpe?g|png|webp))(\n([\s\S]*))?$/);
                var vm = m.content.match(/^\[voice\] (\/assets\/cache\/wa-voice\/[A-Za-z0-9._-]+\.(ogg|mp3|m4a|webm))$/);
                if (pm) {
                  body = '<a href="' + pm[1] + '" target="_blank" rel="noopener"><img src="' + pm[1] + '" alt="Customer photo" style="max-width:220px;width:100%;border-radius:6px;display:block;"></a>' +
                         (pm[4] ? '<span style="display:block;margin-top:4px;">' + esc(pm[4]) + '</span>' : '');
                } else if (vm) {
                  body = '<audio controls preload="none" src="' + vm[1] + '"></audio>';
                } else {
                  body = esc(m.content);
                }
                var el = document.createElement('div');
                el.className = 'wa-msg ' + dir;
                el.innerHTML = '<div class="wa-bubble">' + who + '<span class="wa-text">' + body +
                  '</span><span class="wa-time">' + esc(m.time) + '</span></div>';
                chat.appendChild(el);
              }

              /* Only one poll may be in flight at a time. Without this, the
                 poll() fired right after a send races the 3-second timer's
                 poll(): both request with the SAME after= id, both receive the
                 new message, and it gets appended twice. The id check is a
                 second safety net against any other overlap. */
              var pollBusy = false;
              function poll() {
                if (pollBusy) return;
                pollBusy = true;
                fetch('/admin/chat-leads/messages.php?conv=' + convId + '&after=' + lastId)
                  .then(function (r) { return r.json(); })
                  .then(function (d) {
                    pollBusy = false;
                    if (!d || !d.messages || !d.messages.length) return;
                    var stick = nearBottom();
                    d.messages.forEach(function (m) {
                      if (m.id <= lastId) return;   // already rendered
                      addMsg(m);
                      lastId = m.id;
                    });
                    if (stick) scrollDown();
                  })
                  .catch(function () { pollBusy = false; });
              }
              setInterval(function () { if (!document.hidden) poll(); }, 3000);
              scrollDown();

              function postForm(fd, done) {
                fetch(location.pathname + '?id=' + leadId, { method: 'POST', body: fd })
                  .then(function (r) { return r.text(); })
                  .then(function (t) {
                    var d;
                    try { d = JSON.parse(t); }
                    catch (e) { done({ ok: false, error: 'Server said: ' + t.replace(/<[^>]*>/g, ' ').trim().slice(0, 180) }); return; }
                    done(d);
                  })
                  .catch(function (e) { done({ ok: false, error: 'Connection failed: ' + e.message }); });
              }

              function sendText() {
                var txt = textEl.value.trim();
                if (!txt) return;
                sendBtn.disabled = true;
                var fd = new FormData();
                fd.append('id', leadId);
                fd.append('wa_action', 'reply');
                fd.append('reply_text', txt);
                fd.append('ajax', '1');
                postForm(fd, function (d) {
                  sendBtn.disabled = false;
                  if (d.ok) { textEl.value = ''; status.textContent = ''; poll(); }
                  else { status.textContent = d.error || 'Send failed.'; }
                });
              }
              sendBtn.addEventListener('click', sendText);
              textEl.addEventListener('keydown', function (e) {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendText(); }
              });

              function sendAudio(blob, filename) {
                status.textContent = 'Sending voice note...';
                var fd = new FormData();
                fd.append('id', leadId);
                fd.append('wa_action', 'voice');
                fd.append('ajax', '1');
                fd.append('voice', blob, filename);
                postForm(fd, function (d) {
                  if (d.ok) { status.textContent = ''; hidePreview(); poll(); }
                  else { status.textContent = d.error || 'Send failed.'; }
                });
              }

              /* ---- voice note preview: listen before sending ---- */
              var preview = document.createElement('div');
              preview.className = 'wa-vn-preview';
              preview.style.display = 'none';
              preview.innerHTML = '<audio controls></audio>' +
                '<button type="button" class="crm-btn gold" id="vn-send">Send voice note</button>' +
                '<button type="button" class="crm-btn" id="vn-discard">Discard</button>';
              document.getElementById('reply-box').insertAdjacentElement('beforebegin', preview);
              var pendingBlob = null, pendingName = '';
              function showPreview(blob, filename) {
                pendingBlob = blob; pendingName = filename;
                preview.querySelector('audio').src = URL.createObjectURL(blob);
                preview.style.display = 'flex';
                status.textContent = 'Listen to your recording, then Send or Discard.';
              }
              function hidePreview() {
                preview.style.display = 'none';
                var a = preview.querySelector('audio');
                a.pause(); a.removeAttribute('src');
                pendingBlob = null;
              }
              /* ---- in-browser audio conversion (no server dependencies) ----
                 WhatsApp only reliably accepts mp3/ogg. Browser recordings are
                 webm/m4a, so we convert to mp3 locally with ffmpeg.wasm. */
              var ffmpegObj = null;
              function addScript(src) {
                return new Promise(function (res, rej) {
                  var sc = document.createElement('script');
                  sc.src = src; sc.onload = res; sc.onerror = rej;
                  document.head.appendChild(sc);
                });
              }
              function loadFFmpeg() {
                if (ffmpegObj) return Promise.resolve(ffmpegObj);
                status.textContent = 'Loading audio converter (~30 MB, first time only)...';
                return addScript('/assets/vendor/ffmpeg/ffmpeg.js')
                  .then(function () { return addScript('/assets/vendor/ffmpeg/util.js'); })
                  .then(function () {
                    var ff = new FFmpegWASM.FFmpeg();
                    return ff.load({
                      coreURL: '/assets/vendor/ffmpeg/ffmpeg-core.js',
                      wasmURL: '/assets/vendor/ffmpeg/ffmpeg-core.wasm'
                    }).then(function () { ffmpegObj = ff; return ff; });
                  });
              }
              /* OGG/Opus renders as a real WhatsApp voice note (waveform bubble);
                 mp3 is the fallback if the opus encoder is unavailable. */
              function convertAudio(blob) {
                return loadFFmpeg().then(function (ff) {
                  return FFmpegUtil.fetchFile(blob)
                    .then(function (data) { return ff.writeFile('in.dat', data); })
                    .then(function () {
                      status.textContent = 'Converting audio...';
                      return ff.exec(['-i', 'in.dat', '-vn', '-ar', '16000', '-ac', '1',
                                      '-c:a', 'libopus', '-b:a', '24k', 'out.ogg'])
                        .then(function () { return ff.readFile('out.ogg'); })
                        .then(function (out) {
                          if (!out || !out.length) throw new Error('opus empty');
                          return { blob: new Blob([out.buffer], { type: 'audio/ogg' }), name: 'voice.ogg' };
                        })
                        .catch(function () {
                          return ff.exec(['-i', 'in.dat', '-vn', '-ar', '44100', '-ac', '1',
                                          '-c:a', 'libmp3lame', '-b:a', '64k', 'out.mp3'])
                            .then(function () { return ff.readFile('out.mp3'); })
                            .then(function (out) {
                              return { blob: new Blob([out.buffer], { type: 'audio/mpeg' }), name: 'voice.mp3' };
                            });
                        });
                    });
                });
              }

              preview.addEventListener('click', function (e) {
                if (e.target.id === 'vn-send' && pendingBlob) {
                  var t = (pendingBlob.type || '').toLowerCase();
                  if (t.indexOf('ogg') > -1) {
                    sendAudio(pendingBlob, pendingName);   // already voice-note format
                  } else {
                    convertAudio(pendingBlob)
                      .then(function (r) { sendAudio(r.blob, r.name); })
                      .catch(function (err) {
                        status.textContent = 'Conversion failed (' + (err && err.message ? err.message : 'unknown') + ') - try uploading an ogg/mp3 with 📎.';
                      });
                  }
                }
                if (e.target.id === 'vn-discard') { hidePreview(); status.textContent = ''; }
              });

              fileBtn.addEventListener('click', function () { fileInp.click(); });
              fileInp.addEventListener('change', function () {
                if (fileInp.files.length) {
                  showPreview(fileInp.files[0], fileInp.files[0].name);
                  fileInp.value = '';
                }
              });

              var rec = null, chunks = [], timer = null, secs = 0;
              recBtn.addEventListener('click', function () {
                if (rec && rec.state === 'recording') { rec.stop(); return; }
                if (!navigator.mediaDevices || !window.MediaRecorder) {
                  status.textContent = 'Recording not supported here - use 📎 to upload an audio file.';
                  return;
                }
                navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
                  var mt = ['audio/ogg;codecs=opus', 'audio/webm;codecs=opus', 'audio/mp4']
                    .find(function (t) { return MediaRecorder.isTypeSupported(t); }) || '';
                  rec = new MediaRecorder(stream, mt ? { mimeType: mt } : undefined);
                  chunks = []; secs = 0;
                  rec.ondataavailable = function (e) { chunks.push(e.data); };
                  rec.onstop = function () {
                    clearInterval(timer);
                    stream.getTracks().forEach(function (t) { t.stop(); });
                    recBtn.textContent = '🎤';
                    var type = rec.mimeType || 'audio/webm';
                    var ext = type.indexOf('ogg') > -1 ? 'ogg' : (type.indexOf('mp4') > -1 ? 'm4a' : 'webm');
                    showPreview(new Blob(chunks, { type: type }), 'voice.' + ext);
                  };
                  rec.start();
                  recBtn.textContent = '⏹';
                  status.textContent = 'Recording... 0s (press ⏹ to send)';
                  timer = setInterval(function () {
                    secs++;
                    status.textContent = 'Recording... ' + secs + 's (press ⏹ to send)';
                  }, 1000);
                }).catch(function () {
                  status.textContent = 'Microphone access denied - use 📎 to upload an audio file instead.';
                });
              });
            })();
            </script>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="crm-side">
      <?php if ($isWa): ?>
        <?php
          $aiState = 'active';
          $aiLabel = 'AI is ACTIVE in this chat';
          if (!empty($conv['human_owned'])) {
              $aiState = 'off';
              $aiLabel = 'AI is OFF — humans own this chat';
          } elseif (!empty($conv['bot_paused_until']) && strtotime($conv['bot_paused_until']) > time()) {
              $aiState = 'paused';
              $aiLabel = 'AI paused until ' . date('j M, g:ia', strtotime($conv['bot_paused_until']));
          }
        ?>
        <div class="crm-card crm-panel crm-ai-card" id="ai-card">
          <h2>AI Control</h2>
          <p class="crm-ai-status <?= e($aiState) ?>"><?= e($aiLabel) ?></p>
          <?php if ($aiState === 'active'): ?>
            <form method="post" class="crm-ai-row">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <input type="hidden" name="wa_action" value="pause">
              <select name="duration">
                <option value="1">for 1 hour</option>
                <option value="6" selected>for 6 hours</option>
                <option value="24">for 24 hours</option>
                <option value="48">for 48 hours</option>
                <option value="168">for 7 days</option>
                <option value="forever">forever</option>
              </select>
              <button type="submit" class="crm-btn">⏸ Stop AI</button>
            </form>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="id" value="<?= (int)$id ?>">
              <input type="hidden" name="wa_action" value="resume">
              <button type="submit" class="crm-btn gold" style="width:100%;">▶ Re-enable AI now</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="crm-card crm-scorebox">
        <div class="num"><?= (int)$lead['score'] ?><small>/100</small></div>
        <span class="crm-q <?= e($lead['quality']) ?>"><?= e($lead['quality']) ?> lead</span>
        <div class="crm-meta" style="margin-top:12px;">
          <?= $lead['source'] === 'manual' ? 'Manually added' : 'Captured by chatbot' ?>
          <?php if (!empty($lead['channel'])): ?> &middot; <?= e(ucwords($lead['channel'])) ?><?php endif; ?><br>
          Created <?= e(date('j M Y, g:ia', strtotime($lead['created_at']))) ?><br>
          <?php if (!empty($lead['updated_at'])): ?>Updated <?= e(date('j M Y, g:ia', strtotime($lead['updated_at']))) ?><?php endif; ?>
        </div>
      </div>
      <?php if ($wa !== ''): ?>
        <a class="crm-btn wa" target="_blank" rel="noopener"
           href="https://wa.me/<?= e($wa) ?>?text=<?= rawurlencode('Hello ' . ($lead['name'] ?? '') . ', this is ' . SITE_NAME . '. Thank you for your interest in ' . ($lead['car_interest'] ?: 'our cars') . '.') ?>">
           Message on WhatsApp</a>
      <?php endif; ?>
      <?php if (!empty($lead['email'])): ?>
        <a class="crm-btn" href="mailto:<?= e($lead['email']) ?>">Send email</a>
      <?php endif; ?>
    </div>

  </div>
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
