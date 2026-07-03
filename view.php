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

    <div class="detail-head">
        <div>
            <h1><?php echo e($row['company_name']); ?></h1>
            <div class="meta">
                Escalation #<?php echo e($row['public_id']); ?>
                &middot; raised <?php echo e(timeAgo($row['created_at'])); ?>
                &middot; <?php echo $row['source'] === 'panel' ? 'from their billing panel' : 'on the public platform'; ?>
                <?php if ($row['account_manager'] !== ''): ?>&middot; account manager <?php echo e($row['account_manager']); ?><?php endif; ?>
                &middot; follow-up <?php echo e(maskPhone($row['follow_up_number'])); ?>
            </div>
        </div>
        <span class="pill <?php echo $meta['class']; ?>"><?php echo $meta['label']; ?></span>
    </div>

    <div class="section-label">The issue in their words</div>
    <div class="detail-issue"><?php echo e($row['issue']); ?></div>

    <?php if ($imgs): ?>
        <div class="section-label">Pictures of the issue</div>
        <div class="gallery">
            <?php foreach ($imgs as $img): ?>
                <a href="<?php echo e($img); ?>" class="zoom"><img src="<?php echo e($img); ?>" alt="Issue picture" loading="lazy"></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="section-label">What normal support said</div>
    <?php if ($row['support_screenshot'] !== ''): ?>
        <div class="gallery">
            <a href="<?php echo e($row['support_screenshot']); ?>" class="zoom">
                <img src="<?php echo e($row['support_screenshot']); ?>" alt="Reply received from support" loading="lazy">
            </a>
        </div>
    <?php else: ?>
        <div class="notice">No support reply screenshot was attached.</div>
    <?php endif; ?>

    <div class="section-label">Official response</div>
    <?php if ((string)$row['official_reply'] !== '' && $row['official_reply'] !== null): ?>
        <div class="reply-box">
            <div class="who">ISP Ledger team<?php echo $row['replied_at'] ? ' &middot; ' . e(timeAgo($row['replied_at'])) : ''; ?></div>
            <p><?php echo e($row['official_reply']); ?></p>
        </div>
    <?php else: ?>
        <div class="notice">The team has not posted a public response here yet. Escalations are reviewed every day and the follow-up number is called directly.</div>
    <?php endif; ?>

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
