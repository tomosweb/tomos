<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $passes++;
}

function readDocument(string $root, string $relative): string
{
    $path = $root . '/' . $relative;
    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        throw new RuntimeException('could not read ' . $relative);
    }
    return $contents;
}

$version = trim(readDocument($root, 'VERSION'));
check(preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:-[0-9A-Za-z.-]+)?\z/', $version) === 1, 'VERSION has a valid format');

$readme = readDocument($root, 'README.md');
$install = readDocument($root, 'INSTALL.md');
$changelog = readDocument($root, 'CHANGELOG.md');
$limitations = readDocument($root, 'KNOWN_LIMITATIONS.md');
$security = readDocument($root, 'SECURITY.md');
$disclaimer = readDocument($root, 'DISCLAIMER.md');
$docsReadme = readDocument($root, 'docs/README.md');
$installSupplement = readDocument($root, 'docs/install/install.md');
$setupGuide = readDocument($root, 'docs/install/setup.md');
$troubleshooting = readDocument($root, 'docs/install/troubleshooting.md');
$updateGuide = readDocument($root, 'docs/install/update.md');
$tomosUpdateGuide = readDocument($root, 'docs/install/tomos-update.md');
$postGuide = readDocument($root, 'docs/features/post.md');

check(strpos($readme, '現在のバージョンは `v' . $version . '`') !== false, 'README current version matches VERSION');
check(strpos($docsReadme, '現在のバージョン: `v' . $version . '`') !== false, 'docs README current version matches VERSION');

$releaseNotePath = 'docs/releases/v' . $version . '.md';
$releaseNote = readDocument($root, $releaseNotePath);
check(strpos($releaseNote, '# Tomos v' . $version) === 0, 'release note H1 matches VERSION');

preg_match('/^## (v[^\s]+)(?:\s+-\s+[^\r\n]+)?$/m', $changelog, $heading);
check(($heading[1] ?? null) === 'v' . $version, 'CHANGELOG first version heading matches VERSION');

$staleTokensByDocument = [
    'README.md' => [
        '現在のバージョンは v0.1.0-alpha.16',
        'Login is not implemented',
        'Admin screens are not implemented',
        'Theme upload is not implemented',
        'Automatic updates are not implemented',
    ],
    'INSTALL.md' => [
        'インストール対象バージョン:',
        'alpha版を含む検証版では',
    ],
    'KNOWN_LIMITATIONS.md' => [
        'Tomos is an alpha release.',
    ],
    'SECURITY.md' => [
        'Tomos `v0.1.0-alpha` は限定テスト用の初期開発版です。',
    ],
    'DISCLAIMER.md' => [
        'Tomos 0.1.0-alpha.2 は初期開発版です。',
    ],
    'docs/README.md' => [
        '現在のバージョン: v0.1.0-alpha.8',
    ],
    'docs/install/install.md' => [
        'tomos-0.1.0-alpha.4.zip',
    ],
    'docs/install/setup.md' => [
        'テーマアップロード機能は現時点ではありません。',
    ],
    'docs/install/troubleshooting.md' => [
        '`config.php.tmp` の作成',
        '短時間に投稿試行が続いたため、一時的に制限されている',
        '短時間に投稿や取り下げを繰り返したため、一時的に制限されている',
    ],
    'docs/install/tomos-update.md' => [
        'alpha.10以降では、通常Update完了後にUpdater本体の明示反映が必要です。',
    ],
];

$documents = [
    'README.md' => $readme,
    'INSTALL.md' => $install,
    'KNOWN_LIMITATIONS.md' => $limitations,
    'SECURITY.md' => $security,
    'DISCLAIMER.md' => $disclaimer,
    'docs/README.md' => $docsReadme,
    'docs/install/install.md' => $installSupplement,
    'docs/install/setup.md' => $setupGuide,
    'docs/install/troubleshooting.md' => $troubleshooting,
    'docs/install/tomos-update.md' => $tomosUpdateGuide,
];

foreach ($staleTokensByDocument as $name => $tokens) {
    foreach ($tokens as $token) {
        check(strpos($documents[$name], $token) === false, $name . ' has no stale token: ' . $token);
    }
}

