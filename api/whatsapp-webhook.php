<?php
/**
 * =============================================================================
 *  WHATSAPP AI SALES BOT  (Meta Cloud API webhook)
 * =============================================================================
 *  Customers message the dealership's WhatsApp number; the same AI that runs
 *  the website chat replies here - same knowledge base, same live inventory,
 *  same lead CRM. Extras for WhatsApp:
 *
 *   - No gate form: the customer's number + WhatsApp name arrive automatically,
 *     so every conversation is a lead from message one (source: whatsapp).
 *   - PDF brochures: when someone asks for photos/specs/brochure, the bot sends
 *     the car's PDF (cars.brochure_url) as a WhatsApp document.
 *   - Human takeover (Coexistence): the moment anyone on the team replies from
 *     the WhatsApp Business app, the bot goes silent for that customer
 *     (echoes of app-sent messages pause it).
 *
 *  CONFIG: see .env.example (WA_CLOUD_TOKEN, WA_CLOUD_PHONE_ID,
 *  WA_WEBHOOK_VERIFY_TOKEN, WA_APP_SECRET).
 *
 *  META APP SETUP: WhatsApp > Configuration > set Callback URL to
 *  https://your-domain.example/api/whatsapp-webhook.php with your verify token,
 *  then subscribe to the "messages" and "smb_message_echoes" webhook fields.
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/functions.php';
if (is_file(__DIR__ . '/../includes/chatbot-notify.php')) {
    require_once __DIR__ . '/../includes/chatbot-notify.php';
}

/* ---- Webhook verification handshake (Meta calls this once with GET) -------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
    if (($_GET['hub_mode'] ?? '') === 'subscribe'
        && defined('WA_WEBHOOK_VERIFY_TOKEN')
        && hash_equals(WA_WEBHOOK_VERIFY_TOKEN, (string)($_GET['hub_verify_token'] ?? ''))) {
        echo $_GET['hub_challenge'] ?? '';
    } else {
        http_response_code(403);
    }
    exit;
}

$raw = file_get_contents('php://input') ?: '';

/* ---- Optional but recommended: verify the payload really came from Meta ----- */
if (defined('WA_APP_SECRET') && WA_APP_SECRET !== '') {
    $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if (!hash_equals('sha256=' . hash_hmac('sha256', $raw, WA_APP_SECRET), $sig)) {
        http_response_code(403);
        exit;
    }
}

/* Ack Meta immediately so it never retries, then keep processing */
http_response_code(200);
echo 'OK';
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    @ob_end_flush();
    @flush();
}
ignore_user_abort(true);
set_time_limit(120);

$payload = json_decode($raw, true) ?: [];
$change  = $payload['entry'][0]['changes'][0] ?? [];
$field   = (string)($change['field'] ?? '');
$value   = $change['value'] ?? [];

