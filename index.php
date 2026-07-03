<?php
// Public escalation wall.
require_once __DIR__ . '/lib.php';

$db = getDB();

$status = $_GET['status'] ?? '';
if (!in_array($status, ['open', 'in_review', 'resolved'], true)) {
    $status = '';
}
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

$where = [];
$args = [];
if ($status !== '') {
    $where[] = 'status = ?';
    $args[] = $status;
}
if ($q !== '') {
    $where[] = '(company_name LIKE ? OR subdomain LIKE ? OR public_id = ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = $q;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM escalations $whereSql");
$stmt->execute($args);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $db->prepare("SELECT * FROM escalations $whereSql ORDER BY id DESC LIMIT " . (($page - 1) * $perPage) . ", $perPage");
$stmt->execute($args);
$rows = $stmt->fetchAll();

$counts = ['all' => 0, 'open' => 0, 'in_review' => 0, 'resolved' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM escalations GROUP BY status") as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = (int)$r['c'];
    }
    $counts['all'] += (int)$r['c'];
}

$tabUrl = function ($st) use ($q) {
    $params = [];
    if ($st !== '') {
        $params['status'] = $st;
    }
    if ($q !== '') {
        $params['q'] = $q;
    }
    return 'index.php' . ($params ? ('?' . http_build_query($params)) : '');
};

pageHeader('Escalate by ISP Ledger: public escalation wall', 'wall');
?>

<section class="hero">
    <span class="orbit-tag">Public and permanent</span>
    <h1>When support lets you down,<br>take it <span class="glow">to orbit</span>.</h1>
    <p class="sub">Every escalation raised here is published for everyone to see and posted straight to our Telegram channel. No queue, no silence: leadership sees it, the community sees it, and you get called back.</p>
    <div class="pledge">
        <p><b>An honest word from us.</b> Serving thousands of ISPs means we will not get it right for every customer, every time, no matter how hard we try. What we can promise is that no complaint dies in a queue. This platform is our last line of accountability: when normal support has not given you the resolution you deserve, raise it here in the open, where it cannot be ignored, and we will do everything in our power to make it right.</p>
    </div>
    <div class="hero-actions">
        <a class="btn" href="submit.php">Raise an Escalation</a>
        <a class="btn ghost" href="#wall">Browse the wall</a>
    </div>
</section>

<div class="stats">
    <div class="stat"><b><?php echo $counts['all']; ?></b><span>Escalations</span></div>
    <div class="stat"><b><?php echo $counts['resolved']; ?></b><span>Resolved</span></div>
    <div class="stat"><b><?php echo $counts['open'] + $counts['in_review']; ?></b><span>Being handled</span></div>
</div>

<div id="wall" class="filterbar">
    <div class="tabs">
        <a class="tab <?php echo $status === '' ? 'active' : ''; ?>" href="<?php echo e($tabUrl('')); ?>">All</a>
        <a class="tab <?php echo $status === 'open' ? 'active' : ''; ?>" href="<?php echo e($tabUrl('open')); ?>">Open</a>
        <a class="tab <?php echo $status === 'in_review' ? 'active' : ''; ?>" href="<?php echo e($tabUrl('in_review')); ?>">In Review</a>
        <a class="tab <?php echo $status === 'resolved' ? 'active' : ''; ?>" href="<?php echo e($tabUrl('resolved')); ?>">Resolved</a>
    </div>
    <form class="searchbox" method="get" action="index.php">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search company or reference...">
        <button class="btn small" type="submit">Search</button>
    </form>
</div>

<?php if (!$rows): ?>
    <div class="empty">
        <div class="big">&#128752;</div>
        <p>Nothing here yet<?php echo ($q !== '' || $status !== '') ? ' for this filter' : ''; ?>.</p>
        <p><a class="readmore" href="submit.php">Be the first to raise an escalation</a></p>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($rows as $row):
            $meta = statusMeta($row['status']);
            $imgs = imagesOf($row);
            $url = 'view.php?id=' . rawurlencode($row['public_id']);
        ?>
        <article class="card">
            <div class="card-head">
                <div>
                    <div class="card-company"><?php echo e($row['company_name']); ?></div>
                    <div class="card-meta">#<?php echo e($row['public_id']); ?> &middot; <?php echo e(timeAgo($row['created_at'])); ?></div>
                </div>
                <span class="pill <?php echo $meta['class']; ?>"><?php echo $meta['label']; ?></span>
            </div>
            <p class="card-issue"><?php echo e(excerptWords($row['issue'], 34)); ?></p>
            <?php if ($imgs): ?>
            <div class="thumbs">
                <?php foreach (array_slice($imgs, 0, 3) as $img): ?>
                    <img src="<?php echo e($img); ?>" alt="Issue picture" loading="lazy">
                <?php endforeach; ?>
                <?php if (count($imgs) > 3): ?><span class="more">+<?php echo count($imgs) - 3; ?></span><?php endif; ?>
            </div>
            <?php endif; ?>
            <div class="card-foot">
                <a class="readmore" href="<?php echo e($url); ?>">Read full escalation</a>
                <?php if ((string)$row['official_reply'] !== '' && $row['official_reply'] !== null): ?>
                    <span class="card-meta">Official reply posted</span>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php for ($p = 1; $p <= $pages; $p++):
            $params = ['page' => $p];
            if ($status !== '') { $params['status'] = $status; }
            if ($q !== '') { $params['q'] = $q; }
            $href = 'index.php?' . http_build_query($params);
        ?>
            <?php if ($p === $page): ?><span class="cur"><?php echo $p; ?></span>
            <?php else: ?><a href="<?php echo e($href); ?>"><?php echo $p; ?></a><?php endif; ?>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php pageFooter(); ?>
