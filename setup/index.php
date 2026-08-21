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
$samplePath = $rootDir . '/config.sample.php';
$configExists = is_file($configPath);
$config = [];

if ($configExists) {
    $loadedConfig = require $configPath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
} elseif (is_file($samplePath)) {
    $loadedConfig = require $samplePath;
    $config = is_array($loadedConfig) ? $loadedConfig : [];
}
$checks = Tomos\SetupGuard::environmentChecks($rootDir);
$errors = [];
$completed = false;
$postPassword = '';
$detectedUrl = null;
$setupUrlError = '';

if (empty($_SESSION['tomos_setup_token'])) {
    $_SESSION['tomos_setup_token'] = bin2hex(random_bytes(32));
}

if (Tomos\SetupGuard::isDisabled($config, $configExists)) {
    renderPage('セットアップは完了しています', $config, $checks, [], true, false);
    exit;
}

try {
    $detectedUrl = Tomos\SetupUrlResolver::resolve($_SERVER);
} catch (Throwable $exception) {
    $setupUrlError = 'このアクセスからTomosの公開URLを自動取得できませんでした。サーバーのURL設定を確認してください。';
    if (!in_array($setupUrlError, $errors, true)) {
        $errors[] = $setupUrlError;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if (!hash_equals((string) $_SESSION['tomos_setup_token'], $token)) {
        $errors[] = 'フォームの有効期限が切れました。もう一度送信してください。';
    } else {
        $input = $_POST;
        try {
            $detectedUrl = Tomos\SetupUrlResolver::resolve($_SERVER);
            $setupUrlError = '';
        } catch (Throwable $exception) {
            $setupUrlError = 'このアクセスからTomosの公開URLを自動取得できませんでした。サーバーのURL設定を確認してください。';
            if (!in_array($setupUrlError, $errors, true)) {
                $errors[] = $setupUrlError;
            }
        }
        if ($detectedUrl !== null) {
            $input['site_url'] = $detectedUrl['site_url'];
            $input['base_path'] = $detectedUrl['base_path'];
            $input['public_base_path'] = '';
        }
        $generatedPostPassword = '';
        if (!empty($input['feature_post'])) {
            $generatedPostPassword = Tomos\PostPassword::generate();
            $input['post_password_hash'] = Tomos\PostPassword::hash($generatedPostPassword);
            $input['rate_limit_salt'] = Tomos\PostRateLimiter::generateSalt();
        }

        [$newConfig, $validationErrors] = Tomos\ConfigWriter::build($input, $config, $rootDir);
        $errors = array_merge($errors, $validationErrors);

        if ($errors === []) {
            if (Tomos\ConfigWriter::write($configPath, $newConfig, $rootDir)) {
                $config = $newConfig;
                $completed = true;
                $postPassword = $generatedPostPassword;
                $_SESSION['tomos_setup_token'] = bin2hex(random_bytes(32));
            } else {
                $errors[] = 'config.php を書き込めませんでした。ファイルまたは設置ディレクトリの書き込み権限を確認してください。';
            }
        }
    }
}

renderPage($completed ? 'セットアップが完了しました' : 'Tomos セットアップ', $config, $checks, $errors, false, $completed, $postPassword, $setupUrlError, $detectedUrl);

/** @param array{site_url:string,base_path:string}|null $detectedUrl */
function renderPage(string $title, array $config, array $checks, array $errors, bool $disabled, bool $completed, string $postPassword = '', string $setupUrlError = '', ?array $detectedUrl = null): void
{
    header('Content-Type: text/html; charset=utf-8');
    $site = $config['site'] ?? [];
    $features = $config['features'] ?? [];
    $feed = $config['feed'] ?? [];
    $theme = $config['theme'] ?? [];
    $analytics = $config['analytics'] ?? [];
    $themeResults = (new Tomos\ThemeRepository(dirname(__DIR__) . '/themes'))->all();
    $validThemeCount = count(array_filter($themeResults, function (array $result): bool {
        return !empty($result['valid']);
    }));
    $token = (string) ($_SESSION['tomos_setup_token'] ?? '');

    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . e($title) . '</title>';
    echo '<style>
body{background:#f6f4ef;color:#2f2f2f;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;line-height:1.6;margin:0;padding:32px 16px}
.wrap{background:#fcfbf8;border:1px solid #d9d6cf;border-radius:10px;margin:0 auto;max-width:800px;padding:28px}
h1{font-size:1.8rem;margin:0 0 0.5rem} h2{border-top:1px solid #e7e3dc;font-size:1.2rem;margin:2rem 0 1rem;padding-top:1.5rem}
label{display:block;font-weight:700;margin:1rem 0 0.35rem} input[type=text],input[type=url],select{border:1px solid #d9d6cf;border-radius:6px;box-sizing:border-box;font:inherit;padding:0.65rem;width:100%}
.checks{border-collapse:collapse;width:100%}.checks td,.checks th{border-bottom:1px solid #e7e3dc;padding:0.55rem;text-align:left}.ok{color:#006f63;font-weight:700}.warn{color:#8a6f00;font-weight:700}.ng{color:#9b2c2c;font-weight:700}
	.errors{background:#fff3f1;border:1px solid #e0aaa3;border-radius:6px;color:#832d24;padding:1rem}.success{background:#fbf4e8;border:1px solid #e5c998;border-radius:6px;color:#6f4b1d;padding:1rem}
.hint{color:#6b6b6b;font-size:0.95rem}.actions{margin-top:1.5rem}button,.button{background:#9a431c;border:1px solid #9a431c;border-radius:6px;color:#fff;display:inline-block;font:inherit;font-weight:700;padding:0.7rem 1rem;text-decoration:none}
button:disabled{background:#c7b6ad;border-color:#c7b6ad;cursor:not-allowed}
.checkbox{align-items:center;display:flex;gap:0.5rem;margin:0.45rem 0}.checkbox input{width:auto}
.theme-list{display:grid;gap:0.75rem;margin-top:0.75rem}.theme-card{border:1px solid #d9d6cf;border-radius:8px;padding:0.9rem}.theme-card.invalid{background:#fbf8f2;border-color:#e4d2a4}.theme-card label{align-items:flex-start;display:flex;gap:0.65rem;margin:0}.theme-title{font-weight:700}.theme-meta,.theme-desc,.theme-note{color:#6b6b6b;font-size:0.92rem;margin:0.25rem 0 0}.theme-problems{color:#7a4f00;font-size:0.92rem;margin:0.55rem 0 0;padding-left:1.25rem}
code{background:#f1f1ee;border-radius:4px;padding:0.1rem 0.25rem}
</style></head><body><main class="wrap">';

    echo '<h1>' . e($title) . '</h1>';

    if ($disabled) {
        echo '<div class="success"><p>セットアップは完了しています。</p><p>安全のため、<code>setup/</code> ディレクトリを削除してください。</p><p><a class="button" href="../">トップページを確認する</a></p></div>';
        echo '</main></body></html>';
        return;
    }

    if ($completed) {
        echo '<div class="success"><p>セットアップが完了しました。</p>';
        if ($postPassword !== '') {
            echo '<p>管理用合言葉を作成しました。</p>';
            echo '<p>この合言葉は、Tomos Writeなどで作成した記事ファイルの投稿、投稿の取り下げ、trash整理、テーマ切り替えに必要です。あとから画面では確認できません。安全な場所に控えてください。</p>';
            echo '<p><strong>管理用合言葉:</strong><br><code>' . e($postPassword) . '</code></p>';
        }
        echo '<p>安全のため、<code>setup/</code> ディレクトリを削除してください。</p><p><a class="button" href="../">トップページを確認する</a></p></div>';
        echo '</main></body></html>';
        return;
    }

    if ($errors !== []) {
        echo '<div class="errors"><strong>入力内容を確認してください。</strong><ul>';
        foreach ($errors as $error) {
            echo '<li>' . e((string) $error) . '</li>';
        }
        echo '</ul></div>';
    }

    echo '<h2>環境チェック</h2>';
    echo '<table class="checks"><tr><th>項目</th><th>状態</th><th>補足</th></tr>';
    foreach ($checks as $check) {
        $status = (string) $check['status'];
        $statusClass = $status === 'OK' ? 'ok' : ($status === '注意' ? 'warn' : 'ng');
        echo '<tr><td>' . e((string) $check['label']) . '</td><td class="' . e($statusClass) . '">' . e($status) . '</td><td>' . e((string) $check['note']) . '</td></tr>';
    }
    echo '</table>';

    echo '<form method="post" action="">';
    echo '<input type="hidden" name="_token" value="' . e($token) . '">';

    echo '<h2>サイト基本設定</h2>';
    input('サイト名', 'site_name', (string) ($site['name'] ?? 'Tomos Site'), 'text');
    input('サイト説明', 'site_description', (string) ($site['description'] ?? ''), 'text');
    echo '<label for="detected_site_url">TomosのURL</label>';
    echo '<input id="detected_site_url" type="url" value="' . e((string) ($detectedUrl['site_url'] ?? '')) . '" readonly aria-describedby="detected_site_url_hint">';
    echo '<p id="detected_site_url_hint" class="hint">このサーバーから自動的に取得しました。URLや設置パスの入力は必要ありません。</p>';

    echo '<label for="language">サイトの言語</label><select id="language" name="language">';
    $siteLanguage = (string) ($site['language'] ?? 'ja');
    $commonLanguages = ['ja' => '日本語 (ja)', 'en' => 'English (en)', 'fr' => 'Français (fr)', 'de' => 'Deutsch (de)', 'zh-Hans' => '简体中文 (zh-Hans)', 'zh-Hant' => '繁體中文 (zh-Hant)', 'ko' => '한국어 (ko)'];
    if (!isset($commonLanguages[$siteLanguage]) && $siteLanguage !== '') {
        $commonLanguages = [$siteLanguage => $siteLanguage] + $commonLanguages;
    }
    foreach ($commonLanguages as $value => $label) {
        echo '<option value="' . e($value) . '"' . ($siteLanguage === $value ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select><p class="hint">ページ側で <code>language</code> を指定しない場合、この言語が使用されます。</p>';
    input('カスタム言語コード（任意）', 'language_custom', '', 'text');
    echo '<p class="hint">一覧にない言語は <code>en-US</code> や <code>zh-Hant</code> のようなBCP 47形式で入力できます。入力した場合は選択欄より優先されます。</p>';
    input('タイムゾーン', 'timezone', (string) ($site['timezone'] ?? 'Asia/Tokyo'), 'text');

    echo '<h2>Google Analytics 4（任意）</h2>';
    input('Google Analytics 4 測定ID', 'ga4_measurement_id', (string) ($analytics['ga4_measurement_id'] ?? ''), 'text');
    echo '<p class="hint">Google Analytics 4を利用する場合は、<code>G-</code>から始まる測定IDを入力してください。利用しない場合は空欄のままで構いません。</p>';

    echo '<h2>テーマ</h2>';
    renderThemeChoices($themeResults, (string) ($theme['name'] ?? 'tomos-minimal'));
    echo '<p class="hint">テーマはサイトの見た目を決めるファイル一式です。通常は標準テーマ <code>tomos-minimal</code> のままで構いません。</p>';
    echo '<p class="hint">テーマは <code>themes/</code> フォルダに追加できます。設置後はTomos Postのテーマ管理からテーマZIPを追加でき、検証に通ったテーマだけを使用できます。</p>';

    echo '<h2>機能</h2>';
    checkbox('検索を有効にする', 'feature_search', !empty($features['search']));
    checkbox('タグを有効にする', 'feature_tags', !empty($features['tags']));
    checkbox('RSSを有効にする', 'feature_rss', !empty($features['rss']));
    input('RSS対象パス（任意）', 'rss_path_prefix', (string) ($feed['path_prefix'] ?? ''), 'text');
    echo '<p class="hint">空欄ではすべての公開ページをRSSに含めます。<code>/news</code> のように指定すると、そのパスより下のページだけを含め、一覧ページ自体は含めません。</p>';
    checkbox('sitemapを有効にする', 'feature_sitemap', !empty($features['sitemap']));
    checkbox('HTMLキャッシュを有効にする', 'feature_html_cache', array_key_exists('html_cache', $features) ? !empty($features['html_cache']) : true);
    checkbox('Tomos Postを有効にする', 'feature_post', array_key_exists('post', $features) ? !empty($features['post']) : true);
    echo '<p class="hint">HTMLキャッシュはMarkdown変換後の本文HTMLを <code>cache/html/</code> に保存し、表示を軽くします。通常は有効のままで構いません。</p>';
    echo '<p class="hint">Tomos Postでは、Markdownファイルの投稿、記事管理、投稿の取り下げ、trash整理、サイト設定、セキュリティ、テーマ管理を行えます。有効にすると、setup完了時に管理用合言葉を一度だけ表示します。</p>';
    echo '<p class="hint">メタデータキャッシュは有効として保存します。生HTML許可などの危険な項目はセットアップ画面では変更できません。</p>';

    echo '<div class="actions"><button type="submit"' . ($validThemeCount === 0 || $detectedUrl === null || $setupUrlError !== '' ? ' disabled' : '') . '>config.php を保存する</button></div>';
    if ($validThemeCount === 0) {
        echo '<p class="hint">利用できるテーマがないため保存できません。<code>themes/</code> フォルダに有効なテーマを配置してください。</p>';
    }
    echo '</form>';

    echo '</main></body></html>';
}

function input(string $label, string $name, string $value, string $type): void
{
    echo '<label for="' . e($name) . '">' . e($label) . '</label>';
    echo '<input id="' . e($name) . '" type="' . e($type) . '" name="' . e($name) . '" value="' . e($value) . '">';
}

function checkbox(string $label, string $name, bool $checked): void
{
    echo '<label class="checkbox"><input type="checkbox" name="' . e($name) . '" value="1"' . ($checked ? ' checked' : '') . '> ' . e($label) . '</label>';
}

function renderThemeChoices(array $themes, string $selectedTheme): void
{
    if ($themes === []) {
        echo '<div class="errors"><p>テーマが見つかりません。<code>themes/</code> フォルダに標準テーマ <code>tomos-minimal</code> が含まれているか確認してください。</p></div>';
        return;
    }

    $validThemes = array_filter($themes, function (array $theme): bool {
        return !empty($theme['valid']);
    });
    if ($validThemes === []) {
        echo '<div class="errors"><p>利用できるテーマがありません。<code>themes/</code> フォルダに有効なテーマを配置してください。</p></div>';
    }

    $validNames = array_map(function (array $theme): string {
        return (string) ($theme['name'] ?? '');
    }, $validThemes);
    if (!in_array($selectedTheme, $validNames, true)) {
        $selectedTheme = in_array('tomos-minimal', $validNames, true) ? 'tomos-minimal' : (string) reset($validNames);
    }

    echo '<div class="theme-list">';
    foreach ($themes as $directory => $result) {
        $valid = !empty($result['valid']);
        $themeName = (string) ($result['name'] ?? $directory);
        $displayName = (string) ($result['display_name'] ?? $directory);
        $version = (string) ($result['version'] ?? '');
        $description = (string) ($result['description'] ?? '');
        $author = (string) ($result['author'] ?? '');
        $checked = $valid && ($themeName === $selectedTheme || ($selectedTheme === '' && $themeName === 'tomos-minimal'));

        echo '<div class="theme-card' . ($valid ? '' : ' invalid') . '">';
        if ($valid) {
            echo '<label><input type="radio" name="theme_name" value="' . e($themeName) . '"' . ($checked ? ' checked' : '') . '>';
            echo '<span>';
        } else {
            echo '<div>';
        }

        echo '<span class="theme-title">' . e($displayName !== '' ? $displayName : $directory) . '</span>';
        echo '<p class="theme-meta">';
        echo 'テーマ名: <code>' . e($themeName !== '' ? $themeName : $directory) . '</code>';
        if ($version !== '') {
            echo ' / バージョン: ' . e($version);
        }
        if ($author !== '') {
            echo ' / 作者: ' . e($author);
        }
        echo ' / 状態: ' . ($valid ? '有効' : '無効');
        echo '</p>';

        if ($description !== '') {
            echo '<p class="theme-desc">' . e($description) . '</p>';
        }

        if (!$valid) {
            echo '<p class="theme-note">このテーマは選択できません。</p>';
            renderThemeProblems($result['errors'] ?? []);
        } elseif (!empty($result['warnings'])) {
            renderThemeProblems($result['warnings'], '注意');
        }

        if ($valid) {
            echo '</span></label>';
        } else {
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function renderThemeProblems(array $items, string $label = '理由'): void
{
    if ($items === []) {
        return;
    }

    echo '<ul class="theme-problems">';
    foreach ($items as $item) {
        echo '<li>' . e($label . ': ' . (string) $item) . '</li>';
    }
    echo '</ul>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