/** Mark a number as belonging to the human team - the bot never talks to it. */
function wa_mark_human_owned(string $phone): void
{
    $phone = preg_replace('/\D/', '', $phone);
    if ($phone === '') { return; }
    $exists = db_one("SELECT id FROM chat_conversations WHERE wa_phone = ?", [$phone]);
    if ($exists) {
        db_run("UPDATE chat_conversations SET human_owned = 1 WHERE id = ?", [(int)$exists['id']]);
    } else {
        db_run("INSERT INTO chat_conversations (session_token, wa_phone, user_agent, page_url, human_owned)
                VALUES (?,?,?,?,1)", [bin2hex(random_bytes(16)), $phone, 'whatsapp', 'human-owned']);
    }
}

/* ---- Team replied from the app: that customer belongs to humans, forever ---- */
$echoes = $value['message_echoes'] ?? [];
if ($echoes) {
    foreach ($echoes as $echo) {
        wa_mark_human_owned((string)($echo['to'] ?? ''));
    }
    exit;
}

/* ---- Coexistence history sync: every number from past chats = human-owned ---- */
if ($field === 'history' || $field === 'smb_app_state_sync') {
    $seen = [];
    array_walk_recursive($value, function ($v, $k) use (&$seen) {
        if (in_array($k, ['wa_id', 'from', 'to'], true)) {
            $p = preg_replace('/\D/', '', (string)$v);
            if (strlen($p) >= 8 && strlen($p) <= 15) { $seen[$p] = true; }
        }
    });
    foreach (array_keys($seen) as $p) { wa_mark_human_owned($p); }
    exit;
}

/* Only genuine incoming customer messages from here on */
if ($field !== 'messages') { exit; }

$msg = $value['messages'][0] ?? null;
if (!$msg) { exit; }                                   // status updates etc.

if (!defined('CHATBOT_ENABLED') || !CHATBOT_ENABLED
    || !defined('ANTHROPIC_API_KEY') || ANTHROPIC_API_KEY === ''
    || !defined('WA_CLOUD_TOKEN') || WA_CLOUD_TOKEN === ''
    || !defined('WA_CLOUD_PHONE_ID') || WA_CLOUD_PHONE_ID === '') {
    exit;
}

$fromPhone = preg_replace('/\D/', '', (string)($msg['from'] ?? ''));
$wamid     = (string)($msg['id'] ?? '');
$waName    = trim((string)($value['contacts'][0]['profile']['name'] ?? ''));
if ($fromPhone === '') { exit; }

/* What did they send? */
$userMsg = '';
$imageMedia = null;   // ['mime' =>, 'b64' =>] when the customer sends a photo
$imagePath  = '';     // saved copy of that photo, for the dashboard transcript
switch ($msg['type'] ?? '') {
    case 'text':
        $userMsg = trim((string)($msg['text']['body'] ?? ''));
        break;
    case 'button':
        $userMsg = trim((string)($msg['button']['text'] ?? ''));
        break;
    case 'interactive':
        $userMsg = trim((string)($msg['interactive']['button_reply']['title']
                 ?? $msg['interactive']['list_reply']['title'] ?? ''));
        break;
    case 'image':
        /* Customers often send a photo/screenshot of a car they saw.
           The AI can SEE images - download it from Meta and pass it along. */
        $imageMedia = wa_fetch_media((string)($msg['image']['id'] ?? ''));
        $caption    = trim((string)($msg['image']['caption'] ?? ''));
        if ($imageMedia) {
            /* keep a copy on disk so the dashboard transcript can SHOW the
               photo (before this, admins only saw the text "[Photo] ...") */
            $dir = ROOT_PATH . '/assets/cache/wa-img';
            if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
            $iext  = str_contains($imageMedia['mime'], 'png') ? 'png'
                   : (str_contains($imageMedia['mime'], 'webp') ? 'webp' : 'jpg');
            $ifile = 'cust-' . bin2hex(random_bytes(8)) . '.' . $iext;
            if (@file_put_contents($dir . '/' . $ifile, base64_decode($imageMedia['b64']))) {
                $imagePath = '/assets/cache/wa-img/' . $ifile;
            }
            $userMsg = $caption !== '' ? $caption
                     : '(The customer sent a photo of a car with no caption - identify which of our cars it is and help them.)';
        } else {
            wa_send_text($fromPhone, "I couldn't open that image 🙈 Could you resend it, or just tell me which car it is?");
            exit;
        }
        break;
    case 'video':
        wa_send_text($fromPhone, "I can't watch videos here just yet 🙂 Send me a screenshot from it, or tell me which car it is, and I'll get you everything - price, specs, photos.");
        exit;
    case 'audio':
        /* voice message: save it so the dashboard can play it, then escalate */
        $voicePath = wa_save_voice((string)($msg['audio']['id'] ?? ''));
        $userMsg = $voicePath ? '[voice] ' . $voicePath : '(sent a voice message)';
        $voiceEscalate = true;
        break;
    default:
        /* stickers, documents, contacts... */
        wa_send_text($fromPhone, "I can read text and photos here 🙂 Tell me which car you're interested in, or send a picture of it!");
        exit;
}
if ($userMsg === '' || mb_strlen($userMsg) > 2000) {
    exit;
}

/* Customers paste links to our own car pages - resolve them server-side so
   the AI knows EXACTLY which car it is (no vision, no guessing). */
$siteHost = preg_quote((string)parse_url(SITE_URL, PHP_URL_HOST), '#');
if ($siteHost !== ''
    && preg_match_all('#https?://(?:www\.)?' . $siteHost . '/([a-z0-9-]+)/([A-Za-z0-9_-]+)/?#i', $userMsg, $mm, PREG_SET_ORDER)) {
    $linkNotes = [];
    foreach (array_slice($mm, 0, 3) as $m2) {
        $car = db_one("SELECT title, year, mileage, price, price_contact, status
                         FROM cars WHERE slug_prefix = ? AND ref_number = ? AND deleted_at IS NULL",
                      [strtolower($m2[1]), $m2[2]]);
        if ($car) {
            $price = $car['price'] > 0 ? CURRENCY . ' ' . number_format((float)$car['price'])
                                       : ($car['price_contact'] ?: 'Price on request');
            $linkNotes[] = $car['title'] . ($car['year'] ? ' (' . $car['year'] . ')' : '')
                         . ' - ' . $price
                         . ($car['mileage'] !== null ? ' - ' . number_format((int)$car['mileage']) . ' km' : '')
                         . ' - status: ' . $car['status'];
        }
    }
    if ($linkNotes) {
        $userMsg .= "\n\n[SYSTEM NOTE - the link(s) the customer sent are these cars from our stock: "
                  . implode('; ', $linkNotes) . ']';
    }
}

/* ---- Find or create the conversation + lead --------------------------------- */

$conv = db_one("SELECT * FROM chat_conversations WHERE wa_phone = ?", [$fromPhone]);

/* dedupe: Meta occasionally redelivers the same message */
if ($conv && $wamid !== '' && $conv['last_wamid'] === $wamid) { exit; }

$isNew = false;
if (!$conv) {
    $isNew = true;
    db_run("INSERT INTO chat_conversations (session_token, wa_phone, wa_name, ip, user_agent, page_url)
            VALUES (?,?,?,?,?,?)",
           [bin2hex(random_bytes(16)), $fromPhone, $waName ?: null, null, 'whatsapp', 'whatsapp']);
    $conv = db_one("SELECT * FROM chat_conversations WHERE wa_phone = ?", [$fromPhone]);

    /* every WhatsApp conversation is a lead with a verified number */
    db_run("INSERT INTO chat_leads (conversation_id, source, name, phone, score, quality)
            VALUES (?,?,?,?,?,?)",
           [(int)$conv['id'], 'whatsapp', $waName ?: null, '+' . $fromPhone, 45, 'warm']);

    if (function_exists('chatbot_wa_notify')) {
        chatbot_wa_notify("💬 New customer chatting with the AI right now\n"
            . "Name: " . ($waName ?: 'Unknown') . " +" . $fromPhone . "\n"
            . "First message: " . mb_substr($userMsg, 0, 120) . "\n"
            . "Tap to chat: https://wa.me/" . $fromPhone . "\n"
            . "Transcript: " . SITE_URL . "/admin/chat-leads/");
    }
}
$convId = (int)$conv['id'];

/* Self-healing: if the lead row was deleted from the dashboard but the
   conversation lives on, recreate the lead so the chat stays visible. */
if (!$isNew && !db_one("SELECT id FROM chat_leads WHERE conversation_id = ?", [$convId])) {
    db_run("INSERT INTO chat_leads (conversation_id, source, name, phone, score, quality)
            VALUES (?,?,?,?,?,?)",
           [$convId, 'whatsapp', $waName ?: null, '+' . $fromPhone, 45, 'warm']);
}

db_run("UPDATE chat_conversations SET last_wamid = ?, last_active = NOW() WHERE id = ?", [$wamid, $convId]);
/* Photos are stored as "[photo] /path" (+ caption on the next line) so the
   dashboard can render the actual image instead of a "[Photo]" placeholder. */
$storedMsg = $userMsg;
if ($imageMedia) {
    $storedMsg = $imagePath !== ''
        ? '[photo] ' . $imagePath . ($caption !== '' ? "\n" . $caption : '')
        : '[Photo] ' . $userMsg;
}
db_run("INSERT INTO chat_messages (conversation_id, role, content) VALUES (?, 'user', ?)",
       [$convId, $storedMsg]);

/* human-owned = this customer belongs to the team, the bot never replies */
if (!empty($conv['human_owned'])) {
    exit;
}

/* paused = a human already took over this chat */
if (!empty($conv['bot_paused_until']) && strtotime($conv['bot_paused_until']) > time()) {
    exit;
}

/* Voice message: alert the team (no auto-pause - AI stays available) */
if (!empty($voiceEscalate)) {
    if (function_exists('chatbot_wa_notify')) {
        chatbot_wa_notify("🎤 Voice message - human needed\nCustomer: " . ($waName ?: 'Unknown') . " +" . $fromPhone
            . "\nTap to chat: https://wa.me/" . $fromPhone);
    }
    wa_mark_read($wamid);
    wa_send_text($fromPhone, "Thanks for the voice note! 🎤 A member of our sales team will listen and reply to you right here shortly.");
    exit;
}

/* basic rate limit per customer - tell them once, then go quiet */
$limit = defined('CHATBOT_RATE_LIMIT') ? (int)CHATBOT_RATE_LIMIT : 30;
$sent  = db_one("SELECT COUNT(*) n FROM chat_messages
                 WHERE conversation_id = ? AND role = 'user'
                   AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$convId]);
