<?php

declare(strict_types=1);

final class InstallerApplication
{
    public const VERSION = 'phase4.0.0';
    public const FALLBACK_URL = 'https://tomoswords.org/start/install/';

    public function __construct(string $rootDir, array $config = [], ?InstallerCore $core = null, ?InstallerPlacement $placement = null, ?InstallerLifecycle $lifecycle = null)
    {
        $this->root = rtrim($rootDir, DIRECTORY_SEPARATOR);
        $this->config = $config;
        $this->core = $core ?? new InstallerCore($this->root, $config);
        $this->placement = $placement ?? new InstallerPlacement($this->root, self::VERSION);
        $this->lifecycle = $lifecycle ?? new InstallerLifecycle($this->root, self::VERSION);
    }

    private $root;
    private $config;
    private $core;
    private $placement;
    private $lifecycle;

    public function run(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $bootstrap = null;
        $recovery = [];
        $diagnostics = ['errors' => [], 'warnings' => []];
        $message = null;
        $result = null;
        try {
            $this->core->bootstrap();
            $recovery = $this->placement->recover();
            foreach ($recovery as $recovered) {
                if (($recovered['status'] ?? '') === 'forward_recovered') {
                    $installed = $this->placement->installedData();
                    $this->lifecycle->disable([
                        'version' => $installed['tomos_version'] ?? '',
                        'transaction_id' => $installed['transaction_id'] ?? '',
                    ]);
                }
            }
            if ($method === 'GET') {
                $diagnostics = $this->core->diagnostics();
            } elseif ($method === 'POST') {
                $result = $this->installPost();
            } else {
                http_response_code(405);
                $message = ['kind' => 'error', 'text' => 'この操作は利用できません。'];
            }
        } catch (Throwable $exception) {
            $message = $this->messageFor($exception);
            if ($message['kind'] === 'recovery') http_response_code(409);
        }
        if ($result !== null) {
            $this->renderComplete($result);
            return;
        }
        if ($this->lifecycle->isDisabled()) {
            http_response_code(410);
            $this->renderDisabled();
            return;
        }
        $this->render($diagnostics, $recovery, $message);
    }

    public function installPost(?array $post = null): array
    {
        $post = $post ?? $_POST;
        $token = is_string($post['csrf'] ?? null) ? $post['csrf'] : '';
        $mode = (string) ($post['mode'] ?? InstallerPlacement::MODE_CURRENT);
        $child = $mode === InstallerPlacement::MODE_CHILD ? (string) ($post['child_directory'] ?? '') : null;
        if ($mode !== InstallerPlacement::MODE_CURRENT && $mode !== InstallerPlacement::MODE_CHILD) {
            throw new InstallManifestException('target_exists', 'Placement mode is invalid.');
        }
        if ($mode === InstallerPlacement::MODE_CHILD && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/D', (string) $child) !== 1) {
            throw new InstallManifestException('target_exists', 'Child directory name is invalid.');
        }
        $prepared = $this->core->prepare($token);
        $verified = InstallerVerifiedResult::fromArray($prepared);
        $verified->selectedMode = $mode;
        $verified->childName = $child;
        $installed = $this->placement->place($verified, $mode, $child);
        $this->lifecycle->disable($installed);
        $selfDeleted = $this->lifecycle->trySelfDelete((string) ($this->config['installer_path'] ?? ''), (bool) ($this->config['allow_self_delete'] ?? false));
        $installed['self_deleted'] = $selfDeleted;
        $installed['start_url'] = $this->lifecycle->completionTarget($mode, $child);
        return $installed;
    }

    public function renderDiagnostics(): array
    {
        return $this->core->diagnostics();
    }

