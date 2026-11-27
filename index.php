<?php
require __DIR__ . '/inc/lib.php';
require __DIR__ . '/inc/layout.php';
require __DIR__ . '/inc/gmail.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");

start_session();
$p = $_GET['p'] ?? 'home';
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post) check_csrf();

/* ---------- first-time setup ---------- */
if (!has_password()) {
    $err = null;
    if ($post && $p === 'setup') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_POST['code'] ?? ''));
        $pw = $_POST['password'] ?? ''; $pw2 = $_POST['password2'] ?? '';
        if (login_locked()) $err = 'Too many attempts. Wait 15 minutes.';
        elseif (!password_verify($code, SETUP_CODE_HASH)) { record_failed_login(); $err = 'Setup code is wrong.'; }
        elseif (strlen($pw) < 10) $err = 'Use at least 10 characters for your password.';
        elseif ($pw !== $pw2) $err = 'The two passwords do not match.';
        else {
            write_json('auth.json', ['hash' => password_hash($pw, PASSWORD_DEFAULT), 'failed' => []]);
            if (!file_exists(data_path('db.json'))) write_json('db.json', seed_db((string)($_POST['starter'] ?? '')));
            session_regenerate_id(true);
            $_SESSION['uid'] = 'owner';
            flash('Password set. Your tracker is ready.');
            redirect('./');
        }
    }
    page_auth('Set up your tracker', function () use ($err) { ?>
        <p class="muted">Enter the one-time setup code, then choose the password you'll use to log in.</p>
        <?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
        <form method="post" action="?p=setup" class="stack">
            <?= csrf_field() ?>
            <label>Setup code<input name="code" required autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX"></label>
            <label>New password<input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
            <label>Repeat password<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
            <label>Starter accounts, budgets, goals <small class="muted">(optional, one per line)</small>
              <textarea name="starter" rows="6" spellcheck="false" placeholder="HDFC Savings | bank | 25000&#10;Visa card | card | 12000&#10;budget | Rent | 5000"></textarea></label>
            <button class="btn primary">Create password</button>
        </form>
    <?php });
    exit;
}

/* ---------- login / logout ---------- */
if ($p === 'logout') { $_SESSION = []; session_destroy(); redirect('./?p=login'); }

if (!logged_in()) {
    $err = null;
    if ($post && $p === 'login') {
        if (login_locked()) $err = 'Too many attempts. Wait 15 minutes.';
        elseif (password_verify($_POST['password'] ?? '', auth()['hash'])) {
            $a = auth(); $a['failed'] = []; write_json('auth.json', $a);
            session_regenerate_id(true);
            $_SESSION['uid'] = 'owner';
            redirect('./');
        } else { record_failed_login(); $err = 'Wrong password.'; }
    }
    page_auth('Log in', function () use ($err) { ?>
        <?php if ($err): ?><p class="alert"><?= e($err) ?></p><?php endif; ?>
        <form method="post" action="?p=login" class="stack">
            <?= csrf_field() ?>
            <label>Password<input type="password" name="password" required autofocus autocomplete="current-password"></label>
            <button class="btn primary">Log in</button>
        </form>
    <?php });
    exit;
}

/* ---------- actions (POST) ---------- */
$db = db_load();

