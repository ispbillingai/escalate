<?php
// JSON API for the billing panels.
//
// POST (multipart/form-data)  -> create an escalation on behalf of a tenant.
//   fields: company_name, subdomain, follow_up_number, issue, account_manager, topic
//   files:  images[] (1..MAX_IMAGES), support_screenshot (required)
//
// GET ?action=list&sub=<subdomain>  -> that tenant's escalations (public data only)
//                                      plus the account manager list.
// GET ?action=managers              -> just the account manager list.
//
// This endpoint always answers with JSON and never redirects.

require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jout(array $data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') === 'managers') {
        jout(['ok' => true, 'managers' => accountManagers(), 'topics' => escalationTopics()]);
    }
    if (($_GET['action'] ?? '') === 'checksub') {
        // Live account check used by the public form: does the typed company
        // name resolve as <sub>.<PANEL_DOMAIN> in DNS?
        $sub = normalizeSub($_GET['sub'] ?? '');
        jout([
            'ok'    => true,
            'sub'   => $sub,
            'host'  => $sub !== '' ? $sub . '.' . panelDomain() : '',
            'valid' => $sub !== '' && subdomainResolves($sub),
        ]);
    }
    if (($_GET['action'] ?? '') !== 'list') {
        jout(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
    $sub = normalizeSub($_GET['sub'] ?? '');
    if ($sub === '') {
        jout(['ok' => true, 'items' => [], 'managers' => accountManagers(), 'topics' => escalationTopics()]);
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT public_id, company_name, status, topic, issue, official_reply, replied_at, created_at
        FROM escalations WHERE subdomain = ? ORDER BY id DESC LIMIT 100");
    $stmt->execute([$sub]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'id'             => $row['public_id'],
            'company'        => $row['company_name'],
            'status'         => $row['status'],
            'status_label'   => statusMeta($row['status'])['label'],
            'topic'          => (string)($row['topic'] ?? ''),
            'excerpt'        => excerptWords($row['issue'], 30),
            'official_reply' => (string)$row['official_reply'],
            'replied_at'     => (string)$row['replied_at'],
            'created_at'     => (string)$row['created_at'],
            'url'            => escalationUrl($row['public_id']),
        ];
    }
    jout(['ok' => true, 'items' => $items, 'managers' => accountManagers(), 'topics' => escalationTopics()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jout(['ok' => false, 'error' => 'POST required.'], 405);
}

$issueFiles = normalizeFilesArray($_FILES['images'] ?? null);
$supportFile = (isset($_FILES['support_screenshot']) && (int)$_FILES['support_screenshot']['error'] !== UPLOAD_ERR_NO_FILE)
    ? $_FILES['support_screenshot'] : null;

list($ok, $payload) = createEscalation($_POST, $issueFiles, $supportFile, 'panel');

if (!$ok) {
    jout(['ok' => false, 'error' => implode(' ', $payload['errors']), 'errors' => $payload['errors']], 422);
}

jout([
    'ok'  => true,
    'id'  => $payload['public_id'],
    'url' => escalationUrl($payload['public_id']),
]);
