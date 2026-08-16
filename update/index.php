<?php

declare(strict_types=1);

session_start();

spl_autoload_register(function (string $class): void {
    $prefix = 'Tomos\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = dirname(__DIR__) . '/core/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

$rootDir = dirname(__DIR__);
$configPath = $rootDir . '/config.php';
$config = is_file($configPath) ? require $configPath : [];
$config = is_array($config) ? $config : [];
$publicBasePath = (string) (($config['site']['public_base_path'] ?? '') ?: ($config['site']['base_path'] ?? ''));
$postUrl = Tomos\Security::publicUrl('/post/', $publicBasePath) . '?section=settings';
$updateUrl = Tomos\Security::publicUrl('/update/', $publicBasePath);
$authRemember = new Tomos\PostAuthRememberToken($config, $rootDir);
$authRemember->restoreSession();

if (empty($_SESSION['tomos_update_token'])) {
    $_SESSION['tomos_update_token'] = bin2hex(random_bytes(32));
}
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['new'])) {
    unset($_SESSION['tomos_update_package']);
}

$errors = [];
$onlineError = null;
$releaseInfo = null;
$result = null;
$summary = null;
$service = new Tomos\UpdateService($rootDir);
$service->cleanupStaleTemporaryFiles();

if ($config === []) {
    $errors[] = 'config.php が見つかりません。先にsetupを完了してください。';
} elseif (empty($config['features']['post'])) {
    $errors[] = '管理機能が無効なためTomos Updateを利用できません。';
} elseif ((string) ($config['security']['post_password_hash'] ?? '') === '') {
    $errors[] = '管理用合言葉が設定されていません。Tomos Postの設定を確認してください。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors === []) {
    $action = (string) ($_POST['action'] ?? '');
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals((string) $_SESSION['tomos_update_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。画面を再読み込みしてください。';
    } elseif ($action === 'inspect') {
        $authError = authenticateUpdatePost($config, $rootDir, $authRemember);
        if ($authError !== null) {
            $errors[] = $authError;
        } else {
            try {
                $summary = $service->stageUpload($_FILES['update_zip'] ?? [], session_id());
                $_SESSION['tomos_update_package'] = (string) $summary['id'];
                $_SESSION['tomos_update_token'] = bin2hex(random_bytes(32));
            } catch (Tomos\UpdateException $exception) {
                error_log('Tomos Update inspect [' . $exception->stage() . ']: ' . $exception->getMessage());
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Tomos Update inspect exception: ' . get_class($exception) . ': ' . $exception->getMessage());
                $errors[] = '更新ZIPを確認できませんでした。正規のTomos更新ZIPを選択してください。';
            }
        }
    } elseif ($action === 'inspect_online') {
        $authError = authenticateUpdatePost($config, $rootDir, $authRemember);
        if ($authError !== null) {
            $onlineError = $authError;
        } else {
            try {
                $currentVersion = $service->currentVersion();
                $releaseInfo = (new Tomos\UpdateReleaseProvider())->getNextUpdate($currentVersion);
                if (!$releaseInfo['update_available']) {
                    $onlineError = '現在利用できるオンライン更新はありません。';
                } else {
                    $downloadDir = $rootDir . '/storage/update-tmp';
                    if (!is_dir($downloadDir) && !@mkdir($downloadDir, 0700, true)) {
                        throw new Tomos\UpdatePackageDownloaderException('オンライン更新用の一時領域を作成できません。', 'destination');
                    }
                    $downloadPath = $downloadDir . '/online-download-' . bin2hex(random_bytes(16)) . '.zip';
                    try {
                        (new Tomos\UpdatePackageDownloader())->download(
                            (string) $releaseInfo['package_url'],
                            (string) $releaseInfo['sha256'],
                            $downloadPath
                        );
                        $summary = $service->stageDownloadedPackage(
                            $downloadPath,
                            session_id(),
                            (string) $releaseInfo['current_version'],
                            (string) $releaseInfo['next_version']
                        );
                        $_SESSION['tomos_update_package'] = (string) $summary['id'];
                        $_SESSION['tomos_update_token'] = bin2hex(random_bytes(32));
                    } finally {
                        if (isset($downloadPath)) {
                            @unlink($downloadPath);
                        }
                    }
                }
            } catch (Tomos\UpdateReleaseProviderException $exception) {
                error_log('Tomos online update catalog [' . $exception->errorCode() . ']: ' . $exception->getMessage());
                $onlineError = 'オンライン更新情報を取得できませんでした。手動の更新ZIPは引き続き利用できます。';
            } catch (Tomos\UpdatePackageDownloaderException $exception) {
                error_log('Tomos online update download [' . $exception->errorCode() . ']: ' . $exception->getMessage());
                $onlineError = 'オンライン更新ZIPを取得できませんでした。手動の更新ZIPは引き続き利用できます。';
            } catch (Tomos\UpdateException $exception) {
                error_log('Tomos online update staging [' . $exception->stage() . ']: ' . $exception->getMessage());
                $onlineError = 'オンライン更新ZIPを確認できませんでした。手動の更新ZIPは引き続き利用できます。';
            } catch (Throwable $exception) {
                error_log('Tomos online update exception: ' . get_class($exception) . ': ' . $exception->getMessage());
                $onlineError = 'オンライン更新を開始できませんでした。手動の更新ZIPは引き続き利用できます。';
            }
        }
    } elseif ($action === 'apply') {
        $id = (string) ($_SESSION['tomos_update_package'] ?? '');
        if (empty($_SESSION['tomos_post_authenticated']) || $id === '') {
            $errors[] = '更新内容の有効期限が切れました。更新ZIPを選び直してください。';
        } elseif (!empty($_SESSION['tomos_update_applying'])) {
            $errors[] = '更新処理はすでに開始されています。';
        } else {
            $_SESSION['tomos_update_applying'] = true;
            unset($_SESSION['tomos_update_package']);
            $_SESSION['tomos_update_token'] = bin2hex(random_bytes(32));
            session_write_close();
            try {
                $result = (new Tomos\InstalledIntegrityVerifier($rootDir))->verifyAfterUpdate(
                    $service->apply($id, session_id())
                );
            } catch (Tomos\UpdateException $exception) {
                error_log('Tomos Update apply [' . $exception->stage() . ']: ' . $exception->getMessage());
                $errors[] = $exception->getMessage();
            } catch (Throwable $exception) {
                error_log('Tomos Update apply exception: ' . get_class($exception) . ': ' . $exception->getMessage());
                $errors[] = '更新処理を完了できませんでした。管理者へ連絡してください。';
            }
            session_start();
            unset($_SESSION['tomos_update_applying']);
        }
    } else {
        $errors[] = '更新操作を確認できませんでした。';
    }
}

if ($summary === null && !empty($_SESSION['tomos_update_package']) && $errors === []) {
    try {
        $summary = $service->inspectStaged((string) $_SESSION['tomos_update_package'], session_id());
    } catch (Throwable $exception) {
        unset($_SESSION['tomos_update_package']);
    }
}

if ($summary === null && $errors === [] && $onlineError === null) {
    try {
        $releaseInfo = (new Tomos\UpdateReleaseProvider())->getNextUpdate($service->currentVersion());
    } catch (Tomos\UpdateReleaseProviderException $exception) {
        error_log('Tomos online update catalog [' . $exception->errorCode() . ']: ' . $exception->getMessage());
        $onlineError = 'オンライン更新情報を取得できませんでした。手動の更新ZIPは引き続き利用できます。';
    } catch (Throwable $exception) {
        error_log('Tomos online update catalog exception: ' . get_class($exception) . ': ' . $exception->getMessage());
        $onlineError = 'オンライン更新情報を取得できませんでした。手動の更新ZIPは引き続き利用できます。';
    }
}

renderUpdatePage(
    $service->currentVersion(),
    $service->diagnostics(),
    $errors,
    $onlineError,
    $releaseInfo,
    $summary,
    $result,
    (string) $_SESSION['tomos_update_token'],
    $updateUrl,
    $postUrl,
    !empty($_SESSION['tomos_post_authenticated'])
);

function renderUpdatePage(
    string $currentVersion,
    array $diagnostics,
    array $errors,
    ?string $onlineError,
    ?array $releaseInfo,
    ?array $summary,
    ?array $result,
    string $token,
    string $updateUrl,
    string $postUrl,
    bool $authenticated
): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Tomos Update</title><style>
:root{--bg:#f6f4ef;--surface:#fcfbf8;--input:#fff;--text:#2f2f2f;--muted:#6b6b6b;--border:#d9d6cf;--accent:#9a431c;--accent-hover:#853919;--notice:#fbf4e8;--notice-border:#e5c998;--error:#f8ecea;--error-border:#d9a39e}
*{box-sizing:border-box}body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}.wrap{background:var(--surface);border:1px solid var(--border);border-radius:10px;margin:0 auto;max-width:820px;padding:28px}h1{font-size:1.8rem;margin:0 0 .5rem}h2{font-size:1.2rem;margin:2rem 0 1rem}.version,.summary,.notice,.errors,.success{border:1px solid var(--border);border-radius:7px;margin:1rem 0;padding:1rem}.errors{background:var(--error);border-color:var(--error-border)}.notice,.success{background:var(--notice);border-color:var(--notice-border)}label{display:block;font-weight:700;margin:.8rem 0 .3rem}input[type=file],input[type=password]{background:var(--input);border:1px solid var(--border);border-radius:6px;font:inherit;max-width:100%;padding:.65rem;width:100%}button,.button{background:var(--accent);border:1px solid var(--accent);border-radius:6px;color:#fff;display:inline-block;font:inherit;font-weight:700;padding:.7rem 1rem;text-decoration:none}button:hover{background:var(--accent-hover)}.button.secondary{background:var(--input);color:var(--text);border-color:var(--border)}.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.5rem}.muted{color:var(--muted)}ul.files{max-height:18rem;overflow:auto;padding-left:1.4rem}code{overflow-wrap:anywhere}
</style></head><body><main class="wrap">';
    echo '<h1>Tomos Update</h1>';
    echo '<p class="muted">署名済みの更新ZIPから、Tomos本体だけを更新します。</p>';
    echo '<div class="version"><strong>現在のバージョン：</strong> ' . e($currentVersion === '' ? '不明' : $currentVersion) . '</div>';

    if ($errors !== []) {
        echo '<div class="errors"><strong>処理できませんでした。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    }
    if ($result !== null) {
        echo '<div class="success"><h2>Tomosを更新しました。</h2>';
        echo '<p><strong>' . e((string) $result['previous_version']) . ' → ' . e((string) $result['version']) . '</strong></p>';
        echo '<p>更新ファイル：' . (int) $result['file_count'] . '件<br>設定・記事・画像：変更なし</p></div>';
        echo '<div class="actions"><a class="button secondary" href="' . e($postUrl) . '">Tomos Postへ戻る</a></div>';
        echo '</main></body></html>';
        return;
    }
    if ($diagnostics !== []) {
        echo '<div class="notice"><strong>この環境では更新を開始できません。</strong><ul>';
        foreach ($diagnostics as $diagnostic) {
            echo '<li>' . e((string) $diagnostic) . '</li>';
        }
        echo '</ul></div>';
    } elseif ($summary !== null) {
        echo '<div class="summary"><p><strong>更新経路：</strong> ' . e((string) ($summary['from_version'] ?? $summary['current_version'])) . ' → ' . e((string) $summary['version']) . '<br>';
        echo '<strong>現在のバージョン：</strong> ' . e((string) $summary['current_version']) . '<br>';
        echo '<strong>更新後のバージョン：</strong> ' . e((string) $summary['version']) . '</p>';
        echo '<h2>更新対象（' . count($summary['files']) . '件）</h2><ul class="files">';
        foreach ($summary['files'] as $file) {
            echo '<li><code>' . e((string) $file) . '</code></li>';
        }
        echo '</ul>';
        if ($summary['theme_files'] !== []) {
            echo '<div class="notice"><strong>標準テーマのファイルが含まれています。</strong><p>対象テーマを直接カスタマイズしている場合、その変更が置き換わる可能性があります。対象ファイルは更新前にバックアップされます。</p></div>';
        } else {
            echo '<p>テーマ変更なし</p>';
        }
        echo '<p><strong>設定・記事・画像は変更されません。</strong></p></div>';
        echo '<form method="post" action="' . e($updateUrl) . '" onsubmit="this.querySelector(\'button\').disabled=true">';
        echo '<input type="hidden" name="action" value="apply"><input type="hidden" name="_token" value="' . e($token) . '">';
        echo '<div class="actions"><button type="submit">更新する</button><a class="button secondary" href="' . e($updateUrl . '?new=1') . '">選び直す</a></div></form>';
    } else {
        echo '<section class="online"><h2>オンライン更新</h2>';
        if ($onlineError !== null) {
            echo '<div class="notice"><strong>' . e($onlineError) . '</strong></div>';
        } elseif (is_array($releaseInfo) && !empty($releaseInfo['update_available'])) {
            echo '<p>次の更新が利用できます。</p>';
            echo '<p class="version-path"><strong>' . e($currentVersion) . ' → ' . e((string) $releaseInfo['next_version']) . '</strong></p>';
            echo '<form method="post" action="' . e($updateUrl) . '" onsubmit="this.querySelector(\'button\').disabled=true">';
            echo '<input type="hidden" name="action" value="inspect_online"><input type="hidden" name="_token" value="' . e($token) . '">';
            renderUpdateAuthFields($authenticated, 'online_post_password');
            echo '<div class="actions"><button type="submit">更新内容を確認</button></div></form>';
        } else {
            echo '<p>現在利用できるオンライン更新はありません。</p>';
        }
        echo '</section><hr>';
        echo '<section class="manual"><h2>更新ZIPを使用する</h2><p>手元にある署名済みTomos Update ZIPから更新できます。</p>';
        echo '<form method="post" enctype="multipart/form-data" action="' . e($updateUrl) . '">';
        echo '<input type="hidden" name="action" value="inspect"><input type="hidden" name="_token" value="' . e($token) . '">';
        echo '<label for="update_zip">更新ZIPを選択</label><input id="update_zip" type="file" name="update_zip" accept=".zip,application/zip" required>';
        renderUpdateAuthFields($authenticated);
        echo '<div class="actions"><button type="submit">更新内容を確認</button><a class="button secondary" href="' . e($postUrl) . '">Tomos Postへ戻る</a></div></form></section>';
    }
    echo '</main></body></html>';
}

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function authenticateUpdatePost(array $config, string $rootDir, Tomos\PostAuthRememberToken $authRemember): ?string
{
    if (!empty($_SESSION['tomos_post_authenticated'])) {
        return null;
    }
    $rateLimiter = new Tomos\PostRateLimiter($config, $rootDir, clientIp());
    $limit = $rateLimiter->checkAuthAllowed();
    if (!$limit->allowed) {
        return $limit->message;
    }
    if (!Tomos\PostPassword::verify((string) ($_POST['post_password'] ?? ''), (string) $config['security']['post_password_hash'])) {
        $rateLimiter->recordFailure();
        return '管理用合言葉が正しくありません。';
    }
    $rateLimiter->clearFailures();
    $_SESSION['tomos_post_authenticated'] = true;
    if ((string) ($_POST['remember_post_auth'] ?? '') === '1' && !$authRemember->rememberCurrentBrowser()) {
        return '認証には成功しましたが、このブラウザに30日間の認証情報を保存できませんでした。';
    }
    return null;
}

function renderUpdateAuthFields(bool $authenticated, string $fieldId = 'post_password'): void
{
    if ($authenticated) {
        return;
    }
    echo '<label for="' . e($fieldId) . '">管理用合言葉</label><input id="' . e($fieldId) . '" type="password" name="post_password" autocomplete="current-password" required>';
    echo '<label><input type="checkbox" name="remember_post_auth" value="1"> このブラウザで30日間、合言葉の入力を省略する</label>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
