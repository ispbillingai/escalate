<?php
// Shared helpers for the Escalate platform.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

function e($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function clientIp()
{
    return substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function wordCount($text)
{
    $parts = preg_split('/\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY);
    return is_array($parts) ? count($parts) : 0;
}

function newPublicId()
{
    return substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 4) . bin2hex(random_bytes(3));
}

function escalationUrl($publicId)
{
    return rtrim(BASE_URL, '/') . '/view.php?id=' . rawurlencode($publicId);
}

function maskPhone($phone)
{
    $p = (string)$phone;
    if (strlen($p) <= 6) {
        return substr($p, 0, 2) . str_repeat('*', max(strlen($p) - 2, 1));
    }
    return substr($p, 0, 4) . str_repeat('*', strlen($p) - 6) . substr($p, -2);
}

function timeAgo($datetime)
{
    $ts = strtotime((string)$datetime);
    if (!$ts) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        $m = floor($diff / 60);
        return $m . ' minute' . ($m == 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = floor($diff / 3600);
        return $h . ' hour' . ($h == 1 ? '' : 's') . ' ago';
    }
    if ($diff < 2592000) {
        $d = floor($diff / 86400);
        return $d . ' day' . ($d == 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y', $ts);
}

function accountManagers()
{
    if (!defined('ACCOUNT_MANAGERS')) {
        return [];
    }
    $names = array_filter(array_map('trim', explode(',', ACCOUNT_MANAGERS)));
    return array_values($names);
}

function escalationTopics()
{
    $raw = defined('ESCALATION_TOPICS') ? ESCALATION_TOPICS
        : 'Billing & Payments,Payments Not Reflecting,Technical Billing Issues,Router / Connectivity,Hotspot Login Page,Speed & Bandwidth,SMS / Notifications,Non-responsive Support,Support Experience,Other';
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function panelDomain()
{
    return defined('PANEL_DOMAIN') ? PANEL_DOMAIN : 'ispledger.com';
}

/**
 * Normalize what the customer typed into a bare panel subdomain: lowercase,
 * strips protocol, a leading www., and the panel domain if they pasted the
 * full address. Returns '' when nothing usable is left.
 */
function normalizeSub($raw)
{
    $s = strtolower(trim((string)$raw));
    $s = preg_replace('#^https?://#', '', $s);
    $s = rtrim(explode('/', $s)[0], '.');
    $suffix = '.' . panelDomain();
    if (substr($s, -strlen($suffix)) === $suffix) {
        $s = substr($s, 0, -strlen($suffix));
    }
    if (strpos($s, 'www.') === 0) {
        $s = substr($s, 4);
    }
    return preg_replace('/[^a-z0-9\-]/', '', $s);
}

/**
 * Live DNS lookup (the dig check): does <sub>.<PANEL_DOMAIN> actually exist?
 * Accepts A, AAAA or CNAME so proxied setups still pass.
 */
function subdomainResolves($sub)
{
    if ($sub === '') {
        return false;
    }
    $host = $sub . '.' . panelDomain() . '.';
    return checkdnsrr($host, 'A') || checkdnsrr($host, 'AAAA') || checkdnsrr($host, 'CNAME');
}

function statusMeta($status)
{
    switch ($status) {
        case 'resolved':
            return ['label' => 'Resolved', 'class' => 'pill-resolved', 'icon' => '&#10004;', 'state' => 'st-resolved'];
        case 'in_review':
            return ['label' => 'In Review', 'class' => 'pill-review', 'icon' => '&#9685;', 'state' => 'st-review'];
        default:
            return ['label' => 'Open', 'class' => 'pill-open', 'icon' => '&#9679;', 'state' => 'st-open'];
    }
}

/** Uppercase first letter for the avatar circle. */
function avatarInitial($name)
{
    $c = mb_strtoupper(mb_substr(trim((string)$name), 0, 1));
    return $c !== '' ? $c : '?';
}

function excerptWords($text, $words = 40)
{
    $parts = preg_split('/\s+/u', trim((string)$text), -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return '';
    }
    if (count($parts) <= $words) {
        return implode(' ', $parts);
    }
    return implode(' ', array_slice($parts, 0, $words)) . ' ...';
}

function imagesOf(array $row)
{
    $imgs = json_decode((string)($row['images_json'] ?? '[]'), true);
    return is_array($imgs) ? $imgs : [];
}

// ---------------------------------------------------------------------------
// Thread replies. The wall never accepts replies directly: companies reply
// from their billing panel and staff from admin.php, so everything published
// went through our servers.

/** Replies for one escalation, oldest first. */
function repliesOf($escalationId, $limit = 100)
{
    $stmt = getDB()->prepare("SELECT author_type, author_name, body, images_json, created_at
        FROM escalation_replies WHERE escalation_id = ? ORDER BY id ASC LIMIT " . (int)$limit);
    $stmt->execute([(int)$escalationId]);
    return $stmt->fetchAll();
}

/** Image paths attached to one reply row. */
function replyImages(array $reply)
{
    $imgs = json_decode((string)($reply['images_json'] ?? ''), true);
    return is_array($imgs) ? $imgs : [];
}

/** Replies for many escalations at once, keyed by escalation id. */
function repliesForAll(array $escalationIds, $limitPer = 30)
{
    $out = [];
    $ids = array_values(array_filter(array_map('intval', $escalationIds)));
    if (!$ids) {
        return $out;
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("SELECT escalation_id, author_type, author_name, body, images_json, created_at
        FROM escalation_replies WHERE escalation_id IN ($marks) ORDER BY id ASC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $r) {
        $eid = (int)$r['escalation_id'];
        if (count($out[$eid] ?? []) < $limitPer) {
            $out[$eid][] = $r;
        }
    }
    return $out;
}

/**
 * Add a reply to an escalation and post it to Telegram (best effort).
 * Returns [ok(bool), errorOrReplyRow].
 */
function addReply(array $row, $authorType, $authorName, $body, $ip, array $imageFiles = [])
{
    $body = trim((string)$body);
    $authorType = $authorType === 'staff' ? 'staff' : 'company';
    $authorName = mb_substr(trim((string)$authorName), 0, 160);
    if (mb_strlen($body) < 2 && !$imageFiles) {
        return [false, 'Write the reply first.'];
    }
    if (mb_strlen($body) > 4000) {
        return [false, 'Keep the reply under 4000 characters.'];
    }
    if (count($imageFiles) > MAX_IMAGES) {
        return [false, 'A maximum of ' . MAX_IMAGES . ' images per reply is allowed.'];
    }
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM escalation_replies WHERE submit_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$ip]);
        if ($authorType !== 'staff' && (int)$stmt->fetchColumn() >= 20) {
            return [false, 'Too many replies from this connection in the last hour. Please try again later.'];
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM escalation_replies WHERE escalation_id = ?");
        $stmt->execute([(int)$row['id']]);
        if ((int)$stmt->fetchColumn() >= 100) {
            return [false, 'This escalation already has the maximum number of replies.'];
        }
    } catch (Throwable $ex) {
        error_log('[escalate] reply rate check failed: ' . $ex->getMessage());
    }
    $saved = [];
    try {
        foreach ($imageFiles as $i => $f) {
            $saved[] = saveImage($f, 'Reply image ' . ($i + 1));
        }
    } catch (Exception $ex) {
        foreach ($saved as $p) {
            @unlink(__DIR__ . '/' . $p);
        }
        return [false, $ex->getMessage()];
    }
    $stmt = $db->prepare("INSERT INTO escalation_replies (escalation_id, author_type, author_name, body, images_json, submit_ip) VALUES (?,?,?,?,?,?)");
    $stmt->execute([(int)$row['id'], $authorType, $authorName, $body, json_encode($saved), substr((string)$ip, 0, 45)]);
    if ($authorType === 'company') {
        // The customer spoke: any pending "no response in 2 days" countdown resets.
        try {
            $db->prepare("UPDATE escalations SET customer_nudged_at = NULL WHERE id = ?")->execute([(int)$row['id']]);
        } catch (Throwable $ex) {
            error_log('[escalate] nudge reset failed: ' . $ex->getMessage());
        }
    }
    postThreadReplyToTelegram($row, $authorType, $authorName, $body, $saved);
    return [true, ['author_type' => $authorType, 'author_name' => $authorName, 'body' => $body, 'images_json' => json_encode($saved), 'created_at' => date('Y-m-d H:i:s')]];
}

/** Post a thread reply under the original channel message. Best effort.
 *  Sent as Telegram HTML: bold header, and the reply body inside a
 *  <blockquote> so what was actually said stands apart from the metadata. */
function postThreadReplyToTelegram(array $row, $authorType, $authorName, $body, array $imagePaths = [])
{
    try {
        $esc = function ($s) {
            return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
        };
        $header = $authorType === 'staff'
            ? "\u{1F7E2} <b>FREEISPRADIUS TEAM (STAFF)</b>"
            : "\u{1F7E0} <b>CUSTOMER · " . $esc(mb_strtoupper($authorName !== '' ? $authorName : $row['company_name'])) . "</b> (from their billing panel)";
        $bodyText = trim(mb_substr((string)$body, 0, 3500));
        $text = $header . "\n"
            . "<b>REPLY ON ESCALATION #" . $esc($row['public_id']) . "</b>\n"
            . "Customer: " . $esc($row['company_name']) . "\n"
            . ($authorType === 'staff' ? "Status: " . $esc(statusMeta($row['status'])['label']) . "\n" : '')
            . ($imagePaths ? "Images attached below.\n" : '') . "\n"
            . ($bodyText !== '' ? "<blockquote>" . $esc($bodyText) . "</blockquote>\n\n" : '')
            . escalationUrl($row['public_id']);
        $params = [
            'chat_id'                  => TELEGRAM_CHAT_ID,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => 'true',
        ];
        if (telegramTopicId() > 0) {
            $params['message_thread_id'] = telegramTopicId();
        }
        if ($row['telegram_message_id'] !== '') {
            $params['reply_to_message_id'] = $row['telegram_message_id'];
        }
        $sent = tgApi('sendMessage', $params);
        $replyMsgId = ($sent && !empty($sent['ok'])) ? (string)$sent['result']['message_id'] : '';

        $paths = array_values(array_filter($imagePaths, function ($p) {
            return $p !== '' && is_file(__DIR__ . '/' . $p);
        }));
        if ($paths) {
            $caption = ($authorType === 'staff' ? "\u{1F7E2} Staff" : "\u{1F7E0} Customer " . $row['company_name'])
                . ' · reply on escalation #' . $row['public_id'];
            $photoParams = [
                'chat_id' => TELEGRAM_CHAT_ID,
            ];
            if (telegramTopicId() > 0) {
                $photoParams['message_thread_id'] = telegramTopicId();
            }
            if ($replyMsgId !== '') {
                $photoParams['reply_to_message_id'] = $replyMsgId;
            }
            if (count($paths) === 1) {
                $photoParams['photo'] = new CURLFile(__DIR__ . '/' . $paths[0]);
                $photoParams['caption'] = $caption;
                tgApi('sendPhoto', $photoParams);
            } else {
                $media = [];
                foreach ($paths as $i => $p) {
                    $item = ['type' => 'photo', 'media' => 'attach://photo' . $i];
                    if ($i === 0) {
                        $item['caption'] = $caption;
                    }
                    $media[] = $item;
                    $photoParams['photo' . $i] = new CURLFile(__DIR__ . '/' . $p);
                }
                $photoParams['media'] = json_encode($media);
                tgApi('sendMediaGroup', $photoParams);
            }
        }
    } catch (Throwable $ex) {
        error_log('[escalate] telegram thread reply failed: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Uploads

function detectImageExt($tmpPath)
{
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);
    $map = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    return $map[$mime] ?? null;
}

/**
 * Validate and store one uploaded image. Returns the relative path
 * (uploads/YYYY/MM/xxx.ext) or throws with a user-facing message.
 */
function saveImage(array $file, $label)
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new Exception($label . ': upload failed, please try again.');
    }
    if ($file['size'] > MAX_IMAGE_BYTES) {
        throw new Exception($label . ': image is larger than ' . round(MAX_IMAGE_BYTES / 1048576) . ' MB.');
    }
    $ext = detectImageExt($file['tmp_name']);
    if ($ext === null) {
        throw new Exception($label . ': only JPG, PNG, WEBP or GIF images are accepted.');
    }
    $dir = 'uploads/' . date('Y') . '/' . date('m');
    $abs = __DIR__ . '/' . $dir;
    if (!is_dir($abs) && !mkdir($abs, 0755, true)) {
        throw new Exception('Could not prepare the upload folder.');
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $abs . '/' . $name)) {
        throw new Exception($label . ': could not save the image.');
    }
    return $dir . '/' . $name;
}

/** Normalize a $_FILES entry for input name="images[]" into a flat list. */
function normalizeFilesArray($entry)
{
    $out = [];
    if (!is_array($entry) || !isset($entry['name'])) {
        return $out;
    }
    if (is_array($entry['name'])) {
        foreach ($entry['name'] as $i => $n) {
            if ((string)$n === '' && (int)$entry['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $out[] = [
                'name'     => $entry['name'][$i],
                'type'     => $entry['type'][$i],
                'tmp_name' => $entry['tmp_name'][$i],
                'error'    => $entry['error'][$i],
                'size'     => $entry['size'][$i],
            ];
        }
    } elseif ((string)$entry['name'] !== '' || (int)$entry['error'] !== UPLOAD_ERR_NO_FILE) {
        $out[] = $entry;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Telegram

function telegramChannelUrl()
{
    return (defined('TELEGRAM_CHANNEL_URL') && TELEGRAM_CHANNEL_URL !== '') ? TELEGRAM_CHANNEL_URL : '';
}

/** Forum topic (message_thread_id) escalation posts go into, 0 = none. */
function telegramTopicId()
{
    return defined('TELEGRAM_TOPIC_ID') ? (int)TELEGRAM_TOPIC_ID : 0;
}

/**
 * Member count of the public channel. Bot API when a token is configured,
 * otherwise the t.me preview page. Cached for 6 hours; falls back to the
 * last known value, then to the approximate community size.
 */
function telegramMemberCount()
{
    $url = telegramChannelUrl();
    if ($url === '') {
        return 0;
    }
    $cacheFile = __DIR__ . '/tg_members.cache';
    $cached = null;
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached) && ($cached['at'] ?? 0) > time() - 21600 && (int)($cached['count'] ?? 0) > 0) {
            return (int)$cached['count'];
        }
    }

    $count = 0;
    if (TELEGRAM_BOT_TOKEN !== '' && TELEGRAM_CHAT_ID !== '') {
        $res = tgApi('getChatMemberCount', ['chat_id' => TELEGRAM_CHAT_ID]);
        if ($res && !empty($res['ok'])) {
            $count = (int)$res['result'];
        }
    }
    if ($count === 0) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; EscalateBot/1.0)',
        ]);
        $html = (string)curl_exec($ch);
        curl_close($ch);
        if ($html !== '' && preg_match('/([0-9][0-9\s\x{00A0}\x{202F},\.]*)\s*(subscribers|members)/u', $html, $m)) {
            $count = (int)preg_replace('/[^0-9]/', '', $m[1]);
        }
    }
    if ($count > 0) {
        @file_put_contents($cacheFile, json_encode(['at' => time(), 'count' => $count]));
        return $count;
    }
    if (is_array($cached) && (int)($cached['count'] ?? 0) > 0) {
        return (int)$cached['count'];
    }
    return 1300;
}

