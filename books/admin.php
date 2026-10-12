<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

session_name('library_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$password = (string) cfg('admin_password', '');
$passwordUsable = CONFIG_LOADED && strlen($password) >= 8 && $password !== 'change-me-to-something-long';
$signedIn = !empty($_SESSION['admin']);
$notice = null;
$error = null;
$syncResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $csrfOk = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

    if ($action === 'login') {
        if ($passwordUsable && hash_equals($password, (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: admin.php');
            exit;
        }
        sleep(1);
        $error = 'That password is not correct.';
    } elseif (!$csrfOk) {
        $error = 'The form expired. Try again.';
    } elseif ($signedIn) {
        try {
            switch ($action) {
                case 'logout':
                    $_SESSION = [];
                    session_destroy();
                    header('Location: admin.php');
                    exit;
                case 'connect':
                    $token = DropboxSource::exchangeCode(cfg('dropbox_app_key'), cfg('dropbox_app_secret'), (string) ($_POST['code'] ?? ''), cfg('_dropbox_hosts', []));
                    save_dropbox_refresh_token($token);
                    $src = make_source();
                    $who = '';
                    try {
                        $who = $src instanceof DropboxSource ? ' as ' . $src->accountName() : '';
                    } catch (Throwable $e) {
                        // Account name is only a courtesy; the file scopes are what matter.
                    }
                    $notice = 'Dropbox connected' . $who . '. Run a sync to load the catalogue.';
                    break;
                case 'disconnect':
                    save_dropbox_refresh_token(null);
                    $notice = 'Dropbox disconnected. Covers and the catalogue already on the site stay until the next sync.';
                    break;
                case 'test':
                    $src = make_source();
                    $v = $src->dbVersion();
                    $notice = 'Found metadata.db (' . $src->describe() . '). The connection works.';
                    break;
                case 'sync':
                case 'sync-force':
                    @set_time_limit(60);
                    $syncResult = (new Sync(make_source()))->run(20.0, $action === 'sync-force');
                    if (($syncResult['status'] ?? '') === 'error') {
                        $error = $syncResult['error'];
                    }
                    break;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$signedIn = !empty($_SESSION['admin']);
page_top('Admin', ['search' => false, 'noindex' => true, 'class' => 'admin']);
?>
  <section class="admin-wrap">
    <h1>Library admin</h1>

    <?php if ($notice): ?><p class="flash flash-ok" role="status"><?= h($notice) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>

<?php if (!$signedIn): ?>
    <?php if (!CONFIG_LOADED): ?>
      <p>Copy <code>app/config.sample.php</code> to <code>app/config.php</code> and set an admin password first.</p>
    <?php elseif (!$passwordUsable): ?>
      <p>Set <code>admin_password</code> in <code>app/config.php</code> to something at least 8 characters long (not the sample value), then reload this page.</p>
    <?php else: ?>
      <form method="post" class="panel login">
        <input type="hidden" name="action" value="login">
        <label for="pw">Admin password</label>
        <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
        <button type="submit" class="btn">Sign in</button>
      </form>
    <?php endif; ?>
<?php else:
    $state = read_state();
    $connected = dropbox_refresh_token() !== null;
    $usingLocal = !empty(cfg('local_library_path'));
    $haveKeys = cfg('dropbox_app_key') && cfg('dropbox_app_secret');
    $coverCount = is_dir(COVER_DIR) ? count(glob(COVER_DIR . '/*.jpg') ?: []) : 0;
    $missing = array_filter(['pdo_sqlite', 'curl', 'gd', 'dom'], fn($e) => !extension_loaded($e));
    $sqliteVersion = '';
    try {
        $sqliteVersion = (string) (new PDO('sqlite::memory:'))->query('SELECT sqlite_version()')->fetchColumn();
    } catch (Throwable $e) {
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $csrf = h($_SESSION['csrf']);
?>
    <div class="panel">
      <h2>Status</h2>
      <dl class="status">
        <div><dt>Source</dt><dd><?= $usingLocal ? h('Folder on this server: ' . cfg('local_library_path')) : ($connected ? 'Dropbox, ' . h(cfg('dropbox_library_path')) : 'Dropbox not connected') ?></dd></div>
        <div><dt>Books</dt><dd><?= isset($state['book_count']) ? number_format((int) $state['book_count']) : 'Not synced yet' ?></dd></div>
        <div><dt>Covers on site</dt><dd><?= number_format($coverCount) ?><?php if (!empty($state['pending'])): ?> (<?= (int) $state['pending'] ?> still to fetch)<?php endif; ?><?php if (!empty($state['failed'])): ?>, <?= count($state['failed']) ?> unavailable in Dropbox<?php endif; ?></dd></div>
        <div><dt>Last checked</dt><dd><?= !empty($state['last_check']) ? h(date('M j, Y g:i a', strtotime($state['last_check']))) : 'Never' ?></dd></div>
        <div><dt>Catalogue changed</dt><dd><?= !empty($state['last_change']) ? h(date('M j, Y g:i a', strtotime($state['last_change']))) : 'Never' ?></dd></div>
        <?php if (!empty($state['last_error'])): ?><div><dt>Last error</dt><dd class="err"><?= h($state['last_error']) ?></dd></div><?php endif; ?>
        <div><dt>PHP</dt><dd><?= h(PHP_VERSION) ?><?= $sqliteVersion ? ' with SQLite ' . h($sqliteVersion) : '' ?><?= $missing ? ', missing: ' . h(implode(', ', $missing)) : ', all needed extensions present' ?></dd></div>
      </dl>
    </div>

    <?php if (!$usingLocal): ?>
    <div class="panel">
      <h2>Dropbox</h2>
      <?php if (!$haveKeys): ?>
        <p>Add <code>dropbox_app_key</code> and <code>dropbox_app_secret</code> to <code>app/config.php</code>, then reload this page. The README explains how to create the Dropbox app.</p>
      <?php elseif (!$connected): ?>
        <ol class="steps">
          <li><a href="<?= h(DropboxSource::authorizeUrl(cfg('dropbox_app_key'))) ?>" target="_blank" rel="noopener">Open Dropbox's permission page</a> in a new tab and click <strong>Allow</strong>.</li>
          <li>Dropbox shows an access code. Copy it.</li>
          <li>Paste it here:</li>
        </ol>
        <form method="post" class="inline-form">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="connect">
          <label class="visually-hidden" for="code">Access code from Dropbox</label>
          <input id="code" name="code" required autocomplete="off" placeholder="Access code">
          <button type="submit" class="btn">Connect Dropbox</button>
        </form>
      <?php else: ?>
        <p>Connected. The site reads <code><?= h(cfg('dropbox_library_path')) ?></code> with read-only access.</p>
        <div class="btn-row">
          <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="test"><button class="btn btn-quiet">Test connection</button></form>
          <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="disconnect"><button class="btn btn-quiet">Disconnect</button></form>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($connected || $usingLocal): ?>
    <div class="panel">
      <h2>Sync</h2>
      <?php if ($syncResult && ($syncResult['status'] ?? '') !== 'error'): ?>
        <p class="flash flash-ok" role="status">
          <?= $syncResult['status'] === 'busy' ? 'A sync is already running. Wait a minute and try again.' : h(sprintf(
              '%s%d covers fetched, %d unavailable, %d still to fetch.',
              !empty($syncResult['db_updated']) ? 'Catalogue updated. ' : 'Catalogue unchanged. ',
              $syncResult['covers_fetched'] ?? 0, $syncResult['covers_failed'] ?? 0, $syncResult['pending'] ?? 0
          )) ?>
        </p>
      <?php endif; ?>
      <?php $more = $syncResult && !empty($syncResult['pending']) && ($syncResult['status'] ?? '') === 'ok'; ?>
      <p>A sync checks for changes in Calibre and fetches new covers. Each click runs for up to 20 seconds<?= $more ? ', and this page keeps going on its own until every cover is in' : '' ?>.</p>
      <div class="btn-row">
        <form method="post" <?= $more ? 'data-autocontinue' : '' ?>><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sync"><button class="btn"><?= $more ? 'Continue sync' : 'Sync now' ?></button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sync-force"><button class="btn btn-quiet">Full refresh</button></form>
      </div>
      <p class="hint">Full refresh downloads the catalogue again and retries covers that failed before.</p>
    </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Hourly updates</h2>
      <p>In cPanel, open <strong>Cron Jobs</strong>, choose <strong>Once Per Hour</strong>, and use this command:</p>
      <pre class="cmd"><code>php -q <?= h(APP_DIR . '/sync.php') ?> --quiet</code></pre>
      <?php if (cfg('cron_key')): ?>
      <p>Or have a web cron service visit this address every hour:</p>
      <pre class="cmd"><code><?= h($base . '/cron.php?key=' . cfg('cron_key')) ?></code></pre>
      <?php endif; ?>
    </div>

    <?php $log = recent_log(15); if ($log): ?>
    <div class="panel">
      <h2>Recent activity</h2>
      <pre class="log"><?= h(implode("\n", array_reverse($log))) ?></pre>
    </div>
    <?php endif; ?>

    <form method="post" class="signout"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button class="link-btn">Sign out</button></form>
<?php endif; ?>
  </section>
<?php
page_bottom();
