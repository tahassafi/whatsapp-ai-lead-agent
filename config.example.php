<?php
/**
 * =============================================================================
 *  CONFIG LOADER  —  copy to config.php, then create a .env next to it
 *  (see .env.example for every variable). No secrets live in this file.
 * =============================================================================
 */

define('ROOT_PATH', __DIR__);

/* ---- tiny .env parser (no Composer dependency) ----------------------------- */
$envFile = __DIR__ . '/.env';
$env = [];
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) { continue; }
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        /* strip optional surrounding quotes */
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && str_ends_with($v, $v[0])) {
            $v = substr($v, 1, -1);
        }
        $env[trim($k)] = $v;
    }
}
function env(string $key, string $default = ''): string
{
    global $env;
    return $env[$key] ?? (getenv($key) !== false ? (string)getenv($key) : $default);
}

/* ---- Site identity ---------------------------------------------------------- */
define('SITE_URL',         rtrim(env('SITE_URL', 'https://example.com'), '/'));
define('SITE_NAME',        env('SITE_NAME', 'Example Motors'));
define('BUSINESS_TAGLINE', env('BUSINESS_TAGLINE', 'a luxury car showroom'));
define('CURRENCY',         env('CURRENCY', 'AED'));
define('DEFAULT_DIAL_CODE', env('DEFAULT_DIAL_CODE', '+971'));
define('CONTACT_ADDRESS',  env('CONTACT_ADDRESS', ''));
define('CONTACT_PHONE',    env('CONTACT_PHONE', ''));
define('CONTACT_EMAIL',    env('CONTACT_EMAIL', ''));
define('CONTACT_WHATSAPP', env('CONTACT_WHATSAPP', ''));   // digits only, for wa.me links

/* ---- Database ---------------------------------------------------------------- */
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', ''));
define('DB_USER', env('DB_USER', ''));
define('DB_PASS', env('DB_PASS', ''));

/* ---- Chatbot / AI -------------------------------------------------------------- */
define('CHATBOT_ENABLED',      env('CHATBOT_ENABLED', 'true') === 'true');
define('ANTHROPIC_API_KEY',    env('ANTHROPIC_API_KEY', ''));
define('CHATBOT_MODEL',        env('CHATBOT_MODEL', 'claude-haiku-4-5'));
define('CHATBOT_VISION_MODEL', env('CHATBOT_VISION_MODEL', 'claude-sonnet-5'));
define('CHATBOT_MAX_TOKENS',   (int)env('CHATBOT_MAX_TOKENS', '1024'));
define('CHATBOT_RATE_LIMIT',   (int)env('CHATBOT_RATE_LIMIT', '30'));
define('CHATBOT_HISTORY_LIMIT', (int)env('CHATBOT_HISTORY_LIMIT', '24'));
define('CHATBOT_LEAD_EMAIL',   env('CHATBOT_LEAD_EMAIL', ''));

/* ---- WhatsApp Cloud API (Meta) --------------------------------------------------- */
define('WA_CLOUD_TOKEN',           env('WA_CLOUD_TOKEN', ''));
define('WA_CLOUD_PHONE_ID',        env('WA_CLOUD_PHONE_ID', ''));
define('WA_WEBHOOK_VERIFY_TOKEN',  env('WA_WEBHOOK_VERIFY_TOKEN', ''));
define('WA_APP_SECRET',            env('WA_APP_SECRET', ''));
define('WA_CLOUD_TO',              env('WA_CLOUD_TO', ''));
define('WA_CLOUD_TEMPLATE',        env('WA_CLOUD_TEMPLATE', 'new_lead_alert'));

/* ---- CallMeBot notifications (simple/free alternative) ---------------------------- */
define('WA_NOTIFY_PHONE',  env('WA_NOTIFY_PHONE', ''));
define('WA_NOTIFY_APIKEY', env('WA_NOTIFY_APIKEY', ''));
