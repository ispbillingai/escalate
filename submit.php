<?php
// Public escalation form.
require_once __DIR__ . '/lib.php';

$errors = [];
$old = ['company_name' => '', 'subdomain' => '', 'follow_up_number' => '', 'issue' => '', 'account_manager' => ''];
$managers = accountManagers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: real users never fill this hidden field.
    if (trim((string)($_POST['website'] ?? '')) !== '') {
        header('Location: index.php');
        exit;
    }

    foreach (array_keys($old) as $k) {
        $old[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $issueFiles = normalizeFilesArray($_FILES['images'] ?? null);
    $supportFile = (isset($_FILES['support_screenshot']) && (int)$_FILES['support_screenshot']['error'] !== UPLOAD_ERR_NO_FILE)
        ? $_FILES['support_screenshot'] : null;

    list($ok, $payload) = createEscalation($old, $issueFiles, $supportFile, 'web');
    if ($ok) {
        header('Location: view.php?id=' . rawurlencode($payload['public_id']) . '&submitted=1');
        exit;
    }
    $errors = $payload['errors'];
}

pageHeader('Raise an escalation', 'submit');
?>

<section class="hero" style="padding-bottom:10px;">
    <span class="orbit-tag">Mission control is listening</span>
    <h1>Raise an <span class="glow">escalation</span></h1>
    <p class="sub">Tried normal support and got nowhere? Put it on the record. Your escalation goes public here and lands in our Telegram channel the second you press launch.</p>
    <div class="pledge">
        <p><b>A promise from us.</b> We care deeply about getting things right for you, and if support has left you frustrated we genuinely want to know. This page exists for exactly those moments: everything raised here goes straight to the top, is treated with the seriousness of a last resort, and we will not rest until it is put right.</p>
    </div>
</section>

<div class="steps">
    <div class="step"><b><span class="n">1</span>Describe it fully</b>At least <?php echo MIN_WORDS; ?> words, so anyone reading understands exactly what happened.</div>
    <div class="step"><b><span class="n">2</span>Show it</b>Attach pictures of the issue and a screenshot of what support told you.</div>
    <div class="step"><b><span class="n">3</span>Get called back</b>Leave a follow-up number, the team calls it directly.</div>
</div>

<div class="form-card">
    <h1>Escalation details</h1>
    <p class="lead">Everything except your phone number is published publicly. The number is only shown masked and is used to call you back.</p>

    <?php if ($errors): ?>
        <div class="errors"><ul>
            <?php foreach ($errors as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>

    <form method="post" action="submit.php" enctype="multipart/form-data" id="escForm">
        <input type="text" name="website" value="" style="display:none" tabindex="-1" autocomplete="off">

        <div class="field">
            <label for="company_name">Company name <small>(shown publicly)</small></label>
            <input type="text" id="company_name" name="company_name" maxlength="160" required value="<?php echo e($old['company_name']); ?>" placeholder="e.g. Skyline WiFi Ltd">
        </div>

        <div class="field">
            <label for="subdomain">Your panel subdomain <small>(optional, helps us find your account faster)</small></label>
            <input type="text" id="subdomain" name="subdomain" maxlength="64" value="<?php echo e($old['subdomain']); ?>" placeholder="e.g. skyline">
        </div>

        <div class="field">
            <label for="account_manager">Your account manager <small>(required)</small></label>
            <?php if ($managers): ?>
            <select id="account_manager" name="account_manager" required>
                <option value="">Choose your account manager...</option>
                <?php foreach ($managers as $m): ?>
                    <option value="<?php echo e($m); ?>" <?php echo $old['account_manager'] === $m ? 'selected' : ''; ?>><?php echo e($m); ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" id="account_manager" name="account_manager" maxlength="120" required value="<?php echo e($old['account_manager']); ?>" placeholder="Name of your account manager">
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="follow_up_number">Follow-up phone number <small>(never shown in full publicly)</small></label>
            <input type="tel" id="follow_up_number" name="follow_up_number" maxlength="16" required value="<?php echo e($old['follow_up_number']); ?>" placeholder="e.g. +254712345678">
        </div>

        <div class="field">
            <label for="issue">What happened? <small>(minimum <?php echo MIN_WORDS; ?> words)</small></label>
            <textarea id="issue" name="issue" required placeholder="Tell the whole story: what went wrong, when it started, what you asked support, and what you need fixed."><?php echo e($old['issue']); ?></textarea>
            <div class="wordmeter" id="wordmeter">
                <div class="bar"><i id="wordbar"></i></div>
                <b id="wordcount">0 / <?php echo MIN_WORDS; ?> words</b>
            </div>
        </div>

        <div class="field">
            <label>Pictures of the issue <small>(1 to <?php echo MAX_IMAGES; ?> images, up to <?php echo round(MAX_IMAGE_BYTES / 1048576); ?> MB each)</small></label>
            <label class="filedrop" for="images">Click to choose images showing the problem</label>
            <input type="file" id="images" name="images[]" accept="image/*" multiple>
            <div class="previews" id="imagePreviews"></div>
        </div>

        <div class="field">
            <label>Screenshot of the reply support gave you <small>(required)</small></label>
            <label class="filedrop" for="support_screenshot" id="supportDrop">Click to choose the screenshot of support's response</label>
            <input type="file" id="support_screenshot" name="support_screenshot" accept="image/*" required>
            <div class="previews" id="supportPreview"></div>
        </div>

        <button class="btn" type="submit" id="launchBtn" style="width:100%;font-size:17px;padding:14px;">Launch escalation</button>
        <p style="color:var(--muted);font-size:13.5px;margin-top:12px;text-align:center;">
            By launching, your company name, issue and pictures are published on this platform and posted to our public Telegram channel.
        </p>
    </form>
</div>

<script>
(function () {
    var MIN_WORDS = <?php echo (int)MIN_WORDS; ?>;
    var MAX_IMAGES = <?php echo (int)MAX_IMAGES; ?>;

    var issue = document.getElementById('issue');
    var meter = document.getElementById('wordmeter');
    var bar = document.getElementById('wordbar');
    var count = document.getElementById('wordcount');

    function words(text) {
        var m = text.trim().split(/\s+/).filter(Boolean);
        return m.length;
    }
    function updateMeter() {
        var w = words(issue.value);
        count.textContent = w + ' / ' + MIN_WORDS + ' words';
        bar.style.width = Math.min(100, (w / MIN_WORDS) * 100) + '%';
        meter.classList.toggle('ok', w >= MIN_WORDS);
    }
    issue.addEventListener('input', updateMeter);
    updateMeter();

    function bindPreview(input, holder, single) {
        input.addEventListener('change', function () {
            holder.innerHTML = '';
            var files = Array.prototype.slice.call(input.files || []);
            if (!single && files.length > MAX_IMAGES) {
                notify('Too many images', 'Please choose at most ' + MAX_IMAGES + ' images.', 'warning');
                input.value = '';
                return;
            }
            files.forEach(function (f) {
                if (!f.type || f.type.indexOf('image/') !== 0) { return; }
                var wrap = document.createElement('span');
                wrap.className = 'pv';
                var img = document.createElement('img');
                img.src = URL.createObjectURL(f);
                wrap.appendChild(img);
                holder.appendChild(wrap);
            });
        });
    }
    bindPreview(document.getElementById('images'), document.getElementById('imagePreviews'), false);
    bindPreview(document.getElementById('support_screenshot'), document.getElementById('supportPreview'), true);

    var supportInput = document.getElementById('support_screenshot');

    document.getElementById('escForm').addEventListener('submit', function (ev) {
        var problems = [];
        if (words(issue.value) < MIN_WORDS) {
            problems.push('Describe the issue in at least ' + MIN_WORDS + ' words.');
        }
        if (!document.getElementById('account_manager').value) {
            problems.push('Choose your account manager.');
        }
        if (!document.getElementById('images').files.length) {
            problems.push('Attach at least one picture of the issue.');
        }
        if (!supportInput.files.length) {
            problems.push("Attach the screenshot of support's reply.");
        }
        if (problems.length) {
            ev.preventDefault();
            notify('Almost there', problems.join(' '), 'warning');
            return;
        }
        var btn = document.getElementById('launchBtn');
        btn.disabled = true;
        btn.textContent = 'Launching...';
    });
})();
</script>

<?php pageFooter(); ?>
