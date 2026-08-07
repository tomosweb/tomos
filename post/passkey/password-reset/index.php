<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) return;
    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__, 3) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) require_once $file;
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
    if (is_file($vendor)) require_once $vendor;
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
        jsonResponse(['ok'=>false,'message'=>'画面の有効期限が切れました。再読み込みしてください。'], 403);
    }
}

$environment = new Tomos\PasskeyEnvironment($config);
$store = new Tomos\PasskeyCredentialStore($config, $rootDir);
$challenges = new Tomos\PasskeyChallengeStore();
$client = new Tomos\LbuchsPasskeyWebAuthnClient();
$updater = new Tomos\PostPasswordHashUpdater($rootDir);
$reset = new Tomos\PasskeyPasswordResetService($environment, $store, $challenges, $client, $updater);
$remember = new Tomos\PostAuthRememberToken($config, $rootDir);

$api = (string) ($_GET['api'] ?? '');
if ($api !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonResponse(['ok'=>false,'message'=>'POST request required.'], 405);
    $payload = requestPayload();
    requireCsrf($payload);

    try {
        if ($api === 'options') {
            $options = $reset->begin($_SESSION);
            jsonResponse(['ok'=>true,'publicKey'=>$options['public_key']]);
        }
        if ($api === 'verify') {
            $reset->completeReauthentication($_SESSION, $payload, 300);
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            jsonResponse(['ok'=>true,'authorized_for_seconds'=>300]);
        }
        if ($api === 'reset') {
            $reset->resetPassphrase(
                $_SESSION,
                (string) ($payload['new_passphrase'] ?? ''),
                (string) ($payload['confirmation'] ?? '')
            );
            $remember->invalidateAll();
            unset($_SESSION['tomos_passkey_challenges']);
            $_SESSION['tomos_post_token'] = bin2hex(random_bytes(32));
            if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
            jsonResponse(['ok'=>true,'message'=>'管理用合言葉を再設定しました。']);
        }
        jsonResponse(['ok'=>false,'message'=>'処理を確認できませんでした。'], 404);
    } catch (Throwable $exception) {
        jsonResponse(['ok'=>false,'message'=>$exception->getMessage()], 400);
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
$securityUrl = '/' . trim($basePath, '/') . '/post/security/';
$securityUrl = preg_replace('#/+#', '/', $securityUrl);
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>パスキーで合言葉を再設定 - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:720px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}input,button{font:inherit}input[type=password]{box-sizing:border-box;width:100%;max-width:36rem;padding:.65rem;margin:.3rem 0 1rem}button,.button{display:inline-block;padding:.7rem 1rem;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;cursor:pointer}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.ok{color:#166534}.ng{color:#991b1b}.hint{color:#666}
</style>
<link rel="stylesheet" href="../../assets/tomos-post-security.css">
</head>
<body>
<h1>Tomos Post</h1>
<h2>パスキーで合言葉を再設定</h2>
<p>登録済みパスキーで本人確認した後、新しい管理用合言葉を設定します。再設定後は、記憶済みのブラウザ認証をすべて解除します。</p>

<?php if (empty($status['available'])): ?>
<div class="result ng"><p>この環境ではパスキーによる再設定を利用できません。</p></div>
<?php elseif ($credentials === []): ?>
<div class="result ng"><p>このTomosに登録済みのパスキーがありません。</p></div>
<?php else: ?>
<button id="verify-button" type="button">パスキーで本人確認</button>
<form id="reset-form" hidden>
<label for="new_passphrase">新しい管理用合言葉</label>
<input id="new_passphrase" type="password" autocomplete="new-password" required>
<label for="confirmation">新しい管理用合言葉（確認）</label>
<input id="confirmation" type="password" autocomplete="new-password" required>
<button type="submit">管理用合言葉を再設定</button>
</form>
<?php endif; ?>

<p id="result" role="status" aria-live="polite"></p>
<p><a class="button" href="<?= htmlspecialchars((string) $securityUrl, ENT_QUOTES, 'UTF-8') ?>">セキュリティへ戻る</a> <a class="button" href="<?= htmlspecialchars((string) $postUrl, ENT_QUOTES, 'UTF-8') ?>">Tomos Postへ戻る</a></p>

<script>
(() => {
  const verifyButton = document.getElementById('verify-button');
  const resetForm = document.getElementById('reset-form');
  const result = document.getElementById('result');
  if (!verifyButton || !resetForm || !result || !window.PublicKeyCredential || !navigator.credentials) return;
  let token = <?= json_encode($token, JSON_UNESCAPED_SLASHES) ?>;
  const postUrl = <?= json_encode($postUrl, JSON_UNESCAPED_SLASHES) ?>;
  const b64uToBytes = value => {
    const base64 = value.replace(/-/g,'+').replace(/_/g,'/') + '='.repeat((4-value.length%4)%4);
    const raw = atob(base64); return Uint8Array.from(raw, c=>c.charCodeAt(0));
  };
  const bytesToB64 = value => {
    const bytes = new Uint8Array(value); let binary=''; bytes.forEach(b=>binary+=String.fromCharCode(b)); return btoa(binary);
  };
  const bytesToB64u = value => bytesToB64(value).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/g,'');
  const normalize = publicKey => {
    publicKey.challenge = b64uToBytes(publicKey.challenge);
    if (Array.isArray(publicKey.allowCredentials)) publicKey.allowCredentials = publicKey.allowCredentials.map(item=>({...item,id:b64uToBytes(item.id)}));
    return publicKey;
  };
  async function api(name,payload) {
    const response = await fetch('?api='+encodeURIComponent(name), {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({_token:token,...payload})});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || '処理に失敗しました。');
    return data;
  }
  verifyButton.addEventListener('click', async () => {
    try {
      verifyButton.disabled = true; result.className=''; result.textContent='パスキー認証を準備しています…';
      const options = await api('options',{});
      const credential = await navigator.credentials.get({publicKey:normalize(options.publicKey)});
      if (!credential) throw new Error('パスキーを確認できませんでした。');
      await api('verify', {
        credential_id: bytesToB64u(credential.rawId),
        clientDataJSON: bytesToB64(credential.response.clientDataJSON),
        authenticatorData: bytesToB64(credential.response.authenticatorData),
        signature: bytesToB64(credential.response.signature)
      });
      result.className='ok'; result.textContent='本人確認しました。5分以内に新しい管理用合言葉を設定してください。';
      resetForm.hidden=false;
    } catch(error) {
      result.className='ng'; result.textContent=error && error.message ? error.message : 'パスキー認証に失敗しました。';
      verifyButton.disabled=false;
    }
  });
  resetForm.addEventListener('submit', async event => {
    event.preventDefault();
    try {
      const newPassphrase = document.getElementById('new_passphrase').value;
      const confirmation = document.getElementById('confirmation').value;
      result.className=''; result.textContent='管理用合言葉を更新しています…';
      await api('reset',{new_passphrase:newPassphrase,confirmation});
      result.className='ok'; result.textContent='管理用合言葉を再設定しました。新しい合言葉で認証してください。';
      resetForm.hidden=true; verifyButton.hidden=true;
      window.setTimeout(()=>window.location.assign(postUrl),1200);
    } catch(error) {
      result.className='ng'; result.textContent=error && error.message ? error.message : '管理用合言葉を再設定できませんでした。';
    }
  });
})();
</script>
</body>
</html>
