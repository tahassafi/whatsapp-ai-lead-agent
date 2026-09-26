<?php
/* Shared helpers for the Chat Leads / CRM section */

const LEAD_STATUSES   = ['new', 'contacted', 'qualified', 'closed', 'junk'];
const LEAD_INTENTS    = ['buy', 'finance', 'viewing', 'other'];
const LEAD_TIMEFRAMES = ['now', 'this_month', '1-3_months', 'just_looking'];
const LEAD_CHANNELS   = ['walk-in', 'website', 'classifieds', 'marketplace',
                         'instagram', 'phone', 'other'];

/** Available cars for the "car of interest" picker (datalist). */
function lead_available_cars(): array
{
    return db_all("SELECT title, year, price, price_contact FROM cars
                    WHERE status = 'available' AND deleted_at IS NULL
                 ORDER BY title");
}

/** Renders the shared <datalist> for the car picker. */
function lead_cars_datalist(): void
{
    echo '<datalist id="lead-cars">';
    foreach (lead_available_cars() as $c) {
        $label = $c['price'] > 0 ? CURRENCY . ' ' . number_format((float)$c['price']) : ($c['price_contact'] ?: 'Price on request');
        echo '<option value="' . e($c['title']) . '">' . e($label) . '</option>';
    }
    echo '</datalist>';
}

/**
 * Multi-car picker: pick from stock (or free text) -> Add -> removable chip.
 * Cars are stored in `car_interest` joined by " | ". Include lead_cars_datalist()
 * on the same page.
 */
function lead_cars_picker(string $value): void
{
    ?>
    <div class="crm-phone-row">
      <input class="crm-in" type="text" id="car-pick" list="lead-cars"
             placeholder="Type to pick from stock, or free text — then Add">
      <button type="button" class="crm-btn" id="car-add">+ Add</button>
    </div>
    <div id="car-chips" class="crm-carchips"></div>
    <input type="hidden" name="car_interest" id="car-interest" value="<?= e($value) ?>">
    <script>
    (function () {
      var hid = document.getElementById('car-interest');
      var inp = document.getElementById('car-pick');
      var btn = document.getElementById('car-add');
      var box = document.getElementById('car-chips');
      var cars = hid.value ? hid.value.split(' | ').filter(Boolean) : [];
      function sync() {
        hid.value = cars.join(' | ');
        box.innerHTML = '';
        cars.forEach(function (c, i) {
          var s = document.createElement('span');
          s.className = 'crm-carchip';
          s.appendChild(document.createTextNode(c));
          var x = document.createElement('button');
          x.type = 'button';
          x.setAttribute('aria-label', 'Remove');
          x.textContent = '✕';
          x.addEventListener('click', function () { cars.splice(i, 1); sync(); });
          s.appendChild(x);
          box.appendChild(s);
        });
      }
      function add() {
        var v = inp.value.trim();
        if (!v) return;
        if (cars.indexOf(v) === -1) cars.push(v);
        inp.value = '';
        sync();
        inp.focus();
      }
      btn.addEventListener('click', add);
      inp.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); add(); }
      });
      /* anything still typed but not added gets included on save */
      inp.form.addEventListener('submit', function () {
        var v = inp.value.trim();
        if (v && cars.indexOf(v) === -1) { cars.push(v); hid.value = cars.join(' | '); }
      });
      sync();
    })();
    </script>
    <?php
}

/** Same scoring the chatbot uses - one source of truth for lead quality. */
function lead_score(array $m): array
{
    $score = 0;
    if (($m['phone'] ?? '') !== '') $score += 35;
    if (($m['email'] ?? '') !== '') $score += 15;
    if (($m['name']  ?? '') !== '') $score += 10;
    if (($m['car_interest'] ?? '') !== '') $score += 10;
    if (($m['budget'] ?? '') !== '')       $score += 10;
    $score += match ($m['timeframe'] ?? '') {
        'now'        => 20,
        'this_month' => 15,
        '1-3_months' => 8,
        default      => 0,
    };
    $score = min(100, $score);
    return [$score, $score >= 65 ? 'hot' : ($score >= 40 ? 'warm' : 'cold')];
}

