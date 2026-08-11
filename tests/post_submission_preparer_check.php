<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/Security.php';
require_once dirname(__DIR__) . '/core/FrontMatterParser.php';
require_once dirname(__DIR__) . '/core/PublishedMetadata.php';
require_once dirname(__DIR__) . '/core/PostBasicPage.php';
require_once dirname(__DIR__) . '/core/PostEditableMarkdown.php';
require_once dirname(__DIR__) . '/core/PostSubmissionPreparer.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-preparer-' . bin2hex(random_bytes(6));
mkdir($root . '/content/docs', 0777, true);
mkdir($root . '/cache', 0777, true);
$config = [
    'paths' => ['content_dir' => $root . '/content', 'cache_dir' => $root . '/cache'],
    'site' => ['timezone' => 'Asia/Tokyo'],
];

try {
    $editableMarkdown = new Tomos\PostEditableMarkdown($config, $root);
    $preparer = new Tomos\PostSubmissionPreparer($editableMarkdown);

    $prepared = $preparer->prepare("---\ntitle: Test\n---\n# 本文\n", '記事.markdown', 'notes/2026', '');
    assertTrue($prepared->ok, 'normal UTF-8 markdown must be accepted');
    assertSame('記事.md', $prepared->safeFileName, 'Japanese filename must be normalized');
    assertSame('notes/2026', $prepared->folder, 'nested folder must be preserved');
    assertSame('記事.markdown', $prepared->chosenFileName, 'original filename must be selected');

    $prepared = $preparer->prepare('# Text', 'article.md', '', 'renamed.txt');
    assertTrue($prepared->ok, 'explicit filename must be accepted');
    assertSame('renamed.md', $prepared->safeFileName, 'accepted extensions must normalize to md');

    assertPreparationError($preparer, '# Text', 'article.html', '', '投稿できるファイルは .md / .markdown / .txt です。', 'invalid extension');
    assertPreparationError($preparer, '# Text', 'note.php.md', '', '危険な拡張子を含むファイル名は投稿できません。', 'dangerous extension segment');
    assertPreparationError($preparer, "# Text\0", 'article.md', '', 'テキストファイルとして読み込めない内容が含まれています。', 'binary content');
    if (function_exists('mb_check_encoding')) {
        assertPreparationError($preparer, "\xFF", 'article.md', '', '文字コードはUTF-8のファイルを投稿してください。', 'invalid UTF-8');
    }
    assertPreparationError($preparer, '# Text', 'article.md', '../private', '保存先フォルダに危険なパス指定が含まれています。', 'folder traversal');

    $prepared = $preparer->prepare('# Home', 'index.md', 'ignored', '');
    assertTrue($prepared->ok, 'root index must be accepted');
    assertSame(Tomos\PostBasicPage::HOME, $prepared->basicPageType, 'root index must be basic page');
    assertSame('', $prepared->folder, 'root basic page must ignore folder');

    $prepared = $preparer->prepare('# About', 'about.md', 'ignored', '');
    assertSame(Tomos\PostBasicPage::ABOUT, $prepared->basicPageType, 'root about must be basic page');

    $folderIndex = "# Folder index\n";
    file_put_contents($root . '/content/docs/index.md', $folderIndex);
    $editable = editableMarkdown('docs/index.md', $folderIndex, "# Updated folder index\n");
    $prepared = $preparer->prepare($editable, 'index.md', 'docs', '');
    assertTrue($prepared->ok && $prepared->editable, 'folder index editable upload must be accepted');
    assertSame('', $prepared->basicPageType, 'folder index must remain an ordinary article');
    assertSame('docs', $prepared->folder, 'folder index source folder must be retained');
    assertSame("# Updated folder index\n", $prepared->content, 'editable helper metadata must be removed');

    $rootIndex = "# Root index\n";
    file_put_contents($root . '/content/index.md', $rootIndex);
    $editable = editableMarkdown('index.md', $rootIndex, "# Updated root\n");
    $prepared = $preparer->prepare($editable, 'index.md', 'ignored', '');
    assertTrue($prepared->ok && $prepared->editable, 'root basic page editable upload must be accepted');
    assertSame(Tomos\PostBasicPage::HOME, $prepared->basicPageType, 'root editable index must remain basic page');
    assertSame('', $prepared->folder, 'root editable index must remain at root');

    echo "post_submission_preparer_check: OK\n";
} finally {
    removeTree($root);
}

function editableMarkdown(string $sourcePath, string $source, string $body): string
{
    return "---\n"
        . "tomos_asset_base_url: https://example.test/content/\n"
        . "tomos_source_path: {$sourcePath}\n"
        . 'tomos_source_hash: ' . hash('sha256', $source) . "\n"
        . "tomos_source_status: published\n"
        . "---\n"
        . $body;
}

function assertPreparationError(Tomos\PostSubmissionPreparer $preparer, string $content, string $fileName, string $folder, string $expected, string $label): void
{
    $result = $preparer->prepare($content, $fileName, $folder, '');
    if ($result->ok || !in_array($expected, $result->errors, true)) {
        throw new RuntimeException($label . ' must return the expected error');
    }
}

function assertTrue(bool $actual, string $message): void
{
    if (!$actual) throw new RuntimeException($message);
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message);
}

function removeTree(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($child) ? removeTree($child) : unlink($child);
    }
    rmdir($path);
}