$n = (int)($sent['n'] ?? 0);
if ($n > $limit) {
    if ($n === $limit + 1) {
        wa_send_text($fromPhone, "You've got lots of great questions! 🙌 One of our sales team will take over from here and reply personally in a few minutes.");
    }
    exit;
}

wa_mark_read($wamid);

/* ---- Build the AI context (mirrors api/chat.php, tuned for WhatsApp) --------- */

$histLimit = defined('CHATBOT_HISTORY_LIMIT') ? (int)CHATBOT_HISTORY_LIMIT : 24;
$rows = db_all(
    "SELECT role, content FROM (
        SELECT id, role, content FROM chat_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT $histLimit
     ) t ORDER BY id ASC", [$convId]);
$messages = [];
foreach ($rows as $r) {
    /* 'agent' rows are dashboard replies by the sales team - the AI sees them
       as its own side of the conversation */
    $messages[] = ['role' => $r['role'] === 'user' ? 'user' : 'assistant', 'content' => $r['content']];
}

/* If this turn is a photo, give the AI the actual image alongside the text */
if ($imageMedia && $messages) {
    $messages[count($messages) - 1]['content'] = [
        ['type' => 'image', 'source' => [
            'type' => 'base64',
            'media_type' => $imageMedia['mime'],
            'data' => $imageMedia['b64'],
        ]],
        ['type' => 'text', 'text' => $userMsg],
    ];
}

