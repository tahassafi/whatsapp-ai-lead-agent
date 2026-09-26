-- =============================================================================
--  WHATSAPP AI LEAD AGENT - DATABASE SCHEMA
--  Consolidated from the project's incremental migrations. Safe to re-run:
--  uses IF NOT EXISTS. Import into the same database the site uses.
-- =============================================================================

-- ---------------------------------------------------------------------------
-- Conversations: one row per website chat session OR WhatsApp number.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_conversations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `session_token` varchar(64) NOT NULL,
  `wa_phone` varchar(20) DEFAULT NULL,               -- WhatsApp threads only
  `wa_name` varchar(150) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `page_url` varchar(500) DEFAULT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_active` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `bot_paused_until` timestamp NULL DEFAULT NULL,    -- human takeover window
  `off_topic_count` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `human_owned` tinyint(1) NOT NULL DEFAULT 0,       -- team owns this customer; bot stays silent
  `last_wamid` varchar(128) DEFAULT NULL,            -- dedupe redelivered webhooks
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_token` (`session_token`),
  KEY `ip_started` (`ip`, `started_at`),
  KEY `wa_phone` (`wa_phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Messages: full transcript. role = 'user' | 'assistant' | 'agent'
-- ('agent' = a human replying from the dashboard).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) NOT NULL,
  `role` varchar(12) NOT NULL,
  `content` mediumtext NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `conv` (`conversation_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Leads: one row per prospective customer, scored server-side.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_leads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `conversation_id` int(11) DEFAULT NULL,
  `source` varchar(20) NOT NULL DEFAULT 'chatbot',   -- chatbot | whatsapp | manual
  `channel` varchar(50) DEFAULT NULL,                -- walk-in, classifieds, instagram...
  `name` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `car_interest` varchar(1000) DEFAULT NULL,         -- cars joined by " | "
  `intent` varchar(30) DEFAULT NULL,                 -- buy | finance | viewing | other
  `budget` varchar(100) DEFAULT NULL,
  `timeframe` varchar(50) DEFAULT NULL,              -- now | this_month | 1-3_months | just_looking
  `notes` text DEFAULT NULL,
  `language` varchar(30) DEFAULT NULL,
  `score` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,    -- 0-100, computed server-side
  `quality` varchar(10) NOT NULL DEFAULT 'cold',     -- hot | warm | cold
  `status` varchar(20) NOT NULL DEFAULT 'new',       -- new | contacted | qualified | closed | junk
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `status_created` (`status`, `created_at`),
  KEY `conv` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Admin users (dashboard sign-in). The totp_* / backup_codes columns exist
-- for the optional TOTP 2FA layer used in production.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL DEFAULT 'Admin',
  `role` varchar(20) NOT NULL DEFAULT 'sales',       -- admin | sales
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `totp_last_slot` bigint(20) DEFAULT NULL,
  `backup_codes` text DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create your first admin (replace the values; generate the hash with:
--   php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT);" )
-- INSERT INTO admin_users (email, password_hash, name, role)
-- VALUES ('admin@example.com', '$2y$10$REPLACE_ME', 'Admin', 'admin');

-- ---------------------------------------------------------------------------
-- Activity log (audit trail of admin actions).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `action` varchar(30) NOT NULL,
  `item_type` varchar(30) NOT NULL,
  `item_id` int(11) NOT NULL DEFAULT 0,
  `item_label` varchar(255) DEFAULT NULL,
  `before_json` text DEFAULT NULL,
  `after_json` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Cars (MINIMAL reference schema). In the original project this table belongs
-- to the dealership website's own CMS; the bot only READS it. If you already
-- have an inventory table, map/rename columns instead of creating this one.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `cars` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `slug_prefix` varchar(150) NOT NULL,               -- e.g. 'lamborghini-urus'
  `ref_number` varchar(50) NOT NULL,                 -- e.g. 'REF12345'; page URL = /slug_prefix/ref_number/
  `brand_slug` varchar(100) DEFAULT NULL,
  `brand_slug_2` varchar(100) DEFAULT NULL,          -- second brand for tuner cars (e.g. onyx)
  `model_slug` varchar(100) DEFAULT NULL,
  `year` smallint(6) DEFAULT NULL,
  `mileage` int(11) DEFAULT NULL,                    -- km; < 1000 is treated as brand new
  `price` decimal(12,2) DEFAULT NULL,
  `price_contact` varchar(100) DEFAULT NULL,         -- shown when price is 0/hidden
  `engine` varchar(150) DEFAULT NULL,
  `horsepower` varchar(50) DEFAULT NULL,
  `transmission` varchar(80) DEFAULT NULL,
  `drive_type` varchar(50) DEFAULT NULL,
  `exterior_color` varchar(80) DEFAULT NULL,
  `interior_color` varchar(80) DEFAULT NULL,
  `featured_options` text DEFAULT NULL,
  `overview` mediumtext DEFAULT NULL,
  `thumbnail` varchar(500) DEFAULT NULL,
  `gallery` mediumtext DEFAULT NULL,                 -- JSON array of image paths
  `video_url` varchar(500) DEFAULT NULL,
  `brochure_url` varchar(500) DEFAULT NULL,          -- PDF the bot sends on WhatsApp
  `status` varchar(20) NOT NULL DEFAULT 'available', -- available | reserved | sold | draft
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `status` (`status`, `deleted_at`),
  KEY `lookup` (`slug_prefix`, `ref_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
