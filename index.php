<?php
// Public escalation wall.
require_once __DIR__ . '/lib.php';

$db = getDB();
autoNudgeTick();

$status = $_GET['status'] ?? '';
if (!in_array($status, ['open', 'in_review', 'resolved'], true)) {
    $status = '';
}
$topics = escalationTopics();
$topic = trim((string)($_GET['topic'] ?? ''));
if (!in_array($topic, $topics, true)) {
    $topic = '';
}
$sort = $_GET['sort'] ?? 'newest';
if (!in_array($sort, ['newest', 'oldest', 'updated'], true)) {
    $sort = 'newest';
}
$orderSql = ['newest' => 'id DESC', 'oldest' => 'id ASC', 'updated' => 'updated_at DESC'][$sort];
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 12;

$where = [];
$args = [];
if ($status !== '') {
    $where[] = 'status = ?';
    $args[] = $status;
}
if ($topic !== '') {
    $where[] = 'topic = ?';
    $args[] = $topic;
}
if ($q !== '') {
    $where[] = '(company_name LIKE ? OR subdomain LIKE ? OR public_id = ? OR issue LIKE ? OR account_manager LIKE ?)';
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
    $args[] = $q;
    $args[] = '%' . $q . '%';
    $args[] = '%' . $q . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare("SELECT COUNT(*) FROM escalations $whereSql");
$stmt->execute($args);
$total = (int)$stmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);

$stmt = $db->prepare("SELECT * FROM escalations $whereSql ORDER BY $orderSql LIMIT " . (($page - 1) * $perPage) . ", $perPage");
$stmt->execute($args);
$rows = $stmt->fetchAll();
$wallThreads = repliesForAll(array_column($rows, 'id'));

$counts = ['all' => 0, 'open' => 0, 'in_review' => 0, 'resolved' => 0];
foreach ($db->query("SELECT status, COUNT(*) c FROM escalations GROUP BY status") as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']] = (int)$r['c'];
    }
    $counts['all'] += (int)$r['c'];
}
$topicCounts = [];
foreach ($db->query("SELECT topic, COUNT(*) c FROM escalations WHERE topic <> '' GROUP BY topic") as $r) {
    $topicCounts[$r['topic']] = (int)$r['c'];
}

// One URL builder for every filter link: keeps the other filters sticky.
$filterUrl = function (array $overrides = []) use ($status, $topic, $q, $sort) {
    $params = array_merge(
        ['status' => $status, 'topic' => $topic, 'q' => $q, 'sort' => $sort === 'newest' ? '' : $sort],
        $overrides
    );
    $params = array_filter($params, function ($v) {
        return $v !== '' && $v !== null;
    });
    return 'index.php' . ($params ? ('?' . http_build_query($params)) : '') . '#wall';
};

pageHeader('Escalate by freeispradius: public escalation wall', 'wall');
?>

<section class="hero">
    <span class="orbit-tag">Public and permanent</span>
    <h1>When support lets you down,<br>take it <span class="glow">to orbit</span>.</h1>
    <p class="sub">Every escalation raised here is published for everyone to see and posted straight to our Telegram channel. No queue, no silence: leadership sees it, the community sees it, and you get called back.</p>
    <div class="pledge">
        <p><b>A promise from us.</b> Every customer matters to us, and we work hard to give each one a great experience. Once in a while we may still miss the mark, and when that happens we would rather hear about it loudly than have you feel unheard. This platform is our promise that no concern ever dies in a queue: raise it here in the open and we will do everything in our power to make it right.</p>
        <p style="margin-top:10px;"><b>Marked as fixed, but it is not?</b> Sometimes support closes an issue as resolved while on your side it is not. Top management wants to hear about exactly that, loudly, right here, so we can check it ourselves.</p>
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
    <?php if (telegramChannelUrl() !== ''): ?>
    <a class="stat stat-link" href="<?php echo e(telegramChannelUrl()); ?>" target="_blank" rel="noopener">
        <b><?php echo number_format(telegramMemberCount()); ?></b><span>Watching on Telegram</span>
    </a>
    <?php endif; ?>
</div>

<div id="wall" class="forum-toolbar">
    <form class="forum-search" method="get" action="index.php">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
        <?php if ($topic !== ''): ?><input type="hidden" name="topic" value="<?php echo e($topic); ?>"><?php endif; ?>
        <?php if ($sort !== 'newest'): ?><input type="hidden" name="sort" value="<?php echo e($sort); ?>"><?php endif; ?>
        <input type="text" name="q" value="<?php echo e($q); ?>" placeholder="Search escalations: company, text, manager or #reference...">
        <button class="btn small" type="submit">Search</button>
    </form>
    <form class="forum-filters" method="get" action="index.php" id="filterForm">
        <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo e($status); ?>"><?php endif; ?>
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo e($q); ?>"><?php endif; ?>
        <select name="topic" onchange="document.getElementById('filterForm').submit();" aria-label="Filter by topic">
            <option value="">All topics</option>
            <?php foreach ($topics as $t): ?>
                <option value="<?php echo e($t); ?>" <?php echo $topic === $t ? 'selected' : ''; ?>>
                    <?php echo e($t); ?><?php echo !empty($topicCounts[$t]) ? ' (' . $topicCounts[$t] . ')' : ''; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="sort" onchange="document.getElementById('filterForm').submit();" aria-label="Sort escalations">
            <option value="newest" <?php echo $sort === 'newest' ? 'selected' : ''; ?>>Newest</option>
            <option value="oldest" <?php echo $sort === 'oldest' ? 'selected' : ''; ?>>Oldest</option>
            <option value="updated" <?php echo $sort === 'updated' ? 'selected' : ''; ?>>Recently updated</option>
        </select>
    </form>
    <a class="btn new-esc" href="submit.php">Raise an Escalation</a>
