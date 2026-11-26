<?php

function head_html(string $title): void { ?>
<!doctype html>
<html lang="en-IN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0f766e">
<meta name="apple-mobile-web-app-capable" content="yes">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/app.css?v=3">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%230f766e'/><text x='16' y='22' font-size='18' text-anchor='middle' fill='white' font-family='sans-serif'>₹</text></svg>">
<script src="assets/app.js?v=3" defer></script>
</head>
<?php }

function page_auth(string $title, callable $body): void {
    head_html($title); ?>
<body class="auth">
<main class="auth-card">
  <div class="brand"><span class="logo">₹</span> <?= e(APP_NAME) ?></div>
  <h1><?= e($title) ?></h1>
  <?php $body(); ?>
</main>
</body></html>
<?php }

function layout(string $title, string $active, callable $body): void {
    head_html($title);
    $nav = ['home' => 'Dashboard', 'add' => '+ Add', 'txns' => 'Entries', 'accounts' => 'Accounts', 'plan' => 'Plan'];
    $msg = flash(); ?>
<body>
<header class="top">
  <a class="brand" href="./"><span class="logo">₹</span> <?= e(APP_NAME) ?></a>
  <nav>
    <?php foreach ($nav as $k => $label): ?>
      <a href="./?p=<?= $k ?>" class="<?= $k === $active ? 'on' : '' ?><?= $k === 'add' ? ' add' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="./?p=logout" class="quiet">Log out</a>
  </nav>
</header>
<main class="wrap">
  <?php if ($msg): ?><div class="toast" role="status"><?= e($msg) ?></div><?php endif; ?>
  <?php $body(); ?>
</main>
<nav class="tabbar">
  <?php foreach ($nav as $k => $label): ?>
    <a href="./?p=<?= $k ?>" class="<?= $k === $active ? 'on' : '' ?><?= $k === 'add' ? ' add' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
</body></html>
<?php }

/* ---------- shared bits ---------- */

