<?php
// Staff moderation area: update status, post the official reply, retry the
// Telegram post, delete spam. Password comes from ADMIN_PASSWORD in config.php.
require_once __DIR__ . '/lib.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['esc_admin']);
    header('Location: admin.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && empty($_SESSION['esc_admin'])) {
    if (hash_equals(ADMIN_PASSWORD, (string)$_POST['password'])) {
        $_SESSION['esc_admin'] = true;
        header('Location: admin.php');
        exit;
    }
    $loginError = 'Wrong password.';
}

if (empty($_SESSION['esc_admin'])) {
    pageHeader('Staff sign in', '');
    echo '<div class="form-card" style="max-width:420px;margin-top:60px;"><h1>Staff sign in</h1>';
    if (!empty($loginError)) {
        echo '<div class="errors"><ul><li>' . e($loginError) . '</li></ul></div>';
    }
    echo '<form method="post" action="admin.php">'
        . '<div class="field"><label>Password</label><input type="text" name="password" autocomplete="off" style="-webkit-text-security:disc;"></div>'
        . '<button class="btn" type="submit" style="width:100%;">Sign in</button></form></div>';
    pageFooter();
    exit;
}

$db = getDB();
autoNudgeTick();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do'])) {
    $rid = (int)($_POST['rid'] ?? 0);
    $stmt = $db->prepare("SELECT * FROM escalations WHERE id = ?");
    $stmt->execute([$rid]);
    $row = $stmt->fetch();

    if ($row) {
        switch ($_POST['do']) {
            case 'respond':
                // One composer: sets the status and, if anything was written or
                // attached, posts it as a staff reply on the public thread.
                $status = in_array($_POST['status'] ?? '', ['open', 'in_review', 'resolved'], true) ? $_POST['status'] : $row['status'];
                $upd = $db->prepare("UPDATE escalations SET status = ?, customer_nudged_at = NULL WHERE id = ?");
                $upd->execute([$status, $rid]);
                $body = trim((string)($_POST['reply_body'] ?? ''));
                $replyImgs = normalizeFilesArray($_FILES['reply_images'] ?? null);
                if ($body !== '' || $replyImgs) {
                    $row['status'] = $status;
                    list($ok,) = addReply($row, 'staff', 'freeispradius team', $body, clientIp(), $replyImgs);
                    if ($ok) {
                        $db->prepare("UPDATE escalations SET replied_at = NOW() WHERE id = ?")->execute([$rid]);
                    }
                }
                break;
            case 'retry_telegram':
                if ($row['telegram_message_id'] === '') {
                    $messageId = postEscalationToTelegram($row);
                    if ($messageId !== '') {
                        $upd = $db->prepare("UPDATE escalations SET telegram_message_id = ? WHERE id = ?");
                        $upd->execute([$messageId, $rid]);
                    }
                }
                break;
            case 'delete':
                foreach (imagesOf($row) as $p) {
                    @unlink(__DIR__ . '/' . $p);
                }
                if ($row['support_screenshot'] !== '') {
                    @unlink(__DIR__ . '/' . $row['support_screenshot']);
                }
                $del = $db->prepare("DELETE FROM escalations WHERE id = ?");
                $del->execute([$rid]);
                break;
        }
    }
    header('Location: admin.php' . (isset($_POST['back']) ? '?' . $_POST['back'] : ''));
    exit;
}

