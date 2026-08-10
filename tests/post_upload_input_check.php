<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostUploadInput.php';

$tmp = tempnam(sys_get_temp_dir(), 'tomos-post-input-');
if ($tmp === false) {
    throw new RuntimeException('temporary file could not be created');
}

try {
    $result = \Tomos\PostUploadInput::read(['error' => UPLOAD_ERR_NO_FILE]);
    assertError($result, '投稿するファイルを選択してください。', 'missing file');

    $result = \Tomos\PostUploadInput::read([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => 0,
        'name' => 'empty.md',
    ], static fn (string $path): bool => true);
    assertError($result, '空のファイルは投稿できません。', 'empty file');

    $result = \Tomos\PostUploadInput::read([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => 1048577,
        'name' => 'large.md',
    ], static fn (string $path): bool => true);
    assertError($result, 'ファイルサイズが大きすぎます。初期版では1MBまでです。', 'large file');

    file_put_contents($tmp, "# 日本語\n");
    $result = \Tomos\PostUploadInput::read([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => 13,
        'name' => '記事-日本語.md',
    ], static fn (string $path): bool => true);
    if (!$result->ok || $result->content !== "# 日本語\n" || $result->originalFileName !== '記事-日本語.md') {
        throw new RuntimeException('filename and content must be read from the upload');
    }

    $result = \Tomos\PostUploadInput::read([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp . '-missing',
        'size' => 1,
        'name' => 'missing.md',
    ], static fn (string $path): bool => true);
    assertError($result, 'アップロードされたファイルを読み込めませんでした。', 'read failure');

    $result = \Tomos\PostUploadInput::read([
        'error' => UPLOAD_ERR_OK,
        'tmp_name' => $tmp,
        'size' => 1,
        'name' => 'not-uploaded.md',
    ]);
    assertError($result, 'アップロードされたファイルを確認できませんでした。', 'non-uploaded file');

    echo "post_upload_input_check: OK\n";
} finally {
    if (is_file($tmp)) {
        unlink($tmp);
    }
}

function assertError(object $result, string $expected, string $label): void
{
    if ($result->ok || !in_array($expected, $result->errors, true)) {
        throw new RuntimeException($label . ' must return the expected error');
    }
}
