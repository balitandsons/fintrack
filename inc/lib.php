<?php
require_once __DIR__ . '/config.php';

date_default_timezone_set(TIMEZONE);

/* ---------- storage ---------- */

function data_path(string $file): string { return DATA_DIR . '/' . $file; }

function ensure_data_dir(): void {
    if (!is_dir(DATA_DIR)) { mkdir(DATA_DIR, 0750, true); }
    $ht = data_path('.htaccess');
    if (!file_exists($ht)) { file_put_contents($ht, "Require all denied\nDeny from all\n"); }
    if (!is_dir(data_path('sessions'))) { mkdir(data_path('sessions'), 0700, true); }
}

function read_json(string $file, $default) {
    $p = data_path($file);
    if (!file_exists($p)) return $default;
    $raw = file_get_contents($p);
    $d = json_decode($raw, true);
    return is_array($d) ? $d : $default;
}

function write_json(string $file, array $data): void {
    $p = data_path($file);
    $tmp = $p . '.tmp' . bin2hex(random_bytes(3));
    file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    rename($tmp, $p);
}

/** Run $fn with an exclusive lock; $fn receives the db by reference. */
function db_update(callable $fn) {
    $lock = fopen(data_path('.lock'), 'c');
    flock($lock, LOCK_EX);
    $db = db_load();
    $result = $fn($db);
    $db['updated_at'] = date('c');
    write_json('db.json', $db);
    flock($lock, LOCK_UN);
    fclose($lock);
    return $result;
}

function db_load(): array {
    $db = read_json('db.json', []);
    return $db ?: seed_db();
}

function new_id(): string { return bin2hex(random_bytes(6)); }

function seed_db(string $starter = ''): array {
    $acc = fn($name, $type, $opening = 0) => [
        'id' => new_id(), 'name' => $name, 'type' => $type, 'opening' => $opening,
        'note' => '', 'archived' => false,
    ];
    $db = [
        'version' => 1,
        'created_at' => date('c'),
        'categories' => [
            'expense' => ['Food & groceries', 'Eating out', 'Travel', 'Bills & recharge', 'Rent', 'Family support',
                          'Shopping', 'Health', 'Wedding', 'Gifts', 'Business', 'Fees & charges', 'Other'],
            'income'  => ['Salary', 'Freelance / side income', 'Business sales', 'Refund / cashback', 'Other income'],
        ],
        'accounts' => [$acc('Bank account', 'bank'), $acc('Cash', 'cash'), $acc('Credit card', 'card')],
        'txns' => [],
        'budgets' => [],
        'goals' => [],
    ];
    if (trim($starter) !== '') apply_starter($db, $starter);
    return $db;
}

/**
 * Optional first-run setup text, one item per line, fields split by "|":
 *   Account name | type | amount | note        (type: bank, cash, wallet, savings, card, loan)
 *   budget | Category | monthly amount
 *   goal | Name | target amount | YYYY-MM-DD | account name
 */
function apply_starter(array &$db, string $text): void {
    $accounts = []; $budgets = []; $goals = [];
    $num = fn($v) => (float)str_replace(',', '', $v ?? '0');
    foreach (preg_split('/\R/', $text) as $line) {
        $p = array_map('trim', explode('|', $line));
        if (count($p) < 2 || $p[0] === '') continue;
        $kind = strtolower($p[0]);
        if ($kind === 'budget') { if ($num($p[2] ?? 0) > 0) $budgets[mb_substr($p[1], 0, 40)] = $num($p[2]); continue; }
        if ($kind === 'goal') { $goals[] = $p; continue; }
        $type = strtolower($p[1]);
        if (!isset(ACCOUNT_TYPES[$type])) continue;
        $accounts[] = ['id' => new_id(), 'name' => mb_substr($p[0], 0, 60), 'type' => $type,
                       'opening' => $num($p[2] ?? 0), 'note' => mb_substr($p[3] ?? '', 0, 200), 'archived' => false];
    }
    if ($accounts) $db['accounts'] = array_slice($accounts, 0, 40);
    if ($budgets) $db['budgets'] = $budgets;
    foreach ($goals as $g) {
        $accId = '';
        foreach ($db['accounts'] as $a) if (strcasecmp($a['name'], $g[4] ?? '') === 0) $accId = $a['id'];
        $db['goals'][] = ['id' => new_id(), 'name' => mb_substr($g[1], 0, 60), 'target' => $num($g[2] ?? 0),
                          'target_date' => valid_date($g[3] ?? '') ? $g[3] : date('Y-12-31'), 'account_id' => $accId];
    }
}

