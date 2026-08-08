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
        jsonResponse(['ok' => false, 'message' => '画面の有効期限が切れました。再読み込みしてください。'], 403);
    }
}

$environment = new Tomos\PasskeyEnvironment($config);
$store = new Tomos\PasskeyCredentialStore($config, $rootDir);
$challenges = new Tomos\PasskeyChallengeStore();
$client = new Tomos\LbuchsPasskeyWebAuthnClient();
$authentication = new Tomos\PasskeyAuthenticationService($environment, $store, $challenges, $client);

$api = (string) ($_GET['api'] ?? '');
if ($api !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'message' => 'POST request required.'], 405);
    }

    $payload = requestPayload();
    requireCsrf($payload);

    try {
        if ($api === 'options') {
            $options = $authentication->begin($_SESSION);
            jsonResponse(['ok' => true, 'publicKey' => $options['public_key']]);
        }

        if ($api === 'complete') {
            $credential = $authentication->complete($_SESSION, $payload);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            jsonResponse([
                'ok' => true,
                'credential' => [
                    'label' => (string) ($credential['label'] ?? ''),
                    'last_used_at' => (int) ($credential['last_used_at'] ?? 0),
                ],
            ]);
        }

        jsonResponse(['ok' => false, 'message' => '処理を確認できませんでした。'], 404);
    } catch (Throwable $exception) {
        jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 400);
    }
}

$status = $environment->diagnose();
$credentials = [];
if (!empty($status['available'])) {
    try {
        $rpId = $environment->rpId();
        $credentials = array_values(array_filter($store->all(), static function (array $record) use ($rpId): bool {
            return (string) ($record['rp_id'] ?? '') === $rpId;
        }));
    } catch (Throwable $exception) {
        $credentials = [];
    }
}
$token = (string) $_SESSION['tomos_post_token'];
$basePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$postUrl = '/' . trim($basePath, '/') . '/post/';
$postUrl = preg_replace('#/+#', '/', $postUrl);
$registerUrl = '/' . trim($basePath, '/') . '/post/passkey/register/';
$registerUrl = preg_replace('#/+#', '/', $registerUrl);
$securityUrl = '/' . trim($basePath, '/') . '/post/security/';
$securityUrl = preg_replace('#/+#', '/', $securityUrl);
$forgotUrl = '/' . trim($basePath, '/') . ($credentials === [] ? '/post/passkey/recovery/' : '/post/passkey/password-reset/');
$forgotUrl = preg_replace('#/+#', '/', $forgotUrl);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>パスキーで開く - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:720px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}button,.button{display:inline-block;padding:.75rem 1rem;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;cursor:pointer}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.ok{color:#166534}.ng{color:#991b1b}.hint{color:#666}
</style>
<link rel="stylesheet" href="../../assets/tomos-post-security.css">
</head>
<body>
<h1>Tomos Post</h1>
<h2>パスキーで開く</h2>
<p>登録済みパスキーで認証します。管理用合言葉認証は従来どおり利用できます。</p>

<?php if (empty($status['available'])): ?>
<div class="result ng"><p>この環境ではパスキー認証を利用できません。</p></div>
<?php elseif ($credentials === []): ?>
<div class="result"><p>登録済みパスキーがありません。</p><p><a class="button" href="<?= htmlspecialchars((string) $registerUrl, ENT_QUOTES, 'UTF-8') ?>">管理用合言葉が分かる場合はパスキーを登録</a></p><p><a class="button" href="<?= htmlspecialchars((string) $forgotUrl, ENT_QUOTES, 'UTF-8') ?>">管理用合言葉も忘れた場合はTomos Postを復旧</a></p></div>
<?php else: ?>
<p class="hint">登録済み: <?= count($credentials) ?> 件</p>
<button id="login-button" type="button">パスキーで開く</button>
<?php endif; ?>

<p id="result" role="status" aria-live="polite"></p>
<p><a class="button" href="<?= htmlspecialchars((string) $postUrl, ENT_QUOTES, 'UTF-8') ?>">管理用合言葉で開く</a> <a class="button" href="<?= htmlspecialchars((string) $forgotUrl, ENT_QUOTES, 'UTF-8') ?>">合言葉を忘れた場合</a></p>
<p><a class="button" href="<?= htmlspecialchars((string) $securityUrl, ENT_QUOTES, 'UTF-8') ?>">セキュリティへ戻る</a></p>

<script>
(() => {
  const button = document.getElementById('login-button');
  const result = document.getElementById('result');
  if (!button || !result || !window.PublicKeyCredential || !navigator.credentials) return;

  const token = <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
  const postUrl = <?= json_encode($postUrl, JSON_UNESCAPED_SLASHES) ?>;

  const base64UrlToBytes = value => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - value.length % 4) % 4);
    const raw = atob(base64);
    return Uint8Array.from(raw, char => char.charCodeAt(0));
  };

  const bytesToBase64 = value => {
    const bytes = new Uint8Array(value);
    let binary = '';
    bytes.forEach(byte => binary += String.fromCharCode(byte));
    return btoa(binary);
  };

  const bytesToBase64Url = value => bytesToBase64(value)
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/g, '');

  const normalizeOptions = publicKey => {
    publicKey.challenge = base64UrlToBytes(publicKey.challenge);
    if (Array.isArray(publicKey.allowCredentials)) {
      publicKey.allowCredentials = publicKey.allowCredentials.map(item => ({...item, id: base64UrlToBytes(item.id)}));
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

  button.addEventListener('click', async () => {
    try {
      button.disabled = true;
      result.className = '';
      result.textContent = 'パスキー認証を準備しています…';
      const options = await api('options', {});
      const credential = await navigator.credentials.get({publicKey: normalizeOptions(options.publicKey)});
      if (!credential) throw new Error('パスキーを確認できませんでした。');

      await api('complete', {
        credential_id: bytesToBase64Url(credential.rawId),
        clientDataJSON: bytesToBase64(credential.response.clientDataJSON),
        authenticatorData: bytesToBase64(credential.response.authenticatorData),
        signature: bytesToBase64(credential.response.signature),
      });

      result.className = 'ok';
      result.textContent = '認証しました。Tomos Postを開きます。';
      window.location.assign(postUrl);
    } catch (error) {
      result.className = 'ng';
      result.textContent = error && error.message ? error.message : 'パスキー認証に失敗しました。';
      button.disabled = false;
    }
  });
})();
</script>
</body>
</html>