$stats  = db_one("SELECT COUNT(*) n, MIN(price) lo, MAX(price) hi
                    FROM cars WHERE status = 'available' AND deleted_at IS NULL AND price > 0");
$allCars = db_all("SELECT title, slug_prefix, ref_number, year, mileage, price, price_contact, brochure_url, exterior_color
                     FROM cars WHERE status = 'available' AND deleted_at IS NULL
                 ORDER BY brand_slug, title");
$stockList = '';
if (count($allCars) <= 300) {
    $lines = [];
    foreach ($allCars as $c) {
        $price = $c['price'] > 0 ? CURRENCY . ' ' . number_format((float)$c['price'])
                                 : ($c['price_contact'] ?: 'Price on request');
        $condition = '';
        if ($c['mileage'] !== null) {
            $condition = ((int)$c['mileage'] < 1000 ? ' | BRAND NEW (' : ' | USED (')
                       . number_format((int)$c['mileage']) . ' km)';
        }
        $lines[] = '- ' . $c['title'] . ($c['year'] ? ' (' . $c['year'] . ')' : '')
                 . ' | ' . $price
                 . $condition
                 . (!empty($c['exterior_color']) ? ' | ' . $c['exterior_color'] : '')
                 . ' | ' . rtrim(SITE_URL, '/') . '/' . $c['slug_prefix'] . '/' . $c['ref_number'] . '/'
                 . (!empty($c['brochure_url']) ? ' | PDF available' : '');
    }
    $stockList = implode("\n", $lines);
}

$lead = db_one("SELECT name, phone, car_interest, budget, timeframe FROM chat_leads WHERE conversation_id = ?", [$convId]);

$systemPrompt = "You are the WhatsApp sales assistant of " . SITE_NAME . ", " . BUSINESS_TAGLINE . ". You chat like a warm, sharp human salesperson - never like a robot.\n\n"
    . "SHOWROOM FACTS (use these, never invent others):\n"
    . "- Address: " . CONTACT_ADDRESS . "\n"
    . "- Phone: " . CONTACT_PHONE . " | Email: " . CONTACT_EMAIL . "\n"
    . "- Website: " . SITE_URL . "\n"
    . "- Cars in stock: " . (int)($stats['n'] ?? 0) . " (roughly " . CURRENCY . " " . number_format((float)($stats['lo'] ?? 0)) . " to " . CURRENCY . " " . number_format((float)($stats['hi'] ?? 0)) . ")\n\n"
    . "HOW TO SELL:\n"
    . "0. In your FIRST message of a conversation, briefly introduce yourself as the showroom's *AI assistant* (one short phrase - customers should know they're not talking to a human yet), then get straight to helping.\n"
    . "1. Read the customer's FIRST message for intent. If they mention a car, brand or budget, respond immediately with the matching cars from stock - names and prices - then ask ONE follow-up question. If the intent is unclear, greet briefly and ask what they're looking for.\n"
    . "1b. When we have SEVERAL of a model, give the overview first - count, year range, price range (e.g. 'We have 3 G63s, 2021 to 2025, from " . CURRENCY . " 780,000 to " . CURRENCY . " 999,999') - lead with the NEWEST, then ask which year, color or budget they prefer.\n"
    . "2. When they ask about a specific car's specs (mileage, engine, transmission, colors, options...), call get_car_details and answer from it precisely - it returns ALL versions we have, so check every one before saying a color or year doesn't exist. When they ask for photos or the brochure, call send_car_pdf with a query specific enough to hit the right version (include year/color). If a car has no PDF, say the sales team will send full photos shortly.\n"
    . "2e. PHOTOS vs PDF: on a FIRST request for photos/pictures, send the brochure (send_car_pdf) - it contains all photos and specs. But if the customer wants ACTUAL IMAGES - they say 'not the pdf', 'send real pictures', or ask for photos again right after getting the brochure - call send_car_photos, which drops the full listing gallery into the chat as real photos. Never argue about format; just give them what they prefer.\n"
    . "2b. When they ask for a VIDEO of a car, call send_car_video - it delivers the video directly. If it reports no video, call request_followup and say the sales team will record and send one personally.\n"
    . "2d. LINKS FROM CUSTOMERS: links to OUR website are resolved for you - a [SYSTEM NOTE] in the message tells you exactly which car it is; answer from that with full confidence. Links to Instagram, TikTok, YouTube or any other site: you CANNOT open them - never guess what's behind one. Say you can't open links and ask for a screenshot or the car's name instead.\n"
    . "2c. PHOTOS FROM CUSTOMERS: customers often send a photo or screenshot of a car they saw. LOOK at the image carefully: model, BODY STYLE (coupe vs convertible - e.g. Ferrari 812 Superfast is the coupe, 812 GTS is the convertible), COLOR, kit/tuner, and READ any visible text. Then VERIFY before confirming: find candidate cars in the FULL STOCK LIST and check the candidate's listed COLOR against what you SEE. If the photo shows a yellow car, it is NOT our black one - a color or body-style mismatch means it's NOT that car. Only confirm a car when color and model both match. If nothing matches exactly, say honestly which model it looks like, show our similar cars with their colors, and ask one clarifying question. A wrong confident answer destroys trust - being unsure is fine, being wrong is not.\n"
    . "3. Qualify naturally over the conversation: which car -> budget -> how soon. Call save_lead whenever you learn or can infer something new (car_interest, budget, timeframe, intent, language, and keep a 1-2 line notes summary for the sales team).\n"
    . "4. When a customer is clearly serious (viewing request, buying steps, finance help): FIRST call request_followup with what they need, THEN tell them a salesperson will follow up right here on WhatsApp shortly. NEVER promise that the team will contact them or send them anything without calling request_followup first - a promise without the tool call means nobody is actually notified.\n"
    . "5. NEVER flatly say 'we don't have that'. You're a salesperson: always pivot to the closest cars we DO have (same model other years, same segment, similar budget, other tuner kits) and sell them with enthusiasm.\n"
    . "6. ESCALATION: if the customer asks for a human, is frustrated, wants to negotiate, or asks something you can't answer - offer to connect them with the team. Once they agree (or if they're clearly upset), call escalate_to_human, warmly confirm a colleague will join shortly, and keep helping normally until the salesperson actually takes over.\n"
    . "7. OFF-TOPIC - ZERO ENTERTAINMENT POLICY: you exist for ONE thing - helping customers buy our cars. Anything else (selling us cars, jobs, marketing/SEO offers, partnerships, influencer requests, personal chat, jokes, other businesses): call mark_off_topic and reply with ONE short polite line that you can only help with buying our cars. NEVER give out the showroom's email, phone number, or any contact route as an alternative for off-topic requests - contact details are for car buyers only. NEVER ask a follow-up question on an off-topic subject, never explain at length, never continue the thread. One line, done. If the tool returns final=true, send one brief goodbye and keep any further off-topic replies to a single short line.\n\n"
    . "RULES:\n"
    . "- NEVER write a URL from memory. The ONLY links you may send are ones that appear in a tool result, the FULL STOCK LIST, or the DEALERSHIP KNOWLEDGE BASE below, copied EXACTLY. If you don't have a link for something, don't invent one - use the tools or call request_followup.\n"
    . "- ALWAYS answer spec questions directly. If the customer asks mileage, color, engine, options - even AFTER you sent a brochure - call get_car_details and give the exact answer warmly. NEVER tell them to 'check the PDF' or 'see the brochure' instead of answering.\n"
    . "- NEVER mention systems, tools, searches, or failures ('the system isn't finding...', 'let me try again with the exact title'). If something can't be sent or found, glide over it like a human would: offer the closest alternative or say the sales team will send it personally in a few minutes.\n"
    . "- Reply in the customer's language, always.\n"
    . "- SHORT messages: 1-3 sentences. This is WhatsApp - nobody reads essays.\n"
    . "- DIRECT ANSWERS: when a customer asks a short confirmation question ('GCC?', 'available?', 'automatic?'), lead with the direct answer in the first word: 'Yes - GCC spec ✅'. Do NOT explain what a term means unless they explicitly ask what it means. Read short questions in the context of what was just discussed.\n"
    . "- WhatsApp formatting only: SINGLE *bold* (never markdown **), _italic_, plain URLs. NO markdown links like [text](url) - paste the URL directly. Use bold sparingly: the car name and price, nothing more.\n"
    . "- Prices in " . CURRENCY . ", formatted like " . CURRENCY . " 1,250,000. Never invent cars, specs or prices - use the stock list and search_cars only.\n"
    . "- Never quote discounts or final prices - the sales team confirms those.\n"
    . "- SELLERS: the INSTANT someone offers to sell us a car or asks about trade-ins, reply with ONE short polite line: we don't buy cars or take trade-ins, we only sell our own stock. NO follow-up question, no pivot, no asking about their car. Also call mark_off_topic. Only re-engage if THEY then ask about buying from us.\n"
    . "- JOB SEEKERS: the INSTANT someone asks about jobs, hiring, CVs, or working for us, reply ONLY with a short polite 'sorry, I can't help with job enquiries'. DO NOT give them any email address, phone number, or alternative contact - not the info address, not the showroom number, nothing. NO follow-up question. Also call mark_off_topic.\n"
    . "- Tuners: Onyx, Brabus, Mansory, Novitec, Lumma are kit builders. A G63 by Onyx IS a G63. If we lack the requested tuner, offer our similar kitted cars.\n"
    . "- Off-topic questions: steer back to cars politely.\n";

if ($stockList !== '') {
    $systemPrompt .= "\nFULL STOCK LIST (every car available right now - title | price | link | PDF availability). You know this list like the showroom floor. Never say we don't have something that's on it:\n" . $stockList . "\n";
}

$kbFile = ROOT_PATH . '/data/chatbot-knowledge.txt';
if (is_file($kbFile)) {
    $kb = trim((string)file_get_contents($kbFile));
    if ($kb !== '') {
        $systemPrompt .= "\nDEALERSHIP KNOWLEDGE BASE (finance, leasing, policies - answer from this; numbers are indicative, final terms come from the bank/sales team):\n" . mb_substr($kb, 0, 24000) . "\n";
    }
}

if ($lead) {
    $systemPrompt .= "\nKNOWN CUSTOMER INFO (never re-ask): Name: " . ($lead['name'] ?: ($waName ?: 'unknown'))
        . " | WhatsApp: " . ($lead['phone'] ?? '')
        . (!empty($lead['car_interest']) ? " | Interested in: " . $lead['car_interest'] : '')
        . (!empty($lead['budget']) ? " | Budget: " . $lead['budget'] : '')
        . (!empty($lead['timeframe']) ? " | Timeframe: " . $lead['timeframe'] : '')
        . "\nGreet them by first name when natural.\n";
}

/* ---- Tools ------------------------------------------------------------------- */

$tools = [
    [
        'name' => 'search_cars',
        'description' => 'Search the live showroom inventory (available cars only).',
        'input_schema' => ['type' => 'object', 'properties' => [
            'query'     => ['type' => 'string', 'description' => 'Words that must all appear in the title, any order'],
            'brand'     => ['type' => 'string', 'description' => 'Brand slug, e.g. "rolls-royce", "mercedes-benz"'],
            'max_price' => ['type' => 'number'],
            'min_price' => ['type' => 'number'],
            'sort'      => ['type' => 'string', 'enum' => ['price_asc', 'price_desc', 'newest']],
        ]],
    ],
    [
        'name' => 'get_car_details',
        'description' => 'Get the FULL spec sheets of matching cars (up to 5, newest first): mileage, engine, horsepower, transmission, colors, featured options and description. Returns ALL versions of a model, so use it to compare years/colors. ALWAYS call this before saying a color, year or spec is not available.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Words from the car title; can include a color or year, e.g. "turbo s black", "812 gts onyx"'],
        ], 'required' => ['query']],
    ],
    [
        'name' => 'send_car_pdf',
        'description' => 'Send the PDF brochure of ONE specific car to the customer on WhatsApp. Use when they ask for photos, specs or a brochure. Make the query specific enough to hit the right car - include the year and/or color when several versions exist (e.g. "turbo s 2023 black").',
        'input_schema' => ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Words from the car title + year and/or color to disambiguate'],
        ], 'required' => ['query']],
    ],
    [
        'name' => 'send_car_photos',
        'description' => 'Send the FULL photo gallery of ONE specific car directly into the chat as images. Use when the customer explicitly wants real pictures instead of the PDF (e.g. after receiving the brochure they say they want images, or they say no pdf). For a FIRST photos request, prefer send_car_pdf - the brochure has everything.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Words from the car title + year/color to hit the right version'],
        ], 'required' => ['query']],
    ],
    [
        'name' => 'send_car_video',
        'description' => 'Send the video of ONE specific car to the customer. Use whenever they ask for a video. Never paste video links yourself - always use this tool.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'query' => ['type' => 'string', 'description' => 'Words from the car title + year/color to hit the right version'],
        ], 'required' => ['query']],
    ],
    [
        'name' => 'request_followup',
        'description' => 'Alert the sales team that this customer needs a human follow-up (viewing request, photos/videos to send personally, finance help, serious buyer). Does NOT pause you - keep chatting. You MUST call this BEFORE ever telling the customer that the sales team will contact them or send them something.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'reason' => ['type' => 'string', 'description' => 'What the customer needs, e.g. "wants viewing of 2024 G63", "needs photos of Cullinan"'],
        ], 'required' => ['reason']],
    ],
    [
        'name' => 'escalate_to_human',
        'description' => 'Alert the sales team that a human must take over this chat, and step aside. Call when: the customer asks for a human, is frustrated or complaining, wants to negotiate price, or asks something you cannot help with and agreed to be connected.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'reason' => ['type' => 'string', 'description' => 'Short reason, e.g. "price negotiation", "complaint"'],
        ]],
    ],
    [
        'name' => 'mark_off_topic',
        'description' => 'Call whenever the customer\'s message has nothing to do with our cars or buying from the showroom (spam, other business offers, personal chat, jokes). Then still reply with one short polite line.',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
    ],
    [
        'name' => 'save_lead',
        'description' => 'Update the customer\'s lead record with anything new you learned or inferred.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'name'         => ['type' => 'string'],
            'car_interest' => ['type' => 'string'],
            'intent'       => ['type' => 'string', 'enum' => ['buy', 'finance', 'viewing', 'other']],
            'budget'       => ['type' => 'string'],
            'timeframe'    => ['type' => 'string', 'enum' => ['now', 'this_month', '1-3_months', 'just_looking']],
            'notes'        => ['type' => 'string'],
            'language'     => ['type' => 'string'],
        ]],
    ],
];

