<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/core/PostInbox.php';
require_once dirname(__DIR__) . '/core/PostUpload.php';

use Tomos\PostInbox;
use Tomos\PostUpload;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tomos-inbox-' . bin2hex(random_bytes(6));
$content = $root . DIRECTORY_SEPARATOR . 'content';
$cache = $root . DIRECTORY_SEPARATOR . 'cache';
$inboxPath = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inbox';
foreach ([$content, $cache, $inboxPath] as $directory) {
    if (!mkdir($directory, 0775, true)) {
        throw new RuntimeException('test directory could not be created');
    }
}

$config = [
    'site' => ['url' => 'https://example.test/', 'timezone' => 'Asia/Tokyo'],
    'paths' => ['content_dir' => $content, 'cache_dir' => $cache, 'inbox_dir' => $inboxPath],
    'features' => ['html_cache' => false],
    'metadata' => ['include_drafts' => false],
];
$inbox = new PostInbox($config, $root);

$oldPath = $inboxPath . DIRECTORY_SEPARATOR . 'old.txt';
$newPath = $inboxPath . DIRECTORY_SEPARATOR . '受信記事.markdown';
$unsupportedPath = $inboxPath . DIRECTORY_SEPARATOR . 'ignored.php';
file_put_contents($oldPath, "# Old\n");
file_put_contents($newPath, "---\nfolder: diary\n---\n# Inbox article\n");
file_put_contents($unsupportedPath, "<?php echo 'not markdown';\n");
touch($oldPath, time() - 60);

$items = $inbox->list();
if (count($items) !== 2 || $items[0]->fileName !== '受信記事.markdown') {
    throw new RuntimeException('supported inbox files must be listed newest first');
}

$unsafe = $inbox->read('../outside.md');
if ($unsafe->ok || $unsafe->errors !== ['受信箱のファイルパスが正しくありません。']) {
    throw new RuntimeException('inbox traversal path must be rejected');
}

$read = $inbox->read('受信記事.markdown');
if (!$read->ok || $read->content === '' || $inbox->folderFromMarkdown($read->content) !== 'diary') {
    throw new RuntimeException('inbox Markdown and folder metadata must be readable');
}

$upload = new PostUpload($config, $root);
$published = $upload->handleContent(
    $read->content,
    $read->fileName,
    $inbox->folderFromMarkdown($read->content),
    '',
    'session-a',
    [],
    [],
    false,
    str_repeat('a', 64)
);
if (!$published->ok || $published->contentPath !== 'diary/受信記事.md') {
    throw new RuntimeException('inbox Markdown must use the common posting pipeline');
}
if (!$inbox->delete($read->path) || is_file($newPath)) {
    throw new RuntimeException('published inbox file must be deleted after success');
}

$failedPath = $inboxPath . DIRECTORY_SEPARATOR . 'failed.md';
file_put_contents($failedPath, "bad\0content\n");
$failedRead = $inbox->read('failed.md');
$failedPublish = $upload->handleContent($failedRead->content, $failedRead->fileName, '', '', 'session-a', [], [], false, str_repeat('b', 64));
if ($failedPublish->ok || !is_file($failedPath)) {
    throw new RuntimeException('failed publication must keep the inbox file');
}

if ($inbox->read('/etc/passwd')->ok || $inbox->read("bad\0.md")->ok) {
    throw new RuntimeException('absolute and null-byte inbox paths must be rejected');
}

echo "post_inbox_check: OK\n";