</div>

<div class="forum-box">
    <div class="forum-head">
        <a class="fstate <?php echo $status === '' ? 'active' : ''; ?>" href="<?php echo e($filterUrl(['status' => '', 'page' => ''])); ?>">All <b><?php echo $counts['all']; ?></b></a>
        <a class="fstate <?php echo $status === 'open' ? 'active' : ''; ?>" href="<?php echo e($filterUrl(['status' => 'open', 'page' => ''])); ?>"><span class="st-open">&#9679;</span> Open <b><?php echo $counts['open']; ?></b></a>
        <a class="fstate <?php echo $status === 'in_review' ? 'active' : ''; ?>" href="<?php echo e($filterUrl(['status' => 'in_review', 'page' => ''])); ?>"><span class="st-review">&#9685;</span> In Review <b><?php echo $counts['in_review']; ?></b></a>
        <a class="fstate <?php echo $status === 'resolved' ? 'active' : ''; ?>" href="<?php echo e($filterUrl(['status' => 'resolved', 'page' => ''])); ?>"><span class="st-resolved">&#10004;</span> Resolved <b><?php echo $counts['resolved']; ?></b></a>
        <span class="forum-count">
            <?php if ($q !== '' || $topic !== ''): ?>
                <?php echo $total; ?> found<?php echo $topic !== '' ? ' in ' . e($topic) : ''; ?> &middot; <a href="index.php#wall" style="color:var(--accent);">clear</a>
            <?php else: ?>
                <?php echo $total; ?> escalation<?php echo $total === 1 ? '' : 's'; ?>
            <?php endif; ?>
        </span>
    </div>

    <?php if (!$rows): ?>
    <div class="forum-empty">
        <div class="big">&#128752;</div>
        <p>Nothing here<?php echo ($q !== '' || $status !== '' || $topic !== '') ? ' for this filter' : ' yet'; ?>.</p>
        <p><a class="readmore" href="submit.php">Be the first to raise an escalation</a></p>
    </div>
    <?php else: ?>
        <?php foreach ($rows as $row):
            $meta = statusMeta($row['status']);
            $imgs = imagesOf($row);
            $url = 'view.php?id=' . rawurlencode($row['public_id']);
            $thread = $wallThreads[(int)$row['id']] ?? [];
            $hasOfficial = (string)$row['official_reply'] !== '' && $row['official_reply'] !== null;
            $staffReplied = $hasOfficial;
            foreach ($thread as $r) {
                if ($r['author_type'] === 'staff') {
                    $staffReplied = true;
                    break;
                }
            }
            $replyCount = ($hasOfficial ? 1 : 0) + count($thread);
        ?>
        <article class="frow">
            <span class="frow-state <?php echo $meta['state']; ?>" title="<?php echo $meta['label']; ?>"><?php echo $meta['icon']; ?></span>
            <div class="frow-main">
                <a class="frow-title" href="<?php echo e($url); ?>"><?php echo e(excerptWords($row['issue'], 12)); ?></a>
                <?php if ((string)($row['topic'] ?? '') !== ''): ?>
                    <a class="tlabel topic" href="<?php echo e($filterUrl(['topic' => $row['topic'], 'page' => ''])); ?>"><?php echo e($row['topic']); ?></a>
                <?php endif; ?>
                <?php if ($staffReplied): ?><span class="tlabel replied">Team replied</span><?php endif; ?>
                <div class="frow-meta">
                    #<?php echo e($row['public_id']); ?>
                    &middot; <b style="color:var(--text);font-weight:600;"><?php echo e($row['company_name']); ?></b>
                    &middot; opened <?php echo e(timeAgo($row['created_at'])); ?>
                    <?php if ($row['account_manager'] !== ''): ?>&middot; manager <?php echo e($row['account_manager']); ?><?php endif; ?>
                </div>
            </div>
            <div class="frow-side">
                <?php if ($imgs): ?><span class="fico" title="<?php echo count($imgs); ?> picture(s)">&#128444;&#65039; <?php echo count($imgs); ?></span><?php endif; ?>
                <span class="fico" title="Replies">&#128172; <?php echo $replyCount; ?></span>
                <span class="avatar" title="<?php echo e($row['company_name']); ?>"><?php echo e(avatarInitial($row['company_name'])); ?></span>
            </div>
        </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($rows): ?>

    <?php if ($pages > 1): ?>
    <div class="pager">
        <?php
        // Windowed pagination: Prev, first page, a window around the current
        // page with ellipses, last page, Next. Stays tidy at any page count.
        $window = [];
        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $page) <= 2) {
                $window[] = $p;
            }
        }
        if ($page > 1): ?>
            <a href="<?php echo e($filterUrl(['page' => $page - 1])); ?>">&laquo; Prev</a>
        <?php endif;
        $last = 0;
        foreach ($window as $p):
            if ($last && $p > $last + 1): ?><span class="gap">&hellip;</span><?php endif;
            $last = $p;
            if ($p === $page): ?><span class="cur"><?php echo $p; ?></span>
            <?php else: ?><a href="<?php echo e($filterUrl(['page' => $p])); ?>"><?php echo $p; ?></a><?php endif;
        endforeach;
        if ($page < $pages): ?>
            <a href="<?php echo e($filterUrl(['page' => $page + 1])); ?>">Next &raquo;</a>
        <?php endif; ?>
    </div>
    <p style="text-align:center;color:var(--muted);font-size:12.5px;">Page <?php echo $page; ?> of <?php echo $pages; ?> &middot; <?php echo $total; ?> escalations</p>
    <?php endif; ?>
<?php endif; ?>

<?php pageFooter(); ?>
