<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/ui.php';

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
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
                $remember->refreshSessionCookiePath();
            }
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
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
                $remember->refreshSessionCookiePath();
            }
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
?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>パスキーで合言葉を再設定 - Tomos Post</title>
<style>
body{font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;max-width:720px;margin:40px auto;padding:0 20px;line-height:1.7;color:#222}input,button{font:inherit}input[type=password]{box-sizing:border-box;width:100%;max-width:36rem;padding:.65rem;margin:.3rem 0 1rem}button,.button{display:inline-block;padding:.7rem 1rem;border:1px solid #777;border-radius:.35rem;background:#fff;color:inherit;text-decoration:none;cursor:pointer}.result{margin:1rem 0;padding:1rem;background:#f5f5f5}.ok{color:#166534}.ng{color:#991b1b}.hint{color:#666}.passphrase-actions{display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem}.passphrase-actions button{font-size:.9em}
</style>
<link rel="stylesheet" href="../../assets/tomos-post-security.css">
<link rel="stylesheet" href="../../assets/tomos-post-ui.css">
</head>
<body class="post-standalone">
<main class="wrap">
<h1>パスキーで合言葉を再設定</h1>
<p>登録済みパスキーで本人確認した後、新しい管理用合言葉を設定します。再設定後は、記憶済みのブラウザ認証をすべて解除します。</p>

<?php if (empty($status['available'])): ?>
<div class="result ng"><p>この環境ではパスキーによる再設定を利用できません。</p></div>
<?php elseif ($credentials === []): ?>
<div class="result ng"><p>このTomosに登録済みのパスキーがありません。</p></div>
<?php else: ?>
<button id="verify-button" type="button">パスキーで本人確認</button>
<form id="reset-form" hidden>
<p class="hint">安全な合言葉を自動入力します。必要なら変更できます（1文字以上・4096バイト以内）。</p>
<label for="new_passphrase">新しい管理用合言葉</label>
<input id="new_passphrase" type="password" autocomplete="new-password" required>
<div class="passphrase-actions">
<button id="generate-button" type="button">別の合言葉を生成</button>
<button id="show-button" type="button" aria-pressed="false">合言葉を表示</button>
<button id="copy-button" type="button">合言葉をコピー</button>
</div>
<label for="confirmation">新しい管理用合言葉（確認）</label>
<input id="confirmation" type="password" autocomplete="new-password" required>
<p class="hint"><strong>再設定前に合言葉をコピーして、パスワード管理アプリなどへ保存してください。</strong>再設定後は、この画面から確認できません。</p>
<button type="submit">管理用合言葉を再設定</button>
</form>
<?php endif; ?>

<p id="result" role="status" aria-live="polite"></p>

<script>
(() => {
  const verifyButton = document.getElementById('verify-button');
  const resetForm = document.getElementById('reset-form');
  const newPassphraseInput = document.getElementById('new_passphrase');
  const confirmationInput = document.getElementById('confirmation');
  const generateButton = document.getElementById('generate-button');
  const showButton = document.getElementById('show-button');
  const copyButton = document.getElementById('copy-button');
  const result = document.getElementById('result');
  if (!verifyButton || !resetForm || !result || !window.PublicKeyCredential || !navigator.credentials) return;

  // Web Crypto only: no Math.random fallback for administrator credentials.
  function generatePassphrase() {
    if (!window.crypto || typeof window.crypto.getRandomValues !== 'function') return null;
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    const cutoff = Math.floor(256 / alphabet.length) * alphabet.length;
    const chars = [];
    while (chars.length < 24) {
      const bytes = new Uint8Array(32);
      window.crypto.getRandomValues(bytes);
      for (const byte of bytes) {
        if (byte >= cutoff) continue;
        chars.push(alphabet[byte % alphabet.length]);
        if (chars.length === 24) break;
      }
    }
    return 'tms-' + (chars.join('').match(/.{4}/g) || []).join('-');
  }
  function fillGeneratedPassphrase() {
    const value = generatePassphrase();
    if (!value || !newPassphraseInput || !confirmationInput) return false;
    newPassphraseInput.value = value;
    confirmationInput.value = value;
    return true;
  }
  if (generateButton) generateButton.addEventListener('click', () => {
    if (fillGeneratedPassphrase()) {
      result.className = '';
      result.textContent = '合言葉を生成しました。保存してから再設定してください。';
    } else {
      result.className = 'ng';
      result.textContent = '自動生成できません。合言葉を手入力してください。';
    }
  });
  if (showButton) showButton.addEventListener('click', () => {
    const visible = newPassphraseInput.type === 'password';
    newPassphraseInput.type = visible ? 'text' : 'password';
    showButton.textContent = visible ? '合言葉を隠す' : '合言葉を表示';
    showButton.setAttribute('aria-pressed', visible ? 'true' : 'false');
  });
  if (copyButton) copyButton.addEventListener('click', async () => {
    if (!newPassphraseInput.value) {
      result.className = 'ng';
      result.textContent = 'コピーする合言葉がありません。';
      return;
    }
    try {
      if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('Clipboard unavailable');
      await navigator.clipboard.writeText(newPassphraseInput.value);
      result.className = 'ok';
      result.textContent = '合言葉をコピーしました。パスワード管理アプリなどへ保存してください。';
    } catch (_) {
      result.className = 'ng';
      result.textContent = 'コピーできませんでした。「合言葉を表示」で内容を確認し、手動で保存してください。';
    }
  });
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
      resetForm.hidden=false;
      if (fillGeneratedPassphrase()) {
        result.className='ok'; result.textContent='本人確認しました。生成した合言葉を保存し、5分以内に再設定してください。';
      } else {
        result.className='ok'; result.textContent='本人確認しました。新しい合言葉を入力し、5分以内に再設定してください。';
      }
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
      result.className='ok'; result.textContent='管理用合言葉を再設定しました。Tomos Postを開きます。';
      resetForm.hidden=true; verifyButton.hidden=true;
      window.setTimeout(()=>window.location.assign(postUrl),1200);
    } catch(error) {
      result.className='ng'; result.textContent=error && error.message ? error.message : '管理用合言葉を再設定できませんでした。';
    }
  });
})();
</script>
<?= tomosPostReturnLink((string) $postUrl, 'Tomos Postを開く') ?>
</main>
</body>
</html>
