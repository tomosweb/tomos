<?php

declare(strict_types=1);

$path = dirname(__DIR__) . '/core/PostUpload.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "PostUpload.phpを読み込めませんでした。\n");
    exit(1);
}

$start = strpos($source, 'public function createRenamedFromTemp');
$end = $start === false ? false : strpos($source, 'public function cancelTemp', $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "createRenamedFromTemp()を確認できませんでした。\n");
    exit(1);
}

$method = substr($source, $start, $end - $start);
if (strpos($method, '$decision->originalFileName') === false) {
    fwrite(STDERR, "リネーム結果がリネーム入力名を引き継いでいません。\n");
    exit(1);
}

if (strpos($method, "(string) (\$record->meta['original_file_name'] ?? '')") !== false) {
    fwrite(STDERR, "リネーム結果が最初のアップロード名へ戻っています。\n");
    exit(1);
}

if (strpos($method, "'rename_create'") === false) {
    fwrite(STDERR, "rename_create操作を確認できませんでした。\n");
    exit(1);
}

echo "Issue #96 rename result filename check: OK\n";