function lead_quality_badge(string $q): string
{
    $color = ['hot' => '#e05545', 'warm' => '#d99b3c', 'cold' => '#6f8aa5'][$q] ?? '#888';
    return '<span style="background:' . $color . ';color:#fff;border-radius:10px;padding:2px 10px;'
         . 'font-size:11px;font-weight:700;text-transform:uppercase;">' . e($q) . '</span>';
}

/**
 * Translates a whole conversation to English via the same Claude API the
 * chatbot uses. Returns [index => english] or null on failure.
 */
function lead_translate_transcript(array $transcript): ?array
{
    if (!defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === '' || ANTHROPIC_API_KEY === 'PASTE_YOUR_KEY_HERE') {
        return null;
    }
    $items = [];
    foreach ($transcript as $i => $m) {
        $items[] = ['i' => $i, 't' => mb_substr((string)$m['content'], 0, 1500)];
    }
    if (!$items) { return []; }

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => defined('CHATBOT_MODEL') ? CHATBOT_MODEL : 'claude-haiku-4-5',
            'max_tokens' => 8000,
            'system' => 'You translate chat messages to English. Input is a JSON array of {"i": index, "t": text}. '
                      . 'Reply with ONLY a JSON array of {"i": same index, "en": English translation} - no other text, no code fences. '
                      . 'If a text is already English, return it unchanged.',
            'messages' => [['role' => 'user', 'content' => json_encode($items, JSON_UNESCAPED_UNICODE)]],
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $http !== 200) { return null; }

    $txt = json_decode($body, true)['content'][0]['text'] ?? '';
    $txt = trim(preg_replace('/^```(json)?|```$/m', '', $txt));
    $arr = json_decode($txt, true);
    if (!is_array($arr)) { return null; }

    $out = [];
    foreach ($arr as $row) {
        if (isset($row['i'], $row['en'])) { $out[(int)$row['i']] = (string)$row['en']; }
    }
    return $out;
}

/** Is the WhatsApp Cloud API configured? */
function lead_wa_configured(): bool
{
    return defined('WA_CLOUD_TOKEN') && WA_CLOUD_TOKEN !== ''
        && defined('WA_CLOUD_PHONE_ID') && WA_CLOUD_PHONE_ID !== '';
}

/**
 * Send a text to a customer AS the bot number, from the dashboard.
 * Returns true on success, or an error string.
 */
function lead_wa_send_text(string $to, string $text): bool|string
{
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(WA_CLOUD_PHONE_ID) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_CLOUD_TOKEN],
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp', 'to' => preg_replace('/\D/', '', $to),
            'type' => 'text', 'text' => ['preview_url' => true, 'body' => mb_substr($text, 0, 3900)],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body !== false && $http < 400) { return true; }
    $err = json_decode((string)$body, true)['error']['message'] ?? ('HTTP ' . $http);
    if (str_contains($err, '24') || str_contains(strtolower($err), 'window') || str_contains($err, '131047')) {
        $err .= ' (customers can only receive free-form replies within 24h of their last message)';
    }
    return $err;
}

/**
 * Send a voice/audio recording to the customer AS the bot number.
 * Accepts ogg/opus, mp3, m4a/aac; converts webm (Chrome recordings) via
 * ffmpeg when available. Returns [true, storedRelPath] or an error string.
 */
