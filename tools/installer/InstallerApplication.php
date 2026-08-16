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
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tomos かんたんインストール</title><style>' . self::css() . '</style></head><body><main class="wrap"><h1>Tomos かんたんインストール</h1>';
        if ($message !== null) echo '<div class="message ' . htmlspecialchars((string) $message['kind'], ENT_QUOTES, 'UTF-8') . '" role="alert">' . htmlspecialchars((string) $message['text'], ENT_QUOTES, 'UTF-8') . '</div>';
        $blocked = $hasErrors || ($message !== null && in_array((string) ($message['kind'] ?? ''), ['error', 'recovery'], true));
        if ($recoveryUnsafe) {
            echo '<h2>自動復旧を停止しました</h2><p>前回のインストール状態を自動で復旧できませんでした。</p><p>安全のため、この場所へ新しいファイルをアップロードしないでください。</p><p class="diagnostic">確認番号: ' . $this->diagnosticCode('recovery_unsafe') . '</p>';
        } elseif ($blocked) {
            echo '<h2>かんたんインストールを利用できません</h2><p>このサーバーでは、かんたんインストールを利用できません。</p><p>Tomosは通常のファイルアップロードで設置できます。</p><p><a class="button secondary" href="' . $fallback . '">設置方法を見る</a></p>';
        } else {
            echo '<p>Tomosをこのサーバーに設置します。</p><form method="post" id="installer-form"><input type="hidden" name="csrf" value="' . $token . '"><fieldset><legend>設置場所</legend><label><input type="radio" name="mode" value="current" checked> この場所に設置</label><label><input type="radio" name="mode" value="child"> 新しいフォルダに設置</label><div id="child-wrap" hidden><label for="child_directory">フォルダ名</label><input id="child_directory" name="child_directory" maxlength="64" pattern="[A-Za-z0-9][A-Za-z0-9_-]{0,63}" autocomplete="off"><small class="hint">半角英数字、ハイフン、アンダーバーが使えます。</small></div></fieldset><div class="actions"><button type="submit" id="submit">Tomosをインストール</button></div></form><p class="status" id="status" hidden>Tomosを準備しています。この画面を閉じずにお待ちください。</p>';
        }
        $diagnostic = $recoveryUnsafe ? 'recovery_unsafe' : ($message['code'] ?? ($hasErrors ? 'environment' : 'ready'));
        echo '<p class="diagnostic">確認番号: ' . $this->diagnosticCode((string) $diagnostic) . '</p></main><script>document.querySelectorAll("input[name=mode]").forEach(function (e) { e.addEventListener("change", function () { document.getElementById("child-wrap").hidden = this.value !== "child"; }); }); document.getElementById("installer-form")?.addEventListener("submit", function () { document.getElementById("submit").disabled = true; document.getElementById("status").hidden = false; });</script></body></html>';
    }

    private function renderComplete(array $result): void
    {
        $url = htmlspecialchars((string) $result['start_url'], ENT_QUOTES, 'UTF-8');
        $delete = empty($result['self_deleted']) ? '<p class="notice">安全のため、可能であれば install.php を削除してください。</p>' : '';
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Tomosのインストール完了</title><style>' . self::css() . '</style></head><body><main class="wrap"><h1>Tomosのインストールが完了しました。</h1><p>Tomosを利用できます。</p><p><a class="button" href="' . $url . '">Tomosをはじめる</a></p>' . $delete . '</main></body></html>';
    }

    private function renderDisabled(): void
    {
        echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>使用済みインストーラー</title><style>' . self::css() . '</style></head><body><main class="wrap"><h1>このインストーラーはすでに使用済みです。</h1><p>Tomosはインストール済みです。</p><p>install.php は削除して構いません。</p></main></body></html>';
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
        return 'body{box-sizing:border-box;margin:0;padding:32px 16px;background:#f6f4ef;color:#2f2f2f;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6}.wrap{box-sizing:border-box;width:100%;max-width:800px;margin:0 auto;padding:28px;background:#fcfbf8;border:1px solid #d9d6cf;border-radius:10px;box-shadow:0 1px 2px rgba(47,47,47,0.04)}h1{margin-top:0;font-size:1.8rem;line-height:1.3}h2{font-size:1.2rem;line-height:1.4}fieldset{border:0;padding:0;margin:24px 0}legend{font-weight:700;margin-bottom:10px}label{display:block;margin:10px 0}input[type=radio]{accent-color:#9a431c}input[type=text],input:not([type]){box-sizing:border-box;width:100%;max-width:100%;padding:.65rem;background:#fff;color:#2f2f2f;border:1px solid #d9d6cf;border-radius:6px;font:inherit;font-size:16px}#child_directory{display:block;margin-top:6px}.hint,small{display:block;color:#6b6b6b}.actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:1.5rem}.button,button{display:inline-block;min-height:44px;box-sizing:border-box;border:1px solid #9a431c;border-radius:6px;padding:.7rem 1rem;background:#9a431c;color:#fff;text-decoration:none;font:inherit;cursor:pointer}.button:hover,button:hover{background:#853919;border-color:#853919}.button.secondary{background:#fcfbf8;border-color:#d9d6cf;color:#2f2f2f}.button.secondary:hover{background:#f7f5f0;border-color:#cfcbc3}.button:focus-visible,button:focus-visible,input:focus-visible{outline:3px solid rgba(164,74,29,.28);outline-offset:2px}button:disabled{background:#c7b6ad;border-color:#c7b6ad;opacity:.8;cursor:wait}.message{padding:1rem;border-radius:6px;margin:14px 0;border:1px solid transparent}.message.notice{background:#fbf4e8;border-color:#e5c998;color:#6f4b1d}.message.error,.message.recovery{background:#f8ecea;border-color:#d9a39e;color:#8a2e26}.message.success{background:#fbf4e8;border-color:#e5c998;color:#6f4b1d}.notice{padding:1rem;background:#fbf4e8;border:1px solid #e5c998;border-radius:6px;color:#6f4b1d}.diagnostic{margin-top:2rem;color:#6b6b6b;font-size:.78rem;overflow-wrap:anywhere}.status{margin-top:18px;color:#6b6b6b}@media(max-width:560px){body{padding:16px 10px}.wrap{padding:20px 16px}.actions .button,.actions button{width:100%}label{min-height:44px}.button,button{width:100%}}';
    }
}
