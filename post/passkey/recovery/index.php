<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 3) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__, 3);
$configPath = $rootDir . '/config.php';
$config = [];
if (is_file($configPath)) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}

if (PHP_VERSION_ID >= 80000) {
    $vendor = $rootDir . '/core/webauthn/vendor/autoload.php';
    if (is_file($vendor)) {
        require_once $vendor;
    }
}

if (empty($_SESSION['tomos_post_token'])) {
    $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestPayload(): array
{
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function requireCsrf(array $payload): void
{
    $expected = (string) ($_SESSION['tomos_post_token'] ?? '');
    $actual = (string) ($payload['_token'] ?? '');
    if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
        jsonResponse(['ok' => false, 'message' => 'フォームの有効期限が切れました。画面を再読み込みしてください。'], 403);
    }
}

$environment = new Tomos\PasskeyEnvironment($config);
$store = new Tomos\PasskeyCredentialStore($config, $rootDir);
$challenges = new Tomos\PasskeyChallengeStore();
$client = new Tomos\LbuchsPasskeyWebAuthnClient();
$recovery = new Tomos\PasskeyServerRecoveryService($environment, $store, $challenges, $client, $rootDir);

$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$passwordResetUrl = '/' . trim($basePath, '/') . '/post/passkey/password-reset/';
$passwordResetUrl = preg_replace('#/+#', '/', $passwordResetUrl);
$postUrl = '/' . trim($basePath, '/') . '/post/';
$postUrl = preg_replace('#/+#', '/', $postUrl);

$download = (string) ($_GET['download'] ?? '');
if ($download !== '') {
    try {
        $data = $recovery->recoveryDownloadData($_SESSION);
        $fileName = (string) $data['name'];
        $contents = (string) $data['contents'];

        if ($download === 'txt') {
            header('Content-Type: text/plain; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Cache-Control: no-store');
            echo $contents;
            exit;
        }

        if ($download === 'zip') {
            if (!class_exists('ZipArchive')) {
                http_response_code(501);
                header('Content-Type: text/plain; charset=UTF-8');
                header('Cache-Control: no-store');
                echo 'この環境ではZIPを作成できません。テキストファイルを直接ダウンロードしてください。';
                exit;
            }

            $tmp = tempnam(sys_get_temp_dir(), 'tomos-recovery-');
            if (!is_string($tmp) || $tmp === '') {
                throw new RuntimeException('復旧ZIPを準備できませんでした。');
            }
            $zipPath = $tmp . '.zip';
            @unlink($tmp);

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('復旧ZIPを作成できませんでした。');
            }
            if (!$zip->addFromString($fileName, $contents)) {
                $zip->close();
                @unlink($zipPath);
                throw new RuntimeException('復旧ファイルをZIPへ追加できませんでした。');
            }
            $zip->close();

            $zipBytes = file_get_contents($zipPath);
            @unlink($zipPath);
            if (!is_string($zipBytes)) {
                throw new RuntimeException('復旧ZIPを読み込めませんでした。');
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="tomos-recovery.zip"');
            header('Content-Length: ' . strlen($zipBytes));
            header('Cache-Control: no-store');
            echo $zipBytes;
            exit;
        }

        http_response_code(404);
        exit;
    } catch (Throwable $exception) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
        echo $exception->getMessage();
        exit;
    }
}

$api = (string) ($_GET['api'] ?? '');
if ($api !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'POST request required.'], 405);
    }

    $payload = requestPayload();
    requireCsrf($payload);

    try {
        if ($api === 'issue') {
            $recovery->issueChallenge($_SESSION, 600);
            jsonResponse([
                'ok' => true,
                'file_name' => $recovery->recoveryFileName($_SESSION),
                'zip_available' => class_exists('ZipArchive'),
                'expires_in' => 600,
            ]);
        }

        if ($api === 'verify') {
            $recovery->verifyServerAccess($_SESSION, 300);
            jsonResponse(['ok' => true, 'authorized_for_seconds' => 300]);
        }

        if ($api === 'options') {
            $options = $recovery->beginRegistration($_SESSION);
            jsonResponse(['ok' => true, 'publicKey' => $options['public_key']]);
        }

        if ($api === 'complete') {
            $label = trim((string) ($payload['label'] ?? ''));
            $credential = $recovery->completeRegistration($_SESSION, $payload, $label);
            jsonResponse([
                'ok' => true,
                'credential' => [
                    'label' => (string) ($credential['label'] ?? ''),
                    'created_at' => (int) ($credential['created_at'] ?? 0),
                    'rp_id' => (string) ($credential['rp_id'] ?? ''),
                ],
                'password_reset_url' => $passwordResetUrl,
            ]);
        }

        jsonResponse(['ok' => false, 'message' => '処理を確認できませんでした。'], 404);
    } catch (Throwable $exception) {
        jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
    }
}