/** Title matching that understands years: "g63 2025" matches a 2025 G63 even
 *  though "2025" isn't in the title (checks year and ref number too). */
function wa_car_words(string $query, array &$where, array &$params): void
{
    foreach (preg_split('/\s+/', trim($query)) ?: [] as $w) {
        if ($w === '') { continue; }
        if (preg_match('/^(19|20)\d{2}$/', $w)) {
            $where[]  = '(year = ? OR title LIKE ? OR ref_number = ?)';
            array_push($params, (int)$w, "%$w%", $w);
        } else {
            /* words also match the exterior color, so "turbo s black" finds
               the black one among several Turbo S cars */
            $where[]  = '(title LIKE ? OR exterior_color LIKE ?)';
            array_push($params, "%$w%", "%$w%");
        }
    }
}

function wa_tool_search_cars(array $in): array
{
    $where  = ["status = 'available'", 'deleted_at IS NULL'];
    $params = [];
    if (!empty($in['brand'])) {
        $slug = strtolower(trim((string)$in['brand']));
        $where[] = '(brand_slug = ? OR brand_slug_2 = ?)';
        array_push($params, $slug, $slug);
    }
    if (!empty($in['query'])) {
        wa_car_words((string)$in['query'], $where, $params);
    }
    if (!empty($in['max_price'])) { $where[] = 'price > 0 AND price <= ?'; $params[] = (float)$in['max_price']; }
    if (!empty($in['min_price'])) { $where[] = 'price >= ?';               $params[] = (float)$in['min_price']; }
    $order = match ($in['sort'] ?? '') {
        'price_asc'  => 'price IS NULL, price ASC',
        'price_desc' => 'price DESC',
        default      => 'created_at DESC',
    };
    $rows = db_all('SELECT title, slug_prefix, ref_number, year, mileage, price, price_contact,
                           engine, horsepower, exterior_color, brochure_url
                      FROM cars WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT 6", $params);
    return ['showing' => count($rows), 'cars' => array_map(fn($c) => [
        'title'   => $c['title'],
        'year'    => $c['year'],
        'price'   => $c['price'] > 0 ? CURRENCY . ' ' . number_format((float)$c['price']) : ($c['price_contact'] ?: 'Price on request'),
        'mileage' => $c['mileage'] !== null ? number_format((int)$c['mileage']) . ' km' : null,
        'color'   => $c['exterior_color'],
        'link'    => rtrim(SITE_URL, '/') . '/' . $c['slug_prefix'] . '/' . $c['ref_number'] . '/',
        'has_pdf' => !empty($c['brochure_url']),
    ], $rows)];
}

function wa_tool_get_car_details(array $in): array
{
    $where  = ["status = 'available'", 'deleted_at IS NULL'];
    $params = [];
    wa_car_words((string)($in['query'] ?? ''), $where, $params);
    /* ALL matching versions, newest first - so colors/years can be compared */
    $rows = db_all('SELECT title, slug_prefix, ref_number, year, mileage, price, price_contact,
                           engine, horsepower, transmission, drive_type, exterior_color,
                           interior_color, featured_options, overview, brochure_url, video_url
                      FROM cars WHERE ' . implode(' AND ', $where) . ' ORDER BY year DESC LIMIT 5', $params);
    if (!$rows) { return ['found' => false]; }
    $clean = fn(?string $s, int $len) =>
        mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)$s))), 0, $len);
    $cars = array_map(fn($car) => [
        'title'            => $car['title'],
        'year'             => $car['year'],
        'mileage'          => $car['mileage'] !== null ? number_format((int)$car['mileage']) . ' km' : null,
        'price'            => $car['price'] > 0 ? CURRENCY . ' ' . number_format((float)$car['price']) : ($car['price_contact'] ?: 'Price on request'),
        'engine'           => $car['engine'],
        'horsepower'       => $car['horsepower'],
        'transmission'     => $car['transmission'],
        'drive_type'       => $car['drive_type'],
        'exterior_color'   => $car['exterior_color'],
        'interior_color'   => $car['interior_color'],
        'featured_options' => $clean($car['featured_options'], 800),
        'overview'         => $clean($car['overview'], 900),
        'link'             => rtrim(SITE_URL, '/') . '/' . $car['slug_prefix'] . '/' . $car['ref_number'] . '/',
        'has_pdf'          => !empty($car['brochure_url']),
        'video'            => $car['video_url'] ?: null,
    ], $rows);
    return ['found' => true, 'matches' => count($cars), 'cars' => $cars];
}

