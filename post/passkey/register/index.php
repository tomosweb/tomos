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
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode((string) file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
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
$registration = new Tomos\PasskeyRegistrationService($environment, $store, $challenges, $client);

$api = (string) ($_GET['api'] ?? '');
if ($api !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'POST request required.'], 405);
    }

    $payload = requestPayload();
    requireCsrf($payload);

    try {
        if ($api === 'authorize') {
            $postPasswordHash = (string) ($config['security']['post_password_hash'] ?? '');
            $passphrase = (string) ($payload['post_password'] ?? '');
            if ($postPasswordHash === '' || $passphrase === '' || !password_verify($passphrase, $postPasswordHash)) {
                jsonResponse(['ok' => false, 'message' => '管理用合言葉が正しくありません。'], 403);
            }
            $registration->authorizeAfterPassphrase($_SESSION, 300);
            jsonResponse(['ok' => true, 'authorized_for_seconds' => 300]);
        }

        if ($api === 'options') {
            $options = $registration->begin($_SESSION);
            jsonResponse(['ok' => true, 'publicKey' => $options['public_key']]);
        }

        if ($api === 'complete') {
            $label = trim((string) ($payload['label'] ?? ''));
            $credential = $registration->complete($_SESSION, $payload, $label);
            jsonResponse([
                'ok' => true,
                'credential' => [
                    'credential_id' => (string) ($credential['credential_id'] ?? ''),
                    'label' => (string) ($credential['label'] ?? ''),
                    'created_at' => (int) ($credential['created_at'] ?? 0),
                    'rp_id' => (string) ($credential['rp_id'] ?? ''),
                ],
                'count' => count($store->all()),
            ]);
        }

        jsonResponse(['ok' => false, 'message' => '処理を確認できませんでした。'], 404);
    } catch (Throwable $exception) {
        jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
    }
}

$status = $environment->diagnose();
$token = (string) $_SESSION['tomos_post_token'];
$credentials = $store->all();
$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$postUrl = '/' . trim($basePath, '/') . '/post/';
$postUrl = preg_replace('#/+#', '/', $postUrl);
$securityUrl = '/' . trim($basePath, '/') . '/post/security/';
$securityUrl = preg_replace('#/+#', '/', $securityUrl);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>パスキーを追加 - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:760px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}input,button{font:inherit}input[type=password],input[type=text]{box-sizing:border-box;width:100%;max-width:36rem;padding:.65rem;margin:.3rem 0 1rem}button,.button{display:inline-block;padding:.7rem 1rem;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;cursor:pointer}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.ok{color:#166534}.ng{color:#991b1b}.hint{color:#666}code{word-break:break-all}
</style>
<link rel="stylesheet" href="../../assets/tomos-post-security.css">
</head>
<body>
<h1>Tomos Post</h1>
<h2>パスキーを追加</h2>
<p>Tomos Postへ追加するパスキーを登録します。現在の管理用合言葉認証は変更されません。</p>

<?php if (empty($status['available'])): ?>
<div class="result ng">
<p>この環境ではパスキーを登録できません。</p>
<pre><?= htmlspecialchars(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8') ?></pre>
</div>
<?php else: ?>
<form id="authorize-form">
<label for="post_password">管理用合言葉を再入力</label>
<input id="post_password" name="post_password" type="password" autocomplete="current-password" required>
<button type="submit">登録を許可する</button>
</form>

<div id="register-area" hidden>
<label for="label">パスキーの名称</label>
<input id="label" type="text" maxlength="100" placeholder="例: iPhone、MacBook">
<p class="hint">端末名はTomosが自動判定しません。わかりやすい名称を任意で入力してください。</p>
<button id="register-button" type="button">パスキーを登録</button>
</div>
<?php endif; ?>

<p id="result" role="status" aria-live="polite"></p>

<h2>登録済み</h2>
<?php if ($credentials === []): ?>
<p>登録済みパスキーはありません。</p>
<?php else: ?>
<ul>
<?php foreach ($credentials as $credential): ?>
<li><?= htmlspecialchars((string) (($credential['label'] ?? '') ?: '名称なし'), ENT_QUOTES, 'UTF-8') ?> — <code><?= htmlspecialchars((string) ($credential['rp_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<p><a class="button" href="<?= htmlspecialchars((string) $securityUrl, ENT_QUOTES, 'UTF-8') ?>">セキュリティへ戻る</a> <a class="button" href="<?= htmlspecialchars((string) $postUrl, ENT_QUOTES, 'UTF-8') ?>">Tomos Postへ戻る</a></p>

<script>
(() => {
  const token = <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
  const authorizeForm = document.getElementById('authorize-form');
  const registerArea = document.getElementById('register-area');
  const registerButton = document.getElementById('register-button');
  const result = document.getElementById('result');
  if (!authorizeForm || !registerArea || !registerButton || !result || !window.PublicKeyCredential || !navigator.credentials) return;

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

  async function api(name, payload) {
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

  authorizeForm.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      result.className = '';
      result.textContent = '管理用合言葉を確認しています…';
      const password = document.getElementById('post_password');
      await api('authorize', {post_password: password ? password.value : ''});
      if (password) password.value = '';
      authorizeForm.hidden = true;
      registerArea.hidden = false;
      result.className = 'ok';
      result.textContent = '確認しました。5分以内にパスキーを登録してください。';
    } catch (error) {
      result.className = 'ng';
      result.textContent = error && error.message ? error.message : '管理用合言葉を確認できませんでした。';
    }
  });

  registerButton.addEventListener('click', async () => {
    try {
      registerButton.disabled = true;
      result.className = '';
      result.textContent = 'パスキー登録を準備しています…';
      const options = await api('options', {});
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
      result.textContent = `パスキーを登録しました。現在 ${completed.count} 件登録されています。`;
      window.setTimeout(() => window.location.reload(), 700);
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
