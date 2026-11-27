<?php
/* Read-only Gmail link. Uses Google OAuth with the gmail.readonly scope.
   Nothing is ever sent, deleted or changed in the mailbox. */

const GMAIL_SCOPE = 'https://www.googleapis.com/auth/gmail.readonly';
const GMAIL_QUERY = 'newer_than:45d -category:promotions (debited OR credited OR spent OR "amount due" OR "statement" OR "UPI" OR "salary")';

function gmail_cfg(): array { return read_json('gmail.json', []); }
function gmail_save(array $c): void { write_json('gmail.json', $c); }
function gmail_connected(): bool { return !empty(gmail_cfg()['refresh_token']); }

function gmail_redirect_uri(): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . $dir . '/gmail_callback.php';
}

function http_req(string $url, ?array $post = null, array $headers = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => $headers]);
    if ($post !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode((string)$body, true) ?: []];
}

function gmail_auth_url(): string {
    $c = gmail_cfg();
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => $c['client_id'] ?? '', 'redirect_uri' => gmail_redirect_uri(), 'response_type' => 'code',
        'scope' => GMAIL_SCOPE, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $_SESSION['oauth_state'],
    ]);
}

function gmail_handle_callback(): string {
    $c = gmail_cfg();
    if (!empty($_GET['error'])) return 'Google said: ' . $_GET['error'];
    if (!hash_equals($_SESSION['oauth_state'] ?? '', $_GET['state'] ?? '')) return 'Connection check failed. Try again.';
    unset($_SESSION['oauth_state']);
    [$code, $r] = http_req('https://oauth2.googleapis.com/token', [
        'code' => $_GET['code'] ?? '', 'client_id' => $c['client_id'] ?? '', 'client_secret' => $c['client_secret'] ?? '',
        'redirect_uri' => gmail_redirect_uri(), 'grant_type' => 'authorization_code']);
    if ($code !== 200 || empty($r['refresh_token'])) return 'Google did not return access (' . ($r['error_description'] ?? $r['error'] ?? 'no refresh token') . ').';
    $c['refresh_token'] = $r['refresh_token'];
    $c['access_token'] = $r['access_token'] ?? '';
    $c['expires_at'] = time() + (int)($r['expires_in'] ?? 3000) - 60;
    [$pc, $prof] = http_req('https://gmail.googleapis.com/gmail/v1/users/me/profile', null, ['Authorization: Bearer ' . $c['access_token']]);
    $c['email'] = $prof['emailAddress'] ?? '';
    gmail_save($c);
    return '';
}

function gmail_token(): ?string {
    $c = gmail_cfg();
    if (empty($c['refresh_token'])) return null;
    if (!empty($c['access_token']) && ($c['expires_at'] ?? 0) > time()) return $c['access_token'];
    [$code, $r] = http_req('https://oauth2.googleapis.com/token', [
        'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'],
        'refresh_token' => $c['refresh_token'], 'grant_type' => 'refresh_token']);
    if ($code !== 200 || empty($r['access_token'])) return null;
    $c['access_token'] = $r['access_token'];
    $c['expires_at'] = time() + (int)($r['expires_in'] ?? 3000) - 60;
    gmail_save($c);
    return $c['access_token'];
}

/** Pull recent money-related mails and guess amount/direction. Returns [error, rows]. */
function gmail_sync(): array {
    $tok = gmail_token();
    if (!$tok) return ['Gmail access expired. Disconnect and connect again.', []];
    $h = ['Authorization: Bearer ' . $tok];
    [$code, $list] = http_req('https://gmail.googleapis.com/gmail/v1/users/me/messages?maxResults=40&q=' . urlencode(GMAIL_QUERY), null, $h);
    if ($code !== 200) return ['Gmail returned an error (' . $code . ').', []];
    $rows = [];
    foreach (($list['messages'] ?? []) as $m) {
        [$mc, $msg] = http_req('https://gmail.googleapis.com/gmail/v1/users/me/messages/' . urlencode($m['id'])
            . '?format=metadata&metadataHeaders=Subject&metadataHeaders=From', null, $h);
        if ($mc !== 200) continue;
        $hd = []; foreach (($msg['payload']['headers'] ?? []) as $x) $hd[strtolower($x['name'])] = $x['value'];
        $text = ($hd['subject'] ?? '') . ' ' . ($msg['snippet'] ?? '');
        $rows[] = array_merge(['id' => $m['id'], 'date' => date('Y-m-d', (int)(($msg['internalDate'] ?? 0) / 1000)),
            'from' => mb_substr($hd['from'] ?? '', 0, 60), 'subject' => mb_substr($hd['subject'] ?? '', 0, 90),
            'snippet' => mb_substr(html_entity_decode($msg['snippet'] ?? ''), 0, 140)], gmail_guess($text));
    }
    $c = gmail_cfg(); $c['rows'] = $rows; $c['synced_at'] = date('c'); gmail_save($c);
    return ['', $rows];
}

