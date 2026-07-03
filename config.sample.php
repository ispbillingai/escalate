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
define('TELEGRAM_CHAT_ID', '');   // e.g. '@ispledger_escalations' or '-1001234567890'

// Shared key the billing panels send when submitting on behalf of a tenant
define('PANEL_API_KEY', 'change_this_key');

// Password for the moderation area (admin.php)
define('ADMIN_PASSWORD', 'change_this_password');

// Submission rules
define('MIN_WORDS', 100);            // minimum words in the issue text
define('MAX_IMAGES', 4);             // issue pictures per escalation
define('MAX_IMAGE_BYTES', 5242880);  // 5 MB per image
define('RATE_LIMIT_PER_HOUR', 5);    // max submissions per IP per hour
