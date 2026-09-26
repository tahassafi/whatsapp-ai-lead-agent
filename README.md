# WhatsApp AI Lead Agent

An AI sales agent for a car dealership, built for a client on their existing
custom PHP site. One Claude-powered brain answers customers on **two
channels** — the website chat widget and the dealership's **WhatsApp Business
number** — grounded in the live inventory database, and every conversation
feeds a built-in **lead CRM** with server-side scoring, human takeover, and
WhatsApp alerts for the sales team.

Plain PHP 8.1+, no framework, no Composer. Runs on ordinary shared/cPanel
hosting with MySQL and cURL.

## What it does

**Website chat** (`api/chat.php` + `assets/js/chatbot.js`)
- Streaming replies (Server-Sent Events), any language.
- Pre-chat gate: name + validated phone before the AI spends a single token
  (with a honeypot field and server-side re-validation against bots).
- Tool use against the live `cars` table: `search_cars` renders real car
  cards in the chat, `get_car_details` answers exact spec questions,
  `save_lead` updates the CRM — the model can never invent stock or prices.
- IP rate limiting, 24-hour conversation expiry, hot-lead email pings.

**WhatsApp bot** (`api/whatsapp-webhook.php`)
- Meta Cloud API webhook: every inbound number is a verified lead from
  message one.
- Sends PDF brochures as WhatsApp documents (uploaded, so they get a
  first-page preview), full photo galleries as real images (WebP converted to
  JPEG on the fly by `api/car-photo.php`), and car videos.
- **Vision**: customers send a photo/screenshot of a car; a stronger model
  identifies it and verifies color/body style against stock before confirming.
- Resolves links to the site's own car pages server-side, so the AI knows
  exactly which car a pasted URL is.
- **Coexistence / human takeover**: the moment anyone on the team replies
  from the WhatsApp Business app (message echo) the bot goes permanently
  silent for that customer; history sync marks all pre-existing chats as
  human-owned. Voice notes escalate to a human instead of being guessed at.
- Off-topic strike counter, per-customer rate limiting, webhook signature
  verification, redelivery dedupe.

**Lead CRM** (`admin/chat-leads/`)
- Leads list with stat-card quick filters, live search, sortable columns
  (junk always sinks to the bottom), status pills, quality-graded avatars.
- Lead record with full conversation transcript in a WhatsApp-style view,
  one-click **translate to English**, and live polling.
- Reply to WhatsApp customers **as the business number** straight from the
  dashboard — text and voice notes (recorded in the browser and converted to
  OGG/Opus with ffmpeg.wasm so they render as real voice notes).
- AI control per conversation: pause for 1h–7d, hand off forever, resume.
- Manual lead entry for walk-ins/calls/portals, same scoring model.
- Editable plain-text knowledge base (finance terms, policies, hours) the AI
  uses on the very next message.
- Role-based users (`admin` / `sales`) with an audit log.

## Architecture

```
                        ┌────────────────────────────────────────────┐
                        │                MySQL (site DB)             │
                        │  cars  chat_conversations  chat_messages   │
                        │  chat_leads  admin_users  activity_log     │
                        └───────▲──────────────▲─────────────▲───────┘
                                │              │             │
   Website visitor              │              │             │        Sales team
        │                       │              │             │            │
        ▼                       │              │             │            ▼
┌──────────────────┐   SSE   ┌──┴──────────┐   │   ┌─────────┴───────────────┐
│ chatbot.js widget├────────►│ api/chat.php│   │   │  admin/chat-leads/ CRM  │
│ (pre-chat gate)  │◄────────┤ tool loop   │   │   │  list · view · reply    │
└──────────────────┘         └──────┬──────┘   │   │  knowledge · users      │
                                    │          │   └─────────┬───────────────┘
                                    ▼          │             │ send as business number
                             ┌────────────┐    │             ▼
                             │ Claude API │    │      ┌─────────────┐
                             │ (Anthropic)│◄───┤      │  Meta Cloud │◄── customer on
                             └────────────┘    │      │  API (WA)   │──► WhatsApp
                                               │      └──────▲──────┘
                                        ┌──────┴───────────┐ │ webhook
                                        │api/whatsapp-     ├─┘
                                        │webhook.php       │
                                        └──────────────────┘
```

**Request flow, website chat:** widget POSTs `{token, message, page, visitor}`
→ gate/rate-limit checks → conversation + lead rows → history + live stock
snapshot assembled into the system prompt → streaming Claude call with tools
→ tool calls executed against MySQL mid-stream (car cards pushed to the
browser as SSE events) → final text stored and streamed → WhatsApp alert to
the team fired after the reply finishes.

**Request flow, WhatsApp:** Meta POSTs the webhook → signature check → HTTP
200 acked immediately (processing continues after `fastcgi_finish_request`)
→ echo/history events mark numbers human-owned → customer message stored
(photos and voice notes downloaded from Meta's media API and cached locally
for the dashboard) → human-owned/paused/rate-limit gates → same tool loop as
the website (plus `send_car_pdf`, `send_car_photos`, `send_car_video`,
`request_followup`, `escalate_to_human`, `mark_off_topic`) → reply sent via
the Cloud API and stored.

## Repo layout