/* ---------- helpers ---------- */

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Indian number grouping: 12,34,567 */
function inr($n, bool $sym = true): string {
    $neg = $n < 0; $n = abs(round((float)$n));
    $s = (string)(int)$n;
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s = $rest . ',' . $last3;
    }
    return ($neg ? '−' : '') . ($sym ? '₹' : '') . $s;
}

function is_liability(array $a): bool { return in_array($a['type'], ['card', 'loan'], true); }

const ACCOUNT_TYPES = [
    'bank' => 'Bank account', 'cash' => 'Cash', 'wallet' => 'UPI / wallet', 'savings' => 'Savings / goal fund',
    'card' => 'Credit card', 'loan' => 'Loan I owe',
];

/** Balance per account. Assets: money you have. Card/loan: amount you owe. */
function balances(array $db): array {
    $bal = [];
    $types = [];
    foreach ($db['accounts'] as $a) { $bal[$a['id']] = (float)$a['opening']; $types[$a['id']] = is_liability($a); }
    foreach ($db['txns'] as $t) {
        $amt = (float)$t['amount'];
        $from = $t['account'] ?? null; $to = $t['to_account'] ?? null;
        if ($t['type'] === 'expense' && isset($bal[$from])) {
            $bal[$from] += $types[$from] ? $amt : -$amt;
        } elseif ($t['type'] === 'income' && isset($bal[$from])) {
            $bal[$from] += $types[$from] ? -$amt : $amt;
        } elseif ($t['type'] === 'transfer') {
            if (isset($bal[$from])) $bal[$from] += $types[$from] ? $amt : -$amt;
            if (isset($bal[$to]))   $bal[$to]   += $types[$to] ? -$amt : $amt;
        }
    }
    return $bal;
}

function account_by_id(array $db, ?string $id): ?array {
    foreach ($db['accounts'] as $a) if ($a['id'] === $id) return $a;
    return null;
}

function next_due(array $a): ?DateTime {
    $d = (int)($a['due_day'] ?? 0);
    if ($d < 1 || $d > 31) return null;
    $now = new DateTime('today');
    $y = (int)$now->format('Y'); $m = (int)$now->format('n');
    for ($i = 0; $i < 2; $i++) {
        $days = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        $cand = new DateTime(sprintf('%04d-%02d-%02d', $y, $m, min($d, $days)));
        if ($cand >= $now) return $cand;
        $m++; if ($m > 12) { $m = 1; $y++; }
    }
    return null;
}

function month_txns(array $db, string $ym): array {
    return array_values(array_filter($db['txns'], fn($t) => substr($t['date'], 0, 7) === $ym));
}

function sort_txns(array $txns): array {
    usort($txns, fn($a, $b) => [$b['date'], $b['created'] ?? ''] <=> [$a['date'], $a['created'] ?? '']);
    return $txns;
}

function valid_date(string $d): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/* ---------- auth & session ---------- */

function start_session(): void {
    ensure_data_dir();
    $life = SESSION_DAYS * 86400;
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_strict_mode', '1');
    session_save_path(data_path('sessions'));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('mt_sess');
    session_set_cookie_params(['lifetime' => $life, 'path' => '/', 'secure' => $https,
                               'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

function auth(): array { return read_json('auth.json', []); }
function has_password(): bool { return !empty(auth()['hash']); }
function logged_in(): bool { return !empty($_SESSION['uid']) && has_password(); }

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function check_csrf(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400); exit('Form expired. Go back, refresh and try again.');
    }
}

/** Simple throttle: max 5 failed attempts per 15 minutes. */
function login_locked(): bool {
    $a = auth();
    $recent = array_filter($a['failed'] ?? [], fn($t) => $t > time() - 900);
    return count($recent) >= 5;
}
function record_failed_login(): void {
    $a = auth();
    $a['failed'] = array_values(array_filter($a['failed'] ?? [], fn($t) => $t > time() - 900));
    $a['failed'][] = time();
    write_json('auth.json', $a);
}

function redirect(string $to): void { header('Location: ' . $to); exit; }

function flash(?string $msg = null): ?string {
    if ($msg !== null) { $_SESSION['flash'] = $msg; return null; }
    $m = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $m;
}