if ($post) {
    switch ($p) {
    case 'save_txn':
        $type = $_POST['type'] ?? 'expense';
        $amount = round((float)str_replace(',', '', $_POST['amount'] ?? '0'), 2);
        $date = $_POST['date'] ?? date('Y-m-d');
        $acct = $_POST['account'] ?? '';
        $to = $_POST['to_account'] ?? '';
        $cat = trim($_POST['category'] ?? '');
        $note = trim(mb_substr($_POST['note'] ?? '', 0, 200));
        $id = $_POST['id'] ?? '';
        $errs = [];
        if (!in_array($type, ['expense', 'income', 'transfer'], true)) $errs[] = 'Pick a type.';
        if ($amount <= 0) $errs[] = 'Enter an amount above zero.';
        if (!valid_date($date)) $errs[] = 'Enter a valid date.';
        if (!account_by_id($db, $acct)) $errs[] = 'Pick an account.';
        if ($type === 'transfer' && (!account_by_id($db, $to) || $to === $acct)) $errs[] = 'Pick a different "to" account.';
        if ($errs) { flash(implode(' ', $errs)); redirect('./?p=add' . ($id ? '&id=' . urlencode($id) : '')); }
        $again = isset($_POST['again']);
        db_update(function (&$db) use ($type, $amount, $date, $acct, $to, $cat, $note, $id) {
            $t = ['date' => $date, 'type' => $type, 'amount' => $amount, 'account' => $acct,
                  'to_account' => $type === 'transfer' ? $to : null,
                  'category' => $type === 'transfer' ? 'Transfer' : ($cat ?: 'Other'), 'note' => $note];
            foreach ($db['txns'] as &$x) {
                if ($id && $x['id'] === $id) { $x = array_merge($x, $t, ['edited' => date('c')]); return; }
            }
            unset($x);
            $db['txns'][] = array_merge(['id' => new_id(), 'created' => date('c')], $t);
        });
        flash(($id ? 'Updated: ' : 'Saved: ') . inr($amount) . ' · ' . ($type === 'transfer' ? 'transfer' : ($cat ?: 'Other')));
        redirect($again ? './?p=add&date=' . urlencode($date) . '&type=' . urlencode($type) . '&account=' . urlencode($acct) : './');

    case 'delete_txn':
        $id = $_POST['id'] ?? '';
        db_update(function (&$db) use ($id) {
            $db['txns'] = array_values(array_filter($db['txns'], fn($t) => $t['id'] !== $id));
        });
        flash('Entry deleted.');
        redirect('./?p=txns');

    case 'save_account':
        $id = $_POST['id'] ?? '';
        $type = $_POST['type'] ?? 'bank';
        if (!isset(ACCOUNT_TYPES[$type])) $type = 'bank';
        $name = trim(mb_substr($_POST['name'] ?? '', 0, 60));
        if ($name === '') { flash('Give the account a name.'); redirect('./?p=accounts'); }
        $fields = ['name' => $name, 'type' => $type, 'note' => trim(mb_substr($_POST['note'] ?? '', 0, 200)),
                   'limit' => (float)($_POST['limit'] ?? 0),
                   'statement_day' => max(0, min(31, (int)($_POST['statement_day'] ?? 0))),
                   'due_day' => max(0, min(31, (int)($_POST['due_day'] ?? 0)))];
        $opening = (float)str_replace(',', '', $_POST['opening'] ?? '0');
        db_update(function (&$db) use ($id, $fields, $opening) {
            foreach ($db['accounts'] as &$a) { if ($id && $a['id'] === $id) { $a = array_merge($a, $fields); return; } }
            unset($a);
            $db['accounts'][] = array_merge(['id' => new_id(), 'opening' => $opening, 'archived' => false], $fields);
        });
        flash($id ? 'Account updated.' : 'Account added.');
        redirect('./?p=accounts');

    case 'set_balance':
        $id = $_POST['id'] ?? '';
        $actual = (float)str_replace(',', '', $_POST['balance'] ?? '0');
        db_update(function (&$db) use ($id, $actual) {
            $bal = balances($db);
            foreach ($db['accounts'] as &$a) {
                if ($a['id'] === $id) { $a['opening'] += $actual - $bal[$id]; $a['balance_set_at'] = date('c'); }
            }
        });
        flash('Balance updated.');
        redirect('./?p=accounts');

    case 'archive_account':
        $id = $_POST['id'] ?? '';
        db_update(function (&$db) use ($id) {
            foreach ($db['accounts'] as &$a) if ($a['id'] === $id) $a['archived'] = !$a['archived'];
        });
        redirect('./?p=accounts');

    case 'save_plan':
        $budgets = [];
        foreach (($_POST['budget'] ?? []) as $cat => $v) { $v = (float)str_replace(',', '', $v); if ($v > 0) $budgets[$cat] = $v; }
        $clean = fn($s) => array_values(array_unique(array_filter(array_map(fn($x) => trim(mb_substr($x, 0, 40)), explode("\n", $s)))));
        $exp = $clean($_POST['cat_expense'] ?? ''); $inc = $clean($_POST['cat_income'] ?? '');
        db_update(function (&$db) use ($budgets, $exp, $inc) {
            $db['budgets'] = $budgets;
            if ($exp) $db['categories']['expense'] = $exp;
            if ($inc) $db['categories']['income'] = $inc;
        });
        flash('Plan saved.');
        redirect('./?p=plan');

    case 'save_goal':
        $id = $_POST['id'] ?? '';
        $g = ['name' => trim(mb_substr($_POST['name'] ?? 'Goal', 0, 60)),
              'target' => (float)str_replace(',', '', $_POST['target'] ?? '0'),
              'target_date' => valid_date($_POST['target_date'] ?? '') ? $_POST['target_date'] : date('Y-12-31'),
              'account_id' => $_POST['account_id'] ?? ''];
        db_update(function (&$db) use ($id, $g) {
            foreach ($db['goals'] as &$x) { if ($id && $x['id'] === $id) { $x = array_merge($x, $g); return; } }
            unset($x);
            $db['goals'][] = array_merge(['id' => new_id()], $g);
        });
        flash('Goal saved.');
        redirect('./?p=plan');

    case 'delete_goal':
        $id = $_POST['id'] ?? '';
        db_update(function (&$db) use ($id) { $db['goals'] = array_values(array_filter($db['goals'], fn($g) => $g['id'] !== $id)); });
        redirect('./?p=plan');

    case 'gmail_save':
        $g = gmail_cfg();
        $g['client_id'] = trim($_POST['client_id'] ?? '');
        if (trim($_POST['client_secret'] ?? '') !== '') $g['client_secret'] = trim($_POST['client_secret']);
        gmail_save($g);
        flash('Saved. Now tap Connect my Gmail.');
        redirect('./?p=gmail');

    case 'gmail_sync':
        [$err, $rows] = gmail_sync();
        flash($err ?: count($rows) . ' recent money emails found.');
        redirect('./?p=gmail');

    case 'gmail_disconnect':
        $g = gmail_cfg();
        foreach (['refresh_token', 'access_token', 'expires_at', 'email', 'rows', 'synced_at'] as $k) unset($g[$k]);
        gmail_save($g);
        flash('Gmail disconnected.');
        redirect('./?p=gmail');

    case 'import_apply':
        $f = data_path('import.json');
        $imp = read_json('import.json', []);
        if (!$imp || empty($imp['txns']) || !account_by_id($db, $imp['account'] ?? '')) { flash('Nothing to import.'); redirect('./?p=txns'); }
        $n = db_update(function (&$db) use ($imp, $f) {
            $net = 0; $n = 0;
            foreach ($imp['txns'] as $t) {
                if (!valid_date($t['date'] ?? '') || ($t['amount'] ?? 0) <= 0 || !in_array($t['type'] ?? '', ['expense', 'income'], true)) continue;
                $db['txns'][] = ['id' => new_id(), 'created' => date('c'), 'date' => $t['date'], 'type' => $t['type'],
                    'amount' => round((float)$t['amount'], 2), 'account' => $imp['account'], 'to_account' => null,
                    'category' => $t['category'] ?? 'Other', 'note' => mb_substr($t['note'] ?? '', 0, 200), 'imported' => true];
                $net += $t['type'] === 'income' ? $t['amount'] : -$t['amount'];
                $n++;
            }
            // Keep today's balance exactly as it is: shift the opening balance by what was imported.
            foreach ($db['accounts'] as &$a) if ($a['id'] === $imp['account']) $a['opening'] = round($a['opening'] - $net, 2);
            unset($a);
            rename($f, $f . '.done');
            return $n;
        });
        flash("$n entries imported. Balances unchanged.");
        redirect('./?p=txns&m=2026-11');

    case 'change_password':
        $a = auth();
        if (!password_verify($_POST['current'] ?? '', $a['hash'])) flash('Current password is wrong.');
        elseif (strlen($_POST['new'] ?? '') < 10) flash('Use at least 10 characters.');
        else { $a['hash'] = password_hash($_POST['new'], PASSWORD_DEFAULT); write_json('auth.json', $a); flash('Password changed.'); }
        redirect('./?p=plan');
    }
    redirect('./');
}