```
api/
  chat.php               website chat backend (SSE + tool loop)
  whatsapp-webhook.php   WhatsApp bot (Meta Cloud API webhook)
  car-photo.php          WebP→JPEG converter so WhatsApp can show gallery photos
admin/
  login.php / logout.php / users.php / _header.php / _footer.php
  chat-leads/            the CRM: index, view, add, update, messages (polling),
                         knowledge editor, _lead.php shared helpers
includes/
  db.php functions.php auth.php audit.php
  chatbot-embed.php      one-line footer include that mounts the widget
  chatbot-notify.php     lead alerts via CallMeBot (simple/free)
  chatbot-notify-cloudapi.php  lead alerts via Meta template message (production)
  country-codes.php      dial codes for the phone inputs
assets/
  js/chatbot.js  css/chatbot.css  css/crm.css
  vendor/ffmpeg/         ffmpeg.wasm (not committed - see its README)
data/
  chatbot-knowledge.example.txt
sql/
  schema.sql             all tables, consolidated
config.example.php  .env.example  LICENSE
```

## Setup

1. **Database** — import `sql/schema.sql` into the site's database. If the
   site already has an inventory table, adapt the `cars` reference schema /
   queries to your column names. Create the first admin user (see the
   commented INSERT in the schema).
2. **Config** — copy `config.example.php` to `config.php` and
   `.env.example` to `.env`; fill in every value (each has a comment).
   `config.php` and `.env` are gitignored.
3. **Upload** — drop the files into the site's document root, keeping the
   folder structure. PHP 8.1+ with cURL, PDO MySQL and GD (for the WebP
   converter).
4. **Widget** — add one line to your site footer, just above your main JS:
   ```php
   <?php require __DIR__ . '/chatbot-embed.php'; ?>
   ```
5. **ffmpeg.wasm** (optional, for browser-recorded voice notes) — see
   `assets/vendor/ffmpeg/README.md`.

### WhatsApp (Meta Cloud API) setup

1. Create a Meta app with the WhatsApp product; add/verify the business
   phone number and note its **Phone number ID**.
2. Create a **System User** in Meta Business Settings, grant it the app +
   WhatsApp account, and generate a **permanent token**
   (`whatsapp_business_messaging` + `whatsapp_business_management`) →
   `WA_CLOUD_TOKEN`.
3. WhatsApp → Configuration → Callback URL:
   `https://your-domain.example/api/whatsapp-webhook.php`, verify token =
   your `WA_WEBHOOK_VERIFY_TOKEN`. Subscribe to the **`messages`** and
   **`smb_message_echoes`** webhook fields (echoes power human takeover).
4. Set `WA_APP_SECRET` so payload signatures are verified.
5. For lead alerts via the Cloud API notifier, create an approved Utility
   template (body: "New lead on the website") and set `WA_CLOUD_TO` /
   `WA_CLOUD_TEMPLATE`; or use the zero-setup CallMeBot notifier instead.

### Cron

None required — both bots are fully event-driven (HTTP webhook + visitor
requests). The only scheduled task worth adding is a cache sweep if disk
matters:

```
0 4 * * 0  find /path/to/site/assets/cache/wa-jpg -type f -mtime +30 -delete
```

## Design decisions

- **No framework, no Composer.** The client's site is hand-rolled PHP on
  shared cPanel hosting, deployed by uploading files. Every dependency is
  either PHP stdlib or a single self-hosted JS file, so deploys stay
  copy-paste simple and nothing breaks when the host tweaks PHP.
- **Ground truth over generation.** The model never free-generates inventory
  facts: prices, specs and links only enter a reply through tool results or
  the stock list injected into the system prompt, and the prompt forbids
  writing URLs from memory. Lead scores are computed server-side so the
  model can't inflate them.
- **Gate before you pay.** The website widget collects a validated name +
  phone before the first API call, so every token spent is spent on an
  identified lead — and spam bots are filtered by a honeypot plus
  server-side phone validation.
- **Humans always win.** Any reply from the WhatsApp Business app instantly
  and permanently silences the bot for that customer (via message echoes),
  history sync protects pre-existing conversations, and the dashboard has
  explicit pause/handoff controls. The bot escalates voice notes and
  negotiations instead of guessing.
- **Ack fast, work late.** The webhook returns 200 before doing any work
  (`fastcgi_finish_request`), so Meta never re-delivers, with `last_wamid`
  dedupe as a second net.
- **Cheap by default, smart when it counts.** Text turns run on a fast small
  model; only photo turns (car identification) pay for the stronger vision
  model.
- **WhatsApp's quirks are handled where they live**: OGG/Opus with an
  explicit `codecs=opus` MIME and `voice:true` for real voice-note bubbles,
  document upload (not link) for PDF previews, WebP→JPEG conversion for
  images, markdown→WhatsApp formatting fixes, 3,900-char chunking.

## Notes

This is a sanitized portfolio release of a system built for a client's
dealership; branding, credentials, phone numbers and business data have been
replaced with environment variables and examples. The production deployment
also layers TOTP two-factor auth over the admin login (the schema already
carries the columns) and lives inside a larger site admin.

## License

All rights reserved — published for portfolio and code-review purposes only. See [LICENSE](LICENSE).