function wa_tool_send_car_pdf(array $in, string $toPhone): array
{
    $where  = ["status = 'available'", 'deleted_at IS NULL'];
    $params = [];
    wa_car_words((string)($in['query'] ?? ''), $where, $params);
    $car = db_one('SELECT title, price, price_contact, brochure_url FROM cars WHERE '
                . implode(' AND ', $where) . ' ORDER BY year DESC LIMIT 1', $params);
    if (!$car) { return ['sent' => false, 'reason' => 'no matching car']; }
    if (empty($car['brochure_url'])) { return ['sent' => false, 'reason' => 'this car has no PDF brochure - offer that the sales team sends photos shortly']; }

    $url = $car['brochure_url'];
    if (!str_starts_with($url, 'http')) { $url = rtrim(SITE_URL, '/') . '/' . ltrim($url, '/'); }
    $price = $car['price'] > 0 ? CURRENCY . ' ' . number_format((float)$car['price']) : ($car['price_contact'] ?: 'Price on request');
    $ok = wa_send_document($toPhone, $url, preg_replace('/[^A-Za-z0-9 ._-]/', '', $car['title']) . '.pdf',
                           $car['title'] . ' — ' . $price);
    return $ok ? ['sent' => true, 'car' => $car['title']]
               : ['sent' => false, 'reason' => 'sending failed - offer the sales team follow-up'];
}

function wa_tool_send_car_photos(array $in, string $toPhone): array
{
    $where  = ["status = 'available'", 'deleted_at IS NULL'];
    $params = [];
    wa_car_words((string)($in['query'] ?? ''), $where, $params);
    $car = db_one('SELECT title, year, price, price_contact, thumbnail, gallery FROM cars WHERE '
                . implode(' AND ', $where) . ' ORDER BY year DESC LIMIT 1', $params);
    if (!$car) { return ['sent' => false, 'reason' => 'no matching car']; }

    $imgs = json_array($car['gallery'] ?? null);
    if (!$imgs && !empty($car['thumbnail'])) { $imgs = [$car['thumbnail']]; }
    if (!$imgs) {
        return ['sent' => false, 'reason' => 'no photos on file - call request_followup so the sales team sends photos personally'];
    }

    $price = $car['price'] > 0 ? CURRENCY . ' ' . number_format((float)$car['price']) : ($car['price_contact'] ?: 'Price on request');
    $sent = 0;
    foreach (array_slice($imgs, 0, 30) as $i => $img) {   // full listing gallery (sane hard cap)
        $rel = car_image_url((string)$img);
        if ($rel === '') { continue; }
        /* WhatsApp only accepts JPEG/PNG - route webp through the converter */
        if (preg_match('/\.webp$/i', $rel) && !str_starts_with($rel, 'http')) {
            $url = rtrim(SITE_URL, '/') . '/api/car-photo.php?p=' . rawurlencode($rel);
        } elseif (!str_starts_with($rel, 'http')) {
            $url = rtrim(SITE_URL, '/') . '/' . ltrim($rel, '/');
        } else {
            $url = $rel;
        }
        $payload = ['messaging_product' => 'whatsapp', 'to' => $toPhone, 'type' => 'image',
                    'image' => ['link' => $url]];
        if ($i === 0) { $payload['image']['caption'] = mb_substr($car['title'] . ' — ' . $price, 0, 1000); }
        if (wa_api(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))['ok']) { $sent++; }
        usleep(250000);   // slight pacing so photos arrive in order
    }
    return $sent > 0
        ? ['sent' => true, 'photos_sent' => $sent, 'car' => $car['title']]
        : ['sent' => false, 'reason' => 'sending failed - call request_followup so the team sends photos personally'];
}