/* ---------- one-time import page ---------- */
if ($p === 'import') {
    $imp = read_json('import.json', []);
    layout('Import', 'txns', function () use ($imp, $db) { ?>
      <section class="card narrow"><h1>Import from bank statement</h1>
      <?php if (!$imp || empty($imp['txns'])): ?><p class="muted">Nothing waiting to import.</p>
      <?php else:
        $in = array_sum(array_map(fn($t) => $t['type'] === 'income' ? $t['amount'] : 0, $imp['txns']));
        $out = array_sum(array_map(fn($t) => $t['type'] === 'expense' ? $t['amount'] : 0, $imp['txns'])); ?>
        <p><?= count($imp['txns']) ?> entries into <strong><?= e(account_by_id($db, $imp['account'])['name'] ?? '?') ?></strong>:
           spent <?= e(inr($out)) ?>, received <?= e(inr($in)) ?>. Your balance stays as it is today.</p>
        <form method="post" action="?p=import_apply"><?= csrf_field() ?><button class="btn primary">Import now</button></form>
      <?php endif; ?></section>
    <?php });
    exit;
}

/* ---------- gmail connect (GET) ---------- */
if ($p === 'gmail_connect') {
    if (empty(gmail_cfg()['client_id'])) { flash('Save your Google Client ID first.'); redirect('./?p=gmail'); }
    redirect(gmail_auth_url());
}
if ($p === 'gmail_callback') {
    $err = gmail_handle_callback();
    flash($err ?: 'Gmail connected.');
    redirect('./?p=gmail');
}

/* ---------- export ---------- */
if ($p === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="money-tracker-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Type', 'Amount', 'Category', 'Account', 'To account', 'Note']);
    foreach (sort_txns($db['txns']) as $t) {
        fputcsv($out, [$t['date'], $t['type'], $t['amount'], $t['category'],
            account_by_id($db, $t['account'])['name'] ?? '', account_by_id($db, $t['to_account'] ?? null)['name'] ?? '', $t['note']]);
    }
    exit;
}

/* ---------- pages ---------- */
$pages = ['home' => 'page_home', 'add' => 'page_add', 'txns' => 'page_txns', 'accounts' => 'page_accounts', 'plan' => 'page_plan', 'gmail' => 'page_gmail'];
($pages[$p] ?? 'page_home')($db);
