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

function statusMeta($status)
{
    switch ($status) {
        case 'resolved':
            return ['label' => 'Resolved', 'class' => 'pill-resolved'];
        case 'in_review':
            return ['label' => 'In Review', 'class' => 'pill-review'];
        default:
            return ['label' => 'Open', 'class' => 'pill-open'];
    }
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
        $text = "NEW ESCALATION #" . $row['public_id'] . "\n\n"
            . "Company: " . $row['company_name']
            . ($row['subdomain'] !== '' ? " (" . $row['subdomain'] . ")" : '') . "\n"
            . "Account manager: " . ($row['account_manager'] !== '' ? $row['account_manager'] : 'not set') . "\n"
            . "Follow-up number: " . $row['follow_up_number'] . "\n"
            . "Raised: " . ($row['source'] === 'panel' ? 'from their billing panel' : 'on the public platform') . "\n"
            . "A screenshot of the reply support gave is attached.\n\n"
            . mb_substr((string)$row['issue'], 0, 3300) . "\n\n"
            . escalationUrl($row['public_id']);

        $sent = tgApi('sendMessage', [
            'chat_id'                  => TELEGRAM_CHAT_ID,
            'text'                     => $text,
            'disable_web_page_preview' => 'true',
        ]);
        if (!$sent || empty($sent['ok'])) {
            return '';
        }
        $messageId = (string)$sent['result']['message_id'];

        $paths = imagesOf($row);
        if ($row['support_screenshot'] !== '') {
            $paths[] = $row['support_screenshot'];
        }
        $paths = array_values(array_filter($paths, function ($p) {
            return is_file(__DIR__ . '/' . $p);
        }));
        $paths = array_slice($paths, 0, 10);

        if (count($paths) === 1) {
            tgApi('sendPhoto', [
                'chat_id'             => TELEGRAM_CHAT_ID,
                'photo'               => new CURLFile(__DIR__ . '/' . $paths[0]),
                'reply_to_message_id' => $messageId,
            ]);
        } elseif (count($paths) > 1) {
            $media = [];
            $params = [
                'chat_id'             => TELEGRAM_CHAT_ID,
                'reply_to_message_id' => $messageId,
            ];
            foreach ($paths as $i => $p) {
                $media[] = ['type' => 'photo', 'media' => 'attach://photo' . $i];
                $params['photo' . $i] = new CURLFile(__DIR__ . '/' . $p);
            }
            $params['media'] = json_encode($media);
            tgApi('sendMediaGroup', $params);
        }
        return $messageId;
    } catch (Throwable $ex) {
        error_log('[escalate] telegram post failed: ' . $ex->getMessage());
        return '';
    }
}

/** Post a status update or official reply under the original channel message. */
function postReplyToTelegram(array $row, $replyText)
{
    try {
        $text = "UPDATE ON ESCALATION #" . $row['public_id'] . "\n"
            . "Company: " . $row['company_name'] . "\n"
            . "Status: " . statusMeta($row['status'])['label'] . "\n\n"
            . mb_substr((string)$replyText, 0, 3500) . "\n\n"
            . escalationUrl($row['public_id']);
        $params = [
            'chat_id'                  => TELEGRAM_CHAT_ID,
            'text'                     => $text,
            'disable_web_page_preview' => 'true',
        ];
        if ($row['telegram_message_id'] !== '') {
            $params['reply_to_message_id'] = $row['telegram_message_id'];
        }
        tgApi('sendMessage', $params);
    } catch (Throwable $ex) {
        error_log('[escalate] telegram reply failed: ' . $ex->getMessage());
    }
}

// ---------------------------------------------------------------------------
// Core: create an escalation (shared by the public form and the panel API)

/**
 * @param array      $in          company_name, subdomain, follow_up_number, issue, account_manager
 * @param array      $issueFiles  flat list of $_FILES-style arrays (the issue pictures)
 * @param array|null $supportFile single $_FILES-style array (support reply screenshot, required)
 * @param string     $source      'web' or 'panel'
 * @return array [ok(bool), payload(row|['errors'=>[]])]
 */
function createEscalation(array $in, array $issueFiles, $supportFile, $source)
{
    $errors = [];

    $company = trim((string)($in['company_name'] ?? ''));
    $sub = strtolower(trim((string)($in['subdomain'] ?? '')));
    $sub = preg_replace('/[^a-z0-9\-\.]/', '', $sub);
    $phone = preg_replace('/[^0-9+]/', '', (string)($in['follow_up_number'] ?? ''));
    $issue = trim((string)($in['issue'] ?? ''));
    $manager = trim((string)($in['account_manager'] ?? ''));

    if (mb_strlen($company) < 2 || mb_strlen($company) > 160) {
        $errors[] = 'Please give your company name.';
    }
    $managers = accountManagers();
    if ($managers && !in_array($manager, $managers, true)) {
        $errors[] = 'Choose your account manager from the list.';
    } elseif (!$managers && $manager === '') {
        $errors[] = 'Give the name of your account manager.';
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
         support_screenshot, account_manager, source, submit_ip)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([
        $publicId, $company, $sub, $phone, $issue, json_encode($saved),
        $supportPath, $manager, $source === 'panel' ? 'panel' : 'web', $ip,
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
        . '<link rel="stylesheet" href="assets/style.css">'
        . '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>'
        . '<script>window.notify=function(t,m,i){if(window.Swal){Swal.fire({title:t,text:m,icon:i||"info",confirmButtonColor:"#3f6fe8",background:"#101728",color:"#e8ecf7"});}else{alert(t+"\n"+m);}};</script>'
        . '</head><body><div class="stars" aria-hidden="true"></div>'
        . '<header class="topbar"><div class="wrap topbar-inner">'
        . '<a class="brand" href="index.php"><span class="brand-icon">&#128752;</span>'
        . '<span class="brand-text">Escalate<small>by ISP Ledger</small></span></a>'
        . '<nav class="nav">'
        . $nav('wall', 'index.php', 'Escalation Wall')
        . $nav('submit', 'submit.php', 'Raise an Escalation')
        . '</nav></div></header><main class="wrap">';
}

function pageFooter()
{
    echo '</main><footer class="footer"><div class="wrap">'
        . '<p>Every escalation on this platform is public and is posted to our Telegram channel, so nothing gets buried. '
        . 'We read every single one and call back on the follow-up number provided.</p>'
        . '<p class="footer-links"><a href="https://ispledger.com" target="_blank" rel="noopener">ispledger.com</a>'
        . '<span>&middot;</span><a href="submit.php">Raise an escalation</a>'
        . '<span>&middot;</span><a href="admin.php">Staff</a></p>'
        . '</div></footer></body></html>';
}