function wa_tool_send_car_video(array $in, string $toPhone): array
{
    $where  = ["status = 'available'", 'deleted_at IS NULL'];
    $params = [];
    wa_car_words((string)($in['query'] ?? ''), $where, $params);
    $car = db_one('SELECT title, year, video_url FROM cars WHERE '
                . implode(' AND ', $where) . ' ORDER BY year DESC LIMIT 1', $params);
    if (!$car) { return ['sent' => false, 'reason' => 'no matching car']; }
    if (empty($car['video_url'])) {
        return ['sent' => false,
                'reason' => 'this car has no video on file - call request_followup so the sales team records and sends one personally'];
    }
    $url = $car['video_url'];
    if (!str_starts_with($url, 'http')) { $url = rtrim(SITE_URL, '/') . '/' . ltrim($url, '/'); }
    /* direct mp4 -> native WhatsApp video; anything else (YouTube...) -> link with preview */
    if (preg_match('/\.mp4(\?|$)/i', $url)) {
        $ok = wa_api(json_encode([
            'messaging_product' => 'whatsapp', 'to' => $toPhone,
            'type' => 'video',
            'video' => ['link' => $url, 'caption' => mb_substr($car['title'], 0, 1000)],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))['ok'];
    } else {
        $ok = wa_send_text($toPhone, '🎥 ' . $car['title'] . "\n" . $url);
    }
    return $ok ? ['sent' => true, 'car' => $car['title']]
               : ['sent' => false, 'reason' => 'sending failed - call request_followup so the team sends it personally'];
}

function wa_tool_request_followup(array $in, int $convId, string $fromPhone, string $waName): array
{
    error_log('request_followup called: conv=' . $convId . ' reason=' . substr((string)($in['reason'] ?? ''), 0, 80)
        . (function_exists('chatbot_wa_notify') ? '' : ' [NOTIFY FILE MISSING]'));
    if (function_exists('chatbot_wa_notify')) {
        chatbot_wa_notify("📞 Sales follow-up needed\nCustomer: " . ($waName ?: 'Unknown') . " +" . $fromPhone
            . "\nNeeds: " . substr(trim((string)($in['reason'] ?? '')) ?: 'follow-up', 0, 120)
            . "\nTap to chat: https://wa.me/" . $fromPhone);
    }
    return ['notified' => true, 'note' => 'Team alerted. Now you may tell the customer the sales team will be in touch shortly.'];
}

function wa_tool_escalate(array $in, int $convId, string $fromPhone, string $waName): array
{
    error_log('escalate_to_human called: conv=' . $convId
        . (function_exists('chatbot_wa_notify') ? '' : ' [NOTIFY FILE MISSING]'));
    /* NOTE: no auto-pause - the AI only goes silent when a human actually
       starts chatting (app echo / dashboard reply) or is stopped manually. */
    if (function_exists('chatbot_wa_notify')) {
        chatbot_wa_notify("🚨 Human needed on WhatsApp\nCustomer: " . ($waName ?: 'Unknown') . " +" . $fromPhone
            . "\nReason: " . substr(trim((string)($in['reason'] ?? '')) ?: 'customer requested a human', 0, 100)
            . "\nTap to chat: https://wa.me/" . $fromPhone);
    }
    return ['escalated' => true,
            'note' => 'Team alerted. Tell the customer warmly that a colleague will join this chat shortly - keep helping them normally until the salesperson takes over.'];
}

function wa_tool_off_topic(int $convId): array
{
    db_run("UPDATE chat_conversations SET off_topic_count = off_topic_count + 1 WHERE id = ?", [$convId]);
    $n = (int)(db_one("SELECT off_topic_count n FROM chat_conversations WHERE id = ?", [$convId])['n'] ?? 0);
    if ($n >= 5) {
        return ['strikes' => $n, 'final' => true,
                'note' => 'This person keeps going off-topic. Reply with ONE very short goodbye line and nothing more. Keep every future off-topic reply to one short line.'];
    }
    return ['strikes' => $n, 'final' => false];
}

function wa_tool_save_lead(array $in, int $convId): array
{
    $existing = db_one("SELECT * FROM chat_leads WHERE conversation_id = ?", [$convId]) ?: [];
    $pick = fn(string $new, string $key, int $len) =>
        $new !== '' ? substr($new, 0, $len) : (string)($existing[$key] ?? '');
    $m = [
        'name'         => $pick(trim(strip_tags((string)($in['name'] ?? ''))), 'name', 150),
        'car_interest' => $pick(trim((string)($in['car_interest'] ?? '')), 'car_interest', 1000),
        'intent'       => $pick(trim((string)($in['intent'] ?? '')), 'intent', 30),
        'budget'       => $pick(trim((string)($in['budget'] ?? '')), 'budget', 100),
        'timeframe'    => $pick(trim((string)($in['timeframe'] ?? '')), 'timeframe', 50),
        'notes'        => $pick(trim((string)($in['notes'] ?? '')), 'notes', 2000),
        'language'     => $pick(trim((string)($in['language'] ?? '')), 'language', 30),
    ];
    $score = 45;   // name may be missing but the number is verified
    if ($m['car_interest'] !== '') $score += 10;
    if ($m['budget'] !== '')       $score += 10;
    $score += match ($m['timeframe']) { 'now' => 20, 'this_month' => 15, '1-3_months' => 8, default => 0 };
    $score = min(100, $score);
    $quality = $score >= 65 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');
    if ($existing) {
        db_run("UPDATE chat_leads SET name=COALESCE(NULLIF(?,''),name), car_interest=?, intent=?, budget=?,
                       timeframe=?, notes=?, language=?, score=?, quality=? WHERE id = ?",
               [$m['name'], $m['car_interest'] ?: null, $m['intent'] ?: null, $m['budget'] ?: null,
                $m['timeframe'] ?: null, $m['notes'] ?: null, $m['language'] ?: null,
                $score, $quality, (int)$existing['id']]);
    }
    return ['saved' => true, 'lead_quality' => $quality];
}

/**
 * Downloads a customer-sent image from Meta's media API.
 * Returns ['mime' =>, 'b64' =>] or null (unsupported type / too big / error).
 */
function wa_fetch_media(string $mediaId): ?array
{
    if ($mediaId === '') { return null; }
    $auth = ['Authorization: Bearer ' . WA_CLOUD_TOKEN];

    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($mediaId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $auth]);
    $meta = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $url  = (string)($meta['url'] ?? '');
    $mime = (string)($meta['mime_type'] ?? '');
    if ($url === '' || !preg_match('#^image/(jpeg|png|webp|gif)$#', $mime)) { return null; }
    if ((int)($meta['file_size'] ?? 0) > 8 * 1024 * 1024) { return null; }   // 8 MB cap

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
                            CURLOPT_HTTPHEADER => $auth, CURLOPT_FOLLOWLOCATION => true]);
    $bin  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($bin === false || $http >= 400 || $bin === '') { return null; }

    return ['mime' => $mime, 'b64' => base64_encode($bin)];
}

/**
 * Downloads a customer voice note and stores it so the dashboard can play it.
 * Returns the public relative path, or null.
 */
function wa_save_voice(string $mediaId): ?string
{
    if ($mediaId === '') { return null; }
    $auth = ['Authorization: Bearer ' . WA_CLOUD_TOKEN];

    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode($mediaId));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $auth]);
    $meta = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $url  = (string)($meta['url'] ?? '');
    $mime = (string)($meta['mime_type'] ?? '');
    if ($url === '' || !str_starts_with($mime, 'audio/')) { return null; }
    if ((int)($meta['file_size'] ?? 0) > 16 * 1024 * 1024) { return null; }

    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
                            CURLOPT_HTTPHEADER => $auth, CURLOPT_FOLLOWLOCATION => true]);
    $bin  = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($bin === false || $http >= 400 || $bin === '') { return null; }

    $dir = ROOT_PATH . '/assets/cache/wa-voice';
    if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
    $ext  = str_contains($mime, 'mpeg') ? 'mp3' : (str_contains($mime, 'mp4') || str_contains($mime, 'aac') ? 'm4a' : 'ogg');
    $file = md5($mediaId) . '.' . $ext;
    if (@file_put_contents($dir . '/' . $file, $bin) === false) { return null; }
    return '/assets/cache/wa-voice/' . $file;
}

/* ---- WhatsApp send helpers ---------------------------------------------------- */

function wa_api(string $endpointPayloadJson): array
{
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(WA_CLOUD_PHONE_ID) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . WA_CLOUD_TOKEN],
        CURLOPT_POSTFIELDS => $endpointPayloadJson,
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $http >= 400) {
        error_log('wa send failed: http=' . $http . ' body=' . substr((string)$body, 0, 300));
        return ['ok' => false];
    }
    return ['ok' => true];
}

