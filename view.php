<?php
// Single escalation detail page.
require_once __DIR__ . '/lib.php';

$id = trim((string)($_GET['id'] ?? ''));
$db = getDB();
$stmt = $db->prepare("SELECT * FROM escalations WHERE public_id = ?");
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    pageHeader('Escalation not found', 'wall');
    echo '<div class="empty"><div class="big">&#128760;</div><p>That escalation does not exist or was removed.</p>'
        . '<p><a class="readmore" href="index.php">Back to the wall</a></p></div>';
    pageFooter();
    exit;
}

$meta = statusMeta($row['status']);
$imgs = imagesOf($row);
$justSubmitted = isset($_GET['submitted']);

pageHeader('Escalation #' . $row['public_id'] . ' from ' . $row['company_name'], 'wall');
?>

<div class="detail">
    <?php if ($justSubmitted): ?>
        <div class="notice ok" style="margin-bottom:20px;">
            Your escalation is live and has been posted to our Telegram channel.
            Keep this link to follow progress, we will call the follow-up number you gave.
        </div>
    <?php endif; ?>

    <?php $tcolor = nameColor((string)($row['topic'] ?? '')); ?>
    <div class="detail-head">
        <div>
            <h1><?php echo e(excerptWords($row['issue'], 12)); ?></h1>
            <div class="meta">
                <span class="pill <?php echo $meta['class']; ?>" style="vertical-align:1px;"><?php echo $meta['icon']; ?> <?php echo $meta['label']; ?></span>
                &middot; #<?php echo e($row['public_id']); ?>
                &middot; <b style="color:var(--text);"><?php echo e($row['company_name']); ?></b>
                &middot; opened <?php echo e(timeAgo($row['created_at'])); ?>
                <?php echo $row['source'] === 'panel' ? 'from their billing panel' : 'on the public platform'; ?>
                <?php if ((string)($row['topic'] ?? '') !== ''): ?>
                    &middot; <a class="tlabel" style="color:<?php echo $tcolor; ?>;border:1px solid <?php echo $tcolor; ?>55;background:<?php echo $tcolor; ?>14;" href="index.php?topic=<?php echo e(rawurlencode($row['topic'])); ?>#wall"><?php echo e($row['topic']); ?></a>
                <?php endif; ?>
                <?php if ($row['account_manager'] !== ''): ?>&middot; account manager <?php echo e($row['account_manager']); ?><?php endif; ?>
                &middot; follow-up <?php echo e(maskPhone($row['follow_up_number'])); ?>
            </div>
        </div>
    </div>

    <div class="thread">
        <div class="tl-item">
            <span class="avatar big" style="background:<?php echo nameColor($row['company_name']); ?>;"><?php echo e(avatarInitial($row['company_name'])); ?></span>
            <div class="comment">
                <div class="comment-head"><b><?php echo e($row['company_name']); ?></b> opened this escalation &middot; <?php echo e(timeAgo($row['created_at'])); ?></div>
                <div class="comment-body"><?php echo e($row['issue']); ?><?php if ($imgs): ?>
                    <div class="gallery">
                        <?php foreach ($imgs as $img): ?>
                            <a href="<?php echo e($img); ?>" class="zoom"><img src="<?php echo e($img); ?>" alt="Issue picture" loading="lazy"></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?></div>
            </div>
        </div>

        <div class="tl-item">
            <span class="avatar big" style="background:#3a4664;color:#e8ecf7;">&#127911;</span>
            <div class="comment">
                <div class="comment-head"><b>What normal support said</b> &middot; screenshot provided by the company</div>
                <div class="comment-body"><?php if ($row['support_screenshot'] !== ''): ?>
                    <div class="gallery" style="margin-top:0;">
                        <a href="<?php echo e($row['support_screenshot']); ?>" class="zoom">
                            <img src="<?php echo e($row['support_screenshot']); ?>" alt="Reply received from support" loading="lazy">
                        </a>
                    </div>
                <?php else: ?>No support reply screenshot was attached.<?php endif; ?></div>
            </div>
        </div>

        <?php if ((string)$row['official_reply'] !== '' && $row['official_reply'] !== null): ?>
        <div class="tl-item">
            <span class="avatar big" style="background:var(--green);color:#06281c;">IL</span>
            <div class="comment staff">
                <div class="comment-head"><b>ISP Ledger team</b> <span class="staff-badge">Staff</span><?php echo $row['replied_at'] ? ' &middot; ' . e(timeAgo($row['replied_at'])) : ''; ?></div>
                <div class="comment-body"><?php echo e($row['official_reply']); ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="tl-event">
            <span class="dot"></span>
            The team has not posted a public response here yet. Escalations are reviewed every day and the follow-up number is called directly.
        </div>
        <?php endif; ?>
    </div>

    <div style="margin-top:28px;display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn ghost" href="index.php">Back to the wall</a>
        <button class="btn" type="button" id="copyLink">Copy link to this escalation</button>
    </div>
</div>

<div class="lightbox" id="lightbox"><img src="" alt=""></div>

<script>
(function () {
    var lb = document.getElementById('lightbox');
    var lbImg = lb.querySelector('img');
    document.querySelectorAll('a.zoom').forEach(function (a) {
        a.addEventListener('click', function (ev) {
            ev.preventDefault();
            lbImg.src = a.getAttribute('href');
            lb.classList.add('show');
        });
    });
    lb.addEventListener('click', function () { lb.classList.remove('show'); lbImg.src = ''; });
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') { lb.classList.remove('show'); } });

    var copy = document.getElementById('copyLink');
    copy.addEventListener('click', function () {
        var url = window.location.origin + window.location.pathname + '?id=<?php echo e(rawurlencode($row['public_id'])); ?>';
        (navigator.clipboard ? navigator.clipboard.writeText(url) : Promise.reject()).then(function () {
            copy.textContent = 'Link copied';
            setTimeout(function () { copy.textContent = 'Copy link to this escalation'; }, 1800);
        }).catch(function () { notify('Copy this link', url, 'info'); });
    });
})();
</script>

<?php pageFooter(); ?>
