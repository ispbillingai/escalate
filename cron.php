<?php
// No-response reminder / auto-resolve worker. Safe to run as often as you
// like (every action is guarded, nothing double-posts). Suggested cron:
//   */30 * * * * php /var/www/escalate/cron.php
// or over HTTP: curl -s https://escalate.ispledger.com/cron.php
// Without cron, page loads run the same pass at most once an hour.
require_once __DIR__ . '/lib.php';

runAutoNudges();
echo "ok\n";