function gmail_guess(string $t): array {
    $amount = null; $kind = null; $label = '';
    if (preg_match('/(?:total\s+amount\s+due|amount\s+due|total\s+due)[^0-9]{0,25}(?:Rs\.?|INR|₹)?\s*([\d,]+(?:\.\d{1,2})?)/iu', $t, $m)) {
        $amount = (float)str_replace(',', '', $m[1]); $kind = 'due'; $label = 'Card bill due';
    } elseif (preg_match('/(?:Rs\.?|INR|₹)\s*([\d,]+(?:\.\d{1,2})?)/iu', $t, $m)) {
        $amount = (float)str_replace(',', '', $m[1]);
        if (preg_match('/credited|received|refund|salary|cashback/i', $t)) { $kind = 'income'; $label = 'Money in'; }
        elseif (preg_match('/debited|spent|paid|purchase|withdrawn|sent/i', $t)) { $kind = 'expense'; $label = 'Money out'; }
    }
    return ['amount' => $amount, 'kind' => $kind, 'label' => $label];
}

function page_gmail(array $db): void {
    $c = gmail_cfg();
    $conn = gmail_connected();
    $rows = $c['rows'] ?? [];
    layout('Gmail', 'gmail', function () use ($c, $conn, $rows) { ?>
      <section class="card narrow">
        <h1>Connect Gmail</h1>
        <p class="muted">Read-only. The tracker can look for bank, UPI and card emails and suggest entries. It can never send, delete or change anything, and nothing is added until you tap Add.</p>
        <?php if ($conn): ?>
          <p><strong>Connected:</strong> <?= e($c['email'] ?? 'Gmail') ?></p>
          <form method="post" action="?p=gmail_sync" class="actions"><?= csrf_field() ?>
            <button class="btn primary">Check my emails now</button></form>
          <form method="post" action="?p=gmail_disconnect" class="danger" data-confirm="Disconnect Gmail?"><?= csrf_field() ?>
            <button class="btn ghost-danger">Disconnect</button></form>
        <?php elseif (!empty($c['client_id']) && !empty($c['client_secret'])): ?>
          <p><a class="btn primary" href="./?p=gmail_connect">Connect my Gmail</a></p>
          <p class="muted">Google will ask you to sign in and allow read-only access. If it warns the app is unverified, choose Continue; it is your own app and only you are allowed in.</p>
        <?php endif; ?>
      </section>

      <?php if (!$conn): ?>
      <section class="card narrow">
        <h2>One-time Google setup</h2>
        <ol class="muted">
          <li>Open console.cloud.google.com, create a project, and enable the <strong>Gmail API</strong>.</li>
          <li>Under APIs &amp; Services → OAuth consent screen: choose External, add your own Gmail as a <strong>Test user</strong>.</li>
          <li>Credentials → Create credentials → <strong>OAuth client ID</strong> → Web application. Add this redirect URI exactly:<br><code><?= e(gmail_redirect_uri()) ?></code></li>
          <li>Paste the Client ID and Client secret below and save, then tap Connect.</li>
        </ol>
        <form method="post" action="?p=gmail_save" class="stack"><?= csrf_field() ?>
          <label>Client ID<input name="client_id" required autocomplete="off" value="<?= e($c['client_id'] ?? '') ?>"></label>
          <label>Client secret<input type="password" name="client_secret" required autocomplete="off" placeholder="<?= !empty($c['client_secret']) ? 'saved (type to replace)' : '' ?>"></label>
          <button class="btn">Save</button>
        </form>
        <p class="muted"><small>While the app is in Google's testing mode, the connection lapses every 7 days and you simply tap Connect again.</small></p>
      </section>
      <?php endif; ?>

      <?php if ($conn): ?>
      <section class="card narrow">
        <h2>Suggested entries <?php if (!empty($c['synced_at'])): ?><small class="muted">(checked <?= e(date('d M, H:i', strtotime($c['synced_at']))) ?>)</small><?php endif; ?></h2>
        <?php if (!$rows): ?><p class="muted">Nothing yet. Tap "Check my emails now".</p><?php endif; ?>
        <?php foreach ($rows as $r): ?>
          <div class="mailrow">
            <div>
              <strong><?= e($r['subject'] ?: '(no subject)') ?></strong><br>
              <small class="muted"><?= e($r['date']) ?> · <?= e($r['from']) ?></small><br>
              <small class="muted"><?= e($r['snippet']) ?></small>
            </div>
            <?php if ($r['amount']): ?>
              <div>
                <strong><?= e($r['label'] ?: 'Amount') ?>: <?= e(inr($r['amount'])) ?></strong><br>
                <?php if ($r['kind'] === 'expense' || $r['kind'] === 'income'): ?>
                  <a class="btn" href="./?p=add&amp;type=<?= e($r['kind']) ?>&amp;amount=<?= e($r['amount']) ?>&amp;date=<?= e($r['date']) ?>&amp;note=<?= e(rawurlencode(mb_substr($r['subject'], 0, 60))) ?>">Add</a>
                <?php else: ?><small class="muted">Check the card's due date on Accounts</small><?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
          <hr>
        <?php endforeach; ?>
      </section>
      <?php endif; ?>
    <?php });
}