    private function render(array $diagnostics, array $recovery, ?array $message): void
    {
        $token = '';
        try { $token = htmlspecialchars($this->core->csrfToken(), ENT_QUOTES, 'UTF-8'); } catch (Throwable $ignored) { }
        $hasErrors = $diagnostics['errors'] !== [];
        $recoveryUnsafe = false;
        $recovered = false;
        foreach ($recovery as $item) {
            if (($item['status'] ?? '') === 'recovery_required') $recoveryUnsafe = true;
            if (($item['status'] ?? '') === 'cleaned') $recovered = true;
            if (($item['status'] ?? '') === 'forward_recovered') $message = ['kind' => 'success', 'text' => '前回のインストール処理を確認しました。Tomosのインストールは完了しています。'];
        }
        if ($recoveryUnsafe) $message = ['kind' => 'recovery', 'text' => '前回のインストール状態を自動で復旧できませんでした。安全のため、自動処理を停止しています。'];
        if ($recovered && $message === null) $message = ['kind' => 'notice', 'text' => '前回のインストールは完了しませんでした。安全に初期化しました。もう一度インストールできます。'];
        $fallback = htmlspecialchars((string) ($this->config['fallback_url'] ?? self::FALLBACK_URL), ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tomos かんたんインストール</title><style>' . self::css() . '</style></head><body><main class="card"><h1>Tomos かんたんインストール</h1>';
        if ($message !== null) echo '<div class="message ' . htmlspecialchars((string) $message['kind'], ENT_QUOTES, 'UTF-8') . '" role="alert">' . htmlspecialchars((string) $message['text'], ENT_QUOTES, 'UTF-8') . '</div>';
        $blocked = $hasErrors || ($message !== null && in_array((string) ($message['kind'] ?? ''), ['error', 'recovery'], true));
        if ($recoveryUnsafe) {
            echo '<h2>自動復旧を停止しました</h2><p>前回のインストール状態を自動で復旧できませんでした。</p><p>安全のため、この場所へ新しいファイルをアップロードしないでください。</p><p class="diagnostic">診断コード: ' . $this->diagnosticCode('recovery_unsafe') . '</p>';
        } elseif ($blocked) {
            echo '<h2>かんたんインストールを利用できません</h2><p>このサーバーでは、かんたんインストールを利用できません。</p><p>Tomosは通常のファイルアップロードで設置できます。</p><p><a class="button secondary" href="' . $fallback . '">設置方法を見る</a></p>';
        } else {
            echo '<p>Tomosをこのサーバーに設置します。</p><form method="post" id="installer-form"><input type="hidden" name="csrf" value="' . $token . '"><fieldset><legend>設置場所</legend><label><input type="radio" name="mode" value="current" checked> この場所に設置</label><label><input type="radio" name="mode" value="child"> 新しいフォルダに設置</label><div id="child-wrap" hidden><label for="child_directory">フォルダ名</label><input id="child_directory" name="child_directory" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" autocomplete="off"><small>半角英数字、ハイフン、アンダーバーが使えます。</small></div></fieldset><button type="submit" id="submit">Tomosをインストール</button></form><p class="status" id="status" hidden>Tomosを準備しています。この画面を閉じずにお待ちください。</p>';
        }
        echo '<p class="diagnostic">診断コード: ' . $this->diagnosticCode($hasErrors ? 'environment' : 'ready') . '</p></main><script>document.querySelectorAll("input[name=mode]").forEach(function (e) { e.addEventListener("change", function () { document.getElementById("child-wrap").hidden = this.value !== "child"; }); }); document.getElementById("installer-form")?.addEventListener("submit", function () { document.getElementById("submit").disabled = true; document.getElementById("status").hidden = false; });</script></body></html>';
    }

    private function renderComplete(array $result): void
    {
        $url = htmlspecialchars((string) $result['start_url'], ENT_QUOTES, 'UTF-8');
        $delete = empty($result['self_deleted']) ? '<p class="notice">安全のため、可能であれば install.php を削除してください。</p>' : '';
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tomosのインストール完了</title><style>' . self::css() . '</style></head><body><main class="card"><h1>Tomosのインストールが完了しました。</h1><p>Tomosを利用できます。</p><p><a class="button" href="' . $url . '">Tomosをはじめる</a></p>' . $delete . '</main></body></html>';
    }

    private function renderDisabled(): void
    {
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>使用済みインストーラー</title><style>' . self::css() . '</style></head><body><main class="card"><h1>このインストーラーはすでに使用済みです。</h1><p>Tomosはインストール済みです。</p><p>install.php は削除して構いません。</p></main></body></html>';
    }

    private function messageFor(Throwable $exception): array
    {
        $code = $exception instanceof InstallManifestException ? $exception->errorCode() : 'internal';
        $kind = in_array($code, ['recovery_unsafe', 'rollback_failed'], true) ? 'recovery' : 'error';
        if (in_array($code, ['environment', 'staging_create', 'lock', 'session', 'csrf', 'bootstrap_owner', 'target_exists', 'target_collision', 'target_symlink', 'rename_failed', 'rename_state'], true)) $text = 'このサーバーでは、かんたんインストールを利用できません。';
        elseif (in_array($code, ['manifest_signature', 'manifest_schema', 'manifest_version', 'asset_hash', 'asset_size', 'zip_open', 'zip_path', 'zip_duplicate', 'zip_symlink', 'zip_limits', 'zip_contents', 'file_hash', 'file_size', 'required_file', 'placement_verify'], true)) $text = 'Tomosの配布データを安全に確認できませんでした。';
        elseif (in_array($code, ['pointer_download', 'pointer_schema', 'manifest_download', 'signature_download', 'asset_download'], true)) $text = 'Tomosを取得できませんでした。';
        elseif (in_array($code, ['installed_marker', 'disable_marker', 'rollback_failed', 'recovery_unsafe'], true)) $text = 'インストール状態を自動で復旧できませんでした。';
        else $text = 'インストールを完了できませんでした。';
        return ['kind' => $kind, 'text' => $text, 'code' => $code];
    }

    private function diagnosticCode(string $code): string
    {
        return 'TOMOS-INSTALL-' . strtoupper(substr(hash('sha256', $code . '|' . gmdate('Y-m-d-H')), 0, 10));
    }

    private static function css(): string
    {
        return 'body{margin:0;background:#f5f3ef;color:#262522;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6}.card{box-sizing:border-box;width:min(100% - 32px,640px);margin:8vh auto;padding:32px;background:#fff;border:1px solid #ddd8cf;border-radius:14px;box-shadow:0 8px 30px #0000000d}h1{font-size:1.6rem;line-height:1.3;margin-top:0}h2{font-size:1.2rem}fieldset{border:0;padding:0;margin:24px 0}legend{font-weight:700;margin-bottom:10px}label{display:block;margin:10px 0}input[type=text],input:not([type]){box-sizing:border-box;max-width:100%;padding:9px;border:1px solid #aaa;border-radius:6px}#child_directory{display:block;margin-top:6px;width:100%}small{display:block;color:#666}.button,button{display:inline-block;border:0;border-radius:7px;padding:10px 18px;background:#28634d;color:#fff;text-decoration:none;font:inherit;cursor:pointer}.button.secondary{background:#555}.button:focus,button:focus,input:focus{outline:3px solid #9dd2bd;outline-offset:2px}button:disabled{opacity:.6;cursor:wait}.message{padding:12px;border-radius:7px;margin:14px 0}.message.notice{background:#fff7db}.message.error,.message.recovery{background:#fde8e8}.message.success{background:#e3f4e9}.notice{padding:12px;background:#fff7db;border-radius:7px}.diagnostic{color:#666;font-size:.78rem;word-break:break-all}.status{margin-top:18px;color:#28634d}@media(max-width:480px){.card{margin:3vh auto;padding:22px}}';
    }
}
