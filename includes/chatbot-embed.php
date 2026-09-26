<?php
/**
 * Chatbot embed  (include in your site footer)
 *
 * Wire it in by adding ONE line to your footer include, just above the
 * main <script> line near the bottom:
 *
 *     <?php require __DIR__ . '/chatbot-embed.php'; ?>
 *
 * Remove the line (or set CHATBOT_ENABLED=false in .env) to turn the
 * widget off site-wide.
 */
if (!defined('CHATBOT_ENABLED') || !CHATBOT_ENABLED) { return; }

require_once __DIR__ . '/country-codes.php';
$dcbCountries = array_map(
    fn($c) => ['i' => $c['iso'], 'n' => $c['name'], 'c' => $c['code'], 'f' => $c['flag']],
    country_dial_codes()
);
?>
<link rel="stylesheet" href="<?= e(asset_v('/assets/css/chatbot.css')) ?>">
<div id="ai-chat-widget"
     data-endpoint="/api/chat.php"
     data-whatsapp="<?= e(CONTACT_WHATSAPP) ?>"
     data-sitename="<?= e(SITE_NAME) ?>"></div>
<script>window.DCB_COUNTRIES = <?= json_encode($dcbCountries, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= e(asset_v('/assets/js/chatbot.js')) ?>" defer></script>
