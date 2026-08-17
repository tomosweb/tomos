<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$setup = (string) file_get_contents($root . '/setup/index.php');

$checks = [
    strpos($setup, 'テーマアップロード機能は現時点ではありません。') === false => 'stale theme upload wording is removed',
    strpos($setup, '最小機能です') === false => 'stale minimal-feature wording is removed',
    strpos($setup, 'Tomos Postのテーマ管理からテーマZIPを追加でき') !== false => 'setup documents current theme ZIP flow',
    strpos($setup, '記事管理、投稿の取り下げ、trash整理、サイト設定、セキュリティ、テーマ管理') !== false => 'setup documents current Tomos Post scope',
];

$passed = 0;
foreach ($checks as $ok => $message) {
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $passed++;
}

echo "setup_ui_copy_check: {$passed} checks passed\n";
