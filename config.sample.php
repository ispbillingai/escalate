<?php
// Escalate Platform Configuration
// Copy this file to config.php and fill in real values. config.php is gitignored.

$db_host     = 'localhost';
$db_user     = 'your_db_user';
$db_password = 'your_db_password';
$db_name     = 'escalate';

// Public base URL of this platform, no trailing slash
define('BASE_URL', 'https://escalate.ispledger.com');

// Telegram channel posting.
// 1. Create a bot with @BotFather and paste its token below.
// 2. Add the bot as an Administrator of your channel (Post Messages permission).
// 3. Put the channel @username (public channel) or -100... id (private channel) below.
define('TELEGRAM_BOT_TOKEN', '');
define('TELEGRAM_CHAT_ID', '@freeispradius');   // channel @username or -100... id

// Public channel link shown on the site; member count is pulled from it.
define('TELEGRAM_CHANNEL_URL', 'https://t.me/freeispradius');

// Optional: post into a specific topic of a topics-enabled group. The topic id
// is the middle number in a message link, e.g. t.me/freeispradius/24886/24893
// means topic 24886. Leave empty to post to the General topic / plain channel.
define('TELEGRAM_TOPIC_ID', '');

// WhatsApp alert to staff when a new escalation lands. Leave empty to disable.
// The URL is called as: <url>?to=<number>&msg=<text>&secret=<secret>
define('WHATSAPP_ALERT_URL', '');      // e.g. https://whatsapp.ispledger.com/api/sendWA.php
define('WHATSAPP_ALERT_SECRET', '');
define('WHATSAPP_ALERT_TO', '');       // e.g. 254712345678

// Password for the moderation area (admin.php)
define('ADMIN_PASSWORD', 'change_this_password');

// Account managers, comma separated. Shown as typing suggestions on the form;
// customers can also type a name that is not on the list.
define('ACCOUNT_MANAGERS', 'Manager One,Manager Two,Manager Three');

// The domain tenant panels live under. The company name a customer types is
// their panel subdomain: it must resolve as <company>.<PANEL_DOMAIN> in DNS
// (checked with a live lookup) before a public escalation is accepted.
define('PANEL_DOMAIN', 'ispledger.com');

// Escalation topics, comma separated. Used on the form and as wall filters.
define('ESCALATION_TOPICS', 'Billing & Payments,Payments Not Reflecting,Technical Billing Issues,Router / Connectivity,Hotspot Login Page,Speed & Bandwidth,SMS / Notifications,Non-responsive Support,Support Experience,Other');

// No-response reminders. When staff replied last and the customer stays
// silent for NUDGE_AFTER_HOURS, a reminder is posted on the thread; if the
// silence continues for NUDGE_RESOLVE_AFTER_HOURS more, the escalation is
// marked resolved automatically. Runs from cron.php or on page loads.
define('NUDGE_AFTER_HOURS', 48);
define('NUDGE_RESOLVE_AFTER_HOURS', 24);

// Submission rules
define('MIN_WORDS', 100);            // minimum words in the issue text
define('MAX_IMAGES', 4);             // issue pictures per escalation
define('MAX_IMAGE_BYTES', 5242880);  // 5 MB per image
define('RATE_LIMIT_PER_HOUR', 5);    // max submissions per IP per hour