function wa_send_text(string $to, string $text): bool
{
    /* The AI sometimes slips into markdown habits. Convert to clean WhatsApp
       formatting so customers never see stray asterisks or markdown links:
         **bold**  -> *bold*
         [text](url) -> text: url                                            */
    $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text);
    $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', '$1: $2', $text);

    $ok = true;
    foreach (mb_str_split($text, 3900) as $chunk) {   // WhatsApp caps ~4096 chars
        $r = wa_api(json_encode([
            'messaging_product' => 'whatsapp', 'to' => $to,
            'type' => 'text', 'text' => ['preview_url' => true, 'body' => $chunk],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $ok = $ok && $r['ok'];
    }
    return $ok;
}

/** Uploads a local file to WhatsApp and returns its media id (or null). */
function wa_upload_media(string $localPath, string $filename): ?string
{
    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(WA_CLOUD_PHONE_ID) . '/media');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . WA_CLOUD_TOKEN],
        CURLOPT_POSTFIELDS => [
            'messaging_product' => 'whatsapp',
            'file' => new CURLFile($localPath, 'application/pdf', $filename),
        ],
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $http >= 400) {
        error_log('wa media upload failed: http=' . $http . ' body=' . substr((string)$body, 0, 300));
        return null;
    }
    return json_decode($body, true)['id'] ?? null;
}

function wa_send_document(string $to, string $url, string $filename, string $caption): bool
{
    /* Documents sent by UPLOAD get a first-page preview thumbnail in WhatsApp;
       documents sent by link show only a generic icon. Our brochures live on
       this same server, so resolve the local file and upload it. */
    $payloadDoc = null;
    $sitePrefix = rtrim(SITE_URL, '/');
    $rel = null;
    if (str_starts_with($url, $sitePrefix . '/')) { $rel = substr($url, strlen($sitePrefix)); }
    elseif (str_starts_with($url, '/'))           { $rel = $url; }
    if ($rel !== null) {
        $localPath = ROOT_PATH . rawurldecode($rel);
        if (is_file($localPath) && filesize($localPath) <= 100 * 1024 * 1024) {
            $mediaId = wa_upload_media($localPath, $filename);
            if ($mediaId !== null) {
                $payloadDoc = ['id' => $mediaId, 'filename' => $filename, 'caption' => mb_substr($caption, 0, 1000)];
            }
        }
    }
    if ($payloadDoc === null) {   // fallback: send by link (works, just no preview)
        $payloadDoc = ['link' => $url, 'filename' => $filename, 'caption' => mb_substr($caption, 0, 1000)];
    }
    return wa_api(json_encode([
        'messaging_product' => 'whatsapp', 'to' => $to,
        'type' => 'document', 'document' => $payloadDoc,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))['ok'];
}

function wa_mark_read(string $wamid): void
{
    if ($wamid === '') { return; }
    wa_api(json_encode(['messaging_product' => 'whatsapp', 'status' => 'read', 'message_id' => $wamid]));
}

/* ---- Claude call (non-streaming, with tool loop) ------------------------------- */

function wa_claude(array $payload): ?array
{
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . ANTHROPIC_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $http >= 400) {
        error_log('wa bot claude failed: http=' . $http . ' body=' . substr((string)$body, 0, 300));
        return null;
    }
    return json_decode($body, true);
}

$model     = defined('CHATBOT_MODEL') ? CHATBOT_MODEL : 'claude-haiku-4-5';
$maxTokens = defined('CHATBOT_MAX_TOKENS') ? (int)CHATBOT_MAX_TOKENS : 1024;

/* Photo turns use a stronger model - much more reliable at identifying cars
   and reading screenshots. Only image messages pay the higher rate. */
if ($imageMedia) {
    $model = defined('CHATBOT_VISION_MODEL') && CHATBOT_VISION_MODEL !== ''
           ? CHATBOT_VISION_MODEL : 'claude-sonnet-5';
}

$assistantText = '';
$rounds = 0;

while (true) {
    if (++$rounds > 5) { break; }
    $resp = wa_claude([
        'model' => $model, 'max_tokens' => $maxTokens,
        'system' => $systemPrompt, 'messages' => $messages, 'tools' => $tools,
    ]);
    if ($resp === null) { break; }

    $content = $resp['content'] ?? [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') === 'text') { $assistantText .= $block['text']; }
    }
    if (($resp['stop_reason'] ?? '') !== 'tool_use') { break; }

    $toolResults = [];
    foreach ($content as $block) {
        if (($block['type'] ?? '') !== 'tool_use') { continue; }
        $out = match ($block['name']) {
            'search_cars'       => wa_tool_search_cars($block['input'] ?? []),
            'get_car_details'   => wa_tool_get_car_details($block['input'] ?? []),
            'send_car_pdf'      => wa_tool_send_car_pdf($block['input'] ?? [], $fromPhone),
            'send_car_photos'   => wa_tool_send_car_photos($block['input'] ?? [], $fromPhone),
            'send_car_video'    => wa_tool_send_car_video($block['input'] ?? [], $fromPhone),
            'request_followup'  => wa_tool_request_followup($block['input'] ?? [], $convId, $fromPhone, $waName),
            'save_lead'         => wa_tool_save_lead($block['input'] ?? [], $convId),
            'escalate_to_human' => wa_tool_escalate($block['input'] ?? [], $convId, $fromPhone, $waName),
            'mark_off_topic'    => wa_tool_off_topic($convId),
            default             => ['error' => 'unknown tool'],
        };
        if ($block['name'] === 'search_cars' && !empty($out['cars'])) {
            $terms = trim(implode(' ', array_filter([
                (string)($block['input']['brand'] ?? ''), (string)($block['input']['query'] ?? '')])));
            if ($terms !== '') {
                db_run("UPDATE chat_leads SET car_interest = COALESCE(NULLIF(car_interest, ''), ?)
                         WHERE conversation_id = ?", [ucwords($terms), $convId]);
            }
        }
        $toolResults[] = ['type' => 'tool_result', 'tool_use_id' => $block['id'],
            'content' => json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)];
    }
    $messages[] = ['role' => 'assistant', 'content' => $content];
    $messages[] = ['role' => 'user', 'content' => $toolResults];
}

if ($assistantText !== '') {
    wa_send_text($fromPhone, $assistantText);
    db_run("INSERT INTO chat_messages (conversation_id, role, content) VALUES (?, 'assistant', ?)",
           [$convId, $assistantText]);
}