$status = $_GET['status'] ?? '';
if (!in_array($status, ['open', 'in_review', 'resolved'], true)) {
    $status = '';
}
$aq = trim((string)($_GET['q'] ?? ''));
$where = [];
$args = [];
if ($status !== '') {
    $where[] = 'status = ?';
    $args[] = $status;
}
if ($aq !== '') {
    $where[] = '(company_name LIKE ? OR subdomain LIKE ? OR public_id = ? OR issue LIKE ? OR account_manager LIKE ? OR follow_up_number LIKE ?)';
    $args[] = '%' . $aq . '%';
    $args[] = '%' . $aq . '%';
    $args[] = $aq;
    $args[] = '%' . $aq . '%';
    $args[] = '%' . $aq . '%';
    $args[] = '%' . $aq . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stmt = $db->prepare("SELECT * FROM escalations $whereSql ORDER BY id DESC LIMIT 200");
$stmt->execute($args);
$rows = $stmt->fetchAll();
$adminThreads = repliesForAll(array_column($rows, 'id'));
$backQs = http_build_query(array_filter(['status' => $status, 'q' => $aq]));

pageHeader('Escalations moderation', '');
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin:30px 0 18px;">
    <h1 style="font-size:24px;">Moderation (<?php echo count($rows); ?>)</h1>
    <div class="tabs">
        <a class="tab <?php echo $status === '' ? 'active' : ''; ?>" href="admin.php<?php echo $aq !== '' ? '?q=' . rawurlencode($aq) : ''; ?>">All</a>
        <a class="tab <?php echo $status === 'open' ? 'active' : ''; ?>" href="admin.php?status=open<?php echo $aq !== '' ? '&q=' . rawurlencode($aq) : ''; ?>">Open</a>
        <a class="tab <?php echo $status === 'in_review' ? 'active' : ''; ?>" href="admin.php?status=in_review<?php echo $aq !== '' ? '&q=' . rawurlencode($aq) : ''; ?>">In Review</a>
        <a class="tab <?php echo $status === 'resolved' ? 'active' : ''; ?>" href="admin.php?status=resolved<?php echo $aq !== '' ? '&q=' . rawurlencode($aq) : ''; ?>">Resolved</a>
        <a class="tab" href="admin.php?logout=1">Sign out</a>
    </div>
    <form class="searchbox" method="get" action="admin.php">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
        <input type="text" name="q" value="<?php echo e($aq); ?>" placeholder="Search company, phone, manager, text...">
        <button class="btn small" type="submit">Search</button>
    </form>
</div>

<div class="detail" style="margin-top:0;overflow-x:auto;">
<table class="admintable">
    <tr><th>Escalation</th><th>Contact</th><th>Manage</th></tr>
    <?php foreach ($rows as $row): $meta = statusMeta($row['status']); ?>
    <tr>
        <td style="max-width:420px;">
            <b><?php echo e($row['company_name']); ?></b>
            <span class="pill <?php echo $meta['class']; ?>" style="margin-left:6px;"><?php echo $meta['label']; ?></span><br>
            <span style="color:var(--muted);font-size:12.5px;">
                #<?php echo e($row['public_id']); ?> &middot; <?php echo e($row['created_at']); ?> &middot; <?php echo e($row['source']); ?>
                <?php echo $row['subdomain'] !== '' ? '&middot; ' . e($row['subdomain']) : ''; ?>
                <?php echo (string)($row['topic'] ?? '') !== '' ? '&middot; ' . e($row['topic']) : ''; ?>
                <?php echo (string)($row['router'] ?? '') !== '' ? '&middot; router ' . e($row['router']) : ''; ?>
                <?php echo $row['telegram_message_id'] === '' ? '&middot; <span style="color:var(--amber)">not on Telegram</span>' : ''; ?>
                <?php echo !empty($row['customer_nudged_at']) && $row['status'] !== 'resolved' ? '&middot; <span style="color:var(--amber)">reminder sent ' . e(timeAgo($row['customer_nudged_at'])) . ', auto-resolves after ' . e(nudgeHoursHuman(nudgeResolveAfterHours())) . '</span>' : ''; ?>
            </span>
            <p style="margin-top:6px;color:#c6d1e8;"><?php echo e(excerptWords($row['issue'], 45)); ?></p>
            <?php $tRep = $adminThreads[(int)$row['id']] ?? []; ?>
            <?php if ($tRep || (string)$row['official_reply'] !== ''): ?>
                <div style="margin-top:6px;border-left:2px solid var(--border);padding-left:8px;">
                <?php if ((string)$row['official_reply'] !== ''): ?>
                    <div style="font-size:12px;color:var(--green);"><b>Official reply:</b> <?php echo e(excerptWords($row['official_reply'], 18)); ?></div>
                <?php endif; ?>
                <?php foreach (array_slice($tRep, -3) as $r): ?>
                    <div style="font-size:12px;color:<?php echo $r['author_type'] === 'staff' ? 'var(--green)' : 'var(--muted)'; ?>;">
                        <b><?php echo $r['author_type'] === 'staff' ? 'Staff' : e($r['author_name'] !== '' ? $r['author_name'] : $row['company_name']); ?>:</b>
                        <?php echo e(excerptWords($r['body'], 18)); ?><?php $rImgCount = count(replyImages($r)); echo $rImgCount ? ' &#128247;' . $rImgCount : ''; ?>
                    </div>
                <?php endforeach; ?>
                <?php if (count($tRep) > 3): ?><div style="font-size:11.5px;color:var(--muted);"><?php echo count($tRep) - 3; ?> earlier repl<?php echo count($tRep) - 3 === 1 ? 'y' : 'ies'; ?> on the public page</div><?php endif; ?>
                </div>
            <?php endif; ?>
            <a class="readmore" href="view.php?id=<?php echo e(rawurlencode($row['public_id'])); ?>" target="_blank">Open public page</a>
        </td>
        <td style="white-space:nowrap;">
            <?php echo e($row['follow_up_number']); ?><br>
            <?php if ($row['account_manager'] !== ''): ?>
                <span style="color:var(--muted);font-size:12.5px;">Manager: <?php echo e($row['account_manager']); ?></span><br>
            <?php endif; ?>
            <span style="color:var(--muted);font-size:12.5px;">IP <?php echo e($row['submit_ip']); ?></span>
        </td>
        <td style="min-width:290px;">
            <form method="post" action="admin.php" enctype="multipart/form-data" class="respond-form">
                <input type="hidden" name="do" value="respond">
                <input type="hidden" name="rid" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="back" value="<?php echo e($backQs); ?>">
                <select name="status">
                    <option value="open" <?php echo $row['status'] === 'open' ? 'selected' : ''; ?>>Open</option>
                    <option value="in_review" <?php echo $row['status'] === 'in_review' ? 'selected' : ''; ?>>In Review</option>
                    <option value="resolved" <?php echo $row['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                </select>
                <textarea name="reply_body" rows="3" style="width:100%;margin:8px 0 0;" placeholder="Reply to the customer: posted on the public thread and Telegram"></textarea>
                <label class="minidrop">Attach images: click, drag &amp; drop, or Ctrl+V
                    <input type="file" name="reply_images[]" accept="image/*" multiple>
                </label>
                <div class="previews"></div>
                <button class="btn small" type="submit">Save</button>
            </form>
            <form method="post" action="admin.php" style="display:inline-block;margin-top:8px;">
                <input type="hidden" name="do" value="retry_telegram">
                <input type="hidden" name="rid" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="back" value="<?php echo e($backQs); ?>">
                <button class="btn small ghost" type="submit" <?php echo $row['telegram_message_id'] !== '' ? 'disabled' : ''; ?>>Retry Telegram</button>
            </form>
            <form method="post" action="admin.php" style="display:inline-block;margin-top:8px;" class="confirm-delete">
                <input type="hidden" name="do" value="delete">
                <input type="hidden" name="rid" value="<?php echo (int)$row['id']; ?>">
                <input type="hidden" name="back" value="<?php echo e($backQs); ?>">
                <button class="btn small danger" type="submit">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
</div>

<script>
// Reply composer: removable previews plus paste (Ctrl+V) and drag & drop.
// Click into a row's reply box first; that composer becomes the paste target.
(function () {
    var MAX_IMAGES = <?php echo (int)MAX_IMAGES; ?>;
    var canDT = true;
    try { new DataTransfer(); } catch (e) { canDT = false; }
    if (!canDT) { return; }   // the plain file input still works

    var active = null;
    var pasteN = 0;

    function named(f) {
        pasteN++;
        var ext = (f.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
        try { return new File([f], 'pasted-' + Date.now() + '-' + pasteN + '.' + ext, { type: f.type }); }
        catch (e) { return f; }
    }
    function imageFiles(dt) {
        var out = [];
        var items = (dt && dt.items) ? dt.items : [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file') {
                var f = items[i].getAsFile();
                if (f && f.type.indexOf('image/') === 0) { out.push(f); }
            }
        }
        if (!out.length && dt && dt.files) {
            out = Array.prototype.slice.call(dt.files).filter(function (f) {
                return f.type && f.type.indexOf('image/') === 0;
            });
        }
        return out;
    }
    function flash(el) {
        el.classList.remove('flash');
        void el.offsetWidth;
        el.classList.add('flash');
    }

    document.querySelectorAll('form.respond-form').forEach(function (form) {
        form.classList.add('cdt');
        var input = form.querySelector('input[type=file]');
        var zone = form.querySelector('.minidrop');
        var holder = form.querySelector('.previews');
        var files = [];

        function sync() {
            var dt = new DataTransfer();
            files.forEach(function (f) { dt.items.add(f); });
            input.files = dt.files;
            render();
        }
        function render() {
            holder.innerHTML = '';
            files.forEach(function (f, idx) {
                var wrap = document.createElement('span');
                wrap.className = 'pv';
                var img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                wrap.appendChild(img);
                var x = document.createElement('button');
                x.type = 'button';
                x.className = 'pv-x';
                x.title = 'Remove this image';
                x.innerHTML = '&times;';
                x.addEventListener('click', function () {
                    files.splice(idx, 1);
                    sync();
                });
                wrap.appendChild(x);
                holder.appendChild(wrap);
            });
        }
        function addFiles(list) {
            var chosen = Array.prototype.slice.call(list || []).filter(function (f) {
                return f.type && f.type.indexOf('image/') === 0;
            });
            if (!chosen.length) { return; }
            chosen.forEach(function (f) {
                var dup = files.some(function (g) { return g.name === f.name && g.size === f.size; });
                if (!dup) { files.push(f); }
            });
            if (files.length > MAX_IMAGES) {
                notify('Too many images', 'Only ' + MAX_IMAGES + ' images per reply, extra ones were dropped.', 'warning');
                files = files.slice(0, MAX_IMAGES);
            }
            sync();
        }
        input.addEventListener('change', function () { addFiles(input.files); });

        function setActive() {
            active = { addFiles: addFiles, zone: zone };
            document.querySelectorAll('.minidrop.target').forEach(function (el) { el.classList.remove('target'); });
            zone.classList.add('target');
        }
        form.addEventListener('focusin', setActive);
        form.addEventListener('click', setActive);
        form.addEventListener('dragover', function (ev) {
            ev.preventDefault();
            zone.classList.add('dragover');
        });
        form.addEventListener('dragleave', function () { zone.classList.remove('dragover'); });
        form.addEventListener('drop', function (ev) {
            ev.preventDefault();
            zone.classList.remove('dragover');
            var imgs = imageFiles(ev.dataTransfer);
            if (imgs.length) { setActive(); addFiles(imgs.map(named)); flash(zone); }
        });
    });

    // A stray drop outside a composer must not replace the whole page.
    document.addEventListener('dragover', function (ev) { ev.preventDefault(); });
    document.addEventListener('drop', function (ev) { ev.preventDefault(); });

    document.addEventListener('paste', function (ev) {
        if (!active) { return; }
        var imgs = imageFiles(ev.clipboardData);
        if (!imgs.length) { return; }
        ev.preventDefault();
        active.addFiles(imgs.map(named));
        flash(active.zone);
    });
})();

document.querySelectorAll('form.confirm-delete').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
        if (form.dataset.confirmed === '1') { return; }
        ev.preventDefault();
        if (!window.Swal) { form.dataset.confirmed = '1'; form.submit(); return; }
        Swal.fire({
            title: 'Delete this escalation?',
            text: 'The escalation and its images are removed for good.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#ff5d73',
            background: '#101728',
            color: '#e8ecf7'
        }).then(function (res) {
            if (res.isConfirmed) { form.dataset.confirmed = '1'; form.submit(); }
        });
    });
});
</script>

<?php pageFooter(); ?>