check(strpos($install, 'tomos-<VERSION>.zip') !== false, 'INSTALL uses a version-independent distribution ZIP example');
check(preg_match('/tomos-[0-9]+(?:\.[0-9]+)+(?:-[0-9A-Za-z.-]+)?\.zip/', $install) !== 1, 'INSTALL does not hard-code a versioned distribution ZIP');
check(strpos($installSupplement, 'tomos-<VERSION>.zip') !== false, 'install supplement uses a version-independent distribution ZIP example');

foreach (['tomos-minimal', 'tomos-journal', 'tomos-dark', 'tomos-note', 'tomos-blog', 'tomos-90s'] as $themeId) {
    check(strpos($docsReadme, '`' . $themeId . '`') !== false, 'docs README lists bundled theme ' . $themeId);
}

check(strpos($setupGuide, 'テーマZIPを追加できます') !== false, 'setup guide documents post-setup theme ZIP addition');
check(strpos($setupGuide, '登録済みパスキー') !== false, 'setup guide documents current password recovery preference');
check(strpos($troubleshooting, '/post/passkey/recovery/') !== false, 'troubleshooting documents passkey recovery');
check(strpos($troubleshooting, 'submission_id') !== false, 'troubleshooting documents current duplicate-submit behavior');

check(strpos($updateGuide, '## 現在の更新方法') !== false, 'update guide has current update instructions');
check(strpos($updateGuide, 'v0.1.0-beta.1からv0.2.0への更新') !== false, 'update guide describes beta.1 to 0.2.0');
check(strpos($updateGuide, 'from_version: 0.1.0-beta.1') !== false, 'update guide fixes 0.2.0 from_version boundary');
check(strpos($updateGuide, 'version: 0.2.0') !== false, 'update guide fixes 0.2.0 target boundary');
check(strpos($updateGuide, 'v0.1.0-alpha.18からv0.1.0-alpha.19への更新') !== false, 'update guide describes alpha.18 to alpha.19');
check(strpos($updateGuide, 'v0.1.0-alpha.17からv0.1.0-alpha.18への移行') !== false, 'update guide describes the alpha.17 bootstrap');
check(strpos($updateGuide, '/post/update-finalize/') !== false, 'update guide preserves legacy finalize instructions');

if ($version === '0.1.0-beta.1') {
    check(strpos($updateGuide, 'v0.1.0-alpha.19からv0.1.0-beta.1への更新') !== false, 'update guide describes alpha.19 to beta.1');
    check(strpos($updateGuide, '`from_version`は`0.1.0-alpha.19`') !== false, 'update guide fixes beta.1 from_version boundary');
    check(strpos($tomosUpdateGuide, 'v0.1.0-alpha.19からv0.1.0-beta.1への更新') !== false, 'Tomos Update guide describes alpha.19 to beta.1');
    check(strpos($tomosUpdateGuide, 'この更新では `/post/update-finalize/` の操作は必要ありません。') !== false, 'beta.1 update guide explicitly excludes finalize');
}

check(strpos($tomosUpdateGuide, '手動ZIP更新は恒久的な正式ルートとして残ります') !== false, 'Tomos Update guide preserves formal manual updates');
check(strpos($tomosUpdateGuide, 'v0.1.0-alpha.17 -> v0.1.0-alpha.18') !== false, 'Tomos Update guide limits legacy finalize to alpha.17 to alpha.18');
check(strpos($tomosUpdateGuide, 'alpha.18以降の通常Updateでは、このlegacy finalize手順を繰り返しません。') !== false, 'Tomos Update guide distinguishes current update flow from legacy finalize');

check(strpos($postGuide, '/post/passkey/password-reset/') !== false, 'Post guide documents passkey password reset');
check(strpos($postGuide, '/post/passkey/recovery/') !== false, 'Post guide documents server ownership recovery');
check(strpos($postGuide, 'post-reset.enable') !== false, 'Post guide preserves legacy emergency password reset');

check(strpos($limitations, 'pre-release') !== false, 'limitations document identifies pre-release status without alpha lock-in');
check(strpos($security, 'pre-release') !== false, 'security policy identifies current pre-release line');
check(strpos($disclaimer, 'pre-release') !== false, 'disclaimer identifies pre-release status without hard-coded version');

echo "release_docs_check: {$passes} checks passed\n";