function tgApi($method, array $params)
{
    if (TELEGRAM_BOT_TOKEN === '' || TELEGRAM_CHAT_ID === '') {
        return null;
    }
    $ch = curl_init('https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $out = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string)$out, true);
    return is_array($json) ? $json : null;
}

/**
 * Post an escalation to the Telegram channel: one text message with the
 * company, issue and link, then the pictures as an album replying to it.
 * Best effort: returns the message id or '' and never throws.
 */
function postEscalationToTelegram(array $row)
{
    try {
        $text = "\u{1F7E0} CUSTOMER · " . mb_strtoupper($row['company_name']) . "\n"
            . "NEW ESCALATION #" . $row['public_id'] . "\n\n"
            . "Company: " . $row['company_name'] . "\n"
            . ($row['subdomain'] !== '' ? "Panel: " . $row['subdomain'] . "." . panelDomain() . "\n" : '')
            . "Account manager: " . ($row['account_manager'] !== '' ? $row['account_manager'] : 'not set') . "\n"
            . "Topic: " . (($row['topic'] ?? '') !== '' ? $row['topic'] : 'Other') . "\n"
            . (($row['router'] ?? '') !== '' ? "Router: " . $row['router'] . "\n" : '')
            . "Follow-up number: " . $row['follow_up_number'] . "\n"
            . "Raised: " . ($row['source'] === 'panel' ? 'from their billing panel' : 'on the public platform') . "\n"
            . "Pictures of the issue and the reply support gave follow below.\n\n"
            . mb_substr((string)$row['issue'], 0, 3300) . "\n\n"
            . escalationUrl($row['public_id']);

        $params = [
            'chat_id'                  => TELEGRAM_CHAT_ID,
            'text'                     => $text,
            'disable_web_page_preview' => 'true',
        ];
        if (telegramTopicId() > 0) {
            $params['message_thread_id'] = telegramTopicId();
        }
        $sent = tgApi('sendMessage', $params);
        if (!$sent || empty($sent['ok'])) {
            return '';
        }
        $messageId = (string)$sent['result']['message_id'];

        // The topic is closed, so these posts are the whole record: every
        // image goes out captioned with the escalation and company it belongs
        // to, issue pictures and the support reply labelled separately.
        $exists = function ($p) {
            return $p !== '' && is_file(__DIR__ . '/' . $p);
        };
        $issuePaths = array_slice(array_values(array_filter(imagesOf($row), $exists)), 0, 9);
        $tag = "\u{1F7E0} Customer " . $row['company_name'] . ' · escalation #' . $row['public_id']
            . ($row['account_manager'] !== '' ? ' · manager: ' . $row['account_manager'] : '');

        if (count($issuePaths) === 1) {
            $photoParams = [
                'chat_id'             => TELEGRAM_CHAT_ID,
                'photo'               => new CURLFile(__DIR__ . '/' . $issuePaths[0]),
                'caption'             => $tag . ' · picture of the issue',
                'reply_to_message_id' => $messageId,
            ];
            if (telegramTopicId() > 0) {
                $photoParams['message_thread_id'] = telegramTopicId();
            }
            tgApi('sendPhoto', $photoParams);
        } elseif (count($issuePaths) > 1) {
            $media = [];
            $params = [
                'chat_id'             => TELEGRAM_CHAT_ID,
                'reply_to_message_id' => $messageId,
            ];
            if (telegramTopicId() > 0) {
                $params['message_thread_id'] = telegramTopicId();
            }
            foreach ($issuePaths as $i => $p) {
                $item = ['type' => 'photo', 'media' => 'attach://photo' . $i];
                if ($i === 0) {
                    $item['caption'] = $tag . ' · pictures of the issue';
                }
                $media[] = $item;
                $params['photo' . $i] = new CURLFile(__DIR__ . '/' . $p);
            }
            $params['media'] = json_encode($media);
            tgApi('sendMediaGroup', $params);
        }

        if ($exists((string)$row['support_screenshot'])) {
            $photoParams = [
                'chat_id'             => TELEGRAM_CHAT_ID,
                'photo'               => new CURLFile(__DIR__ . '/' . $row['support_screenshot']),
                'caption'             => $tag . ' · the reply support gave them',
                'reply_to_message_id' => $messageId,
            ];
            if (telegramTopicId() > 0) {
                $photoParams['message_thread_id'] = telegramTopicId();
            }
            tgApi('sendPhoto', $photoParams);
        }
        return $messageId;
    } catch (Throwable $ex) {
        error_log('[escalate] telegram post failed: ' . $ex->getMessage());
        return '';
    }
}

/**
 * WhatsApp alert to staff when a new escalation lands. Best effort, never
 * blocks or fails the submission. Configured via WHATSAPP_ALERT_* constants.
 */
function notifyEscalationByWhatsApp(array $row)
{
    if (!defined('WHATSAPP_ALERT_URL') || WHATSAPP_ALERT_URL === ''
        || !defined('WHATSAPP_ALERT_TO') || WHATSAPP_ALERT_TO === '') {
        return;
    }
    try {
        $msg = 'New escalation #' . $row['public_id']
            . ' from ' . $row['company_name']
            . ((string)($row['topic'] ?? '') !== '' ? ' (' . $row['topic'] . ')' : '')
            . ($row['account_manager'] !== '' ? ', manager ' . $row['account_manager'] : '')
            . '. ' . escalationUrl($row['public_id']);
        $url = WHATSAPP_ALERT_URL
            . '?to=' . rawurlencode(WHATSAPP_ALERT_TO)
            . '&msg=' . rawurlencode($msg)
            . ((defined('WHATSAPP_ALERT_SECRET') && WHATSAPP_ALERT_SECRET !== '') ? '&secret=' . rawurlencode(WHATSAPP_ALERT_SECRET) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        curl_exec($ch);
        curl_close($ch);
    } catch (Throwable $e) {
        error_log('[escalate] whatsapp alert failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------------
// No-response reminders. When staff replied and the customer has been silent
// for NUDGE_AFTER_HOURS, a reminder is posted on the thread; if the silence
// continues for NUDGE_RESOLVE_AFTER_HOURS more, the escalation auto-resolves.
// ONLY In Review threads participate: an Open escalation is one the team has
// not taken up yet, so it is never nudged and never auto-closed. Staff move a
// thread to In Review from the admin composer when they start handling it.
// Runs from cron.php and opportunistically on page loads (at most once/hour).

function nudgeAfterHours()
{
    return defined('NUDGE_AFTER_HOURS') ? max(1, (int)NUDGE_AFTER_HOURS) : 48;
}

function nudgeResolveAfterHours()
{
    return defined('NUDGE_RESOLVE_AFTER_HOURS') ? max(1, (int)NUDGE_RESOLVE_AFTER_HOURS) : 24;
}

function nudgeHoursHuman($h)
{
    if ($h % 24 === 0) {
        $d = (int)($h / 24);
        return $d . ' day' . ($d === 1 ? '' : 's');
    }
    return $h . ' hour' . ($h === 1 ? '' : 's');
}

function runAutoNudges()
{
    $db = getDB();
    $waitH = nudgeAfterHours();
    $graceH = nudgeResolveAfterHours();

    // 1. Remind: staff spoke last, the customer has been silent past the
    //    window, and no reminder was sent yet for this waiting period.
    $rows = $db->query("SELECT e.*,
            (SELECT r.author_type FROM escalation_replies r WHERE r.escalation_id = e.id ORDER BY r.id DESC LIMIT 1) AS last_author,
            (SELECT r.created_at FROM escalation_replies r WHERE r.escalation_id = e.id ORDER BY r.id DESC LIMIT 1) AS last_reply_at
        FROM escalations e
        WHERE e.status = 'in_review' AND e.customer_nudged_at IS NULL")->fetchAll();
    foreach ($rows as $row) {
        if ($row['last_author'] === 'staff') {
            $staffAt = $row['last_reply_at'];
        } elseif ($row['last_author'] === null && (string)$row['official_reply'] !== '' && $row['replied_at']) {
            $staffAt = $row['replied_at'];
        } else {
            continue;   // the ball is in our court, never rush the customer
        }
        if (strtotime((string)$staffAt) > time() - $waitH * 3600) {
            continue;
        }
        // Guarded update so two overlapping runs cannot double-post.
        $upd = $db->prepare("UPDATE escalations SET customer_nudged_at = NOW() WHERE id = ? AND customer_nudged_at IS NULL");
        $upd->execute([(int)$row['id']]);
        if ($upd->rowCount() > 0) {
            addReply($row, 'staff', 'freeispradius team',
                'Hello ' . $row['company_name'] . ', just checking in: we have not received a response from you in '
                . nudgeHoursHuman($waitH) . '. If we do not hear back within the next ' . nudgeHoursHuman($graceH)
                . ', this escalation will be marked as resolved. You can reply any time from your billing panel.',
                'auto');
        }
    }

    // 2. Resolve: the reminder aged past the grace window with no customer
    //    reply since it was sent.
    $stmt = $db->prepare("SELECT * FROM escalations e
        WHERE e.status = 'in_review' AND e.customer_nudged_at IS NOT NULL
          AND e.customer_nudged_at <= DATE_SUB(NOW(), INTERVAL " . $graceH . " HOUR)
          AND NOT EXISTS (SELECT 1 FROM escalation_replies r
              WHERE r.escalation_id = e.id AND r.author_type = 'company' AND r.created_at >= e.customer_nudged_at)");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $upd = $db->prepare("UPDATE escalations SET status = 'resolved' WHERE id = ? AND status = 'in_review'");
        $upd->execute([(int)$row['id']]);
        if ($upd->rowCount() > 0) {
            $row['status'] = 'resolved';
            addReply($row, 'staff', 'freeispradius team',
                'We did not receive a response after our reminder, so this escalation has been marked as resolved. '
                . 'If the issue is not sorted on your side, reply from your billing panel and the team will pick it up again.',
                'auto');
        }
    }
}

/** Throttled entry point for page loads: runs the nudge pass at most hourly. */
function autoNudgeTick()
{
    $stamp = __DIR__ . '/auto_nudge.cache';
    $last = is_file($stamp) ? (int)file_get_contents($stamp) : 0;
    if ($last > time() - 3600) {
        return;
    }
    @file_put_contents($stamp, (string)time());
    try {
        runAutoNudges();
    } catch (Throwable $ex) {
        error_log('[escalate] auto nudge failed: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Core: create an escalation (shared by the public form and the panel API)

/**
 * @param array      $in          company_name, subdomain, follow_up_number, issue, account_manager, topic
 * @param array      $issueFiles  flat list of $_FILES-style arrays (the issue pictures)
 * @param array|null $supportFile single $_FILES-style array (support reply screenshot, required)
 * @param string     $source      'web' or 'panel'
 * @return array [ok(bool), payload(row|['errors'=>[]])]
 */
function createEscalation(array $in, array $issueFiles, $supportFile, $source)
{
    $errors = [];

    $company = trim((string)($in['company_name'] ?? ''));
    $sub = normalizeSub($in['subdomain'] ?? '');
    $phone = preg_replace('/[^0-9+]/', '', (string)($in['follow_up_number'] ?? ''));
    $issue = trim((string)($in['issue'] ?? ''));
    $manager = trim((string)($in['account_manager'] ?? ''));
    $topic = trim((string)($in['topic'] ?? ''));
    $router = mb_substr(trim((string)($in['router'] ?? '')), 0, 120);   // optional

    if ($source === 'panel') {
        // Panels are key-authenticated and send their own subdomain; some run
        // on custom domains, so no DNS gate here.
        if (mb_strlen($company) < 2 || mb_strlen($company) > 160) {
            $errors[] = 'Please give your company name.';
        }
    } else {
        // On the public form the company name IS the panel subdomain. It must
        // resolve in DNS as <company>.<PANEL_DOMAIN> to prove the account exists.
        $sub = $sub !== '' ? $sub : normalizeSub($company);
        if ($sub === '' || strlen($sub) > 64) {
            $errors[] = 'Give your company name exactly as it appears in your panel address (the part before .' . panelDomain() . ').';
        } elseif (!subdomainResolves($sub)) {
            $errors[] = 'We could not find ' . $sub . '.' . panelDomain() . '. Enter the company name exactly as it appears in your panel address so we can verify your account.';
        }
        $company = $sub;
    }

    // One escalation at a time: while this customer still has an unresolved
    // escalation on the wall, a fresh one is refused and they are pointed at
    // the existing thread instead. Marking it resolved frees the slot again.
    if (!$errors) {
        try {
            $stmt = getDB()->prepare("SELECT public_id, status, created_at FROM escalations
                WHERE " . ($sub !== '' ? 'subdomain' : 'company_name') . " = ? AND status <> 'resolved'
                ORDER BY id DESC LIMIT 1");
            $stmt->execute([$sub !== '' ? $sub : $company]);
            $open = $stmt->fetch();
            if ($open) {
                return [false, ['errors' => ['You already have an escalation that is still '
                    . statusMeta($open['status'])['label'] . ': #' . $open['public_id']
                    . ', raised ' . timeAgo($open['created_at'])
                    . '. One escalation is allowed at a time, so reply on that thread instead; replies reach the team just as loudly. Once it is marked resolved you can raise a new one. '
                    . escalationUrl($open['public_id'])]]];
            }
        } catch (Throwable $ex) {
            error_log('[escalate] open-escalation check failed: ' . $ex->getMessage());
        }
    }

    if (!in_array($topic, escalationTopics(), true)) {
        $topic = 'Other';
    }
    if (mb_strlen($manager) < 2 || mb_strlen($manager) > 120) {
        $errors[] = 'Write the name of your account manager.';
    }
    if (strlen($phone) < 7 || strlen($phone) > 16) {
        $errors[] = 'Please give a valid follow-up phone number.';
    }
    $words = wordCount($issue);
    if ($words < MIN_WORDS) {
        $errors[] = 'Describe the issue in at least ' . MIN_WORDS . ' words. You wrote ' . $words . '.';
    }
    if (count($issueFiles) < 1) {
        $errors[] = 'Attach at least one picture of the issue.';
    }
    if (count($issueFiles) > MAX_IMAGES) {
        $errors[] = 'A maximum of ' . MAX_IMAGES . ' issue pictures is allowed.';
    }
    if ($supportFile === null || (int)($supportFile['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Attach a screenshot of the reply you got from support.';
    }

    $ip = clientIp();
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT COUNT(*) FROM escalations WHERE submit_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute([$ip]);
        if ((int)$stmt->fetchColumn() >= RATE_LIMIT_PER_HOUR) {
            $errors[] = 'Too many escalations from this connection in the last hour. Please try again later.';
        }
    } catch (Throwable $ex) {
        error_log('[escalate] rate check failed: ' . $ex->getMessage());
    }

    if ($errors) {
        return [false, ['errors' => $errors]];
    }

    $saved = [];
    $supportPath = '';
    try {
        foreach ($issueFiles as $i => $f) {
            $saved[] = saveImage($f, 'Issue picture ' . ($i + 1));
        }
        $supportPath = saveImage($supportFile, 'Support reply screenshot');
    } catch (Exception $ex) {
        foreach ($saved as $p) {
            @unlink(__DIR__ . '/' . $p);
        }
        return [false, ['errors' => [$ex->getMessage()]]];
    }

    $publicId = newPublicId();
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO escalations
        (public_id, company_name, subdomain, follow_up_number, issue, images_json,
         support_screenshot, account_manager, topic, router, source, submit_ip)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $publicId, $company, $sub, $phone, $issue, json_encode($saved),
        $supportPath, $manager, $topic, $router, $source === 'panel' ? 'panel' : 'web', $ip,
    ]);

    $row = $db->prepare("SELECT * FROM escalations WHERE public_id = ?");
    $row->execute([$publicId]);
    $row = $row->fetch();

    $messageId = postEscalationToTelegram($row);
    if ($messageId !== '') {
        $upd = $db->prepare("UPDATE escalations SET telegram_message_id = ? WHERE id = ?");
        $upd->execute([$messageId, $row['id']]);
        $row['telegram_message_id'] = $messageId;
    }

    notifyEscalationByWhatsApp($row);

    return [true, $row];
}

// ---------------------------------------------------------------------------
// Shared page chrome

function pageHeader($title, $active = '')
{
    $nav = function ($key, $href, $label) use ($active) {
        $cls = $key === $active ? 'nav-link active' : 'nav-link';
        return '<a class="' . $cls . '" href="' . e($href) . '">' . e($label) . '</a>';
    };
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . '</title>'
        . '<link rel="icon" href="data:image/svg+xml,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y="80" font-size="80">&#128752;</text></svg>') . '">'
        . '<link rel="stylesheet" href="assets/style.css?v=' . (int)@filemtime(__DIR__ . '/assets/style.css') . '">'
        . '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>'
        . '<script>window.notify=function(t,m,i){if(window.Swal){Swal.fire({title:t,text:m,icon:i||"info",confirmButtonColor:"#3f6fe8",background:"#101728",color:"#e8ecf7"});}else{alert(t+"\n"+m);}};</script>'
        . '</head><body><div class="stars" aria-hidden="true"></div>'
        . '<header class="topbar"><div class="wrap topbar-inner">'
        . '<a class="brand" href="index.php"><span class="brand-icon">&#128752;</span>'
        . '<span class="brand-text">Escalate<small>by freeispradius</small></span></a>'
        . '<nav class="nav">'
        . $nav('wall', 'index.php', 'Escalation Wall')
        . $nav('submit', 'submit.php', 'Raise an Escalation')
        . (telegramChannelUrl() !== ''
            ? '<a class="nav-link" href="' . e(telegramChannelUrl()) . '" target="_blank" rel="noopener">Telegram Channel</a>'
            : '')
        . '</nav></div></header><main class="wrap">';
}

function pageFooter()
{
    echo '</main><footer class="footer"><div class="wrap">'
        . '<p>Every escalation on this platform is public and is posted to our Telegram channel, so nothing gets buried. '
        . 'We read every single one and call back on the follow-up number provided.</p>'
        . '<p class="footer-links"><a href="https://ispledger.com" target="_blank" rel="noopener">ispledger.com</a>'
        . (telegramChannelUrl() !== ''
            ? '<span>&middot;</span><a href="' . e(telegramChannelUrl()) . '" target="_blank" rel="noopener">Telegram channel</a>'
            : '')
        . '<span>&middot;</span><a href="submit.php">Raise an escalation</a>'
        . '<span>&middot;</span><a href="admin.php">Staff</a></p>'
        . '</div></footer></body></html>';
}
