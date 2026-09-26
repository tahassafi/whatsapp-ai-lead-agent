<?php
/**
 * WhatsApp lead notifications via Meta's official Cloud API.
 *
 * TO SWITCH FROM CALLMEBOT: rename this file to chatbot-notify.php on the
 * server (replacing the CallMeBot one). Nothing else changes - api/chat.php
 * calls the same chatbot_wa_notify() function.
 *
 * Env values used (see .env.example):
 *
 *   WA_CLOUD_TOKEN     - permanent System User access token
 *   WA_CLOUD_PHONE_ID  - "Phone number ID" of your SENDER number
 *                        (from the WhatsApp > API Setup page)
 *   WA_CLOUD_TO        - recipient, e.g. 971501234567
 *   WA_CLOUD_TEMPLATE  - your approved Utility template (default: new_lead_alert)
 *
 * The template body should simply be: New lead on the website
 * (no variables needed - the $text argument is ignored on purpose, so no
 * customer data is ever sent).
 */

function chatbot_wa_notify(string $text): void
{
    if (!defined('WA_CLOUD_TOKEN') || WA_CLOUD_TOKEN === ''
        || !defined('WA_CLOUD_PHONE_ID') || WA_CLOUD_PHONE_ID === ''
        || !defined('WA_CLOUD_TO') || WA_CLOUD_TO === '') {
        return;
    }
    $template = defined('WA_CLOUD_TEMPLATE') && WA_CLOUD_TEMPLATE !== ''
              ? WA_CLOUD_TEMPLATE : 'new_lead_alert';

    $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode(WA_CLOUD_PHONE_ID) . '/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . WA_CLOUD_TOKEN,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'messaging_product' => 'whatsapp',
            'to' => WA_CLOUD_TO,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => 'en'],
            ],
        ]),
    ]);
    $body = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || $http >= 400) {
        error_log('chatbot_wa_notify (cloud api) failed: http=' . $http
            . ' body=' . substr((string)$body, 0, 300));   // never breaks the lead flow
    }
}