$status = $environment->diagnose();
$token = (string) $_SESSION['tomos_post_token'];
$currentRpId = '';
try {
    $currentRpId = $environment->rpId();
} catch (Throwable $exception) {
    $currentRpId = '';
}
$hasCredential = false;
foreach ($store->all() as $record) {
    if ($currentRpId !== '' && (string) ($record['rp_id'] ?? '') === $currentRpId) {
        $hasCredential = true;
        break;
    }
}
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tomos Postを復旧</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}input,button{font:inherit}input[type=text]{box-sizing:border-box;width:100%;max-width:36rem;padding:.65rem;margin:.3rem 0 1rem}button,.button{display:inline-block;padding:.7rem 1rem;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;cursor:pointer}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.ok{color:#166534}.ng{color:#991b1b}.hint{color:#666}.steps{padding-left:1.4rem}.steps li{margin:.8rem 0}code{word-break:break-all}
</style>
<link rel="stylesheet" href="../../assets/tomos-post-security.css">
</head>
<body>
<h1>Tomos Post</h1>
<h2>Tomos Postを復旧</h2>
<p>管理用合言葉を忘れ、登録済みパスキーもない場合に、サーバーへの書き込み権限を確認して最初のパスキーを登録します。</p>

<?php if (empty($status['available'])): ?>
<div class="result ng">この環境ではパスキーを利用できません。</div>
<?php elseif ($hasCredential): ?>
<div class="result">
<p>このTomosには登録済みパスキーがあるため、サーバー所有確認による復旧は利用できません。</p>
<p><a class="button" href="<?= htmlspecialchars((string) $passwordResetUrl, ENT_QUOTES, 'UTF-8') ?>">登録済みパスキーで合言葉を再設定</a></p>
</div>
<?php else: ?>
<section id="issue-area">
<h2>1. 復旧ファイルを準備</h2>
<p>復旧ファイルは10分間有効です。</p>
<button id="issue-button" type="button">復旧ファイルを準備</button>
</section>

<section id="server-area" hidden>
<h2>2. 復旧ファイルをアップロード</h2>
<ol class="steps">
<li><a id="zip-download" class="button" href="?download=zip">復旧ファイルをダウンロード（ZIP）</a><br><span id="zip-note" class="hint"></span></li>
<li>ダウンロードしたZIPファイルを展開してください。</li>
<li>展開すると <code id="recovery-file-name"></code> が入っています。</li>
<li><strong>ZIPそのものではなく、展開した .txt ファイルだけ</strong>をSFTP/FTPでTomosの設置フォルダへアップロードしてください。<br><span class="hint">目印は <code>config.php</code> があるフォルダです。</span></li>
<li>アップロードできたら、下の「サーバー所有を確認」を押してください。</li>
</ol>
<p class="hint">確認成功後、アップロードした復旧ファイルはTomosが自動削除します。削除できない場合は復旧を続行しません。</p>
<p><a id="txt-download" href="?download=txt">ZIPを利用できない場合はテキストファイルを直接ダウンロード</a></p>
<button id="verify-button" type="button">サーバー所有を確認</button>
</section>

<section id="register-area" hidden>
<h2>3. 最初のパスキーを登録</h2>
<p>サーバー所有確認が完了しました。5分以内に1件だけパスキーを登録できます。</p>
<label for="label">パスキーの名称</label>
<input id="label" type="text" maxlength="100" placeholder="例: iPhone、MacBook">
<button id="register-button" type="button">パスキーを登録</button>
</section>
<?php endif; ?>

<p id="result" role="status" aria-live="polite"></p>
<p><a class="button" href="<?= htmlspecialchars((string) $postUrl, ENT_QUOTES, 'UTF-8') ?>">Tomos Postへ戻る</a></p>

<script>
(() => {
  const token = <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
  const issueButton = document.getElementById('issue-button');
  const verifyButton = document.getElementById('verify-button');
  const registerButton = document.getElementById('register-button');
  const serverArea = document.getElementById('server-area');
  const registerArea = document.getElementById('register-area');
  const fileName = document.getElementById('recovery-file-name');
  const zipDownload = document.getElementById('zip-download');
  const zipNote = document.getElementById('zip-note');
  const result = document.getElementById('result');
  if (!result) return;

  const bytesToBase64 = value => {
    const bytes = new Uint8Array(value);
    let binary = '';
    bytes.forEach(byte => binary += String.fromCharCode(byte));
    return btoa(binary);
  };

  const base64UrlToBytes = value => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4);
    const raw = atob(base64);
    return Uint8Array.from(raw, char => char.charCodeAt(0));
  };

  const normalizeOptions = publicKey => {
    publicKey.challenge = base64UrlToBytes(publicKey.challenge);
    publicKey.user.id = base64UrlToBytes(publicKey.user.id);
    if (Array.isArray(publicKey.excludeCredentials)) {
      publicKey.excludeCredentials = publicKey.excludeCredentials.map(item => ({...item, id: base64UrlToBytes(item.id)}));
    }
    return publicKey;
  };

  async function api(name, payload = {}) {
    const response = await fetch('?api=' + encodeURIComponent(name), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({_token: token, ...payload}),
    });
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || '処理に失敗しました。');
    return data;
  }

  if (issueButton) issueButton.addEventListener('click', async () => {
    try {
      issueButton.disabled = true;
      result.className = '';
      result.textContent = '復旧ファイルを準備しています…';
      const issued = await api('issue');
      if (fileName) fileName.textContent = issued.file_name;
      if (zipDownload && !issued.zip_available) {
        zipDownload.hidden = true;
        if (zipNote) zipNote.textContent = 'この環境ではZIPを利用できません。下のテキストファイルを直接ダウンロードしてください。';
      }
      if (serverArea) serverArea.hidden = false;
      result.className = 'ok';
      result.textContent = '復旧ファイルを準備しました。10分以内にダウンロードしてアップロードしてください。';
    } catch (error) {
      result.className = 'ng';
      result.textContent = error && error.message ? error.message : '復旧ファイルを準備できませんでした。';
    } finally {
      issueButton.disabled = false;
    }
  });

  if (verifyButton) verifyButton.addEventListener('click', async () => {
    try {
      verifyButton.disabled = true;
      result.className = '';
      result.textContent = 'サーバー上の復旧ファイルを確認しています…';
      await api('verify');
      if (registerArea) registerArea.hidden = false;
      result.className = 'ok';
      result.textContent = 'サーバー所有を確認し、復旧ファイルを削除しました。';
    } catch (error) {
      result.className = 'ng';
      result.textContent = error && error.message ? error.message : 'サーバー所有を確認できませんでした。';
    } finally {
      verifyButton.disabled = false;
    }
  });

  if (registerButton) registerButton.addEventListener('click', async () => {
    if (!window.PublicKeyCredential || !navigator.credentials) {
      result.className = 'ng';
      result.textContent = 'このブラウザではパスキーを利用できません。';
      return;
    }
    try {
      registerButton.disabled = true;
      result.className = '';
      result.textContent = 'パスキー登録を準備しています…';
      const options = await api('options');
      const credential = await navigator.credentials.create({publicKey: normalizeOptions(options.publicKey)});
      if (!credential) throw new Error('パスキーを作成できませんでした。');
      const transports = typeof credential.response.getTransports === 'function'
        ? credential.response.getTransports()
        : [];
      const label = document.getElementById('label');
      const completed = await api('complete', {
        label: label ? label.value : '',
        clientDataJSON: bytesToBase64(credential.response.clientDataJSON),
        attestationObject: bytesToBase64(credential.response.attestationObject),
        transports,
      });
      result.className = 'ok';
      result.textContent = 'パスキーを登録しました。続いて、そのパスキーで認証して管理用合言葉を再設定します。';
      window.setTimeout(() => { window.location.href = completed.password_reset_url; }, 900);
    } catch (error) {
      result.className = 'ng';
      result.textContent = error && error.message ? error.message : 'パスキーを登録できませんでした。';
    } finally {
      registerButton.disabled = false;
    }
  });
})();
</script>
</body>
</html>