function lead_wa_send_voice(string $to, string $tmpFile, string $mime): array|string
{
    $mime = strtolower(trim($mime ?: (function_exists('mime_content_type') ? (string)mime_content_type($tmpFile) : '')));

    /* WhatsApp is picky about audio. When ffmpeg is available, normalise
       EVERYTHING to ogg/opus - the one format that reliably delivers (and
       shows as a proper voice note). Browser-recorded m4a/webm often uploads
       fine but then silently never arrives without this. */
    $ffmpeg = '';
    if (function_exists('shell_exec')) {
        $ffmpeg = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '' && is_executable('/usr/local/bin/ffmpeg')) { $ffmpeg = '/usr/local/bin/ffmpeg'; }
    }
    if ($ffmpeg !== '') {
        $out = $tmpFile . '.ogg';
        @shell_exec($ffmpeg . ' -y -i ' . escapeshellarg($tmpFile) . ' -vn -c:a libopus -b:a 32k -application voip ' . escapeshellarg($out) . ' 2>/dev/null');
        if (is_file($out) && filesize($out) > 0) {
            $tmpFile = $out;
            $mime = 'audio/ogg';
        } elseif (str_contains($mime, 'webm')) {
            return 'Audio conversion failed on the server.';
        }
    } elseif (str_contains($mime, 'webm')) {
        return 'Voice conversion needs shell_exec enabled and ffmpeg installed on the server. Until then, use the 📎 button with an mp3/m4a/ogg file.';
    }

    $extMap = ['audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3',
               'audio/mp4' => 'm4a', 'audio/aac' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/amr' => 'amr'];
    $base = explode(';', $mime)[0];
    if (!isset($extMap[$base])) { return 'Unsupported audio format (' . $mime . ') - use mp3, m4a or ogg.'; }
    if (filesize($tmpFile) > 16 * 1024 * 1024) { return 'Recording too large (max 16 MB).'; }
    $ext = $extMap[$base];

    /* keep a copy so the transcript can play it */
    $dir = ROOT_PATH . '/assets/cache/wa-voice';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $file = 'agent-' . bin2hex(random_bytes(8)) . '.' . $ext;
    @copy($tmpFile, $dir . '/' . $file);

    /* 1) upload the media to Meta */
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(WA_CLOUD_PHONE_ID) . '/media');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_CLOUD_TOKEN],
        CURLOPT_POSTFIELDS => [
            'messaging_product' => 'whatsapp',
            /* WhatsApp only renders a playable voice note (PTT) when the OGG
               upload declares the opus codec explicitly. A bare "audio/ogg"
               uploads fine but then shows "This audio is no longer available"
               on the phone. */
            'type' => $base === 'audio/ogg' ? 'audio/ogg; codecs=opus' : $base,
            'file' => new CURLFile($tmpFile, $base === 'audio/ogg' ? 'audio/ogg; codecs=opus' : $base, 'voice.' . $ext),
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $mediaId = json_decode((string)$body, true)['id'] ?? '';
    if ($http >= 400 || $mediaId === '') {
        return 'Media upload failed: ' . (json_decode((string)$body, true)['error']['message'] ?? ('HTTP ' . $http));
    }

    /* 2) send it as an audio message */
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(WA_CLOUD_PHONE_ID) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_CLOUD_TOKEN],
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp', 'to' => preg_replace('/\D/', '', $to),
            'type' => 'audio',
            /* voice:true = render as a real voice note (waveform bubble).
               Only valid for ogg/opus - mp3 with this flag breaks playback. */
            'audio' => $base === 'audio/ogg'
                ? ['id' => $mediaId, 'voice' => true]
                : ['id' => $mediaId],
        ]),
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($http >= 400) {
        return 'Send failed: ' . (json_decode((string)$body, true)['error']['message'] ?? ('HTTP ' . $http));
    }
    return [true, '/assets/cache/wa-voice/' . $file];
}

/** Reads lead fields from a submitted form, sanitised. */
function lead_from_post(): array
{
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $email = ''; }
    $intent    = (string)($_POST['intent'] ?? '');
    $timeframe = (string)($_POST['timeframe'] ?? '');
    $channel   = (string)($_POST['channel'] ?? '');
    if ($channel === 'other') {
        $channel = substr(trim(strip_tags((string)($_POST['channel_other'] ?? ''))), 0, 50) ?: 'other';
    } elseif (!in_array($channel, LEAD_CHANNELS, true)) {
        $channel = '';
    }
    return [
        'channel'      => $channel,
        'name'         => substr(trim(strip_tags((string)($_POST['name'] ?? ''))), 0, 150),
        'phone'        => substr(preg_replace('/[^0-9+ ()-]/', '', (string)($_POST['phone'] ?? '')), 0, 50),
        'email'        => substr($email, 0, 255),
        'car_interest' => substr(trim(strip_tags((string)($_POST['car_interest'] ?? ''))), 0, 1000),
        'intent'       => in_array($intent, LEAD_INTENTS, true) ? $intent : '',
        'budget'       => substr(trim(strip_tags((string)($_POST['budget'] ?? ''))), 0, 100),
        'timeframe'    => in_array($timeframe, LEAD_TIMEFRAMES, true) ? $timeframe : '',
        'notes'        => substr(trim((string)($_POST['notes'] ?? '')), 0, 5000),
        'language'     => substr(trim(strip_tags((string)($_POST['language'] ?? ''))), 0, 30),
    ];
}