function account_options(array $db, ?string $sel, bool $liabFirst = false): string {
    $html = '';
    $groups = ['Money you have' => [], 'Cards & loans' => []];
    foreach ($db['accounts'] as $a) {
        if ($a['archived'] && $a['id'] !== $sel) continue;
        $groups[is_liability($a) ? 'Cards & loans' : 'Money you have'][] = $a;
    }
    if ($liabFirst) $groups = array_reverse($groups, true);
    foreach ($groups as $label => $list) {
        if (!$list) continue;
        $html .= '<optgroup label="' . e($label) . '">';
        foreach ($list as $a) {
            $html .= '<option value="' . e($a['id']) . '"' . ($a['id'] === $sel ? ' selected' : '') . '>' . e($a['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
}

function txn_row(array $db, array $t): void {
    $acc = account_by_id($db, $t['account']);
    $to = account_by_id($db, $t['to_account'] ?? null);
    $sign = $t['type'] === 'income' ? '+' : ($t['type'] === 'transfer' ? '' : '−'); ?>
    <a class="row txn <?= e($t['type']) ?>" href="./?p=add&id=<?= e($t['id']) ?>">
      <span class="when"><?= e(date('d M', strtotime($t['date']))) ?></span>
      <span class="what">
        <strong><?= e($t['type'] === 'transfer' ? ($acc['name'] ?? '?') . ' → ' . ($to['name'] ?? '?') : $t['category']) ?></strong>
        <small><?= e($t['note'] ?: ($t['type'] === 'transfer' ? 'Transfer / payment' : ($acc['name'] ?? ''))) ?></small>
      </span>
      <span class="amt"><?= $sign . inr($t['amount']) ?></span>
    </a>
<?php }

function bar(float $value, float $max, string $cls = ''): string {
    $pct = $max > 0 ? min(100, max(0, $value / $max * 100)) : 0;
    return '<span class="bar ' . $cls . '"><span style="width:' . round($pct, 1) . '%"></span></span>';
}

/* ---------- dashboard ---------- */

function page_home(array $db): void {
    $today = date('Y-m-d'); $yday = date('Y-m-d', strtotime('-1 day')); $ym = date('Y-m');
    $spentOn = function ($d) use ($db) {
        return array_sum(array_map(fn($t) => $t['amount'], array_filter($db['txns'], fn($t) => $t['type'] === 'expense' && $t['date'] === $d)));
    };
    $month = month_txns($db, $ym);
    $mSpent = array_sum(array_map(fn($t) => $t['amount'], array_filter($month, fn($t) => $t['type'] === 'expense')));
    $mIncome = array_sum(array_map(fn($t) => $t['amount'], array_filter($month, fn($t) => $t['type'] === 'income')));
    $budgetTotal = array_sum($db['budgets']);
    $byCat = [];
    foreach ($month as $t) if ($t['type'] === 'expense') $byCat[$t['category']] = ($byCat[$t['category']] ?? 0) + $t['amount'];
    foreach ($db['budgets'] as $c => $b) $byCat[$c] = $byCat[$c] ?? 0;
    arsort($byCat);

    $bal = balances($db);
    $assets = 0; $owed = 0; $cards = []; $loans = []; $cashAccts = [];
    foreach ($db['accounts'] as $a) {
        if ($a['archived']) continue;
        $b = $bal[$a['id']];
        if ($a['type'] === 'card') { $owed += $b; $cards[] = [$a, $b]; }
        elseif ($a['type'] === 'loan') { $owed += $b; $loans[] = [$a, $b]; }
        else { $assets += $b; $cashAccts[] = [$a, $b]; }
    }

    // last 30 days
    $days = [];
    for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $days[$d] = 0; }
    foreach ($db['txns'] as $t) if ($t['type'] === 'expense' && isset($days[$t['date']])) $days[$t['date']] += $t['amount'];
    $maxDay = max(1, max($days));
    $loggedYday = count(array_filter($db['txns'], fn($t) => $t['date'] === $yday)) > 0;

    layout('Dashboard', 'home', function () use ($db, $today, $yday, $spentOn, $mSpent, $mIncome, $budgetTotal, $byCat,
                                                $assets, $owed, $cards, $loans, $cashAccts, $days, $maxDay, $loggedYday, $bal) { ?>
    <?php if (!$loggedYday): ?>
      <a class="nudge" href="./?p=add&date=<?= e($yday) ?>">Nothing logged for yesterday (<?= e(date('D, d M', strtotime($yday))) ?>). <strong>Add yesterday's spends →</strong></a>
    <?php endif; ?>

    <section class="stats">
      <div class="stat"><small>Today</small><b><?= inr($spentOn($today)) ?></b></div>
      <div class="stat"><small>Yesterday</small><b><?= inr($spentOn($yday)) ?></b></div>
      <div class="stat"><small><?= e(date('F')) ?> spent</small><b><?= inr($mSpent) ?></b>
        <?php if ($budgetTotal): ?><?= bar($mSpent, $budgetTotal, $mSpent > $budgetTotal ? 'over' : '') ?><em>of <?= inr($budgetTotal) ?> budget</em><?php endif; ?></div>
      <div class="stat"><small>Net worth</small><b class="<?= $assets - $owed < 0 ? 'neg' : '' ?>"><?= inr($assets - $owed) ?></b><em><?= inr($assets) ?> have · <?= inr($owed) ?> owe</em></div>
    </section>

    <div class="grid">
      <section class="card">
        <h2>Quick add</h2>
        <?php quick_form($db, ['date' => $today]); ?>
      </section>

      <section class="card">
        <h2>Last 30 days</h2>
        <div class="spark" aria-label="Daily spending, last 30 days">
          <?php foreach ($days as $d => $v): ?>
            <span title="<?= e(date('d M', strtotime($d)) . ': ' . inr($v)) ?>" style="height:<?= max(2, round($v / $maxDay * 100)) ?>%" class="<?= $d === $today ? 'today' : '' ?>"></span>
          <?php endforeach; ?>
        </div>
        <p class="muted small">Daily spending. Average <?= inr(array_sum($days) / 30) ?>/day · this month's income <?= inr($mIncome) ?></p>
      </section>

      <section class="card">
        <h2><?= e(date('F')) ?> by category</h2>
        <?php if (!array_filter($byCat)): ?><p class="muted">No spending logged this month yet.</p><?php endif; ?>
        <?php foreach ($byCat as $c => $v): $b = $db['budgets'][$c] ?? 0; if (!$v && !$b) continue; ?>
          <div class="catline">
            <span><?= e($c) ?></span>
            <span class="num"><?= inr($v) ?><?= $b ? ' <small>/ ' . inr($b) . '</small>' : '' ?></span>
            <?= bar($v, $b ?: $v, $b && $v > $b ? 'over' : ($b ? '' : 'plain')) ?>
          </div>
        <?php endforeach; ?>
      </section>

      <section class="card">
        <h2>Credit cards</h2>
        <?php if (!$cards): ?><p class="muted">No cards added.</p><?php endif; ?>
        <?php foreach ($cards as [$a, $b]): $due = next_due($a); ?>
          <div class="acct">
            <div><strong><?= e($a['name']) ?></strong>
              <small><?php if ($due): $n = (int)(new DateTime('today'))->diff($due)->days; ?>Due <?= e($due->format('d M')) ?> · <?= $n === 0 ? 'today' : "in $n days" ?><?php else: ?>Set due day in Accounts<?php endif; ?></small></div>
            <div class="num"><b><?= inr($b) ?></b><?php if (!empty($a['limit'])): ?><small><?= round($b / $a['limit'] * 100) ?>% of <?= inr($a['limit']) ?></small><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
        <h2 class="mt">Accounts</h2>
        <?php foreach ($cashAccts as [$a, $b]): ?>
          <div class="acct"><div><strong><?= e($a['name']) ?></strong><small><?= e(ACCOUNT_TYPES[$a['type']]) ?></small></div><div class="num"><b class="<?= $b < 0 ? 'neg' : '' ?>"><?= inr($b) ?></b></div></div>
        <?php endforeach; ?>
      </section>

      <section class="card">
        <h2>Loans you owe</h2>
        <?php foreach ($loans as [$a, $b]): $start = max((float)$a['opening'], $b, 1);
              $paid = 0; foreach ($db['txns'] as $t) if ($t['type'] === 'transfer' && ($t['to_account'] ?? '') === $a['id']) $paid += $t['amount']; ?>
          <div class="catline">
            <span><?= e($a['name']) ?></span>
            <span class="num"><?= inr($b) ?> <small>left</small></span>
            <?= bar($paid, $paid + $b, 'good') ?>
            <?php if ($a['note']): ?><small class="muted"><?= e($a['note']) ?></small><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <p class="muted small">Log a repayment as a <b>Transfer</b> from your bank to the loan.</p>
      </section>

      <section class="card">
        <h2>Goals</h2>
        <?php foreach ($db['goals'] as $g):
            $saved = !empty($g['account_id']) && isset($bal[$g['account_id']]) ? $bal[$g['account_id']] : 0;
            $left = max(0, $g['target'] - $saved);
            $months = max(1, (int)ceil((strtotime($g['target_date']) - time()) / (86400 * 30.44))); ?>
          <div class="catline">
            <span><?= e($g['name']) ?> <small class="muted">by <?= e(date('M Y', strtotime($g['target_date']))) ?></small></span>
            <span class="num"><?= inr($saved) ?> <small>/ <?= inr($g['target']) ?></small></span>
            <?= bar($saved, $g['target'], 'good') ?>
            <small class="muted"><?= $left > 0 ? 'Needs ' . inr($left / $months) . '/month for ' . $months . ' months' : 'Target reached' ?></small>
          </div>
        <?php endforeach; ?>
        <p class="muted small">Move money into a goal with a <b>Transfer</b> to its fund account.</p>
      </section>
    </div>

    <section class="card">
      <h2>Recent entries <a class="more" href="./?p=txns">See all →</a></h2>
      <?php $recent = array_slice(sort_txns($db['txns']), 0, 8);
            if (!$recent): ?><p class="muted">No entries yet. Add your first one above.</p><?php endif;
            foreach ($recent as $t) txn_row($db, $t); ?>
    </section>
<?php });
}

/* ---------- add / edit ---------- */

function quick_form(array $db, array $t, bool $full = false): void {
    $type = $t['type'] ?? 'expense';
    $date = $t['date'] ?? date('Y-m-d');
    $acct = $t['account'] ?? ($db['accounts'][0]['id'] ?? '');
    $id = $t['id'] ?? ''; ?>
    <form method="post" action="?p=save_txn" class="txnform" data-cats-expense="<?= e(json_encode($db['categories']['expense'])) ?>" data-cats-income="<?= e(json_encode($db['categories']['income'])) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= e($id) ?>">
      <div class="seg" role="radiogroup">
        <?php foreach (['expense' => 'Spent', 'income' => 'Received', 'transfer' => 'Transfer / pay'] as $k => $l): ?>
          <label><input type="radio" name="type" value="<?= $k ?>" <?= $type === $k ? 'checked' : '' ?>><span><?= $l ?></span></label>
        <?php endforeach; ?>
      </div>
      <label class="amount">Amount (₹)<input name="amount" inputmode="decimal" required placeholder="0" value="<?= isset($t['amount']) ? e(rtrim(rtrim(number_format($t['amount'], 2, '.', ''), '0'), '.')) : '' ?>" <?= $full ? 'autofocus' : '' ?>></label>
      <div class="two">
        <label>Date<input type="date" name="date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d', strtotime('+1 year'))) ?>" required></label>
        <div class="chips">
          <button type="button" data-date="<?= e(date('Y-m-d')) ?>">Today</button>
          <button type="button" data-date="<?= e(date('Y-m-d', strtotime('-1 day'))) ?>">Yesterday</button>
        </div>
      </div>
      <label class="for-cat">Category
        <select name="category">
          <?php $cats = $db['categories'][$type === 'income' ? 'income' : 'expense'];
                $cur = $t['category'] ?? $cats[0];
                if (!in_array($cur, $cats, true) && $type !== 'transfer') $cats[] = $cur;
                foreach ($cats as $c): ?>
            <option <?= $c === $cur ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label><span class="lbl-from">Paid from</span>
        <select name="account"><?= account_options($db, $acct) ?></select>
      </label>
      <label class="for-to">To (bank, card, loan or goal fund)
        <select name="to_account"><?= account_options($db, $t['to_account'] ?? null, true) ?></select>
      </label>
      <label>Note <small class="muted">(optional)</small><input name="note" maxlength="200" value="<?= e($t['note'] ?? '') ?>" placeholder="e.g. Groceries at DMart"></label>
      <div class="actions">
        <button class="btn primary"><?= $id ? 'Save changes' : 'Save' ?></button>
        <?php if (!$id): ?><button class="btn" name="again" value="1">Save &amp; add another</button><?php endif; ?>
      </div>
    </form>
<?php }

function page_add(array $db): void {
    $t = [];
    if (!empty($_GET['id'])) {
        foreach ($db['txns'] as $x) if ($x['id'] === $_GET['id']) $t = $x;
    } else {
        if (!empty($_GET['date']) && valid_date($_GET['date'])) $t['date'] = $_GET['date'];
        if (!empty($_GET['type'])) $t['type'] = $_GET['type'];
        if (!empty($_GET['account'])) $t['account'] = $_GET['account'];
    }
    layout(!empty($t['id']) ? 'Edit entry' : 'Add entry', 'add', function () use ($db, $t) { ?>
      <section class="card narrow">
        <h1><?= !empty($t['id']) ? 'Edit entry' : 'Add an entry' ?></h1>
        <?php quick_form($db, $t, true); ?>
        <?php if (!empty($t['id'])): ?>
          <form method="post" action="?p=delete_txn" class="danger" data-confirm="Delete this entry? This can't be undone.">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
            <button class="btn ghost-danger">Delete entry</button>
          </form>
        <?php endif; ?>
      </section>
      <?php
      $byDay = array_filter($db['txns'], fn($x) => $x['date'] === ($t['date'] ?? date('Y-m-d')));
      if ($byDay && empty($t['id'])): ?>
        <section class="card narrow">
          <h2>Already logged for <?= e(date('D, d M', strtotime($t['date'] ?? 'today'))) ?></h2>
          <?php foreach (sort_txns($byDay) as $x) txn_row($db, $x); ?>
        </section>
      <?php endif;
    });
}

/* ---------- entries list ---------- */

function page_txns(array $db): void {
    $ym = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
    $cat = $_GET['c'] ?? '';
    $acct = $_GET['a'] ?? '';
    $list = month_txns($db, $ym);
    if ($cat !== '') $list = array_filter($list, fn($t) => $t['category'] === $cat);
    if ($acct !== '') $list = array_filter($list, fn($t) => $t['account'] === $acct || ($t['to_account'] ?? '') === $acct);
    $list = sort_txns(array_values($list));
    $spent = array_sum(array_map(fn($t) => $t['amount'], array_filter($list, fn($t) => $t['type'] === 'expense')));
    $income = array_sum(array_map(fn($t) => $t['amount'], array_filter($list, fn($t) => $t['type'] === 'income')));
    $prev = date('Y-m', strtotime($ym . '-01 -1 month')); $next = date('Y-m', strtotime($ym . '-01 +1 month'));
    layout('Entries', 'txns', function () use ($db, $ym, $cat, $acct, $list, $spent, $income, $prev, $next) { ?>
      <section class="card">
        <div class="monthnav">
          <a href="./?p=txns&m=<?= e($prev) ?>">← <?= e(date('M', strtotime($prev . '-01'))) ?></a>
          <h1><?= e(date('F Y', strtotime($ym . '-01'))) ?></h1>
          <a href="./?p=txns&m=<?= e($next) ?>"><?= e(date('M', strtotime($next . '-01'))) ?> →</a>
        </div>
        <form class="filters" method="get">
          <input type="hidden" name="p" value="txns"><input type="hidden" name="m" value="<?= e($ym) ?>">
          <select name="c" data-autosubmit><option value="">All categories</option>
            <?php foreach (array_merge($db['categories']['expense'], $db['categories']['income'], ['Transfer']) as $c): ?>
              <option <?= $c === $cat ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select>
          <select name="a" data-autosubmit><option value="">All accounts</option><?= account_options($db, $acct) ?></select>
          <noscript><button class="btn">Filter</button></noscript>
        </form>
        <p class="summary"><span>Spent <b><?= inr($spent) ?></b></span><span>Received <b><?= inr($income) ?></b></span><span><?= count($list) ?> entries</span></p>
        <?php if (!$list): ?><p class="muted">Nothing here.</p><?php endif;
        $lastDate = null;
        foreach ($list as $t):
            if ($t['date'] !== $lastDate): $lastDate = $t['date'];
                $dayTotal = array_sum(array_map(fn($x) => $x['amount'], array_filter($list, fn($x) => $x['date'] === $lastDate && $x['type'] === 'expense'))); ?>
              <h3 class="day"><?= e(date('l, d M', strtotime($lastDate))) ?><span><?= $dayTotal ? inr($dayTotal) . ' spent' : '' ?></span></h3>
            <?php endif; txn_row($db, $t); endforeach; ?>
        <p class="muted small mt"><a href="./?p=export">Download all entries (CSV)</a></p>
      </section>
<?php });
}

/* ---------- accounts ---------- */

function page_accounts(array $db): void {
    $bal = balances($db);
    $edit = null;
    if (!empty($_GET['edit'])) $edit = account_by_id($db, $_GET['edit']);
    layout('Accounts', 'accounts', function () use ($db, $bal, $edit) { ?>
      <section class="card">
        <h1>Accounts, cards &amp; loans</h1>
        <p class="muted small">Balances are calculated from your entries. If one drifts from your bank app, use <b>Set balance</b> to match it.</p>
        <?php foreach (ACCOUNT_TYPES as $type => $label):
            $list = array_filter($db['accounts'], fn($a) => $a['type'] === $type);
            if (!$list) continue; ?>
          <h3 class="day"><?= e($label) ?></h3>
          <?php foreach ($list as $a): $b = $bal[$a['id']]; ?>
            <div class="acct big <?= $a['archived'] ? 'archived' : '' ?>">
              <div>
                <strong><?= e($a['name']) ?></strong>
                <small><?php
                  if ($type === 'card') {
                      $due = next_due($a);
                      echo $due ? 'Next due ' . e($due->format('d M')) : 'No due day set';
                      if (!empty($a['statement_day'])) echo ' · statement on ' . (int)$a['statement_day'];
                      if (!empty($a['limit'])) echo ' · limit ' . inr($a['limit']) . ' · ' . inr(max(0, $a['limit'] - $b)) . ' available';
                  } else echo e($a['note']);
                  if (!empty($a['balance_set_at'])) echo ' · balance set ' . e(date('d M', strtotime($a['balance_set_at'])));
                ?></small>
              </div>
              <div class="num"><b class="<?= !is_liability($a) && $b < 0 ? 'neg' : '' ?>"><?= inr($b) ?></b><small><?= is_liability($a) ? 'owed' : 'balance' ?></small></div>
              <details class="acct-actions">
                <summary>Manage</summary>
                <form method="post" action="?p=set_balance" class="inline">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>">
                  <label><?= is_liability($a) ? 'Actual amount owed now' : 'Actual balance now' ?><input name="balance" inputmode="decimal" value="<?= e(round($b, 2)) ?>"></label>
                  <button class="btn">Set balance</button>
                </form>
                <a class="btn" href="./?p=accounts&edit=<?= e($a['id']) ?>#form">Edit details</a>
                <a class="btn" href="./?p=txns&a=<?= e($a['id']) ?>">Entries</a>
                <form method="post" action="?p=archive_account" class="inline">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($a['id']) ?>">
                  <button class="btn"><?= $a['archived'] ? 'Unhide' : 'Hide' ?></button>
                </form>
              </details>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </section>

      <section class="card narrow" id="form">
        <h2><?= $edit ? 'Edit ' . e($edit['name']) : 'Add an account, card or loan' ?></h2>
        <form method="post" action="?p=save_account" class="stack acctform">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
          <label>Name<input name="name" required maxlength="60" value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. ICICI Savings, HDFC Millennia card"></label>
          <label>Type<select name="type">
            <?php foreach (ACCOUNT_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= ($edit['type'] ?? '') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
          </select></label>
          <?php if (!$edit): ?><label>Current balance / amount owed (₹)<input name="opening" inputmode="decimal" value="0"></label><?php endif; ?>
          <div class="cardonly three">
            <label>Credit limit<input name="limit" inputmode="decimal" value="<?= e($edit['limit'] ?? 0) ?>"></label>
            <label>Statement day<input name="statement_day" type="number" min="0" max="31" value="<?= e($edit['statement_day'] ?? 0) ?>"></label>
            <label>Due day<input name="due_day" type="number" min="0" max="31" value="<?= e($edit['due_day'] ?? 0) ?>"></label>
          </div>
          <label>Note<input name="note" maxlength="200" value="<?= e($edit['note'] ?? '') ?>"></label>
          <div class="actions"><button class="btn primary"><?= $edit ? 'Save' : 'Add' ?></button><?php if ($edit): ?><a class="btn" href="./?p=accounts">Cancel</a><?php endif; ?></div>
        </form>
      </section>
<?php });
}

/* ---------- plan: budgets, categories, goals, password ---------- */

function page_plan(array $db): void {
    $bal = balances($db);
    layout('Plan', 'plan', function () use ($db, $bal) { ?>
      <section class="card narrow">
        <h1>Monthly budgets</h1>
        <form method="post" action="?p=save_plan" class="stack">
          <?= csrf_field() ?>
          <div class="budgets">
          <?php foreach ($db['categories']['expense'] as $c): ?>
            <label><?= e($c) ?><input name="budget[<?= e($c) ?>]" inputmode="decimal" value="<?= e($db['budgets'][$c] ?? '') ?>" placeholder="—"></label>
          <?php endforeach; ?>
          </div>
          <details>
            <summary>Edit categories</summary>
            <div class="two">
              <label>Spending categories (one per line)<textarea name="cat_expense" rows="10"><?= e(implode("\n", $db['categories']['expense'])) ?></textarea></label>
              <label>Income categories (one per line)<textarea name="cat_income" rows="10"><?= e(implode("\n", $db['categories']['income'])) ?></textarea></label>
            </div>
          </details>
          <div class="actions"><button class="btn primary">Save budgets</button></div>
        </form>
      </section>

      <section class="card narrow">
        <h2>Goals</h2>
        <?php foreach ($db['goals'] as $g): $saved = isset($bal[$g['account_id'] ?? '']) ? $bal[$g['account_id']] : 0; ?>
          <details class="goal">
            <summary><b><?= e($g['name']) ?></b> · <?= inr($saved) ?> of <?= inr($g['target']) ?> by <?= e(date('M Y', strtotime($g['target_date']))) ?></summary>
            <?php goal_form($db, $g); ?>
            <form method="post" action="?p=delete_goal" data-confirm="Delete this goal?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= e($g['id']) ?>"><button class="btn ghost-danger">Delete goal</button></form>
          </details>
        <?php endforeach; ?>
        <details class="goal"><summary>+ Add a goal</summary><?php goal_form($db, []); ?></details>
      </section>

      <section class="card narrow">
        <h2>Change password</h2>
        <form method="post" action="?p=change_password" class="stack">
          <?= csrf_field() ?>
          <label>Current password<input type="password" name="current" required autocomplete="current-password"></label>
          <label>New password<input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
          <div class="actions"><button class="btn">Change password</button></div>
        </form>
        <p class="muted small mt"><a href="./?p=export">Download all entries (CSV)</a></p>
      </section>
<?php });
}

function goal_form(array $db, array $g): void { ?>
    <form method="post" action="?p=save_goal" class="stack">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= e($g['id'] ?? '') ?>">
      <label>Name<input name="name" required value="<?= e($g['name'] ?? '') ?>"></label>
      <div class="two">
        <label>Target (₹)<input name="target" inputmode="decimal" value="<?= e($g['target'] ?? '') ?>"></label>
        <label>By<input type="date" name="target_date" value="<?= e($g['target_date'] ?? '') ?>"></label>
      </div>
      <label>Money is kept in<select name="account_id"><option value="">— none —</option><?= account_options($db, $g['account_id'] ?? null) ?></select></label>
      <div class="actions"><button class="btn primary">Save goal</button></div>
    </form>
<?php }
