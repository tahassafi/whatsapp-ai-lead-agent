<?php
/**
 * WhatsApp lead notifications via CallMeBot (simple/free option).
 *
 * One-time setup to get your API key (free):
 *   1. On the phone that should RECEIVE alerts, add CallMeBot's WhatsApp bot
 *      number to your contacts (current number is on
 *      callmebot.com/blog/free-api-whatsapp-messages/)
 *   2. Send it the WhatsApp message:  I allow callmebot to send me messages
 *   3. It replies with your personal apikey - put it in .env:
 *
 *      WA_NOTIFY_PHONE=9715XXXXXXXX    (receives the alerts, digits only)
 *      WA_NOTIFY_APIKEY=123456         (key from CallMeBot)
 *
 * Leave either value empty to turn notifications off.
 *
 * PRODUCTION ALTERNATIVE: chatbot-notify-cloudapi.php sends the same alert
 * through Meta's official Cloud API using an approved template - swap the
 * files (or the require) to use it instead.
 */

function chatbot_wa_notify(string $text): void
{
    if (!defined('WA_NOTIFY_PHONE') || !defined('WA_NOTIFY_APIKEY')
        || WA_NOTIFY_PHONE === '' || WA_NOTIFY_APIKEY === '') {
        return;
    }
    $url = 'https://api.callmebot.com/whatsapp.php'
         . '?phone=' . rawurlencode(WA_NOTIFY_PHONE)
         . '&apikey=' . rawurlencode(WA_NOTIFY_APIKEY)
         . '&text=' . rawurlencode(mb_substr($text, 0, 1500));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
    ]);
    $ok   = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    /* CallMeBot sometimes answers 200 with an error message in the body
       (rate limit, wrong apikey...) - log those too so failures are visible */
    if ($ok === false || $http >= 400
        || ($ok !== false && preg_match('/error|not registered|too many/i', (string)$ok))) {
        error_log('chatbot_wa_notify failed: http=' . $http
            . ' body=' . substr(trim(strip_tags((string)$ok)), 0, 200));
    }
}
